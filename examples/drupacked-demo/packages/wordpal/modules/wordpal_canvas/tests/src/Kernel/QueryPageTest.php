<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\Theme\ResolvedTemplate;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_canvas\CanvasWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generated query View and Canvas front page.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class QueryPageTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc} */
  protected static $modules = [
    'wordpal', 'wordpal_convert', 'wordpal_canvas', 'wordpal_canvas_runtime', 'system', 'user', 'field', 'node', 'text',
    'file', 'image', 'views', 'path', 'path_alias', 'block', 'comment',
  ];

  /**
   * Creates a mapped node bundle and Page storage.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'system', 'user', 'views']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', [
      'wordpal:group',
      'wordpal:query',
      'wordpal:post-template',
      'wordpal:post-title',
      'wordpal:query-pagination',
      'wordpal:query-pagination-previous',
      'wordpal:query-pagination-numbers',
      'wordpal:query-pagination-next',
      'wordpal:query-no-results',
      'wordpal:frozen-html',
    ]);
  }

  /**
   * Tests a block with no component in a Query layout freezes there.
   */
  public function testFreezesBlockInQueryLayout(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => NULL,
      'tag' => NULL,
      'featured_image' => NULL,
    ]);
    $writer = $this->container->get(CanvasWriter::class);
    $html = '<p class="acme-count">3 posts</p>';
    $nodes = [
      new BlockNode('core/query', [
        'query' => ['perPage' => 3, 'offset' => 0, 'postType' => 'post', 'order' => 'desc', 'orderBy' => 'date'],
      ], '', [
        new BlockNode('acme/count', [], '', [], $html),
        new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])]),
      ]),
    ];
    $loops = QueryLoop::allFromTree($nodes, 10)['loops'];

    self::assertSame(['acme/count'], array_column($writer->frozenNodes($nodes, $mapping), 'name'));
    $result = $writer->writeQueryPattern('frozen_query_fixture', 'Frozen query fixture', $nodes, $loops, $mapping, 'fixture');

    $layout = $result['queries'][0]['view']->getThirdPartySetting('wordpal', 'query')['layout'];
    self::assertSame([
      'component' => 'wordpal:frozen-html',
      'props' => ['block_name' => 'acme/count', 'key' => FrozenBlock::key($html)],
      'children' => [],
    ], $layout[0]);
    self::assertSame(['marker' => 'rows'], $layout[1]);
  }

  /**
   * Tests a Query in the single Template becomes a Views block there.
   */
  public function testWritesQueryInContentTemplate(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => NULL,
      'tag' => NULL,
      'featured_image' => NULL,
    ]);
    $writer = $this->container->get(CanvasWriter::class);
    $partSet = new PartSet([['type' => 'marker']]);
    $writer->writePageVariant($partSet, 'fixture', $mapping, $partSet->loops(10, 1, NULL), NULL, []);
    $nodes = [
      new BlockNode('core/post-title', [], '', []),
      new BlockNode('core/query', [
        'query' => ['perPage' => 4, 'offset' => 0, 'postType' => 'post', 'order' => 'desc', 'orderBy' => 'date'],
      ], '', [
        new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', ['isLink' => TRUE], '', [])]),
      ]),
    ];
    $loops = QueryLoop::allFromTree($nodes, 10)['loops'];

    $result = $writer->writeQueryContentTemplate('wordpal_post', 'single', new ResolvedTemplate($nodes, $partSet, $loops, []), $mapping, 'fixture');

    $outputIds = $writer->queryOutputIds('fixture', $loops[0], 'wordpal_post');
    self::assertSame('node.wordpal_post.full', $result['template']->id());
    $ids = array_column($result['template']->getComponentTree()->getValue(), 'component_id');
    self::assertSame(['sdc.wordpal.post-title', $outputIds['component']], $ids);
    self::assertSame(4, $result['queries'][0]['view']->get('display')['default']['display_options']['pager']['options']['items_per_page']);
  }

  /**
   * Tests View configuration, paging, empty results, and Page placement.
   */
  public function testWritesQueryPage(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => NULL,
      'tag' => NULL,
      'featured_image' => NULL,
    ]);
    $writer = $this->container->get(CanvasWriter::class);
    $partSet = new PartSet([['type' => 'marker']]);
    $variant = $writer->writePageVariant($partSet, 'fixture', $mapping, $partSet->loops(10, 1, NULL), NULL, [])['variant'];
    $home = $this->home();
    $loops = QueryLoop::allFromTree($home, 10)['loops'];
    $outputIds = $writer->queryOutputIds('fixture', $loops[0], 'wordpal_post');
    $result = $writer->writeQueryPage('home', 'wordpal_post', new ResolvedTemplate($home, $partSet, $loops, []), $mapping, 'fixture');
    $first = $result['queries'][0];

    self::assertSame('node.' . $outputIds['view_mode'], $first['view_mode']->id());
    self::assertTrue($first['template']->status());
    self::assertSame($outputIds['view_mode'], $first['template']->get('content_entity_type_view_mode'));
    $display = $first['view']->get('display');
    self::assertSame(3, $display['default']['display_options']['pager']['options']['items_per_page']);
    self::assertSame($outputIds['view_mode'], $display['default']['display_options']['row']['options']['view_mode']);
    self::assertSame(['wordpal_post' => 'wordpal_post'], $display['default']['display_options']['filters']['type']['value']);
    self::assertSame('DESC', $display['default']['display_options']['sorts']['created']['order']);
    self::assertContains('wordpal', $first['view']->getDependencies()['module']);
    self::assertNotContains('wordpal_canvas', $first['view']->getDependencies()['module']);
    self::assertContains('canvas.pattern.' . $outputIds['empty_pattern'], $first['view']->getDependencies()['config']);
    self::assertFalse(Pattern::load($outputIds['empty_pattern'])->status());

    $components = $result['page']->get('components')->getValue();
    $ids = array_column($components, 'component_id');
    self::assertCount(2, array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'block.views_block.')));
    self::assertFalse((bool) array_filter($ids, static fn (string $id): bool => str_contains($id, 'query-pagination')));
    self::assertSame($variant->id(), $result['page']->get('page_variant')->value);

    foreach (range(1, 4) as $index) {
      Node::create(['type' => 'wordpal_post', 'title' => "Post $index", 'status' => 1, 'created' => $index])->save();
    }
    $firstPage = Views::getView($first['view']->id());
    $firstPage->setDisplay('block_1');
    $firstPage->execute();
    self::assertCount(3, $firstPage->result);
    $secondPage = Views::getView($first['view']->id());
    $secondPage->setDisplay('block_1');
    $secondPage->setCurrentPage(1);
    $secondPage->execute();
    self::assertCount(1, $secondPage->result);

    foreach (Node::loadMultiple() as $node) {
      $node->setUnpublished()->save();
    }
    $empty = Views::getView($first['view']->id());
    $empty->setDisplay('block_1');
    $empty->execute();
    self::assertCount(0, $empty->result);
    self::assertSame(
      $outputIds['empty_pattern'],
      $display['default']['display_options']['empty']['wordpal_pattern']['pattern_id'],
    );

    self::assertCount(2, $result['queries']);
    $second = $result['queries'][1];
    $secondDisplay = $second['view']->get('display')['default']['display_options'];
    self::assertSame(1, $secondDisplay['pager']['options']['offset']);
    self::assertSame('0', $secondDisplay['filters']['sticky']['value']);
    // Both Query blocks in home() render the same Post Template card, so
    // their view mode and template are shared even though their View isn't:
    // the second Query's paging, offset, and sticky filter differ.
    self::assertNotSame($first['view']->id(), $second['view']->id());
    self::assertSame($first['view_mode']->id(), $second['view_mode']->id());
    self::assertSame($first['template']->id(), $second['template']->id());
  }

  /**
   * Tests a Query card tree the writer cannot build saves no query output.
   *
   * An unsupported prop value inside the card is caught by
   * addNodes()/inputs(). writeQueryPage()
   * builds and validates every tree before it saves anything, so no View,
   * view mode or card ContentTemplate exists afterward.
   */
  public function testUnsupportedCardPropWritesNothing(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => NULL,
      'tag' => NULL,
      'featured_image' => NULL,
    ]);
    $writer = $this->container->get(CanvasWriter::class);
    $partSet = new PartSet([['type' => 'marker']]);
    $writer->writePageVariant($partSet, 'fixture', $mapping, $partSet->loops(10, 1, NULL), NULL, []);
    $postTemplate = new BlockNode('core/post-template', [], '', [
      new BlockNode('core/post-title', ['align' => 'unsupported'], '', []),
    ]);
    $query = new BlockNode('core/query', [
      'query' => [
        'perPage' => 3,
        'offset' => 0,
        'postType' => 'post',
        'order' => 'desc',
        'orderBy' => 'date',
      ],
    ], '', [$postTemplate]);
    $home = [new BlockNode('core/group', ['tagName' => 'main'], '', [$query])];
    $loops = QueryLoop::allFromTree($home, 10)['loops'];

    try {
      $writer->writeQueryPage('home', 'wordpal_post', new ResolvedTemplate($home, $partSet, $loops, []), $mapping, 'fixture');
      self::fail('Expected writeQueryPage() to throw on a card prop violation.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertStringContainsString('align="unsupported"', $exception->getMessage());
    }

    $ids = $writer->queryOutputIds('fixture', $loops[0], 'wordpal_post');
    self::assertNull(View::load($ids['view']));
    self::assertNull(EntityViewMode::load('node.' . $ids['view_mode']));
    self::assertNull(ContentTemplate::load('node.wordpal_post.' . $ids['view_mode']));
    self::assertSame([], $this->container->get('entity_type.manager')->getStorage('canvas_page')->loadMultiple());
  }

  /**
   * Tests two patterns with the same card tree share a view mode.
   *
   * View mode and template ids hash the card tree, never the owning
   * Pattern. Two patterns whose Query blocks render an identical card get
   * the same view mode and template id, and the second write reuses what
   * the first already saved instead of colliding with it.
   */
  public function testTwoPatternsShareCardTree(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => NULL,
      'tag' => NULL,
      'featured_image' => NULL,
    ]);
    $writer = $this->container->get(CanvasWriter::class);
    $query = static fn (int $perPage): array => [new BlockNode('core/group', ['tagName' => 'main'], '', [
      new BlockNode('core/query', [
        'query' => ['perPage' => $perPage, 'offset' => 0, 'postType' => 'post', 'order' => 'desc', 'orderBy' => 'date'],
      ], '', [new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])])]),
    ]),
    ];

    $nodesA = $query(3);
    $resultA = $writer->writeQueryPattern('pattern_a', 'Pattern A', $nodesA, QueryLoop::allFromTree($nodesA, 10)['loops'], $mapping, 'fixture');
    $nodesB = $query(6);
    $resultB = $writer->writeQueryPattern('pattern_b', 'Pattern B', $nodesB, QueryLoop::allFromTree($nodesB, 10)['loops'], $mapping, 'fixture');

    $queryA = $resultA['queries'][0];
    $queryB = $resultB['queries'][0];
    self::assertSame($queryA['view_mode']->id(), $queryB['view_mode']->id());
    self::assertSame($queryA['template']->id(), $queryB['template']->id());
    self::assertNotSame($queryA['view']->id(), $queryB['view']->id());
    self::assertNotSame($queryA['component']->id(), $queryB['component']->id());
  }

  /**
   * Tests a Query in a template part becomes a Views block in the frame.
   */
  public function testPageVariantTurnsPartQueryIntoView(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => NULL,
      'tag' => NULL,
      'featured_image' => NULL,
    ]);
    $writer = $this->container->get(CanvasWriter::class);
    $footer = [
      new BlockNode('core/query', [
        'query' => ['perPage' => 3, 'offset' => 0, 'postType' => 'post', 'order' => 'desc', 'orderBy' => 'date'],
      ], '', [
        new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])]),
      ]),
    ];
    $partSet = new PartSet([
      ['type' => 'marker'],
      ['type' => 'part', 'slug' => 'footer', 'attributes' => [], 'nodes' => $footer],
    ]);
    $trees = $partSet->loops(10, 2, NULL);

    ['variant' => $variant, 'queries' => $queries] = $writer->writePageVariant($partSet, 'fixture', $mapping, $trees, NULL, []);

    $ids = array_column($variant->getComponentTree()->getValue(), 'component_id');
    $outputIds = $writer->queryOutputIds('fixture', $trees[1]['loops'][0], 'wordpal_post');
    self::assertContains($outputIds['component'], $ids);
    self::assertStringStartsWith('block.views_block.', $outputIds['component']);
    self::assertNotContains('sdc.wordpal.query', $ids);
    self::assertCount(1, $queries);
    self::assertNotNull(View::load($outputIds['view']));
    self::assertSame(2, $queries[0]['view']->get('display')['default']['display_options']['pager']['options']['id']);
  }

  /**
   * Returns a home tree with one query boundary.
   */
  private function home(): array {
    $postTemplate = new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])]);
    $pagination = new BlockNode('core/query-pagination', ['paginationArrow' => 'arrow', 'layout' => ['type' => 'flex']], '', [
      new BlockNode('core/query-pagination-previous', [], '', []),
      new BlockNode('core/query-pagination-numbers', [], '', []),
      new BlockNode('core/query-pagination-next', [], '', []),
    ]);
    $query = new BlockNode('core/query', [
      'query' => [
        'perPage' => 3,
        'offset' => 0,
        'postType' => 'post',
        'order' => 'desc',
        'orderBy' => 'date',
      ],
    ], '', [
      $postTemplate,
      $pagination,
      new BlockNode('core/query-no-results', [], '', []),
    ]);
    $secondQuery = new BlockNode('core/query', [
      'query' => [
        'perPage' => 2,
        'offset' => 1,
        'postType' => 'post',
        'order' => 'desc',
        'orderBy' => 'date',
        'sticky' => 'exclude',
      ],
    ], '', [new BlockNode('core/post-template', [], '', [
      new BlockNode('core/post-title', [], '', []),
    ]),
    ]);
    return [new BlockNode('core/group', ['tagName' => 'main'], '', [$query, $secondQuery])];
  }

}
