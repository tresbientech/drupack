<?php

declare(strict_types=1);

namespace Drupack\Support;

final class LocalFileUri {

  /**
   * Answers whether $uri is a local path, not a stream wrapper or a remote address.
   *
   * parse_url() reads a Windows drive letter ("C:/icon.svg") as a URL scheme. No PHP stream
   * wrapper has a one-character name, so a one-character scheme is a drive letter. Any other
   * scheme, and any host, stays refused, which keeps "public://" and the writable site storage
   * out.
   */
  public static function permitsRead(string $uri): bool {
    $parts = parse_url($uri);
    if ($parts === FALSE || isset($parts['host'])) {
      return FALSE;
    }
    if (isset($parts['scheme']) && strlen($parts['scheme']) !== 1) {
      return FALSE;
    }
    // parse_url() reads an authority only after "//", so the native Windows
    // UNC form "\\host\share" arrives as a path carrying no host.
    return !str_starts_with($parts['path'] ?? '', '\\\\');
  }

}
