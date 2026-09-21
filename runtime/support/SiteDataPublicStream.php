<?php

declare(strict_types=1);

namespace Drupack\Support;

use Drupal\Core\StreamWrapper\PublicStream;

/**
 * Public files stored in Site data, addressed as if they sat under the site.
 *
 * Core reads one directory path for two jobs: the address it builds routes and
 * links from, and the directory it reads and writes. Drupack needs them apart,
 * because the application holds one shared read-only copy per release while
 * every site keeps its own uploads. The address stays root-relative, so a site
 * restarted on another port serves the same address for the same file, and the
 * image style route still matches what a rendered page asks for.
 */
final class SiteDataPublicStream extends PublicStream {

  /**
   * The address a page carries, relative to the site root.
   */
  private const ADDRESS = 'sites/default/files';

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath() {
    return self::ADDRESS;
  }

  /**
   * The directory on disk, inside Site data.
   */
  private function storageRoot(): string {
    return getenv('DRUPACK_RUNTIME_DATA_DIR') . '/files';
  }

  /**
   * {@inheritdoc}
   *
   * Core resolves against getDirectoryPath(), which names the address here.
   */
  protected function getLocalPath($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }
    $root = $this->storageRoot();
    $path = $root . '/' . $this->getTarget($uri);
    $realpath = realpath($path);
    if (!$realpath) {
      // The file does not exist yet, so its directory names where it will go.
      $realpath = realpath(dirname($path)) . '/' . basename($path);
    }
    $directory = realpath($root);
    if (!$realpath || !$directory) {
      return FALSE;
    }
    // A target that resolves outside public storage is refused, whatever
    // produced it. The separator keeps a sibling directory whose name merely
    // starts with this one out.
    if ($realpath !== $directory
      && !str_starts_with($realpath, $directory . DIRECTORY_SEPARATOR)) {
      return FALSE;
    }
    return $realpath;
  }

  /**
   * {@inheritdoc}
   *
   * A recursive create cannot resolve a path that does not exist yet, so core
   * builds it from getDirectoryPath() rather than from getLocalPath().
   */
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
