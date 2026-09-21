<?php

declare(strict_types=1);

namespace Drupack\Support;

use Drupal\Core\Theme\Icon\IconFinder;

/**
 * Reads a local icon file by path, where a Windows drive letter is not a
 * URL scheme.
 */
class DriveLetterIconFinder extends IconFinder {

  /**
   * {@inheritdoc}
   */
  public function getFileContents(string $uri): string|bool {
    if (!LocalFileUri::permitsRead($uri)) {
      return FALSE;
    }
    if (!file_exists($uri)) {
      return FALSE;
    }
    return file_get_contents($uri);
  }

}
