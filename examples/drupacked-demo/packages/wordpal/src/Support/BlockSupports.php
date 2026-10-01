<?php

declare(strict_types=1);

namespace Drupal\wordpal\Support;

use Drupal\wordpal\Component\PropName;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal\WordPress\StyleEngine\StyleEngineBridge;

/**
 * Turns the style props of a block into classes, inline styles and CSS.
 *
 * WordPress splits this work: the block editor writes colors, typography and
 * spacing into the saved markup, and WordPress adds the layout at render
 * time. A converted block holds all of it as props, so both halves happen
 * here. Colors, typography, spacing, dimensions, border, the shadow and the
 * elements support go through `StyleEngineBridge`, the copied WordPress style
 * engine. Layout, duotone and position stay hand-written: `LayoutCss` owns
 * layout by decision, and WordPress builds duotone and position outside its
 * style engine. A static block's per-side border values stay hand-written
 * too, because the style engine treats them differently from WordPress's
 * static markup (see `addBorder()`).
 */
final class BlockSupports {

  /**
   * The style groups whose preset values print as a class alone.
   *
   * WordPress's colors.php and typography.php call the style engine with
   * `convert_vars_to_classnames`; the other supports also print the
   * preset's `var()` inline.
   */
  private const CLASSNAME_PRESET_GROUPS = ['color', 'typography'];

  /**
   * Element color props, as element => [color key => prop].
   */
  private const ELEMENT_COLORS = [
    'link' => ['text' => 'link_color'],
    'heading' => ['text' => 'heading_color'],
    'button' => ['text' => 'button_color', 'background' => 'button_background_color'],
  ];

  /**
   * The selectors each element rule applies to, inside the block.
   *
   * WordPress's `wp_render_elements_support_styles()` writes these.
   */
  private const ELEMENT_SELECTORS = [
    'link' => ['a:where(:not(.wp-element-button))'],
    'heading' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
    'button' => ['.wp-element-button', '.wp-block-button__link'],
  ];

  public function __construct(
    private readonly LayoutCss $layoutCss,
    private readonly ThemeSettings $themeSettings,
    private readonly ComponentPluginManager $components,
  ) {}

  /**
   * Builds the output of one block instance.
   *
   * @param string $blockName
   *   The WordPress block name, such as "core/group".
   * @param array $props
   *   The block's props, as the component received them.
   * @param bool $includeLayout
   *   FALSE when the layout belongs to an inner element, as in a cover block.
   * @param array $layoutDefaults
   *   The layout the block type ships, such as the flex row of a columns block.
   *
   * @return \Drupal\wordpal\Support\SupportOutput
   *   Classes, inline styles, CSS rules and attributes.
   */
  public function render(string $blockName, array $props, bool $includeLayout = TRUE, array $layoutDefaults = []): SupportOutput {
    $classes = [];
    $styles = [];
    $rules = [];
    $attributes = [];
    $props = $this->withoutCustomReferences($blockName, $props);

    if (!empty($props['css_class'])) {
      $classes = array_merge($classes, $this->classNames($blockName, (string) $props['css_class']));
    }
    if (!empty($props['align'])) {
      $classes[] = 'align' . $props['align'];
    }
    if (!empty($props['text_align'])) {
      $classes[] = 'has-text-align-' . $props['text_align'];
    }
    if (!empty($props['anchor'])) {
      $attributes['id'] = (string) $props['anchor'];
    }
    if (!empty($props['aria_label'])) {
      $attributes['aria-label'] = (string) $props['aria_label'];
    }
    if (!empty($props['fit_text'])) {
      // WordPress's wp_render_typography_support() adds these directives
      // for the fit-text script module.
      $classes[] = 'has-fit-text';
      $attributes['data-wp-interactive'] = '';
      $attributes['data-wp-context---core-fit-text'] = 'core/fit-text::{"fontSize":""}';
      $attributes['data-wp-init---core-fit-text'] = 'core/fit-text::callbacks.init';
      $attributes['data-wp-style--font-size'] = 'core/fit-text::context.fontSize';
    }

    $this->addColors($blockName, $props, $classes, $styles, $rules);
    $this->addTypography($props, $classes, $styles);
    $this->addSpacing($props, $classes, $styles);
    $this->addDimensions($props, $classes, $styles);
    $this->addBorder($blockName, $props, $classes, $styles);
    $this->addFromStyleEngine(['shadow'], 'shadow', $props, $classes, $styles);
    $this->addDuotone($blockName, $props, $classes, $rules);
    $this->addPosition($props, $classes, $rules);

    $layout = $includeLayout ? $this->layout($blockName, $props, $layoutDefaults) : new SupportOutput([], [], []);
    $childLayout = $this->layoutCss->childLayout($props);

    return new SupportOutput(
      array_values(array_unique(array_merge($classes, $layout->classes, $childLayout->classes))),
      $styles,
      $rules + $layout->cssRules + $childLayout->cssRules,
      $attributes,
    );
  }

