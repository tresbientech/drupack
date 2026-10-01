<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\TreeConcepts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the content concepts and dropped options a block tree carries.
 */
#[CoversClass(TreeConcepts::class)]
#[Group('wordpal')]
final class TreeConceptsTest extends UnitTestCase {

  /**
   * Returns a block node with default attributes and inner HTML.
   */
  private static function node(string $name, array $attributes = [], array $children = []): BlockNode {
    return new BlockNode($name, $attributes, '', $children);
  }

  /**
   * Tests concept counts and the required list across a nested tree.
   */
  public function testCountsConceptsAcrossTheTree(): void {
    $tree = [
      self::node('core/group', [], [
        self::node('core/post-title'),
        self::node('core/post-terms', ['term' => 'post_tag']),
      ]),
      self::node('core/latest-posts'),
    ];
    self::assertSame(['post' => 2, 'tag' => 1], TreeConcepts::counts($tree));
    self::assertSame(['post', 'tag'], TreeConcepts::required($tree));
  }

  /**
   * Tests a navigation block with its own links binds no mapped menu.
   */
  public function testNavigationWithOwnLinksBindsNoMenu(): void {
    $own = self::node('core/navigation', [], [self::node('core/navigation-link', ['label' => 'Home', 'url' => '/'])]);
    self::assertNull(TreeConcepts::concept($own));
    self::assertSame('navigation', TreeConcepts::concept(self::node('core/navigation', ['ref' => 4])));
  }

  /**
   * Tests a Categories block binds the concept of its taxonomy.
   */
  public function testCategoriesBindsItsTaxonomyConcept(): void {
    self::assertSame('category', TreeConcepts::concept(self::node('core/categories')));
    self::assertSame('tag', TreeConcepts::concept(self::node('core/categories', ['taxonomy' => 'post_tag'])));
    $this->expectException(\UnexpectedValueException::class);
    TreeConcepts::concept(self::node('core/categories', ['taxonomy' => 'product_cat']));
  }

  /**
   * Tests requiresPostContent finds a post body nested under other blocks.
   */
  public function testRequiresPostContentFindsNestedBody(): void {
    self::assertTrue(TreeConcepts::requiresPostContent([self::node('core/group', [], [self::node('core/post-content')])]));
    self::assertFalse(TreeConcepts::requiresPostContent([self::node('core/paragraph')]));
  }

  /**
   * Tests dropped options are named once per affected block.
   */
  public function testDroppedOptionsNamesFullPostContent(): void {
    $tree = [
      self::node('core/latest-posts', ['displayPostContent' => TRUE, 'displayPostContentRadio' => 'full_post']),
      self::node('core/archives', ['displayAsDropdown' => TRUE]),
    ];
    self::assertSame([
      'core/latest-posts: full post content, rendered as the excerpt',
    ], TreeConcepts::droppedOptions($tree));
  }

}
