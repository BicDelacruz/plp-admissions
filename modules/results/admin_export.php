<?php
// ============================================================
// modules/results/admin_export.php
// M6 — Admin / SSO: export all results as CSV or view summary
//
// Access: Admin and SSO. SSO is the office that actually files
// admissions paperwork with the registrar, so they need the same
// CSV/summary that Admin has. Dean is intentionally excluded —
// Dean's job is academic oversight of results, not bulk export,
// and their per-college Results view is at /staff/results.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN, ROLE_SSO);

$db = db();

// -- CSV export -------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $schoolYear = $_GET['sy'] ?? school_setting('current_school_year', '');
    $stmt = $db->prepare(
        'SELECT u.name, u.email, a.applicant_type, a.course_applied,
                a.school_year, a.overall_status,
                ar.result, ar.remarks, ar.released_at,
                er.score, er.total_items
         FROM applicants a
         JOIN users u ON u.id = a.user_id
         LEFT JOIN admission_results ar ON ar.applicant_id = a.id
         LEFT JOIN exam_results er ON er.applicant_id = a.id
         WHERE a.school_year = ?
         ORDER BY u.name'
    );
    $stmt->execute([$schoolYear]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plp_results_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['Name','Email','Type','Course','School Year','Stage','Result','Remarks','Released At','Exam Score','Total Items']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['name'], $r['email'], $r['applicant_type'], $r['course_applied'],
            $r['school_year'], $r['overall_status'],
            $r['result'] ?? '', $r['remarks'] ?? '',
            $r['released_at'] ?? '',
            $r['score'] ?? '', $r['total_items'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// -- Summary view -----------------------------------------------
$schoolYear = $_GET['sy'] ?? school_setting('current_school_year', '');
$stmt = $db->prepare(
    'SELECT ar.result, COUNT(*) as cnt
     FROM admission_results ar
     JOIN applicants a ON a.id = ar.applicant_id
     WHERE a.school_year = ?
     GROUP BY ar.result'
);
$stmt->execute([$schoolYear]);
$resultCounts = array_column($stmt->fetchAll(), 'cnt', 'result');

$totalReleased = array_sum($resultCounts);

$stmt = $db->prepare(
    'SELECT COUNT(*) FROM applicants WHERE school_year=?'
);
$stmt->execute([$schoolYear]);
$totalApplicants = (int)$stmt->fetchColumn();

// School years list
$years = $db->query(
    'SELECT DISTINCT school_year FROM applicants ORDER BY school_year DESC'
)->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>

<!-- Year filter -->
<div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-6);flex-wrap:wrap">
    <?php foreach ($years as $yr): ?>
        <a href="?sy=<?= urlencode($yr) ?>"
           class="btn <?= $yr === $schoolYear ? 'btn-primary' : 'btn-ghost' ?> btn-sm"><?= e($yr) ?></a>
    <?php endforeach; ?>
</div>

<!-- Result breakdown (pure CSS). One color family, counts and shares printed on the rows. -->
<?php if ($totalReleased > 0): ?>
<div class="card" style="padding:var(--space-6)">
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:var(--space-3);flex-wrap:wrap;margin-bottom:var(--space-5)">
        <span style="font-weight:var(--weight-semibold)">Result Breakdown</span>
        <span style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= number_format($totalReleased) ?> released of <?= number_format($totalApplicants) ?> applicants, <?= e($schoolYear) ?></span>
    </div>
    <?php foreach (['accepted' => ['Accepted','var(--accent-light)'], 'rejected' => ['Not Accepted','var(--border-strong)']] as $key => [$label, $color]):
        $cnt = $resultCounts[$key] ?? 0;
        $pct = $totalReleased > 0 ? round(($cnt / $totalReleased) * 100) : 0;
    ?>
        <div style="margin-bottom:var(--space-4)">
            <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= $label ?></span>
                <span style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= $cnt ?> (<?= $pct ?>%)</span>
            </div>
            <div style="height:10px;background:var(--bg-subtle);border-radius:var(--radius);overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:var(--radius)"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
    <div style="text-align:center;padding:var(--space-10);color:var(--text-tertiary);font-size:var(--text-sm)">
        No results released yet for <?= e($schoolYear) ?>.
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Results Export';
$pageActions = ui_button('Export CSV', ['href' => '?export=csv&sy=' . urlencode($schoolYear), 'variant' => 'primary', 'icon' => 'ic_fluent_arrow_download_24_regular']);
$activeNav = 'results-export';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
