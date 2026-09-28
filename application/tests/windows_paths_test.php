<?php

declare(strict_types=1);

// Table-driven checks for LocalFileUri: no Drupal import, so this runs
// standalone under php-cli with no container.

require __DIR__ . '/../support/LocalFileUri.php';

use Drupack\Support\LocalFileUri;

require __DIR__ . '/cases.php';

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
  test("LocalFileUri::permitsRead($uri)", fn () => same($expected, LocalFileUri::permitsRead($uri)));
}

exit(runCases());
