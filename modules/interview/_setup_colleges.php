<?php
// _setup_colleges.php — College card grid for admin
// Variables: $departments, $collegeCounts
//
// The way back (Interview Sessions > Interview Setup) is the breadcrumb set
// in staff_setup.php, not a button. Cards use the shared selector-card layout
// (.select-grid / .select-card in app.css).
?>

<?php if (empty($departments)): ?>
    <div class="select-empty">No colleges / departments configured in the system.</div>
<?php else: ?>
    <div class="select-grid college-grid">
        <?php foreach ($departments as $dept):
            $info = $collegeCounts[$dept] ?? ['sessions' => 0, 'upcoming' => 0];
            $needsSetup = ((int)$info['upcoming']) === 0;
        ?>
            <a href="<?= url('/staff/interviews/setup') ?>?college=<?= urlencode($dept) ?>"
               class="select-card college-card">
                <?php if ($needsSetup): ?>
                    <span class="select-card-dot" title="No upcoming sessions — needs setup"></span>
                <?php endif; ?>
                <div class="select-card-title"><?= e($dept) ?></div>
                <div class="select-card-meta">
                    <div class="select-card-meta-row">
                        <?= (int)$info['upcoming'] ?> upcoming
                        <?php if ($info['sessions'] !== $info['upcoming']): ?>
                            &middot; <?= (int)$info['sessions'] ?> total
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
