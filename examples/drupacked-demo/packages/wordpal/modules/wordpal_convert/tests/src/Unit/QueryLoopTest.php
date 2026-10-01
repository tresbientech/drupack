<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\QueryLoop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests extraction of the WordPress Query boundary.
 */
#[CoversClass(QueryLoop::class)]
#[Group('wordpal')]
final class QueryLoopTest extends UnitTestCase {

  /**
   * Tests that a static home has no query boundary.
   */
  public function testStaticHomeHasNoQuery(): void {
    self::assertSame([], QueryLoop::allFromTree([
      new BlockNode('core/paragraph', [], '', []),
    ], 10)['loops']);
  }

  /**
   * Tests extraction through ordinary layout ancestors.
   */
  public function testExtractsQueryParts(): void {
    $postTemplate = new BlockNode('core/post-template', ['layout' => ['type' => 'grid']], '', [
      new BlockNode('core/post-title', [], '', []),
    ]);
    $pagination = new BlockNode('core/query-pagination', ['paginationArrow' => 'arrow'], '', []);
    $noResults = new BlockNode('core/query-no-results', [], '', []);
    $query = new BlockNode('core/query', ['query' => ['perPage' => 3]], '', [$postTemplate, $pagination, $noResults]);

    $loops = QueryLoop::allFromTree([
      new BlockNode('core/group', [], '', [new BlockNode('core/columns', [], '', [$query])]),
    ], 10)['loops'];

    self::assertCount(1, $loops);
    self::assertSame($query, $loops[0]->query);
    self::assertSame($postTemplate, $loops[0]->postTemplate);
    self::assertSame($pagination, $loops[0]->pagination);
    self::assertSame($noResults, $loops[0]->noResults);
  }

