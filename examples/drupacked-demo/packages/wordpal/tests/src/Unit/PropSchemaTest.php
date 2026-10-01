<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Support\PresetShape;
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
   * Tests a prop that stores a bare preset slug.
   */
  public function testSlugPresetReturnsGroupAndShape(): void {
    $preset = PropSchema::preset('background_color');
    self::assertNotNull($preset);
    self::assertSame('color', $preset->group);
    self::assertSame(PresetShape::Slug, $preset->shape);
  }

  /**
   * Tests a prop that stores a `var:preset|<group>|<slug>` reference.
   */
  public function testReferencePresetReturnsShape(): void {
    $preset = PropSchema::preset('link_color');
    self::assertNotNull($preset);
    self::assertSame('color', $preset->group);
    self::assertSame(PresetShape::Reference, $preset->shape);
  }

  /**
   * Tests that a plain, non-preset prop returns NULL.
   */
  public function testPlainPropReturnsNull(): void {
    self::assertNull(PropSchema::preset('content'));
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
