<?php

declare(strict_types=1);

namespace Drupack\Support;

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

  private const ADDRESS = 'sites/default/files';

  public function getDirectoryPath() {
    return self::ADDRESS;
  }

  private function storageRoot(): string {
    return getenv('DRUPACK_RUNTIME_FILES_DIR');
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
      $realpath = realpath(dirname($path)) . '/' . basename($path);
    }
    $directory = realpath($root);
    if (!$realpath || !$directory) {
      return FALSE;
    }
    // A target that resolves outside public storage is refused. isBasePath() keeps a sibling
    // directory whose name starts with this one out.
    if (!Path::isBasePath($directory, $realpath)) {
      return FALSE;
    }
    return $realpath;
  }

  // A recursive create cannot resolve a path that does not exist yet, so core builds it from
  // getDirectoryPath().
  public function mkdir($uri, $mode, $options) {
    if (!($options & STREAM_MKDIR_RECURSIVE)) {
      return parent::mkdir($uri, $mode, $options);
    }
    $this->uri = $uri;
    $path = $this->storageRoot() . '/' . $this->getTarget($uri);
    $fileSystem = \Drupal::service('file_system');
    if ($options & STREAM_REPORT_ERRORS) {
      return $fileSystem->mkdir($path, $mode, TRUE);
    }
    return @$fileSystem->mkdir($path, $mode, TRUE);
  }

}
