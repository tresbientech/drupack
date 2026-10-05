<?php

declare(strict_types=1);

// The server serving_test.php stops. It claims the lease over the directory it names,
// writes the stop record fixture with its own port, and answers one request with the
// status it names. It exits 0 when that request carried the fixture's token.

require __DIR__ . '/../process.php';

[, $directory, $status] = $argv;
$serving = new Serving($directory, $directory);
if (!$serving->claim()) {
    exit(2);
}
$socket = stream_socket_server('tcp://127.0.0.1:0');
$record = json_decode((string) file_get_contents(__DIR__ . '/../../runtime/watch/testdata/stop.json'), true);
$record['port'] = (int) substr((string) strrchr(stream_socket_get_name($socket, false), ':'), 1);
file_put_contents($serving->stopRecord, json_encode($record));
fwrite(STDOUT, "ready\n");
$connection = stream_socket_accept($socket, 30);
$request = (string) fread($connection, 8192);
fwrite($connection, "HTTP/1.1 $status Stub\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
fclose($connection);
exit(str_contains($request, "Authorization: Bearer {$record['token']}") ? 0 : 3);
