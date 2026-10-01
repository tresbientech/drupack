<?php

declare(strict_types=1);

namespace Drupal\wordpal\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * What the converted theme states in its theme.json.
 *
 * WordPress reads these settings while it renders a block, so a converted
 * block reads them too. A conversion writes them, one config object per
 * generated theme, and only that theme's own rendering reads them back.
 */
final class ThemeSettings {

  private const CONFIG_PREFIX = 'wordpal.theme_settings.';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  /**
   * Returns the config name a theme's settings are stored under.
   */
  public static function configName(string $themeId): string {
    return self::CONFIG_PREFIX . $themeId;
  }

  /**
   * Returns whether a conversion wrote settings for the active theme.
   */
  public function activeThemeIsConverted(): bool {
    return !$this->configFactory->get(self::configName($this->themeManager->getActiveTheme()->getName()))->isNew();
  }

  /**
   * Returns TRUE when alignments follow the root padding of the theme.
   */
  public function usesRootPaddingAwareAlignments(): bool {
    return (bool) $this->settings()->get('use_root_padding_aware_alignments');
  }

  /**
   * Returns whether the theme turns block gap support on.
   *
   * Without it WordPress prints no gap CSS, whatever a block sets.
   */
  public function hasBlockGapSupport(): bool {
    $support = $this->settings()->get('block_gap_support');
    if ($support === NULL) {
      $theme = $this->themeManager->getActiveTheme()->getName();
      throw new \RuntimeException("Theme '$theme' was converted before WordPal recorded block gap support. Reconvert it with wordpal:convert.");
    }
    return $support;
  }

  /**
   * Returns the gap a block's own styles set for a side it leaves out.
   *
   * WordPress resolves one registered style variation from the class
   * attribute, then reads that variation's gap, then the block type's, before
   * the root gap (block-supports/layout.php). Only a variation in the style
   * registry counts, so a theme.json variation that block.json alone declares
   * sets no gap.
   *
   * @param string $blockName
   *   The WordPress block name, such as "core/columns".
   * @param string $cssClass
   *   The block's class attribute, which names a style variation.
   *
   * @return string|array|null
   *   The gap as the theme.json states it, a string or an array with "top"
   *   and "left", or NULL when only the root gap applies.
   */
  public function blockGapOverride(string $blockName, string $cssClass): string|array|null {
    $gaps = $this->settings()->get('block_gaps');
    $registered = $this->settings()->get('registered_variations')[$blockName] ?? [];
    foreach (preg_split('/\s+/', $cssClass, -1, PREG_SPLIT_NO_EMPTY) as $class) {
      $variation = substr($class, strlen('is-style-'));
      if (str_starts_with($class, 'is-style-') && $variation !== 'default' && in_array($variation, $registered, TRUE)) {
        return $gaps["$blockName/$variation"] ?? $gaps[$blockName] ?? NULL;
      }
    }
    return $gaps[$blockName] ?? NULL;
  }

  /**
   * Returns the root block gap as the theme.json states it, or NULL.
   *
   * @return string|array|null
   *   A string, or an array with "top" and "left".
   */
  public function rootBlockGap(): string|array|null {
    return $this->settings()->get('root_block_gap');
  }

  /**
   * Returns whether the theme allows a position type, such as "sticky".
   */
  public function allowsPosition(string $type): bool {
    return $this->settings()->get("position.$type") === TRUE;
  }

  /**
   * Returns the SVG filter of a duotone preset, if the theme has the preset.
   */
  public function duotoneFilter(string $slug): ?string {
    return $this->settings()->get('duotone_filters')[$slug] ?? NULL;
  }

  /**
   * Returns the class of a block style variation the theme styles.
   *
   * WordPress marks each instance of such a variation with its own class.
   * The snapshot names the one class every converted instance carries.
   *
   * @return string|null
   *   The class, or NULL when the theme does not style the variation.
   */
  public function variationClass(string $blockName, string $variation): ?string {
    return $this->settings()->get('styled_variations')["$blockName/$variation"] ?? NULL;
  }

  /**
   * Computes WordPress fluid typography for a custom font size.
   */
  public function fontSize(string $size): string {
    $fluid = $this->settings()->get('typography.fluid');
    $preferred = $this->dimension($size);
    if (!$fluid || $preferred === NULL || $preferred[0] === 0.0) {
      return $size;
    }
    $options = is_array($fluid) ? $fluid : [];
    [$value, $unit] = $preferred;
    $minimumLimit = $this->dimension($options['minFontSize'] ?? '14px', $unit)
      ?? $this->dimension('14px', $unit);
    if ($value <= $minimumLimit[0]) {
      return $size;
    }
    // WordPress takes the wide size as the largest viewport only in a unit
    // it can compute with.
    $wide = $this->settings()->get('layout.wideSize');
    if ($wide === NULL || $this->dimension($wide) === NULL) {
      $wide = '1600px';
    }
    $maximumViewport = $this->dimension($options['maxViewportWidth'] ?? $wide, $unit);
    $minimumViewport = $this->dimension($options['minViewportWidth'] ?? '320px', $unit);
    if ($maximumViewport === NULL || $minimumViewport === NULL || $maximumViewport[0] === $minimumViewport[0]) {
      return $size;
    }
    $pixels = $unit === 'px' ? $value : $value * 16;
    $factor = max(0.25, min(0.75, 1 - 0.075 * log($pixels, 2)));
    $minimum = max($minimumLimit[0], round($value * $factor, 3));
    $minimumRem = round($unit === 'px' ? $minimum / 16 : $minimum, 3);
    $offset = round($minimumViewport[0] / 100, 3);
    $linear = round(100 * ($value - $minimum) / ($maximumViewport[0] - $minimumViewport[0]), 3) ?: 1;
    return "clamp($minimum$unit, {$minimumRem}rem + ((1vw - $offset$unit) * $linear), $value$unit)";
  }

  /**
   * Parses the units WordPress supports for fluid typography.
   */
  private function dimension(string $size, ?string $coerce = NULL): ?array {
    if (is_numeric($size)) {
      $size .= 'px';
    }
    // CSS props and remote theme settings may use non-fluid units or formulas.
    if (!preg_match('/^(\d*\.?\d+)(px|em|rem)$/', $size, $matches)) {
      return NULL;
    }
    $value = (float) $matches[1];
    $unit = $matches[2];
    if ($coerce !== NULL && $coerce !== $unit) {
      $value *= $unit === 'px' ? 1 / 16 : ($coerce === 'px' ? 16 : 1);
      $unit = $coerce;
    }
    return [round($value, 3), $unit];
  }

  /**
   * Returns the settings the active theme's own conversion recorded.
   */
  private function settings(): ImmutableConfig {
    $theme = $this->themeManager->getActiveTheme()->getName();
    $config = $this->configFactory->get(self::configName($theme));
    if ($config->isNew()) {
      // Rendering WordPal components under a theme nothing converted is not
      // supported: there are no settings to render them with.
      throw new \RuntimeException("Theme '$theme' has no WordPal settings. Convert it with wordpal:convert before rendering its components.");
    }
    return $config;
  }

}
