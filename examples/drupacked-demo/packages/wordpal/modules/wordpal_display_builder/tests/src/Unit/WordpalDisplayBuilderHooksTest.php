<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Theme\DesignTokens;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Component\Serialization\Yaml;

/**
 * Tests the hook wordpal_display_builder implements on wordpal_convert.
 */
#[Group('wordpal')]
final class WordpalDisplayBuilderHooksTest extends UnitTestCase {

  /**
   * Loads the hook implementations under test.
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/wordpal_display_builder.module';
  }

  /**
   * Tests the theme-files hook adds the ui_skins CSS variables file.
   */
  public function testThemeFilesAlterAddsCssVariablesFile(): void {
    $presets = [
      'color' => [['slug' => 'base', 'name' => 'Base', 'color' => '#f9f9f9']],
      'gradient' => [],
      'font-size' => [],
      'font-family' => [],
      'spacing' => [],
    ];
    $tokens = DesignTokens::yaml($presets);
    $files = [
      'themes/custom/example/example.info.yml' => "name: Example\n",
      'themes/custom/example/example.tokens.yml' => $tokens,
    ];

    wordpal_display_builder_wordpal_theme_files_alter($files, 'example');

    self::assertArrayHasKey('themes/custom/example/example.ui_skins.css_variables.yml', $files);
    $variables = Yaml::decode($files['themes/custom/example/example.ui_skins.css_variables.yml']);
    self::assertSame('#f9f9f9', $variables['wp--preset--color--base']['default_values'][':root']);
    // The design tokens file itself is left untouched.
    self::assertSame($tokens, $files['themes/custom/example/example.tokens.yml']);
  }

}
