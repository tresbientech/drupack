<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

use Drupal\Component\Utility\NestedArray;
use Drupal\wordpal\Component\PropName as RuntimePropName;
use Drupal\wordpal\Support\LayoutCss;
use Drupal\wordpal\Support\PresetValue;
use Drupal\wordpal\Support\PropSchema;

/**
 * Turns the attributes of a parsed block into flat component props.
 *
 * WordPress nests styling under `style` and `layout`. Components take one
 * scalar prop per value.
 */
final class AttributeFlattener {

  /**
   * Nested attribute paths, as path => prop name.
   */
  private const PATHS = [
    'style.spacing.blockGap' => 'block_gap',
    'style.typography.fontSize' => 'font_size',
    'style.typography.fontFamily' => 'font_family',
    'style.typography.lineHeight' => 'line_height',
    'style.typography.fontStyle' => 'font_style',
    'style.typography.fontWeight' => 'font_weight',
    'style.typography.letterSpacing' => 'letter_spacing',
    'style.typography.textTransform' => 'text_transform',
    'style.typography.textDecoration' => 'text_decoration',
    'style.typography.writingMode' => 'writing_mode',
    'style.typography.textColumns' => 'text_columns',
    'style.typography.textIndent' => 'text_indent',
    'style.typography.textAlign' => 'text_align',
    'style.color.background' => 'background_color',
    'style.color.text' => 'text_color',
    'style.color.gradient' => 'gradient',
    'style.color.duotone' => 'duotone',
    'style.elements.link.color.text' => 'link_color',
    'style.elements.heading.color.text' => 'heading_color',
    'style.elements.button.color.text' => 'button_color',
    'style.elements.button.color.background' => 'button_background_color',
    'style.shadow' => 'shadow',
    'style.dimensions.minHeight' => 'min_height',
    'style.dimensions.width' => 'width',
    'style.dimensions.aspectRatio' => 'aspect_ratio',
    'style.border.radius' => 'border_radius',
    'style.border.color' => 'border_color',
    'style.border.width' => 'border_width',
    'style.border.style' => 'border_style',
    'style.position.type' => 'position_type',
    'style.position.top' => 'position_top',
    'style.position.right' => 'position_right',
    'style.position.bottom' => 'position_bottom',
    'style.position.left' => 'position_left',
    'style.layout.selfStretch' => 'self_stretch',
    'style.layout.flexSize' => 'flex_size',
    'layout.type' => 'layout_type',
    'layout.contentSize' => 'content_size',
    'layout.wideSize' => 'wide_size',
    'layout.justifyContent' => 'justify_content',
    'layout.orientation' => 'orientation',
    'layout.flexWrap' => 'flex_wrap',
    'layout.verticalAlignment' => 'vertical_alignment',
    'layout.columnCount' => 'column_count',
    'layout.minimumColumnWidth' => 'minimum_column_width',
  ];

  /**
   * The props a preset attribute and a custom `style` value both write.
   */
  private const PRESET_PROPS = [
    'background_color', 'text_color', 'gradient', 'font_size', 'font_family',
    'border_color',
  ];

  /**
   * A block gap WordPress drops before layout (wp_sanitize_block_gap_value()).
   */
  private const UNSAFE_GAP = '%[\\\\(&=}]|/\*%';

  /**
   * Attributes whose prop carries another name.
   */
  private const ALIASES = ['className' => 'css_class', 'linkClassName' => 'link_css_class'];

