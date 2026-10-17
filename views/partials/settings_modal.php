<?php
// ============================================================
// views/partials/settings_modal.php
// The Settings modal, included once by views/layouts/app.php.
// Left: the sections of the role's settings page. Right: that page
// (modules/settings/{student,staff,admin}.php) loaded in an iframe with
// ?embed=1&section=<key>, so its content is the same cards, unchanged.
// Opened by any [data-settings-open] link, or by ?settings=1 in the URL.
// Behaviour: SettingsModal in public/assets/js/app.js.
// Expects $userRole and $isStudent from the layout.
// ============================================================

if ($isStudent) {
    $settingsBase  = '/student/settings';
    $settingsNav   = [
        'appearance' => ['Appearance', 'palette'],
        'profile'    => ['Profile',    'user'],
        'password'   => ['Password',   'lock'],
    ];
} elseif ($userRole === ROLE_ADMIN) {
    $settingsBase  = '/admin/settings';
    $settingsNav   = [
        'appearance' => ['Appearance',     'palette'],
        'branding'   => ['School Branding', 'image'],
        'password'   => ['Password',       'lock'],
    ];
} else {
    $settingsBase  = '/staff/settings';
    $settingsNav   = [
        'appearance' => ['Appearance',   'palette'],
        'password'   => ['Password',     'lock'],
        'about'      => ['Help & About', 'info'],
    ];
}
$settingsFirst = array_key_first($settingsNav);
?>
<div id="settings-modal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-label="Settings">
    <div class="modal settings-modal">
        <nav class="settings-modal-nav" aria-label="Settings sections">
            <div class="settings-modal-nav-label">Settings</div>
            <?php foreach ($settingsNav as $key => [$label, $ico]): ?>
                <button type="button" class="nav-item<?= $key === $settingsFirst ? ' active' : '' ?>"
                        data-settings-nav="<?= e($key) ?>">
                    <?= icon($ico, 16) ?>
                    <span class="nav-label"><?= e($label) ?></span>
                </button>
            <?php endforeach; ?>
        </nav>
        <div class="settings-modal-body">
            <button type="button" class="btn-icon settings-modal-close" data-settings-close aria-label="Close settings">
                <?= icon('x', 18) ?>
            </button>
            <iframe id="settings-modal-frame" class="settings-modal-frame" title="Settings"
                    data-base="<?= e(url($settingsBase)) ?>" data-first="<?= e($settingsFirst) ?>"></iframe>
        </div>
    </div>
</div>
