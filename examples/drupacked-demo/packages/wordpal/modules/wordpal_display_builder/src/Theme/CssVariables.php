<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder\Theme;

use Symfony\Component\Yaml\Yaml;

/**
 * Writes a theme's presets as ui_skins CSS variable definitions.
 *
 * Reads the DTCG tokens DesignTokens::yaml() already wrote for the same
 * theme, so a preset's default value is derived once, from one source of
 * truth. The plugin id reverses ui_skins' own CSS variable naming
 * (UiSkinsUtility::getCssVariableName(): "--" + the id, "_" turned to "-")
 * to WordPress's own `--wp--preset--<group>--<slug>` name.
 */
final class CssVariables {

  /**
   * Builds the ui_skins css_variables plugin definitions from DTCG tokens.
   *
   * @param array $tokens
   *   The decoded DTCG tokens \Drupal\wordpal_convert\Theme\DesignTokens
   *   ::yaml() produced, keyed by group then slug.
   *
   * @return string
   *   The YAML of a `<theme>.ui_skins.css_variables.yml` file.
   */
  public static function yaml(array $tokens): string {
    $definitions = [];
    foreach ($tokens as $group => $slugs) {
      foreach ($slugs as $slug => $token) {
        $definitions["wp--preset--$group--$slug"] = [
          'label' => (string) $token['$description'],
          'category' => 'WordPress presets',
          'default_values' => [':root' => self::value($token)],
        ];
      }
    }
    return Yaml::dump($definitions, 4, 2);
  }

  /**
   * Returns the CSS value a DTCG token's typed $value prints.
   */
  private static function value(array $token): string {
    return match ($token['$type'] ?? NULL) {
      'fontFamily' => implode(', ', array_map(
        static fn (string $name): string => str_contains($name, ' ') ? "\"$name\"" : $name,
        $token['$value'],
      )),
      'dimension' => $token['$value']['value'] . $token['$value']['unit'],
      default => (string) $token['$value'],
    };
  }

}
