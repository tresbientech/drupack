<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests fluid typography against WordPress reference renders.
 */
#[CoversClass(ThemeSettings::class)]
#[Group('wordpal')]
final class ThemeSettingsTest extends UnitTestCase {

  /**
   * Tests the custom font sizes used in Twenty Twenty-Four patterns.
   */
  public function testWordPressFluidSizes(): void {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/fluid-typography.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    $settings = $this->settings($fixture['settings']);
    foreach ($fixture['sizes'] as $size => $expected) {
      self::assertSame($expected, $settings->fontSize($size), $size);
    }
  }

  /**
   * Tests values WordPress leaves outside fluid typography.
   */
  public function testNonFluidSizes(): void {
    $settings = $this->settings(['typography' => ['fluid' => TRUE]]);
    foreach (['0', '0px', '12px', '0.75rem', 'var(--wp--preset--font-size--large)', 'clamp(1rem, 2vw, 3rem)', '120%'] as $size) {
      self::assertSame($size, $settings->fontSize($size));
    }
    $disabled = $this->settings(['typography' => ['fluid' => FALSE]]);
    self::assertSame('12rem', $disabled->fontSize('12rem'));
  }

  /**
   * Tests that a wide size without a supported unit leaves the 1600px default.
   */
  public function testWideSizeInAnUnsupportedUnit(): void {
    $default = $this->settings(['typography' => ['fluid' => TRUE]])->fontSize('3rem');
    self::assertStringStartsWith('clamp(', $default);
    foreach (['90vw', '80%', 'calc(100vw - 2rem)', 'var(--wp--custom--wide)'] as $wide) {
      $settings = $this->settings(['typography' => ['fluid' => TRUE], 'layout' => ['wideSize' => $wide]]);
      self::assertSame($default, $settings->fontSize('3rem'), $wide);
    }
  }

  /**
   * Tests that settings converted before block gap support was recorded fail.
   */
  public function testMissingBlockGapSupportFailsLoudly(): void {
    $settings = $this->settings(['typography' => ['fluid' => FALSE]]);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Reconvert it with wordpal:convert');
    $settings->hasBlockGapSupport();
  }

  /**
   * Tests that a recorded FALSE still reads as no support.
   */
  public function testRecordedBlockGapSupport(): void {
    self::assertFalse($this->settings(['block_gap_support' => FALSE])->hasBlockGapSupport());
    self::assertTrue($this->settings(['block_gap_support' => TRUE])->hasBlockGapSupport());
  }

  /**
   * Builds settings for the active theme from fixture data.
   */
  private function settings(array $data): ThemeSettings {
    $factory = $this->getConfigFactoryStub([
      ThemeSettings::configName('twentytwentyfour') => $data,
    ]);
    $activeTheme = $this->createMock(ActiveTheme::class);
    $activeTheme->method('getName')->willReturn('twentytwentyfour');
    $themeManager = $this->createMock(ThemeManagerInterface::class);
    $themeManager->method('getActiveTheme')->willReturn($activeTheme);
    return new ThemeSettings($factory, $themeManager);
  }

}
