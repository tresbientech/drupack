<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Support;

/**
 * Checks the shape of a relative path named by a snapshot or a release zip.
 *
 * Snapshot manifests and generated theme paths both name files that a
 * subprocess or a downloaded WordPress release chose. This checks the
 * syntactic shape only; callers still resolve the path and check it stays
 * inside their own root, since an existing snapshot file and a not-yet-
 * written theme file resolve containment differently.
 */
final class PathValidator {

  /**
   * The characters a validated relative path may contain.
   */
  public const SAFE_PATH = '/^[a-zA-Z0-9_.,@\[\]\/-]+\z/';

  /**
   * Returns whether a path has a safe shape.
   *
   * Checks the required prefix, the character allowlist, and that no
   * segment reads "..".
   */
  public static function hasSafeShape(string $path, string $prefix): bool {
    return str_starts_with($path, $prefix) && !str_contains($path, '..') && (bool) preg_match(self::SAFE_PATH, $path);
  }

}
