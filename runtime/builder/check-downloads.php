<?php
// Compares each source static-php-cli downloaded into DOWNLOADS with library-versions.txt
// beside this script, and exits 1 naming each one with no line, another file or other bytes.
// With --report it names them and passes, for a site's runtime, which downloads the libraries'
// current releases.
//
// Usage: php check-downloads.php [--report] DOWNLOADS

$arguments = array_slice($argv, 1);
$report = $arguments[0] === '--report';
$downloads = end($arguments);
$records = [];
foreach (file(__DIR__ . '/library-versions.txt', FILE_IGNORE_NEW_LINES) as $line) {
    [$name, , $file, $sha256] = explode(' ', $line);
    $records[$name] = [$file, $sha256];
}
// Sources static-php-cli adds for its own compilers: the compiler-rt of the installed Zig, and re2c.
$toolchain = ['llvm-compiler-rt', 're2c'];
// static-php-cli writes the cache, so its entries are remote input.
$cache = json_decode((string) @file_get_contents("$downloads/.cache.json"), true);
$failures = [];
$checked = 0;
foreach (is_array($cache) ? $cache : [] as $name => $entry) {
    $source = $entry['source'] ?? null;
    // A binary has no source entry. A local source is a branch pin, checked out at its commit.
    if (!is_array($source) || ($source['cache_type'] ?? null) === 'local' || in_array($name, $toolchain, true)) {
        continue;
    }
    if (!isset($records[$name])) {
        $failures[] = "$name: no line in library-versions.txt";
        continue;
    }
    [$file, $sha256] = $records[$name];
    $checked++;
    if (!in_array($source['cache_type'] ?? null, ['archive', 'file'], true) || ($source['filename'] ?? null) !== $file) {
        $failures[] = "$name: static-php-cli holds " . json_encode($source['filename'] ?? $source['dirname'] ?? null) . ", the record names $file";
    } elseif (!is_file("$downloads/$file") || hash_file('sha256', "$downloads/$file") !== $sha256) {
        $failures[] = "$name: $file differs from the SHA-256 library-versions.txt records";
    }
}
if ($checked === 0) {
    $failures[] = "$downloads/.cache.json names no source library-versions.txt records";
}
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit($report ? 0 : 1);
}
