<?php

declare(strict_types=1);

namespace Drupal\wordpal\Support;

/**
 * The WordPress block-support facts every reader of a prop needs.
 *
 * Holds which props are presets and in what shape, which blocks build their
 * wrapper at render time, and which props the style engine writes there.
 * `InspectorFormHooks`, `CanvasWriter` and `BlockSupports` read these facts
 * through this class rather than each holding its own copy of the tables.
 */
final class PropSchema {

  /**
   * Preset props, as prop => [theme.json preset group, stored shape].
   *
   * A slug shape becomes a class, such as `has-base-color`. A reference
   * shape stores a `var:preset|<group>|<slug>` string.
   *
   * @see \Drupal\wordpal\Support\PresetValue::css()
   */
  private const PRESETS = [
    'background_color' => ['color', PresetShape::Slug],
    'text_color' => ['color', PresetShape::Slug],
    'gradient' => ['gradient', PresetShape::Slug],
    'border_color' => ['color', PresetShape::Slug],
    'font_size' => ['font-size', PresetShape::Slug],
    'font_family' => ['font-family', PresetShape::Slug],
    'link_color' => ['color', PresetShape::Reference],
    'heading_color' => ['color', PresetShape::Reference],
    'border_top_color' => ['color', PresetShape::Reference],
    'border_right_color' => ['color', PresetShape::Reference],
    'border_bottom_color' => ['color', PresetShape::Reference],
    'border_left_color' => ['color', PresetShape::Reference],
    'padding_top' => ['spacing', PresetShape::Reference],
    'padding_right' => ['spacing', PresetShape::Reference],
    'padding_bottom' => ['spacing', PresetShape::Reference],
    'padding_left' => ['spacing', PresetShape::Reference],
    'margin_top' => ['spacing', PresetShape::Reference],
    'margin_right' => ['spacing', PresetShape::Reference],
    'margin_bottom' => ['spacing', PresetShape::Reference],
    'margin_left' => ['spacing', PresetShape::Reference],
    'block_gap' => ['spacing', PresetShape::Reference],
  ];

  /**
   * The WordPal blocks whose wrapper WordPress builds at render time.
   *
   * These call get_block_wrapper_attributes() in WordPress 7.1.2, so the
   * style engine writes their styles. Every other block keeps the styles the
   * editor saved into its markup.
   */
  private const STYLE_ENGINE_BLOCKS = [
    'core/categories', 'core/navigation', 'core/post-author',
    'core/post-author-biography', 'core/post-author-name', 'core/post-content',
    'core/post-date', 'core/post-excerpt', 'core/post-featured-image',
    'core/post-template', 'core/post-terms', 'core/post-title',
    'core/query-no-results', 'core/query-pagination',
    'core/query-pagination-next', 'core/query-pagination-numbers',
    'core/query-pagination-previous', 'core/query-title', 'core/read-more',
    'core/search', 'core/site-logo', 'core/site-tagline', 'core/site-title',
    'core/social-link', 'core/term-description',
  ];

  /**
   * The blocks that never get `has-link-color`, though `wp-elements-N` does.
   *
   * A static block's own saved markup, and every dynamic block whose own
   * render function reads `style.elements.link.color.text`
   * (`render_block_core_site_title()` and its kind), carries this class as
   * one more of its own color classes. WordPal's converter never keeps that
   * class in `css_class`, since it is one `BlockSupports::render()` already
   * regenerates from the structured color props alongside
   * `has-<slug>-color`; this list holds only the blocks that render no
   * class from their own saved link color at all, confirmed against each
   * one's WordPress 7.1.2 render function. Most name a container that
   * renders no anchor of its own, such as `core/read-more` or
   * `core/post-content`; `core/navigation`'s own render has its own class
   * handling in `templates/wordpal-navigation.html.twig` instead.
   */
  private const NO_HAS_LINK_COLOR_CLASS_BLOCKS = [
    'core/archives', 'core/categories', 'core/navigation', 'core/post-author-biography',
    'core/post-content', 'core/post-featured-image',
    'core/query-pagination-next', 'core/query-pagination-numbers',
    'core/query-pagination-previous', 'core/query-title', 'core/read-more',
    'core/search', 'core/site-logo', 'core/site-tagline', 'core/social-link',
  ];

  /**
   * The border props of a block: radius, color and width, uniform and per side.
   */
  public const BORDER_PROPS = [
    'border_radius', 'border_color', 'border_width',
    'border_top_width', 'border_top_color', 'border_right_width', 'border_right_color',
    'border_bottom_width', 'border_bottom_color', 'border_left_width', 'border_left_color',
    'border_top_left_radius', 'border_top_right_radius', 'border_bottom_left_radius', 'border_bottom_right_radius',
  ];

  /**
   * Style props the style engine writes on a block wrapper.
   */
  private const STYLE_ENGINE_PROPS = [
    'background_color', 'text_color', 'gradient', 'font_size', 'font_family',
    'line_height', 'font_style', 'font_weight', 'letter_spacing',
    'text_transform', 'text_decoration', 'writing_mode', 'text_columns', 'text_indent',
    'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
    'margin_top', 'margin_right', 'margin_bottom', 'margin_left',
    'min_height', 'border_radius', 'shadow',
    'border_top_width', 'border_right_width', 'border_bottom_width', 'border_left_width',
    'border_top_color', 'border_right_color', 'border_bottom_color', 'border_left_color',
  ];

  /**
   * The preset group and stored shape of a prop, or NULL for a plain prop.
   */
  public static function preset(string $prop): ?PresetSupport {
    if (!isset(self::PRESETS[$prop])) {
      return NULL;
    }
    [$group, $shape] = self::PRESETS[$prop];
    return new PresetSupport($group, $shape);
  }

  /**
   * Returns TRUE when WordPress's style engine writes this prop of a block.
   *
   * The font size and the border radius are left out: WordPress turns a
   * number into a length before the style engine reads it
   * (wp_get_typography_font_size_value(), block-supports/border.php:67-69).
   */
  public static function isStyleEngineValue(string $blockName, string $prop): bool {
    return !in_array($prop, ['font_size', 'border_radius'], TRUE)
      && self::isStyleEngineBlock($blockName)
      && in_array($prop, self::STYLE_ENGINE_PROPS, TRUE);
  }

  /**
   * Returns TRUE when WordPress renders the block's supports at runtime.
   */
  public static function isStyleEngineBlock(string $blockName): bool {
    return in_array($blockName, self::STYLE_ENGINE_BLOCKS, TRUE);
  }

  /**
   * Returns TRUE when a saved link color prints this block's `has-link-color`.
   */
  public static function addsHasLinkColorClass(string $blockName): bool {
    return !in_array($blockName, self::NO_HAS_LINK_COLOR_CLASS_BLOCKS, TRUE);
  }

  /**
   * The style props the style engine writes on a wrapper it owns.
   */
  public static function styleEngineProps(): array {
    return self::STYLE_ENGINE_PROPS;
  }

}
