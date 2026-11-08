<?php

declare(strict_types=1);

// Unit cases for the Serving class in application/process.php, on a temporary
// directory with a real lease.

require __DIR__ . '/../process.php';
require __DIR__ . '/cases.php';

putenv('DRUPACK_RUNTIME_NAME=fixture-site');

$scratches = [];

function scratch(): string
{
    $path = sys_get_temp_dir() . '/drupack-serving-test-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0700, true)) {
        throw new RuntimeException("Cannot create $path");
    }
    $GLOBALS['scratches'][] = $path;
    return $path;
}

test('a second claim on a held lease is refused', function (): void {
    $state = scratch();
    $holder = new Serving($state, $state);
    same(true, $holder->claim());
    same(false, (new Serving($state, $state))->claim());
});

test('a foreground start removes a stale record and keeps serving here', function (): void {
    $state = scratch();
    $serving = new Serving($state, $state);
    same(true, $serving->claim());
    file_put_contents($serving->stopRecord, '{"port":1,"token":"stale","pid":1}');
    $serving->detach(true, 'fixture-site stop', []);
    same(false, file_exists($serving->stopRecord));
});

$status = runCases();

foreach ($scratches as $path) {
    foreach (glob("$path/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($path);
}

exit($status);
