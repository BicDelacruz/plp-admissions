<?php
// Run: php tests/modal_check.php
// Fails while app code still has native alert/confirm/prompt.
// ponytail: regex scan, not a parser. Comments and strings can false-match; fix the text if so.
$root = dirname(__DIR__);
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (!preg_match('/\.(php|js)$/', $p) || preg_match('#/(lib|vendor|node_modules|tests|\.git)/#', $p)) continue;
    foreach (file($p) as $n => $line) {
        if (preg_match('/(?<![.\w$])(alert|confirm|prompt)\(/', $line) || preg_match('/window\.(alert|confirm|prompt)\(/', $line)) {
            $bad[] = substr($p, strlen($root) + 1) . ':' . ($n + 1) . ' native dialog';
        }
    }
}
if ($bad) { fwrite(STDERR, implode("\n", $bad) . "\nFAIL: " . count($bad) . " left\n"); exit(1); }
echo "OK\n";
