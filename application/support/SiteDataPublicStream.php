<?php

declare(strict_types=1);

namespace Drupack\Support;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Symfony\Component\Filesystem\Path;

/**
 * Public files stored in Site data, addressed as if they sat under the site. Core reads one
 * directory path for both the address it builds routes and links from and the directory it
 * reads and writes. The application holds one shared read-only copy per release while every
 * site keeps its own uploads, so the two must differ. The address stays root-relative, so a
 * restart on another port serves the same address for the same file.
 */
final class SiteDataPublicStream extends PublicStream {

  public const ADDRESS = 'sites/default/files';

  public function getDirectoryPath() {
    return self::ADDRESS;
  }

  private function storageRoot(): string {
    return getenv('DRUPACK_RUNTIME_FILES_DIR');
  }

  // Core's PublicStream makes this refusal, in code this class replaces.
  private function isPrivate(string $path): bool {
    $setting = Settings::get('file_private_path');
    $private = $setting ? realpath($setting) : FALSE;
    return $private && Path::isBasePath($private, $path);
  }

  // Core resolves against getDirectoryPath(), which names the address here.
  protected function getLocalPath($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }
    $root = $this->storageRoot();
    $path = $root . '/' . $this->getTarget($uri);
    $realpath = realpath($path);
    if (!$realpath) {
      // realpath() fails on a dangling link, and a write through one creates its target.
      $parent = is_link($path) ? FALSE : realpath(dirname($path));
      if (!$parent) {
        return FALSE;
      }
      $realpath = $parent . '/' . basename($path);
    }
    $directory = realpath($root);
    if (!$directory) {
      return FALSE;
    }
    // A target that resolves outside public storage is refused. isBasePath() keeps a sibling
    // directory whose name starts with this one out.
    if (!Path::isBasePath($directory, $realpath) || $this->isPrivate($realpath)) {
      return FALSE;
    }
    return $realpath;
  }

  // The path a recursive create may make, or FALSE. The deepest entry that exists decides
  // where the new folders land, so a link there is followed before the check.
  private function creatablePath(string $uri): string|false {
    $root = realpath($this->storageRoot());
    if (!$root) {
      return FALSE;
    }
    $path = Path::canonicalize($root . '/' . $this->getTarget($uri));
    if (!Path::isBasePath($root, $path)) {
      return FALSE;
    }
    $existing = $path;
    while (!file_exists($existing) && !is_link($existing)) {
      $existing = dirname($existing);
    }
    $realpath = realpath($existing);
    if (!$realpath || !Path::isBasePath($root, $realpath)) {
      return FALSE;
    }
    return $this->isPrivate($realpath) || $this->isPrivate($path) ? FALSE : $path;
  }

  // A recursive create cannot resolve a path that does not exist yet, so core builds it from
  // getDirectoryPath().
  public function mkdir($uri, $mode, $options) {
    if (!($options & STREAM_MKDIR_RECURSIVE)) {
      return parent::mkdir($uri, $mode, $options);
    }
    $this->uri = $uri;
    $path = $this->creatablePath($uri);
    if ($path === FALSE) {
      return FALSE;
    }
    $fileSystem = \Drupal::service('file_system');
    if ($options & STREAM_REPORT_ERRORS) {
      return $fileSystem->mkdir($path, $mode, TRUE);
    }
    return @$fileSystem->mkdir($path, $mode, TRUE);
  }

}
