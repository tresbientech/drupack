<?php

declare(strict_types=1);

namespace Drupal\wordpal\Support;

use Drupal\wordpal\Theme\ThemeSettings;

/**
 * Builds the classes and CSS that a block layout needs.
 *
 * WordPress writes these at render time. It names classes after what the
 * block itself states, and builds CSS from that merged with the layout the
 * block type ships, such as the flex row of a columns block.
 */
final class LayoutCss {

  /**
   * The props a block's saved `layout` attribute flattens to.
   *
   * Columns, Column and Media & Text carry a vertical_alignment attribute
   * outside their layout, so this list leaves it out.
   */
  private const LAYOUT_PROPS = [
    'layout_type', 'content_size', 'wide_size', 'justify_content',
    'orientation', 'flex_wrap', 'column_count', 'minimum_column_width',
  ];

  /**
   * The Gallery block's own gap fallback chain (blocks/gallery.php).
   *
   * `--gallery-block--gutter-size` is deprecated; WordPress keeps it in the
   * chain for themes that still set it.
   */
  private const GALLERY_GAP_FALLBACK = 'var(--wp--style--gallery-gap-default, var(--gallery-block--gutter-size, var(--wp--style--block-gap, 0.5em)))';

  /**
   * The root block gap, which a flattened gap names for a side it leaves out.
   *
   * A block.json `spacing.blockGap.__experimentalDefault` (`core/columns`
   * declares "2em") does not apply: WordPal blocks carry no block.json.
   */
  public const ROOT_GAP = 'var(--wp--style--block-gap, 0.5em)';

  public function __construct(
    private readonly ThemeSettings $themeSettings,
  ) {}

  /**
   * Builds the layout of one block.
   *
   * @param string $blockName
   *   The WordPress block name, such as "core/group".
   * @param array $props
   *   The block's props.
   * @param array $defaults
   *   The layout the block type ships, keyed like the layout props.
   *
   * @return \Drupal\wordpal\Support\SupportOutput
   *   Layout classes and the rules for its container class.
   */
  public function build(string $blockName, array $props, array $defaults = []): SupportOutput {
    // wp_render_layout_support_flag() uses a saved layout in place of the
    // block type's default, never merged with it.
    $saved = array_filter(array_intersect_key($props, array_flip(self::LAYOUT_PROPS)), static fn (mixed $value): bool => $value !== NULL && $value !== '');
    $used = $saved === [] ? $props + $defaults : $props;
    $type = $used['layout_type'] ?? 'default';
    $typeClass = $type === 'default' ? 'flow' : $type;

    $classes = [
      'is-layout-' . $typeClass,
      str_replace('core/', 'wp-block-', $blockName) . '-is-layout-' . $typeClass,
    ];
    if ($type === 'constrained' && $this->themeSettings->usesRootPaddingAwareAlignments()) {
      $classes[] = 'has-global-padding';
    }
    // These classes follow the block's own attributes, not the block type's.
    if (!empty($props['orientation'])) {
      $classes[] = 'is-' . $props['orientation'];
    }
    if (!empty($props['justify_content'])) {
      $classes[] = 'is-content-justification-' . $props['justify_content'];
    }
    if (($props['flex_wrap'] ?? NULL) === 'nowrap') {
      $classes[] = 'is-nowrap';
    }

    $rules = match ($type) {
      'constrained' => $this->constrainedRules($blockName, $used),
      'flex' => $this->flexRules($blockName, $used),
      'grid' => $this->gridRules($blockName, $used),
      default => $this->gapRules($blockName, $used),
    };

    if ($blockName === 'core/gallery' && $type === 'flex') {
      // blocks/gallery.php always writes this custom property, gap or no
      // gap of the block's own: the Gallery style.css's width calc()s divide
      // it out of each image's flex-basis, falling through to the theme's
      // block gap when the block sets none.
      $rules[''] = ($rules[''] ?? '') . '--wp--style--unstable-gallery-gap:' . $this->galleryGapColumn($blockName, $used) . ';';
    }

    if ($rules === []) {
      return new SupportOutput($classes, [], []);
    }

    $container = 'wp-container-' . str_replace('/', '-', $blockName) . '-is-layout-' . $this->hash($rules);
    $classes[] = $container;
    $selectors = [];
    foreach ($rules as $suffix => $declarations) {
      $selectors[".$container$suffix"] = $declarations;
    }

    return new SupportOutput($classes, [], $selectors);
  }

  /**
   * Builds the room a block takes inside its parent layout.
   */
  public function childLayout(array $props): SupportOutput {
    $declarations = [];
    $stretch = $props['self_stretch'] ?? NULL;
    // WordPress serializes Max as `fixed` and Fixed as `fixedNoShrink`.
    if (in_array($stretch, ['fixed', 'fixedNoShrink'], TRUE) && !empty($props['flex_size'])) {
      $declarations['flex-basis'] = PresetValue::css((string) $props['flex_size']);
      if ($stretch === 'fixedNoShrink') {
        $declarations['flex-shrink'] = '0';
      }
      $declarations['box-sizing'] = 'border-box';
    }
    elseif ($stretch === 'fill') {
      $declarations['flex-grow'] = '1';
    }

    if ($declarations === []) {
      return new SupportOutput([], [], []);
    }

    $rules = ['' => $this->declarations($declarations)];
    $container = 'wp-container-content-' . $this->hash($rules);
    return new SupportOutput([$container], [], [".$container" => $rules['']]);
  }

