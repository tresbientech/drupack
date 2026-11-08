<?php

declare(strict_types=1);

// The launcher parses every line and answers each word. It runs this script for the detach of
// a start that has not asked to stay in the terminal.

require_once __DIR__ . '/process.php';

// One served folder's state lives in the cache beside the unpacked engine, keyed by the
// folder, so the folder gains no file. Windows spells one path in several casings, so the
// key reads a single one.
function folderEntry(string $project): string
{
    return getenv('DRUPACK_RUNTIME_CACHE_ROOT') . '/folders/f'
        . substr(hash('sha256', windows() ? strtolower($project) : $project), 0, 16);
}

function projectFolder(?string $directory): string
{
    $project = realpath($directory ?? '.');
    if ($project === false || !is_dir($project)) {
        throw new RuntimeException("No such directory: $directory. Run " . executableName() . ' --help.');
    }
    return canonical($project);
}

// The launcher has refused what a start can refuse and runs the start itself with --foreground.
// This claims the lease only to refuse a second server in the reader's terminal, then runs the
// start again in the background and relays its log. $directory is the folder as the reader wrote
// it, '' for the working directory, and $typed their words after `start`.
function detach(string $directory, array $typed): void
{
    $project = projectFolder($directory === '' ? null : $directory);
    $entry = folderEntry($project);
    directory($entry);
    $serving = new Serving($entry, $entry);
    if (!$serving->claim()) {
        throw new RuntimeException(executableName() . " already serves $project");
    }
    $serving->detach(false, stopCommand($directory === '' ? '' : ' ' . shellWord($directory)), array_merge(['start'], $typed));
}

// application/tests/serve_test.php loads this file for its functions alone.
if (defined('DRUPACK_SERVE_LIBRARY')) {
    return;
}

try {
    detach($argv[2], array_slice($argv, 3));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
