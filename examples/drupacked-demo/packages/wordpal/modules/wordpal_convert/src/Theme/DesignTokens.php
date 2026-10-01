<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

use Symfony\Component\Yaml\Yaml;

/**
 * Writes a theme's presets as DTCG design tokens, the format of core 11.5.
 *
 * Each group carries the name WordPress gives the preset type in a
 * `var:preset|<group>|<slug>` reference, and each token the preset's slug.
 * The token's description holds the name WordPress shows for the preset.
 */
final class DesignTokens {

  /**
   * The preset groups, as group => key of the value in a WordPress preset.
   */
  public const GROUPS = [
    'color' => 'color',
    'gradient' => 'gradient',
    'font-size' => 'size',
    'font-family' => 'fontFamily',
    'spacing' => 'size',
  ];

  /**
   * Builds the tokens file from the presets of a snapshot.
   *
   * @param array $presets
   *   Lists of WordPress presets, keyed by the groups of self::GROUPS.
   *
   * @return string
   *   The YAML of a `<theme>.tokens.yml` file.
   */
  public static function yaml(array $presets): string {
    $tokens = [];
    foreach (self::GROUPS as $group => $valueKey) {
      foreach ($presets[$group] as $preset) {
        $tokens[$group][$preset['slug']] = self::token($group, $preset[$valueKey]) + ['$description' => $preset['name'] ?? ''];
      }
    }
    return Yaml::dump($tokens, 4, 2);
  }

  /**
   * Builds the type and value of one token.
   *
   * DTCG has no gradient string, so a gradient is a color token, as core
   * reads neither. A font size or spacing size is a dimension when it is a
   * length in px or rem, the units core's Dimension accepts. Any other CSS
   * value, such as `min(1.5rem, 2vw)`, stays untyped, which core skips.
   */
  private static function token(string $group, string $value): array {
    if ($group === 'color' || $group === 'gradient') {
      return ['$type' => 'color', '$value' => $value];
    }
    if ($group === 'font-family') {
      $names = array_map(static fn (string $name): string => trim($name, " \"'"), explode(',', $value));
      return ['$type' => 'fontFamily', '$value' => $names];
    }
    if (preg_match('/^(\d*\.?\d+)(px|rem)$/', $value, $match)) {
      return ['$type' => 'dimension', '$value' => ['value' => $match[1] + 0, 'unit' => $match[2]]];
    }
    return ['$value' => $value];
  }

}
