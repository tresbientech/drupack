<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Support\PresetValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the CSS values PresetValue::css accepts and rejects.
 */
#[CoversClass(PresetValue::class)]
#[Group('wordpal')]
final class PresetValueTest extends UnitTestCase {

  /**
   * Tests that every value WordPress really stores passes through.
   */
  #[DataProvider('validValues')]
  public function testAcceptsRealWordPressValues(string $value, string $expected): void {
    self::assertSame($expected, PresetValue::css($value));
  }

  /**
   * WordPress values, and the CSS PresetValue::css must turn them into.
   */
  public static function validValues(): array {
    return [
      'preset reference' => ['var:preset|spacing|50', 'var(--wp--preset--spacing--50)'],
      'preset with a hyphenated slug' => [
        'var:preset|color|vivid-cyan-blue',
        'var(--wp--preset--color--vivid-cyan-blue)',
      ],
      'preset with a numbered slug' => ['var:preset|color|contrast-2', 'var(--wp--preset--color--contrast-2)'],
      'preset with a capitalized slug' => ['var:preset|color|Background', 'var(--wp--preset--color--background)'],
      'preset with a camel-case slug' => ['var:preset|color|heavenlyBlue', 'var(--wp--preset--color--heavenly-blue)'],
      'unnested custom property reference' => ['var:custom|xl-spacing', 'var(--wp--custom--xl-spacing)'],
      'nested custom property reference' => ['var:custom|link|color', 'var(--wp--custom--link--color)'],
      'pixel length' => ['2rem', '2rem'],
      'unitless zero' => ['0', '0'],
      'percentage' => ['50%', '50%'],
      'viewport unit' => ['1.5vw', '1.5vw'],
      'bare number' => ['700', '700'],
      'decimal number' => ['1.75', '1.75'],
      'hex color, short' => ['#fff', '#fff'],
      'hex color, with alpha' => ['#ffffff80', '#ffffff80'],
      'rgb color' => ['rgb(0, 0, 0)', 'rgb(0, 0, 0)'],
      'rgba color' => ['rgba(0, 0, 0, 0.5)', 'rgba(0, 0, 0, 0.5)'],
      'hsl color' => ['hsl(200, 50%, 50%)', 'hsl(200, 50%, 50%)'],
      'keyword' => ['italic', 'italic'],
      'multi-word keyword' => ['underline line-through', 'underline line-through'],
      'camel-case keyword' => ['currentColor', 'currentColor'],
      'calc expression' => ['calc(100% - 2rem)', 'calc(100% - 2rem)'],
      'clamp expression' => ['clamp(1rem, 2vw, 3rem)', 'clamp(1rem, 2vw, 3rem)'],
      'min expression' => ['min(10px, 5%)', 'min(10px, 5%)'],
      'max expression' => ['max(10px, 5%)', 'max(10px, 5%)'],
      'calc nesting a preset custom property' => [
        'calc(1rem + var(--wp--preset--spacing--20))',
        'calc(1rem + var(--wp--preset--spacing--20))',
      ],
      'custom property reference' => ['var(--wp--custom--link--color)', 'var(--wp--custom--link--color)'],
      'hsla color' => ['hsla(200, 50%, 50%, 0.5)', 'hsla(200, 50%, 50%, 0.5)'],
      'linear gradient' => [
        'linear-gradient(135deg, #fff 0%, #000 100%)',
        'linear-gradient(135deg, #fff 0%, #000 100%)',
      ],
      'grouped calc terms' => ['calc((100% - 2rem) * 0.5)', 'calc((100% - 2rem) * 0.5)'],
    ];
  }

  /**
   * Tests that a value holding a structural character throws.
   */
  #[DataProvider('unsafeValues')]
  public function testRejectsBreakoutCharacters(string $value): void {
    $this->expectException(\UnexpectedValueException::class);
    PresetValue::css($value);
  }

