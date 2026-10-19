<?php
// ============================================================
// views/layouts/auth.php
// Shared layout for every auth page (login, register, forgot / reset
// password, verify email, privacy policy).
//
// Optional variables set by the page before including this file:
//   $pageTitle        string  Browser title
//   $authWide         bool    Register only: no photo panel, top bar + wide
//                             content instead (so a long form gets the space)
//   $authTopbarLink   array   [text, label, href] shown on the right of the
//                             wide top bar
//   $hidePrivacyLink  bool    Hide the footer Privacy Policy link
// ============================================================
$schoolName  = school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig');
$accentColor = school_setting('accent_color', '#2d6a4f');
$pageTitle   = $pageTitle ?? 'Welcome';
$authLogo    = school_setting('school_logo', '');
$authLogoUrl = $authLogo
    ? (str_starts_with($authLogo, 'http') ? $authLogo : url($authLogo))
    : asset('img/' . rawurlencode('plp logo.png'));
$authPhotoUrl = asset('img/' . rawurlencode('schol blg.jpg'));
?>
<?php
header("Content-Security-Policy: " . auth_csp());
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($schoolName) ?></title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap">

    <?= phosphor_head() ?>

    <!-- Critical canvas colour: applies before app.css arrives so page switches never flash -->
    <style>
        html{background:#fff;color-scheme:light}
    </style>
    <!-- Bootstrap 5.3.3 + Bootstrap Icons, served locally (works offline). Order: Bootstrap -> bootstrap-compat.css -> app.css -->
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/bootstrap-compat.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">

    <script>
        (function(){
            // Auth pages are always light (no theme switch here).
            window.PLP_FORCE_LIGHT = true;
            document.documentElement.dataset.theme = 'light';
            var fs = null; try { fs = localStorage.getItem('plp_font_size'); } catch (e) {}
            document.documentElement.dataset.fontSize = ['small','medium','large','xlarge'].indexOf(fs) > -1 ? fs : 'medium';
        })();
    </script>
</head>
<body>

<?php if (!empty($authWide)): ?>
<div class="auth-page auth-page--wide">
    <header class="auth-topbar">
        <a class="auth-brand auth-brand--bar" href="<?= url('/login') ?>">
            <img class="auth-brand-logo" src="<?= e($authLogoUrl) ?>" alt="PLP seal">
            <span>
                <span class="auth-brand-title">PLP Admissions</span>
                <span class="auth-brand-sub"><?= e($schoolName) ?></span>
            </span>
        </a>
        <?php if (!empty($authTopbarLink)): ?>
        <p class="auth-topbar-link">
            <?= e($authTopbarLink['text']) ?>
            <a class="auth-link" href="<?= e($authTopbarLink['href']) ?>"><?= e($authTopbarLink['label']) ?></a>
        </p>
        <?php endif; ?>
    </header>
    <main class="auth-main">
        <?= $content ?? '' ?>
    </main>
    <?php if (empty($hidePrivacyLink)): ?>
    <footer class="auth-legal"><a href="<?= url('/privacy') ?>">Privacy Policy</a></footer>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="auth-page" style="--auth-photo:url('<?= e($authPhotoUrl) ?>')">
    <aside class="auth-aside">
        <div class="auth-brand">
            <img class="auth-brand-logo" src="<?= e($authLogoUrl) ?>" alt="PLP seal">
            <div>
                <p class="auth-brand-title">PLP Admissions</p>
                <p class="auth-brand-sub"><?= e($schoolName) ?></p>
            </div>
        </div>
        <div class="auth-aside-copy">
            <h2>Your application, in one place.</h2>
            <ul class="auth-points">
                <li>Upload your requirements (PDF, JPG, PNG or WEBP, 4 MB max)</li>
                <li>See your exam and interview schedule, then your result</li>
                <li>Verify your email with the code we send before signing in</li>
            </ul>
        </div>
        <p class="auth-aside-foot">Admissions Office</p>
    </aside>
    <div class="auth-pane">
        <main class="auth-main">
            <?= $content ?? '' ?>
        </main>
        <?php if (empty($hidePrivacyLink)): ?>
        <footer class="auth-legal"><a href="<?= url('/privacy') ?>">Privacy Policy</a></footer>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script src="<?= asset('js/jquery.min.js') ?>"></script>
<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<script>setAccentColor('<?= e($accentColor) ?>');</script>
<script>
// Auth UI (jQuery): show-password toggle, confirm-password check, loading label.
jQuery(function ($) {
    var eye = '<i class="ph-bold ph-eye" style="font-size:18px" aria-hidden="true"></i>';

    // One show/hide password toggle for every auth page.
    $('input[type="password"]').each(function () {
        var $in = $(this);
        if ($in.parent().hasClass('auth-pw')) return;
        var $wrap = $('<div class="auth-pw"></div>');
        $in.wrap($wrap);
        $('<button type="button" class="auth-pw-eye" aria-label="Show password"></button>')
            .html(eye)
            .insertAfter($in)
            .on('click', function () {
                var show = $in.attr('type') === 'password';
                $in.attr('type', show ? 'text' : 'password');
                $(this).toggleClass('on', show).attr('aria-label', show ? 'Hide password' : 'Show password');
                $(this).find('i').toggleClass('ph-eye', !show).toggleClass('ph-eye-slash', show);
            });
    });

    // Confirm password must match (client hint only; server still validates).
    var $pw = $('#password'), $cf = $('#password_confirm');
    if ($pw.length && $cf.length) {
        $cf.on('input blur', function () {
            var bad = $cf.val() !== '' && $cf.val() !== $pw.val();
            $cf.toggleClass('error', bad);
        });
    }

    // Live email check on blur (shows the red message before submit).
    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    $('input[type="email"]').on('blur input', function (ev) {
        var $in = $(this), v = $.trim($in.val());
        if (ev.type === 'input' && !$in.hasClass('error')) return;
        var bad = v !== '' && !emailRe.test(v);
        $in.siblings('.form-error').remove();
        $in.toggleClass('error', bad);
        if (bad) $('<span class="form-error">Enter a valid email address.</span>').insertAfter($in);
    });

    // Loading label on submit (app.js already disables the button).
    $('form[data-once]').on('submit', function () {
        var $b = $(this).find('[type="submit"]').first();
        if ($b.length && !$b.data('label')) $b.data('label', $b.text()).attr('aria-busy', 'true');
    });
});
</script>
<?php if (CAPTCHA_ENABLED): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>

</body>
</html>