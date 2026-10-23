<?php
// ============================================================
// modules/auth/security_check.php
// Standalone "Performing security verification" page. One blank page,
// Cloudflare Turnstile, a line of text. No layout, no auth card.
// A pass is kept in the session, then the visitor goes on to the page
// they asked for (sign in, register or forgot password).
// See captcha_gate() in core/helpers.php.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (Auth::check()) {
    header("Location: " . Auth::homeUrl()); exit;
}

// Where to go after the check. Only our own guest pages are allowed.
$next = (string) Session::get('captcha_next', '/login');
if (!preg_match('#^/(?:login|register|forgot-password)?(?:\?[\x21-\x7E]*)?$#', $next)) {
    $next = '/login';
}

// Already verified, or the check is off (no keys): nothing to do here.
if (captcha_passed()) {
    Session::remove('captcha_next');
    redirect($next);
}

$failed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (captcha_verify()) {
        captcha_mark_passed();
        Session::remove('captcha_next');
        redirect($next);
    }
    $failed = true;
}

header("Content-Security-Policy: " . auth_csp());
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Security verification</title>
<style>
html, body { height: 100%; margin: 0; }
body {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    color: #333;
    font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    text-align: center;
}
main { padding: 24px; }
h1 { margin: 0 0 8px; font-size: 20px; font-weight: 600; }
p { margin: 0 0 20px; color: #666; font-size: 14px; }
a { color: #2d6a4f; }
.cf-turnstile { display: inline-block; min-height: 65px; }
</style>
</head>
<body>
<main>
    <h1>Performing security verification</h1>
    <?php if ($failed): ?>
        <p>We could not verify your browser. <a href="<?= e(url('/security-check')) ?>">Try again</a></p>
    <?php else: ?>
        <p>Checking that you are a real person before you continue.</p>
        <form id="f" method="POST" action="<?= e(url('/security-check')) ?>">
            <?= csrf_field() ?>
            <div class="cf-turnstile"
                 data-sitekey="<?= e(TURNSTILE_SITE_KEY) ?>"
                 data-theme="light"
                 data-callback="done"></div>
        </form>
        <noscript><p>JavaScript is required to finish this check.</p></noscript>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        <script>function done() { document.getElementById('f').submit(); }</script>
    <?php endif; ?>
</main>
</body>
</html>
