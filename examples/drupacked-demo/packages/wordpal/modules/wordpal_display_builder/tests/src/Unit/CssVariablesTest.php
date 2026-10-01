<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Theme\DesignTokens;
use Drupal\wordpal_display_builder\Theme\CssVariables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Component\Serialization\Yaml;

/**
 * Tests writing theme presets as ui_skins CSS variable definitions.
 */
#[CoversClass(CssVariables::class)]
#[Group('wordpal')]
final class CssVariablesTest extends UnitTestCase {

  /**
   * Presets as the snapshot records them from WordPress.
   */
  private const PRESETS = [
    'color' => [['slug' => 'base', 'name' => 'Base', 'color' => '#f9f9f9']],
    'gradient' => [],
    'font-size' => [['slug' => 'huge', 'name' => 'Huge', 'size' => '48px']],
    'font-family' => [['slug' => 'body', 'name' => 'Body', 'fontFamily' => 'Inter, sans-serif']],
    'spacing' => [['slug' => '10', 'name' => '1', 'size' => '1rem']],
  ];

  /**
   * Builds the DTCG tokens DesignTokens::yaml() writes for self::PRESETS.
   */
  private function tokens(): array {
    return Yaml::decode(DesignTokens::yaml(self::PRESETS));
  }

  /**
   * Tests each variable is named as WordPress prints it.
   */
  public function testNamesEachVariableAsWordPressPrintsIt(): void {
    $variables = Yaml::decode(CssVariables::yaml($this->tokens()));

    self::assertSame(
      [
        'wp--preset--color--base',
        'wp--preset--font-size--huge',
        'wp--preset--font-family--body',
        'wp--preset--spacing--10',
      ],
      array_keys($variables),
    );
  }

  /**
   * Tests each variable defaults to the preset's own value.
   */
  public function testDefaultsEachVariableToThePresetValue(): void {
    $variables = Yaml::decode(CssVariables::yaml($this->tokens()));

    self::assertSame('#f9f9f9', $variables['wp--preset--color--base']['default_values'][':root']);
    self::assertSame('48px', $variables['wp--preset--font-size--huge']['default_values'][':root']);
    self::assertSame('Inter, sans-serif', $variables['wp--preset--font-family--body']['default_values'][':root']);
    self::assertSame('1rem', $variables['wp--preset--spacing--10']['default_values'][':root']);
  }

  /**
   * Tests each variable carries the preset's name as its label.
   */
  public function testLabelsEachVariableWithThePresetName(): void {
    $variables = Yaml::decode(CssVariables::yaml($this->tokens()));

    self::assertSame('Base', $variables['wp--preset--color--base']['label']);
    self::assertSame('WordPress presets', $variables['wp--preset--color--base']['category']);
  }

  /**
   * Tests a multi-word font family name is quoted for a valid CSS value.
   */
  public function testQuotesMultiWordFontFamilyName(): void {
    $presets = ['color' => [], 'gradient' => [], 'font-size' => [], 'spacing' => []];
    $presets['font-family'] = [['slug' => 'heading', 'name' => 'Heading', 'fontFamily' => '"Iowan Old Style", serif']];
    $tokens = Yaml::decode(DesignTokens::yaml($presets));

    $variables = Yaml::decode(CssVariables::yaml($tokens));

    self::assertSame('"Iowan Old Style", serif', $variables['wp--preset--font-family--heading']['default_values'][':root']);
  }

}
