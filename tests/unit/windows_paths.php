<?php

declare(strict_types=1);

// Table-driven checks for the Windows path pure functions: no Drupal
// import, so this runs standalone under php-cli with no container. Each
// pure function gets its own require and its own table.

require __DIR__ . '/../../runtime/support/LocalFileUri.php';
require __DIR__ . '/../../runtime/support/RootRelativePath.php';

use Drupack\Support\LocalFileUri;
use Drupack\Support\RootRelativePath;

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

function checkString(string $description, string $expected, string $actual): void {
  global $total, $failures;
  $total++;
  if ($expected !== $actual) {
    $failures++;
    fwrite(STDERR, sprintf(
      "FAIL: %s (expected %s, got %s)\n",
      $description,
      $expected,
      $actual
    ));
  }
}

$local_file_uri_cases = [
  ['public://x.svg', FALSE],
  ['http://h/x.svg', FALSE],
  ['//host/share/x.svg', FALSE],
  ['\\\\host\share\x.svg', FALSE],
  ['php://input', FALSE],
  ['C:\\x.svg', TRUE],
  ['C:/x.svg', TRUE],
  ['/var/www/icon.svg', TRUE],
];

foreach ($local_file_uri_cases as [$uri, $expected]) {
  check("LocalFileUri::permitsRead($uri)", $expected, LocalFileUri::permitsRead($uri));
}

$root_relative_path_cases = [
  // A mixed-separator absolute path: core joins the scan directory's
  // forward-slash extension path with DIRECTORY_SEPARATOR, so a Windows
  // component directory carries both.
  ['C:/Users/theno/web/themes/contrib/mercury\components\hero-billboard', 'C:/Users/theno/web', 'themes/contrib/mercury/components/hero-billboard'],
  // A uniform absolute path: the Linux and macOS shape, already forward
  // slashes throughout, so the rewrite is an identity past the root strip.
  ['/var/www/html/themes/contrib/mercury/components/hero-billboard', '/var/www/html', 'themes/contrib/mercury/components/hero-billboard'],
  // An already relative path: it carries no root prefix to strip, so it
  // comes back unchanged.
  ['themes/contrib/mercury/components/hero-billboard', '/var/www/html', 'themes/contrib/mercury/components/hero-billboard'],
  // A root carrying a trailing separator: the strip neither doubles it nor
  // leaves it behind in the result.
  ['/var/www/html/themes/contrib/mercury/components/hero-billboard', '/var/www/html/', 'themes/contrib/mercury/components/hero-billboard'],
];

foreach ($root_relative_path_cases as [$path, $root, $expected]) {
  checkString("RootRelativePath::relativeTo($path, $root)", $expected, RootRelativePath::relativeTo($path, $root));
}

if ($failures > 0) {
  fwrite(STDERR, "$failures of $total checks failed\n");
  exit(1);
}

fwrite(STDOUT, "$total checks passed\n");
exit(0);
