<?php

declare(strict_types=1);

// The launcher parses every line and answers each word. This holds the folder's cache entry key,
// which application/tests/serve_test.php checks.

require_once __DIR__ . '/process.php';

// One served folder's state lives in the cache beside the unpacked engine, keyed by the
// folder, so the folder gains no file. Windows spells one path in several casings, so the
// key reads a single one.
function folderEntry(string $project): string
{
    return getenv('DRUPACK_RUNTIME_CACHE_ROOT') . '/folders/f'
        . substr(hash('sha256', windows() ? strtolower($project) : $project), 0, 16);
}
