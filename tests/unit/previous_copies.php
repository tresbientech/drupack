<?php

declare(strict_types=1);

// Checks for the guard over the copies the previous layout left: no Drupal
// import, so this runs standalone under php-cli with no container. The subject
// reads the filesystem, so each case builds the directory it describes.

require __DIR__ . '/../../runtime/support/PreviousCopies.php';

use Drupack\Support\PreviousCopies;

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

$root = sys_get_temp_dir() . '/drupack-previous-copies-' . bin2hex(random_bytes(6));
mkdir($root . '/runtime/frankenphp_a1b2', 0700, TRUE);
file_put_contents($root . '/runtime/frankenphp_a1b2/launch.php', '<?php');
mkdir($root . '/runtime/frankenphp_c3d4', 0700, TRUE);
mkdir($root . '/runtime/caddy', 0700, TRUE);
file_put_contents($root . '/runtime/caddy/launch.php', '<?php');

$copies = PreviousCopies::under($root . '/runtime');

check(
  'a directory named by the previous layout, holding the application, is a copy',
  TRUE,
  in_array($root . '/runtime/frankenphp_a1b2', $copies, TRUE)
);
check(
  'a directory named by the previous layout, holding no application, stays',
  FALSE,
  in_array($root . '/runtime/frankenphp_c3d4', $copies, TRUE)
);
check(
  'a directory this release writes stays, whatever it holds',
  FALSE,
  in_array($root . '/runtime/caddy', $copies, TRUE)
);
check('a directory holding the application is named as one', TRUE,
  PreviousCopies::holdsApplication($root . '/runtime/frankenphp_a1b2'));
check('an empty directory is not named as one', FALSE,
  PreviousCopies::holdsApplication($root . '/runtime/frankenphp_c3d4'));

foreach (['frankenphp_a1b2/launch.php', 'caddy/launch.php'] as $file) {
  unlink($root . '/runtime/' . $file);
}
foreach (['frankenphp_a1b2', 'frankenphp_c3d4', 'caddy'] as $name) {
  rmdir($root . '/runtime/' . $name);
}
rmdir($root . '/runtime');
rmdir($root);

printf("%d checks, %d failures\n", $total, $failures);
exit($failures === 0 ? 0 : 1);
