<?php

declare(strict_types=1);

// One named operation per call, run by the engine's launcher through php-cli:
//   operate.php check PROJECT [DOCROOT]
//   operate.php login URL DRUSH DIRECTORY
// An operation prints its step lines on stderr and one JSON record on stdout. A refusal prints
// its message on stderr and exits 1.

require_once __DIR__ . '/process.php';

// composer.lock is the reader's file. An extension it declares that this runtime
// lacks fails only once a request reaches the code needing it, so a start refuses.
// Composer's own platform_check.php tests the PHP version alone by default.
function checkPlatform(string $binary, string $project): void
{
    $check = "$project/vendor/composer/platform_check.php";
    if (is_file($check)) {
        $child = proc_open([$binary, 'php-cli', $check], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($child)) {
            throw new RuntimeException("Cannot run $check");
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (proc_close($child) !== 0) {
            throw new RuntimeException("This runtime cannot run $project.\n" . trim($output));
        }
    }
    $lock = json_decode((string) @file_get_contents("$project/composer.lock"), true);
    if (!is_array($lock)) {
        return;
    }
    $declared = [];
    foreach (array_keys($lock['platform'] ?? []) as $requirement) {
        $declared[$requirement][] = "$project/composer.json";
    }
    foreach ($lock['packages'] ?? [] as $package) {
        foreach (array_keys($package['require'] ?? []) as $requirement) {
            $declared[$requirement][] = $package['name'];
        }
    }
    $missing = [];
    foreach ($declared as $requirement => $declarers) {
        // Composer spells an extension's spaces as dashes: ext-zend-opcache.
        if (str_starts_with($requirement, 'ext-') && !extension_loaded(str_replace('-', ' ', substr($requirement, 4)))) {
            $missing[] = '  ' . substr($requirement, 4) . ': ' . implode(', ', $declarers);
        }
    }
    if ($missing !== []) {
        throw new RuntimeException("$project/composer.lock declares PHP extensions this runtime lacks:\n" . implode("\n", $missing));
    }
}

// Null when the probe cannot tell, such as for a database it cannot reach.
function siteInstalled(string $binary, string $project, string $docroot): ?bool
{
    $descriptors = [0 => ['file', nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', nullDevice(), 'w']];
    $child = proc_open([$binary, 'php-cli', __DIR__ . '/installed.php', $project, $docroot], $descriptors, $pipes, $project);
    if (!is_resource($child)) {
        throw new RuntimeException('Cannot run the installation probe');
    }
    $answer = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    $status = proc_close($child);
    return $status === 0 ? $answer === 'yes' : null;
}


// The platform check, and with a $docroot the installation probe too. installed is null when the
// probe did not run or cannot tell.
function check(string $binary, string $project, ?string $docroot): array
{
    checkPlatform($binary, $project);
    return ['installed' => $docroot === null ? null : siteInstalled($binary, $project, $docroot)];
}

// The one-time login link for uid 1, or the reason Drush gave when it cannot mint one. A project
// whose settings block uid 1 or name no reachable database still serves, so the launcher reports
// the reason and goes on.
function mintLoginLink(string $binary, string $url, string $drush, string $directory): array
{
    try {
        return ['link' => drushLoginLink($binary, $drush, $directory, ["--uri=$url"], $url)];
    } catch (LoginLinkFailure $failure) {
        return ['failure' => $failure->reason];
    }
}

// application/tests/serve_test.php loads this file for its functions alone.
if (defined('DRUPACK_SERVE_LIBRARY')) {
    return;
}

try {
    $binary = getenv('DRUPACK_RUNTIME_BINARY');
    $record = match ($argv[1]) {
        'check' => check($binary, $argv[2], $argv[3] ?? null),
        'login' => mintLoginLink($binary, $argv[2], $argv[3], $argv[4]),
    };
    fwrite(STDOUT, json_encode($record, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
