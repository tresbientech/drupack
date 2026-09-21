<?php

declare(strict_types=1);

namespace Drupack\Support;

/**
 * Decides whether a URI names a local file this product may read from disk.
 */
final class LocalFileUri {

  /**
   * Answers whether $uri is a local path, not a stream wrapper or a remote
   * address.
   *
   * parse_url() reads a Windows drive letter ("C:/icon.svg") as a URL
   * scheme, so a caller that refuses any scheme refuses every local read on
   * Windows. No PHP stream wrapper has a one-character name, so a
   * one-character scheme can only be a drive letter: permitting it costs
   * nothing a bare local path did not already carry. Any other scheme, and
   * any host, stays refused, which keeps "public://" and every other
   * wrapper out, including the writable site storage a bare file_exists()
   * check would otherwise expose.
   */
  public static function permitsRead(string $uri): bool {
    $parts = parse_url($uri);
    if ($parts === FALSE || isset($parts['host'])) {
      return FALSE;
    }
    if (isset($parts['scheme']) && strlen($parts['scheme']) !== 1) {
      return FALSE;
    }
    return TRUE;
  }

}
