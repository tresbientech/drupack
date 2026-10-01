<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\display_builder\Entity\PatternPreset;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\display_builder_page_layout\Entity\PageLayout;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\ConversionRunner;
use Drupal\wordpal_convert\TemplatePage;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal_convert\WordPress\PlaygroundRunner;
use Drupal\wordpal_convert\WordPress\Snapshot;
use Drupal\wordpal_display_builder\DisplayBuilderWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Route;

/**
 * Tests a display_builder conversion writes patterns, frames, home and single.
 *
 * The fixture's home and single Templates each hold their own header and
 * footer Part set (a different footer slug apiece), so both convert to
 * their own page_layout. The fixture's "Latest posts" pattern holds a Query
 * loop, converting to a preset that places the Query's own View as its
 * block source.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class DisplayBuilderConversionTest extends KernelTestBase {

  use FixtureConversionTrait;

  /**
   * The Drupal machine name of the fixture theme.
   */
  private const THEME_ID = 'wordpal_fixture';

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'wordpal_convert', 'wordpal_display_builder', 'wordpal_display_builder_runtime', 'display_builder',
    'display_builder_page_layout', 'display_builder_entity_view', 'display_builder_views',
    'ui_patterns', 'ui_patterns_field', 'ui_patterns_views', 'system', 'user', 'field', 'node',
    'text', 'file', 'image', 'views', 'path', 'path_alias', 'block',
    'comment', 'filter', 'taxonomy', 'link', 'menu_link_content',
  ];

  /**
   * Isolated generated-theme root.
   */
  private string $themeRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('menu_link_content');
    $this->installEntitySchema('display_builder_instance');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['field', 'filter', 'node', 'user', 'views', 'ui_patterns', 'display_builder']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    // The checked-in mapping binds post_body to "body"; writeRuntimePresets()
    // builds a Post Content preset for the bundle whenever that concept is
    // bound, so the field must exist the same as it would on a real site the
    // mapping validator already checked.
    FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_long'])->save();
    FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'wordpal_post'])->save();
    FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'wordpal_page'])->save();
    $this->themeRoot = sys_get_temp_dir() . '/wordpal-convert-' . bin2hex(random_bytes(6)) . '/web';
    mkdir($this->themeRoot, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->themeRoot)) {
      (new Filesystem())->remove(dirname($this->themeRoot));
    }
    parent::tearDown();
  }

  /**
   * Returns the fixture snapshot with a 404 and a page Template added.
   *
   * The 404 Template has its own Part set, built from parts the other
   * Templates never use. The page Template shares single's parts, and its
   * body gives Drupal-owned routes their frame.
   */
  private function snapshotWithNotFoundAndPageTemplates(): Snapshot {
    $directory = $this->mirroredFixtureDirectory();
    file_put_contents(
      $directory . '/out/templates/404.html',
      '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->' .
      '<!-- wp:group {"tagName":"main"} --><main><!-- wp:paragraph --><p>Page not found.</p><!-- /wp:paragraph --></main><!-- /wp:group -->' .
      '<!-- wp:template-part {"slug":"footer-404","area":"footer","tagName":"footer"} /-->'
    );
    file_put_contents($directory . '/out/parts/footer-404.html', '<!-- wp:paragraph --><p>Site footer.</p><!-- /wp:paragraph -->');
    file_put_contents(
      $directory . '/out/templates/page.html',
      '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->' .
      '<!-- wp:group {"tagName":"main"} --><main><!-- wp:post-title {"level":1} /--><!-- wp:post-date /--><!-- wp:post-content /--></main><!-- /wp:group -->' .
      '<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->'
    );
    // The renderer looks up every template and part's rendered blocks by
    // this index; an entry-less file fails to parse as unrendered.
    $renderIndex = json_decode(file_get_contents($directory . '/out/render-index.json'), TRUE);
    $renderIndex['templates/404.html'] = [];
    $renderIndex['templates/page.html'] = [];
    $renderIndex['parts/footer-404.html'] = [];
    file_put_contents($directory . '/out/render-index.json', json_encode($renderIndex));
    $version = $this->container->get(ComponentSet::class)->wordPressVersion();
    return $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $version);
  }

  /**
   * Returns the fixture mapping, with the page concept bound too.
   *
   * The checked-in fixture mapping binds only what the fixture's patterns
   * and Templates read; this target's home Template additionally needs a
   * page bundle to hold its node, so this copy adds one.
   */
  private function mappingWithPage(): string {
    $path = $this->themeRoot . '/mapping-with-page.yml';
    $mapping = file_get_contents($this->mappingPath()) . "\npage: wordpal_page\npage_body: body\n";
    file_put_contents($path, $mapping);
    return $path;
  }

  /**
   * Tests the report names the Display Builder path of a listing Template.
   */
  public function testReportNamesWhereTheDisplayBuilderEditsListingTemplates(): void {
    $directory = $this->mirroredFixtureDirectory();
    file_put_contents(
      $directory . '/out/templates/archive.html',
      '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->' .
      '<!-- wp:group {"tagName":"main"} --><main><!-- wp:query {"query":{"perPage":3,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query --></main><!-- /wp:group -->' .
      '<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->'
    );
    $renderIndex = json_decode(file_get_contents($directory . '/out/render-index.json'), TRUE);
    $renderIndex['templates/archive.html'] = ['0' => ''];
    file_put_contents($directory . '/out/render-index.json', json_encode($renderIndex));
    $snapshot = $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $this->container->get(ComponentSet::class)->wordPressVersion());

    $report = $this->convert($snapshot, $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');

    self::assertSame('the Display Builder', $report->targetLabel);
    self::assertSame(['/admin/structure/views/view/wordpal_wordpal_fixture_archive/display-builder/default'], $report->templatePaths);
  }

  /**
   * Tests patterns, the three frames, the home page and single's display.
   */
  public function testPatternsFramesHomeAndSingleFullDisplayConvert(): void {
    $report = $this->convert($this->snapshotWithNotFoundAndPageTemplates(), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');

    self::assertNotNull(PatternPreset::load(ConversionRunner::patternId('wordpal-fixture', 'wordpal-fixture/welcome')), 'A loop-free pattern must convert to a preset.');

    $frames = self::createdIdsOfType($report->created, 'page_layout');
    self::assertCount(4, $frames, "Each Part set converts to its own frame, and Drupal-owned routes get one more.");
    $layouts = [];
    foreach ($frames as $id) {
      $layout = PageLayout::load($id);
      self::assertNotEmpty($layout->getSources(), 'Each frame holds its template parts and the main content.');
      self::assertStringContainsString('"plugin_id":"system_messages_block"', json_encode($layout->getSources()), 'Each frame places the status messages.');
      $routes = $layout->getConditions()->getConfiguration()['wordpal_frame_routes'] ?? NULL;
      $key = match (TRUE) {
        $routes === NULL => 'drupal',
        $routes['pages'] === '<front>' => 'home',
        $routes['bundles'] !== [] => 'single',
        default => 'not_found',
      };
      $layouts[$key] = $layout;
    }
    ksort($layouts);
    self::assertSame(['drupal', 'home', 'not_found', 'single'], array_keys($layouts));
    self::assertSame(-30, $layouts['home']->get('weight'), "Home's frame carries the lowest weight, so it wins first.");
    $single = $layouts['single']->getConditions()->getConfiguration()['wordpal_frame_routes'];
    self::assertSame(['wordpal_post', 'wordpal_page'], $single['bundles'], 'Single and page share one frame, conditioned on both bundles.');
    self::assertSame('', $single['pages']);
    self::assertSame(-10, $layouts['single']->get('weight'), "A bundle-only frame carries the bundle-wide weight.");
    $notFound = $layouts['not_found']->getConditions()->getConfiguration()['wordpal_frame_routes'];
    self::assertSame(TemplatePage::alias(self::THEME_ID, 'not_found'), $notFound['pages'], "The 404 frame is conditioned on the 404 page's path.");
    self::assertSame(-20, $layouts['not_found']->get('weight'), 'A path beats the page bundle the 404 node also belongs to.');

    $sources = json_encode($layouts['drupal']->getSources());
    self::assertStringContainsString('"plugin_id":"wordpal_page_title"', $sources, 'Post Title shows the route title.');
    self::assertStringContainsString('"source_id":"main_page_content"', $sources, 'Post Content holds the main content.');
    self::assertStringNotContainsString('post-date', $sources, 'A block that prints the post is left out.');
    self::assertStringNotContainsString('The route begins by the river', $sources, "Post Content shows no sample post, only the main content.");
    self::assertArrayNotHasKey('wordpal', $report->unregistered, "The frame's stand-in blocks are no WordPress blocks to register.");
    self::assertSame([], array_filter($report->skipped, static fn (string $line): bool => str_contains($line, 'frame')), 'Every frame gets its condition.');

    $themeId = ThemeGenerator::themeId('wordpal-fixture');
    $home = $this->container->get('entity.repository')->loadEntityByUuid('node', TemplatePage::uuid($themeId, 'home'));
    self::assertInstanceOf(Node::class, $home);
    self::assertSame('wordpal_page', $home->bundle());
    self::assertNotEmpty($home->get('field_display_full')->getValue(), 'The home node carries the Template body in the override field.');
    $site = $this->container->get('config.factory')->get('system.site');
    self::assertSame(TemplatePage::alias($themeId, 'home'), $site->get('page.front'), "Activation must point the front page at the home node's alias.");

    $singleDisplay = $this->container->get('entity_display.repository')->getViewDisplay('node', 'wordpal_post', 'full');
    self::assertSame('default', $singleDisplay->getThirdPartySetting('display_builder', 'profile'), "The single Template's bundle gets a Display Builder full display.");
    self::assertNotEmpty($singleDisplay->getThirdPartySetting('display_builder', 'sources'), 'The full display carries the single Template body.');

    $recipe = $this->recipe();
    self::assertSame('wordpal_display_builder_runtime', $this->container->get(DisplayBuilderWriter::class)->runtimeModule());
    self::assertContains('wordpal_display_builder_runtime', $recipe['install'], "The Site recipe installs the target's own runtime module.");
    self::assertNotContains('wordpal_display_builder', $recipe['install'], 'The Site recipe never installs the converter.');
    self::assertNotContains('wordpal_convert', $recipe['install'], 'The Site recipe never installs the converter.');
    foreach ($frames as $frameId) {
      self::assertFileExists($this->recipeDirectory() . "/config/display_builder_page_layout.page_layout.$frameId.yml", 'The recipe exports each page layout.');
    }
    self::assertFileExists($this->recipeDirectory() . '/config/field.storage.node.field_display_full.yml', 'The recipe exports the override field storage.');
    self::assertFileExists($this->recipeDirectory() . '/config/field.field.node.wordpal_page.field_display_full.yml', 'The recipe exports the override field on the page bundle.');
    self::assertFileExists($this->recipeDirectory() . '/config/core.entity_view_display.node.wordpal_page.full.yml', 'The recipe exports the page bundle full display.');
    self::assertFileExists($this->recipeDirectory() . '/config/core.entity_view_display.node.wordpal_post.full.yml', 'The recipe exports the single Template full display.');
    self::assertFileExists($this->recipeDirectory() . '/content/node/' . $home->uuid() . '.yml', 'The recipe exports the home node.');

    self::assertNotNull(PatternPreset::load(ConversionRunner::patternId('wordpal-fixture', 'wordpal-fixture/latest')), 'The Query pattern converts through ListingWriter, not skipped.');
    $viewIds = self::createdIdsOfType($report->created, 'view');
    self::assertCount(1, $viewIds, "The Query pattern's View is created.");
    self::assertSame('wordpal_query', View::load($viewIds[0])->get('tag'));
    self::assertEmpty($report->skippedPatterns, 'Every fixture pattern converts on this target now.');
  }

  /**
   * Tests a page Template's sources join an existing override-field display.
   *
   * The page bundle's full display carries the home node's override field
   * (savePageNode()'s ensureOverrideField()) and, once a page Template also
   * maps to that bundle, that Template's own sources. The Site recipe must
   * force both onto a production site's pre-existing display, not only the
   * override field.
   */
  public function testPageTemplateSourcesJoinTheOverrideFieldDisplay(): void {
    $page = '<!-- wp:paragraph --><p>Page area.</p><!-- /wp:paragraph -->';
    $this->convert($this->snapshotWithTemplates(['page' => $page]), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');

    $displayName = 'core.entity_view_display.node.wordpal_page.full';
    $display = $this->container->get('entity_display.repository')->getViewDisplay('node', 'wordpal_page', 'full');
    self::assertNotEmpty($display->getThirdPartySetting('display_builder', 'override_field'), "The page bundle's display still carries the static pages' override field.");
    self::assertNotEmpty($display->getThirdPartySetting('display_builder', 'sources'), "The page Template's own sources join the same display.");

    $settings = [];
    foreach ($this->recipe()['config']['actions'][$displayName]['setThirdPartySettings'] as $setting) {
      self::assertSame('display_builder', $setting['module']);
      $settings[$setting['key']] = $setting['value'];
    }
    self::assertSame($display->getThirdPartySetting('display_builder', 'sources'), $settings['sources'], 'The recipe forces the page Template sources onto an existing display.');
    self::assertSame($display->getThirdPartySetting('display_builder', 'override_field'), $settings['override_field'], 'The recipe still forces the override field.');
    self::assertSame($display->getThirdPartySetting('display_builder', 'override_profile'), $settings['override_profile']);
    self::assertSame($display->getThirdPartySetting('display_builder', 'profile'), $settings['profile']);
  }

  /**
   * Tests --no-activate writes outputs without touching site activation.
   *
   * ConversionRunner::write() only wires system.site's front and 404 pages
   * when $activate is TRUE (self::WIRING vs. the empty $conversion->wired a
   * fresh, non-replace conversion carries); the 'frame' wiring key never
   * applies to this target regardless of $activate (writeOutputs() sets
   * $frame to NULL whenever the target is not "canvas", since a page
   * layout's condition-less fallback needs no config write), so a page
   * layout's enabled status is set once, at creation, by
   * DisplayBuilderWriter::writePageVariant() alone.
   */
  public function testNoActivateLeavesSiteActivationUntouched(): void {
    $siteBefore = $this->config('system.site');
    $frontBefore = $siteBefore->get('page.front');
    $notFoundBefore = $siteBefore->get('page.404');
    $themeBefore = $this->config('system.theme')->get('default');

    $report = $this->convert($this->snapshotWithTemplates([]), $this->mappingWithPage(), FALSE, FALSE, FALSE, 'display_builder');

    $frames = self::createdIdsOfType($report->created, 'page_layout');
    self::assertNotEmpty($frames, 'The conversion still writes its page layouts.');
    self::assertSame($frontBefore, $this->config('system.site')->get('page.front'), '--no-activate leaves the front page untouched.');
    self::assertSame($notFoundBefore, $this->config('system.site')->get('page.404'), '--no-activate leaves the 404 page untouched.');
    self::assertSame($themeBefore, $this->config('system.theme')->get('default'), '--no-activate leaves the default theme untouched.');
    foreach ($frames as $frameId) {
      self::assertTrue(PageLayout::load($frameId)->status(), "A page layout's enabled status does not depend on activation.");
    }
  }

  /**
   * Tests a node without a converted Template falls back to the default frame.
   *
   * A kernel test has no route match to drive the frames' conditions, so
   * this exercises the selection rule itself: the frame of Drupal-owned
   * routes carries no condition, and loadCurrentPageLayout() returns it for
   * a node whose bundle no converted Template names.
   */
  public function testNodeWithoutTemplateFallsBackToTheDefaultFrame(): void {
    $report = $this->convert($this->snapshotWithNotFoundAndPageTemplates(), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');
    self::assertNotEmpty(self::createdIdsOfType($report->created, 'page_layout'));

    NodeType::create(['type' => 'wordpal_other', 'name' => 'Unmapped bundle'])->save();
    $node = Node::create(['type' => 'wordpal_other', 'title' => 'No Template maps here', 'status' => TRUE]);
    $node->save();
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($node, 'full');
    self::assertNotEmpty((string) $this->container->get('renderer')->renderInIsolation($build), 'A node outside the Content mapping still renders through the ordinary view builder.');

    $layout = $this->container->get('entity_type.manager')->getAccessControlHandler('page_layout')->loadCurrentPageLayout();
    self::assertInstanceOf(PageLayout::class, $layout, 'A condition-less page layout is always available as the fallback.');
    self::assertCount(0, $layout->getConditions(), "The frame display_builder falls back to for an unmapped node's route carries no conditions.");
  }

  /**
   * Tests the fallback frame prints its header and footer around the content.
   *
   * The page variant builds the frame selected by loadCurrentPageLayout()
   * for a route no converted Template names. Its output holds the frame's
   * header and footer Parts around the routed main content.
   */
  public function testDefaultFrameOutputHoldsHeaderFooterAndMainContent(): void {
    $this->convert($this->snapshotWithNotFoundAndPageTemplates(), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');
    $this->container->get('theme.manager')->setActiveTheme(new ActiveTheme(['name' => self::THEME_ID, 'engine' => NULL]));

    $variant = $this->container->get('plugin.manager.display_variant')->createInstance('display_builder_page_layout');
    $variant->setMainContent(['#markup' => 'Unmapped node body.']);
    $variant->setTitle('Unmapped node');
    $html = (string) $this->container->get('renderer')->renderInIsolation($variant->build());

    $header = strpos($html, 'wp-block-template-part');
    $main = strpos($html, 'Unmapped node body.');
    $footer = strrpos($html, 'wp-block-template-part');
    self::assertNotFalse($header, 'The frame prints a Part.');
    self::assertNotSame($header, $footer, 'The frame prints two Parts.');
    self::assertNotFalse($main, 'The routed main content sits inside the frame.');
    self::assertTrue($header < $main && $main < $footer, 'The frame prints a Part before and a Part after the main content.');
    $fallback = $this->container->get('entity_type.manager')->getAccessControlHandler('page_layout')->loadCurrentPageLayout();
    self::assertSame(0, $fallback->getConditions()->count(), 'The frame selected for the route is the no-condition fallback.');
  }

  /**
   * Tests a converted frame renders only under the theme it was converted for.
   */
  public function testFrameNeedsItsConvertedTheme(): void {
    $this->convert($this->snapshotWithNotFoundAndPageTemplates(), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');

    self::assertSame('display_builder_page_layout', $this->selectPageVariant(self::THEME_ID));
    self::assertSame('block_page', $this->selectPageVariant('claro'));
  }

  /**
   * Returns the page display variant selected under an active theme.
   */
  private function selectPageVariant(string $theme): string {
    $this->container->get('theme.manager')->setActiveTheme(new ActiveTheme(['name' => $theme, 'engine' => NULL]));
    $event = new PageDisplayVariantSelectionEvent('simple_page', new RouteMatch('wordpal.test', new Route('/wordpal-test')));
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    return $event->getPluginId();
  }

  /**
   * Tests a basic page node renders through the page Template.
   *
   * The page bundle's full display carries the page Template's own sources
   * alongside the static pages' override field
   * (testPageTemplateSourcesJoinTheOverrideFieldDisplay), and
   * EntityViewDisplayTrait::buildMultiple() falls back to the display's own
   * sources for any node whose own override field is empty, so a plain
   * page node with no override renders through the shared Template rather
   * than needing one of its own.
   */
  public function testBasicPageRendersThroughThePageTemplate(): void {
    $page = '<!-- wp:post-title /--><!-- wp:paragraph --><p>Page area.</p><!-- /wp:paragraph -->';
    $this->convert($this->snapshotWithTemplates(['page' => $page]), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');

    $node = Node::create([
      'type' => 'wordpal_page',
      'title' => 'A basic page',
      'body' => ['value' => '<p>Body copy.</p>', 'format' => 'plain_text'],
      'status' => TRUE,
    ]);
    $node->save();

    $display = $this->container->get('entity_display.repository')->getViewDisplay('node', 'wordpal_page', 'full');
    $sources = $display->getThirdPartySetting('display_builder', 'sources');
    self::assertNotEmpty($sources, "The bundle's full display holds the page Template.");
    self::assertSame('wordpal:post-title', $sources[0]['source']['component']['component_id'], "The page Template's first component is Post Title.");

    $build = $display->buildMultiple([$node->id() => $node]);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build[$node->id()]['content']);
    self::assertStringContainsString('A basic page', $html, "Rendering through the display binds the node's title.");
  }

  /**
   * Tests a refused conversion leaves another theme's full displays owned.
   *
   * The second theme maps the same bundles, so activation would take over
   * the first theme's full displays. An unmanaged config object in its way
   * refuses the conversion before any manifest changes.
   */
  public function testRefusedConversionKeepsFullDisplayOwner(): void {
    $this->convert($this->snapshotWithNotFoundAndPageTemplates(), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');
    $fullDisplays = $this->manifest()['full_displays'];
    self::assertNotSame([], $fullDisplays);
    $this->config('wordpal.theme_settings.other_theme')->setData($this->config('wordpal.theme_settings.' . self::THEME_ID)->getRawData())->save();

    try {
      $this->convert($this->themeSnapshot('other-theme'), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');
      self::fail('An unmanaged output in the way must refuse the conversion.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('wordpal.theme_settings.other_theme', $exception->getMessage());
    }

    self::assertSame($fullDisplays, $this->manifest()['full_displays']);
  }

  /**
   * Tests --replace keeps a deterministic UUID for a preset and a View.
   */
  public function testReplaceKeepsDeterministicUuids(): void {
    $report = $this->convert($this->snapshotWithNotFoundAndPageTemplates(), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');
    $presetId = ConversionRunner::patternId('wordpal-fixture', 'wordpal-fixture/welcome');
    $presetUuidBefore = PatternPreset::load($presetId)->uuid();
    $viewId = self::createdIdsOfType($report->created, 'view')[0];
    $viewUuidBefore = View::load($viewId)->uuid();
    $frameId = self::createdIdsOfType($report->created, 'page_layout')[0];
    $conditionsBefore = PageLayout::load($frameId)->getConditions()->getConfiguration();

    $this->convert($this->snapshotWithNotFoundAndPageTemplates(), $this->mappingWithPage(), TRUE, FALSE, TRUE, 'display_builder');

    self::assertSame($presetUuidBefore, PatternPreset::load($presetId)->uuid(), "Replace must keep the pattern preset's deterministic UUID.");
    self::assertSame($viewUuidBefore, View::load($viewId)->uuid(), "Replace must keep the view's deterministic UUID.");
    self::assertSame($conditionsBefore, PageLayout::load($frameId)->getConditions()->getConfiguration(), 'A reconversion keeps the same frame conditions.');
  }

  /**
   * Tests --replace leaves no preset of a pattern the theme no longer has.
   *
   * The second snapshot keeps only the Welcome pattern. The Site recipe holds
   * one pattern_preset config file per written preset, before and after.
   */
  public function testReplaceLeavesNoOrphanPresetAndTheRecipeHoldsEachPreset(): void {
    $first = $this->convert($this->snapshotWithNotFoundAndPageTemplates(), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');
    $welcome = ConversionRunner::patternId('wordpal-fixture', 'wordpal-fixture/welcome');
    $spotlight = ConversionRunner::patternId('wordpal-fixture', 'wp-pattern-directory/spotlight');
    $firstIds = self::createdIdsOfType($first->created, 'pattern_preset');
    self::assertContains($welcome, $firstIds);
    self::assertContains($spotlight, $firstIds);
    self::assertGreaterThan(1, count($firstIds));
    sort($firstIds);
    self::assertSame($firstIds, $this->recipePresetIds(), 'The recipe holds one config file per written preset.');

    $directory = $this->mirroredFixtureDirectory();
    $patterns = json_decode(file_get_contents("$directory/out/patterns.json"), TRUE);
    $onlyWelcome = ['wordpal-fixture--welcome' => $patterns['wordpal-fixture--welcome']];
    file_put_contents("$directory/out/patterns.json", json_encode($onlyWelcome));
    $version = $this->container->get(ComponentSet::class)->wordPressVersion();
    $snapshot = $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $version);
    $second = $this->convert($snapshot, $this->mappingWithPage(), TRUE, FALSE, TRUE, 'display_builder');

    $secondIds = self::createdIdsOfType($second->created, 'pattern_preset');
    sort($secondIds);
    self::assertContains($welcome, $secondIds);
    self::assertNotContains($spotlight, $secondIds);
    $stored = array_keys(PatternPreset::loadMultiple());
    sort($stored);
    self::assertSame($secondIds, $stored, 'No preset of a pattern the theme dropped is left.');
    self::assertNull(PatternPreset::load($spotlight));
    self::assertSame($secondIds, $this->recipePresetIds(), 'The recipe holds exactly the written presets.');
  }

  /**
   * Tests a preset holds a Frozen block and a Navigation block, and renders.
   *
   * The Audio block has no WordPal component here, so the pattern freezes the
   * HTML the render index holds for it. The frozen component prints that file
   * from the default theme's path, and this kernel site resolves the theme
   * name to wordpal_canvas' stand-in theme, so the printed HTML is asserted
   * live and in wordpal_canvas' FrozenBlocksTest. Here the preset must hold
   * the frozen source keyed to the HTML, the generated theme must hold that
   * file, and the Navigation block must render its own markup.
   */
  public function testPresetHoldsFrozenBlockAndRendersNavigation(): void {
    $directory = $this->mirroredFixtureDirectory();
    $patterns = json_decode(file_get_contents("$directory/out/patterns.json"), TRUE);
    $patterns['wordpal-fixture--media'] = [
      'slug' => 'wordpal-fixture/media',
      'title' => 'Media',
      'categories' => ['featured'],
      'inserter' => TRUE,
    ];
    file_put_contents("$directory/out/patterns.json", json_encode($patterns));
    file_put_contents("$directory/out/patterns/wordpal-fixture--media.html", '<!-- wp:audio --><figure class="wp-block-audio"><audio controls src="/sites/default/files/fixture.mp3"></audio></figure><!-- /wp:audio --><!-- wp:navigation /-->');
    $audio = '<figure class="wp-block-audio"><audio controls src="/sites/default/files/fixture.mp3"></audio></figure>';
    $index = json_decode(file_get_contents("$directory/out/render-index.json"), TRUE);
    $index['patterns/wordpal-fixture--media.html'] = ['0' => $audio];
    file_put_contents("$directory/out/render-index.json", json_encode($index));
    $blocks = json_decode(file_get_contents("$directory/out/blocks.json"), TRUE);
    $blocks['core/navigation'] = [];
    file_put_contents("$directory/out/blocks.json", json_encode($blocks));
    $version = $this->container->get(ComponentSet::class)->wordPressVersion();
    $snapshot = $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $version);
    $mapping = $this->themeRoot . '/mapping-with-navigation.yml';
    file_put_contents($mapping, file_get_contents($this->mappingWithPage()) . "navigation: main\n");
    $this->convert($snapshot, $mapping, FALSE, FALSE, TRUE, 'display_builder');

    $id = ConversionRunner::patternId('wordpal-fixture', 'wordpal-fixture/media');
    $sources = json_encode(PatternPreset::load($id)->getSources());
    $key = FrozenBlock::key($audio);
    self::assertStringContainsString('"component_id":"wordpal:frozen-html"', $sources);
    self::assertStringContainsString($key, $sources, 'The frozen source is keyed to the HTML WordPress rendered.');
    self::assertFileExists($this->themeRoot . '/themes/custom/wordpal_fixture/' . FrozenBlock::path($key));
    self::assertStringContainsString('"plugin_id":"wordpal_navigation"', $sources);
    $build = $this->container->get(DisplayBuilderWriter::class)->renderPattern($id);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertStringContainsString('wp-block-navigation', $html, 'The navigation block prints its own markup.');
  }

  /**
   * Returns the preset ids the Site recipe holds, sorted.
   *
   * @return string[]
   *   The ids.
   */
  private function recipePresetIds(): array {
    $ids = array_map(static fn (string $path): string => substr(basename($path, '.yml'), strlen('display_builder.pattern_preset.')), glob($this->recipeDirectory() . '/config/display_builder.pattern_preset.*.yml'));
    sort($ids);
    return $ids;
  }

  /**
   * Tests a page Template drops a Post Featured Image its bundle can't hold.
   *
   * The image field is mapped and exists on the post bundle, so the mapping
   * itself is sound; wordpal_page just never got that field, the same gap
   * Twenty Twenty-Five's page.html hits against a page bundle with no image
   * field. Post Title stays, proving the template writes rather than skips.
   */
  public function testPageDropsFeaturedImageWithNoBundleField(): void {
    $snapshot = $this->templateSnapshot([
      'page' => '<!-- wp:post-title /--><!-- wp:post-featured-image /-->',
    ]);
    FieldStorageConfig::create(['field_name' => 'field_image', 'entity_type' => 'node', 'type' => 'image'])->save();
    FieldConfig::create(['field_name' => 'field_image', 'entity_type' => 'node', 'bundle' => 'wordpal_post'])->save();
    $mapping = $this->themeRoot . '/mapping-page-image.yml';
    file_put_contents($mapping, file_get_contents($this->mappingPath()) . "\npage: wordpal_page\nfeatured_image: field_image\n");

    $report = $this->convert($snapshot, $mapping, FALSE, FALSE, FALSE, 'display_builder');

    $pageDisplay = $this->container->get('entity_display.repository')->getViewDisplay('node', 'wordpal_page', 'full');
    $tree = $pageDisplay->getThirdPartySetting('display_builder', 'sources');
    self::assertCount(1, $tree, 'Post Featured Image is dropped, Post Title is written.');
    self::assertSame('wordpal:post-title', $tree[0]['source']['component']['component_id']);
    self::assertSame(1, $report->dropped['featured_image'] ?? NULL, 'The dropped block is named in the report.');
  }

  /**
   * Tests the home and 404 nodes fill a required page excerpt field.
   *
   * Drupal CMS requires its page bundle's description, and the Site recipe
   * leaves out a node that fails validation.
   */
  public function testPageNodesCarryTheTemplateDescription(): void {
    FieldStorageConfig::create(['field_name' => 'field_description', 'entity_type' => 'node', 'type' => 'string_long'])->save();
    FieldConfig::create([
      'field_name' => 'field_description',
      'entity_type' => 'node',
      'bundle' => 'wordpal_page',
      'required' => TRUE,
    ])->save();
    $mapping = $this->themeRoot . '/mapping-page-excerpt.yml';
    file_put_contents($mapping, file_get_contents($this->mappingWithPage()) . "page_excerpt: field_description\n");

    $this->convert($this->snapshotWithNotFoundAndPageTemplates(), $mapping, FALSE, FALSE, TRUE, 'display_builder');

    $themeId = ThemeGenerator::themeId('wordpal-fixture');
    $entityRepository = $this->container->get('entity.repository');
    foreach (TemplatePage::ROUTES as $route) {
      $node = $entityRepository->loadEntityByUuid('node', TemplatePage::uuid($themeId, $route));
      self::assertSame(TemplatePage::description($route), $node->get('field_description')->value, "The $route node carries its Template's description.");
      self::assertCount(0, $node->validate(), "The $route node validates.");
    }
  }

  /**
   * Tests the override field is reused, and the node rewritten, on --replace.
   *
   * DisplayBuilderWriter::ensureOverrideField() loads the field storage and
   * instance by name before creating either, so a second conversion reuses
   * both rather than duplicating them; ConversionOwnership never tracks
   * that field, so --replace's delete() cannot touch it either. The home
   * node itself is not reused the same way: delete() deletes every page in
   * the manifest's "pages" list outright, so savePageNode() recreates it
   * with the same deterministic UUID and a tree rebuilt from the snapshot,
   * not the same field-value storage carried over. The rebuilt tree equals
   * the original except for each unset prop's own "node_id": ui_patterns
   * assigns those itself, outside SourceTreeBuilder::nodeId()'s hash, fresh
   * on every save, so the comparison strips them first.
   */
  public function testOverrideFieldIsReusedOnSecondConversion(): void {
    $this->convert($this->snapshotWithTemplates([]), $this->mappingWithPage(), FALSE, FALSE, TRUE, 'display_builder');
    $storageBefore = FieldStorageConfig::loadByName('node', 'field_display_full');
    $fieldBefore = FieldConfig::loadByName('node', 'wordpal_page', 'field_display_full');
    self::assertNotNull($storageBefore, 'The override field storage is created on the first conversion.');
    self::assertNotNull($fieldBefore, 'The override field instance is created on the first conversion.');
    $storageUuidBefore = $storageBefore->uuid();
    $fieldUuidBefore = $fieldBefore->uuid();
    $themeId = ThemeGenerator::themeId('wordpal-fixture');
    $entityRepository = $this->container->get('entity.repository');
    $treeBefore = $entityRepository->loadEntityByUuid('node', TemplatePage::uuid($themeId, 'home'))->get('field_display_full')->getValue();

    $this->convert($this->snapshotWithTemplates([]), $this->mappingWithPage(), TRUE, FALSE, TRUE, 'display_builder');

    $storageStorage = $this->container->get('entity_type.manager')->getStorage('field_storage_config');
    $fieldStorage = $this->container->get('entity_type.manager')->getStorage('field_config');
    self::assertCount(1, $storageStorage->loadByProperties(['field_name' => 'field_display_full']), 'The override field storage exists once.');
    $instances = $fieldStorage->loadByProperties(['field_name' => 'field_display_full', 'bundle' => 'wordpal_page']);
    self::assertCount(1, $instances, 'The override field instance exists once.');
    self::assertSame($storageUuidBefore, FieldStorageConfig::loadByName('node', 'field_display_full')->uuid(), "Replace must keep the override field storage's UUID.");
    self::assertSame($fieldUuidBefore, FieldConfig::loadByName('node', 'wordpal_page', 'field_display_full')->uuid(), "Replace must keep the override field instance's UUID.");

    $home = $entityRepository->loadEntityByUuid('node', TemplatePage::uuid($themeId, 'home'));
    self::assertSame(self::withoutNodeIds($treeBefore), self::withoutNodeIds($home->get('field_display_full')->getValue()), 'The recreated home node is rewritten to the same tree, not carrying the old value over.');
  }

  /**
   * Strips ui_patterns' own node_id keys, recursively.
   *
   * @param array<array-key, mixed> $tree
   *   A source tree or field value array.
   */
  private static function withoutNodeIds(array $tree): array {
    unset($tree['node_id']);
    foreach ($tree as $key => $value) {
      if (is_array($value)) {
        $tree[$key] = self::withoutNodeIds($value);
      }
    }
    return $tree;
  }

}
