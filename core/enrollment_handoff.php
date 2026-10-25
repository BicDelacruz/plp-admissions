<?php
// ============================================================
// core/enrollment_handoff.php
// Sends a confirmed, accepted applicant to the Enrollment system.
//
// One HTTPS POST to Enrollment's /api/admissions/intake with a Bearer
// secret. The body follows features/admissions-intake/schema.ts in the
// Enrollment repo (snake_case JSON). Progress is kept in the
// enrollment_handoff_outbox table, so a failed send stays visible and
// staff can retry it. Repeating a send is safe: Enrollment treats the
// applicant_ref as the identity, so a second call is a refresh, never a
// second account.
//
// Nothing here throws. A failed send must never block the student's
// confirmation.
//
// Settings (environment): ENROLLMENT_INTAKE_URL, ENROLLMENT_INTAKE_SECRET
// The secret must equal ADMISSIONS_INTAKE_SECRET in the Enrollment project.
// ============================================================

const ENROLLMENT_HANDOFF_CONNECT_TIMEOUT = 5;    // seconds
const ENROLLMENT_HANDOFF_TIMEOUT         = 15;   // seconds; Enrollment sends an email during the call

/**
 * Admissions document slot -> the Enrollment document type it is the same paper as.
 * Only papers that are the same thing on both sides are listed. A valid ID,
 * the barangay certificate and the photo are left out on purpose: Enrollment
 * asks for different items (two IDs, a 2x2 photo), so "verified" would mislead.
 */
const ENROLLMENT_DOC_TYPE_MAP = [
    'psa_birth_cert' => 'psa_birth_cert',
    'form_138'       => 'form_138',
    'form_137'       => 'form_137',
    'tor'            => 'tor',
    'passport'       => 'passport',
    'visa_permit'    => 'visa_permit',
    'alien_cert'     => 'acr',
];

/** Approved Admissions document slots -> sorted, unique Enrollment document types. */
function enrollment_verified_documents(array $approvedSlots): array
{
    $types = [];
    foreach ($approvedSlots as $slot) {
        if (isset(ENROLLMENT_DOC_TYPE_MAP[$slot])) {
            $types[ENROLLMENT_DOC_TYPE_MAP[$slot]] = true;
        }
    }
    $types = array_keys($types);
    sort($types);
    return $types;
}

/** The code in the last brackets of a course name, or null. */
function enrollment_course_code(string $course): ?string
{
    if (preg_match('/\(([^()\s][^()]*)\)\s*$/', trim($course), $m)) {
        return trim($m[1]);
    }
    return null;
}

/** ADM-<first year of the school year>-<applicant id padded to 6>, or null. */
function enrollment_applicant_reference(string $schoolYear, int $applicantId): ?string
{
    if (!preg_match('/^(\d{4})/', $schoolYear, $m)) {
        return null;
    }
    return 'ADM-' . $m[1] . '-' . str_pad((string) $applicantId, 6, '0', STR_PAD_LEFT);
}

/** Admissions applicant type -> Enrollment admission type (new, transferee, foreign). */
function enrollment_admission_type(string $applicantType): string
{
    return match ($applicantType) {
        'transferee' => 'transferee',
        'foreign'    => 'foreign',
        default      => 'new',
    };
}

/** "First Middle Last Suffix". Falls back to the combined name. */
function enrollment_full_name(array $row): string
{
    $parts = [];
    foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    $name = implode(' ', $parts);
    return $name !== '' ? $name : trim((string) ($row['name'] ?? ''));
}

/**
 * Build the payload from live data.
 * Returns [payload, null, null] when ready,
 *         [null, message, 'not_ready'] when the applicant is not accepted and confirmed (nothing is recorded),
 *         [null, message, 'invalid'] when the data cannot be sent until someone fixes it.
 */
function enrollment_handoff_payload(PDO $db, int $applicantId): array
{
    $stmt = $db->prepare(
        'SELECT a.id, a.applicant_type, a.course_applied, a.school_year,
                u.name, u.first_name, u.middle_name, u.last_name, u.suffix, u.email,
                ar.result, ar.enrollment_intent, ar.released_at
         FROM applicants a
         JOIN users u ON u.id = a.user_id
         JOIN admission_results ar ON ar.applicant_id = a.id
         WHERE a.id = ? LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [null, 'No released result for this applicant.', 'not_ready'];
    }
    if ($row['result'] !== 'accepted' || $row['enrollment_intent'] !== 'confirmed') {
        return [null, 'The applicant is not accepted and confirmed.', 'not_ready'];
    }

    $code = enrollment_course_code((string) $row['course_applied']);
    if ($code === null) {
        return [null, 'The course "' . $row['course_applied'] . '" has no code in brackets, so Enrollment cannot match it. Rename the course with its code first.', 'invalid'];
    }

    $reference = enrollment_applicant_reference((string) $row['school_year'], (int) $row['id']);
    if ($reference === null) {
        return [null, 'The school year "' . $row['school_year'] . '" has no four digit year.', 'invalid'];
    }

    $fullName = enrollment_full_name($row);
    if ($fullName === '') {
        return [null, 'The applicant has no name on file.', 'invalid'];
    }

    // Information for the Registrar only; a failed read must not block the send.
    $verified = [];
    try {
        $docs = $db->prepare("SELECT DISTINCT doc_type FROM documents WHERE applicant_id = ? AND status = 'approved'");
        $docs->execute([$applicantId]);
        $verified = enrollment_verified_documents($docs->fetchAll(PDO::FETCH_COLUMN));
    } catch (\Throwable) {
        $verified = [];
    }

    return [[
        'applicant_ref'  => $reference,
        'full_name'      => $fullName,
        'email'          => strtolower(trim((string) $row['email'])),
        'program_code'   => $code,
        'admission_date' => substr((string) $row['released_at'], 0, 10),
        'admission_type' => enrollment_admission_type((string) $row['applicant_type']),
        'source_name'    => 'PLP Admissions',
        'verified_documents' => $verified,
    ], null, null];
}

