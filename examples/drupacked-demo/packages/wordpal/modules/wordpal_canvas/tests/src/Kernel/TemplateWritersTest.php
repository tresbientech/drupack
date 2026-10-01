<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Pattern;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Views;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\Theme\ResolvedTemplate;
use Drupal\wordpal_canvas\CanvasWriter;
use Drupal\wordpal_convert\TemplatePage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the page, archive, search and 404 Template writers.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class TemplateWritersTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'wordpal_convert', 'wordpal_canvas', 'wordpal_canvas_runtime', 'system', 'user', 'field', 'node', 'text',
    'file', 'image', 'views', 'path', 'path_alias', 'block', 'comment', 'filter', 'taxonomy',
    'search_api', 'search_api_db',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('search_api_task');
    $this->installEntitySchema('comment');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['field', 'filter', 'node', 'system', 'taxonomy', 'user', 'views', 'search_api']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    foreach (['post_text' => 'wordpal_post', 'page_text' => 'wordpal_page'] as $field => $bundle) {
      FieldStorageConfig::create(['field_name' => $field, 'entity_type' => 'node', 'type' => 'text_long'])->save();
      FieldConfig::create(['field_name' => $field, 'entity_type' => 'node', 'bundle' => $bundle])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_tags', 'entity_type' => 'node', 'bundle' => 'wordpal_post'])->save();
    Server::create([
      'id' => 'database',
      'name' => 'Database',
      'backend' => 'search_api_db',
      'backend_config' => ['database' => 'default:default', 'min_chars' => 1, 'matching' => 'words'],
    ])->save();
    Index::create([
      'id' => 'content',
      'name' => 'Content',
      'server' => 'database',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
      'field_settings' => [
        'title' => [
          'label' => 'Title',
          'datasource_id' => 'entity:node',
          'property_path' => 'title',
          'type' => 'text',
          'boost' => 1.0,
        ],
      ],
      'options' => ['index_directly' => FALSE],
    ])->save();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', [
      'wordpal:group', 'wordpal:query', 'wordpal:post-template', 'wordpal:post-title',
      'wordpal:post-content', 'wordpal:query-title', 'wordpal:paragraph', 'wordpal:post-terms',
      'wordpal:frozen-html',
    ]);
  }

  /**
   * Tests the page Template becomes the page bundle's full content template.
   */
  public function testPageTemplateBindsPageBody(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $marker = new PartSet([['type' => 'marker']]);
    $variant = $writer->writePageVariant($marker, 'fixture', $this->mapping(), $marker->loops(10, 1, NULL), NULL, [])['variant'];
    $template = $writer->writeContentTemplate('wordpal_page', 'full', [
      new BlockNode('core/group', ['tagName' => 'main'], '', [
        new BlockNode('core/post-title', [], '', []),
        new BlockNode('core/post-content', [], '', []),
      ]),
    ], $this->mapping(), $variant->id());

    self::assertSame('node.wordpal_page.full', $template->id());
    self::assertSame($variant->id(), ContentTemplate::load('node.wordpal_page.full')->getPageVariant());
    $content = array_values(array_filter(
      $template->get('component_tree'),
      static fn (array $item): bool => $item['component_id'] === 'sdc.wordpal.post-content',
    ))[0];
    self::assertStringContainsString('page_text', $content['inputs']['content']['expression'], 'The page body binds the mapped page_body field.');
  }

  /**
   * Tests an inheriting part Query shows the route of its frame.
   */
  public function testPartInheritingQueryReadsTheFrameRoute(): void {
    $mapping = $this->mapping();
    $writer = $this->container->get(CanvasWriter::class);
    $feed = [
      new BlockNode('core/query', ['query' => ['inherit' => TRUE]], '', [
        new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])]),
      ]),
    ];
    $partSet = new PartSet([
      ['type' => 'marker'],
      ['type' => 'part', 'slug' => 'blog-feed', 'attributes' => [], 'nodes' => $feed],
    ]);
    $trees = $partSet->loops(10, 1, NULL);

    ['queries' => $queries] = $writer->writePageVariant($partSet, 'fixture', $mapping, $trees, 'archive', []);

    $archiveId = $writer->queryOutputIds('fixture', $trees[1]['loops'][0], 'wordpal_post', 'archive')['view'];
    self::assertSame($archiveId, $queries[0]['view']->id());
    self::assertNotSame($archiveId, $writer->queryOutputIds('fixture', $trees[1]['loops'][0], 'wordpal_post')['view']);
  }

  /**
   * Tests a Query inside a Frozen block places its View in the block's hole.
   */
  public function testFrozenBlockPlacesItsQueryViewInHole(): void {
    $mapping = $this->mapping();
    $writer = $this->container->get(CanvasWriter::class);
    $query = new BlockNode('core/query', ['query' => ['perPage' => 3]], '', [
      new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])]),
    ], '<ul class="posts"></ul>');
    $tabs = '<div class="tabs"><h2>Latest</h2><ul class="posts"></ul></div>';
    $partSet = new PartSet([
      ['type' => 'marker'],
      [
        'type' => 'part',
        'slug' => 'footer',
        'attributes' => [],
        'nodes' => [new BlockNode('acme/tabs', [], '', [$query], $tabs)],
      ],
    ]);
    $trees = $partSet->loops(10, 1, NULL);

    ['variant' => $variant, 'queries' => $queries] = $writer->writePageVariant($partSet, 'fixture', $mapping, $trees, NULL, []);

    $items = $variant->get('component_tree');
    $frozen = array_column($items, NULL, 'component_id')['sdc.wordpal.frozen-html'];
    $view = array_values(array_filter($items, static fn (array $item): bool => str_contains($item['component_id'], $queries[0]['view']->id())))[0];
    self::assertSame([$frozen['uuid'], 'hole_1'], [$view['parent_uuid'], $view['slot']]);
    self::assertSame(FrozenBlock::key($tabs), $frozen['inputs']['key']);
    self::assertSame([FrozenBlock::key($tabs) => '<div class="tabs"><h2>Latest</h2><!--wordpal:hole-1--></div>'], $writer->frozenHtml($trees[1]['nodes'], $mapping));
  }

  /**
   * Tests a Frozen block in a hole Query's rows gets its own theme file.
   */
  public function testFrozenBlockInsideHoleQueryIsStored(): void {
    $mapping = $this->mapping();
    $writer = $this->container->get(CanvasWriter::class);
    $rating = new BlockNode('acme/rating', [], '', [], '<div class="rating">4.5</div>');
    $query = new BlockNode('core/query', ['query' => ['perPage' => 3]], '', [
      new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', []), $rating]),
    ], '<ul class="posts"></ul>');
    $tabs = '<div class="tabs"><ul class="posts"></ul></div>';
    $partSet = new PartSet([
      ['type' => 'marker'],
      [
        'type' => 'part',
        'slug' => 'footer',
        'attributes' => [],
        'nodes' => [new BlockNode('acme/tabs', [], '', [$query], $tabs)],
      ],
    ]);
    $trees = $partSet->loops(10, 1, NULL);

    self::assertSame([FrozenBlock::key($tabs), FrozenBlock::key('<div class="rating">4.5</div>')], array_keys($writer->frozenHtml($trees[1]['nodes'], $mapping)));
  }

  /**
   * Tests a left-out Query is cut from its Frozen block's HTML.
   */
  public function testFrozenBlockLosesItsLeftOutQuery(): void {
    $mapping = $this->mapping();
    $writer = $this->container->get(CanvasWriter::class);
    $products = new BlockNode('core/query', ['query' => ['perPage' => 3, 'postType' => 'product']], '', [
      new BlockNode('core/post-template', [], '', []),
    ], '<ul class="products"></ul>');
    $tabs = '<div class="tabs"><h2>Related</h2><ul class="products"></ul></div>';
    $partSet = new PartSet([
      ['type' => 'marker'],
      [
        'type' => 'part',
        'slug' => 'footer',
        'attributes' => [],
        'nodes' => [new BlockNode('acme/tabs', [], '', [$products], $tabs)],
      ],
    ]);
    $trees = $partSet->loops(10, 1, NULL);

    ['queries' => $queries] = $writer->writePageVariant($partSet, 'fixture', $mapping, $trees, NULL, []);

    self::assertSame([], $queries);
    self::assertSame([FrozenBlock::key($tabs) => '<div class="tabs"><h2>Related</h2></div>'], $writer->frozenHtml($trees[1]['nodes'], $mapping));
  }

  /**
   * Tests the 404 Template becomes its own owned Canvas page.
   */
  public function testNotFoundTemplateBecomesCanvasPage(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $marker = new PartSet([['type' => 'marker']]);
    $variant = $writer->writePageVariant($marker, 'fixture', $this->mapping(), $marker->loops(10, 1, NULL), NULL, [])['variant'];
    $page = $writer->writeStaticPage('not_found', [
      new BlockNode('core/group', ['tagName' => 'main'], '', [new BlockNode('core/paragraph', [], '<p>Missing.</p>', [])]),
    ], $this->mapping(), 'fixture', $variant->id());

    self::assertSame(TemplatePage::uuid('fixture', 'not_found'), $page->uuid());
    self::assertSame('Page not found', $page->label());
    self::assertSame(TemplatePage::alias('fixture', 'not_found'), $page->get('path')->alias);
    self::assertSame($variant->id(), $page->get('page_variant')->value);
  }

  /**
   * Tests the archive Template lists the route term's posts on term pages.
   */
  public function testArchiveTemplateView(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $nodes = $this->listing('archive');
    $result = $writer->writeTemplateView('archive', $this->resolved($nodes, QueryLoop::allFromTree($nodes, 10)['loops']), $this->mapping(), 'fixture');

    $ids = $writer->templateOutputIds('fixture', 'archive');
    self::assertSame($ids['view'], $result['view']->id());
    self::assertSame('taxonomy/term/%', $result['view']->getDisplay('page_1')['display_options']['path']);
    self::assertSame($ids['pattern'], $result['view']->getDisplay('default')['display_options']['header']['wordpal_pattern']['pattern_id']);
    self::assertFalse(Pattern::load($ids['pattern'])->status(), 'The Template Pattern stays out of the Canvas library.');
    self::assertFalse($result['view']->status(), 'The page View takes over the term path only once activation enables it.');
    $query = $result['queries'][0]['view'];
    self::assertSame('taxonomy_tid', $query->getDisplay('default')['display_options']['arguments']['tid']['default_argument_type']);
    self::assertNotSame($writer->queryOutputIds('fixture', QueryLoop::allFromTree($nodes, 10)['loops'][0], 'wordpal_post')['view'], $query->id(), 'The archive Query shares no View with a Query outside it.');

    $city = Term::create(['vid' => 'tags', 'name' => 'City']);
    $city->save();
    $other = Term::create(['vid' => 'tags', 'name' => 'Other']);
    $other->save();
    $tagged = Node::create(['type' => 'wordpal_post', 'title' => 'Tagged', 'status' => 1, 'field_tags' => [$city->id()]]);
    $tagged->save();
    Node::create(['type' => 'wordpal_post', 'title' => 'Elsewhere', 'status' => 1, 'field_tags' => [$other->id()]])->save();
    // The term argument checks the visitor's view access to the term.
    $this->setUpCurrentUser([], ['access content']);
    $listing = Views::getView($query->id());
    $listing->setArguments([$city->id()]);
    $listing->execute('block_1');
    self::assertSame([(int) $tagged->id()], array_map(static fn ($row): int => (int) $row->_entity->id(), $listing->result));
  }

  /**
   * Tests the search Template lists the mapped index's results by parameter.
   */
  public function testSearchTemplateView(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $nodes = $this->listing('search');
    $result = $writer->writeTemplateView('search', $this->resolved($nodes, QueryLoop::allFromTree($nodes, 10)['loops']), $this->mapping(), 'fixture');

    self::assertSame('search', $result['view']->getDisplay('page_1')['display_options']['path']);
    $query = $result['queries'][0]['view'];
    self::assertSame('search_api_index_content', $query->get('base_table'));
    $options = $query->getDisplay('default')['display_options'];
    self::assertSame('keywords', $options['filters']['search_api_fulltext']['expose']['identifier']);
    $viewMode = substr($result['queries'][0]['view_mode']->id(), strlen('node.'));
    self::assertSame(['entity:node' => ['wordpal_page' => $viewMode, 'wordpal_post' => $viewMode]], $options['row']['options']['view_modes'], 'Posts and pages render in the Query card.');
    self::assertTrue($query->getDisplay('block_1')['display_options']['exposed_block'], 'The search form of the Template submits the parameter.');
    self::assertSame("node.wordpal_page.$viewMode", $result['queries'][0]['page_template']->id());
    $pageCard = array_column($result['queries'][0]['page_template']->get('component_tree'), 'component_id');
    self::assertNotContains('sdc.wordpal.post-terms', $pageCard, 'The page card leaves out the tags the page bundle lacks.');
    self::assertSame([
      'wordpal_post' => ['type' => 'post', 'category_field' => NULL, 'tag_field' => 'field_tags', 'image_field' => NULL],
      'wordpal_page' => ['type' => 'page', 'category_field' => NULL, 'tag_field' => NULL, 'image_field' => NULL],
    ], $query->getThirdPartySetting('wordpal', 'query')['bundles']);

    $city = Term::create(['vid' => 'tags', 'name' => 'City']);
    $city->save();
    $post = Node::create([
      'type' => 'wordpal_post',
      'title' => 'City walk',
      'status' => 1,
      'field_tags' => [$city->id()],
    ]);
    $post->save();
    $page = Node::create(['type' => 'wordpal_page', 'title' => 'City guide', 'status' => 1]);
    $page->save();
    Node::create(['type' => 'wordpal_page', 'title' => 'Elsewhere', 'status' => 1])->save();
    Index::load('content')->indexItems();
    $this->setUpCurrentUser([], ['access content']);
    $listing = Views::getView($query->id());
    // The index has no ignorecase processor, and the database backend
    // matches words case-sensitively.
    $listing->setExposedInput(['keywords' => 'City']);
    $build = $listing->preview('block_1');
    $ids = array_map(static fn ($row): int => (int) $row->_object->getValue()->id(), $listing->result);
    sort($ids);
    self::assertSame([(int) $post->id(), (int) $page->id()], $ids, 'The search lists the matching post and page.');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertStringContainsString('type-page', $html);
    self::assertStringContainsString('page-' . $page->id(), $html);
    self::assertStringContainsString('tag-city', $html);
  }

  /**
   * Tests a search Template's non-inheriting Query ignores the search keys.
   *
   * WordPress 7.1.2 blocks/post-template.php gives a non-inheriting Query
   * its own WP_Query from build_query_vars_from_query_block(), which sets
   * "s" only from the block's own search attribute.
   */
  public function testSearchTemplateOwnQueryListsLatestPosts(): void {
    $nodes = $this->listing('search');
    $nodes[] = new BlockNode('core/query', [
      'query' => ['perPage' => 4, 'postType' => 'post', 'inherit' => FALSE],
    ], '', [
      new BlockNode('core/post-template', [], '', [new BlockNode('core/post-date', [], '', [])]),
    ]);
    $result = $this->container->get(CanvasWriter::class)->writeTemplateView('search', $this->resolved($nodes, QueryLoop::allFromTree($nodes, 10)['loops']), $this->mapping(), 'fixture');

    self::assertSame('search_api_index_content', $result['queries'][0]['view']->get('base_table'));
    $own = $result['queries'][1];
    self::assertSame('node_field_data', $own['view']->get('base_table'));
    self::assertArrayNotHasKey('search_api_fulltext', $own['view']->getDisplay('default')['display_options']['filters']);
    self::assertSame(4, $own['view']->getDisplay('default')['display_options']['pager']['options']['items_per_page']);
    self::assertSame(0, $result['queries'][0]['view']->getDisplay('default')['display_options']['pager']['options']['id']);
    self::assertSame(1, $own['view']->getDisplay('default')['display_options']['pager']['options']['id'], 'The Query pages apart from the search results.');
    self::assertNull($own['page_template'], 'The Query lists posts only, so it has no page card.');

    foreach (['City walk', 'Harbour walk'] as $title) {
      Node::create(['type' => 'wordpal_post', 'title' => $title, 'status' => 1])->save();
    }
    $this->setUpCurrentUser([], ['access content']);
    $listing = Views::getView($own['view']->id());
    $listing->setExposedInput(['keywords' => 'City']);
    $listing->execute('block_1');
    self::assertCount(2, $listing->result, 'The keys do not filter the Query.');
  }

  /**
   * Tests a term Template's own Query lists posts of every term.
   */
  public function testTermTemplateOwnQueryListsLatestPosts(): void {
    $nodes = $this->listing('archive');
    $nodes[] = new BlockNode('core/query', [
      'query' => ['perPage' => 4, 'postType' => 'post', 'inherit' => FALSE],
    ], '', [
      new BlockNode('core/post-template', [], '', [new BlockNode('core/post-date', [], '', [])]),
    ]);
    $result = $this->container->get(CanvasWriter::class)->writeTermTemplate('tag', $this->resolved($nodes, QueryLoop::allFromTree($nodes, 10)['loops']), $this->mapping(), 'fixture');

    $inheriting = $result['queries'][0]['view']->getDisplay('default')['display_options'];
    self::assertSame('taxonomy_tid', $inheriting['arguments']['tid']['default_argument_type']);
    self::assertSame(0, $inheriting['pager']['options']['id']);
    $own = $result['queries'][1]['view']->getDisplay('default')['display_options'];
    self::assertArrayNotHasKey('tid', $own['arguments'] ?? [], 'The route term does not filter the Query.');
    self::assertSame(1, $own['pager']['options']['id'], 'The Query pages apart from the term listing.');
  }

  /**
   * Tests the page card leaves out a featured Cover and Post Content it lacks.
   */
  public function testSearchPageCardSkipsFieldsThePageLacks(): void {
    FieldStorageConfig::create(['field_name' => 'field_image', 'entity_type' => 'node', 'type' => 'image'])->save();
    FieldConfig::create(['field_name' => 'field_image', 'entity_type' => 'node', 'bundle' => 'wordpal_post'])->save();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:cover']);
    $nodes = $this->listing('search', [
      new BlockNode('core/post-title', [], '', []),
      new BlockNode('core/cover', ['useFeaturedImage' => TRUE], '', [new BlockNode('core/paragraph', [], '<p>Over.</p>', [])]),
      new BlockNode('core/post-content', [], '', []),
    ]);
    $mapping = new ContentMapping([
      'featured_image' => 'field_image',
      'page_body' => NULL,
    ] + $this->mappingTargets());

    $result = $this->container->get(CanvasWriter::class)->writeTemplateView('search', $this->resolved($nodes, QueryLoop::allFromTree($nodes, 10)['loops']), $mapping, 'fixture');

    $postCard = array_column($result['queries'][0]['template']->get('component_tree'), 'component_id');
    self::assertContains('sdc.wordpal.cover', $postCard);
    self::assertContains('sdc.wordpal.post-content', $postCard);
    $pageCard = array_column($result['queries'][0]['page_template']->get('component_tree'), 'component_id');
    self::assertContains('sdc.wordpal.post-title', $pageCard);
    self::assertNotContains('sdc.wordpal.cover', $pageCard, 'The page bundle has no featured image field.');
    self::assertNotContains('sdc.wordpal.paragraph', $pageCard, 'The Cover content goes with the Cover.');
    self::assertNotContains('sdc.wordpal.post-content', $pageCard, 'The mapping drops page_body.');
  }

  /**
   * Tests a tag Template renders on the mapped vocabulary's term pages only.
   */
  public function testTagTemplateSharesTheArchiveView(): void {
    $writer = $this->container->get(CanvasWriter::class);
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    $tagNodes = $this->listing('archive');
    $tag = $writer->writeTermTemplate('tag', $this->resolved($tagNodes, QueryLoop::allFromTree($tagNodes, 10)['loops']), $this->mapping(), 'fixture');
    $nodes = [new BlockNode('core/group', ['tagName' => 'main'], '', [new BlockNode('core/paragraph', [], '<p>Archive.</p>', [])])];
    $archive = $writer->writeTemplateView('archive', $this->resolved($nodes, []), $this->mapping(), 'fixture', ['tags' => $tag['pattern']->id()]);
    self::assertSame('fixture_tag_template', $tag['pattern']->id());
    $archive['view']->enable()->save();

    $this->setUpCurrentUser([], ['access content']);
    foreach (['tags' => $tag['pattern']->id(), 'topics' => $writer->templateOutputIds('fixture', 'archive')['pattern']] as $vocabulary => $expected) {
      $term = Term::create(['vid' => $vocabulary, 'name' => $vocabulary]);
      $term->save();
      $view = Views::getView($archive['view']->id());
      $view->setArguments([$term->id()]);
      $view->execute('page_1');
      $rendered = [];
      foreach ($view->display_handler->getHandlers('header') as $area) {
        if ($area->render() !== []) {
          $rendered[] = $area->options['pattern_id'];
        }
      }
      self::assertSame([$expected], $rendered, "A $vocabulary term page renders its own Template.");
    }
  }

  /**
   * Returns a listing Template's main tree: its title and inheriting Query.
   *
   * @param string $type
   *   The Query Title type.
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $card
   *   The Post Template's blocks. Defaults to a Title block.
   */
  private function listing(string $type, array $card = []): array {
    return [
      new BlockNode('core/group', ['tagName' => 'main'], '', [
        new BlockNode('core/query-title', ['type' => $type], '', []),
        new BlockNode('core/query', [
          'query' => [
            'perPage' => 3,
            'offset' => 0,
            'postType' => 'post',
            'order' => 'desc',
            'orderBy' => 'date',
            'inherit' => TRUE,
          ],
        ], '', [
          new BlockNode('core/post-template', [], '', $card ?: [new BlockNode('core/post-title', [], '', [])]),
        ]),
      ]),
    ];
  }

  /**
   * Resolves a listing Template's tree, with no page frame of its own.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The Template's main tree.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop[] $loops
   *   The tree's Query blocks.
   */
  private function resolved(array $nodes, array $loops): ResolvedTemplate {
    return new ResolvedTemplate($nodes, new PartSet([]), $loops, []);
  }

  /**
   * Returns the mapping every writer reads.
   */
  private function mapping(): ContentMapping {
    return new ContentMapping($this->mappingTargets());
  }

  /**
   * Returns the targets of the mapping every writer reads.
   */
  private function mappingTargets(): array {
    return [
      'post' => 'wordpal_post',
      'post_body' => 'post_text',
      'page' => 'wordpal_page',
      'page_body' => 'page_text',
      'category' => NULL,
      'tag' => ['vocabulary' => 'tags', 'field' => 'field_tags'],
      'featured_image' => NULL,
      'search' => ['path' => '/search', 'parameter' => 'keywords', 'index' => 'content'],
    ];
  }

}
