<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Traits;

use Drupal\wordpal\Theme\ThemeSettings;

/**
 * Writes the WordPal settings a conversion would leave for the active theme.
 */
trait ThemeSettingsTrait {

  /**
   * Writes theme settings for the themes a kernel test renders under.
   *
   * A kernel test renders under "core" until a theme is selected, then under
   * "stark". Settings default to a theme that turns block gap support on.
   *
   * @param array $settings
   *   The settings to write.
   */
  protected function writeThemeSettings(array $settings = ['block_gap_support' => TRUE]): void {
    foreach (['core', 'stark'] as $theme) {
      $this->config(ThemeSettings::configName($theme))->setData($settings)->save();
    }
  }

}
