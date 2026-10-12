<?php
// Run: php tests/google_claims_check.php
// Checks google_claims_ok() with made up claims. No database, no network.
// ponytail: plain asserts; the redirect, code exchange and account decisions are covered by the manual test.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/helpers.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

$cid = 'client-123.apps.googleusercontent.com';
$now = 1_800_000_000;
$good = [
    'iss' => 'https://accounts.google.com', 'aud' => $cid, 'exp' => $now + 600,
    'email_verified' => true, 'sub' => '1234567890', 'email' => 'a@example.com',
];

check(google_claims_ok($good, $cid, $now), 'good claims pass');
check(google_claims_ok(['iss' => 'accounts.google.com'] + $good, $cid, $now), 'bare accounts.google.com iss passes');
check(google_claims_ok(['email_verified' => 'true'] + $good, $cid, $now), 'email_verified string true passes');
check(!google_claims_ok(['aud' => 'other'] + $good, $cid, $now), 'wrong aud fails');
check(!google_claims_ok($good, '', $now), 'empty client id fails');
check(!google_claims_ok(['iss' => 'https://evil.example.com'] + $good, $cid, $now), 'wrong iss fails');
check(!google_claims_ok(['exp' => $now - 1] + $good, $cid, $now), 'expired fails');
check(!google_claims_ok(['email_verified' => false] + $good, $cid, $now), 'unverified email fails');
check(!google_claims_ok(['email_verified' => 'false'] + $good, $cid, $now), 'email_verified string false fails');
$noSub = $good; unset($noSub['sub']);
check(!google_claims_ok($noSub, $cid, $now), 'missing sub fails');
check(!google_claims_ok(['sub' => ''] + $good, $cid, $now), 'empty sub fails');
$noEmail = $good; unset($noEmail['email']);
check(!google_claims_ok($noEmail, $cid, $now), 'missing email fails');

echo "google_claims_check: all passed\n";
