<?php
require_once CORE_PATH . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$secret=trim((string)getenv('ENROLLMENT_INTAKE_SECRET'));
$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
$token=str_starts_with($auth,'Bearer ')?substr($auth,7):'';
if ($secret==='' || $token==='' || !hash_equals($secret,$token)) {
 http_response_code(401); echo json_encode(['error'=>'Unauthorized']); return;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') {
 http_response_code(405); header('Allow: POST'); echo json_encode(['error'=>'Use POST']); return;
}
try {
 $db=db();
 $ids=$db->query("SELECT a.id FROM applicants a JOIN admission_results ar ON ar.applicant_id=a.id
 LEFT JOIN enrollment_handoff_outbox h ON h.applicant_id=a.id
 WHERE ar.result='accepted' AND ar.enrollment_intent='confirmed'
 AND (h.id IS NULL OR h.status IN ('pending','rejected')) ORDER BY a.id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
 $sent=0;$failed=0;$results=[];
 foreach($ids as $id) {
  [$ok,$message]=enrollment_handoff_send($db,(int)$id);
  $ok?++$sent:++$failed;
  $results[]=['applicant_id'=>(int)$id,'status'=>$ok?'sent':'not_sent','message'=>$message];
 }
 echo json_encode(['status'=>$failed?'partial':'completed','processed'=>count($ids),'sent'=>$sent,'failed'=>$failed,'results'=>$results]);
} catch(Throwable $e) {
 error_log('Enrollment retry sync failed: '.$e->getMessage());
 http_response_code(503); echo json_encode(['error'=>'Retry sync is temporarily unavailable.']);
}