  /**
   * Flattens one block's attributes.
   *
   * @param array $attributes
   *   The attributes from the block comment.
   * @param string $blockName
   *   The WordPress block name, such as "core/spacer".
   *
   * @return array
   *   Props, keyed by prop name.
   */
  public function flatten(array $attributes, string $blockName): array {
    $props = [];

    foreach ($attributes as $name => $value) {
      if (in_array($name, PropName::EDITOR_ONLY, TRUE) || $name === 'style' || $name === 'layout') {
        continue;
      }
      if (is_scalar($value)) {
        $prop = self::ALIASES[$name] ?? PropName::fromAttribute((string) $name);
        $props[$prop] = in_array($prop, self::PRESET_PROPS, TRUE) ? self::presetReference($prop, (string) $value) : $value;
      }
    }

    foreach (self::PATHS as $path => $prop) {
      $value = NestedArray::getValue($attributes, explode('.', $path));
      // A preset attribute wins over the custom value saved beside it
      // (block-supports/colors.php, typography.php and border.php).
      if (is_scalar($value) && !(isset($props[$prop]) && in_array($prop, self::PRESET_PROPS, TRUE))) {
        $props[$prop] = $value;
      }
    }

    // WordPress rounds a focal point to whole percentages before printing it
    // (cover.php, block_core_cover_render_content_with_post_thumbnail()) and
    // the block's own save() does the same for a static background image.
    $x = NestedArray::getValue($attributes, ['focalPoint', 'x']);
    $y = NestedArray::getValue($attributes, ['focalPoint', 'y']);
    if (is_numeric($x) && is_numeric($y)) {
      $props['focal_point'] = round($x * 100) . '% ' . round($y * 100) . '%';
    }

    // wp_render_background_support() reads these five sub-properties and
    // prints each as its own inline declaration; the component template
    // builds that declaration list itself (PresetValue::css()'s allowlist
    // excludes url()), so each reaches its own scalar prop here.
    $image = NestedArray::getValue($attributes, ['style', 'background', 'backgroundImage', 'url']);
    if (is_scalar($image)) {
      $props['background_image'] = $image;
    }
    foreach ([
      'backgroundSize' => 'background_size',
      'backgroundPosition' => 'background_position',
      'backgroundRepeat' => 'background_repeat',
      'backgroundAttachment' => 'background_attachment',
    ] as $key => $prop) {
      $value = NestedArray::getValue($attributes, ['style', 'background', $key]);
      if (is_scalar($value)) {
        $props[$prop] = $value;
      }
    }

    if (isset($props['block_gap']) && preg_match(self::UNSAFE_GAP, (string) $props['block_gap'])) {
      unset($props['block_gap']);
    }

    // WordPress lays out a legacy `inherit` flag, or any content width, as a
    // constrained layout (wp_render_layout_support_flag()).
    if (!empty($attributes['layout']['inherit']) || !empty($attributes['layout']['contentSize'])) {
      $props['layout_type'] = 'constrained';
    }

    // A Post Template reads the Query's legacy `displayLayout` as context.
    if (($attributes['displayLayout']['type'] ?? NULL) === 'flex' && is_scalar($attributes['displayLayout']['columns'] ?? NULL)) {
      $props['display_layout_columns'] = $attributes['displayLayout']['columns'];
    }

    foreach (['padding', 'margin'] as $box) {
      foreach (RuntimePropName::SIDES as $side) {
        $value = NestedArray::getValue($attributes, ['style', 'spacing', $box, $side]);
        if (is_scalar($value)) {
          $props[$box . '_' . $side] = $value;
        }
      }
    }

    foreach (RuntimePropName::SIDES as $side) {
      $value = NestedArray::getValue($attributes, ['style', 'border', $side, 'width']);
      if (is_scalar($value)) {
        $props["border_{$side}_width"] = $value;
      }
      $value = NestedArray::getValue($attributes, ['style', 'border', $side, 'color']);
      if (is_scalar($value)) {
        $props["border_{$side}_color"] = $value;
      }
      $value = NestedArray::getValue($attributes, ['style', 'border', $side, 'style']);
      if (is_scalar($value)) {
        $props["border_{$side}_style"] = $value;
      }
    }

    // A border radius can hold one corner value per key, instead of the
    // uniform string at style.border.radius.
    foreach (RuntimePropName::CORNERS as $corner => $key) {
      $value = NestedArray::getValue($attributes, ['style', 'border', 'radius', $key]);
      if (is_scalar($value)) {
        $props["border_{$corner}_radius"] = $value;
      }
    }

    // A gap can hold a row and a column value, which CSS writes as one pair.
    // A side the block leaves out names the root gap, which LayoutCss swaps
    // for the block type's own gap where the theme sets one.
    $row = self::safeGap(NestedArray::getValue($attributes, ['style', 'spacing', 'blockGap', 'top']));
    $column = self::safeGap(NestedArray::getValue($attributes, ['style', 'spacing', 'blockGap', 'left']));
    if (is_scalar($row) || is_scalar($column)) {
      $props['block_gap'] = ($row ?? LayoutCss::ROOT_GAP) . ' ' . ($column ?? LayoutCss::ROOT_GAP);
    }

    // WordPress prints a unitless border width in pixels on the same blocks
    // (block-supports/border.php:92-93).
    if (isset($props['border_width']) && is_numeric($props['border_width']) && PropSchema::isStyleEngineBlock($blockName)) {
      $props['border_width'] .= 'px';
    }

    // WordPress prints a unitless radius in pixels on a block the style
    // engine writes (block-supports/border.php:67-69).
    if (isset($props['border_radius']) && is_numeric($props['border_radius']) && PropSchema::isStyleEngineBlock($blockName)) {
      $props['border_radius'] .= 'px';
    }
    // A Spacer's save() interpolates height/width straight into the style
    // attribute, and a bare number becomes a pixel value the way any numeric
    // inline style does (spacer/save.js).
    if ($blockName === 'core/spacer') {
      foreach (['height', 'width'] as $prop) {
        if (isset($props[$prop]) && is_numeric($props[$prop])) {
          $props[$prop] .= 'px';
        }
      }
    }

    return $props;
  }

