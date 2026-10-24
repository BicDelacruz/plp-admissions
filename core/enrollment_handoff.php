<?php
// ============================================================
// core/enrollment_handoff.php
// Sends a confirmed, accepted applicant to the enrollment system.
//
// One HTTPS POST with a Bearer secret. Progress is kept in the
// enrollment_handoff_outbox table, so a failed send stays pending
// and staff can retry it. Nothing here ever throws: a failed send
// must never block the student's confirmation.
//
// Settings (environment): ENROLLMENT_HANDOFF_URL, ENROLLMENT_HANDOFF_SECRET
// ============================================================

const ENROLLMENT_HANDOFF_TIMEOUT = 10;   // seconds

// Admissions course code -> enrollment program code. A code that is not
// listed here and is not an enrollment program is rejected, never guessed.
const ENROLLMENT_PROGRAM_MAP = [
    'BSA'       => 'BSA',
    'BSBA'      => 'BSBA',
    'BSHM'      => 'BSHM',
    'BEED'      => 'BEED',
    'BSCS'      => 'BSCS',
    'BSIT'      => 'BSIT',
    'BSECE'     => 'BSECE',
    'BSN'       => 'BSN',
    'BSED-ENG'  => 'BSED',
    'BSED-FIL'  => 'BSED',
    'BSED-MATH' => 'BSED',
    'AB Psych'  => 'BSP',
    // BSENT has no enrollment program yet, so it is left out on purpose.
];

/** The code in the last brackets of a course name, or null. */
function enrollment_course_code(string $course): ?string
{
    if (preg_match('/\(([^()]+)\)\s*$/', trim($course), $m)) {
        return trim($m[1]);
    }
    return null;
}

/** Enrollment program code for an admissions course name, or null. */
function enrollment_program_code(string $course): ?string
{
    $code = enrollment_course_code($course);
    return ($code !== null && isset(ENROLLMENT_PROGRAM_MAP[$code])) ? ENROLLMENT_PROGRAM_MAP[$code] : null;
}

/** ADM-<year>-<id padded to 6>. The year is the first year of the school year. */
function enrollment_applicant_reference(string $schoolYear, int $applicantId): ?string
{
    if (!preg_match('/^(\d{4})/', $schoolYear, $m)) {
        return null;
    }
    return 'ADM-' . $m[1] . '-' . str_pad((string) $applicantId, 6, '0', STR_PAD_LEFT);
}

/**
 * Build the payload from live data.
 * Returns [payload, null] or [null, error message].
 */
function enrollment_handoff_payload(PDO $db, int $applicantId): array
{
    $stmt = $db->prepare(
        'SELECT a.id, a.applicant_type, a.course_applied, a.school_year,
                ar.result, ar.enrollment_intent, ar.released_at
         FROM applicants a
         JOIN admission_results ar ON ar.applicant_id = a.id
         WHERE a.id = ? LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [null, 'No released result for this applicant.'];
    }
    if ($row['result'] !== 'accepted' || $row['enrollment_intent'] !== 'confirmed') {
        return [null, 'The applicant is not accepted and confirmed.'];
    }

    $program = enrollment_program_code((string) $row['course_applied']);
    if ($program === null) {
        return [null, 'No enrollment program for "' . $row['course_applied'] . '". Add it to the program map first.'];
    }

    $reference = enrollment_applicant_reference((string) $row['school_year'], (int) $row['id']);
    if ($reference === null) {
        return [null, 'The school year "' . $row['school_year'] . '" has no four digit year.'];
    }

    $type = ($row['applicant_type'] === 'transferee') ? 'TRANSFEREE' : 'NEW_STUDENT';

    return [[
        'applicant_reference'   => $reference,
        'approved_program_code' => $program,
        'admission_date'        => substr((string) $row['released_at'], 0, 10),
        'admission_type'        => $type,
        'source_name'           => 'PLP Admissions',
        'course_name'           => (string) $row['course_applied'],
    ], null];
}

/** POST the payload. Returns [ok, message]. */
function enrollment_handoff_post(array $payload): array
{
    $url    = trim((string) getenv('ENROLLMENT_HANDOFF_URL'));
    $secret = trim((string) getenv('ENROLLMENT_HANDOFF_SECRET'));
    if ($url === '' || $secret === '') {
        return [false, 'ENROLLMENT_HANDOFF_URL or ENROLLMENT_HANDOFF_SECRET is not set.'];
    }
    if (!str_starts_with($url, 'https://') && APP_ENV === 'production') {
        return [false, 'ENROLLMENT_HANDOFF_URL must start with https://.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
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

    if ($body === false) {
        return [false, 'Could not reach enrollment: ' . ($err !== '' ? $err : 'no response') . '.'];
    }

    $json    = json_decode((string) $body, true);
    $message = is_array($json) ? (string) ($json['message'] ?? '') : '';

    if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['ok'])) {
        return [true, $message !== '' ? $message : 'Sent.'];
    }
    return [false, 'Enrollment answered HTTP ' . $status . ($message !== '' ? ': ' . $message : '.')];
}

/**
 * Send one applicant. Creates the outbox row if needed, records the attempt,
 * and marks it sent on success. Safe to call again: a sent row is left alone.
 * Returns [ok, message].
 */
function enrollment_handoff_send(PDO $db, int $applicantId): array
{
    try {
        [$payload, $buildError] = enrollment_handoff_payload($db, $applicantId);

        $db->prepare(
            'INSERT INTO enrollment_handoff_outbox (applicant_id, applicant_reference)
             VALUES (?, ?) ON CONFLICT (applicant_id) DO NOTHING'
        )->execute([$applicantId, $payload['applicant_reference'] ?? null]);

        $stmt = $db->prepare('SELECT status FROM enrollment_handoff_outbox WHERE applicant_id = ?');
        $stmt->execute([$applicantId]);
        if ($stmt->fetchColumn() === 'sent') {
            return [true, 'Already sent to Enrollment.'];
        }

        if ($payload === null) {
            [$ok, $message] = [false, $buildError];
        } else {
            [$ok, $message] = enrollment_handoff_post($payload);
        }

        $sentAt = $ok ? 'NOW()' : 'sent_at';
        $db->prepare(
            "UPDATE enrollment_handoff_outbox
             SET attempts = attempts + 1,
                 last_attempt_at = NOW(),
                 status = ?,
                 sent_at = $sentAt,
                 last_error = ?,
                 applicant_reference = COALESCE(?, applicant_reference)
             WHERE applicant_id = ?"
        )->execute([
            $ok ? 'sent' : 'pending',
            $ok ? null : mb_substr((string) $message, 0, 500),
            $payload['applicant_reference'] ?? null,
            $applicantId,
        ]);

        audit_log(
            $ok ? 'enrollment_handoff_sent' : 'enrollment_handoff_failed',
            "Applicant {$applicantId}: " . $message,
            'applicant', $applicantId
        );
        return [$ok, (string) $message];
    } catch (\Throwable $e) {
        error_log('enrollment handoff error: ' . $e->getMessage());
        return [false, 'The handoff could not be recorded.'];
    }
}
