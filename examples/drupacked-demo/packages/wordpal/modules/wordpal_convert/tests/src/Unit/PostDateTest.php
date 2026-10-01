<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\PostDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which date a Post Date block shows.
 */
#[CoversClass(PostDate::class)]
#[Group('wordpal')]
final class PostDateTest extends UnitTestCase {

  /**
   * Tests the Modified Date binding and the legacy attribute.
   */
  public function testIsModified(): void {
    $binding = static fn (string $field): array => [
      'metadata' => [
        'bindings' => [
          'datetime' => ['source' => 'core/post-data', 'args' => ['field' => $field]],
        ],
      ],
    ];
    self::assertTrue(PostDate::isModified(new BlockNode('core/post-date', $binding('modified'), '', [])));
    self::assertFalse(PostDate::isModified(new BlockNode('core/post-date', $binding('date'), '', [])));
    self::assertTrue(PostDate::isModified(new BlockNode('core/post-date', ['displayType' => 'modified'], '', [])));
    self::assertFalse(PostDate::isModified(new BlockNode('core/post-date', ['displayType' => 'modified'] + $binding('date'), '', [])));
    self::assertFalse(PostDate::isModified(new BlockNode('core/post-date', [], '', [])));
  }

}
