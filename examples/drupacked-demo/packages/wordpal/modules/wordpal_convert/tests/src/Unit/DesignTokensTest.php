<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Theme\DesignTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Component\Serialization\Yaml;

/**
 * Tests writing theme presets as DTCG design tokens.
 */
#[CoversClass(DesignTokens::class)]
#[Group('wordpal')]
final class DesignTokensTest extends UnitTestCase {

  /**
   * Presets as the snapshot records them from WordPress.
   */
  private const PRESETS = [
    'color' => [
      ['slug' => 'base', 'name' => 'Base', 'color' => '#f9f9f9'],
      ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
    ],
    'gradient' => [
      ['slug' => 'dusk', 'name' => 'Dusk', 'gradient' => 'linear-gradient(135deg, #f9f9f9 0%, #111111 100%)'],
    ],
    'font-size' => [
      ['slug' => 'small', 'name' => 'Small', 'size' => '0.9rem', 'fluid' => FALSE],
      ['slug' => 'large', 'name' => 'Large', 'size' => '1.85rem', 'fluid' => ['min' => '1.39rem', 'max' => '1.85rem']],
      ['slug' => 'huge', 'name' => 'Huge', 'size' => '48px'],
    ],
    'font-family' => [
      ['slug' => 'body', 'name' => 'Body', 'fontFamily' => 'Inter, sans-serif'],
      ['slug' => 'heading', 'name' => 'Heading', 'fontFamily' => '"Iowan Old Style", serif'],
    ],
    'spacing' => [
      ['slug' => '10', 'name' => '1', 'size' => '1rem'],
      ['slug' => '20', 'name' => '2', 'size' => 'min(1.5rem, 2vw)'],
    ],
  ];

  /**
   * Tests that each preset becomes a token named by its slug.
   */
  public function testWritesPresetsAsTokens(): void {
    $tokens = Yaml::decode(DesignTokens::yaml(self::PRESETS));

    self::assertSame(['color', 'gradient', 'font-size', 'font-family', 'spacing'], array_keys($tokens));
    self::assertSame(['$type' => 'color', '$value' => '#f9f9f9', '$description' => 'Base'], $tokens['color']['base']);
    self::assertSame('color', $tokens['gradient']['dusk']['$type']);
    self::assertSame('linear-gradient(135deg, #f9f9f9 0%, #111111 100%)', $tokens['gradient']['dusk']['$value']);
    // A fluid font size records the size WordPress scales down from.
    self::assertSame(
      ['$type' => 'dimension', '$value' => ['value' => 1.85, 'unit' => 'rem'], '$description' => 'Large'],
      $tokens['font-size']['large'],
    );
    self::assertSame(['value' => 48, 'unit' => 'px'], $tokens['font-size']['huge']['$value']);
    self::assertSame(['Inter', 'sans-serif'], $tokens['font-family']['body']['$value']);
    self::assertSame(['Iowan Old Style', 'serif'], $tokens['font-family']['heading']['$value']);
    self::assertSame(['value' => 1, 'unit' => 'rem'], $tokens['spacing'][10]['$value']);
    // DTCG has no type for a CSS expression, so the token stays untyped.
    self::assertSame(['$value' => 'min(1.5rem, 2vw)', '$description' => '2'], $tokens['spacing'][20]);
  }

  /**
   * Tests a preset with no name gets an empty description.
   *
   * A theme.json preset's name is optional, and Snapshot::presets() reads
   * such a preset rather than failing.
   */
  public function testUnnamedPresetGetsAnEmptyDescription(): void {
    $presets = ['color' => [], 'gradient' => [], 'font-size' => [], 'spacing' => []];
    $presets['font-family'] = [['slug' => 'heading', 'fontFamily' => 'Frank Ruhl Libre']];
    $tokens = Yaml::decode(DesignTokens::yaml($presets));

    self::assertSame(
      ['$type' => 'fontFamily', '$value' => ['Frank Ruhl Libre'], '$description' => ''],
      $tokens['font-family']['heading'],
    );
  }

  /**
   * Tests that every token of a type core 11.5 reads holds a valid value.
   *
   * Core's Dimension value plugin accepts px and rem, and its FontFamily
   * plugin a name or a list of names. Core has no color plugin.
   */
  public function testTokensOfCoreTypesMatchCoreValueShapes(): void {
    $tokens = Yaml::decode(DesignTokens::yaml(self::PRESETS));

    foreach ($tokens as $group) {
      foreach ($group as $token) {
        $type = $token['$type'] ?? NULL;
        if ($type === 'dimension') {
          self::assertContains($token['$value']['unit'], ['px', 'rem']);
          self::assertTrue(is_int($token['$value']['value']) || is_float($token['$value']['value']));
        }
        elseif ($type === 'fontFamily') {
          self::assertContainsOnlyString($token['$value']);
        }
        else {
          self::assertContains($type, ['color', NULL]);
          self::assertIsString($token['$value']);
        }
      }
    }
  }

}
