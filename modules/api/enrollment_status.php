<?php
// ============================================================
// modules/api/enrollment_status.php
// POST from the Enrollment system: an applicant became a student, or was
// enrolled (COR issued). No login and no CSRF: the caller proves itself with
// the shared secret, like the intake call in the other direction.
//
// Body (JSON): { "applicant_ref": "ADM-2026-000042",
//                "stage": "student" | "enrolled",
//                "student_number": "2026-00012" }
// Setting (environment): ENROLLMENT_STATUS_SECRET, must equal
// ADMISSIONS_STATUS_SECRET in the Enrollment project.
// Safe to repeat. The stage only moves forward, so a late or repeated
// "student" never undoes "enrolled".
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

header('Content-Type: application/json');

function status_reply(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$secret = trim((string) getenv('ENROLLMENT_STATUS_SECRET'));
if ($secret === '') {
    status_reply(503, ['error' => 'The status endpoint is not configured.']);
}

// Apache can hide the Authorization header from PHP; Vercel passes it through.
$header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($header === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $header = (string) $value;
        }
    }
}
if (!str_starts_with($header, 'Bearer ') || !hash_equals($secret, trim(substr($header, 7)))) {
    status_reply(401, ['error' => 'Not authorized.']);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$parsed = enrollment_status_parse(is_array($body) ? $body : []);
if (is_string($parsed)) {
    status_reply(400, ['error' => $parsed]);
}
[$applicantId, $stage, $studentNumber] = $parsed;

try {
    $db   = db();
    $stmt = $db->prepare(
        "UPDATE enrollment_handoff_outbox
            SET enrollment_stage = ?, enrollment_student_number = ?, enrollment_stage_at = NOW()
          WHERE applicant_id = ?
            AND (enrollment_stage IS NULL OR enrollment_stage = 'student')
            AND (enrollment_stage IS DISTINCT FROM ?)"
    );
    $stmt->execute([$stage, $studentNumber, $applicantId, $stage]);

    if ($stmt->rowCount() > 0) {
        audit_log('enrollment_status_' . $stage, "Applicant {$applicantId}: Enrollment reports {$stage} {$studentNumber}", 'applicant', $applicantId);
        status_reply(200, ['status' => 'recorded']);
    }

    $exists = $db->prepare('SELECT 1 FROM enrollment_handoff_outbox WHERE applicant_id = ?');
    $exists->execute([$applicantId]);
    if (!$exists->fetchColumn()) {
        status_reply(404, ['error' => 'No such applicant was sent to Enrollment.']);
    }
    status_reply(200, ['status' => 'unchanged']);
} catch (\Throwable $e) {
    error_log('enrollment status error: ' . $e->getMessage());
    status_reply(500, ['error' => 'The status could not be recorded.']);
}
