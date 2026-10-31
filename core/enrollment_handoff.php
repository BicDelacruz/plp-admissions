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
// Enrollment books the visit slot during the call and answers with the date,
// time and office; they are kept on the outbox row for the confirmation message.
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
        'SELECT a.id, a.applicant_type, a.course_applied, a.school_year, a.created_at,
                a.shs_strand, a.inputted_gwa, a.gwa_verified_at,
                u.name, u.first_name, u.middle_name, u.last_name, u.suffix, u.email,
                u.birthdate, u.sex, u.address, u.phone,
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
        return [
            null,
            'The course "' . $row['course_applied'] . '" has no code in brackets, so Enrollment cannot match it. '
            . 'Rename the course with its code first.',
            'invalid',
        ];
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
        'program_name'   => (string) $row['course_applied'],
        'applicant_details' => [
            'first_name' => trim((string) $row['first_name']),
            'middle_name' => trim((string) $row['middle_name']),
            'last_name' => trim((string) $row['last_name']),
            'suffix' => trim((string) $row['suffix']),
            'birthdate' => $row['birthdate'] ?: null,
            'sex' => $row['sex'] ?: null,
            'address' => trim((string) $row['address']),
            'contact_number' => trim((string) $row['phone']),
            'shs_strand' => trim((string) $row['shs_strand']),
            'source_created_on' => substr((string) $row['created_at'], 0, 10),
        ],
        'inputted_gwa' => $row['inputted_gwa'] !== null ? (float) $row['inputted_gwa'] : null,
        'gwa_verified' => $row['gwa_verified_at'] !== null,
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
        return [
            'pending',
            null,
            'Could not reach Enrollment' . ($transportError !== '' ? ': ' . $transportError : '') . '.',
        ];
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
        return [
            'rejected',
            null,
            $error !== '' ? $error : 'Enrollment rejected the details (HTTP ' . $httpStatus . ').',
        ];
    }
    if ($httpStatus === 401) {
        return ['pending', null, 'Enrollment did not accept the shared secret. Check ENROLLMENT_INTAKE_SECRET.'];
    }
    return ['pending', null, 'Enrollment answered HTTP ' . $httpStatus . ($error !== '' ? ': ' . $error : '.')];
}

/**
 * The visit Enrollment booked for the applicant, read from its answer.
 * Returns ['date' => 'YYYY-MM-DD', 'start' => 'HH:MM', 'end' => 'HH:MM', 'office' => string],
 * or null when there is no visit or it is not valid. No database, no network.
 */
function enrollment_visit_from_answer(mixed $json): ?array
{
    $visit = is_array($json) && isset($json['visit']) && is_array($json['visit']) ? $json['visit'] : null;
    if ($visit === null) {
        return null;
    }
    $date   = (string) ($visit['date'] ?? '');
    $start  = (string) ($visit['start_time'] ?? '');
    $end    = (string) ($visit['end_time'] ?? '');
    $office = trim((string) ($visit['office_name'] ?? ''));

    $day = DateTime::createFromFormat('Y-m-d', $date);
    $from = DateTime::createFromFormat('H:i', $start);
    $to   = DateTime::createFromFormat('H:i', $end);
    if (!$day || $day->format('Y-m-d') !== $date
        || !$from || $from->format('H:i') !== $start
        || !$to || $to->format('H:i') !== $end
        || $office === '') {
        return null;
    }
    return ['date' => $date, 'start' => $start, 'end' => $end, 'office' => mb_substr($office, 0, 100)];
}

/**
 * The next-step sentence added to the "Enrollment Confirmed" message, so the
 * applicant gets one message with the visit date instead of two.
 * $state is the outbox row (enrollment_handoff_state). Pure, so it can be tested alone.
 */
function enrollment_confirmation_note(?array $state): string
{
    if ($state === null || ($state['status'] ?? '') !== 'sent') {
        return 'PLP Enrollment will email you a link to set your password.';
    }

    // A new account gets a set-password email; someone who already has one just signs in.
    $created = ($state['enrollment_result'] ?? '') === 'created';
    $access  = $created
        ? 'Open the email from PLP Enrollment to set your password and see what to bring.'
        : 'Sign in to PLP Enrollment to see what to bring.';

    $day    = DateTime::createFromFormat('Y-m-d', substr((string) ($state['visit_date'] ?? ''), 0, 10));
    $from   = DateTime::createFromFormat('H:i', substr((string) ($state['visit_start_time'] ?? ''), 0, 5));
    $to     = DateTime::createFromFormat('H:i', substr((string) ($state['visit_end_time'] ?? ''), 0, 5));
    $office = trim((string) ($state['visit_office'] ?? ''));

    if ($day && $from && $to && $office !== '') {
        return 'Your next step: go to ' . $office . ' on ' . $day->format('l, F j, Y') . ', '
            . $from->format('g:i A') . ' to ' . $to->format('g:i A') . '. ' . $access;
    }
    return $created
        ? 'Open the email from PLP Enrollment to set your password. Your visit date will be there once it is set.'
        : 'Sign in to PLP Enrollment. Your visit date will be there once it is set.';
}

