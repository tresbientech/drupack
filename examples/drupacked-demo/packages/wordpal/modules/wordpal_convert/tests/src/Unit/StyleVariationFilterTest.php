<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Site Editor's full style variation test that Playground runs.
 */
#[Group('wordpal')]
final class StyleVariationFilterTest extends UnitTestCase {

  /**
   * Loads the Playground functions under test.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    require_once dirname(__DIR__, 3) . '/src/WordPress/playground/style-variations.php';
  }

  /**
   * Tests which variations count as full ones.
   */
  #[DataProvider('variations')]
  public function testFullVariation(array $variation, bool $full): void {
    self::assertSame($full, wordpal_is_full_variation($variation));
  }

  /**
   * Provides variations shaped like Twenty Twenty-Four and Five's.
   */
  public static function variations(): array {
    $palette = ['palette' => [['slug' => 'base', 'color' => '#fff']]];
    $fonts = ['fontFamilies' => [['slug' => 'body', 'fontFamily' => 'Inter']]];
    $linkColor = ['link' => ['color' => ['text' => 'var(--accent)']]];
    $headingType = ['heading' => ['typography' => ['fontWeight' => '700']]];
    $full = [
      'title' => 'Ember',
      'settings' => ['color' => $palette, 'typography' => $fonts],
      'styles' => ['color' => ['background' => 'var(--base)'], 'elements' => $headingType],
    ];
    $colorsAndBlocks = [
      'settings' => ['color' => $palette],
      'styles' => ['blocks' => ['core/button' => ['border' => ['radius' => '0']]]],
    ];
    $colorPreset = [
      'title' => 'Evening',
      'settings' => ['color' => $palette],
      'styles' => ['color' => ['text' => 'var(--contrast)'], 'elements' => $linkColor],
    ];
    $typographyPreset = [
      'title' => 'Beiruti & Literata',
      'settings' => ['typography' => $fonts],
      'styles' => ['typography' => ['fontFamily' => 'var(--body)'], 'elements' => $headingType],
    ];
    $typographySpacingPreset = [
      'settings' => ['typography' => $fonts, 'spacing' => ['blockGap' => '1rem']],
      'styles' => ['spacing' => ['padding' => ['top' => '0']]],
    ];
    return [
      'full: colors and fonts' => [$full, TRUE],
      'full: colors and block styles beside them' => [$colorsAndBlocks, TRUE],
      'color preset' => [$colorPreset, FALSE],
      'typography preset' => [$typographyPreset, FALSE],
      'typography and spacing preset' => [$typographySpacingPreset, FALSE],
    ];
  }

}
