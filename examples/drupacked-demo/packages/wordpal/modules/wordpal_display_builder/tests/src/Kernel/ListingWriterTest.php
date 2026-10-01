<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Kernel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\display_builder\Entity\PatternPreset;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\wordpal\Traits\ThemeSettingsTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\Theme\ResolvedTemplate;
use Drupal\wordpal\Query\QuerySettings;
use Drupal\wordpal_display_builder\DisplayBuilderWriter;
use Drupal\wordpal_display_builder\ListingWriter;
use Drupal\wordpal_display_builder\SourceTreeBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Query loops and listing Templates on the display_builder target.
 *
 * Drives ListingWriter directly against hand-built QueryLoop, PartSet and
 * ResolvedTemplate objects, the way wordpal_canvas's own TemplateWritersTest
 * and QueryPageTest exercise CanvasWriter, rather than through a full
 * snapshot fixture: the checked-in fixture theme has no archive, tag,
 * category or search Template.
 */
#[CoversClass(ListingWriter::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ListingWriterTest extends KernelTestBase {

  use ThemeSettingsTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'user', 'comment', 'node', 'views', 'menu_link_content', 'filter', 'link', 'taxonomy', 'block',
    'wordpal', 'wordpal_convert', 'wordpal_display_builder', 'wordpal_display_builder_runtime',
    'display_builder', 'display_builder_page_layout', 'display_builder_entity_view', 'display_builder_views',
    'ui_patterns', 'ui_patterns_field', 'ui_patterns_views',
    'system', 'field', 'text', 'file', 'image', 'path', 'path_alias',
    'search_api', 'search_api_db',
  ];

  /**
   * The writer under test.
   */
  private DisplayBuilderWriter $writer;

  /**
   * A Content mapping binding only the post bundle.
   */
  private ContentMapping $mapping;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('search_api_task');
    $this->installEntitySchema('display_builder_instance');
    $this->installEntitySchema('file');
    $this->installConfig([
      'field', 'filter', 'node', 'system', 'taxonomy', 'user', 'views', 'ui_patterns', 'display_builder', 'search_api',
    ]);
    $this->installSchema('search_api', ['search_api_item']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('file', ['file_usage']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->writer = $this->container->get(DisplayBuilderWriter::class);
    $targets = array_fill_keys(ContentMapping::CONCEPTS, NULL);
    $targets['post'] = 'wordpal_post';
    $targets['search'] = ['path' => '/search', 'parameter' => 'keywords', 'index' => 'wordpal_content'];
    $this->mapping = new ContentMapping($targets);
    $this->mapping->setThemeId('wordpal_fixture');
    $this->writeThemeSettings();
  }

  /**
   * Returns a minimal Query loop, with pagination and an optional empty tree.
   *
   * @param bool $withEmpty
   *   Whether the Query has a no-results tree.
   * @param int $perPage
   *   The Query's own page size.
   * @param bool $inherit
   *   Whether the Query inherits the route's own main query, the shape a
   *   real archive, tag, category or search Template's Query takes.
   */
  private function loop(bool $withEmpty = FALSE, int $perPage = 3, bool $inherit = FALSE): array {
    $postTemplate = new BlockNode('core/post-template', [], '', [
      new BlockNode('core/post-title', [], '<h2>Sample</h2>', []),
      new BlockNode('core/post-date', [], '<time>Sample</time>', []),
    ]);
    $pagination = new BlockNode('core/query-pagination', [], '', []);
    $noResults = $withEmpty ? new BlockNode('core/query-no-results', [], '', [
      new BlockNode('core/paragraph', [], '<p>Nothing found.</p>', []),
    ]) : NULL;
    $children = array_filter([$postTemplate, $pagination, $noResults]);
    $query = new BlockNode('core/query', ['query' => ['perPage' => $perPage, 'inherit' => $inherit]], '', $children);
    $loop = new QueryLoop($query, $postTemplate, $pagination, $noResults, $perPage, 0, 'DESC', FALSE, $inherit, $inherit ? 0 : 1, 0, 'date');
    return [$query, $loop];
  }

  /**
   * Tests a pattern with a Query loop places the View as a block source.
   */
  public function testPatternWithQueryLoopPlacesTheViewAsBlockSource(): void {
    [$query, $loop] = $this->loop();
    $written = $this->writer->writeQueryPattern('wordpal_fixture_latest', 'Latest', [$query], [$loop], $this->mapping, 'wordpal-fixture');
    ['pattern' => $preset, 'queries' => $queries] = $written;

    self::assertInstanceOf(PatternPreset::class, $preset);
    $sources = $preset->getSources();
    self::assertSame('block', $sources['source_id'], 'The outer tree places the Query as a block source.');
    self::assertStringStartsWith('views_block:', $sources['source']['plugin_id']);

    self::assertCount(1, $queries);
    $view = $queries[0]['view'];
    self::assertInstanceOf(View::class, $view);
    self::assertSame('wordpal_query', $view->get('tag'));
    $default = $view->getDisplay('default');
    self::assertSame('ui_patterns', $default['display_options']['row']['type']);
    self::assertSame('default', $default['display_options']['display_extenders']['display_builder']['profile']);
    $extenderSources = $default['display_options']['display_extenders']['display_builder']['sources'];
    self::assertSame('wordpal:query', $extenderSources[0]['source']['component']['component_id'], "The Query block's own component wraps the View.");
    $querySources = $extenderSources[0]['source']['component']['slots']['content']['sources'];
    self::assertSame(['view_rows', 'view_pager'], array_column($querySources, 'source_id'), 'No empty tree, so no view_empty region.');
    self::assertNull($queries[0]['empty_pattern']);
  }

  /**
   * Tests a Query saved with a legacy displayLayout still places its rows.
   */
  public function testLegacyDisplayLayoutQueryPlacesRows(): void {
    [$query, $loop] = $this->loop();
    $query = new BlockNode('core/query', $query->attributes + ['displayLayout' => ['type' => 'flex', 'columns' => 2]], '', $query->children);
    $loop = QueryLoop::allFromTree([$query], 10)['loops'][0];
    ['queries' => $queries] = $this->writer->writeQueryPattern('wordpal_fixture_legacy', 'Legacy', [$query], [$loop], $this->mapping, 'wordpal-fixture');

    $sources = $queries[0]['view']->getDisplay('default')['display_options']['display_extenders']['display_builder']['sources'];
    self::assertSame(['view_rows', 'view_pager'], array_column($sources[0]['source']['component']['slots']['content']['sources'], 'source_id'));
  }

  /**
   * Tests a written Query View carries the `wordpal.query` setting.
   *
   * Display Builder's rows and pager render as WordPress markup only when
   * QuerySettings::fromView() can read this back, the same setting
   * CanvasWriter writes for its own target.
   */
  public function testWrittenQueryViewCarriesWordpalQuerySetting(): void {
    [$query, $loop] = $this->loop();
    ['queries' => $queries] = $this->writer->writeQueryPattern('wordpal_fixture_latest', 'Latest', [$query], [$loop], $this->mapping, 'wordpal-fixture');

    $settings = QuerySettings::fromView($queries[0]['view']);
    self::assertSame([], $settings->query, "Display Builder's writer sets no `query`; Canvas's own embed alone needs it.");
    self::assertSame([], $settings->layout, "Display Builder's writer sets no `layout`; Canvas's own embed alone needs it.");
    self::assertArrayHasKey('wordpal_post', $settings->bundles);
    self::assertSame('post', $settings->bundles['wordpal_post']['type']);
  }

  /**
   * Tests a written Query View renders the Query component as its root.
   *
   * WordPress prints the Query block straight inside its parent, so the
   * View's own `.view` wrapper div must not surround it.
   */
  public function testWrittenQueryViewRendersNoViewWrapper(): void {
    // display_builder_views_install() enables the extender, and kernel tests
    // skip install hooks.
    $this->config('views.settings')->set('display_extenders', ['display_builder'])->save();
    $this->setUpCurrentUser([], ['access content', 'bypass node access']);
    Node::create(['type' => 'wordpal_post', 'title' => 'First walk', 'status' => 1])->save();
    [$query, $loop] = $this->loop();
    ['queries' => $queries] = $this->writer->writeQueryPattern('wordpal_fixture_latest', 'Latest', [$query], [$loop], $this->mapping, 'wordpal-fixture');

    $build = Views::getView($queries[0]['view']->id())->buildRenderable('block_1');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertStringContainsString('First walk', $html);
    self::assertStringStartsWith('<div data-component-id="wordpal:query"', trim($html));
  }

  /**
   * Tests an empty listing saves a disabled preset the empty region renders.
   */
  public function testEmptyListingSavesDisabledPreset(): void {
    [$query, $loop] = $this->loop(withEmpty: TRUE);
    $written = $this->writer->writeQueryPattern('wordpal_fixture_latest', 'Latest', [$query], [$loop], $this->mapping, 'wordpal-fixture');
    ['queries' => $queries] = $written;

    $emptyPattern = $queries[0]['empty_pattern'];
    self::assertInstanceOf(PatternPreset::class, $emptyPattern);
    self::assertFalse($emptyPattern->status());
    $view = $queries[0]['view'];
    $default = $view->getDisplay('default');
    $extenderSources = $default['display_options']['display_extenders']['display_builder']['sources'];
    self::assertContains('view_empty', array_column($extenderSources[0]['source']['component']['slots']['content']['sources'], 'source_id'));
    self::assertSame($emptyPattern->id(), $default['display_options']['empty']['wordpal_pattern_preset']['pattern_id']);
  }

  /**
   * Tests two identical Query blocks share one View.
   */
  public function testTwoIdenticalQueryBlocksShareOneView(): void {
    [$queryA, $loopA] = $this->loop();
    [$queryB, $loopB] = $this->loop();

    ['queries' => $queriesA] = $this->writer->writeQueryPattern('wordpal_fixture_a', 'A', [$queryA], [$loopA], $this->mapping, 'wordpal-fixture');
    ['queries' => $queriesB] = $this->writer->writeQueryPattern('wordpal_fixture_b', 'B', [$queryB], [$loopB], $this->mapping, 'wordpal-fixture');

    self::assertSame($queriesA[0]['view']->id(), $queriesB[0]['view']->id(), 'Identical Query blocks reuse the same View.');
    self::assertCount(1, View::loadMultiple([$queriesA[0]['view']->id()]));
  }

  /**
   * Tests the row binds the row entity's own title and date.
   */
  public function testRowBindsTheRowEntityTitleAndDate(): void {
    [$query, $loop] = $this->loop();
    $written = $this->writer->writeQueryPattern('wordpal_fixture_latest', 'Latest', [$query], [$loop], $this->mapping, 'wordpal-fixture');
    ['queries' => $queries] = $written;

    $row = $queries[0]['view']->getDisplay('default')['display_options']['row']['options']['ui_patterns'];
    self::assertSame('wordpal:fragment', $row['component_id'], 'Two card blocks (title, date) sit under the Fragment root.');
    $titleNode = $row['slots']['content']['sources'][0];
    self::assertSame('entity_field', $titleNode['source']['component']['props']['title']['source_id']);
    self::assertSame('entity_link', $titleNode['source']['component']['props']['url']['source_id']);
    $dateNode = $row['slots']['content']['sources'][1];
    self::assertSame('entity_field', $dateNode['source']['component']['props']['timestamp']['source_id']);
  }

  /**
   * Adds a real field to a bundle, the way a mapped concept needs one.
   */
  private function addField(string $bundle, string $name, string $type): void {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type])->save();
    FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle])->save();
  }

  /**
   * Returns a mapping binding post, featured_image and excerpt to real fields.
   */
  private function richMapping(): ContentMapping {
    $this->addField('wordpal_post', 'field_hero_image', 'image');
    $this->addField('wordpal_post', 'field_teaser', 'string_long');
    $targets = array_fill_keys(ContentMapping::CONCEPTS, NULL);
    $targets['post'] = 'wordpal_post';
    $targets['featured_image'] = 'field_hero_image';
    $targets['excerpt'] = 'field_teaser';
    $mapping = new ContentMapping($targets);
    $mapping->setThemeId('wordpal_fixture');
    return $mapping;
  }

  /**
   * Returns a Query loop whose card has title, date, image, excerpt, author.
   */
  private function richLoop(): array {
    $postTemplate = new BlockNode('core/post-template', [], '', [
      new BlockNode('core/post-title', [], '', []),
      new BlockNode('core/post-date', [], '', []),
      new BlockNode('core/post-featured-image', [], '', []),
      new BlockNode('core/post-excerpt', [], '', []),
      new BlockNode('core/post-author-name', [], '', []),
    ]);
    $pagination = new BlockNode('core/query-pagination', [], '', []);
    $query = new BlockNode('core/query', ['query' => ['perPage' => 3]], '', [$postTemplate, $pagination]);
    $loop = new QueryLoop($query, $postTemplate, $pagination, NULL, 3, 0, 'DESC', FALSE, FALSE, 1, 0, 'date');
    return [$query, $loop];
  }

  /**
   * Tests title, date, image, excerpt and author bind through the table.
   */
  public function testRowBindsTitleDateImageExcerptAndAuthorThroughTheTable(): void {
    $mapping = $this->richMapping();
    [$query, $loop] = $this->richLoop();

    $written = $this->writer->writeQueryPattern('wordpal_fixture_rich', 'Rich', [$query], [$loop], $mapping, 'wordpal-fixture');
    $row = $written['queries'][0]['view']->getDisplay('default')['display_options']['row']['options']['ui_patterns'];
    $sources = $row['slots']['content']['sources'];

    [$titleNode, $dateNode, $imageNode, $excerptNode, $authorNode] = $sources;
    self::assertSame('entity_field', $titleNode['source']['component']['props']['title']['source_id']);
    self::assertSame('entity_link', $titleNode['source']['component']['props']['url']['source_id']);
    self::assertSame('entity_field', $dateNode['source']['component']['props']['timestamp']['source_id']);
    self::assertSame('entity_field', $imageNode['source']['component']['props']['src']['source_id'], 'Featured image src binds through the table.');
    self::assertSame('entity_field', $imageNode['source']['component']['props']['alt']['source_id']);
    self::assertSame('entity_field', $excerptNode['source']['component']['props']['excerpt']['source_id']);
    self::assertSame('entity_field', $authorNode['source']['component']['props']['author_id']['source_id'], 'Author name and avatar both read author_id.');
  }

  /**
   * Tests a bundle with no image field drops the card's image, and reports it.
   */
  public function testRowDropsFeaturedImageWithNoBundleFieldAndReports(): void {
    $targets = array_fill_keys(ContentMapping::CONCEPTS, NULL);
    $targets['post'] = 'wordpal_post';
    $targets['featured_image'] = 'field_no_such_field';
    $mapping = new ContentMapping($targets);
    $mapping->setThemeId('wordpal_fixture');

    $postTemplate = new BlockNode('core/post-template', [], '', [
      new BlockNode('core/post-title', [], '', []),
      new BlockNode('core/post-featured-image', [], '', []),
    ]);
    $pagination = new BlockNode('core/query-pagination', [], '', []);
    $query = new BlockNode('core/query', ['query' => ['perPage' => 3]], '', [$postTemplate, $pagination]);
    $loop = new QueryLoop($query, $postTemplate, $pagination, NULL, 3, 0, 'DESC', FALSE, FALSE, 1, 0, 'date');

    $written = $this->writer->writeQueryPattern('wordpal_fixture_no_image', 'No image', [$query], [$loop], $mapping, 'wordpal-fixture');
    $row = $written['queries'][0]['view']->getDisplay('default')['display_options']['row']['options']['ui_patterns'];

    self::assertSame('wordpal:post-title', $row['component_id'], 'Post Featured Image is dropped, Post Title is the card\'s single root.');
    self::assertSame(['featured_image' => 1], $mapping->droppedBlocks());
  }

  /**
   * Tests the row and the entity view display bind a block the same way.
   *
   * One bindings table serves both targets: SourceTreeBuilder::build() with
   * a bound bundle resolves the entity view display's own runtime blocks,
   * and rowComponent() resolves the row's; both read RuntimeBindings, so the
   * same block against the same bundle must produce the same source.
   */
  public function testRowAndEntityViewDisplayBindTheSameBlockIdentically(): void {
    $direct = $this->container->get(SourceTreeBuilder::class)->build([new BlockNode('core/post-title', [], '', [])], $this->mapping, boundBundle: 'wordpal_post');

    [$query, $loop] = $this->loop();
    $written = $this->writer->writeQueryPattern('wordpal_fixture_shape', 'Shape', [$query], [$loop], $this->mapping, 'wordpal-fixture');
    $row = $written['queries'][0]['view']->getDisplay('default')['display_options']['row']['options']['ui_patterns'];
    $rowTitle = $row['slots']['content']['sources'][0]['source']['component']['props']['title'];

    self::assertSame($direct[0]['source']['component']['props']['title'], $rowTitle, 'The row binds title through the same table as the entity view display.');
  }

  /**
   * Tests a single Template with a Query loop writes the View and template.
   */
  public function testSingleTemplateWithQueryLoopWritesTheViewAndTemplate(): void {
    [$query, $loop] = $this->loop();
    $template = $this->template($query, $loop);

    $written = $this->writer->writeQueryContentTemplate('wordpal_post', 'single', $template, $this->mapping, 'wordpal-fixture');
    ['template' => $display, 'queries' => $queries] = $written;

    self::assertInstanceOf(EntityViewDisplayInterface::class, $display);
    $sources = $display->getThirdPartySetting('display_builder', 'sources');
    self::assertSame('block', $sources[0]['source_id'], 'The content template places the Query as a block source.');
    self::assertStringStartsWith('views_block:', $sources[0]['source']['plugin_id']);

    self::assertCount(1, $queries);
    $view = $queries[0]['view'];
    self::assertInstanceOf(View::class, $view);
    self::assertSame('wordpal_query', $view->get('tag'));
  }

  /**
   * Tests a Query in a template part becomes a View block in the frame.
   */
  public function testPageVariantTurnsPartQueryIntoView(): void {
    [$query] = $this->loop();
    $partSet = new PartSet([
      ['type' => 'marker'],
      ['type' => 'part', 'slug' => 'footer', 'attributes' => [], 'nodes' => [$query]],
    ]);
    $trees = $partSet->loops(10, 2, NULL);

    ['variant' => $layout, 'queries' => $queries] = $this->writer->writePageVariant($partSet, 'wordpal-fixture', $this->mapping, $trees, NULL, []);

    $sources = array_column($layout->get('sources'), 'source_id');
    self::assertSame(['main_page_content', 'block'], $sources);
    self::assertStringStartsWith('views_block:', $layout->get('sources')[1]['source']['plugin_id']);
    self::assertCount(1, $queries);
    self::assertNotNull(View::load($queries[0]['view']->id()));
    self::assertSame(2, $queries[0]['view']->get('display')['default']['display_options']['pager']['options']['id']);
  }

  /**
   * Tests a Query inside a Frozen block places its View in the block's hole.
   */
  public function testFrozenBlockPlacesItsQueryViewInHole(): void {
    [$loopQuery] = $this->loop();
    $query = new BlockNode('core/query', $loopQuery->attributes, '', $loopQuery->children, '<ul class="posts"></ul>');
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

    ['variant' => $layout] = $this->writer->writePageVariant($partSet, 'wordpal-fixture', $this->mapping, $trees, NULL, []);

    $frozen = $layout->get('sources')[1]['source']['component'];
    self::assertSame('wordpal:frozen-html', $frozen['component_id']);
    self::assertSame(FrozenBlock::key($tabs), $frozen['props']['key']['source']['value']);
    self::assertStringStartsWith('views_block:', $frozen['slots']['hole_1']['sources'][0]['source']['plugin_id']);
    self::assertSame([FrozenBlock::key($tabs) => '<div class="tabs"><h2>Latest</h2><!--wordpal:hole-1--></div>'], $this->writer->frozenHtml($trees[1]['nodes'], $this->mapping));
  }

  /**
   * Tests a Frozen block in a hole Query's rows gets its own theme file.
   */
  public function testFrozenBlockInsideHoleQueryIsStored(): void {
    $rating = new BlockNode('acme/rating', [], '', [], '<div class="rating">4.5</div>');
    $query = new BlockNode('core/query', ['query' => ['perPage' => 3]], '', [
      new BlockNode('core/post-template', [], '', [$rating]),
    ], '<ul class="posts"></ul>');
    $tabs = '<div class="tabs"><ul class="posts"></ul></div>';

    $keys = array_keys($this->writer->frozenHtml([new BlockNode('acme/tabs', [], '', [$query], $tabs)], $this->mapping));

    self::assertSame([FrozenBlock::key($tabs), FrozenBlock::key('<div class="rating">4.5</div>')], $keys);
  }

  /**
   * Tests an inheriting part Query shows the route of its frame.
   */
  public function testPartInheritingQueryReadsTheFrameRoute(): void {
    [$query] = $this->loop(inherit: TRUE);
    $partSet = new PartSet([
      ['type' => 'marker'],
      ['type' => 'part', 'slug' => 'blog-feed', 'attributes' => [], 'nodes' => [$query]],
    ]);
    $trees = $partSet->loops(10, 1, NULL);

    ['queries' => $queries] = $this->writer->writePageVariant($partSet, 'wordpal-fixture', $this->mapping, $trees, 'archive', []);

    $ids = $this->writer->queryOutputIds('wordpal-fixture', $trees[1]['loops'][0], 'wordpal_post', 'archive');
    self::assertSame($ids['view'], $queries[0]['view']->id());
    self::assertNotSame($ids['view'], $this->writer->queryOutputIds('wordpal-fixture', $trees[1]['loops'][0], 'wordpal_post')['view']);
  }

  /**
   * Returns a resolved Template with one Query loop as its whole main area.
   */
  private function template(BlockNode $query, QueryLoop $loop): ResolvedTemplate {
    $partSet = new PartSet([]);
    return new ResolvedTemplate([$query], $partSet, [$loop], []);
  }

  /**
   * Returns a mapping whose featured image names a field no bundle has.
   */
  private function missingImageMapping(): ContentMapping {
    $targets = array_fill_keys(ContentMapping::CONCEPTS, NULL);
    $targets['post'] = 'wordpal_post';
    $targets['featured_image'] = 'field_no_such_field';
    $mapping = new ContentMapping($targets);
    $mapping->setThemeId('wordpal_fixture');
    return $mapping;
  }

  /**
   * Returns a Query loop whose card holds a title and a featured image.
   */
  private function imageLoop(): array {
    return $this->cardLoop([
      new BlockNode('core/post-title', [], '', []),
      new BlockNode('core/post-featured-image', [], '', []),
    ]);
  }

  /**
   * Returns the component id a View's row renders its card with.
   */
  private function rowComponentId(View $view): string {
    return $view->getDisplay('default')['display_options']['row']['options']['ui_patterns']['component_id'];
  }

  /**
   * Returns a Query loop with the given card and no-results blocks.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $card
   *   The Post Template's children.
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $empty
   *   The no-results tree's children, or none for no such tree.
   */
  private function cardLoop(array $card, array $empty = []): array {
    $postTemplate = new BlockNode('core/post-template', [], '', $card);
    $pagination = new BlockNode('core/query-pagination', [], '', []);
    $noResults = $empty === [] ? NULL : new BlockNode('core/query-no-results', [], '', $empty);
    $children = array_filter([$postTemplate, $pagination, $noResults]);
    $query = new BlockNode('core/query', ['query' => ['perPage' => 3]], '', $children);
    $loop = new QueryLoop($query, $postTemplate, $pagination, $noResults, 3, 0, 'DESC', FALSE, FALSE, 1, 0, 'date');
    return [$query, $loop];
  }

  /**
   * Tests a card's missing image counts once, not from host and card both.
   */
  public function testQueryContentTemplateCountsMissingRowImageOnce(): void {
    $mapping = $this->missingImageMapping();
    [$query, $loop] = $this->imageLoop();

    ['queries' => $queries] = $this->writer->writeQueryContentTemplate('wordpal_post', 'single', $this->template($query, $loop), $mapping, 'wordpal-fixture');

    $row = $queries[0]['view']->getDisplay('default')['display_options']['row']['options']['ui_patterns'];
    self::assertSame('wordpal:post-title', $row['component_id'], 'The card drops its image.');
    self::assertSame(['featured_image' => 1], $mapping->droppedBlocks());
  }

  /**
   * Tests a card keeps its image when only the host bundle lacks the field.
   */
  public function testHostBundleDoesNotDetermineRowImageDrop(): void {
    $mapping = $this->richMapping();
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    [$query, $loop] = $this->imageLoop();

    ['queries' => $queries] = $this->writer->writeQueryContentTemplate('wordpal_page', 'single', $this->template($query, $loop), $mapping, 'wordpal-fixture');

    $row = $queries[0]['view']->getDisplay('default')['display_options']['row']['options']['ui_patterns'];
    self::assertCount(2, $row['slots']['content']['sources'], 'The card keeps title and image.');
    self::assertSame([], $mapping->droppedBlocks());
  }

  /**
   * Tests a host image and a card image, both omitted, count as two.
   */
  public function testHostAndRowImageDropsCountSeparately(): void {
    $mapping = $this->missingImageMapping();
    [$query, $loop] = $this->imageLoop();
    $template = new ResolvedTemplate([new BlockNode('core/post-featured-image', [], '', []), $query], new PartSet([]), [$loop], []);

    ['template' => $display] = $this->writer->writeQueryContentTemplate('wordpal_post', 'single', $template, $mapping, 'wordpal-fixture');

    self::assertCount(1, $display->getThirdPartySetting('display_builder', 'sources'), 'The host drops its image and keeps the Query.');
    self::assertSame(['featured_image' => 2], $mapping->droppedBlocks());
  }

  /**
   * Tests two owners sharing one new View count one card's omissions.
   */
  public function testSharedQueryCardCountsDropsOnce(): void {
    $mapping = $this->missingImageMapping();
    [$queryA, $loopA] = $this->imageLoop();
    [$queryB, $loopB] = $this->imageLoop();

    ['queries' => $queriesA] = $this->writer->writeQueryPattern('wordpal_fixture_a', 'A', [$queryA], [$loopA], $mapping, 'wordpal-fixture');
    ['queries' => $queriesB] = $this->writer->writeQueryPattern('wordpal_fixture_b', 'B', [$queryB], [$loopB], $mapping, 'wordpal-fixture');

    self::assertSame($queriesA[0]['view']->id(), $queriesB[0]['view']->id());
    self::assertSame('wordpal:post-title', $this->rowComponentId($queriesB[0]['view']), 'The shared card drops its image.');
    self::assertSame(['featured_image' => 1], $mapping->droppedBlocks());
  }

  /**
   * Tests a View saved earlier adds no card drops to a new report.
   */
  public function testExistingQueryViewAddsNoRowDrops(): void {
    [$queryA, $loopA] = $this->imageLoop();
    ['queries' => $queriesA] = $this->writer->writeQueryPattern('wordpal_fixture_a', 'A', [$queryA], [$loopA], $this->missingImageMapping(), 'wordpal-fixture');
    $mapping = $this->missingImageMapping();
    [$queryB, $loopB] = $this->imageLoop();

    ['queries' => $queriesB] = $this->writer->writeQueryPattern('wordpal_fixture_b', 'B', [$queryB], [$loopB], $mapping, 'wordpal-fixture');

    self::assertSame($queriesA[0]['view']->id(), $queriesB[0]['view']->id());
    self::assertSame('wordpal:post-title', $this->rowComponentId($queriesB[0]['view']), 'The reused card has no image.');
    self::assertSame([], $mapping->droppedBlocks());
  }

  /**
   * Tests an empty-state preset saved earlier adds no drops to a new report.
   *
   * The two cards differ, so the second write saves its own View while
   * reusing the first write's preset.
   */
  public function testExistingEmptyPresetAddsNoDrops(): void {
    $empty = fn (): array => [
      new BlockNode('core/paragraph', [], '<p>Nothing found.</p>', []),
      new BlockNode('core/tag-cloud', [], '', []),
    ];
    $first = $this->missingImageMapping();
    [$queryA, $loopA] = $this->cardLoop([new BlockNode('core/post-title', [], '', [])], $empty());
    ['queries' => $queriesA] = $this->writer->writeQueryPattern('wordpal_fixture_a', 'A', [$queryA], [$loopA], $first, 'wordpal-fixture');
    $mapping = $this->missingImageMapping();
    $card = [new BlockNode('core/post-title', [], '', []), new BlockNode('core/post-date', [], '', [])];
    [$queryB, $loopB] = $this->cardLoop($card, $empty());

    ['queries' => $queriesB] = $this->writer->writeQueryPattern('wordpal_fixture_b', 'B', [$queryB], [$loopB], $mapping, 'wordpal-fixture');

    self::assertSame(['tag' => 1], $first->droppedBlocks(), 'The first write drops the Tag Cloud from its empty state.');
    self::assertNotSame($queriesA[0]['view']->id(), $queriesB[0]['view']->id());
    self::assertSame($queriesA[0]['empty_pattern']->id(), $queriesB[0]['empty_pattern']->id());
    self::assertSame([], $mapping->droppedBlocks());
  }

  /**
   * Tests the archive Template's View replaces the site's own archive path.
   */
  public function testArchiveTemplateViewHasTheHeaderRegionAndPath(): void {
    [$query, $loop] = $this->loop(inherit: TRUE);
    $template = $this->template($query, $loop);

    ['pattern' => $pattern, 'view' => $view, 'queries' => $queries] = $this->writer->writeTemplateView('archive', $template, $this->mapping, 'wordpal-fixture', []);

    self::assertFalse($pattern->status(), 'The Template pattern saves disabled: the View renders it, not a Content Template.');
    self::assertFalse($view->status(), 'Activation enables the View.');
    self::assertSame('taxonomy/term/%', $view->getDisplay('page_1')['display_options']['path']);
    $extenderSources = $view->getDisplay('default')['display_options']['display_extenders']['display_builder']['sources'];
    self::assertSame(['view_header'], array_column($extenderSources, 'source_id'));
    self::assertSame($pattern->id(), $view->getDisplay('default')['display_options']['header']['wordpal_pattern_preset']['pattern_id']);
    $queryDisplayOptions = $queries[0]['view']->getDisplay('default')['display_options'];
    self::assertSame('taxonomy_index_tid', $queryDisplayOptions['arguments']['tid']['plugin_id'], "The archive's inheriting Query lists the route term's posts.");
  }

  /**
   * Tests a tag Template's preset is a header entry keyed by vocabulary.
   */
  public function testTagTemplateFeedsTheArchiveHeaderByVocabulary(): void {
    [$tagQuery, $tagLoop] = $this->loop(inherit: TRUE);
    $tagTemplate = $this->template($tagQuery, $tagLoop);
    ['pattern' => $tagPattern] = $this->writer->writeTermTemplate('tag', $tagTemplate, $this->mapping, 'wordpal-fixture');

    [$archiveQuery, $archiveLoop] = $this->loop(inherit: TRUE);
    $archiveTemplate = $this->template($archiveQuery, $archiveLoop);
    $termPatterns = ['tags' => $tagPattern->id()];
    ['view' => $view] = $this->writer->writeTemplateView('archive', $archiveTemplate, $this->mapping, 'wordpal-fixture', $termPatterns);

    $header = $view->getDisplay('default')['display_options']['header'];
    self::assertSame($tagPattern->id(), $header['wordpal_pattern_preset_tags']['pattern_id']);
    self::assertSame(['tags'], $header['wordpal_pattern_preset_tags']['vocabularies']);
    self::assertTrue($header['wordpal_pattern_preset']['negate'], 'The archive Template renders only for a vocabulary no term Template claims.');
  }

  /**
   * Tests the search Template's View reads the mapped search index.
   */
  public function testSearchTemplateViewReadsTheMappedSearchIndex(): void {
    Server::create([
      'id' => 'wordpal_search_server',
      'name' => 'Server',
      'backend' => 'search_api_db',
      'backend_config' => ['database' => 'default:default'],
    ])->save();
    Index::create([
      'id' => 'wordpal_content',
      'name' => 'Content',
      'server' => 'wordpal_search_server',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
    ])->save();

    [$query, $loop] = $this->loop(inherit: TRUE);
    $template = $this->template($query, $loop);
    $written = $this->writer->writeTemplateView('search', $template, $this->mapping, 'wordpal-fixture', []);
    ['view' => $pageView, 'queries' => $queries] = $written;

    self::assertSame('search', ltrim($pageView->getDisplay('page_1')['display_options']['path'], '/'));
    $message = "The Query's own View reads the mapped search index, not node_field_data.";
    self::assertSame('search_api_index_wordpal_content', $queries[0]['view']->get('base_table'), $message);
  }

  /**
   * Tests a search Query View renders matching published nodes as cards.
   *
   * The index has no entity_status processor, so the unpublished match
   * reaches the View's results: Search API's own per-result entity access
   * check, on by default, drops it for this visitor. The published
   * match renders through the ui_patterns row, which reads the entity
   * Search API sets on each result row.
   */
  public function testSearchQueryViewRendersPublishedMatchesThroughTheCard(): void {
    $this->config('views.settings')->set('display_extenders', ['display_builder'])->save();
    $this->setUpCurrentUser([], ['access content']);
    Server::create([
      'id' => 'wordpal_search_server',
      'name' => 'Server',
      'backend' => 'search_api_db',
      'backend_config' => ['database' => 'default:default'],
    ])->save();
    $index = Index::create([
      'id' => 'wordpal_content',
      'name' => 'Content',
      'server' => 'wordpal_search_server',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
      'field_settings' => [
        'title' => ['label' => 'Title', 'datasource_id' => 'entity:node', 'property_path' => 'title', 'type' => 'text'],
      ],
    ]);
    $index->save();
    Node::create(['type' => 'wordpal_post', 'title' => 'A walk in the city', 'status' => 1])->save();
    Node::create(['type' => 'wordpal_post', 'title' => 'A walk at night', 'status' => 0])->save();
    $index->indexItems();

    [$query, $loop] = $this->loop(inherit: TRUE);
    ['queries' => $queries] = $this->writer->writeTemplateView('search', $this->template($query, $loop), $this->mapping, 'wordpal-fixture', []);
    $view = Views::getView($queries[0]['view']->id());
    $view->setDisplay('block_1');
    $view->setExposedInput(['keywords' => 'walk']);
    $build = $view->preview();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertCount(1, $view->result, 'Only the published match survives the access check.');
    self::assertMatchesRegularExpression('/data-component-id="wordpal:post-title"[^>]*>.*A walk in the city/s', $html, 'The card renders the result entity\'s title.');
    self::assertStringNotContainsString('A walk at night', $html);

    $this->setUpCurrentUser([], ['access content', 'bypass node access']);
    $editorView = Views::getView($queries[0]['view']->id());
    $editorView->setDisplay('block_1');
    $editorView->setExposedInput(['keywords' => 'walk']);
    $editorView->execute();
    self::assertCount(2, $editorView->result, 'The index holds the unpublished match too.');
  }

  /**
   * Tests a replace keeps the template View's deterministic UUID.
   *
   * The View's id is deterministic, so writeTemplateView() always creates a
   * fresh object for the same id rather than loading-and-reusing it the way
   * saveQuery() does; a random UUID here would collide with the config
   * entity's existing UUID once ConversionRunner's replace deletes and
   * recreates it. The deletes below stand in for
   * ConversionRunner::prepare()'s $this->ownership->delete($themeId) call,
   * which a real replace runs before writing the new conversion's outputs.
   */
  public function testTemplateViewKeepsDeterministicUuidOnReplace(): void {
    [$query, $loop] = $this->loop(inherit: TRUE);
    ['pattern' => $firstPattern, 'view' => $firstView] = $this->writer->writeTemplateView('archive', $this->template($query, $loop), $this->mapping, 'wordpal-fixture', []);
    $uuidBefore = $firstView->uuid();
    $firstPattern->delete();
    $firstView->delete();

    [$query, $loop] = $this->loop(inherit: TRUE);
    ['view' => $secondView] = $this->writer->writeTemplateView('archive', $this->template($query, $loop), $this->mapping, 'wordpal-fixture', []);

    self::assertSame($uuidBefore, $secondView->uuid(), "Replace must keep the template View's deterministic UUID.");
  }

  /**
   * Tests templateOutputIds() and queryOutputIds() are stable across calls.
   */
  public function testOutputIdsAreStable(): void {
    $listingWriter = $this->container->get(ListingWriter::class);
    [, $loop] = $this->loop();

    $first = $listingWriter->templateOutputIds('wordpal-fixture', 'archive');
    $second = $listingWriter->templateOutputIds('wordpal-fixture', 'archive');
    self::assertSame($first, $second);

    $firstQuery = $listingWriter->queryOutputIds('wordpal-fixture', $loop, 'wordpal_post');
    $secondQuery = $listingWriter->queryOutputIds('wordpal-fixture', $loop, 'wordpal_post');
    self::assertSame($firstQuery, $secondQuery);
  }

}
