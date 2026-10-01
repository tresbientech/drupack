<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_display_builder\RuntimeBindings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the runtime bindings that need no field definition.
 */
#[CoversClass(RuntimeBindings::class)]
#[Group('wordpal')]
final class RuntimeBindingsTest extends UnitTestCase {

  /**
   * Tests the Modified Date binding binds the changed field.
   */
  public function testPostDateModifiedBindingBindsChanged(): void {
    $bindings = new RuntimeBindings($this->createMock(EntityFieldManagerInterface::class), $this->createMock(EntityTypeManagerInterface::class));
    $mapping = new ContentMapping(['post' => 'blog']);
    $modified = new BlockNode('core/post-date', [
      'metadata' => [
        'bindings' => [
          'datetime' => ['source' => 'core/post-data', 'args' => ['field' => 'modified']],
        ],
      ],
    ], '', []);

    $props = $bindings->props($modified, 'blog', $mapping);
    self::assertStringContainsString('node:changed', json_encode($props['timestamp']));
    self::assertArrayHasKey('published_timestamp', $props);

    $published = $bindings->props(new BlockNode('core/post-date', [], '', []), 'blog', $mapping);
    self::assertStringContainsString('node:created', json_encode($published['timestamp']));
    self::assertArrayNotHasKey('published_timestamp', $published);
  }

  /**
   * Tests the Author block binds the mapped biography when it shows one.
   */
  public function testPostAuthorBindsBiographyThroughTheAuthor(): void {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getType')->willReturn('text_long');
    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->method('getFieldDefinitions')->with('user', 'user')->willReturn(['field_bio' => $definition]);
    $bindings = new RuntimeBindings($fieldManager, $this->createMock(EntityTypeManagerInterface::class));
    $mapping = new ContentMapping(['post' => 'blog', 'author_biography' => 'field_bio']);

    $shown = $bindings->props(new BlockNode('core/post-author', ['showBio' => TRUE], '', []), 'blog', $mapping);
    self::assertStringContainsString('field_property:user:field_bio:processed', json_encode($shown['author_bio']));
    self::assertArrayNotHasKey('author_bio', $bindings->props(new BlockNode('core/post-author', [], '', []), 'blog', $mapping));
  }

}
