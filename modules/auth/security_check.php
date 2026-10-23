<?php
// ============================================================
// modules/auth/security_check.php
// "Performing security verification" page. Runs Cloudflare Turnstile
// once, then sends the visitor on to the page they asked for
// (sign in, register or forgot password). See captcha_gate() in
// core/helpers.php.
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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (captcha_verify()) {
        captcha_mark_passed();
        Session::remove('captcha_next');
        redirect($next);
    }
    $error = 'We could not verify your browser. Please try again.';
}

// -- View --------------------------------------------------------
ob_start();
?>
<div class="auth-card animate-fade-in sec-check">

    <div class="auth-header">
        <h1 class="auth-title">Performing security verification</h1>
        <p class="auth-subtitle">We are making sure you are a real person before you continue.</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($error) ?>
        </div>
        <a class="btn btn-primary btn-block" href="<?= url('/security-check') ?>">Try again</a>
    <?php else: ?>
        <form id="sec-form" method="POST" action="<?= url('/security-check') ?>">
            <?= csrf_field() ?>
            <div class="sec-status" id="sec-status" role="status" aria-live="polite">
                <span class="sec-spin" aria-hidden="true"></span>
                <span id="sec-msg">Verifying, this only takes a moment...</span>
            </div>
            <div class="cf-turnstile"
                 data-sitekey="<?= e(TURNSTILE_SITE_KEY) ?>"
                 data-theme="auto"
                 data-appearance="interactive-only"
                 data-callback="plpSecDone"
                 data-error-callback="plpSecFail"
                 data-expired-callback="plpSecFail"></div>
            <noscript>
                <div class="alert alert-error" role="alert">JavaScript is required to finish the security verification.</div>
            </noscript>
        </form>
        <div class="sec-retry" id="sec-retry" hidden>
            <div class="alert alert-error" role="alert">
                <?= icon('ic_fluent_info_24_regular', 16) ?>
                The verification did not finish.
            </div>
            <a class="btn btn-primary btn-block" href="<?= url('/security-check') ?>">Try again</a>
        </div>
    <?php endif; ?>

    <p class="sec-note">Protected by Cloudflare. This check is not repeated on the form.</p>
</div>

<style>
.sec-status {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-4);
    margin-bottom: var(--space-4);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: var(--radius-md, 8px);
    color: var(--text-secondary);
    font-size: var(--text-sm);
}
.sec-spin {
    flex: none;
    width: 20px;
    height: 20px;
    border: 2px solid var(--border, #e5e7eb);
    border-top-color: var(--accent, #2d6a4f);
    border-radius: 50%;
    animation: sec-spin .8s linear infinite;
}
@keyframes sec-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .sec-spin { animation-duration: 2.4s; } }
.sec-check .cf-turnstile { margin-bottom: var(--space-4); }
.sec-note { margin: var(--space-4) 0 0; font-size: var(--text-xs); color: var(--text-tertiary); }
</style>

<script>
// Turnstile calls these. A pass submits the form; the server checks the token.
function plpSecDone() {
    var m = document.getElementById('sec-msg');
    if (m) m.textContent = 'Verified. Taking you in...';
    document.getElementById('sec-form').submit();
}
function plpSecFail() {
    var f = document.getElementById('sec-form'), r = document.getElementById('sec-retry');
    if (f) f.hidden = true;
    if (r) r.hidden = false;
}
</script>
<?php
$content       = ob_get_clean();
$pageTitle     = 'Security Verification';
$hidePrivacyLink = false;
$loadTurnstile = true;
include VIEWS_PATH . '/layouts/auth.php';
