<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\comment\Entity\CommentType;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\editor\Entity\Editor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Component\Serialization\Yaml;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\system\Entity\Menu;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\views\Entity\View;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use Drupal\wordpal_convert\Support\StableUuid;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\WordPress\PlaygroundRunner;
use Drupal\wordpal_convert\WordPress\Snapshot;
use Drupal\wordpal_convert\WordPress\WpBlockParser;
use Drupal\wordpal_convert\ConversionOwnership;
use Drupal\wordpal_canvas\CanvasWriter;
use Drupal\wordpal_convert\ConversionRunner;
use Drupal\wordpal_convert\NavigationMenus;
use Drupal\wordpal_convert\SiteRecipeWriter;
use Drupal\wordpal_convert\TemplatePage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Route;

/**
 * Drives ConversionRunner, what wordpal:convert runs, against a fixture.
 *
 * ConvertCommands itself cannot run in a kernel test: Drush only wires a
 * command's logger and IO when a real command invocation boots it, and the
 * command logs its report rather than returning it. ConversionRunner holds
 * the command's orchestration instead, so this test drives that directly.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ConversionRunnerTest extends CanvasKernelTestBase {

  use FixtureConversionTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'wordpal_convert', 'wordpal_canvas', 'wordpal_canvas_runtime', 'system', 'user', 'field', 'node',
    'text', 'file', 'image', 'views', 'path', 'path_alias', 'block', 'comment',
    'filter', 'taxonomy', 'link', 'menu_link_content',
  ];

  /**
   * The Drupal machine name of the fixture theme.
   */
  private const THEME_ID = 'wordpal_fixture';

  /**
   * The render index entry of the fixture footer's core/audio block.
   */
  private const AUDIO = '<figure class="wp-block-audio"><audio controls src="/sites/default/files/fixture.mp3"></audio></figure>';

  /**
   * Isolated generated-theme root.
   */
  private string $themeRoot;

  /**
   * Creates the generated-theme test directory.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['field', 'filter', 'node', 'user', 'views']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    // Site recipes go beside the Drupal root, in the project directory.
    $this->themeRoot = sys_get_temp_dir() . '/wordpal-convert-' . bin2hex(random_bytes(6)) . '/web';
    mkdir($this->themeRoot, 0777, TRUE);
    $this->container->get(ComponentSourceManager::class)
      ->generateComponents('sdc', [
        'wordpal:group', 'wordpal:paragraph', 'wordpal:query',
        'wordpal:post-template', 'wordpal:post-title', 'wordpal:query-no-results',
        'wordpal:frozen-html',
      ]);
  }

  /**
   * Removes generated theme files.
   */
  protected function tearDown(): void {
    if (isset($this->themeRoot)) {
      (new Filesystem())->remove(dirname($this->themeRoot));
    }
    parent::tearDown();
  }

  /**
   * Tests a first conversion's entities, filtering, report, and front page.
   *
   * The fixture snapshot has four inserter patterns (one dropping its only
   * block, an unmapped concept, and one holding a Query whose no-results
   * block holds a paragraph), one Hidden pattern, and one pattern from a
   * namespace outside the theme (a Remote pattern, whose id takes the theme
   * id as a prefix). Its single and home Templates share a
   * header part; the single Template's footer part carries a block with no
   * component, which freezes, so both Templates and their Part sets
   * convert, and the home Template becomes the static Canvas front page.
   */
  public function testFirstConversionCreatesExpectedEntities(): void {
    $report = $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, TRUE);

    self::assertContains('pattern:wordpal_fixture_welcome', $report->created);
    self::assertContains('pattern:wordpal_fixture_tags', $report->created);
    self::assertContains('pattern:wordpal_fixture_wp_pattern_directory_spotlight', $report->created);
    self::assertNotContains('pattern:wordpal_fixture_hidden_note', $report->created);
    self::assertNull(Pattern::load('wordpal_fixture_hidden_note'), 'The Hidden pattern must not convert.');
    self::assertNotNull(Pattern::load('wordpal_fixture_wp_pattern_directory_spotlight'), 'The Remote pattern must convert.');
    self::assertContains('pattern:wordpal_fixture_latest', $report->created);
    self::assertCount(1, self::entitiesOfType($report->created, 'view'), 'The Query pattern must save one View.');

    $pageVariants = self::entitiesOfType($report->created, 'page_variant');
    self::assertCount(2, $pageVariants, 'Both Part sets convert.');
    self::assertContains('content_template:node.wordpal_post.full', $report->created);
    $pages = self::entitiesOfType($report->created, 'canvas_page');
    self::assertCount(1, $pages, 'The static home page must be created.');
    self::assertSame(TemplatePage::alias(self::THEME_ID, 'home'), $this->config('system.site')->get('page.front'), "The home page's alias must become the front page.");

    self::assertSame(['Drupal-owned routes: no page Template converts, so they render without a converted frame.'], $report->skipped);
    self::assertSame(['Template part footer' => ['core/audio']], $report->frozen);
    $frozenFile = 'themes/custom/' . self::THEME_ID . '/' . FrozenBlock::path(FrozenBlock::key(self::AUDIO));
    self::assertContains($frozenFile, $this->manifest()['files'], 'The frozen HTML is an Owned output.');
    self::assertStringEqualsFile($this->themeRoot . '/' . $frozenFile, self::AUDIO);
    self::assertSame([], $report->skippedPatterns);
    self::assertSame(['tag' => 1], $report->dropped);
    $this->assertManifestMatchesLive($report->created);
  }

  /**
   * Tests a second conversion fails, counts existing outputs, changes nothing.
   */
  public function testSecondConversionFailsAndCountsExistingOutputs(): void {
    $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, FALSE);
    $welcomeUuid = Pattern::load('wordpal_fixture_welcome')->uuid();

    try {
      $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, FALSE);
      self::fail('A second conversion without --replace must fail.');
    }
    catch (\RuntimeException $exception) {
      self::assertMatchesRegularExpression('/^\S.* is already converted on this site: \d+ outputs \(.*\d+ pattern.*\)\. Nothing was changed\. Run the command again with --replace/', $exception->getMessage());
    }

    self::assertSame($welcomeUuid, Pattern::load('wordpal_fixture_welcome')->uuid(), 'The rejected second conversion must not touch existing outputs.');
    $this->assertManifestMatchesLive();
  }

  /**
   * Tests a repeat conversion is recognized from the manifest alone.
   */
  public function testAlreadyConvertedReadsTheManifest(): void {
    self::assertNull($this->runner()->alreadyConverted('wordpal-fixture'));

    $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, FALSE);

    self::assertMatchesRegularExpression('/^wordpal-fixture is already converted on this site: \d+ outputs \(.*\d+ pattern.*\)\. Nothing was changed\. Run the command again with --replace/', $this->runner()->alreadyConverted('wordpal-fixture'));
  }

  /**
   * Tests the manifest records both versions and the report a lost control.
   */
  public function testRecordsVersionsAndUnreadStyles(): void {
    $image = '<!-- wp:image {"style":{"color":{"duotone":["#000000","#ffffff"],"text":"#111111"}}} --><figure class="wp-block-image"><img src="/fixture.jpg" alt=""/></figure><!-- /wp:image -->';
    $snapshot = $this->snapshotWithTemplates(['home' => $image]);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:image']);

    $report = $this->convert($snapshot, $this->mappingPath(), FALSE, FALSE, FALSE);

    self::assertSame($snapshot->wordPressVersion, $this->manifest()['wordpress_version']);
    self::assertArrayHasKey('wordpal_version', $this->manifest());
    self::assertNull($this->manifest()['wordpal_version'], 'A git checkout has no version key.');
    self::assertSame(['home Template: core/image: style.color.duotone'], $report->unreadStyleLines());
  }

  /**
   * Tests an override with missing targets fails before any write.
   */
  public function testOverrideWithMissingTargetsFailsBeforeWrites(): void {
    $path = $this->themeRoot . '/override.yml';
    file_put_contents($path, "post: missing_post\npage: missing_page\nnavigation: missing_menu\ntag: ~\n");

    try {
      $this->convert($this->snapshot(), $path, FALSE, FALSE, FALSE);
      self::fail('An override with missing targets must fail.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('post names a missing node bundle: missing_post.', $exception->getMessage());
      self::assertStringContainsString('page names a missing node bundle: missing_page.', $exception->getMessage());
      self::assertStringContainsString('navigation names a missing menu: missing_menu.', $exception->getMessage());
    }
    self::assertNull(Pattern::load('wordpal_fixture_welcome'));
    self::assertTrue($this->container->get('config.factory')->get('wordpal_convert.conversion.' . self::THEME_ID)->isNew());
  }

  /**
   * Tests a --replace with an invalid settings.json keeps the conversion.
   */
  public function testReplaceWithInvalidSettingsKeepsPreviousOutputs(): void {
    $snapshot = $this->themeSnapshot('wordpal-fixture');
    $this->convert($snapshot, $this->mappingPath(), FALSE, FALSE, TRUE);
    $manifest = $this->manifest();
    file_put_contents($this->themeRoot . '/snapshots/wordpal-fixture/out/settings.json', '{}');

    try {
      $this->convert($snapshot, $this->mappingPath(), TRUE, FALSE, TRUE);
      self::fail('An invalid settings.json must refuse the replace.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertStringContainsString('settings.json has an invalid shape', $exception->getMessage());
    }

    self::assertSame($manifest, $this->manifest());
    self::assertNotNull(Pattern::load('wordpal_fixture_welcome'));
    self::assertNotSame([], $this->config('wordpal.theme_settings.' . self::THEME_ID)->getRawData());
    $this->assertManifestMatchesLive();
  }

  /**
   * Tests --replace recreates a conversion's owned outputs with the same UUID.
   */
  public function testReplaceDeletesAndRecreates(): void {
    $report = $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, TRUE);
    $welcomeUuidBefore = Pattern::load('wordpal_fixture_welcome')->uuid();
    $pageVariantId = self::entitiesOfType($report->created, 'page_variant')[0];
    $pageVariantUuidBefore = PageVariant::load($pageVariantId)->uuid();
    $contentTemplateUuidBefore = ContentTemplate::load('node.wordpal_post.full')->uuid();
    $viewId = self::entitiesOfType($report->created, 'view')[0];
    $viewUuidBefore = View::load($viewId)->uuid();
    $pageBefore = $this->container->get('entity.repository')->loadEntityByUuid('canvas_page', TemplatePage::uuid(self::THEME_ID, 'home'));

    $report = $this->convert($this->snapshot(), $this->mappingPath(), TRUE, FALSE, TRUE);

    self::assertSame($welcomeUuidBefore, Pattern::load('wordpal_fixture_welcome')->uuid(), "Replace must keep the pattern's deterministic UUID.");
    self::assertSame($pageVariantUuidBefore, PageVariant::load($pageVariantId)->uuid(), "Replace must keep the page variant's deterministic UUID.");
    self::assertSame($contentTemplateUuidBefore, ContentTemplate::load('node.wordpal_post.full')->uuid(), "Replace must keep the content template's deterministic UUID.");
    self::assertSame($viewUuidBefore, View::load($viewId)->uuid(), "Replace must keep the view's deterministic UUID.");
    $pageAfter = $this->container->get('entity.repository')->loadEntityByUuid('canvas_page', TemplatePage::uuid(self::THEME_ID, 'home'));
    self::assertNotSame($pageBefore->id(), $pageAfter->id(), 'Replace must delete and recreate the home page.');
    self::assertSame($pageBefore->uuid(), $pageAfter->uuid(), "The home page's deterministic UUID must survive replacement.");
    self::assertSame(TemplatePage::alias(self::THEME_ID, 'home'), $this->config('system.site')->get('page.front'));
    self::assertContains('pattern:wordpal_fixture_welcome', $report->created);
    $this->assertManifestMatchesLive($report->created);
  }

  /**
   * Tests --replace on an installed theme drops its cached theme.
   *
   * The replace runs without activation, which saves no system.theme, so
   * only the runner clears the registry, the active theme, the libraries
   * and the rendered markup.
   */
  public function testReplaceResetsInstalledThemeCaches(): void {
    $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, TRUE);
    $cache = $this->container->get('cache.default');
    $cache->set('theme_registry:' . self::THEME_ID, ['stale' => TRUE]);
    $render = $this->container->get('cache.render');
    $render->set('wordpal_stale_markup', 'stale', CacheBackendInterface::CACHE_PERMANENT, ['rendered']);
    $bootstrap = $this->container->get('cache.bootstrap');
    $stale = new ActiveTheme([
      'name' => self::THEME_ID,
      'engine' => NULL,
      'libraries' => ['wordpal_stale/library'],
    ]);
    $bootstrap->set('theme.active_theme.' . self::THEME_ID, $stale);
    $cache->set('wordpal_stale_libraries', 'stale', CacheBackendInterface::CACHE_PERMANENT, ['library_info']);

    $this->convert($this->snapshot(), $this->mappingPath(), TRUE, FALSE, FALSE);

    self::assertFalse($cache->get('theme_registry:' . self::THEME_ID), 'The theme registry is rebuilt from the new templates.');
    self::assertFalse($render->get('wordpal_stale_markup'), 'Markup rendered with the old templates is invalidated.');
    $libraries = $this->container->get('theme.initialization')->getActiveThemeByName(self::THEME_ID)->getLibraries();
    self::assertNotContains('wordpal_stale/library', $libraries, 'The active theme is rebuilt with the new info file libraries.');
    self::assertFalse($cache->get('wordpal_stale_libraries'), 'Assets resolved from the old libraries are invalidated.');
  }

  /**
   * Tests two themes mapping different post bundles both convert.
   *
   * A Canvas content template is keyed by bundle and view mode, so two
   * themes on one site convert side by side only when each maps its posts
   * to its own bundle.
   */
  public function testTwoThemesConvertWithoutCollision(): void {
    NodeType::create(['type' => 'other_post', 'name' => 'Other post'])->save();

    $first = $this->convert($this->themeSnapshot('wordpal-fixture'), $this->bundleMapping('wordpal_post'), FALSE, FALSE, FALSE);
    $second = $this->convert($this->themeSnapshot('other-theme'), $this->bundleMapping('other_post'), FALSE, FALSE, FALSE);

    self::assertContains('content_template:node.wordpal_post.full', $first->created);
    self::assertContains('content_template:node.other_post.full', $second->created);
    self::assertContains('pattern:other_theme_wordpal_fixture_welcome', $second->created);
    self::assertSame([], array_intersect($first->created, $second->created), 'The two conversions must share no output.');
    $config = $this->container->get('config.factory');
    self::assertSame(['wordpal.theme_settings.wordpal_fixture'], $config->get('wordpal_convert.conversion.wordpal_fixture')->get('config'));
    self::assertSame(['wordpal.theme_settings.other_theme'], $config->get('wordpal_convert.conversion.other_theme')->get('config'));
  }

  /**
   * Tests a second theme mapping the same post bundle refuses to convert.
   */
  public function testSameBundleCollides(): void {
    $this->convert($this->themeSnapshot('wordpal-fixture'), $this->bundleMapping('wordpal_post'), FALSE, FALSE, FALSE);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("These items are in the way and WordPal does not own them:\ncontent_template:node.wordpal_post.full\nNothing was changed.");
    $this->convert($this->themeSnapshot('other-theme'), $this->bundleMapping('wordpal_post'), FALSE, FALSE, FALSE);
  }

  /**
   * Tests the report lists each unregistered namespace with its trees.
   */
  public function testReportsUnregisteredNamespaces(): void {
    $snapshot = $this->themeSnapshot('plugin-theme');
    $out = $snapshot->directory;
    file_put_contents("$out/parts/header.html", '<!-- wp:acme/cart /-->', FILE_APPEND);
    file_put_contents("$out/patterns/wordpal-fixture--welcome.html", '<!-- wp:group --><div class="wp-block-group"><!-- wp:acme/cart /--><!-- wp:shop/badge /--></div><!-- /wp:group -->', FILE_APPEND);
    $blocks = [...array_fill_keys($snapshot->registeredBlocks(), []), 'shop/badge' => []];
    file_put_contents("$out/blocks.json", json_encode($blocks));
    $index = json_decode(file_get_contents("$out/render-index.json"), TRUE);
    $header = array_key_last((new WpBlockParser())->parse(file_get_contents("$out/parts/header.html")));
    $index['parts/header.html'][$header] = '<div class="acme-cart"></div>';
    $welcome = array_key_last((new WpBlockParser())->parse(file_get_contents("$out/patterns/wordpal-fixture--welcome.html")));
    $index['patterns/wordpal-fixture--welcome.html'] += [
      "$welcome.0" => '<div class="acme-cart"></div>',
      "$welcome.1" => '<span class="shop-badge"></span>',
    ];
    file_put_contents("$out/render-index.json", json_encode($index, JSON_FORCE_OBJECT));

    $report = $this->convert($snapshot, $this->bundleMapping('wordpal_post'), FALSE, FALSE, FALSE);

    self::assertSame(['acme' => ['wordpal-fixture/welcome', 'Template part header']], $report->unregistered);
    self::assertSame([
      'wordpal-fixture/welcome' => ['acme/cart', 'shop/badge'],
      'Template part header' => ['acme/cart'],
    ], $report->frozen);
  }

  /**
   * Tests a namespace used only by a Template the mapping drops stays out.
   */
  public function testUnregisteredNamespaceExcludesDroppedTemplate(): void {
    $snapshot = $this->templateSnapshot([
      'page' => '<!-- wp:acme/cart /-->',
    ]);
    $dropped = $this->themeRoot . '/no-page-mapping.yml';
    file_put_contents($dropped, file_get_contents($this->demoMapping()) . "\npage: ~\npage_body: ~\n");

    $report = $this->convert($snapshot, $dropped, FALSE, FALSE, FALSE);

    self::assertContains('page Template: the Content mapping drops page', $report->skipped);
    self::assertArrayNotHasKey('acme', $report->unregistered);
  }

  /**
   * Tests Navigation blocks with their own links bind their own menus.
   *
   * The header part holds two blocks with their own links, one with none,
   * which renders the site navigation and binds the mapped menu with its
   * label, and one holding a site logo, which binds the mapped menu without
   * its label and gets a report line. The links with a javascript: URL or an
   * over-long label are left out. A replace recreates the menus and links,
   * the Site recipe carries them, and delete removes them.
   */
  public function testNavigationBlocksBindTheirOwnMenus(): void {
    $snapshot = $this->navigationSnapshot();
    $mapping = $this->themeRoot . '/navigation-mapping.yml';
    file_put_contents($mapping, file_get_contents($this->mappingPath()) . "navigation: main\n");

    $report = $this->convert($snapshot, $mapping, FALSE, FALSE, FALSE);

    $menus = Menu::loadMultiple();
    $about = array_search('WordPal Fixture: About', array_map(static fn (Menu $menu): string => $menu->label(), $menus), TRUE);
    $social = array_search('WordPal Fixture: Social', array_map(static fn (Menu $menu): string => $menu->label(), $menus), TRUE);
    self::assertIsString($about);
    self::assertIsString($social);
    self::assertNotSame($about, $social);
    $expected = [
      $about => ['Team' => 'internal:#', 'History' => 'internal:/history'],
      $social => ['Feed' => 'https://example.com/feed'],
    ];
    $this->assertMenuLinks($expected);
    self::assertContains('Template part header: core/navigation-link "Bad" (javascript:alert(1)): its URL is not a root-relative path, a fragment or an http(s) URL', $report->skipped);
    self::assertContains('Template part header: core/navigation-link "' . str_repeat('x', 59) . '…" (#): its label is longer than 255 characters', $report->skipped);
    self::assertContains("menu:$about", $report->created);
    self::assertSame("$about (WordPal Fixture: About): Template part header", $report->menuLines()[$about < $social ? 0 : 1]);

    $bound = [];
    foreach ($this->container->get('entity_type.manager')->getStorage('page_variant')->loadMultiple() as $variant) {
      foreach ($variant->get('component_tree') as $item) {
        if ($item['component_id'] === 'block.wordpal_navigation') {
          $bound[] = [$item['inputs']['menu'], $item['inputs']['menu_label']];
        }
      }
    }
    self::assertSame([[$about, FALSE], [$social, FALSE], ['main', TRUE], ['main', FALSE]], array_slice($bound, 0, 4), 'Each block binds its own menu, and the others bind the mapped menu, named by it only for the site navigation.');
    self::assertContains('Template part header: core/navigation holds core/site-logo, so it renders the mapped menu instead', $report->skipped);
    self::assertNotSame(NavigationMenus::menuId('another_theme', $this->headerNavigation()), NavigationMenus::menuId(self::THEME_ID, $this->headerNavigation()), 'Menu ids are scoped to the theme.');

    $manifest = $this->manifest();
    $ids = [$about, $social];
    sort($ids);
    self::assertSame($ids, $manifest['entities']['menu']);
    $uuids = array_map(static fn (MenuLinkContent $link): string => $link->uuid(), MenuLinkContent::loadMultiple());
    self::assertEqualsCanonicalizing($uuids, $manifest['demo_content']['menu_link_content']);
    $recipe = SiteRecipeWriter::directory($this->themeRoot, self::THEME_ID);
    foreach ($ids as $id) {
      self::assertFileExists("$recipe/config/system.menu.$id.yml");
    }
    foreach ($uuids as $uuid) {
      self::assertFileExists("$recipe/content/menu_link_content/$uuid.yml");
    }
    self::assertContains('menu_link_content', Yaml::decode(file_get_contents("$recipe/recipe.yml"))['install']);

    $this->convert($snapshot, $mapping, TRUE, FALSE, FALSE);
    $this->assertMenuLinks($expected);

    $this->ownership()->delete(self::THEME_ID);
    self::assertNull(Menu::load($about));
    self::assertNull(Menu::load($social));
    self::assertSame([], MenuLinkContent::loadMultiple());
  }

  /**
   * Tests theme menu links and seeded fixture links share one manifest list.
   *
   * The fallback report line also follows whether the mapping binds
   * navigation.
   */
  public function testThemeLinksMergeWithSeededLinks(): void {
    $snapshot = $this->navigationSnapshot();

    $report = $this->convert($snapshot, $this->demoMapping(), FALSE, TRUE, FALSE);

    $seeded = $report->demoContent['uuids']['menu_link_content'];
    self::assertCount(1, $seeded, 'The fixture seeds Home into the mapped menu, and drops the page link.');
    $theme = array_values(array_diff(array_map(static fn (MenuLinkContent $link): string => $link->uuid(), MenuLinkContent::loadMultiple()), $seeded));
    self::assertCount(3, $theme);
    $manifest = $this->manifest();
    self::assertSame([...$theme, ...$seeded], $manifest['demo_content']['menu_link_content']);
    self::assertSame(DemoContentSeeder::ENTITY_TYPES, array_keys($manifest['demo_content']));
    $recipe = SiteRecipeWriter::directory($this->themeRoot, self::THEME_ID);
    foreach ([...$theme, ...$seeded] as $uuid) {
      self::assertFileExists("$recipe/content/menu_link_content/$uuid.yml");
    }

    $this->convert($snapshot, $this->demoMapping(), TRUE, TRUE, FALSE);
    self::assertCount(4, MenuLinkContent::loadMultiple(), 'A replace recreates both kinds of link once.');
    $this->ownership()->delete(self::THEME_ID);
    self::assertSame([], MenuLinkContent::loadMultiple());

    $home = ['Template part header' => [new BlockNode('core/navigation', [], '', [new BlockNode('core/site-logo', [], '', [])])]];
    self::assertSame(['Template part header: core/navigation holds core/site-logo, so it renders the mapped menu instead'], NavigationMenus::collect($home, self::THEME_ID, 'Fixture', TRUE)['skipped']);
    self::assertSame(['Template part header: core/navigation holds core/site-logo, and the Content mapping drops navigation, so the block is dropped'], NavigationMenus::collect($home, self::THEME_ID, 'Fixture', FALSE)['skipped']);
  }

  /**
   * Returns the fixture snapshot with Navigation blocks in its header part.
   */
  private function navigationSnapshot(): Snapshot {
    $this->container->get(ComponentSourceManager::class)->generateComponents('block', ['wordpal_navigation']);
    $directory = $this->themeRoot . '/snapshots/wordpal-fixture';
    (new Filesystem())->mirror(dirname(__DIR__, 2) . '/fixtures/snapshot/wordpal-fixture', $directory);
    file_put_contents("$directory/out/parts/header.html", implode('', [
      '<!-- wp:navigation {"ariaLabel":"About"} --><!-- wp:navigation-link {"label":"Team","url":"#"} /-->',
      '<!-- wp:navigation-link {"label":"<em>History</em>","url":"/history"} /--><!-- /wp:navigation -->',
      '<!-- wp:navigation {"ariaLabel":"Social"} --><!-- wp:navigation-link {"label":"Feed","url":"https://example.com/feed"} /-->',
      '<!-- wp:navigation-link {"label":"Bad","url":"javascript:alert(1)"} /-->',
      '<!-- wp:navigation-link {"label":"' . str_repeat('x', 256) . '","url":"#"} /--><!-- /wp:navigation -->',
      '<!-- wp:navigation /-->',
      '<!-- wp:navigation --><!-- wp:site-logo /--><!-- /wp:navigation -->',
    ]));
    return $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $this->container->get(ComponentSet::class)->wordPressVersion());
  }

  /**
   * Returns a Navigation block with its own links.
   */
  private function headerNavigation(): BlockNode {
    $link = new BlockNode('core/navigation-link', ['label' => 'Team', 'url' => '#'], '', []);
    return new BlockNode('core/navigation', [], '', [$link]);
  }

  /**
   * Asserts the links of each menu, by title and URI.
   *
   * @param array<string, array<string, string>> $expected
   *   Link URIs keyed by title, keyed by menu id.
   */
  private function assertMenuLinks(array $expected): void {
    $actual = [];
    foreach (MenuLinkContent::loadMultiple() as $link) {
      $actual[$link->getMenuName()][$link->label()] = $link->get('link')->uri;
    }
    ksort($actual);
    ksort($expected);
    self::assertSame($expected, $actual);
  }

  /**
   * Tests Demo content seeds into an empty bundle as Owned outputs.
   */
  public function testSeedsOwnedDemoContent(): void {
    $report = $this->convert($this->snapshot(), $this->demoMapping(), FALSE, TRUE, FALSE);

    $manifest = $this->manifest();
    self::assertSame($report->demoContent['uuids'], $manifest['demo_content']);
    self::assertSame(DemoContentSeeder::ENTITY_TYPES, array_keys($manifest['demo_content']));
    $posts = Node::loadMultiple();
    self::assertCount(11, $posts, 'The fixture holds one post, two neighbors and eight older posts.');
    self::assertEqualsCanonicalizing($manifest['demo_content']['node'], array_map(static fn (Node $post): string => $post->uuid(), array_values($posts)));
    self::assertSame(StableUuid::fromName('demo/wordpal_fixture/post/a-walk-through-the-city'), $manifest['demo_content']['node'][2], 'Demo UUIDs are scoped to the theme.');
    $post = $this->container->get('entity.repository')->loadEntityByUuid('node', $manifest['demo_content']['node'][2]);
    self::assertSame('A walk through the city', $post->label());
    self::assertSame(['City', 'Walking'], array_map(static fn ($term): string => $term->label(), $post->get('field_tags')->referencedEntities()));
    self::assertCount(2, $manifest['demo_content']['taxonomy_term']);
    self::assertSame($manifest['demo_content']['file'][0], $post->get('field_image')->entity->uuid());
    self::assertCount(1, $manifest['demo_content']['user']);
    $links = MenuLinkContent::loadMultiple();
    self::assertSame(['Home'], array_values(array_map(static fn (MenuLinkContent $link): string => $link->label(), $links)));
    self::assertSame(['menu link About (the Demo page): the Content mapping drops page'], $report->demoContent['skipped']);
    self::assertSame(['main'], array_values(array_unique(array_map(static fn (MenuLinkContent $link): string => $link->getMenuName(), $links))));
    self::assertEqualsCanonicalizing($manifest['demo_content']['menu_link_content'], array_map(static fn (MenuLinkContent $link): string => $link->uuid(), array_values($links)));
  }

  /**
   * Tests a bundle holding a node, or the opt-out flag, gets no Demo content.
   */
  public function testSkipsDemoContent(): void {
    $this->convert($this->snapshot(), $this->demoMapping(), FALSE, FALSE, FALSE);
    self::assertSame([], Node::loadMultiple(), '--no-demo-content must seed nothing.');

    $post = Node::create(['type' => 'wordpal_post', 'title' => 'My own post', 'status' => 0]);
    $post->save();
    $report = $this->convert($this->snapshot(), $this->demoMapping(), TRUE, TRUE, FALSE);

    self::assertNull($report->demoContent);
    self::assertSame([(int) $post->id()], array_map('intval', array_keys(Node::loadMultiple())), 'A bundle with any node must get no Demo content.');
    self::assertSame(array_fill_keys(DemoContentSeeder::ENTITY_TYPES, []), $this->manifest()['demo_content']);
  }

  /**
   * Tests --replace recreates Demo content and the front page follows.
   */
  public function testReplaceRecreatesDemoContent(): void {
    $this->convert($this->snapshot(), $this->demoMapping(), FALSE, TRUE, TRUE);
    $before = $this->manifest()['demo_content'];
    $idsBefore = array_keys(Node::loadMultiple());

    $this->convert($this->snapshot(), $this->demoMapping(), TRUE, TRUE, TRUE);

    self::assertSame($before, $this->manifest()['demo_content'], 'Recreated Demo content keeps its stable UUIDs.');
    self::assertCount(11, Node::loadMultiple());
    self::assertSame([], array_intersect($idsBefore, array_keys(Node::loadMultiple())), 'Replace must delete the Demo posts before seeding them again.');
    self::assertSame(TemplatePage::alias(self::THEME_ID, 'home'), $this->config('system.site')->get('page.front'));
  }

  /**
   * Tests Demo content leaves out a menu item the menu already links.
   */
  public function testDemoMenuSkipsLinkedItems(): void {
    MenuLinkContent::create(['menu_name' => 'main', 'title' => 'Start', 'link' => ['uri' => 'internal:/']])->save();

    $report = $this->convert($this->snapshot(), $this->demoMapping(), FALSE, TRUE, FALSE);

    self::assertSame([
      'menu link Home (/): menu main already links it',
      'menu link About (the Demo page): the Content mapping drops page',
    ], $report->demoContent['skipped']);
    self::assertSame([], $report->demoContent['uuids']['menu_link_content']);
    self::assertCount(1, MenuLinkContent::loadMultiple());
  }

  /**
   * Tests --replace purges trashed Demo posts and counts trashed posts.
   */
  public function testReplacePurgesDemoContentFromTrash(): void {
    $this->enableModules(['trash']);
    $this->installConfig(['trash']);
    $this->config('trash.settings')->set('enabled_entity_types', ['node' => []])->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->convert($this->snapshot(), $this->demoMapping(), FALSE, TRUE, FALSE);
    $trashed = Node::load(array_key_first(Node::loadMultiple()));
    $trashed->delete();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $all = fn (): array => $this->container->get('trash.manager')->executeInTrashContext('ignore', fn (): array => array_keys($storage->loadMultiple()));
    self::assertCount(11, $all(), 'Deleting a node under Trash only trashes it.');

    $this->convert($this->snapshot(), $this->demoMapping(), TRUE, TRUE, FALSE);

    self::assertCount(11, $all(), 'Replace purges trashed Demo posts before seeding them again.');
    self::assertNotContains($trashed->id(), $all());
    self::assertDirectoryExists('public://wordpal-demo/' . self::THEME_ID);

    $owner = Node::create(['type' => 'wordpal_post', 'title' => 'My own post']);
    $owner->save();
    $owner->delete();
    $report = $this->convert($this->snapshot(), $this->demoMapping(), TRUE, TRUE, FALSE);
    self::assertNull($report->demoContent, 'A trashed post still counts as content.');
    self::assertSame([(int) $owner->id()], array_map('intval', $all()));
    self::assertDirectoryDoesNotExist('public://wordpal-demo', 'Replace removes the emptied Demo directories.');
  }

  /**
   * Tests a seed that throws leaves no Demo content and restores the site.
   *
   * A link elsewhere already holds the Demo Home link's UUID, so creating
   * that link, after the posts, fails.
   */
  public function testFailedSeedRollsBack(): void {
    MenuLinkContent::create([
      'uuid' => StableUuid::fromName('demo/wordpal_fixture/menu-link/main//'),
      'menu_name' => 'footer',
      'title' => 'Elsewhere',
      'link' => ['uri' => 'internal:/user'],
    ])->save();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.site')->set('page.front', '/user/login')->save();

    try {
      $this->convert($this->snapshot(), $this->demoMapping(), FALSE, TRUE, TRUE);
      self::fail('A duplicate Demo UUID must fail the seed.');
    }
    catch (EntityStorageException) {
    }

    // Media is not installed here: the mapping's image field is a file field.
    foreach (array_diff(DemoContentSeeder::ENTITY_TYPES, ['media']) as $type) {
      // The site's own administrator and menu link stay.
      $expected = in_array($type, ['menu_link_content', 'user'], TRUE) ? 1 : 0;
      self::assertCount($expected, $this->container->get('entity_type.manager')->getStorage($type)->loadMultiple(), "No Demo $type may remain.");
    }
    self::assertSame('stark', $this->config('system.theme')->get('default'));
    self::assertSame('/user/login', $this->config('system.site')->get('page.front'));
  }

  /**
   * Tests a Site recipe that fails to write restores the site.
   *
   * A file holds the recipes directory's path, so the writer cannot create
   * its staging directory. The conversion has already taken over the
   * site's disabled post template, disabled the site's View on the term
   * path and enabled the archive View.
   */
  public function testFailedRecipeRollsBack(): void {
    $snapshot = $this->templateSnapshot([
      'archive' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:query {"query":{"perPage":3,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query --></main><!-- /wp:group -->',
    ]);
    $site = ContentTemplate::create([
      'id' => 'node.wordpal_post.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'wordpal_post',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
    $site->setStatus(FALSE)->save();
    View::create([
      'id' => 'site_terms',
      'label' => 'Site terms',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [],
        ],
        'page_1' => [
          'id' => 'page_1',
          'display_title' => 'Page',
          'display_plugin' => 'page',
          'position' => 1,
          'display_options' => ['path' => 'taxonomy/term/%'],
        ],
      ],
    ])->save();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.site')->set('page.front', '/user/login')->set('page.404', '/user/password')->save();
    touch(dirname($this->recipeDirectory()));

    try {
      $this->convert($snapshot, $this->mappingPath(), FALSE, FALSE, TRUE);
      self::fail('The Site recipe cannot be written.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('mkdir', $exception->getMessage());
    }

    $restored = ContentTemplate::load('node.wordpal_post.full');
    self::assertSame($site->uuid(), $restored->uuid(), "The site's own post template is back.");
    self::assertFalse($restored->status());
    self::assertTrue(View::load('site_terms')->status(), "The site's term View is enabled again.");
    self::assertFalse(View::load('wordpal_wordpal_fixture_archive')->status(), 'The archive View steps aside again.');
    self::assertSame('stark', $this->config('system.theme')->get('default'));
    self::assertSame('/user/login', $this->config('system.site')->get('page.front'));
    self::assertSame('/user/password', $this->config('system.site')->get('page.404'));
    self::assertNotContains('node.wordpal_post.full', $this->manifest()['entities']['content_template'], 'The restored template is the site\'s again.');
    self::assertSame([], $this->manifest()['replaced_views']);
  }

  /**
   * Tests activation installs the theme, makes it default and sets the front.
   */
  public function testActivates(): void {
    $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, TRUE);

    self::assertTrue($this->container->get('theme_handler')->themeExists(self::THEME_ID));
    self::assertSame(self::THEME_ID, $this->config('system.theme')->get('default'));
    self::assertSame(TemplatePage::alias(self::THEME_ID, 'home'), $this->config('system.site')->get('page.front'));
  }

  /**
   * Tests the report lists the variations and a replace keeps a valid one.
   *
   * A replace resets a selected variation the new snapshot lacks, so the
   * theme never attaches a library it no longer declares.
   */
  public function testReportsVariationsAndResetsMissingOne(): void {
    $report = $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, TRUE);
    self::assertSame(['default' => 'Default', 'ember' => 'Ember'], $report->variations);
    self::assertSame('default', $this->config(self::THEME_ID . '.settings')->get('style_variation'));

    $this->config(self::THEME_ID . '.settings')->set('style_variation', 'ember')->save();
    $this->convert($this->snapshot(), $this->mappingPath(), TRUE, FALSE, TRUE);
    self::assertSame('ember', $this->config(self::THEME_ID . '.settings')->get('style_variation'));

    $this->config(self::THEME_ID . '.settings')->set('style_variation', 'gone')->save();
    $this->convert($this->snapshot(), $this->mappingPath(), TRUE, FALSE, TRUE);
    self::assertSame('default', $this->config(self::THEME_ID . '.settings')->get('style_variation'));
  }

  /**
   * Tests a converted frame renders only under the theme it was converted for.
   */
  public function testFrameNeedsItsConvertedTheme(): void {
    $report = $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, TRUE);
    $this->config('canvas.settings')->set('default_page_variant', self::entitiesOfType($report->created, 'page_variant')[0])->save();

    self::assertSame(CanvasPageVariant::PLUGIN_ID, $this->selectPageVariant(self::THEME_ID)->getPluginId());
    self::assertSame('block_page', $this->selectPageVariant('claro')->getPluginId());
  }

  /**
   * Tests Drupal-owned routes get a frame built from the page Template.
   */
  public function testDrupalRoutesGetThePageTemplateFrame(): void {
    $snapshot = $this->templateSnapshot([
      'page' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:post-title {"level":1} /--><!-- wp:post-date /--><!-- wp:post-content /--></main><!-- /wp:group -->',
    ]);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', [
      'wordpal:post-content',
      'wordpal:post-date',
    ]);
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    FieldStorageConfig::create(['field_name' => 'page_text', 'entity_type' => 'node', 'type' => 'text_long'])->save();
    FieldConfig::create(['field_name' => 'page_text', 'entity_type' => 'node', 'bundle' => 'wordpal_page'])->save();
    $mapping = $this->themeRoot . '/page-mapping.yml';
    file_put_contents($mapping, file_get_contents($this->demoMapping()) . "\npage: wordpal_page\npage_body: page_text\n");

    $report = $this->convert($snapshot, $mapping, FALSE, FALSE, TRUE);

    self::assertSame([], array_filter($report->skipped, static fn (string $line): bool => str_starts_with($line, 'Drupal-owned routes')));
    $frame = $this->config('canvas.settings')->get('default_page_variant');
    $byId = array_column(PageVariant::load($frame)->get('component_tree'), NULL, 'component_id');
    self::assertArrayHasKey('block.wordpal_page_title', $byId, 'Post Title shows the route title.');
    self::assertArrayHasKey('block.system_messages_block', $byId, 'The frame places the status messages.');
    self::assertArrayNotHasKey('sdc.wordpal.post-date', $byId, 'A block that prints the post is left out.');
    $marker = $byId[Marker::PAGE_CONTENT_COMPONENT_ID];
    self::assertSame('page_content', $marker['slot'], "Post Content's slot holds the main content.");
    self::assertSame($byId['sdc.wordpal.post-content']['uuid'], $marker['parent_uuid']);
    self::assertArrayNotHasKey('content', $byId['sdc.wordpal.post-content']['inputs'], 'Post Content shows no sample post, only the main content.');
    self::assertSame(['default_page_variant' => $frame], $this->recipe()['config']['actions']['canvas.settings']['simpleConfigUpdate'], 'The Site recipe sets the frame as the site default.');
    self::assertStringContainsString('<s>', $this->config('filter.format.canvas_html_inline')->get('filters.filter_html.settings.allowed_html'), 'Inline rich text keeps struck-through text.');
    self::assertContains('strikethrough', $this->config('editor.editor.canvas_html_inline')->get('settings.toolbar.items'), 'The inline editor writes struck-through text.');
    self::assertStringContainsString('<span class>', $this->config('filter.format.canvas_html_inline')->get('filters.filter_html.settings.allowed_html'), 'Inline rich text keeps classed spans.');
    self::assertSame(['<span class>'], $this->config('editor.editor.canvas_html_inline')->get('settings.plugins.ckeditor5_sourceEditing.allowed_tags'), 'The inline editor keeps classed spans.');
    $violations = Editor::load('canvas_html_inline')->getTypedData()->validate();
    self::assertSame([], array_map(static fn ($violation): string => $violation->getPropertyPath() . ': ' . $violation->getMessage(), iterator_to_array($violations)), 'The inline editor matches its format.');
  }

  /**
   * Tests a Frozen block holding Post Content leaves Drupal routes frameless.
   */
  public function testFrozenPostContentGivesDrupalRoutesNoFrame(): void {
    $snapshot = $this->templateSnapshot([
      'page' => '<!-- wp:shop/section --><div class="shop-section"><!-- wp:post-content /--></div><!-- /wp:shop/section -->',
    ]);
    $out = $snapshot->directory;
    $blocks = [...array_fill_keys($snapshot->registeredBlocks(), []), 'shop/section' => []];
    file_put_contents("$out/blocks.json", json_encode($blocks));
    $index = json_decode(file_get_contents("$out/render-index.json"), TRUE);
    $section = array_keys((new WpBlockParser())->parse(file_get_contents("$out/templates/page.html")))[1];
    $index['templates/page.html'][$section] = '<div class="shop-section"><p>Sample page</p></div>';
    file_put_contents("$out/render-index.json", json_encode($index, JSON_FORCE_OBJECT));
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    FieldStorageConfig::create(['field_name' => 'page_text', 'entity_type' => 'node', 'type' => 'text_long'])->save();
    FieldConfig::create(['field_name' => 'page_text', 'entity_type' => 'node', 'bundle' => 'wordpal_page'])->save();
    $mapping = $this->themeRoot . '/page-mapping.yml';
    file_put_contents($mapping, file_get_contents($this->demoMapping()) . "\npage: wordpal_page\npage_body: page_text\n");

    $report = $this->convert($snapshot, $mapping, FALSE, FALSE, TRUE);

    self::assertContains('Drupal-owned routes: the page Template places Post Content in a Frozen block, so they render without a converted frame.', $report->skipped);
    self::assertNull($this->config('canvas.settings')->get('default_page_variant'));
  }

  /**
   * Returns the page display variant selection under an active theme.
   *
   * @param string $theme
   *   The active theme's name.
   * @param array $defaults
   *   The route's defaults, such as a View page's `view_id`.
   */
  private function selectPageVariant(string $theme, array $defaults = []): PageDisplayVariantSelectionEvent {
    $this->container->get('theme.manager')->setActiveTheme(new ActiveTheme(['name' => $theme, 'engine' => NULL]));
    $event = new PageDisplayVariantSelectionEvent('simple_page', new RouteMatch('wordpal.test', new Route('/wordpal-test', $defaults)));
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    return $event;
  }

  /**
   * Tests activation wires the archive and 404 Templates into the site.
   *
   * The site's own View on the term path steps aside for the archive
   * Template's, the 404 Template's page becomes the site's 404 page, and the
   * archive frame renders the pages that select no page variant. A replace
   * converts the same outputs again.
   */
  public function testActivatesListingAndNotFoundTemplates(): void {
    $directory = $this->themeRoot . '/snapshots/wordpal-fixture';
    (new Filesystem())->mirror(dirname(__DIR__, 2) . '/fixtures/snapshot/wordpal-fixture', $directory);
    $frame = '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->%s<!-- wp:template-part {"slug":"footer-safe","area":"footer","tagName":"footer"} /-->';
    file_put_contents("$directory/out/templates/404.html", sprintf($frame, '<!-- wp:group {"tagName":"main"} --><main><!-- wp:paragraph --><p>Nothing here.</p><!-- /wp:paragraph --></main><!-- /wp:group -->'));
    file_put_contents("$directory/out/templates/archive.html", sprintf($frame, '<!-- wp:group {"tagName":"main"} --><main><!-- wp:query {"query":{"perPage":3,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query --></main><!-- /wp:group -->'));
    $index = json_decode(file_get_contents("$directory/out/render-index.json"), TRUE);
    $index['templates/404.html'] = ['0' => ''];
    $index['templates/archive.html'] = ['0' => ''];
    file_put_contents("$directory/out/render-index.json", json_encode($index));
    $snapshot = $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $this->container->get(ComponentSet::class)->wordPressVersion());
    View::create([
      'id' => 'site_terms',
      'label' => 'Site terms',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [],
        ],
        'page_1' => [
          'id' => 'page_1',
          'display_title' => 'Page',
          'display_plugin' => 'page',
          'position' => 1,
          'display_options' => ['path' => 'taxonomy/term/%'],
        ],
      ],
    ])->save();

    $report = $this->convert($snapshot, $this->mappingPath(), FALSE, FALSE, TRUE);

    self::assertSame(['site_terms', 'taxonomy_term'], $report->disabledViews, "Every enabled View on the term path steps aside, core's own included.");
    self::assertFalse(View::load('site_terms')->status());
    self::assertTrue(View::load('wordpal_wordpal_fixture_archive')->status(), 'Activation enables the archive View.');
    self::assertSame('Canvas', $report->targetLabel);
    self::assertContains('/canvas/pattern/wordpal_fixture_archive_template', $report->templatePaths);
    self::assertContains('view:wordpal_wordpal_fixture_archive', $report->created);
    self::assertContains('pattern:wordpal_fixture_archive_template', $report->created);
    $notFound = $this->container->get('entity.repository')->loadEntityByUuid('canvas_page', TemplatePage::uuid(self::THEME_ID, 'not_found'));
    self::assertSame(TemplatePage::alias(self::THEME_ID, 'not_found'), $this->config('system.site')->get('page.404'));
    self::assertNull($this->config('canvas.settings')->get('default_page_variant'), 'With no page Template, Drupal-owned routes get no frame.');
    $archiveFrame = View::load('wordpal_wordpal_fixture_archive')->getThirdPartySetting('wordpal_canvas_runtime', 'frame');
    self::assertSame($notFound->get('page_variant')->value, $archiveFrame, 'The archive View records its Template frame, which the 404 Template shares.');
    $selection = $this->selectPageVariant(self::THEME_ID, ['view_id' => 'wordpal_wordpal_fixture_archive']);
    self::assertSame(CanvasPageVariant::PLUGIN_ID, $selection->getPluginId(), 'The View route renders in a Canvas frame without a site default.');
    self::assertSame($archiveFrame, $selection->getPluginConfiguration()[CanvasPageVariant::VARIANT_ID_KEY]);
    self::assertSame(['home', 'not_found'], array_keys($this->manifest()['pages']));
    $this->assertManifestMatchesLive($report->created);
    $disabled = [
      'views.view.site_terms' => ['setStatus' => FALSE],
      'views.view.taxonomy_term' => ['setStatus' => FALSE],
    ];
    self::assertSame(['site_terms', 'taxonomy_term'], $this->manifest()['replaced_views']);
    self::assertSame($disabled, array_intersect_key($this->recipe()['config']['actions'], $disabled), 'The Site recipe disables the replaced Views.');

    $replaced = $this->convert($snapshot, $this->mappingPath(), TRUE, FALSE, TRUE);
    self::assertSame([], $replaced->disabledViews, 'Only enabled Views step aside.');
    self::assertSame(['site_terms', 'taxonomy_term'], $this->manifest()['replaced_views'], 'A replace keeps the replaced Views.');
    self::assertSame($disabled, array_intersect_key($this->recipe()['config']['actions'], $disabled));
    $config = static fn (array $created): array => array_values(array_filter($created, static fn (string $line): bool => !str_starts_with($line, 'canvas_page:')));
    self::assertSame($config($report->created), $config($replaced->created), 'The replace writes the same config outputs.');
    $this->assertManifestMatchesLive($replaced->created);

    // A replace without activation points the site back at the new outputs.
    $this->convert($snapshot, $this->mappingPath(), TRUE, FALSE, FALSE);
    $notFound = $this->container->get('entity.repository')->loadEntityByUuid('canvas_page', TemplatePage::uuid(self::THEME_ID, 'not_found'));
    self::assertSame(TemplatePage::alias(self::THEME_ID, 'not_found'), $this->config('system.site')->get('page.404'));
    self::assertSame($notFound->get('page_variant')->value, View::load('wordpal_wordpal_fixture_archive')->getThirdPartySetting('wordpal_canvas_runtime', 'frame'));
    self::assertTrue(View::load('wordpal_wordpal_fixture_archive')->status(), 'The replaced archive View stays in charge of term pages.');
    self::assertFalse(View::load('site_terms')->status());
  }

  /**
   * Tests the site's search View steps aside for the search Template.
   *
   * The mapped search path names the paths whose Views the conversion
   * replaces, the same as the term path does for the term Views.
   */
  public function testSearchViewOnTheMappedPathStepsAside(): void {
    $mapping = $this->searchMapping();
    $snapshot = $this->templateSnapshot([
      'search' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:query {"query":{"perPage":3,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query --></main><!-- /wp:group -->',
    ]);
    foreach (['site_search' => 'search', 'site_glossary' => 'glossary'] as $id => $path) {
      View::create([
        'id' => $id,
        'label' => $id,
        'base_table' => 'node_field_data',
        'display' => [
          'default' => [
            'id' => 'default',
            'display_title' => 'Default',
            'display_plugin' => 'default',
            'position' => 0,
            'display_options' => [],
          ],
          'page_1' => [
            'id' => 'page_1',
            'display_title' => 'Page',
            'display_plugin' => 'page',
            'position' => 1,
            'display_options' => ['path' => $path],
          ],
        ],
      ])->save();
    }

    $report = $this->convert($snapshot, $mapping, FALSE, FALSE, TRUE);

    self::assertSame(['site_search'], $report->disabledViews, 'Only the View on the mapped search path steps aside.');
    self::assertFalse(View::load('site_search')->status());
    self::assertTrue(View::load('site_glossary')->status(), 'A View on another path stays.');
    self::assertContains('view:wordpal_wordpal_fixture_search', $report->created);
  }

  /**
   * Creates a search index and returns a Content mapping that binds search.
   */
  private function searchMapping(): string {
    $this->enableModules(['search_api', 'search_api_db']);
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['search_api']);
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
    $mapping = $this->themeRoot . '/mapping-with-search.yml';
    file_put_contents($mapping, file_get_contents($this->mappingPath()) . "\nsearch:\n  path: /search\n  parameter: keywords\n  index: content\n");
    return $mapping;
  }

  /**
   * Tests part Queries are left out, and no Template skipped, without a post.
   */
  public function testPartQueryLeftOutWithoutPostMapping(): void {
    $snapshot = $this->templateSnapshot([
      'home' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></main><!-- /wp:group -->',
    ]);
    file_put_contents($this->themeRoot . '/snapshots/wordpal-fixture/out/parts/footer-safe.html', '<!-- wp:query {"query":{"perPage":2,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->');
    $mapping = $this->themeRoot . '/no-post-mapping.yml';
    file_put_contents($mapping, "post: ~\npost_body: ~\ntag: ~\n");

    $conversion = $this->runner()->prepare($snapshot, $mapping, 'canvas', FALSE, FALSE);

    self::assertArrayHasKey('home', $conversion->templates);
    self::assertContains('header + footer-safe frame: Query 1 left out: Query blocks require a post mapping', $conversion->skipped);
    self::assertSame([], array_filter($conversion->skipped, static fn (string $line): bool => str_contains($line, 'Template: Query blocks')));
    foreach ($conversion->partTrees as $trees) {
      self::assertSame([], array_merge(...array_column($trees, 'loops')));
    }
  }

  /**
   * Tests a shared frame's inheriting Query reports the route it took.
   */
  public function testSharedFrameInheritingQueryNamesItsRoute(): void {
    $mapping = $this->searchMapping();
    $main = '<!-- wp:group {"tagName":"main"} --><main><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></main><!-- /wp:group -->';
    $snapshot = $this->templateSnapshot(['archive' => $main, 'search' => $main]);
    file_put_contents($this->themeRoot . '/snapshots/wordpal-fixture/out/parts/footer-safe.html', '<!-- wp:query {"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->');

    $conversion = $this->runner()->prepare($snapshot, $mapping, 'canvas', FALSE, FALSE);

    self::assertContains('header + footer-safe frame: its inheriting Query lists the posts of the archive route, though Templates for archive, search share the frame.', $conversion->skipped);
  }

  /**
   * Tests another conversion's View steps aside but stays out of the recipe.
   *
   * Production never holds another theme's Views, so a Site recipe naming
   * one fails. A replace drops a replaced View deleted since.
   */
  public function testOtherConversionViewStaysOutOfTheRecipe(): void {
    $snapshot = $this->templateSnapshot([
      'archive' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:query {"query":{"perPage":3,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query --></main><!-- /wp:group -->',
    ]);
    View::create([
      'id' => 'other_archive',
      'label' => 'Other archive',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [],
        ],
        'page_1' => [
          'id' => 'page_1',
          'display_title' => 'Page',
          'display_plugin' => 'page',
          'position' => 1,
          'display_options' => ['path' => 'taxonomy/term/%'],
        ],
      ],
    ])->save();
    $this->container->get(ConversionOwnership::class)->save('other_theme', [
      'page_entity_type' => 'canvas_page',
      'entities' => ['view' => ['other_archive']] + array_fill_keys(ConversionOwnership::ENTITY_TYPES, []),
      'config' => [],
      'full_displays' => [],
      'files' => [],
      'pages' => [],
      'demo_content' => array_fill_keys(DemoContentSeeder::ENTITY_TYPES, []),
      'recipe' => 'wordpal_other_theme',
      'replaced_views' => [],
    ]);

    $this->convert($snapshot, $this->mappingPath(), FALSE, FALSE, TRUE);

    self::assertFalse(View::load('other_archive')->status(), "The other conversion's View steps aside.");
    self::assertSame(['taxonomy_term'], $this->manifest()['replaced_views']);
    self::assertArrayNotHasKey('views.view.other_archive', $this->recipe()['config']['actions']);

    View::load('taxonomy_term')->delete();
    $this->convert($snapshot, $this->mappingPath(), TRUE, FALSE, TRUE);
    self::assertSame([], $this->manifest()['replaced_views'], 'A replace drops a replaced View deleted since.');
  }

  /**
   * Tests --no-activate leaves term pages on the site's own Views.
   */
  public function testNoActivateLeavesListingViewsDisabled(): void {
    $snapshot = $this->templateSnapshot([
      'archive' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:query {"query":{"perPage":3,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query --></main><!-- /wp:group -->',
    ]);

    $report = $this->convert($snapshot, $this->mappingPath(), FALSE, FALSE, FALSE);

    self::assertContains('view:wordpal_wordpal_fixture_archive', $report->created);
    self::assertFalse(View::load('wordpal_wordpal_fixture_archive')->status());
    self::assertTrue(View::load('taxonomy_term')->status());
    self::assertSame([], $report->disabledViews);
    self::assertSame([], $this->manifest()['replaced_views']);
    $actions = array_keys($this->recipe()['config']['actions']);
    self::assertSame(['editor.editor.canvas_html_inline', 'filter.format.canvas_html_inline'], array_values(preg_grep('/^canvas\.content_template\./', $actions, PREG_GREP_INVERT)), 'Without activation the recipe wires no site setting, and the inline format still keeps struck-through text.');
  }

  /**
   * Tests a query pattern that fails to build leaves the manifest.
   *
   * Two patterns use a separator, whose Canvas component this site lacks.
   * One shares the latest pattern's Query, whose outputs stay Owned. The
   * other has its own Query and converts before latest. Latest's success
   * recreates the id abandon() deleted, so the manifest must still list it.
   * A third has its own Query, a Frozen block and a button URL that fails
   * schema validation after its Query outputs are saved, and those outputs
   * are deleted.
   */
  public function testFailedQueryPatternLeavesManifest(): void {
    $directory = $this->themeRoot . '/snapshots/wordpal-fixture';
    (new Filesystem())->mirror(dirname(__DIR__, 2) . '/fixtures/snapshot/wordpal-fixture', $directory);
    $separator = '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';
    $latest = file_get_contents("$directory/out/patterns/wordpal-fixture--latest.html");
    $patterns = json_decode(file_get_contents("$directory/out/patterns.json"), TRUE);
    $index = json_decode(file_get_contents("$directory/out/render-index.json"), TRUE);
    $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="http://exa mple.com">Go</a></div><!-- /wp:button -->';
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:button']);
    $broken = [
      'shared' => $separator . $latest,
      'own' => $separator . str_replace('"perPage":3', '"perPage":7', $latest),
      'invalid' => '<!-- wp:audio /-->' . str_replace('"perPage":3', '"perPage":9', $latest) . $button,
    ];
    foreach ($broken as $name => $html) {
      file_put_contents("$directory/out/patterns/wordpal-fixture--broken-$name.html", $html);
      $patterns["wordpal-fixture--broken-$name"] = [
        'slug' => "wordpal-fixture/broken-$name",
        'title' => "Broken $name",
        'categories' => ['featured'],
        'inserter' => TRUE,
      ];
      // The audio block is the invalid Pattern's first block.
      $index["patterns/wordpal-fixture--broken-$name.html"] = $name === 'invalid' ? ['0' => self::AUDIO] : [];
    }
    // broken-own's card hashes the same view mode id as latest's, so it must
    // convert first: abandon() must not strip an id a later Pattern recreates.
    $own = $patterns['wordpal-fixture--broken-own'];
    unset($patterns['wordpal-fixture--broken-own']);
    $latestIndex = array_search('wordpal-fixture--latest', array_keys($patterns), TRUE);
    $before = array_slice($patterns, 0, $latestIndex, TRUE);
    $after = array_slice($patterns, $latestIndex, NULL, TRUE);
    $patterns = $before + ['wordpal-fixture--broken-own' => $own] + $after;
    file_put_contents("$directory/out/patterns.json", json_encode($patterns));
    file_put_contents("$directory/out/render-index.json", json_encode($index));
    $snapshot = $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $this->container->get(ComponentSet::class)->wordPressVersion());

    $report = $this->convert($snapshot, $this->mappingPath(), FALSE, FALSE, TRUE);

    $skipped = array_map(static fn (string $line): string => strstr($line, ':', TRUE), $report->skippedPatterns);
    sort($skipped);
    self::assertSame(['wordpal-fixture/broken-invalid', 'wordpal-fixture/broken-own', 'wordpal-fixture/broken-shared'], $skipped);
    $invalid = array_values(array_filter($report->skippedPatterns, static fn (string $line): bool => str_starts_with($line, 'wordpal-fixture/broken-invalid:')));
    self::assertStringContainsString('url', $invalid[0]);
    self::assertSame([], array_intersect_key($report->frozen, array_flip($skipped)), 'A skipped Pattern lists no Frozen blocks.');
    $manifest = $this->manifest();
    self::assertNotContains(ConversionRunner::patternId('wordpal-fixture', 'wordpal-fixture/broken-shared'), $manifest['entities']['pattern']);
    self::assertNotContains(ConversionRunner::patternId('wordpal-fixture', 'wordpal-fixture/broken-own'), $manifest['entities']['pattern']);
    self::assertContains(ConversionRunner::patternId('wordpal-fixture', 'wordpal-fixture/latest'), $manifest['entities']['pattern']);
    self::assertCount(1, $manifest['entities']['view'], "The shared Query's View stays Owned, and the others leave.");
    $this->assertManifestMatchesLive($report->created);
    self::assertFileExists($this->recipeDirectory() . '/recipe.yml', 'The recipe matches the manifest.');
  }

  /**
   * Tests a failed single Template write is skipped, not fatal.
   *
   * The Tag Cloud's invalid font size only fails once the writer builds the
   * block's plugin settings, after prepare()'s own checks pass, so the
   * failure surfaces from write() instead of an earlier preflight skip. The
   * rest of the conversion still lands, and the manifest never lists the
   * content template the failed write never created.
   */
  public function testFailedSingleTemplateWriteIsSkipped(): void {
    $snapshot = $this->templateSnapshot([
      'single' => '<!-- wp:tag-cloud {"smallestFontSize":"20vw","largestFontSize":"30vw"} /-->',
    ]);
    $mapping = $this->demoMapping();

    $report = $this->convert($snapshot, $mapping, FALSE, FALSE, TRUE);

    self::assertContains('single Template: Unsupported Tag Cloud font size: 20vw', $report->skipped);
    self::assertNull(ContentTemplate::load('node.wordpal_post.full'));
    self::assertContains('pattern:wordpal_fixture_welcome', $report->created, 'The rest of the conversion still lands.');
    $this->assertManifestMatchesLive($report->created);
  }

  /**
   * Tests the page Template, its mapped-route skip and its Demo page.
   *
   * A disabled content template the site already has on the page bundle
   * blocks a replace without activation, and activation replaces it. An
   * enabled one blocks both.
   */
  public function testPageTemplateTakesOverDisabledContentTemplate(): void {
    $snapshot = $this->templateSnapshot([
      'page' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group -->',
    ]);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:post-content']);
    $this->demoMapping();
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    FieldStorageConfig::create(['field_name' => 'page_text', 'entity_type' => 'node', 'type' => 'text_long'])->save();
    FieldConfig::create(['field_name' => 'page_text', 'entity_type' => 'node', 'bundle' => 'wordpal_page'])->save();
    $mapping = $this->themeRoot . '/page-mapping.yml';
    file_put_contents($mapping, file_get_contents($this->demoMapping()) . "\npage: wordpal_page\npage_body: page_text\n");
    $dropped = $this->themeRoot . '/no-page-mapping.yml';
    file_put_contents($dropped, file_get_contents($this->demoMapping()) . "\npage: ~\npage_body: ~\n");

    $report = $this->convert($snapshot, $dropped, FALSE, FALSE, FALSE);
    self::assertContains('page Template: the Content mapping drops page', $report->skipped);
    self::assertNull(ContentTemplate::load('node.wordpal_page.full'));

    $site = ContentTemplate::create([
      'id' => 'node.wordpal_page.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'wordpal_page',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
    $site->setStatus(FALSE)->save();
    foreach ([[FALSE, FALSE], [TRUE, TRUE]] as [$enabled, $activate]) {
      $site->setStatus($enabled)->save();
      try {
        $this->runner()->prepare($snapshot, $mapping, 'canvas', TRUE, $activate);
        self::fail('The site content template blocks the conversion.');
      }
      catch (\RuntimeException $exception) {
        self::assertStringContainsString('content_template:node.wordpal_page.full (unmanaged)', $exception->getMessage());
      }
    }
    $site->setStatus(FALSE)->save();

    $report = $this->convert($snapshot, $mapping, TRUE, TRUE, TRUE);

    self::assertSame(['node.wordpal_page.full'], $report->takenOverTemplates);
    self::assertContains('content_template:node.wordpal_page.full', $report->created);
    self::assertTrue(ContentTemplate::load('node.wordpal_page.full')->status());
    self::assertNotSame($site->uuid(), ContentTemplate::load('node.wordpal_page.full')->uuid());
    $pages = Node::loadMultiple($this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('type', 'wordpal_page')->execute());
    self::assertCount(1, $pages, 'Demo content seeds the fixture page.');
    self::assertContains(reset($pages)->uuid(), $this->manifest()['demo_content']['node']);
  }

  /**
   * Tests a single Template's Query becomes a Views block in the post display.
   *
   * The written ids match the intended ones, and the post's render carries
   * the View's cache metadata, so a new post invalidates it.
   */
  public function testSingleTemplateQueryBecomesViewsBlock(): void {
    $snapshot = $this->templateSnapshot([
      'single' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:post-title /--><!-- wp:query {"query":{"perPage":2,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query --></main><!-- /wp:group -->',
    ]);

    $report = $this->convert($snapshot, $this->mappingPath(), FALSE, FALSE, TRUE);

    $loop = QueryLoop::allFromTree([
      new BlockNode('core/query', [
        'query' => [
          'perPage' => 2,
          'offset' => 0,
          'postType' => 'post',
          'order' => 'desc',
          'orderBy' => 'date',
          'inherit' => FALSE,
        ],
      ], '', [
        new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])]),
      ]),
    ], 10)['loops'][0];
    $ids = $this->container->get(CanvasWriter::class)->queryOutputIds('wordpal-fixture', $loop, 'wordpal_post');
    self::assertContains('content_template:node.wordpal_post.full', $report->created);
    self::assertContains('view:' . $ids['view'], $report->created);
    $this->assertManifestMatchesLive($report->created);
    $tree = array_column(ContentTemplate::load('node.wordpal_post.full')->getComponentTree()->getValue(), 'component_id');
    self::assertContains($ids['component'], $tree);

    user_role_grant_permissions('anonymous', ['access content']);
    $posts = [];
    foreach (['First walk', 'Second walk', 'Third walk'] as $offset => $title) {
      $posts[] = $post = Node::create([
        'type' => 'wordpal_post',
        'title' => $title,
        'status' => 1,
        'created' => 1700000000 + $offset,
      ]);
      $post->save();
    }
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($posts[0], 'full');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertStringContainsString('Third walk', $html);
    self::assertStringContainsString('Second walk', $html);
    self::assertContains('node_list', $build['#cache']['tags']);
    self::assertContains('config:views.view.' . $ids['view'], $build['#cache']['tags']);
  }

  /**
   * Tests a template part's Query becomes a View listed in the created outputs.
   */
  public function testTemplatePartQueryBecomesView(): void {
    $snapshot = $this->templateSnapshot([
      'single' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:post-title /--></main><!-- /wp:group -->',
    ]);
    file_put_contents($this->themeRoot . '/snapshots/wordpal-fixture/out/parts/footer-safe.html', '<!-- wp:query {"query":{"perPage":2,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->');

    $report = $this->convert($snapshot, $this->mappingPath(), FALSE, FALSE, TRUE);

    $frames = self::createdIdsOfType($report->created, 'page_variant');
    self::assertCount(1, $frames);
    $components = array_column(PageVariant::load($frames[0])->getComponentTree()->getValue(), 'component_id');
    $blocks = array_filter($components, static fn (string $id): bool => str_starts_with($id, 'block.views_block.'));
    self::assertCount(1, $blocks, 'The footer Query is a View block.');
    self::assertNotContains('sdc.wordpal.query', $components);
    self::assertContains('component:' . reset($blocks), $report->created, 'The View block Component is listed.');
    $this->assertManifestMatchesLive($report->created);
  }

  /**
   * Tests activation grants anonymous visitors comment access, never posting.
   */
  public function testActivationGrantsCommentAccess(): void {
    $mapping = $this->commentsMapping();
    $this->convert($this->commentsSnapshot(), $mapping, FALSE, FALSE, FALSE);
    self::assertFalse(Role::load(RoleInterface::ANONYMOUS_ID)->hasPermission('access comments'), 'Only activation grants the permission.');

    $this->convert($this->commentsSnapshot(), $mapping, TRUE, FALSE, TRUE);
    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    self::assertTrue($anonymous->hasPermission('access comments'));
    self::assertFalse($anonymous->hasPermission('post comments'));
  }

  /**
   * Tests a failed activating conversion revokes the comment access it gave.
   */
  public function testFailedConversionRevokesCommentAccess(): void {
    $mapping = $this->commentsMapping();
    touch(dirname($this->recipeDirectory()));

    try {
      $this->convert($this->commentsSnapshot(), $mapping, FALSE, FALSE, TRUE);
      self::fail('The Site recipe cannot be written.');
    }
    catch (\RuntimeException) {
    }

    self::assertFalse(Role::load(RoleInterface::ANONYMOUS_ID)->hasPermission('access comments'));
  }

  /**
   * Returns the fixture snapshot with a single Template that shows comments.
   */
  private function commentsSnapshot(): Snapshot {
    return $this->templateSnapshot([
      'single' => '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:comments --><div class="wp-block-comments"><!-- wp:comments-title /--></div><!-- /wp:comments --></main><!-- /wp:group -->',
    ]);
  }

  /**
   * Returns the fixture Content mapping, with a comment field bound.
   */
  private function commentsMapping(): string {
    CommentType::create(['id' => 'comment', 'label' => 'Comment', 'target_entity_type_id' => 'node'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_comments',
      'entity_type' => 'node',
      'type' => 'comment',
      'settings' => ['comment_type' => 'comment'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_comments', 'entity_type' => 'node', 'bundle' => 'wordpal_post'])->save();
    $this->container->get(ComponentSourceManager::class)->generateComponents('block', ['wordpal_comments']);
    $path = $this->themeRoot . '/comments-mapping.yml';
    file_put_contents($path, file_get_contents($this->mappingPath()) . "comments: field_comments\n");
    return $path;
  }

  /**
   * Tests --no-activate leaves the theme, default theme and front unchanged.
   */
  public function testNoActivateLeavesSite(): void {
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.site')->set('page.front', '/user/login')->save();

    $report = $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, FALSE);

    self::assertContains('canvas_page:1', $report->created);
    self::assertFalse($this->container->get('theme_handler')->themeExists(self::THEME_ID));
    self::assertSame('stark', $this->config('system.theme')->get('default'));
    self::assertSame('/user/login', $this->config('system.site')->get('page.front'));
    self::assertArrayNotHasKey(self::THEME_ID . '.settings', $this->recipe()['config']['actions'], 'A theme never installed has no style_variation setting to repeat.');
  }

  /**
   * Writes a Content mapping that maps posts to one bundle.
   */
  private function bundleMapping(string $bundle): string {
    $path = $this->themeRoot . "/mapping-$bundle.yml";
    file_put_contents($path, "post: $bundle\ntag: ~\n");
    return $path;
  }

  /**
   * Asserts the manifest lists exactly the theme's live entities.
   *
   * Every entity of a manifest type whose id carries the theme id, and every
   * content template, belongs to this conversion in this test. A saved id
   * missing from the manifest is an orphan a later replace cannot delete.
   *
   * @param string[]|null $created
   *   The conversion report's created lines, also compared when given.
   */
  private function assertManifestMatchesLive(?array $created = NULL): void {
    $manifest = $this->container->get('config.factory')->get('wordpal_convert.conversion.' . self::THEME_ID)->get('entities');
    $entityTypeManager = $this->container->get('entity_type.manager');
    foreach (ConversionOwnership::ENTITY_TYPES as $type) {
      $live = [];
      // This Canvas-target test never enables the module another target
      // saves its own entity type in, such as display_builder's
      // pattern_preset, so the manifest carries no ids for it either.
      if ($entityTypeManager->hasDefinition($type)) {
        foreach (array_keys($entityTypeManager->getStorage($type)->loadMultiple()) as $id) {
          if ($type === 'content_template' || str_contains((string) $id, self::THEME_ID)) {
            $live[] = (string) $id;
          }
        }
      }
      sort($live);
      self::assertSame($manifest[$type], $live, "Manifest $type ids must equal the live $type entities.");
      if ($created !== NULL) {
        $reported = self::entitiesOfType($created, $type);
        sort($reported);
        self::assertSame($manifest[$type], $reported, "Manifest $type ids must equal the saved $type entities.");
      }
    }
  }

  /**
   * Returns the ids of created entities of one type.
   */
  private static function entitiesOfType(array $created, string $type): array {
    $ids = [];
    foreach ($created as $line) {
      if (str_starts_with($line, "$type:")) {
        $ids[] = substr($line, strlen($type) + 1);
      }
    }
    return $ids;
  }

  /**
   * Tests a display_builder attempt on a Canvas-converted theme refuses.
   *
   * The non-replace branch of prepare() throws once the theme already has
   * an ownership manifest, whatever the new target's own collisions are
   * (the `else` branch after the `if ($replace)` in ConversionRunner.php);
   * existingOwned() lists the first conversion's own still-live Canvas
   * outputs, so the refusal names one of them even though the two targets
   * save disjoint entity types.
   */
  public function testDisplayBuilderTargetRefusesCanvasConvertedTheme(): void {
    $this->convert($this->snapshot(), $this->mappingPath(), FALSE, FALSE, TRUE);

    $this->enableModules([
      'wordpal_display_builder', 'wordpal_display_builder_runtime',
      'display_builder', 'display_builder_page_layout', 'display_builder_entity_view',
      'ui_patterns', 'ui_patterns_field',
    ]);
    $this->installEntitySchema('display_builder_instance');
    $this->installConfig(['ui_patterns', 'display_builder']);

    try {
      $this->runner()->prepare($this->snapshot(), $this->mappingPath(), 'display_builder', FALSE, TRUE);
      self::fail('A second target without --replace must refuse.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('is already converted on this site: ', $exception->getMessage(), 'The message counts the existing outputs.');
      self::assertStringContainsString('--replace', $exception->getMessage());
    }
  }

}