/**
 * Turn Enrollment's answer into an outbox outcome. No network, so it can be tested alone.
 * $httpStatus is null when no answer came back.
 * Returns [status ('sent'|'pending'|'rejected'), enrollment_result|null, message].
 */
function enrollment_classify_answer(?int $httpStatus, mixed $json, string $transportError = ''): array
{
    if ($httpStatus === null) {
        return ['pending', null, 'Could not reach Enrollment' . ($transportError !== '' ? ': ' . $transportError : '') . '.'];
    }

    $error  = is_array($json) && isset($json['error']) && is_string($json['error']) ? trim($json['error']) : '';
    $result = is_array($json) && isset($json['status']) && is_string($json['status']) ? $json['status'] : '';

    if ($httpStatus === 200 || $httpStatus === 201) {
        if (in_array($result, ['created', 'updated', 'unchanged'], true)) {
            return ['sent', $result, 'Enrollment answered: ' . $result . '.'];
        }
        return ['pending', null, 'Enrollment gave an answer that was not understood.'];
    }
    if (in_array($httpStatus, [400, 409, 422], true)) {
        return ['rejected', null, $error !== '' ? $error : 'Enrollment rejected the details (HTTP ' . $httpStatus . ').'];
    }
    if ($httpStatus === 401) {
        return ['pending', null, 'Enrollment did not accept the shared secret. Check ENROLLMENT_INTAKE_SECRET.'];
    }
    return ['pending', null, 'Enrollment answered HTTP ' . $httpStatus . ($error !== '' ? ': ' . $error : '.')];
}

/**
 * POST the payload. Returns [httpStatus|null, decodedJson|null, transportError].
 * The secret is only ever read here and never written anywhere.
 */
