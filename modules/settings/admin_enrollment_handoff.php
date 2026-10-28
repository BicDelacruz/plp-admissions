<?php
require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = filter_var($_POST['applicant_id'] ?? null, FILTER_VALIDATE_INT);
    if ($action === 'retry_sync') {
        $ids = $db->query("SELECT a.id FROM applicants a
          JOIN admission_results ar ON ar.applicant_id=a.id
          LEFT JOIN enrollment_handoff_outbox h ON h.applicant_id=a.id
          WHERE ar.result='accepted' AND ar.enrollment_intent='confirmed'
            AND (h.id IS NULL OR h.status IN ('pending','rejected'))
          ORDER BY a.id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
        $sent=0; $failed=0;
        foreach ($ids as $candidate) {
            [$ok,$message]=enrollment_handoff_send($db,(int)$candidate);
            $ok ? ++$sent : ++$failed;
        }
        audit_log('enrollment_handoff_retry_sync',"Batch retry: sent={$sent}; failed={$failed}",'integration');
        Session::flash($failed ? 'error' : 'success',"Retry Sync processed ".count($ids)." applicants: {$sent} sent, {$failed} need attention.");
    } elseif (in_array($action,['retry','accept'],true) && $id && $id>0) {
        if ($action==='accept') {
            $reason=trim((string)($_POST['reason'] ?? ''));
            $gwa = trim((string)($_POST['inputted_gwa'] ?? ''));
            if (!preg_match('/^(?:7[5-9]|[89][0-9]|100)(?:\\.[0-9]{1,2})?$/', $gwa)) {
                Session::flash('error', 'Enter a report card GWA from 75 to 100.');
                redirect('/admin/enrollment-handoff');
            }
            if (mb_strlen($reason)<8 || mb_strlen($reason)>500) {
                Session::flash('error','A written approval reason of 8 to 500 characters is required.');
                redirect('/admin/enrollment-handoff');
            }
            try {
                $db->beginTransaction();
                $q=$db->prepare("SELECT a.id,a.overall_status,a.applicant_type,ar.result,ar.enrollment_intent
                  FROM applicants a LEFT JOIN admission_results ar ON ar.applicant_id=a.id
                  WHERE a.id=? FOR UPDATE OF a");
                $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
                if (!$row || $row['overall_status']==='withdrawn') throw new RuntimeException('Applicant not found or withdrawn.');
                if (!in_array($row['applicant_type'],['freshman','transferee','foreign'],true)) throw new RuntimeException('Unsupported applicant type.');
                $q=$db->prepare("SELECT status FROM enrollment_handoff_outbox WHERE applicant_id=?");
                $q->execute([$id]);
                if ($q->fetchColumn()==='sent') throw new RuntimeException('Already transferred successfully. No decision override was made.');
                $remark='Manual acceptance by administrator #'.Auth::id().'. Reason: '.$reason;
                $q=$db->prepare("INSERT INTO admission_results
                  (applicant_id,result,enrollment_intent,intent_submitted_at,remarks,released_by,released_at)
                  VALUES (?,'accepted','confirmed',NOW(),?,?,NOW())
                  ON CONFLICT (applicant_id) DO UPDATE SET result='accepted',enrollment_intent='confirmed',
                    intent_submitted_at=NOW(),remarks=concat_ws(E'\n',NULLIF(admission_results.remarks,''),EXCLUDED.remarks),
                    released_by=EXCLUDED.released_by,released_at=NOW()");
                $q->execute([$id,$remark,Auth::id()]);
                $db->prepare("UPDATE applicants SET overall_status='released',
                    inputted_gwa=?, gwa_verified_at=NULL, gwa_verified_by=NULL WHERE id=?")->execute([$gwa,$id]);
                $db->commit();
                audit_log('admission_manual_accept_for_handoff',$remark,'applicant',$id);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                Session::flash('error','Manual acceptance failed: '.$e->getMessage());
                redirect('/admin/enrollment-handoff');
            }
        }
        [$ok,$message]=enrollment_handoff_send($db,$id,$action==='retry');
        audit_log($ok?'enrollment_handoff_admin_sent':'enrollment_handoff_admin_failed',"Applicant {$id}: {$message}",'applicant',$id);
        Session::flash($ok?'success':'error',($action==='accept'?'Acceptance recorded. ':'').($ok?'Handoff sent. ':'Handoff not sent. ').$message);
    } else {
        Session::flash('error','Choose a valid applicant and action.');
    }
    redirect('/admin/enrollment-handoff');
}

$rows=$db->query("SELECT a.id,a.course_applied,a.school_year,a.applicant_type,a.overall_status,
 u.first_name,u.middle_name,u.last_name,u.name,ar.result,ar.enrollment_intent,
 h.status AS handoff_status,h.attempts,h.last_error,h.enrollment_student_number
 FROM applicants a JOIN users u ON u.id=a.user_id
 LEFT JOIN admission_results ar ON ar.applicant_id=a.id
 LEFT JOIN enrollment_handoff_outbox h ON h.applicant_id=a.id
 WHERE a.overall_status<>'withdrawn' ORDER BY a.id DESC LIMIT 250")->fetchAll(PDO::FETCH_ASSOC);
ob_start();
?>
<section class="card" style="padding:var(--space-6)">
<h2>Admissions to Enrollment</h2>
<p>Retry Sync reattempts up to ten accepted and confirmed applicants. Enrollment is accessed only through its authenticated REST intake.</p>
<form method="POST" style="margin-top:var(--space-4)"><?= csrf_field() ?><input type="hidden" name="action" value="retry_sync">
<button type="submit" class="btn btn-primary">Retry Sync</button></form>
</section>
<section class="card" style="padding:var(--space-6);margin-top:var(--space-5)">
<h2>Manual Acceptance and Retry Handoff</h2>
<form method="POST"><?= csrf_field() ?>
<label class="form-label" for="handoff-applicant">Applicant</label>
<select id="handoff-applicant" name="applicant_id" class="form-control" required style="width:100%">
<option value="">Select an applicant</option>
<?php foreach($rows as $r): $full=trim(implode(' ',array_filter([$r['first_name'],$r['middle_name'],$r['last_name']])))?:$r['name']; ?>
<option value="<?= (int)$r['id'] ?>"><?= e($full) ?> · <?= e($r['course_applied']) ?> · <?= e($r['result']?:'Pending') ?></option>
<?php endforeach; ?>
</select>
<label class="form-label" for="accept-gwa" style="margin-top:var(--space-3)">Report Card GWA (required for manual acceptance)</label>
<input class="form-control" id="accept-gwa" name="inputted_gwa" type="number" min="75" max="100" step="0.01"
    placeholder="e.g. 89.50">
<p style="color:var(--text-secondary);margin-top:var(--space-1)">Staff verifies the recorded GWA separately using the single Verify GWA button on the applicant's document review page.</p>
<label class="form-label" for="accept-reason" style="margin-top:var(--space-3)">Reason for manual acceptance</label>
<textarea id="accept-reason" name="reason" class="form-control" rows="3" maxlength="500" placeholder="Required only for manual acceptance"></textarea>
<div style="display:flex;gap:var(--space-3);flex-wrap:wrap;margin-top:var(--space-3)">
<button class="btn btn-secondary" name="action" value="retry" type="submit">Retry Handoff</button>
<button class="btn btn-primary" name="action" value="accept" type="submit" data-confirm="Record an audited manual acceptance and immediately attempt Enrollment handoff?" data-confirm-ok="Accept and Transfer">Manually Accept and Transfer</button>
</div>
</form>
<p style="margin-top:var(--space-3);color:var(--text-secondary)">Manual acceptance changes the Admissions decision and intent. It does not mean Registrar enrollment is complete.</p>
</section>
<section class="card" style="padding:var(--space-6);margin-top:var(--space-5)">
<h2>Latest Handoff Status</h2><div style="overflow-x:auto"><table class="auto-table"><thead><tr><th>Applicant ID</th><th>Name</th><th>Course</th><th>Decision</th><th>Intent</th><th>Handoff</th><th>Attempts</th><th>Student Number</th><th>Last Error</th></tr></thead><tbody>
<?php foreach($rows as $r): $full=trim(implode(' ',array_filter([$r['first_name'],$r['middle_name'],$r['last_name']])))?:$r['name']; ?>
<tr><td><?= (int)$r['id'] ?></td><td><?= e($full) ?></td><td><?= e($r['course_applied']) ?></td><td><?= e($r['result']?:'Pending') ?></td><td><?= e($r['enrollment_intent']?:'Awaiting') ?></td><td><?= e($r['handoff_status']?:'Not sent') ?></td><td><?= (int)$r['attempts'] ?></td><td><?= e($r['enrollment_student_number']?:'Not assigned') ?></td><td><?= e($r['last_error']?:'None') ?></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php
$content=ob_get_clean(); $pageTitle='Enrollment Handoff'; $activeNav='enrollment-handoff'; $pageWide=true;
include VIEWS_PATH.'/layouts/app.php';
