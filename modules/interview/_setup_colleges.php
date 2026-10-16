<?php
// _setup_colleges.php — College card grid for admin
// Variables: $departments, $collegeCounts
//
// The "← Back" target (the two-card /staff/interviews landing) is only
// reachable for Admin. SSO lands here directly from the sidebar and
// has nowhere to go back to, so we hide the button for them.
$_isSSO_picker = (Auth::role() === ROLE_SSO);
?>

<?php if (!$_isSSO_picker): ?>
    <div data-page-actions>
        <a href="<?= url('/staff/interviews') ?>" class="btn-icon" aria-label="Back to interviews" title="Back to interviews"><?= icon('ic_fluent_arrow_left_24_regular', 18) ?></a>
    </div>
<?php endif; ?>

<?php if (empty($departments)): ?>
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary);font-size:var(--text-sm)">
        No colleges / departments configured in the system.
    </div>
<?php else: ?>
    <div class="college-grid">
        <?php foreach ($departments as $dept):
            $info = $collegeCounts[$dept] ?? ['sessions' => 0, 'upcoming' => 0];
            $needsSetup = ((int)$info['upcoming']) === 0;
        ?>
            <a href="<?= url('/staff/interviews/setup') ?>?college=<?= urlencode($dept) ?>"
               class="college-card">
                <?php if ($needsSetup): ?>
                    <span class="college-card-dot" title="No upcoming sessions — needs setup"></span>
                <?php endif; ?>
                <div class="college-card-icon">
                    <?= icon('ic_fluent_building_bank_24_regular', 24) ?>
                </div>
                <div class="college-card-name"><?= e($dept) ?></div>
                <div class="college-card-meta">
                    <div class="college-card-meta-row">
                        <?= icon('ic_fluent_calendar_ltr_24_regular', 12) ?>
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
