<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\Theme\ResolvedTemplate;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal_canvas\CanvasWriter;
use Drupal\wordpal_convert\ConversionOwnership;
use Drupal\wordpal_convert\SiteRecipeWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests exact ownership across conversion replacement.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ConversionOwnershipTest extends CanvasKernelTestBase {

  /**
   * Modules required for Canvas Pattern storage.
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'wordpal_convert', 'wordpal_canvas', 'wordpal_canvas_runtime', 'system', 'user', 'field', 'node',
    'text', 'file', 'image', 'views', 'path', 'path_alias', 'block', 'comment',
    'ui_patterns', 'ui_patterns_field', 'display_builder', 'display_builder_entity_view',
  ];

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
    // Site recipes go beside the Drupal root, in the project directory.
    $this->themeRoot = sys_get_temp_dir() . '/wordpal-ownership-' . bin2hex(random_bytes(6)) . '/web';
    mkdir($this->themeRoot . '/themes/custom/twentytwentyfour', 0777, TRUE);
    mkdir($this->themeRoot . '/themes/custom/twentytwentyone', 0777, TRUE);
  }

  /**
   * Removes generated test files.
   */
  protected function tearDown(): void {
    if (isset($this->themeRoot)) {
      (new Filesystem())->remove(dirname($this->themeRoot));
    }
    parent::tearDown();
  }

  /**
   * Builds a ConversionOwnership wired to the isolated theme root.
   */
  private function ownership(): ConversionOwnership {
    return new ConversionOwnership(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->themeRoot,
      new ThemeGenerator($this->themeRoot, $this->container->get('module_handler')),
      $this->container->get('entity.repository'),
      NULL,
    );
  }

  /**
   * Builds a manifest with the fixed entity-type shape a save() writes.
   */
  private function manifest(array $entities, array $files, array $pages = [], array $config = [], array $fullDisplays = []): array {
    return [
      'page_entity_type' => 'canvas_page',
      'entities' => $entities + array_fill_keys(ConversionOwnership::ENTITY_TYPES, []),
      'config' => $config,
      'full_displays' => $fullDisplays,
      'files' => $files,
      'pages' => $pages,
      'demo_content' => array_fill_keys(DemoContentSeeder::ENTITY_TYPES, []),
      'recipe' => 'wordpal_twentytwentyfour',
      'replaced_views' => [],
    ];
  }

  /**
   * Tests replacement removes owned outputs and preserves same-prefix files.
   */
  public function testReplacementDeletesExactOwnedOutputs(): void {
    $ownership = $this->ownership();
    $old = $this->manifest(
      ['pattern' => ['twentytwentyfour_owned']],
      ['themes/custom/twentytwentyfour/owned.css'],
    );
    Pattern::create(['id' => 'twentytwentyfour_owned', 'label' => 'Owned', 'component_tree' => []])->save();
    Pattern::create(['id' => 'twentytwentyfour_unmanaged', 'label' => 'Unmanaged', 'component_tree' => []])->save();
    file_put_contents($this->themeRoot . '/themes/custom/twentytwentyfour/owned.css', 'owned');
    file_put_contents($this->themeRoot . '/themes/custom/twentytwentyfour/unmanaged.css', 'unmanaged');
    $recipes = dirname($this->themeRoot) . '/recipes';
    $filesystem = new Filesystem();
    $filesystem->dumpFile("$recipes/wordpal_twentytwentyfour/recipe.yml", 'name: Owned');
    $filesystem->dumpFile("$recipes/wordpal/recipe.yml", 'name: WordPal');

    $collisions = $ownership->collisions('twentytwentyfour', $old);
    self::assertContains('pattern:twentytwentyfour_owned (unmanaged)', $collisions);
    self::assertContains('recipes/wordpal_twentytwentyfour (unmanaged)', $collisions);
    try {
      $ownership->assertReplaceable('twentytwentyfour', $old);
      self::fail('Replacement must reject outputs with no ownership manifest.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('pattern:twentytwentyfour_owned (unmanaged)', $exception->getMessage());
      self::assertNotNull(Pattern::load('twentytwentyfour_owned'));
      self::assertSame('owned', file_get_contents($this->themeRoot . '/themes/custom/twentytwentyfour/owned.css'));
    }
    $ownership->save('twentytwentyfour', $old);
    self::assertContains('recipes/wordpal_twentytwentyfour (owned)', $ownership->existingOwned('twentytwentyfour'));
    $next = $this->manifest([], []);
    $ownership->assertReplaceable('twentytwentyfour', $next);
    $ownership->delete('twentytwentyfour');

    self::assertNull(Pattern::load('twentytwentyfour_owned'));
    self::assertNotNull(Pattern::load('twentytwentyfour_unmanaged'));
    self::assertFileDoesNotExist($this->themeRoot . '/themes/custom/twentytwentyfour/owned.css');
    self::assertSame('unmanaged', file_get_contents($this->themeRoot . '/themes/custom/twentytwentyfour/unmanaged.css'));
  }

  /**
   * Tests delete() refuses a manifest naming another recipe.
   */
  public function testDeleteRejectsForeignRecipe(): void {
    $ownership = $this->ownership();
    $recipe = dirname($this->themeRoot) . '/recipes/wordpal/recipe.yml';
    (new Filesystem())->dumpFile($recipe, 'name: WordPal');
    $ownership->save('twentytwentyfour', ['recipe' => 'wordpal'] + $this->manifest([], []));

    try {
      $ownership->delete('twentytwentyfour');
      self::fail('delete() must refuse a recipe the theme does not own.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertStringContainsString('names a recipe it cannot own: wordpal', $exception->getMessage());
    }
    self::assertFileExists($recipe);
  }

  /**
   * Tests theme settings collide unless the manifest owns them.
   */
  public function testThemeSettingsOwnership(): void {
    $ownership = $this->ownership();
    $settings = ThemeSettings::configName('twentytwentyfour');
    $intended = $this->manifest([], [], [], [$settings]);
    $this->container->get('config.factory')->getEditable($settings)->setData(['use_root_padding_aware_alignments' => TRUE])->save();

    self::assertSame(["$settings (unmanaged)"], $ownership->collisions('twentytwentyfour', $intended));
    $ownership->save('twentytwentyfour', $intended);
    self::assertSame(["$settings (owned)"], $ownership->collisions('twentytwentyfour', $intended));

    $ownership->delete('twentytwentyfour');
    self::assertSame([], $this->config($settings)->getRawData());
  }

  /**
   * Tests replace deletes a wired-together conversion and recreates it.
   *
   * A PageVariant, View, view mode, Component, Pattern and
   * ContentTemplate reference each other through real config dependencies.
   * Deleting them through the manifest must not leave any of them pointing
   * at a deleted entity, and it must fall the front page back to /node when
   * nothing takes its home page's place.
   */
  public function testReplaceDeletesWiredConversionAndRecreatesIt(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['field', 'node', 'system', 'user', 'views']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', [
      'wordpal:group', 'wordpal:query', 'wordpal:post-template',
      'wordpal:post-title', 'wordpal:query-no-results',
    ]);

    $writer = $this->container->get(CanvasWriter::class);
    $ownership = $this->ownership();
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => NULL,
      'tag' => NULL,
      'featured_image' => NULL,
    ]);

    $home = function (): array {
      $query = new BlockNode('core/query', [
        'query' => ['perPage' => 3, 'offset' => 0, 'postType' => 'post', 'order' => 'desc', 'orderBy' => 'date'],
      ], '', [
        new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])]),
        new BlockNode('core/query-no-results', [], '', []),
      ]);
      return [new BlockNode('core/group', ['tagName' => 'main'], '', [$query])];
    };
    $write = function () use ($writer, $mapping, $home): array {
      $partSet = new PartSet([['type' => 'marker']]);
      $writer->writePageVariant($partSet, 'twentytwentyfour', $mapping, $partSet->loops(10, 1, NULL), NULL, []);
      $homeNodes = $home();
      $loops = QueryLoop::allFromTree($homeNodes, 10)['loops'];
      return $writer->writeQueryPage('home', 'wordpal_post', new ResolvedTemplate($homeNodes, $partSet, $loops, []), $mapping, 'twentytwentyfour');
    };

    $result = $write();
    $loops = QueryLoop::allFromTree($home(), 10)['loops'];
    $ids = $writer->queryOutputIds('twentytwentyfour', $loops[0], 'wordpal_post');
    $intended = $this->manifest(
      [
        'page_variant' => [$result['page']->get('page_variant')->value],
        'view' => [$ids['view']],
        'component' => [$ids['component']],
        'entity_view_mode' => ['node.' . $ids['view_mode']],
        'content_template' => ['node.wordpal_post.' . $ids['view_mode']],
        'pattern' => [$ids['empty_pattern']],
      ],
      [],
      ['home' => $result['page']->uuid()],
    );
    self::assertNotNull(View::load($ids['view']));
    self::assertNotNull(Component::load($ids['component']));
    self::assertNotNull(EntityViewMode::load('node.' . $ids['view_mode']));
    self::assertNotNull(ContentTemplate::load('node.wordpal_post.' . $ids['view_mode']));
    self::assertNotNull(Pattern::load($ids['empty_pattern']));
    $this->config('system.site')
      ->set('page.front', $result['page']->get('path')->alias)
      ->set('page.404', $result['page']->get('path')->alias)
      ->save();
    $this->config('canvas.settings')->set('default_page_variant', $intended['entities']['page_variant'][0])->save();
    $ownership->save('twentytwentyfour', $intended);

    // Replace with nothing to recreate: delete alone must clear every owned
    // entity, without a dangling reference tripping up any of their own
    // preDelete/validation, and fall the front page back to core's default.
    self::assertSame(
      ['front', 'not_found', 'frame'],
      $ownership->delete('twentytwentyfour'),
      'The deleted outputs were the front page, the 404 page and the default frame.',
    );

    self::assertNull(PageVariant::load($intended['entities']['page_variant'][0]));
    self::assertSame('', $this->config('system.site')->get('page.404'), 'A deleted owned page stops being the 404 page.');
    self::assertNull($this->config('canvas.settings')->get('default_page_variant'), 'A deleted owned frame stops being the default page variant.');
    self::assertNull(View::load($ids['view']));
    self::assertNull(Component::load($ids['component']));
    self::assertNull(EntityViewMode::load('node.' . $ids['view_mode']));
    self::assertNull(ContentTemplate::load('node.wordpal_post.' . $ids['view_mode']));
    self::assertNull(Pattern::load($ids['empty_pattern']));
    self::assertSame('/node', $this->config('system.site')->get('page.front'));

    // Recreate: writing the same theme again reuses the same deterministic
    // UUID.
    $recreated = $write();
    self::assertSame($result['page']->uuid(), $recreated['page']->uuid());
    self::assertNotNull(View::load($ids['view']));
  }

  /**
   * Tests replace deletes query outputs two patterns share, once, cleanly.
   *
   * Two Query patterns with the same card and settings save one
   * View, view mode, template, and Component. Saving the second pattern
   * must not collide with what the first already saved, and the manifest
   * must list each shared id once so delete() removes it without erroring
   * on an id it already deleted for the sibling pattern.
   */
  public function testReplaceDeletesSharedQueryOutputsOnce(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('canvas_page');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'system', 'user', 'views']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', [
      'wordpal:group', 'wordpal:query', 'wordpal:post-template', 'wordpal:post-title',
    ]);

    $writer = $this->container->get(CanvasWriter::class);
    $ownership = $this->ownership();
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => NULL,
      'tag' => NULL,
      'featured_image' => NULL,
    ]);
    $query = static fn (): array => [new BlockNode('core/group', ['tagName' => 'main'], '', [
      new BlockNode('core/query', [
        'query' => ['perPage' => 3, 'offset' => 0, 'postType' => 'post', 'order' => 'desc', 'orderBy' => 'date'],
      ], '', [new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])])]),
    ]),
    ];

    $nodesA = $query();
    $resultA = $writer->writeQueryPattern('twentytwentyfour_a', 'A', $nodesA, QueryLoop::allFromTree($nodesA, 10)['loops'], $mapping, 'twentytwentyfour');
    $nodesB = $query();
    $resultB = $writer->writeQueryPattern('twentytwentyfour_b', 'B', $nodesB, QueryLoop::allFromTree($nodesB, 10)['loops'], $mapping, 'twentytwentyfour');

    $queryA = $resultA['queries'][0];
    $queryB = $resultB['queries'][0];
    self::assertSame($queryA['view']->id(), $queryB['view']->id());
    self::assertSame($queryA['view_mode']->id(), $queryB['view_mode']->id());
    self::assertSame($queryA['template']->id(), $queryB['template']->id());
    self::assertSame($queryA['component']->id(), $queryB['component']->id());

    $intended = $this->manifest([
      'pattern' => ['twentytwentyfour_a', 'twentytwentyfour_b'],
      'view' => [$queryA['view']->id()],
      'component' => [$queryA['component']->id()],
      'entity_view_mode' => [$queryA['view_mode']->id()],
      'content_template' => [$queryA['template']->id()],
    ], []);
    $ownership->save('twentytwentyfour', $intended);

    $ownership->delete('twentytwentyfour');

    self::assertNull(View::load($queryA['view']->id()));
    self::assertNull(Component::load($queryA['component']->id()));
    self::assertNull(EntityViewMode::load($queryA['view_mode']->id()));
    self::assertNull(ContentTemplate::load($queryA['template']->id()));
  }

  /**
   * Tests a bundle's full display is Owned by its sources, not deleted.
   *
   * Display Builder's writer tracks a bundle's full display through
   * full_displays instead of an owned entity: the display config is
   * site-wide bundle infrastructure ConversionOwnership never deletes, only
   * the display_builder sources a conversion painted onto it, matching the
   * plan's "a full display Display Builder already builds is an Unmanaged
   * output".
   */
  public function testFullDisplayOwnershipTracksSourcesNotTheConfig(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'system', 'display_builder']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    $displayName = 'core.entity_view_display.node.wordpal_post.full';
    $ownership = $this->ownership();

    $display = $this->container->get('entity_display.repository')->getViewDisplay('node', 'wordpal_post', 'full');
    $display->setThirdPartySetting('display_builder', 'profile', 'default');
    $display->setThirdPartySetting('display_builder', 'sources', [
      ['node_id' => 'a', 'source_id' => 'textfield', 'source' => ['value' => 'hand-built']],
    ]);
    $display->save();

    $intended = $this->manifest([], [], [], [], [$displayName]);
    self::assertSame(["$displayName (unmanaged)"], $ownership->collisions('twentytwentyfour', $intended));
    try {
      $ownership->assertReplaceable('twentytwentyfour', $intended);
      self::fail('A hand-built full display must refuse replacement.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString("$displayName (unmanaged)", $exception->getMessage());
    }

    $ownership->save('twentytwentyfour', $intended);
    self::assertSame(["$displayName (owned)"], $ownership->collisions('twentytwentyfour', $intended));
    $ownership->assertReplaceable('twentytwentyfour', $intended);

    $ownership->delete('twentytwentyfour');

    $cleared = $this->container->get('entity_display.repository')->getViewDisplay('node', 'wordpal_post', 'full');
    self::assertNull($cleared->getThirdPartySetting('display_builder', 'profile'));
    self::assertNull($cleared->getThirdPartySetting('display_builder', 'sources'));
    self::assertNotNull(NodeType::load('wordpal_post'), 'delete() never touches the bundle or its display config entity.');
  }

  /**
   * Builds a bundle's full display with painted Display Builder sources.
   */
  private function paintedFullDisplay(string $bundle): string {
    $displayName = "core.entity_view_display.node.$bundle.full";
    $display = $this->container->get('entity_display.repository')->getViewDisplay('node', $bundle, 'full');
    $display->setThirdPartySetting('display_builder', 'profile', 'default');
    $display->setThirdPartySetting('display_builder', 'sources', [
      ['node_id' => 'a', 'source_id' => 'textfield', 'source' => ['value' => 'painted']],
    ]);
    $display->save();
    return $displayName;
  }

  /**
   * Tests activation moves a full display's ownership between manifests.
   *
   * Two themes mapping the same bundle to Display Builder both intend the
   * bundle's one full display. Activation resolves the collision by moving
   * ownership: the taking-over theme's manifest ends up owning it and the
   * theme that owned it before loses it, so its own next --replace cannot
   * delete what the other theme now owns.
   */
  public function testTakeOverFullDisplaysMovesOwnershipBetweenManifests(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'system', 'display_builder']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    $displayName = $this->paintedFullDisplay('wordpal_post');
    $ownership = $this->ownership();
    $ownership->save('twentytwentyfour', $this->manifest([], [], [], [], [$displayName]));
    $intended = ['recipe' => SiteRecipeWriter::name('twentytwentyone')] + $this->manifest([], [], [], [], [$displayName]);

    $owners = $ownership->fullDisplaysOwnedByOthers('twentytwentyone', $intended['full_displays']);
    self::assertSame(['twentytwentyfour' => [$displayName]], $owners);
    self::assertSame([$displayName], $ownership->load('twentytwentyfour')['full_displays'], 'Finding the owner changes no manifest.');

    $ownership->takeOverFullDisplays($owners);

    self::assertSame([], $ownership->load('twentytwentyfour')['full_displays'], 'The theme that owned it loses it.');
    $ownership->save('twentytwentyone', $intended);
    self::assertSame(["$displayName (owned)"], $ownership->collisions('twentytwentyone', $intended), 'The taking-over theme owns it.');
  }

  /**
   * Tests a shared full display still collides without activation.
   *
   * Only activation takes full displays over; a second theme's own
   * conversion that never activates still sees the bundle's full display,
   * already owned by another theme's manifest, as an unmanaged collision.
   */
  public function testFullDisplayCollisionWithoutActivation(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'system', 'display_builder']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    $displayName = $this->paintedFullDisplay('wordpal_post');
    $ownership = $this->ownership();
    $ownership->save('twentytwentyfour', $this->manifest([], [], [], [], [$displayName]));
    $intended = ['recipe' => SiteRecipeWriter::name('twentytwentyone')] + $this->manifest([], [], [], [], [$displayName]);

    self::assertSame(["$displayName (unmanaged)"], $ownership->collisions('twentytwentyone', $intended));
    try {
      $ownership->assertReplaceable('twentytwentyone', $intended);
      self::fail('A full display another theme owns must refuse replacement without activation.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString("$displayName (unmanaged)", $exception->getMessage());
    }
    self::assertSame([$displayName], $ownership->load('twentytwentyfour')['full_displays'], 'The owning theme keeps it.');
  }

}
