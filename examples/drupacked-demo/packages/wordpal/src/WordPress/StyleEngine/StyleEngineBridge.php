<?php

declare(strict_types=1);

namespace Drupal\wordpal\WordPress\StyleEngine;

use Drupal\wordpal\WordPress\StyleEngine\Vendor\WP_Style_Engine;
use Drupal\wordpal\WordPress\StyleEngine\Vendor\WP_Style_Engine_CSS_Declarations;

/**
 * Runs one style object through the copied WordPress style engine.
 *
 * `BlockSupports` builds the style object WordPress itself would build from
 * a block's attributes, and reads back the classnames and declarations
 * WordPress's own `WP_Style_Engine::BLOCK_STYLE_DEFINITIONS_METADATA` table
 * assigns to it, rather than holding a second copy of that table.
 */
final class StyleEngineBridge {

  /**
   * Whether the vendor files and their shims have been loaded.
   */
  private static bool $loaded = FALSE;

  /**
   * Returns the classnames and declarations for one style object.
   *
   * @param array $blockStyles
   *   A style object shaped like a block's `style` attribute, such as
   *   `['color' => ['background' => 'var:preset|color|accent']]`.
   * @param array $options
   *   The style engine's options, such as `convert_vars_to_classnames`.
   *
   * @return array{classnames: string[], styles: array<string, string>}
   *   The classnames the style engine assigns, and the declarations it
   *   writes, filtered the way an inline style attribute filters them.
   */
  public static function declarations(array $blockStyles, array $options = []): array {
    self::load();
    $parsed = WP_Style_Engine::parse_block_styles($blockStyles, $options);
    if ($parsed['declarations'] === []) {
      return ['classnames' => array_unique($parsed['classnames']), 'styles' => []];
    }
    // get_declarations_string() is the style engine's only public accessor
    // for its safecss-filtered output; this un-joins it back into an array
    // so BlockSupports can merge it with its other style declarations.
    $declarations = new WP_Style_Engine_CSS_Declarations($parsed['declarations']);
    $styles = [];
    foreach (array_filter(explode(';', $declarations->get_declarations_string())) as $declaration) {
      [$property, $value] = explode(':', $declaration, 2);
      $styles[$property] = $value;
    }
    return ['classnames' => array_unique($parsed['classnames']), 'styles' => $styles];
  }

  /**
   * Loads the shims and the copied style engine once per request.
   */
  private static function load(): void {
    if (self::$loaded) {
      return;
    }
    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/Vendor/class-wp-style-engine-css-rule.php';
    require_once __DIR__ . '/Vendor/class-wp-style-engine-css-rules-store.php';
    require_once __DIR__ . '/Vendor/class-wp-style-engine-css-declarations.php';
    require_once __DIR__ . '/Vendor/class-wp-style-engine-processor.php';
    require_once __DIR__ . '/Vendor/class-wp-style-engine.php';
    self::$loaded = TRUE;
  }

}
