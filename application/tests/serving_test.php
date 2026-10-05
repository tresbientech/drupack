<?php

declare(strict_types=1);

// Unit cases for the Serving class in application/process.php, on a temporary
// directory with a real lease. A stub server stands in for the stop channel.

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

// Starts the stub over $directory, answering $status, and returns once its record exists.
function stub(string $directory, string $status): array
{
    $process = proc_open([getenv('DRUPACK_RUNTIME_BINARY'), 'php-cli', __DIR__ . '/stop_channel_stub.php', $directory, $status],
        [1 => ['pipe', 'w']], $pipes);
    if (fgets($pipes[1]) !== "ready\n") {
        throw new RuntimeException('The stub server did not start');
    }
    return [$process, $pipes[1]];
}

// The stub's exit code: 0 when the request it answered carried the fixture's token.
function finished(array $stub): int
{
    fclose($stub[1]);
    return proc_close($stub[0]);
}

test('stop with no lease file reports a server not running', function (): void {
    $state = scratch();
    same(0, (new Serving($state, $state))->stop('folder', $state));
});

test('stop with a free lease and a stale record reports a server not running', function (): void {
    $state = scratch();
    $serving = new Serving($state, $state);
    touch("$state/serving.lock");
    file_put_contents($serving->stopRecord, '{"port":1,"token":"stale","pid":1}');
    same(0, $serving->stop('folder', $state));
});

test('stop with a held lease and no record says a start is still preparing', function (): void {
    $state = scratch();
    // The holder keeps the lease for as long as it lives.
    $holder = new Serving($state, $state);
    same(true, $holder->claim());
    throws('A fixture-site start is still preparing this folder', fn () => (new Serving($state, $state))->stop('folder', $state));
});

test('stop sends the token and waits for the server to free the lease', function (): void {
    $state = scratch();
    $server = stub($state, '204');
    same(0, (new Serving($state, $state))->stop('folder', $state));
    same(0, finished($server), 'the stub saw the fixture token');
    same(true, (new Serving($state, $state))->claim(), 'the lease is free');
});

test('a refused stop names the status the channel answered', function (): void {
    $state = scratch();
    $server = stub($state, '403');
    throws('refused the request (HTTP/1.1 403 Stub)', fn () => (new Serving($state, $state))->stop('folder', $state));
    finished($server);
});

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
