<?php
// ============================================================
// _queue_college_select.php
// Admin landing for the live queue: pick a college first.
// Expects: $db, $today (set by staff_queue.php).
// ============================================================

$departments = function_exists('departments_list') ? departments_list() : [];

// Per-college: today's session + queue counts
$qCounts = [];
foreach ($departments as $d) {
    $qCounts[$d] = ['queue' => 0, 'sessions' => 0, 'in_progress' => 0, 'waiting' => 0];
}
try {
    $stmt = $db->prepare(
        "SELECT s.department,
                COUNT(DISTINCT s.id) AS sessions,
                COUNT(q.id)          AS queue_total,
                SUM(CASE WHEN q.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN q.status = 'checked_in'  THEN 1 ELSE 0 END) AS waiting
           FROM interview_slots s
           LEFT JOIN interview_queue q ON q.slot_id = s.id
          WHERE s.slot_date = ?
          GROUP BY s.department"
    );
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll() as $row) {
        $qCounts[$row['department']] = [
            'queue'       => (int) $row['queue_total'],
            'sessions'    => (int) $row['sessions'],
            'in_progress' => (int) $row['in_progress'],
            'waiting'     => (int) $row['waiting'],
        ];
    }
} catch (\Throwable $e) { /* non-fatal */ }

ob_start();
?>

<?php if (empty($departments)): ?>
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary);font-size:var(--text-sm)">
        No colleges / departments configured in the system.
    </div>
<?php else: ?>
    <div class="college-grid">
        <?php foreach ($departments as $dept):
            $info  = $qCounts[$dept] ?? ['queue' => 0, 'sessions' => 0, 'in_progress' => 0, 'waiting' => 0];
            $live  = $info['in_progress'] > 0 || $info['waiting'] > 0;
        ?>
            <a href="<?= url('/staff/interviews/queue') ?>?college=<?= urlencode($dept) ?>"
               class="college-card <?= $live ? 'is-live' : '' ?>">
                <div class="college-card-icon">
                    <?= icon('ic_fluent_building_bank_24_regular', 24) ?>
                </div>
                <div class="college-card-name"><?= e($dept) ?></div>
                <div class="college-card-meta">
                    <?php if ($info['in_progress'] > 0): ?>
                        <div class="college-card-meta-row">
                            <span class="college-card-live"><span class="dot"></span> Live</span>
                        </div>
                    <?php endif; ?>
                    <div class="college-card-meta-row">
                        <?= icon('ic_fluent_calendar_ltr_24_regular', 12) ?>
                        <?= $info['sessions'] ?> session<?= $info['sessions'] !== 1 ? 's' : '' ?> today
                    </div>
                    <?php if ($info['queue'] > 0): ?>
                        <div class="college-card-meta-row">
                            <?= icon('ic_fluent_people_24_regular', 12) ?>
                            <?= $info['queue'] ?> applicant<?= $info['queue'] !== 1 ? 's' : '' ?>
                        </div>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Live Queue';
$pageBreadcrumb = [['label' => 'Interview Sessions', 'to' => '/staff/interviews']];
$activeNav = 'interviews';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
