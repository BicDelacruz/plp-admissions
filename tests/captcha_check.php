<?php
// Run: php tests/captcha_check.php
// Checks captcha_verify() without calling Cloudflare. No database needed.
// ponytail: plain asserts; the live siteverify call is covered by the manual test with Cloudflare dummy keys.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/helpers.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

$_POST = [];
if (CAPTCHA_ENABLED) {
    check(captcha_verify() === false, 'enabled with no token returns false');
    $_POST['cf-turnstile-response'] = '';
    check(captcha_verify() === false, 'enabled with empty token returns false');
} else {
    check(captcha_verify() === true, 'disabled returns true');
}
check(CAPTCHA_ENABLED === (TURNSTILE_SITE_KEY !== '' && TURNSTILE_SECRET_KEY !== ''), 'enabled only when both keys are set');
check(!str_contains(auth_csp(), 'hcaptcha') && substr_count(auth_csp(), 'https://challenges.cloudflare.com') === 3, 'csp allows Turnstile in script, frame and connect');

echo "captcha_check: all passed\n";