  /**
   * Returns the rules of a constrained layout.
   */
  private function constrainedRules(string $blockName, array $used): array {
    $rules = [];
    $contentSize = $used['content_size'] ?? '';
    $wideSize = $used['wide_size'] ?? '';
    $justify = $used['justify_content'] ?? 'center';

    if ($contentSize !== '' || $wideSize !== '') {
      $content = PresetValue::css((string) ($contentSize ?: $wideSize));
      $wide = PresetValue::css((string) ($wideSize ?: $contentSize));
      $marginLeft = $justify === 'left' ? '0 !important' : 'auto !important';
      $marginRight = $justify === 'right' ? '0 !important' : 'auto !important';
      $rules[' > :where(:not(.alignleft):not(.alignright):not(.alignfull))'] = "max-width:$content;margin-left:$marginLeft;margin-right:$marginRight;";
      $rules[' > .alignwide'] = "max-width:$wide;";
      $rules[' .alignfull'] = 'max-width:none;';
    }

    // A full width child reaches past the padding of its constrained parent.
    $negative = [];
    foreach (['right', 'left'] as $side) {
      $padding = $used["padding_$side"] ?? NULL;
      if ($padding !== NULL && $padding !== '') {
        $value = PresetValue::css((string) $padding);
        // A unitless zero makes calc() a number, which margins reject.
        $value = $value === '0' ? '0px' : $value;
        $negative["margin-$side"] = "calc($value * -1)";
      }
    }
    if ($negative !== []) {
      $rules[' > .alignfull'] = $this->declarations($negative);
    }

    if ($contentSize === '' && $wideSize === '' && in_array($justify, ['left', 'right'], TRUE)) {
      $side = $justify === 'left' ? 'margin-left' : 'margin-right';
      $rules[' > :where(:not(.alignleft):not(.alignright):not(.alignfull))'] = "$side:0 !important;";
    }

    return $rules + $this->gapRules($blockName, $used);
  }

  /**
   * Returns the rules of a flex layout.
   */
  private function flexRules(string $blockName, array $used): array {
    $declarations = [];
    // WordPress prints flex-wrap only for nowrap (layout.php:735).
    if (($used['flex_wrap'] ?? NULL) === 'nowrap') {
      $declarations['flex-wrap'] = 'nowrap';
    }
    if (($used['orientation'] ?? NULL) === 'vertical') {
      $declarations['flex-direction'] = 'column';
    }
    $gap = $this->gap($blockName, $used);
    if ($gap !== NULL) {
      $declarations['gap'] = $gap;
    }
    $justify = $used['justify_content'] ?? NULL;
    $vertical = $used['vertical_alignment'] ?? NULL;
    if (($used['orientation'] ?? NULL) === 'vertical') {
      $declarations['align-items'] = $justify ? $this->justify((string) $justify) : 'flex-start';
      if ($vertical) {
        $declarations['justify-content'] = $this->alignItems((string) $vertical);
      }
    }
    else {
      if ($justify) {
        $declarations['justify-content'] = $this->justify((string) $justify);
      }
      if ($vertical) {
        $declarations['align-items'] = $this->alignItems((string) $vertical);
      }
    }
    return $declarations === [] ? [] : ['' => $this->declarations($declarations)];
  }

  /**
   * Returns the rules of a grid layout.
   */
  private function gridRules(string $blockName, array $used): array {
    $declarations = [];
    if (!empty($used['column_count']) && !empty($used['minimum_column_width'])) {
      // The columns shrink in count once the container cannot fit the
      // minimum width, and the gap comes out of each column's share
      // (layout.php:877-881). A gap the block leaves out is the one its own
      // block type or the root sets, support or not.
      $count = (int) $used['column_count'];
      $override = $this->themeSettings->blockGapOverride($blockName, (string) ($used['css_class'] ?? ''));
      // Without block gap support the root gap's custom property is unset, so
      // the theme's own root value is the fallback.
      if ($override === NULL && !$this->themeSettings->hasBlockGapSupport()) {
        $override = $this->themeSettings->rootBlockGap();
      }
      $gap = $this->gap($blockName, $used)
        ?? PresetValue::layoutGap($override === NULL ? self::ROOT_GAP : self::gapSide($override, 'left'));
      $gap = $gap === '0' ? '0px' : $gap;
      $width = PresetValue::css((string) $used['minimum_column_width']);
      $declarations['grid-template-columns'] = "repeat(auto-fill, minmax(max(min($width, 100%), (100% - ($gap * ($count - 1))) /$count), 1fr))";
      $declarations['container-type'] = 'inline-size';
    }
    elseif (!empty($used['column_count'])) {
      $declarations['grid-template-columns'] = sprintf('repeat(%d, minmax(0, 1fr))', (int) $used['column_count']);
    }
    else {
      // wp_get_layout_style() falls back to 12rem when the grid sets neither.
      $width = PresetValue::css((string) (($used['minimum_column_width'] ?? '') ?: '12rem'));
      $declarations['grid-template-columns'] = sprintf('repeat(auto-fill, minmax(min(%s, 100%%), 1fr))', $width);
      $declarations['container-type'] = 'inline-size';
    }
    $gap = $this->gap($blockName, $used);
    if ($gap !== NULL) {
      $declarations['gap'] = $gap;
    }
    return $declarations === [] ? [] : ['' => $this->declarations($declarations)];
  }