// ----------------------------------------------------------------
// PROGRAM LIST CHECK
// Both systems keep their own copy of the program codes. A course that
// Enrollment does not know is rejected at the handoff, so the Admin is
// warned on the staff results page before an applicant is affected.
// ----------------------------------------------------------------

/** Enrollment's program list URL, built from the intake URL. Empty when it cannot be built. */
function enrollment_programs_url(): string
{
    $url = trim((string) getenv('ENROLLMENT_INTAKE_URL'));
    return preg_match('#/intake/?$#', $url) === 1 ? (string) preg_replace('#/intake/?$#', '/programs', $url) : '';
}

/** Program codes Enrollment knows, or null when it could not be asked. Never throws. */
function enrollment_fetch_program_codes(): ?array
{
    $url    = enrollment_programs_url();
    $secret = trim((string) getenv('ENROLLMENT_INTAKE_SECRET'));
    if ($url === '' || $secret === '') {
        return null;
    }
    if (!str_starts_with($url, 'https://') && APP_ENV === 'production') {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET        => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secret,
            'Accept: application/json',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        return null;
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json) || !isset($json['programs']) || !is_array($json['programs'])) {
        return null;
    }
    $codes = [];
    foreach ($json['programs'] as $program) {
        if (is_array($program) && isset($program['code']) && is_string($program['code'])) {
            $codes[] = $program['code'];
        }
    }
    return $codes;
}

/**
 * The courses Enrollment would not match: no code in brackets, or a code it
 * does not have. Enrollment compares codes without regard to letter case.
 * Pure, so it can be tested alone.
 */
function enrollment_unmatched_courses(array $courses, array $enrollmentCodes): array
{
    $known = array_map('strtolower', array_map('strval', $enrollmentCodes));
    $bad   = [];
    foreach ($courses as $course) {
        $code = enrollment_course_code((string) $course);
        if ($code === null || !in_array(strtolower($code), $known, true)) {
            $bad[] = (string) $course;
        }
    }
    return $bad;
}

/**
 * A warning for the Admin when active courses would be rejected by Enrollment,
 * or '' when all match or Enrollment could not be asked (no false alarm).
 * Checked at most once an hour per session (5 minutes after a failed check).
 */