  /**
   * Values that must never reach an inline style or a page <style> element.
   */
  public static function unsafeValues(): array {
    return [
      'style close tag' => ['red</style><script>alert(1)</script>'],
      'angle bracket' => ['1px solid <img onerror=alert(1)>'],
      'declaration close' => ['red; background:url(x)'],
      'rule open' => ['red}body{background:red'],
      'rule open, other side' => ['red{background:red}'],
      'comment open, otherwise valid characters' => ['calc(1px/*x*/+2px)'],
      'backslash' => ['red\\27 '],
      'double quote' => ['"onmouseover="alert(1)'],
      'single quote' => ["red'; background: url('x')"],
      'empty string' => [''],
      'malformed preset, missing slug' => ['var:preset|spacing'],
      'malformed custom, missing name' => ['var:custom'],
      'malformed custom, empty name' => ['var:custom|'],
      'reference outside preset and custom' => ['var:other|link|color'],
      'custom name with a breakout character' => ['var:custom|x);background:red'],
      'url function' => ['url(//evil.example/x.png)'],
      'image-set function' => ['image-set(x 1x)'],
      'url nested in calc' => ['calc(url(x))'],
      'unknown function' => ['attr(data-x)'],
      'uppercase url function' => ['URL(x)'],
      'custom name WordPress would kebab-case' => ['var:custom|lineHeight'],
      'custom name with a trailing newline' => ["var:custom|x\n"],
      'value with a trailing newline' => ["2rem\n"],
    ];
  }

  /**
   * Tests that a slug with a trailing newline is not a slug.
   */
  public function testSlugRejectsTrailingNewline(): void {
    self::assertTrue(PresetValue::isSlug('red'));
    self::assertFalse(PresetValue::isSlug("red\n"));
  }

  /**
   * Tests slugs print as WordPress's _wp_to_kebab_case() prints them.
   */
  public function testSlugMatchesWordPressKebabCase(): void {
    self::assertSame('background', PresetValue::slug('Background'));
    self::assertSame('contrast-2', PresetValue::slug('contrast2'));
    self::assertSame('heavenly-blue', PresetValue::slug('heavenlyBlue'));
    self::assertSame('vivid-cyan-blue', PresetValue::slug('vivid-cyan-blue'));
    self::assertSame('x-large', PresetValue::slug('x-large'));
    self::assertSame('2-xl', PresetValue::slug('2xl'));
  }

  /**
   * Tests that a layout gap prints as WordPress's layout support prints it.
   */
  public function testLayoutGapResolvesOnlySpacingPresets(): void {
    self::assertSame('var(--wp--preset--spacing--50)', PresetValue::layoutGap('var:preset|spacing|50'));
    self::assertSame('var:custom|xl-spacing', PresetValue::layoutGap('var:custom|xl-spacing'));
    self::assertSame('1.5rem', PresetValue::layoutGap('1.5rem'));
    $this->expectException(\UnexpectedValueException::class);
    PresetValue::layoutGap('var:custom|x);background:red');
  }

  /**
   * Tests a gradient reference yields its kebab-cased slug.
   */
  public function testGradientSlug(): void {
    self::assertSame('vivid-cyan-blue', PresetValue::gradientSlug('var:preset|gradient|vividCyanBlue'));
  }

  /**
   * Tests a value that is not a gradient preset reference fails.
   */
  #[DataProvider('invalidGradients')]
  public function testGradientSlugRejects(string $value): void {
    $this->expectException(\UnexpectedValueException::class);
    PresetValue::gradientSlug($value);
  }

  /**
   * Returns values a gradient prop must not hold.
   */
  public static function invalidGradients(): array {
    return [
      'bare slug' => ['primary-fade-1'],
      'other group' => ['var:preset|color|primary'],
      'extra segment' => ['var:preset|gradient|a|b'],
      'empty slug' => ['var:preset|gradient|'],
    ];
  }

}
