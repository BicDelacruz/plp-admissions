<?php
// Run: php tests/remember_check.php
// Checks the pure remember-me helpers. No database needed.
// ponytail: plain asserts; restore, rotation and theft handling are covered by the manual test.
require_once __DIR__ . '/../core/Auth.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

$sel = str_repeat('a1', 12);
$val = str_repeat('b2', 32);

check(remember_cookie_parse("$sel:$val") === [$sel, $val], 'good cookie parses');
check(remember_cookie_parse('') === null, 'empty cookie rejected');
check(remember_cookie_parse('junk') === null, 'junk rejected');
check(remember_cookie_parse("$sel:") === null, 'missing validator rejected');
check(remember_cookie_parse(":$val") === null, 'missing selector rejected');
check(remember_cookie_parse("$sel:" . substr($val, 1)) === null, 'short validator rejected');
check(remember_cookie_parse(strtoupper("$sel:$val")) === null, 'non hex case rejected');
check(remember_cookie_parse("$sel:$val:x") === null, 'extra part rejected');

$stored = hash('sha256', $val);
check(remember_hash_ok($val, $stored), 'right validator passes');
check(!remember_hash_ok(str_repeat('c3', 32), $stored), 'wrong validator fails');
check(!remember_hash_ok($stored, $stored), 'hash is not the validator');

echo "remember_check: all passed\n";