  /**
   * Returns the style paths holding a value that no prop reads.
   *
   * A path is unread when flatten() returns the same props without it. A
   * list, such as a custom duotone's colors, is one path.
   *
   * @param array $attributes
   *   The attributes from the block comment.
   * @param string $blockName
   *   The WordPress block name, such as "core/image".
   *
   * @return string[]
   *   Dotted paths, such as "style.color.duotone".
   */
  public function unreadStyles(array $attributes, string $blockName): array {
    // A theme's block comment can hold any JSON under "style".
    if (!is_array($attributes['style'] ?? NULL)) {
      return [];
    }
    $props = $this->flatten($attributes, $blockName);
    $unread = [];
    foreach (self::leaves($attributes['style'], ['style']) as $parents) {
      $without = $attributes;
      NestedArray::unsetValue($without, $parents);
      if ($this->flatten($without, $blockName) === $props) {
        $unread[] = implode('.', $parents);
      }
    }
    return $unread;
  }

  /**
   * Returns the parents of every leaf holding a value, lists as one leaf.
   *
   * @return string[][]
   *   Key paths, each starting with $parents.
   */
  private static function leaves(array $values, array $parents): array {
    $leaves = [];
    foreach ($values as $key => $value) {
      if ($value === NULL || $value === '' || $value === []) {
        continue;
      }
      $leaves = is_array($value) && !array_is_list($value)
        ? [...$leaves, ...self::leaves($value, [...$parents, (string) $key])]
        : [...$leaves, [...$parents, (string) $key]];
    }
    return $leaves;
  }

  /**
   * Returns the reference a top-level preset attribute stands for.
   *
   * WordPress always treats these attributes as presets (for the font size,
   * wp_apply_typography_support()'s "var:preset|font-size|{$slug}"), and
   * kebab-cases the slug into a class even when a theme template hardcodes a
   * literal CSS value there (WP_Style_Engine::get_slug_from_preset_value()).
   * The nested `style.*` paths stay raw CSS values, so a prop holds a preset
   * as a reference.
   */
  private static function presetReference(string $prop, string $value): string {
    return 'var:preset|' . PropSchema::presetGroup($prop) . '|' . PresetValue::slug($value);
  }

  /**
   * Returns one side of a block gap, or NULL where WordPress drops it.
   */
  private static function safeGap(mixed $value): mixed {
    return is_scalar($value) && preg_match(self::UNSAFE_GAP, (string) $value) ? NULL : $value;
  }

}