function enrollment_program_warning(): string
{
    try {
        $cached = Session::get('enrollment_program_check');
        if (is_array($cached) && isset($cached['until'], $cached['message']) && (int) $cached['until'] > time()) {
            return (string) $cached['message'];
        }

        $codes = enrollment_fetch_program_codes();
        if ($codes === null) {
            Session::set('enrollment_program_check', ['until' => time() + 300, 'message' => '']);
            return '';
        }

        $bad     = enrollment_unmatched_courses(get_all_courses(), $codes);
        $message = $bad === [] ? '' :
            'Enrollment has no program for: ' . implode('; ', $bad) . '. '
            . 'Applicants for these courses are rejected at the handoff until the program is added in Enrollment '
            . 'or the course name carries the right code.';
        Session::set('enrollment_program_check', ['until' => time() + 3600, 'message' => $message]);
        return $message;
    } catch (\Throwable $e) {
        error_log('enrollment program check error: ' . $e->getMessage());
        return '';
    }
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
        $stmt = $db->prepare('SELECT status, enrollment_result, last_error, attempts, sent_at,
                    enrollment_student_number, enrollment_stage, enrollment_stage_at,
                    enrollment_detail_status,
                    visit_date, visit_start_time, visit_end_time, visit_office
             FROM enrollment_handoff_outbox WHERE applicant_id = ?');
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
function enrollment_handoff_send(PDO $db, int $applicantId, bool $refreshAlreadySent = false): array
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
        if ($stmt->fetchColumn() === 'sent' && !$refreshAlreadySent) {
            return [true, 'Already sent to Enrollment.'];
        }

        $httpStatus = null;
        $visit      = null;
        if ($payload === null) {
            [$status, $result, $message] = ['rejected', null, (string) $buildError];
        } else {
            [$httpStatus, $json, $transportError] = enrollment_handoff_post($payload);
            [$status, $result, $message] = enrollment_classify_answer($httpStatus, $json, $transportError);
            if ($status === 'sent') {
                $visit = enrollment_visit_from_answer($json);
            }
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
                 enrollment_student_number = COALESCE(?, enrollment_student_number),
                 enrollment_stage = CASE WHEN ? IS NOT NULL AND enrollment_stage IS DISTINCT FROM 'enrolled'
                      THEN 'student' ELSE enrollment_stage END,
                 enrollment_stage_at = CASE WHEN ? IS NOT NULL AND enrollment_stage IS DISTINCT FROM 'enrolled'
                      THEN NOW() ELSE enrollment_stage_at END,
                 applicant_ref = COALESCE(?, applicant_ref),
                 visit_date = COALESCE(?, visit_date),
                 visit_start_time = COALESCE(?, visit_start_time),
                 visit_end_time = COALESCE(?, visit_end_time),
                 visit_office = COALESCE(?, visit_office)
             WHERE applicant_id = ?"
        )->execute([
            $status,
            $httpStatus,
            $result,
            $status === 'sent' ? null : mb_substr($message, 0, 4000),
            $status === 'sent' ? ($json['student_number'] ?? null) : null,
            $status === 'sent' ? ($json['student_number'] ?? null) : null,
            $status === 'sent' ? ($json['student_number'] ?? null) : null,
            $payload['applicant_ref'] ?? null,
            $visit['date'] ?? null,
            $visit['start'] ?? null,
            $visit['end'] ?? null,
            $visit['office'] ?? null,
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


/**
 * Refresh an applicant's current Enrollment status from the authenticated
 * Enrollment API when their result page loads. When applicantId is null, use
 * one bounded request for the administrator or SSO listing page.
 * Does not change acceptance, enrollment intent, or send new handoffs.
 */
function enrollment_handoff_refresh_status(PDO $db, ?int $applicantId = null): array
{
    $intakeUrl = trim((string)getenv('ENROLLMENT_INTAKE_URL'));
    $secret = trim((string)getenv('ENROLLMENT_INTAKE_SECRET'));
    if ($intakeUrl === '' || $secret === '') {
        return ['ok' => false, 'message' => 'Integration URL or secret is not configured.'];
    }
    $url = preg_replace('~/intake/?$~', '/status', $intakeUrl);
    if (!is_string($url) || $url === $intakeUrl) {
        return ['ok' => false, 'message' => 'Enrollment status URL could not be derived.'];
    }
    $parsedUrl = parse_url($url);
    if (!is_array($parsedUrl) || !in_array($parsedUrl['scheme'] ?? '', ['http', 'https'], true) ||
        empty($parsedUrl['host']) ||
        (APP_ENV === 'production' && ($parsedUrl['scheme'] ?? '') !== 'https')) {
        return ['ok' => false, 'message' => 'Enrollment status URL must use HTTPS in production.'];
    }
    if ($applicantId !== null && $applicantId < 1) {
        return ['ok' => false, 'message' => 'Invalid applicant identifier.'];
    }

    try {
        if ($applicantId === null) {
            $rows = $db->query("SELECT applicant_id, applicant_ref
                FROM enrollment_handoff_outbox
                WHERE applicant_ref IS NOT NULL AND status = 'sent'
                ORDER BY id DESC LIMIT 250")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $query = $db->prepare("SELECT applicant_id, applicant_ref
                FROM enrollment_handoff_outbox
                WHERE applicant_id=? AND applicant_ref IS NOT NULL AND status='sent'");
            $query->execute([$applicantId]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        }
        if (!$rows) {
            return ['ok' => true, 'checked' => 0, 'message' => 'No transferred applicant requires a status lookup.'];
        }

        $refs = array_values(array_unique(array_column($rows, 'applicant_ref')));
        $body = json_encode(['applicant_refs' => $refs], JSON_THROW_ON_ERROR);
        $ch = curl_init($url);
        if (!$ch) throw new RuntimeException('Could not initialize status request.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$secret,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $answer = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($answer) || $status !== 200) {
            return ['ok' => false, 'message' => 'Enrollment status is temporarily unavailable (HTTP '.$status.').'];
        }
        $json = json_decode($answer, true);
        if (!is_array($json) || !is_array($json['records'] ?? null)) {
            return ['ok' => false, 'message' => 'Enrollment returned an invalid status response.'];
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[(string)$row['applicant_ref']] = (int)$row['applicant_id'];
        }
        // Never silently reassign the Enrollment student number to another
        // Admissions applicant. A mismatch needs staff reconciliation.
        $current = $db->prepare("SELECT enrollment_student_number
            FROM enrollment_handoff_outbox WHERE applicant_id = ?");
        $update = $db->prepare("UPDATE enrollment_handoff_outbox
          SET enrollment_student_number=?,
              enrollment_stage=?,
              enrollment_detail_status=?,
              enrollment_stage_at=NOW()
          WHERE applicant_id=?
            AND (enrollment_student_number IS NULL OR enrollment_student_number = ?)
            AND (enrollment_student_number IS DISTINCT FROM ?
              OR enrollment_stage IS DISTINCT FROM ?
              OR enrollment_detail_status IS DISTINCT FROM ?)");
        $checked = 0;
        $verifiedRefs = [];
        foreach ($json['records'] as $record) {
            if (!is_array($record)) continue;
            $ref = $record['applicant_ref'] ?? null;
            if (!is_string($ref) || !isset($ids[$ref])) continue;
            $id = $ids[$ref];
            $current->execute([$id]);
            $existingNumber = $current->fetchColumn();
            $parsed = enrollment_parse_status_record(
                $record, $ref, is_string($existingNumber) ? $existingNumber : null
            );
            if ($parsed === null) continue;
            [$number, $stage, $detail] = $parsed;
            $update->execute([$number, $stage, $detail, $id, $number, $number, $stage, $detail]);
            $verifiedRefs[] = $ref;
            ++$checked;
        }
        return ['ok' => true, 'checked' => $checked, 'verified_refs' => $verifiedRefs, 'message' => 'Enrollment status checked.'];
    } catch (\Throwable $error) {
        error_log('Enrollment status read failed: '.$error->getMessage());
        return ['ok' => false, 'message' => 'Enrollment status could not be refreshed.'];
    }
}

/**
 * Validate one authenticated Enrollment status record before persistence.
 * The applicant reference must be from the original handoff; a student number
 * cannot be reassigned by a later callback or mismatched status response.
 * Old Enrollment servers only return stage; support those conservatively.
 */
function enrollment_parse_status_record(array $record, string $expectedRef, ?string $existingNumber = null): ?array
{
    if (($record['applicant_ref'] ?? null) !== $expectedRef) return null;
    $number = $record['student_number'] ?? null;
    $stage = $record['stage'] ?? null;
    if (!is_string($number) || !preg_match('/^[A-Za-z0-9-]{3,20}$/', $number) ||
        ($existingNumber !== null && $existingNumber !== '' && $existingNumber !== $number) ||
        !in_array($stage, ['student', 'enrolled'], true)) return null;

    $allowed = [
        'pending_enrollment',
        'pending_registrar_enrollment',
        'returned_for_correction',
        'confirmed_demonstration',
        'officially_enrolled',
    ];
    $detail = $record['enrollment_status'] ??
        ($stage === 'enrolled' ? 'officially_enrolled' : 'pending_registrar_enrollment');
    if (!is_string($detail) || !in_array($detail, $allowed, true)) return null;
    if (($detail === 'officially_enrolled') !== ($stage === 'enrolled')) return null;
    if (isset($record['cor_available']) &&
        (!is_bool($record['cor_available']) ||
         $record['cor_available'] !== in_array($detail, ['confirmed_demonstration','officially_enrolled'], true))) {
        return null;
    }
    return [$number, $stage, $detail];
}

/** Resolve enrollment status independently of the unchanged admission decision. */
function enrollment_display_status(
    ?array $handoff,
    bool $intentConfirmed,
    bool $liveStatusChecked = true
): array {
    if (!$intentConfirmed) return ['Not yet confirmed', 'badge-neutral'];
    if (!$handoff || ($handoff['status'] ?? null) !== 'sent') {
        return ['Pending transfer', 'badge-review'];
    }
    if (!$liveStatusChecked) return ['Temporarily unavailable', 'badge-review'];

    $detail = $handoff['enrollment_detail_status'] ?? null;
    $labels = [
        'pending_enrollment' => ['Pending Enrollment', 'badge-review'],
        'pending_registrar_enrollment' => ['Pending Registrar Enrollment', 'badge-review'],
        'returned_for_correction' => ['Returned for Correction', 'badge-review'],
        'confirmed_demonstration' => ['Confirmed (Demonstration)', 'badge-approved'],
        'officially_enrolled' => ['Officially Enrolled', 'badge-approved'],
    ];
    if (isset($labels[$detail])) return $labels[$detail];
    // Safe fallback for existing outbox rows from the older two-stage bridge.
    if (($handoff['enrollment_stage'] ?? null) === 'enrolled') {
        return ['Officially Enrolled', 'badge-approved'];
    }
    if (($handoff['enrollment_stage'] ?? null) === 'student') {
        return ['Pending Registrar Enrollment', 'badge-review'];
    }
    return ['Pending Enrollment', 'badge-review'];
}
