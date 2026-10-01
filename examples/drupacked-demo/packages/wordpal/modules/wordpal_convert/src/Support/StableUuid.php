<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Support;

/**
 * Creates stable RFC 4122-shaped identifiers for WordPal-owned records.
 */
final class StableUuid {

  /**
   * Returns a deterministic version 5-shaped UUID for one name.
   */
  public static function fromName(string $name): string {
    $hash = sha1('https://wordpal.local/' . $name);
    return sprintf(
      '%s-%s-5%s-%x%s-%s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      substr($hash, 13, 3),
      (hexdec($hash[16]) & 0x3) | 0x8,
      substr($hash, 17, 3),
      substr($hash, 20, 12),
    );
  }

}
