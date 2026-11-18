<?php

declare(strict_types=1);

// Extends Drupal core's PublicStream, so unlike the other files here this needs the vendor/
// autoloader, for core and for Symfony's Path.

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../support/SiteDataPublicStream.php';

use Drupack\Support\SiteDataPublicStream;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;

require __DIR__ . '/cases.php';

$root = sys_get_temp_dir() . '/drupack-site-data-public-stream-' . bin2hex(random_bytes(6));
mkdir("$root/files/shared", 0700, true);
mkdir("$root/outside", 0700, true);
// A sibling directory whose name starts with "files": the containment check must not compare
// the two names as bare strings.
mkdir("$root/files-evil", 0700, true);
// The private directory sits in public storage only here, to test the stream's own refusal.
mkdir("$root/files/private", 0700, true);
mkdir("$root/files/private-public", 0700, true);
file_put_contents("$root/files/inside.txt", 'inside');
file_put_contents("$root/files/private/secret.txt", 'secret');
file_put_contents("$root/files/private-public/open.txt", 'open');
file_put_contents("$root/outside/secret.txt", 'secret');
file_put_contents("$root/files-evil/x.txt", 'evil');
symlink("$root/outside/created.txt", "$root/files/dangling");
symlink("$root/files/private", "$root/files/to-private");
symlink("$root/outside", "$root/files/to-outside");
symlink("$root/files/shared", "$root/files/to-shared");

putenv("DRUPACK_RUNTIME_FILES_DIR=$root/files");
new Settings(['file_private_path' => "$root/files/private"]);

$container = new ContainerBuilder();
$container->set('file_system', new class {

  public function mkdir(string $uri, int $mode, bool $recursive): bool {
    return mkdir($uri, $mode, $recursive);
  }

});
\Drupal::setContainer($container);

$stream = new SiteDataPublicStream();
$getLocalPath = new ReflectionMethod($stream, 'getLocalPath');
$createDirectory = fn (string $uri): bool => $stream->mkdir($uri, 0700, STREAM_MKDIR_RECURSIVE);

test('a target inside Site data resolves to its real path', function () use ($stream, $getLocalPath, $root) {
  same(realpath("$root/files/inside.txt"), $getLocalPath->invoke($stream, 'public://inside.txt'));
});

test('a new file inside Site data resolves below its real parent', function () use ($stream, $getLocalPath, $root) {
  same(realpath("$root/files") . '/new.txt', $getLocalPath->invoke($stream, 'public://new.txt'));
});

// A target that walks out of Site data must be refused after realpath() resolves it, however
// it reached getTarget().
test('a target that resolves outside Site data is refused', function () use ($stream, $getLocalPath) {
  same(false, $getLocalPath->invoke($stream, 'public://../outside/secret.txt'));
});

test('a sibling directory whose name only starts with the storage root is not treated as inside it', function () use ($stream, $getLocalPath) {
  same(false, $getLocalPath->invoke($stream, 'public://../files-evil/x.txt'));
});

test('a target in the private directory is refused', function () use ($stream, $getLocalPath) {
  same(false, $getLocalPath->invoke($stream, 'public://private/secret.txt'));
  same(false, $getLocalPath->invoke($stream, 'public://private'));
  same(false, $getLocalPath->invoke($stream, 'public://private/new.txt'));
});

test('a link into the private directory is refused', function () use ($stream, $getLocalPath) {
  same(false, $getLocalPath->invoke($stream, 'public://to-private/secret.txt'));
});

test('a folder whose name only starts with the private directory stays public', function () use ($stream, $getLocalPath, $root) {
  same(realpath("$root/files/private-public/open.txt"), $getLocalPath->invoke($stream, 'public://private-public/open.txt'));
});

test('a dangling link cannot carry a write out of Site data', function () use ($stream, $getLocalPath, $root) {
  same(false, $getLocalPath->invoke($stream, 'public://dangling'));
  $opened = NULL;
  same(false, @$stream->stream_open('public://dangling', 'w', 0, $opened));
  same(false, file_exists("$root/outside/created.txt"));
});

test('a target below a missing folder is refused', function () use ($stream, $getLocalPath) {
  same(false, $getLocalPath->invoke($stream, 'public://missing/new.txt'));
});

test('a recursive create makes nested folders in Site data', function () use ($createDirectory, $root) {
  same(true, $createDirectory('public://made/deep/er'));
  same(true, is_dir("$root/files/made/deep/er"));
});

test('a recursive create may pass through a link that stays in Site data', function () use ($createDirectory, $root) {
  same(true, $createDirectory('public://to-shared/made'));
  same(true, is_dir("$root/files/shared/made"));
});

test('a recursive create that walks out of Site data creates nothing', function () use ($createDirectory, $root) {
  same(false, $createDirectory('public://made/../../outside/walked'));
  same(false, file_exists("$root/outside/walked"));
});

test('a recursive create through a link out of Site data creates nothing', function () use ($createDirectory, $root) {
  same(false, $createDirectory('public://to-outside/linked/deep'));
  same(false, file_exists("$root/outside/linked"));
});

test('a recursive create through a dangling link creates nothing', function () use ($createDirectory, $root) {
  same(false, $createDirectory('public://dangling/deep'));
  same(false, file_exists("$root/outside/created.txt"));
});

test('a recursive create in the private directory creates nothing', function () use ($createDirectory, $root) {
  same(false, $createDirectory('public://private/made'));
  same(false, $createDirectory('public://to-private/linked'));
  same(false, file_exists("$root/files/private/made"));
  same(false, file_exists("$root/files/private/linked"));
});

$status = runCases();

putenv('DRUPACK_RUNTIME_FILES_DIR');
$entries = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
  RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($entries as $entry) {
  $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($root);

exit($status);