  /**
   * Returns the rules that space out the children of a stacked layout.
   */
  private function gapRules(string $blockName, array $used): array {
    $gaps = $this->themeSettings->hasBlockGapSupport() ? $this->gapValues($blockName, $used) : [];
    if ($gaps === []) {
      return [];
    }
    $gap = $gaps[0];
    return [
      ' > *' => 'margin-block-start:0;margin-block-end:0;',
      ' > * + *' => "margin-block-start:$gap;margin-block-end:0;",
    ];
  }

  /**
   * Returns the gallery's column gap, or its unset fallback chain.
   *
   * Ports the $gap_column WordPress computes in blocks/gallery.php: the gap
   * value's column component when the block sets an asymmetric gap, its
   * single value otherwise, or the theme's block gap chain when the block
   * sets no gap at all. WordPress special-cases a literal `0` to `0px`
   * because the calc() this feeds needs a real unit.
   */
  private function galleryGapColumn(string $blockName, array $used): string {
    $values = $this->gapValues($blockName, $used);
    if ($values === []) {
      return self::GALLERY_GAP_FALLBACK;
    }
    $column = end($values);
    return $column === '0' ? '0px' : $column;
  }

  /**
   * Returns the gap as CSS, or NULL when the block sets none.
   */
  private function gap(string $blockName, array $used): ?string {
    $values = $this->themeSettings->hasBlockGapSupport() ? $this->gapValues($blockName, $used) : [];
    return $values === [] ? NULL : implode(' ', $values);
  }

  /**
   * Returns the row and optional column gap without splitting CSS functions.
   */
  private function gapValues(string $blockName, array $used): array {
    if (!isset($used['block_gap']) || $used['block_gap'] === '') {
      return [];
    }
    $values = $this->splitGap(trim((string) $used['block_gap']));
    // A side the block leaves out takes the gap its own block type or style
    // variation sets, else the root gap (layout.php:1177-1193).
    $override = $this->themeSettings->blockGapOverride($blockName, (string) ($used['css_class'] ?? ''));
    $sides = ['top', 'left'];
    foreach ($values as $index => $value) {
      $values[$index] = PresetValue::layoutGap($value === self::ROOT_GAP && $override !== NULL ? self::gapSide($override, $sides[$index]) : $value);
    }
    return $values;
  }

  /**
   * Returns the gap of one side, as layout.php reads a fallback gap.
   *
   * An array without the side stands for its first value.
   */
  private static function gapSide(string|array $gap, string $side): string {
    return is_array($gap) ? $gap[$side] ?? reset($gap) : $gap;
  }

  /**
   * Splits the row and column values at whitespace outside CSS functions.
   */
  private function splitGap(string $gap): array {
    $depth = 0;
    for ($index = 0, $length = strlen($gap); $index < $length; $index++) {
      $character = $gap[$index];
      $depth += $character === '(' ? 1 : ($character === ')' ? -1 : 0);
      if ($depth === 0 && ctype_space($character)) {
        return [substr($gap, 0, $index), ltrim(substr($gap, $index))];
      }
    }
    return [$gap];
  }

  /**
   * Names a class after a hash of its own rules.
   *
   * WordPress numbers these classes per request. A hash instead keeps the
   * class stable across cached pages, and lets blocks with the same layout
   * share one rule.
   */
  private function hash(array $rules): string {
    return substr(md5(serialize($rules)), 0, 8);
  }

  /**
   * Returns the CSS value for a justification.
   */
  private function justify(string $value): string {
    return match ($value) {
      'left' => 'flex-start',
      'right' => 'flex-end',
      default => $value,
    };
  }

  /**
   * Returns the CSS value for a vertical alignment.
   */
  private function alignItems(string $value): string {
    return match ($value) {
      'top' => 'flex-start',
      'bottom' => 'flex-end',
      default => $value,
    };
  }

  /**
   * Joins declarations into CSS text.
   */
  private function declarations(array $declarations): string {
    $text = '';
    foreach ($declarations as $property => $value) {
      $text .= "$property:$value;";
    }
    return $text;
  }

}
