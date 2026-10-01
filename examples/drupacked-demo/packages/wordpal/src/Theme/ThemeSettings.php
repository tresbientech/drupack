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
    $wide = $this->settings()->get('layout.wideSize') ?? '1600px';
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
