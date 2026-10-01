<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\ComponentDefinitionAlter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the runtime-prop overlay wordpal_convert applies at generation.
 */
#[CoversClass(ComponentDefinitionAlter::class)]
#[Group('wordpal')]
final class ComponentDefinitionAlterTest extends UnitTestCase {

  /**
   * Tests that only named runtime props survive from the checked-in file.
   */
  public function testOverlaysOnlyRuntimeProps(): void {
    $generated = [
      'name' => 'Post title',
      'props' => [
        'type' => 'object',
        'required' => ['is_link'],
        'properties' => [
          'is_link' => [
            'title' => 'Is link',
            'type' => 'boolean',
            'examples' => [TRUE],
            'x-wordpal-panel' => 'settings',
          ],
          'text_align' => ['title' => 'Text align', 'type' => 'string', 'x-wordpal-panel' => 'settings'],
          'font_size' => ['title' => 'Font size', 'type' => 'string', 'x-wordpal-panel' => 'typography'],
        ],
      ],
    ];
    $checkedIn = [
      'props' => [
        'type' => 'object',
        'required' => ['title', 'obsolete'],
        'properties' => [
          'title' => ['title' => 'Title', 'type' => 'string', 'examples' => ['A walk through the city']],
          'url' => ['title' => 'URL', 'type' => 'string', 'format' => 'uri-reference'],
          'is_link' => ['title' => 'Old link prop', 'type' => 'string'],
          'obsolete' => ['title' => 'Obsolete', 'type' => 'string'],
        ],
      ],
    ];

    $definition = ComponentDefinitionAlter::overlay($generated, $checkedIn, ['title', 'url']);
    $properties = $definition['props']['properties'];

    self::assertSame(['is_link', 'title'], $definition['props']['required']);
    self::assertSame(['A walk through the city'], $properties['title']['examples']);
    self::assertSame('uri-reference', $properties['url']['format']);
    self::assertSame('boolean', $properties['is_link']['type']);
    self::assertSame([TRUE], $properties['is_link']['examples']);
    self::assertArrayHasKey('font_size', $properties);
    self::assertArrayHasKey('text_align', $properties);
    self::assertArrayNotHasKey('obsolete', $properties);
    // Runtime props lead, with no inspector panel Canvas would read as shape.
    self::assertArrayNotHasKey('x-wordpal-panel', $properties['title']);
    self::assertSame(['title', 'url', 'is_link', 'text_align', 'font_size'], array_keys($properties));
  }

}
