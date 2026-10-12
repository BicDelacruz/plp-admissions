<?php
// Run: php tests/reset_code_check.php
// Checks the pure reset-code logic. No database needed.
// ponytail: plain asserts, no framework; DB parts (create_reset_code, cooldown SQL) are covered by the manual test.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/automation.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

$now  = 1_700_000_000;
$code = '042517';
$row  = ['token' => hash('sha256', $code), 'expires_at' => $now + 900, 'used' => 0, 'attempts' => 0];

check(reset_code_check($row, $code, $now) === 'ok', 'right code passes');
check(reset_code_check($row, '000000', $now) === 'wrong', 'wrong code fails');
check(reset_code_check($row, '42517', $now) === 'wrong', 'leading zero matters');
check(reset_code_check(['expires_at' => $now - 1] + $row, $code, $now) === 'expired', 'expired code fails');
check(reset_code_check(['expires_at' => date('Y-m-d H:i:s', $now + 900)] + $row, $code, 0) === 'ok', 'string expiry parses');
check(reset_code_check(['used' => 1] + $row, $code, $now) === 'used', 'used code fails');
check(reset_code_check(['attempts' => RESET_MAX_ATTEMPTS] + $row, $code, $now) === 'locked', 'locked after max tries, even with the right code');
check(reset_code_check(['attempts' => RESET_MAX_ATTEMPTS - 1] + $row, $code, $now) === 'ok', '4 wrong tries still allowed');
check($row['token'] !== $code && strlen($row['token']) === 64, 'stored value is a hash, not the code');
check(RESET_MAX_ATTEMPTS === 5 && RESET_RESEND_SECS === 60 && RESET_CODE_TTL_SECS === 900, 'constants match the plan');

echo "reset_code_check: all passed\n";
