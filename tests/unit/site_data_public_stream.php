<?php

declare(strict_types=1);

// SiteDataPublicStream extends Drupal core's PublicStream, so unlike the other
// files here this needs the autoloader vendor/ ships: core itself, reached
// through the Drupal\Core\ mapping composer already generates, and Symfony's
// Path, which the containment check in getLocalPath() now calls.

require __DIR__ . '/../../runtime/vendor/autoload.php';
require __DIR__ . '/../../runtime/support/SiteDataPublicStream.php';

use Drupack\Support\SiteDataPublicStream;

$total = 0;
$failures = 0;

function check(string $description, bool $expected, bool $actual): void {
  global $total, $failures;
  $total++;
  if ($expected !== $actual) {
    $failures++;
    fwrite(STDERR, sprintf(
      "FAIL: %s (expected %s, got %s)\n",
      $description,
      $expected ? 'true' : 'false',
      $actual ? 'true' : 'false'
    ));
  }
}

$root = sys_get_temp_dir() . '/drupack-site-data-public-stream-' . bin2hex(random_bytes(6));
mkdir("$root/files", 0700, true);
mkdir("$root/outside", 0700, true);
// A sibling directory whose name merely starts with "files": the regression
// this check guards against compares the two names as bare strings.
mkdir("$root/files-evil", 0700, true);
file_put_contents("$root/files/inside.txt", 'inside');
file_put_contents("$root/outside/secret.txt", 'secret');
file_put_contents("$root/files-evil/x.txt", 'evil');

putenv("DRUPACK_RUNTIME_DATA_DIR=$root");

$stream = new SiteDataPublicStream();
$getLocalPath = new ReflectionMethod($stream, 'getLocalPath');

check(
  'a target inside Site data resolves to its real path',
  true,
  $getLocalPath->invoke($stream, 'public://inside.txt') === realpath("$root/files/inside.txt")
);

// Core's own DIRECTORY_SEPARATOR-joined suffixes and a crafted stream URI both
// reach getTarget() without ever passing through a "../" collapse, so a target
// that walks out of Site data must still be refused after realpath() resolves it.
check(
  'a target that resolves outside Site data is refused',
  false,
  $getLocalPath->invoke($stream, 'public://../outside/secret.txt') !== false
);

check(
  'a sibling directory whose name only starts with the storage root is not treated as inside it',
  false,
  $getLocalPath->invoke($stream, 'public://../files-evil/x.txt') !== false
);

putenv('DRUPACK_RUNTIME_DATA_DIR');
unlink("$root/files/inside.txt");
unlink("$root/outside/secret.txt");
unlink("$root/files-evil/x.txt");
rmdir("$root/files");
rmdir("$root/outside");
rmdir("$root/files-evil");
rmdir($root);

if ($failures > 0) {
  fwrite(STDERR, "$failures of $total checks failed\n");
  exit(1);
}

fwrite(STDOUT, "$total checks passed\n");
exit(0);