function enrollment_handoff_post(array $payload): array
{
    $url    = trim((string) getenv('ENROLLMENT_INTAKE_URL'));
    $secret = trim((string) getenv('ENROLLMENT_INTAKE_SECRET'));
    if ($url === '' || $secret === '') {
        return [null, null, 'ENROLLMENT_INTAKE_URL or ENROLLMENT_INTAKE_SECRET is not set'];
    }
    if (!str_starts_with($url, 'https://') && APP_ENV === 'production') {
        return [null, null, 'ENROLLMENT_INTAKE_URL must start with https://'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => ENROLLMENT_HANDOFF_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => ENROLLMENT_HANDOFF_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status === 0) {
        return [null, null, $err !== '' ? $err : 'no response'];
    }
    return [$status, json_decode((string) $body, true), ''];
}

/**
 * Check a status call from Enrollment. No database, no network.
 * Returns [applicantId, stage, studentNumber] or an error message string.
 */
function enrollment_status_parse(array $body): array|string
{
    $ref = trim((string) ($body['applicant_ref'] ?? ''));
    if (!preg_match('/^ADM-\d{4}-(\d{1,9})$/', $ref, $m) || (int) $m[1] < 1) {
        return 'applicant_ref is missing or not valid.';
    }
    $stage = (string) ($body['stage'] ?? '');
    if ($stage !== 'student' && $stage !== 'enrolled') {
        return 'stage must be "student" or "enrolled".';
    }
    $number = trim((string) ($body['student_number'] ?? ''));
    if ($number === '' || strlen($number) > 20) {
        return 'student_number is missing or longer than 20 characters.';
    }
    return [(int) $m[1], $stage, $number];
}

/** The outbox row for an applicant, or null. */
function enrollment_handoff_state(PDO $db, int $applicantId): ?array
{
    try {
        $stmt = $db->prepare('SELECT status, enrollment_result, last_error, attempts, sent_at FROM enrollment_handoff_outbox WHERE applicant_id = ?');
        $stmt->execute([$applicantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Send one applicant. Creates the outbox row if needed, records the attempt,
 * and marks it sent on success. Safe to call again: a sent row is left alone.
 * Returns [ok, message].
 */
function enrollment_handoff_send(PDO $db, int $applicantId): array
{
    try {
        [$payload, $buildError, $kind] = enrollment_handoff_payload($db, $applicantId);

        if ($payload === null && $kind === 'not_ready') {
            return [false, (string) $buildError];
        }

        $db->prepare(
            'INSERT INTO enrollment_handoff_outbox (applicant_id, applicant_ref)
             VALUES (?, ?) ON CONFLICT (applicant_id) DO NOTHING'
        )->execute([$applicantId, $payload['applicant_ref'] ?? null]);

        $stmt = $db->prepare('SELECT status FROM enrollment_handoff_outbox WHERE applicant_id = ?');
        $stmt->execute([$applicantId]);
        if ($stmt->fetchColumn() === 'sent') {
            return [true, 'Already sent to Enrollment.'];
        }

        $httpStatus = null;
        if ($payload === null) {
            [$status, $result, $message] = ['rejected', null, (string) $buildError];
        } else {
            [$httpStatus, $json, $transportError] = enrollment_handoff_post($payload);
            [$status, $result, $message] = enrollment_classify_answer($httpStatus, $json, $transportError);
        }

        $sentAt = $status === 'sent' ? 'NOW()' : 'sent_at';
        $db->prepare(
            "UPDATE enrollment_handoff_outbox
             SET attempts = attempts + 1,
                 last_attempt_at = NOW(),
                 status = ?,
                 last_http_status = ?,
                 enrollment_result = ?,
                 sent_at = $sentAt,
                 last_error = ?,
                 applicant_ref = COALESCE(?, applicant_ref)
             WHERE applicant_id = ?"
        )->execute([
            $status,
            $httpStatus,
            $result,
            $status === 'sent' ? null : mb_substr($message, 0, 500),
            $payload['applicant_ref'] ?? null,
            $applicantId,
        ]);

        $ok = $status === 'sent';
        audit_log(
            $ok ? 'enrollment_handoff_sent' : 'enrollment_handoff_failed',
            "Applicant {$applicantId}: " . $message,
            'applicant', $applicantId
        );
        return [$ok, $message];
    } catch (\Throwable $e) {
        error_log('enrollment handoff error: ' . $e->getMessage());
        return [false, 'The handoff could not be recorded.'];
    }
}

// ----------------------------------------------------------------
// AUTOMATIC RETRY
// There is no scheduler on this host, so (like the exam sweep) the staff
// results page calls this when it opens. It retries sends still waiting
// and applicants who confirmed but never got a send recorded. A rejected
// send is never retried here: it needs a person to fix the details first.
// ----------------------------------------------------------------

const ENROLLMENT_RETRY_STEP_SECONDS = 300;  // wait 5 minutes longer after each failed attempt
const ENROLLMENT_RETRY_MAX_STEPS    = 12;   // the wait stops growing at 1 hour
const ENROLLMENT_RETRY_MAX_ATTEMPTS = 20;   // after this, leave it to a person
const ENROLLMENT_RETRY_PER_REQUEST  = 2;    // keeps the page fast when Enrollment is slow

/** Seconds to wait before the next automatic try, given the attempts so far. */
function enrollment_retry_delay(int $attempts): int
{
    return min(max($attempts, 0), ENROLLMENT_RETRY_MAX_STEPS) * ENROLLMENT_RETRY_STEP_SECONDS;
}

/** True when a pending send is due for another automatic try. Pure, so it can be tested alone. */
function enrollment_retry_due(int $attempts, ?int $lastAttemptAt, int $now): bool
{
    if ($attempts >= ENROLLMENT_RETRY_MAX_ATTEMPTS) {
        return false;
    }
    return $lastAttemptAt === null || $now - $lastAttemptAt >= enrollment_retry_delay($attempts);
}

/**
 * Retry what is due. Stops at the first failure, because a failure usually
 * means Enrollment is down and the next try would only make the page wait.
 * Never throws. Returns how many were sent.
 */
function enrollment_handoff_retry_pending(PDO $db): int
{
    try {
        $rows = $db->query(
            "SELECT a.id AS applicant_id, eo.attempts, eo.last_attempt_at
               FROM applicants a
               JOIN admission_results ar ON ar.applicant_id = a.id
          LEFT JOIN enrollment_handoff_outbox eo ON eo.applicant_id = a.id
              WHERE ar.result = 'accepted' AND ar.enrollment_intent = 'confirmed'
                AND (eo.id IS NULL OR eo.status = 'pending')
              ORDER BY eo.last_attempt_at NULLS FIRST, a.id
              LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);

        $now  = time();
        $sent = 0;
        $tried = 0;
        foreach ($rows as $row) {
            $last = $row['last_attempt_at'] !== null ? strtotime((string) $row['last_attempt_at']) : null;
            if (!enrollment_retry_due((int) ($row['attempts'] ?? 0), $last === false ? null : $last, $now)) {
                continue;
            }
            [$ok] = enrollment_handoff_send($db, (int) $row['applicant_id']);
            if (!$ok) {
                break;
            }
            $sent++;
            if (++$tried >= ENROLLMENT_RETRY_PER_REQUEST) {
                break;
            }
        }
        return $sent;
    } catch (\Throwable $e) {
        error_log('enrollment retry error: ' . $e->getMessage());
        return 0;
    }
}
