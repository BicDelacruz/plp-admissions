<?php
// ============================================================
// modules/results/staff_send_enrollment.php
// Staff POST: send (or retry sending) one confirmed applicant to the
// Enrollment system. Same code path as the automatic send on confirm.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_DEAN, ROLE_ADMIN);
csrf_check();

$db          = db();
$applicantId = (int)($_GET['id'] ?? 0);

if (!$applicantId) {
    Session::flash('error', 'Missing applicant.');
    redirect('/staff/results');
}

// A Dean only handles applicants in their own college.
if (Auth::role() === ROLE_DEAN) {
    $stmt = $db->prepare('SELECT course_applied FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $course = (string) $stmt->fetchColumn();
    if ($course === '' || !in_array($course, courses_in_department((string) user_department(Auth::id())), true)) {
        Session::flash('error', 'That applicant is not in your college.');
        redirect('/staff/results');
    }
}

[$ok, $message] = enrollment_handoff_send($db, $applicantId);

if ($ok) {
    Session::flash('success', 'Sent to Enrollment. ' . $message);
} else {
    Session::flash('error', 'Not sent to Enrollment. ' . $message);
}
redirect('/staff/results');