  /**
   * Tests that the Post Template carries the Query's legacy displayLayout.
   */
  public function testPostTemplateReadsDisplayLayoutContext(): void {
    $displayLayout = ['type' => 'flex', 'columns' => 2];
    $query = new BlockNode('core/query', ['displayLayout' => $displayLayout], '', [
      new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])]),
    ]);

    $postTemplate = QueryLoop::allFromTree([$query], 10)['loops'][0]->postTemplate;

    self::assertSame($displayLayout, $postTemplate->attributes['displayLayout']);
    self::assertSame(['core/post-title'], array_column($postTemplate->children, 'name'));
    self::assertSame(2, (new AttributeFlattener())->flatten($postTemplate->attributes, $postTemplate->name)['display_layout_columns']);
    self::assertArrayNotHasKey('display_layout_columns', (new AttributeFlattener())->flatten(['displayLayout' => ['type' => 'list']], 'core/post-template'));
  }

  /**
   * Tests that multiple query boundaries retain source order and settings.
   */
  public function testExtractsMultipleQueries(): void {
    $first = new BlockNode('core/query', [
      'query' => ['perPage' => '1', 'offset' => '0', 'sticky' => 'exclude'],
    ], '', [new BlockNode('core/post-template', [], '', [])]);
    $second = new BlockNode('core/query', [
      'query' => ['perPage' => '2', 'offset' => '1', 'sticky' => ''],
    ], '', [new BlockNode('core/post-template', [], '', [])]);

    $loops = QueryLoop::allFromTree([$first, $second], 10)['loops'];

    self::assertSame([$first, $second], array_column($loops, 'query'));
    self::assertSame([1, 2], array_column($loops, 'perPage'));
    self::assertSame([0, 1], array_column($loops, 'offset'));
    self::assertSame([TRUE, FALSE], array_column($loops, 'excludeSticky'));
  }

  /**
   * Tests that a Query ignoring sticky posts lists them by date.
   */
  public function testIgnoreStickyKeepsStickyPosts(): void {
    $query = new BlockNode('core/query', [
      'query' => ['perPage' => '1', 'offset' => '1', 'sticky' => 'ignore'],
    ], '', [new BlockNode('core/post-template', [], '', [])]);

    $loops = QueryLoop::allFromTree([$query], 10)['loops'];

    self::assertFalse($loops[0]->excludeSticky);
  }

  /**
   * Tests that an inheriting Query takes the main query's posts per page.
   */
  public function testInheritingQueryUsesMainQueryPerPage(): void {
    $query = new BlockNode('core/query', [
      'query' => ['perPage' => 3, 'inherit' => TRUE],
    ], '', [new BlockNode('core/post-template', [], '', [])]);

    self::assertSame(12, QueryLoop::allFromTree([$query], 12)['loops'][0]->perPage);
  }

  /**
   * Tests an inheriting Query ignores its own settings, as WordPress does.
   */
  public function testInheritingQueryIgnoresItsOwnSettings(): void {
    $query = new BlockNode('core/query', [
      'query' => [
        'inherit' => TRUE,
        'pages' => '100',
        'offset' => '1',
        'order' => 'asc',
        'sticky' => 'exclude',
        'search' => 'x',
      ],
    ], '', [new BlockNode('core/post-template', [], '', [])]);

    $loop = QueryLoop::allFromTree([$query], 12)['loops'][0];
    self::assertSame([0, 'DESC', FALSE], [$loop->offset, $loop->order, $loop->excludeSticky]);
  }

  /**
   * Tests a taxonomy with an empty term list filters nothing.
   */
  public function testEmptyTaxonomyFilterIsNoFilter(): void {
    $query = new BlockNode('core/query', [
      'query' => ['perPage' => 3, 'taxQuery' => ['category' => []]],
    ], '', [new BlockNode('core/post-template', [], '', [])]);

    self::assertSame(3, QueryLoop::allFromTree([$query], 12)['loops'][0]->perPage);
  }

  /**
   * Tests a non-inheriting Query with no perPage takes the main query's.
   */
  public function testNonInheritingQueryWithNoPerPageUsesMainQueryPerPage(): void {
    $query = new BlockNode('core/query', ['query' => []], '', [new BlockNode('core/post-template', [], '', [])]);

    self::assertSame(12, QueryLoop::allFromTree([$query], 12)['loops'][0]->perPage);
  }

  /**
   * Tests each non-inheriting Query pages by its own pager element.
   */
  public function testQueriesTakeOwnPagerElements(): void {
    $own = new BlockNode('core/query', ['query' => ['perPage' => 4]], '', [new BlockNode('core/post-template', [], '', [])]);
    $main = new BlockNode('core/query', ['query' => ['inherit' => TRUE]], '', [new BlockNode('core/post-template', [], '', [])]);

    $loops = QueryLoop::allFromTree([$own, $main, $own], 10)['loops'];

    self::assertSame([1, 0, 2], array_column($loops, 'pagerElement'));
  }

  /**
   * Tests a page cap and a title order map to the Query's View.
   */
  public function testReadsPageCapAndTitleOrder(): void {
    $postTemplate = new BlockNode('core/post-template', [], '', []);
    $settings = ['perPage' => 3, 'pages' => '3', 'orderBy' => 'title', 'order' => 'asc'];
    $query = new BlockNode('core/query', ['query' => $settings], '', [$postTemplate]);

    $loop = QueryLoop::allFromTree([$query], 10)['loops'][0];

    self::assertSame(3, $loop->pages);
    self::assertSame('title', $loop->orderBy);
    self::assertSame('ASC', $loop->order);
  }

  /**
   * Tests an unsupported Query leaves its tree alone, and the rest converts.
   */
  public function testUnsupportedQueryIsDroppedAlone(): void {
    $products = new BlockNode('core/query', ['query' => ['perPage' => 3, 'postType' => 'product', 'orderBy' => 'rand']], '', [
      new BlockNode('core/post-template', [], '', []),
    ]);
    $posts = new BlockNode('core/query', ['query' => ['perPage' => 3]], '', [
      new BlockNode('core/post-template', [], '', []),
    ]);
    $heading = new BlockNode('core/heading', [], '', []);

    $tree = QueryLoop::allFromTree([new BlockNode('core/group', [], '', [$heading, $products, $posts])], 10);

    self::assertSame([$posts], array_column($tree['loops'], 'query'));
    self::assertSame(1, $tree['loops'][0]->pagerElement);
    self::assertSame([$heading, $posts], $tree['nodes'][0]->children);
    self::assertSame(['Query 1 left out: A Query uses unsupported settings: postType product, orderBy rand.'], $tree['dropped']);
  }

  /**
   * Tests each ancestor of a left-out Query records its rendered HTML.
   */
  public function testDroppedQueryHtmlIsCutFromItsAncestors(): void {
    $products = new BlockNode('core/query', ['query' => ['perPage' => 3, 'postType' => 'product']], '', [
      new BlockNode('core/post-template', [], '', []),
    ], '<ul class="products"></ul>');
    $inner = new BlockNode('core/group', [], '', [$products], '<div class="inner"><ul class="products"></ul></div>');

    $tree = QueryLoop::allFromTree([new BlockNode('acme/tabs', [], '', [$inner], '<div class="acme-tabs"><div class="inner"><ul class="products"></ul></div></div>')], 10);

    self::assertSame(['<ul class="products"></ul>'], $tree['nodes'][0]->cut);
    self::assertSame(['<ul class="products"></ul>'], $tree['nodes'][0]->children[0]->cut);
  }

  /**
   * Tests a Query with no Post Template leaves its tree alone.
   */
  public function testQueryWithoutPostTemplateIsDropped(): void {
    $tree = QueryLoop::allFromTree([new BlockNode('core/query', ['query' => ['perPage' => 3]], '', [])], 10);

    self::assertSame([], $tree['nodes']);
    self::assertSame(['Query 1 left out: A Query must contain one Post Template block.'], $tree['dropped']);
  }

  /**
   * Tests each Pagination block of a Query renders its one pager.
   */
  public function testQueryWithTwoPaginationBlocksConverts(): void {
    $first = new BlockNode('core/query-pagination', [], '', []);
    $query = new BlockNode('core/query', ['query' => ['perPage' => 3]], '', [
      $first,
      new BlockNode('core/post-template', [], '', []),
      new BlockNode('core/query-pagination', [], '', []),
    ]);

    $tree = QueryLoop::allFromTree([$query], 10);

    self::assertSame([], $tree['dropped']);
    self::assertSame($first, $tree['loops'][0]->pagination);
  }

}
