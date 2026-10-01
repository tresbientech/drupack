<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Theme\ViewTags;
use Drupal\wordpal_convert\Listing\ListingViewConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the View configuration a listing block's Conversion saves.
 */
#[CoversClass(ListingViewConfig::class)]
#[Group('wordpal')]
final class ListingViewConfigTest extends UnitTestCase {

  /**
   * Tests latest posts lists published posts of the bundle, newest first.
   */
  public function testLatestPostsViewListsPublishedPostsNewestFirst(): void {
    $values = ListingViewConfig::latestPosts('wordpal_t_latest_1a2b', 'Latest posts', 'blog', 5, 'created', 'desc');
    self::assertSame(ViewTags::LISTING, $values['tag']);
    $options = $values['display']['default']['display_options'];
    self::assertSame('1', $options['filters']['status']['value']);
    self::assertSame(['blog' => 'blog'], $options['filters']['type']['value']);
    self::assertSame('desc', $options['sorts']['created']['order']);
    self::assertSame(5, $options['pager']['options']['items_per_page']);
    self::assertSame('some', $options['pager']['type']);
    self::assertSame('block', $values['display']['block_1']['display_plugin']);
  }

  /**
   * Tests page list lists published pages of the bundle, titles ascending.
   */
  public function testPageListViewListsPublishedPagesByTitle(): void {
    $values = ListingViewConfig::pageList('wordpal_t_page_1a2b', 'Page list', 'page');
    self::assertSame(ViewTags::LISTING, $values['tag']);
    $options = $values['display']['default']['display_options'];
    self::assertSame('1', $options['filters']['status']['value']);
    self::assertSame(['page' => 'page'], $options['filters']['type']['value']);
    self::assertSame('title', $options['sorts']['title']['field']);
    self::assertSame('ASC', $options['sorts']['title']['order']);
    self::assertSame('none', $options['pager']['type']);
    self::assertSame('block', $values['display']['block_1']['display_plugin']);
  }

  /**
   * Tests latest comments lists published comments of the field, newest first.
   */
  public function testLatestCommentsViewListsPublishedCommentsNewestFirst(): void {
    $values = ListingViewConfig::latestComments('wordpal_t_comments_1a2b', 'Latest comments', 'field_comments', 5);
    self::assertSame(ViewTags::LISTING, $values['tag']);
    self::assertSame('comment_field_data', $values['base_table']);
    $options = $values['display']['default']['display_options'];
    self::assertSame('1', $options['filters']['status']['value']);
    self::assertSame('node', $options['filters']['entity_type']['value']);
    self::assertSame('field_comments', $options['filters']['field_name']['value']);
    self::assertSame('node', $options['relationships']['node']['field']);
    self::assertSame('1', $options['filters']['status_node']['value']);
    self::assertSame('node', $options['filters']['status_node']['relationship']);
    self::assertSame('DESC', $options['sorts']['created']['order']);
    self::assertSame('DESC', $options['sorts']['cid']['order']);
    self::assertSame(5, $options['pager']['options']['items_per_page']);
    self::assertSame('some', $options['pager']['type']);
    self::assertSame('block', $values['display']['block_1']['display_plugin']);
  }

  /**
   * Tests forKind() dispatches to each kind's own builder.
   */
  public function testForKindDispatchesByKind(): void {
    $settings = ['bundle' => 'blog', 'posts_to_show' => 5, 'order' => 'desc', 'order_by' => 'date'];
    $values = ListingViewConfig::forKind('latest_posts', 'wordpal_t_latest_1a2b', $settings);
    self::assertSame('node_field_data', $values['base_table']);
    self::assertSame(['blog' => 'blog'], $values['display']['default']['display_options']['filters']['type']['value']);
  }

  /**
   * Tests viewId() names the same id for the same kind and settings.
   *
   * The View id hash is what lets a Canvas and a Display Builder conversion
   * of one theme share one listing View.
   */
  public function testViewIdIsStableForEqualSettings(): void {
    $settings = ['bundle' => 'blog', 'posts_to_show' => 5, 'order' => 'desc'];
    $first = ListingViewConfig::viewId('mytheme', 'latest_posts', $settings);
    $second = ListingViewConfig::viewId('mytheme', 'latest_posts', $settings);
    self::assertSame($first, $second);
    $different = ListingViewConfig::viewId('mytheme', 'latest_posts', [
      'bundle' => 'blog',
      'posts_to_show' => 10,
      'order' => 'desc',
    ]);
    self::assertNotSame($first, $different);
  }

}
