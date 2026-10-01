<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_canvas\QueryRow;
use Drupal\wordpal_convert\Content\ContentMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the row a Canvas Query View renders its cards with.
 */
#[CoversClass(QueryRow::class)]
#[Group('wordpal')]
final class QueryRowTest extends UnitTestCase {

  /**
   * Tests the search row lists posts and pages in the same card view mode.
   */
  public function testSearchViewValuesListsPostsAndPagesInCardViewMode(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'page' => 'wordpal_page',
      'search' => ['path' => '/search', 'parameter' => 'keywords', 'index' => 'content'],
    ]);

    $row = QueryRow::forRoute('search', 'card_1', $mapping);

    self::assertSame([
      'type' => 'search_api',
      'options' => ['view_modes' => ['entity:node' => ['wordpal_post' => 'card_1', 'wordpal_page' => 'card_1']]],
    ], $row);
  }

  /**
   * Tests any other route renders nodes in the card view mode.
   */
  public function testOtherRoutesRenderTheCardViewMode(): void {
    $row = QueryRow::forRoute('archive', 'card_1', new ContentMapping(['post' => 'wordpal_post']));

    self::assertSame(['type' => 'entity:node', 'options' => ['view_mode' => 'card_1']], $row);
  }

}
