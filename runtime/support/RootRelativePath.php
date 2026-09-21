<?php

declare(strict_types=1);

namespace Drupack\Support;

/**
 * Expresses an absolute application path relative to the application root.
 */
final class RootRelativePath {

  /**
   * Strips $root from the front of $path and turns every backslash into a
   * forward slash, the form a forward-slash platform already produces.
   *
   * Core builds a component directory from a forward-slash extension path,
   * then appends further elements with DIRECTORY_SEPARATOR, so $path can
   * mix separators even where $root carries none. Converting both before
   * comparing keeps the prefix match working regardless of which separator
   * either string carries, and a trailing separator on $root neither breaks
   * the match nor survives into the result.
   */
  public static function relativeTo(string $path, string $root): string {
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
  }

}
