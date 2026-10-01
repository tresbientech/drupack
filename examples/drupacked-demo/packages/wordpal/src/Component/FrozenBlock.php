<?php

declare(strict_types=1);

namespace Drupal\wordpal\Component;

/**
 * Names the component and theme files that hold Frozen blocks.
 *
 * A Frozen block is a block with no component of its own, kept as the HTML
 * WordPress rendered for it. The generated theme holds that HTML in one file
 * per block, named after the SHA-256 hash of the HTML, and the component
 * holds only the hash. The component is written by hand, since no WordPress
 * block.json describes it. A Query inside the block renders as its View in
 * a hole slot, at the place its HTML held.
 */
final class FrozenBlock {

  /**
   * The single-directory component slug.
   */
  public const SLUG = 'frozen-html';

  /**
   * The attribute marking a Frozen block's root on every page.
   */
  public const MARK_ATTRIBUTE = 'data-wordpal-frozen';

  /**
   * The number of Query holes a Frozen block places, one slot each.
   */
  public const HOLES = 4;

  /**
   * The start of the comment marking a Query hole in a Frozen block's file.
   */
  public const HOLE_PREFIX = '<!--wordpal:hole-';

  /**
   * Returns the comment marking one Query hole in a Frozen block's file.
   */
  public static function hole(int $number): string {
    return self::HOLE_PREFIX . "$number-->";
  }

  /**
   * Returns whether a comment, its delimiters included, is one hole marker.
   */
  public static function isHole(string $comment): bool {
    return preg_match('/^' . preg_quote(self::HOLE_PREFIX, '/') . '\d+-->\z/', $comment) === 1;
  }

  /**
   * Splits a Frozen block's file at its Query holes.
   *
   * @return string[]
   *   The HTML before the first hole, between holes, and after the last.
   */
  public static function split(string $html): array {
    return preg_split('/' . preg_quote(self::HOLE_PREFIX, '/') . '\d+-->/', $html);
  }

  /**
   * Returns the component slot that fills one Query hole.
   */
  public static function holeSlot(int $number): string {
    return "hole_$number";
  }

  /**
   * Returns the key a Frozen block's HTML is stored under.
   *
   * $html is the HTML WordPress rendered. The stored file holds it with its
   * Queries' holes marked (FrozenHoles), and the snapshot keys the block's
   * plugin styles by this same key.
   */
  public static function key(string $html): string {
    return hash('sha256', $html);
  }

  /**
   * Returns whether a value has the shape key() returns.
   */
  public static function isKey(string $key): bool {
    return preg_match('/^[0-9a-f]{64}\z/', $key) === 1;
  }

  /**
   * Returns the theme-relative path of the file holding one key's HTML.
   */
  public static function path(string $key): string {
    return "frozen/$key.html";
  }

  /**
   * Returns the theme-relative path of the file listing one key's libraries.
   *
   * The file is a YAML list of the generated theme's library names, such as
   * "plugin.wc-blocks-style". A block with no plugin stylesheet has none.
   */
  public static function librariesPath(string $key): string {
    return "frozen/$key.libraries.yml";
  }

}
