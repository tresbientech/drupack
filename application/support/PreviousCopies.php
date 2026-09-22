<?php

declare(strict_types=1);

namespace Drupack\Support;

/**
 * The application copies the previous layout left inside Site data.
 *
 * That layout unpacked the application under the site's own runtime directory,
 * once per site. This release unpacks once per release into the user cache, so
 * those copies hold space no start reads.
 */
final class PreviousCopies {

  /**
   * The file a copy of this application always holds.
   *
   * Names alone decide nothing here: the runtime directory belongs to the
   * reader, so a directory goes only when it carries this file.
   */
  private const MARKER = 'launch.php';

  /**
   * The name the previous layout gave each copy.
   */
  private const PATTERN = 'frankenphp_*';

  /**
   * Lists the copies under a site's runtime directory.
   */
  public static function under(string $runtime): array {
    $candidates = glob($runtime . DIRECTORY_SEPARATOR . self::PATTERN, GLOB_ONLYDIR);
    return array_values(array_filter($candidates ?: [], self::holdsApplication(...)));
  }

  /**
   * Reports whether a directory holds an application this product unpacked.
   */
  public static function holdsApplication(string $directory): bool {
    return is_file($directory . DIRECTORY_SEPARATOR . self::MARKER);
  }

}
