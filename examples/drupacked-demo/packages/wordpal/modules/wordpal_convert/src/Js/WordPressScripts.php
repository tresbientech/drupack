<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Js;

/**
 * Resolves the block library script files of an unpacked WordPress release.
 */
final class WordPressScripts {

  /**
   * Returns the block library script and its dependencies, in load order.
   *
   * WordPress lists each package's dependencies in script-loader-packages.php.
   * A handle that names no package there is a vendor script.
   *
   * @param string $root
   *   The root of an unpacked WordPress release.
   *
   * @return string[]
   *   Absolute paths of wp-includes/js/dist files, dependencies first.
   */
  public static function forRoot(string $root): array {
    $dist = "$root/wp-includes/js/dist";
    $packages = require "$root/wp-includes/assets/script-loader-packages.php";
    $seen = [];
    $files = [];
    $load = static function (string $handle) use (&$load, &$seen, &$files, $packages, $dist): void {
      if (isset($seen[$handle])) {
        return;
      }
      $seen[$handle] = TRUE;
      $package = preg_replace('/^wp-/', '', $handle) . '.js';
      foreach ($packages[$package]['dependencies'] ?? [] as $dependency) {
        $load($dependency);
      }
      $files[] = isset($packages[$package]) ? "$dist/$package" : "$dist/vendor/$handle.js";
    };
    $load('wp-block-library');
    return $files;
  }

}
