<?php
// Run: php tests/label_for_check.php [file ...]
// Checks every PHP file in modules/ and views/ (or only the files given):
//   1. each for="x" has a matching id="x" in the same file
//   2. no static id repeats in the same file
// ponytail: plain regex, no HTML parser. Ids with PHP inside (<?) are skipped. Same id in two
// branches of an if/else counts as a repeat; give them different ids or fix by hand.

$root = dirname(__DIR__);
$files = array_slice($argv, 1);

if (!$files) {
    foreach (['modules', 'views'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
    }
    sort($files);
}

function static_values(string $src, string $attr): array
{
    preg_match_all('/\s' . $attr . '=(?:"([^"]*)"|\'([^\']*)\')/', $src, $m);
    $out = [];
    foreach (array_keys($m[0]) as $i) {
        $v = $m[1][$i] !== '' ? $m[1][$i] : $m[2][$i];
        if ($v !== '' && strpos($v, '<?') === false) {
            $out[] = $v;
        }
    }
    return $out;
}

$fails = [];

foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        $fails[] = "$file: cannot read";
        continue;
    }
    $short = ltrim(str_replace($root, '', $file), '/\\');
    $ids = static_values($src, 'id');
    $fors = static_values($src, 'for');

    foreach (array_unique($fors) as $for) {
        if (!in_array($for, $ids, true)) {
            $fails[] = "$short: for=\"$for\" has no matching id";
        }
    }
    foreach (array_count_values($ids) as $id => $n) {
        if ($n > 1) {
            $fails[] = "$short: id=\"$id\" appears $n times";
        }
    }
}

if ($fails) {
    foreach ($fails as $f) {
        fwrite(STDERR, "FAIL: $f\n");
    }
    fwrite(STDERR, count($fails) . " problem(s) in " . count($files) . " file(s)\n");
    exit(1);
}

echo "label_for_check: all passed (" . count($files) . " files)\n";