  /**
   * Leaves out the style values the style engine prints no declaration for.
   *
   * The values are still validated, so a malformed one fails loudly.
   */
  private function withoutCustomReferences(string $blockName, array $props): array {
    if (!PropSchema::isStyleEngineBlock($blockName)) {
      return $props;
    }
    foreach (PropSchema::styleEngineProps() as $prop) {
      if (isset($props[$prop]) && is_string($props[$prop]) && PresetValue::isCustomReference($props[$prop])) {
        PresetValue::css($props[$prop]);
        unset($props[$prop]);
      }
    }
    return $props;
  }

  /**
   * Builds only the layout of a block.
   */
  public function layout(string $blockName, array $props, array $layoutDefaults = []): SupportOutput {
    return $this->layoutCss->build($blockName, $props, $layoutDefaults);
  }

  /**
   * Splits the class names a block carries, variation classes included.
   *
   * A theme can style one block style variation. WordPress then marks every
   * instance of it with a second class of its own. Here every instance of a
   * variation shares that class, and snapshot.php keys the variation's
   * stylesheet to it.
   */
  private function classNames(string $blockName, string $cssClass): array {
    $classes = preg_split('/\s+/', trim($cssClass), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($classes as $class) {
      if (!str_starts_with($class, 'is-style-')) {
        continue;
      }
      $variationClass = $this->themeSettings->variationClass($blockName, substr($class, strlen('is-style-')));
      if ($variationClass !== NULL) {
        $classes[] = $variationClass;
      }
    }
    return $classes;
  }

  /**
   * Adds the color support. A preset becomes a class, a value a style.
   */
  private function addColors(string $blockName, array $props, array &$classes, array &$styles, array &$rules): void {
    $this->addFromStyleEngine(['color', 'background'], 'background_color', $props, $classes, $styles);
    $this->addFromStyleEngine(['color', 'text'], 'text_color', $props, $classes, $styles);
    $this->addFromStyleEngine(['color', 'gradient'], 'gradient', $props, $classes, $styles);
    $this->addElementColors($blockName, $props, $classes, $rules);
  }

  /**
   * Adds the elements support: link, heading and button colors inside a block.
   *
   * WordPress's `wp_render_elements_support_styles()` gives a block one
   * `wp-elements-N` class when any of its element colors are set, then
   * registers one CSS rule per element type under that shared class. A link
   * color also prints a `has-link-color` class, one `PropSchema` names the
   * few blocks that never render, from the block's own render function; that
   * runs before the elements filter appends `wp-elements-N`, so
   * `has-link-color` orders before it.
   */
  private function addElementColors(string $blockName, array $props, array &$classes, array &$rules): void {
    $declarations = [];
    foreach (self::ELEMENT_COLORS as $element => $colors) {
      $color = [];
      foreach ($colors as $key => $prop) {
        if (!empty($props[$prop])) {
          $color[$key] = $this->styleValue($prop, (string) $props[$prop]);
        }
      }
      if ($color !== []) {
        $styles = StyleEngineBridge::declarations(['color' => $color])['styles'];
        $declarations[$element] = implode('', array_map(static fn (string $property, string $value): string => "$property:$value;", array_keys($styles), $styles));
      }
    }
    if ($declarations === []) {
      return;
    }
    if (isset($declarations['link']) && PropSchema::addsHasLinkColorClass($blockName)) {
      $classes[] = 'has-link-color';
    }
    $container = 'wp-elements-' . substr(md5(implode('|', $declarations)), 0, 8);
    $classes[] = $container;
    foreach ($declarations as $element => $css) {
      $rules[implode(', ', array_map(static fn (string $selector): string => ".$container $selector", self::ELEMENT_SELECTORS[$element]))] = $css;
    }
  }

  /**
   * Adds a duotone preset, as WP_Duotone::render_duotone_support() does.
   *
   * The filter rule applies to the elements block.json names. A custom
   * duotone never reaches a prop: the flattener keeps scalar values only.
   */
  private function addDuotone(string $blockName, array $props, array &$classes, array &$rules): void {
    $slug = PresetValue::duotoneSlug((string) ($props['duotone'] ?? ''));
    if ($slug === NULL || $this->themeSettings->duotoneFilter($slug) === NULL) {
      return;
    }
    $filterId = "wp-duotone-$slug";
    $classes[] = $filterId;
    $component = 'wordpal:' . substr($blockName, strlen('core/'));
    $selector = $this->components->getDefinition($component)['props']['properties']['duotone'][PropName::DUOTONE_SELECTOR];
    $scoped = array_map(static fn (string $part): string => ".$filterId" . trim($part), explode(',', $selector));
    $rules[implode(', ', $scoped)] = "filter:url(#$filterId);";
  }

  /**
   * Adds the position support, as wp_render_position_support() does.
   *
   * WordPress names the container with wp_unique_id(); this names it after
   * its rules.
   */
  private function addPosition(array $props, array &$classes, array &$rules): void {
    $type = (string) ($props['position_type'] ?? '');
    if ($type === '' || !$this->themeSettings->allowsPosition($type)) {
      return;
    }
    $declarations = '';
    foreach (PropName::SIDES as $side) {
      $value = $props["position_$side"] ?? NULL;
      if ($value === NULL || $value === '') {
        continue;
      }
      $value = PresetValue::css((string) $value);
      if ($side === 'top') {
        // WordPress offsets a sticky or fixed top by the admin bar.
        $value = 'calc(' . ($value === '0' ? '0px' : $value) . ' + var(--wp-admin--admin-bar--position-offset, 0px))';
      }
      $declarations .= "$side:$value;";
    }
    $declarations .= "position:$type;z-index:10;";
    $container = 'wp-container-' . substr(md5($declarations), 0, 8);
    $classes[] = $container;
    $classes[] = "is-position-$type";
    $rules[".$container"] = $declarations;
  }

  /**
   * Adds the typography support.
   */
  private function addTypography(array $props, array &$classes, array &$styles): void {
    $size = (string) ($props['font_size'] ?? '');
    if ($size !== '' && !PresetValue::isSlug($size) && !str_starts_with($size, 'var:')) {
      // WordPress turns a custom font size into its fluid value before the
      // style engine reads it (wp_get_typography_font_size_value()).
      $props['font_size'] = $this->themeSettings->fontSize(PresetValue::css($size));
    }
    $this->addFromStyleEngine(['typography', 'fontSize'], 'font_size', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'fontFamily'], 'font_family', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'lineHeight'], 'line_height', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'fontStyle'], 'font_style', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'fontWeight'], 'font_weight', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'letterSpacing'], 'letter_spacing', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'textTransform'], 'text_transform', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'textDecoration'], 'text_decoration', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'writingMode'], 'writing_mode', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'textColumns'], 'text_columns', $props, $classes, $styles);
    $this->addFromStyleEngine(['typography', 'textIndent'], 'text_indent', $props, $classes, $styles);
  }

  /**
   * Adds the spacing support. The block gap belongs to the layout rules.
   */
  private function addSpacing(array $props, array &$classes, array &$styles): void {
    foreach (['padding', 'margin'] as $box) {
      foreach (PropName::SIDES as $side) {
        $this->addFromStyleEngine(['spacing', $box, $side], "{$box}_{$side}", $props, $classes, $styles);
      }
    }
  }

  /**
   * Adds the dimensions support.
   */
  private function addDimensions(array $props, array &$classes, array &$styles): void {
    $this->addFromStyleEngine(['dimensions', 'minHeight'], 'min_height', $props, $classes, $styles);
  }

  /**
   * Adds the border support.
   *
   * A side's own width, color and style go through the style engine only
   * for a block WordPress renders at runtime. The style engine drops a "0"
   * side value, and the editor keeps it in a static block's saved markup.
   */
  private function addBorder(string $blockName, array $props, array &$classes, array &$styles): void {
    $this->addFromStyleEngine(['border', 'radius'], 'border_radius', $props, $classes, $styles);
    foreach (PropName::CORNERS as $corner => $key) {
      $this->addFromStyleEngine(['border', 'radius', $key], "border_{$corner}_radius", $props, $classes, $styles);
    }
    $this->addFromStyleEngine(['border', 'color'], 'border_color', $props, $classes, $styles);
    $this->addFromStyleEngine(['border', 'width'], 'border_width', $props, $classes, $styles);
    $this->addFromStyleEngine(['border', 'style'], 'border_style', $props, $classes, $styles);
    foreach (PropName::SIDES as $side) {
      if (PropSchema::isStyleEngineBlock($blockName)) {
        foreach (['width', 'color', 'style'] as $property) {
          $this->addFromStyleEngine(['border', $side, $property], "border_{$side}_{$property}", $props, $classes, $styles);
        }
        continue;
      }
      if (isset($props["border_{$side}_width"]) && $props["border_{$side}_width"] !== '') {
        $styles["border-$side-width"] = PresetValue::css((string) $props["border_{$side}_width"]);
      }
      if (isset($props["border_{$side}_color"]) && $props["border_{$side}_color"] !== '') {
        $styles["border-$side-color"] = PresetValue::css((string) $props["border_{$side}_color"]);
      }
      if (isset($props["border_{$side}_style"]) && $props["border_{$side}_style"] !== '') {
        $styles["border-$side-style"] = PresetValue::css((string) $props["border_{$side}_style"]);
      }
    }
  }

  /**
   * Adds one style object leaf through the copied WordPress style engine.
   *
   * @param string[] $path
   *   The path into a WordPress style object, such as ['color', 'background'].
   * @param string $prop
   *   The prop holding the value, such as "background_color".
   * @param array $props
   *   The block's props, as render() received them.
   * @param string[] $classes
   *   Classes for the block's root element, appended to by reference.
   * @param array $styles
   *   Inline style declarations, keyed by CSS property, merged by reference.
   */
  private function addFromStyleEngine(array $path, string $prop, array $props, array &$classes, array &$styles): void {
    if (!isset($props[$prop]) || $props[$prop] === '') {
      return;
    }
    $value = $this->styleValue($prop, (string) $props[$prop]);
    $blockStyles = [];
    $cursor = &$blockStyles;
    foreach ($path as $index => $segment) {
      $cursor[$segment] = $index === array_key_last($path) ? $value : [];
      $cursor = &$cursor[$segment];
    }
    unset($cursor);
    $result = StyleEngineBridge::declarations($blockStyles, ['convert_vars_to_classnames' => in_array($path[0], self::CLASSNAME_PRESET_GROUPS, TRUE)]);
    $classes = array_merge($classes, $result['classnames']);
    $styles += $result['styles'];
  }

  /**
   * Returns a prop value in the form the style engine reads.
   */
  private function styleValue(string $prop, string $value): string {
    if (PresetValue::isCustomReference($value)) {
      // The style engine matches only `var:preset|...` paths, so a
      // `var:custom|...` reference resolves here instead of reaching a
      // wrapper the style engine writes, which would print no declaration
      // for it at all (WP_Style_Engine::get_css_var_value()).
      $value = PresetValue::css($value);
    }
    elseif (PropSchema::preset($prop)?->shape === PresetShape::Slug && PresetValue::isSlug($value)) {
      $value = 'var:preset|' . PropSchema::preset($prop)->group . "|$value";
    }
    else {
      // This value is our own converter's data, not a page author's. A
      // malformed one fails loudly here, rather than let the style engine's
      // own safecss_filter_attr() shim drop it silently downstream.
      PresetValue::css($value);
    }
    return $value;
  }

}
