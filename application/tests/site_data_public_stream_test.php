<?php

declare(strict_types=1);

// Extends Drupal core's PublicStream, so unlike the other files here this needs the vendor/
// autoloader, for core and for Symfony's Path.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/SiteDataPublicStream.php';

use Drupack\Support\SiteDataPublicStream;

require __DIR__ . '/cases.php';

$root = sys_get_temp_dir() . '/drupack-site-data-public-stream-' . bin2hex(random_bytes(6));
mkdir("$root/files", 0700, true);
mkdir("$root/outside", 0700, true);
// A sibling directory whose name starts with "files": the containment check must not compare
// the two names as bare strings.
mkdir("$root/files-evil", 0700, true);
file_put_contents("$root/files/inside.txt", 'inside');
file_put_contents("$root/outside/secret.txt", 'secret');
file_put_contents("$root/files-evil/x.txt", 'evil');

putenv("DRUPACK_RUNTIME_FILES_DIR=$root/files");

$stream = new SiteDataPublicStream();
$getLocalPath = new ReflectionMethod($stream, 'getLocalPath');

test('a target inside Site data resolves to its real path', function () use ($stream, $getLocalPath, $root) {
  same(realpath("$root/files/inside.txt"), $getLocalPath->invoke($stream, 'public://inside.txt'));
});

// A target that walks out of Site data must be refused after realpath() resolves it, however
// it reached getTarget().
test('a target that resolves outside Site data is refused', function () use ($stream, $getLocalPath) {
  same(false, $getLocalPath->invoke($stream, 'public://../outside/secret.txt'));
});

test('a sibling directory whose name only starts with the storage root is not treated as inside it', function () use ($stream, $getLocalPath) {
  same(false, $getLocalPath->invoke($stream, 'public://../files-evil/x.txt'));
});

$status = runCases();

putenv('DRUPACK_RUNTIME_FILES_DIR');
unlink("$root/files/inside.txt");
unlink("$root/outside/secret.txt");
unlink("$root/files-evil/x.txt");
rmdir("$root/files");
rmdir("$root/outside");
rmdir("$root/files-evil");
rmdir($root);

exit($status);
