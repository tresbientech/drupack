<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Support;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Installs one pinned npm package into a private directory, once.
 */
final class PrivateNpmPackage {

  /**
   * Returns a private directory holding one npm package, installing it once.
   *
   * The shared temp directory is writable by other accounts. Only a private
   * install, one no other account can write, is reused. npm runs no package
   * install scripts.
   *
   * @param string $package
   *   An npm package name pinned to a version, such as "jsdom@30.1.1".
   */
  public static function directory(string $package): string {
    $directory = sys_get_temp_dir() . '/wordpal-' . str_replace('@', '-', $package);
    $name = explode('@', $package)[0];
    if (is_dir("$directory/node_modules/$name") && (fileperms($directory) & 0077) === 0) {
      return $directory;
    }
    $filesystem = new Filesystem();
    $staging = $directory . '.' . bin2hex(random_bytes(8));
    if (!mkdir($staging, 0700)) {
      throw new \RuntimeException("Could not create the private directory $staging.");
    }
    try {
      (new Process(['npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund', '--prefix', $staging, $package], timeout: 600))->mustRun();
      if (file_exists($directory)) {
        $filesystem->remove($directory);
      }
      if (!rename($staging, $directory)) {
        throw new \RuntimeException("Could not move $package into $directory.");
      }
    }
    finally {
      $filesystem->remove($staging);
    }
    return $directory;
  }

}
