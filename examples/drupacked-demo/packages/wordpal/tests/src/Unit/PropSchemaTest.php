<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Support\PropSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the preset and style-engine facts PropSchema exposes.
 */
#[CoversClass(PropSchema::class)]
#[Group('wordpal')]
final class PropSchemaTest extends UnitTestCase {

  /**
   * Tests that a preset prop returns its theme.json group.
   */
  public function testPresetPropReturnsGroup(): void {
    self::assertSame('color', PropSchema::presetGroup('background_color'));
    self::assertSame('color', PropSchema::presetGroup('link_color'));
    self::assertSame('font-size', PropSchema::presetGroup('font_size'));
  }

  /**
   * Tests that a plain, non-preset prop returns NULL.
   */
  public function testPlainPropReturnsNull(): void {
    self::assertNull(PropSchema::presetGroup('content'));
  }

  /**
   * Tests that the font size is excluded from the style engine's values.
   *
   * It stays a style-engine prop: only isStyleEngineValue() excludes it,
   * because WordPress turns its number into a length before the style
   * engine reads it.
   */
  public function testFontSizeIsExcludedFromStyleEngineValues(): void {
    self::assertFalse(PropSchema::isStyleEngineValue('core/post-title', 'font_size'));
    self::assertContains('font_size', PropSchema::styleEngineProps());
    self::assertContains('border_radius', PropSchema::styleEngineProps());
  }

  /**
   * Tests that a style-engine block and prop combination returns TRUE.
   */
  public function testStyleEngineValueIsTrueForKnownBlockAndProp(): void {
    self::assertTrue(PropSchema::isStyleEngineValue('core/post-title', 'background_color'));
  }

  /**
   * Tests which blocks build their wrapper attributes from the style engine.
   */
  public function testStyleEngineBlocks(): void {
    self::assertTrue(PropSchema::isStyleEngineBlock('core/post-title'));
    self::assertFalse(PropSchema::isStyleEngineBlock('core/paragraph'));
  }

}
