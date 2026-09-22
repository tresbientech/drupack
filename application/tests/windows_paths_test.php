<?php

declare(strict_types=1);

// Table-driven checks for the Windows path pure functions: no Drupal
// import, so this runs standalone under php-cli with no container. Each
// pure function gets its own require and its own table.

require __DIR__ . '/../support/LocalFileUri.php';

// launch_test.php defines this to load launch.php's functions without starting a site.
define('DRUPACK_LAUNCH_LIBRARY', true);
require __DIR__ . '/../launch.php';

use Drupack\Support\LocalFileUri;

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

// A backslash is a legal file name character everywhere it is not the path
// separator, so canonical() must leave one alone on such a host: a reader who
// passes --data-dir 'a\b' on Linux gets the directory named 'a\b', not 'a/b'.
$want = DIRECTORY_SEPARATOR === '\\' ? 'a/b' : 'a\\b';
check('canonical touches a backslash only where it is the path separator', true, canonical('a\\b') === $want);

if ($failures > 0) {
  fwrite(STDERR, "$failures of $total checks failed\n");
  exit(1);
}

fwrite(STDOUT, "$total checks passed\n");
exit(0);
