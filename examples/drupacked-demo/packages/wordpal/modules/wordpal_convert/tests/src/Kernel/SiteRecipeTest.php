<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\comment\Entity\Comment;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Recipe\Recipe;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\wordpal_convert\SiteRecipeWriter;
use Drupal\wordpal_convert\TemplatePage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Writes the fixture conversion's Site recipe, then applies it elsewhere.
 *
 * The second test runs in a fresh site holding Canvas and the content model,
 * without the converter modules, the way production does. The first test
 * returns copies of its recipe from before and after a --replace
 * reconversion, which PHPUnit passes to the second.
 */
#[CoversClass(SiteRecipeWriter::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class SiteRecipeTest extends CanvasKernelTestBase {

  use CommentTestTrait;
  use FixtureConversionTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'field', 'node', 'taxonomy',
  ];

  /**
   * The Drupal machine name of the fixture theme.
   */
  private const THEME_ID = 'wordpal_fixture';

  /**
   * The WordPal components the fixture's trees use.
   *
   * Installing a module generates its Canvas components, and a kernel test
   * installs WordPal without that hook.
   */
  private const COMPONENTS = [
    'wordpal:group', 'wordpal:paragraph', 'wordpal:query', 'wordpal:post-template',
    'wordpal:post-title', 'wordpal:post-terms', 'wordpal:query-no-results',
    'wordpal:frozen-html',
  ];

  /**
   * The directory of the WordPal recipe's test stand-in and its base.
   */
  private const STAND_IN = __DIR__ . '/../../../../wordpal_convert/tests/fixtures/site/recipes';

  /**
   * The fixture 404 Template's main paragraph.
   */
  private const NOT_FOUND = 'Nothing here.';

  /**
   * Isolated generated-theme and recipes root.
   */
  private string $themeRoot;

  /**
   * Installs the entity tables Canvas and the content model use.
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term', 'path_alias', 'file', 'media'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['field', 'filter', 'node', 'user', 'views']);
    // Site recipes go beside the Drupal root, in the project directory.
    $this->themeRoot = sys_get_temp_dir() . '/wordpal-recipe-' . bin2hex(random_bytes(6)) . '/web';
    mkdir($this->themeRoot, 0777, TRUE);
  }

  /**
   * Removes the isolated root.
   */
  protected function tearDown(): void {
    (new Filesystem())->remove(dirname($this->themeRoot));
    parent::tearDown();
  }

  /**
   * Tests the recipe holds the manifest's config and content.
   *
   * A reconversion rewrites the whole directory.
   *
   * @return array{0: string, 1: string}
   *   Copies of the recipe, in private temporary directories, from before
   *   and after the reconversion.
   */
  public function testRecipeHoldsManifest(): array {
    [$snapshot, $mapping] = $this->convertBlog();

    $directory = $this->recipeDirectory();
    $manifest = $this->manifest();
    self::assertSame('wordpal_' . self::THEME_ID, $manifest['recipe']);
    $names = $this->manifestConfig();
    self::assertSame($names, self::recipeConfig($directory), 'The recipe config is the manifest config.');
    $storage = $this->container->get('config.storage');
    foreach ($names as $name) {
      $active = $storage->read($name);
      unset($active['_core']);
      self::assertSame($active, Yaml::decode(file_get_contents("$directory/config/$name.yml")), "$name matches active config, uuid included.");
    }

    $uuids = array_values($manifest['pages']);
    self::assertNotSame([], $manifest['demo_content']['node']);
    foreach ($manifest['demo_content'] as $ids) {
      $uuids = [...$uuids, ...$ids];
    }
    $exported = array_map(static fn (string $path): string => basename($path, '.yml'), glob("$directory/content/*/*.yml"));
    sort($uuids);
    sort($exported);
    self::assertSame($uuids, $exported, 'The recipe content is the Owned pages and Demo content.');
    $post = Yaml::decode(file_get_contents("$directory/content/node/" . $manifest['demo_content']['node'][0] . '.yml'));
    self::assertArrayHasKey('created', $post['default'], 'Creation dates order the Demo posts.');

    $recipe = Yaml::decode(file_get_contents("$directory/recipe.yml"));
    self::assertSame('Site', $recipe['type']);
    self::assertContains('wordpal', $recipe['install']);
    self::assertContains('canvas', $recipe['install']);
    self::assertSame(self::THEME_ID, end($recipe['install']));
    self::assertSame([], array_intersect(['wordpal_canvas', 'wordpal_convert'], $recipe['install']));
    self::assertFalse($recipe['config']['strict']);
    self::assertSame(['wordpal'], $recipe['recipes']);
    $templates = [];
    foreach ($manifest['entities']['content_template'] as $id) {
      $active = $storage->read("canvas.content_template.$id");
      $templates["canvas.content_template.$id"]['setProperties'] = [
        'status' => $active['status'],
        'component_tree' => $active['component_tree'],
        'exposed_slots' => $active['exposed_slots'],
        'page_variant' => $active['page_variant'],
      ];
    }
    self::assertSame($templates + [
      'editor.editor.canvas_html_inline' => [
        'set' => [
          'property_name' => 'settings',
          'value' => $storage->read('editor.editor.canvas_html_inline')['settings'],
        ],
      ],
      'filter.format.canvas_html_inline' => [
        'setFilterConfig' => [
          'instance_id' => 'filter_html',
          'configuration' => $storage->read('filter.format.canvas_html_inline')['filters']['filter_html'],
        ],
      ],
      'wordpal_fixture.settings' => ['simpleConfigUpdate' => ['style_variation' => 'default']],
      'system.theme' => ['simpleConfigUpdate' => ['default' => self::THEME_ID]],
      'system.site' => [
        'simpleConfigUpdate' => [
          'page.front' => TemplatePage::alias(self::THEME_ID, 'home'),
          'page.404' => TemplatePage::alias(self::THEME_ID, 'not_found'),
        ],
      ],
    ], $recipe['config']['actions'], 'With no page Template, the recipe sets no default frame.');

    $beforeReplace = $this->copyRecipe($directory);

    file_put_contents("$directory/config/stale.yml", 'id: stale');
    $this->convert($snapshot, $mapping, TRUE, TRUE, TRUE);
    self::assertFileDoesNotExist("$directory/config/stale.yml", 'A reconversion rewrites the recipe.');
    self::assertSame($this->manifestConfig(), self::recipeConfig($directory));

    return [$beforeReplace, $this->copyRecipe($directory)];
  }

  /**
   * Tests the writer's left-out content, site wiring and failures.
   */
  public function testRecipeWriterBranches(): void {
    $this->convertBlog();
    $manifest = $this->manifest();
    $author = $this->container->get('entity.repository')->loadEntityByUuid('user', $manifest['demo_content']['user'][0]);
    $author->setPassword('local secret')->save();
    // A field the Demo posts lack, like Drupal CMS's page description.
    FieldStorageConfig::create(['field_name' => 'field_summary', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create([
      'field_name' => 'field_summary',
      'entity_type' => 'node',
      'bundle' => 'blog',
      'required' => TRUE,
    ])->save();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $invalid = $manifest['demo_content']['node'][0];
    $this->config('wordpal_convert.conversion.' . self::THEME_ID)->set('replaced_views', ['taxonomy_term'])->save();

    $leftOut = $this->recipeWriter()->write(self::THEME_ID);

    self::assertCount(count($manifest['demo_content']['node']), $leftOut);
    self::assertContains("Site recipe: node $invalid: field_summary: This value should not be null.", $leftOut);
    $directory = $this->recipeDirectory();
    self::assertFileDoesNotExist("$directory/content/node/$invalid.yml", 'An invalid entity is left out.');
    $user = Yaml::decode(file_get_contents("$directory/content/user/" . $author->uuid() . '.yml'));
    self::assertArrayNotHasKey('pass', $user['default'], 'Password hashes stay out of the recipe.');
    self::assertSame(['setStatus' => FALSE], $this->recipe()['config']['actions']['views.view.taxonomy_term']);

    // The local site no longer points at the conversion, as after a
    // conversion with --no-activate.
    $site = $this->config('system.site');
    $this->config('system.theme')->set('default', 'stark')->save();
    $site->set('page.front', '/node')->set('page.404', '')->save();
    $this->config('canvas.settings')->set('default_page_variant', NULL)->save();
    $this->recipeWriter()->write(self::THEME_ID);
    $actions = $this->recipe()['config']['actions'];
    foreach (['system.theme', 'system.site', 'canvas.settings'] as $name) {
      self::assertArrayNotHasKey($name, $actions);
    }

    $view = 'views.view.' . $manifest['entities']['view'][0];
    $configStorage = $this->container->get('config.storage');
    $data = $configStorage->read($view);
    $data['dependencies']['module'][] = 'wordpal_canvas';
    $configStorage->write($view, $data);
    try {
      $this->recipeWriter()->write(self::THEME_ID);
      self::fail('Owned config depending on a converter module must fail the recipe.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame("Owned config $view depends on wordpal_canvas, which production does not install.", $exception->getMessage());
    }
    self::assertArrayNotHasKey('system.site', $this->recipe()['config']['actions'], 'The previous recipe stays in place.');
    self::assertSame([], glob("$directory.*"), 'No staging directory is left behind.');
  }

  /**
   * Tests the recipe reproduces home, single and 404 without the converter.
   *
   * The site holds WordPal and its Canvas components before the recipe
   * applies, as production does after installing WordPal. The WordPal
   * recipe's test stand-in sits beside the Site recipe.
   */
  #[Depends('testRecipeHoldsManifest')]
  public function testRecipeAppliesOnFreshSite(array $recipeCopies): void {
    [$recipe, $replacedRecipe] = $recipeCopies;
    // Content import runs as the site's first administrator.
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    $this->enableModules(['comment', 'menu_link_content', 'wordpal']);
    $this->installWordpalSchemas();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', self::COMPONENTS);
    // Core resolves the WordPal recipe beside the Site recipe.
    $recipes = dirname($recipe);
    foreach (['wordpal', 'wordpal_test_base'] as $name) {
      (new Filesystem())->mirror(self::STAND_IN . "/$name", "$recipes/$name");
    }
    try {
      RecipeRunner::processRecipe(Recipe::createFromDirectory($recipe));
      // The theme's own settings are simple config and carry no uuid.
      $storage = $this->container->get('config.storage');
      $entities = 0;
      foreach (self::recipeConfig($recipe) as $name) {
        $file = Yaml::decode(file_get_contents("$recipe/config/$name.yml"));
        self::assertSame($file['uuid'] ?? NULL, $storage->read($name)['uuid'] ?? NULL, "$name keeps the recipe's uuid, so a later config import updates it.");
        $entities += isset($file['uuid']) ? 1 : 0;
      }
      self::assertGreaterThan(0, $entities, 'The recipe holds config entities with uuids.');
    }
    finally {
      (new Filesystem())->remove($recipes);
    }

    // The README redeploy path re-exports the conversion site after a
    // further --replace and imports that config into production. A stable
    // uuid per Owned entity keeps that import an update, never a
    // delete-then-create pair (StorageComparer::addChangelistUpdate()).
    // Component stays out: ComponentSourceManager::generateComponents()
    // creates and saves views_block Component entities itself, so
    // CanvasWriter has no create() call of its own to give them a uuid.
    $entityTypeManager = $this->container->get('entity_type.manager');
    $prefixes = array_map(
      static fn (string $type): string => $entityTypeManager->getDefinition($type)->getConfigPrefix() . '.',
      ['pattern', 'page_variant', 'content_template', 'view', 'entity_view_mode'],
    );
    $ownsName = static function (string $name) use ($prefixes): bool {
      foreach ($prefixes as $prefix) {
        if (str_starts_with($name, $prefix)) {
          return TRUE;
        }
      }
      return FALSE;
    };
    $comparer = new StorageComparer(new FileStorage("$replacedRecipe/config"), $this->container->get('config.storage'));
    $comparer->createChangelist();
    $recreated = array_intersect($comparer->getChangelist('create'), $comparer->getChangelist('delete'));
    self::assertSame([], array_values(array_filter($recreated, $ownsName)), 'A redeploy after --replace must update Owned entities, never recreate them.');

    user_role_grant_permissions('anonymous', ['access content']);
    $moduleHandler = $this->container->get('module_handler');
    self::assertTrue($moduleHandler->moduleExists('wordpal'));
    self::assertFalse($moduleHandler->moduleExists('wordpal_canvas'));
    self::assertFalse($moduleHandler->moduleExists('wordpal_convert'));
    self::assertSame(self::THEME_ID, $this->config('system.theme')->get('default'));

    // The recipe changed the default theme after the active one was chosen.
    $this->container->get('theme.manager')->resetActiveTheme();
    $home = $this->request('/');
    self::assertSame(200, $home->getStatusCode());
    self::assertStringContainsString('Welcome home.', $home->getContent());

    $posts = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['type' => 'blog']);
    self::assertNotSame([], $posts, 'The Demo posts are imported.');
    $single = $this->request('/node/' . reset($posts)->id());
    self::assertSame(200, $single->getStatusCode());
    self::assertStringContainsString('Single post area.', $single->getContent());

    $notFound = $this->request('/no-such-page');
    self::assertSame(404, $notFound->getStatusCode());
    self::assertStringContainsString(self::NOT_FOUND, $notFound->getContent());
  }

  /**
   * Tests the recipe leaves Demo comments out and reports them.
   *
   * Core's importer gives an ownerless entity to the administrator, so an
   * anonymous comment's author name fails validation on import.
   */
  public function testDemoCommentsStayOut(): void {
    $this->convertBlog();
    // The comment body field storage.
    $this->installConfig(['comment']);
    $this->addDefaultCommentField('node', 'blog', 'field_comments');
    $manifest = $this->manifest();
    $post = $this->container->get('entity.repository')->loadEntityByUuid('node', $manifest['demo_content']['node'][0]);
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $post->id(),
      'field_name' => 'field_comments',
      'uid' => 0,
      'name' => 'Alex Kim',
      'comment_body' => 'A demo comment.',
    ]);
    $comment->save();
    $this->config('wordpal_convert.conversion.' . self::THEME_ID)->set('demo_content.comment', [$comment->uuid()])->save();

    $leftOut = $this->recipeWriter()->write(self::THEME_ID);

    self::assertSame(["Site recipe: 1 Demo comments: core's importer gives anonymous content to the administrator."], $leftOut);
    self::assertDirectoryDoesNotExist($this->recipeDirectory() . '/content/comment');
  }

  /**
   * Converts the fixture plus a 404 Template onto the blog bundle.
   *
   * @return array{0: \Drupal\wordpal_convert\WordPress\Snapshot, 1: string}
   *   The snapshot and the mapping path, for a reconversion.
   */
  private function convertBlog(): array {
    $this->enableModules([
      'comment', 'menu_link_content', 'wordpal', 'wordpal_convert', 'wordpal_canvas', 'wordpal_canvas_runtime',
    ]);
    $this->installWordpalSchemas();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', self::COMPONENTS);
    $snapshot = $this->templateSnapshot(['404' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:paragraph --><p>' . self::NOT_FOUND . '</p><!-- /wp:paragraph --></main><!-- /wp:group -->']);

    // The WordPal recipe's test stand-in creates the blog bundle, as
    // wordpal:convert applies the WordPal recipe for the default mapping.
    RecipeRunner::processRecipe(Recipe::createFromDirectory(self::STAND_IN . '/wordpal'));
    $mapping = $this->themeRoot . '/blog-mapping.yml';
    file_put_contents($mapping, <<<YAML
      post: blog
      post_body: field_content
      tag: { vocabulary: tags, field: field_tags }
      navigation: main
      YAML);

    $this->convert($snapshot, $mapping, FALSE, TRUE, TRUE);
    return [$snapshot, $mapping];
  }

  /**
   * Installs the tables of WordPal's dependencies and Canvas pages.
   */
  private function installWordpalSchemas(): void {
    foreach (['comment', 'menu_link_content', 'canvas_page'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('comment', ['comment_entity_statistics']);
  }

  /**
   * Returns the config names the manifest lists, sorted.
   */
  private function manifestConfig(): array {
    $manifest = $this->manifest();
    $names = $manifest['config'];
    foreach ($manifest['entities'] as $type => $ids) {
      foreach ($ids as $id) {
        $names[] = $this->container->get('entity_type.manager')->getDefinition($type)->getConfigPrefix() . ".$id";
      }
    }
    sort($names);
    return $names;
  }

  /**
   * Returns the config names a recipe directory holds, sorted.
   */
  private static function recipeConfig(string $directory): array {
    return array_map(static fn (string $path): string => basename($path, '.yml'), glob("$directory/config/*.yml"));
  }

  /**
   * Renders one path through the HTTP kernel.
   */
  private function request(string $path): object {
    return $this->container->get('http_kernel')->handle(Request::create($path));
  }

}
