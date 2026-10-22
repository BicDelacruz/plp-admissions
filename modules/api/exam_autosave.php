<?php
// ============================================================
// modules/api/exam_autosave.php
// AJAX endpoint: save exam draft answers (only while the slot is open)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
csrf_check();

$userId = Auth::id();
$db = db();

$stmt = $db->prepare('SELECT id FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) {
    echo json_encode(['ok' => false, 'error' => 'No applicant']);
    exit;
}

$examId  = (int)($_POST['exam_id'] ?? 0);
$slotId  = (int)($_POST['slot_id'] ?? 0);
$answers = $_POST['answers'] ?? '{}';

if (!$examId || !$slotId) {
    echo json_encode(['ok' => false, 'error' => 'Missing exam_id or slot_id']);
    exit;
}
if (!is_string($answers) || !is_array(json_decode($answers, true))) {
    echo json_encode(['ok' => false, 'error' => 'Invalid answers']);
    exit;
}

// The slot must be this applicant's own
$stmt = $db->prepare('SELECT 1 FROM applicant_exam_slots WHERE applicant_id = ? AND slot_id = ? LIMIT 1');
$stmt->execute([$applicant['id'], $slotId]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid slot']);
    exit;
}

// Check not already submitted
$stmt = $db->prepare('SELECT id FROM exam_results WHERE applicant_id = ? AND exam_id = ? LIMIT 1');
$stmt->execute([$applicant['id'], $examId]);
if ($stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Already submitted']);
    exit;
}

// One statement: write only while the slot is open, judged by the database
// clock (see slot_close_sql). No row back means the slot has closed.
$stmt = $db->prepare(
    'INSERT INTO exam_drafts (applicant_id, exam_id, slot_id, answers)
     SELECT CAST(? AS integer), CAST(? AS integer), s.id, ?
       FROM exam_slot_schedule s
      WHERE s.id = ? AND now() <= ' . slot_close_sql('s') . '
     ON CONFLICT (applicant_id, exam_id, slot_id)
     DO UPDATE SET answers = EXCLUDED.answers, saved_at = now()
     RETURNING saved_at'
);
$stmt->execute([$applicant['id'], $examId, $answers, $slotId]);
$savedAt = $stmt->fetchColumn();

if ($savedAt === false) {
    echo json_encode(['ok' => false, 'closed' => true, 'error' => 'Exam closed']);
    exit;
}

echo json_encode(['ok' => true, 'saved_at' => $savedAt]);
