<?php

declare(strict_types=1);

// Table-driven checks for the Windows path pure functions: no Drupal
// import, so this runs standalone under php-cli with no container. Each
// pure function gets its own require and its own table.

require __DIR__ . '/../../runtime/support/LocalFileUri.php';

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
];

foreach ($local_file_uri_cases as [$uri, $expected]) {
  check("LocalFileUri::permitsRead($uri)", $expected, LocalFileUri::permitsRead($uri));
}

if ($failures > 0) {
  fwrite(STDERR, "$failures of $total checks failed\n");
  exit(1);
}

fwrite(STDOUT, "$total checks passed\n");
exit(0);
