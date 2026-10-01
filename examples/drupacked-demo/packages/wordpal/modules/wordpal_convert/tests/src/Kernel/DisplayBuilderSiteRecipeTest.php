<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\display_builder\Entity\PatternPreset;
use Drupal\display_builder_page_layout\Entity\PageLayout;
use Drupal\KernelTests\KernelTestBase;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\wordpal_convert\OwnedEntityUuid;
use Drupal\wordpal_convert\TemplatePage;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes the fixture's display_builder Site recipe, then applies it.
 *
 * Mirrors SiteRecipeTest, Canvas's version of this same round trip: the
 * first test converts the fixture onto the blog bundle the WordPal recipe's
 * test stand-in provides, and hands a private copy of the recipe plus the
 * ids the conversion created to the second test, which runs in its own
 * kernel container - a fresh site with Display Builder installed but never
 * the converter - and applies the recipe there.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class DisplayBuilderSiteRecipeTest extends KernelTestBase {

  use FixtureConversionTrait;

  /**
   * The Drupal machine name of the fixture theme.
   */
  private const THEME_ID = 'wordpal_fixture';

  /**
   * The directory of the WordPal recipe's test stand-in and its base.
   */
  private const STAND_IN = __DIR__ . '/../../fixtures/site/recipes';

  /**
   * The fixture 404 Template's main paragraph.
   */
  private const NOT_FOUND = 'Nothing here.';

  /**
   * Isolated generated-theme and recipes root.
   */
  private string $themeRoot;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'display_builder', 'display_builder_page_layout', 'display_builder_entity_view', 'display_builder_views',
    'ui_patterns', 'ui_patterns_field', 'ui_patterns_views', 'system', 'user', 'field', 'node',
    'text', 'file', 'image', 'views', 'path', 'path_alias', 'block',
    'comment', 'filter', 'taxonomy', 'link', 'menu_link_content',
  ];

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
    $this->themeRoot = sys_get_temp_dir() . '/wordpal-db-recipe-' . bin2hex(random_bytes(6)) . '/web';
    mkdir($this->themeRoot, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    (new Filesystem())->remove(dirname($this->themeRoot));
    parent::tearDown();
  }

  /**
   * Converts the fixture plus a 404 Template onto the blog bundle.
   *
   * @return array{0: string, 1: string[]}
   *   A private copy of the recipe directory, and the report's created
   *   lines, for the second test to reload by id after reapplying.
   */
  public function testRecipeHoldsManifest(): array {
    $this->enableModules(['wordpal', 'wordpal_convert', 'wordpal_display_builder', 'wordpal_display_builder_runtime']);
    RecipeRunner::processRecipe(Recipe::createFromDirectory(self::STAND_IN . '/wordpal'));
    $snapshot = $this->snapshotWithTemplates(['404' => '<!-- wp:group {"tagName":"main"} --><main><!-- wp:paragraph --><p>' . self::NOT_FOUND . '</p><!-- /wp:paragraph --></main><!-- /wp:group -->']);
    $mapping = $this->themeRoot . '/blog-mapping.yml';
    file_put_contents($mapping, <<<YAML
      post: blog
      post_body: field_content
      page: blog
      tag: { vocabulary: tags, field: field_tags }
      navigation: main
      YAML);

    $report = $this->convert($snapshot, $mapping, FALSE, FALSE, TRUE, 'display_builder');

    $themeId = ThemeGenerator::themeId('wordpal-fixture');
    foreach (TemplatePage::ROUTES as $route) {
      $node = Yaml::decode(file_get_contents($this->recipeDirectory() . '/content/node/' . TemplatePage::uuid($themeId, $route) . '.yml'));
      self::assertSame(
        ['alias' => TemplatePage::alias($themeId, $route), 'langcode' => 'en', 'pathauto' => 0],
        $node['default']['path'][0],
        "The $route node carries pathauto's skip state beside its alias.",
      );
    }
    $recipe = Yaml::decode(file_get_contents($this->recipeDirectory() . '/recipe.yml'));
    self::assertSame(
      ['islands.tokens.status' => TRUE],
      $recipe['config']['actions']['display_builder.profile.default']['setProperties'],
      "The recipe turns on the profile's Design tokens island, which Display Builder ships off.",
    );
    return [$this->copyRecipe($this->recipeDirectory()), $report->created];
  }

  /**
   * Tests the recipe reproduces the pages and frames without the converter.
   *
   * The site holds Display Builder before the recipe applies, but never the
   * converter, the views submodule or the default profile, which Display
   * Builder ships as optional config. It holds pathauto with a title
   * pattern for blog nodes, as Drupal CMS does. The WordPal recipe's test
   * stand-in sits beside the Site recipe.
   *
   * @param array{0: string, 1: string[]} $conversion
   *   The recipe directory copy and created-entity lines from
   *   testRecipeHoldsManifest().
   */
  #[Depends('testRecipeHoldsManifest')]
  public function testRecipeAppliesOnFreshSite(array $conversion): void {
    [$recipe, $created] = $conversion;
    // Content import runs as the site's first administrator.
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    $this->enableModules(['wordpal']);
    $this->disableModules(['display_builder_views']);
    $this->container->get('entity_type.manager')->getStorage('display_builder_profile')->load('default')->delete();
    $this->enableModules(['token', 'pathauto']);
    $this->installConfig(['pathauto']);
    PathautoPattern::create([
      'id' => 'blog',
      'label' => 'Blog',
      'type' => 'canonical_entities:node',
      'pattern' => '[node:title]',
      'selection_criteria' => [[
        'id' => 'entity_bundle:node',
        'bundles' => ['blog' => 'blog'],
        'negate' => FALSE,
        'context_mapping' => ['node' => 'node'],
      ],
      ],
    ])->save();
    // Core resolves the WordPal recipe beside the Site recipe.
    $recipes = dirname($recipe);
    foreach (['wordpal', 'wordpal_test_base'] as $name) {
      (new Filesystem())->mirror(self::STAND_IN . "/$name", "$recipes/$name");
    }
    try {
      RecipeRunner::processRecipe(Recipe::createFromDirectory($recipe));
    }
    finally {
      (new Filesystem())->remove($recipes);
    }

    $moduleHandler = $this->container->get('module_handler');
    self::assertTrue($moduleHandler->moduleExists('wordpal_display_builder_runtime'));
    self::assertTrue($moduleHandler->moduleExists('display_builder_views'), 'The recipe installs the views submodule its listing Views render through.');
    self::assertNotNull($this->container->get('entity_type.manager')->getStorage('display_builder_profile')->load('default'), 'The recipe imports the profile its outputs name.');
    $profile = $this->container->get('entity_type.manager')->getStorage('display_builder_profile')->load('default');
    self::assertTrue($profile->get('islands')['tokens']['status'], 'The recipe enables the profile\'s Design tokens island.');
    self::assertFalse($moduleHandler->moduleExists('wordpal_display_builder'));
    self::assertFalse($moduleHandler->moduleExists('wordpal_convert'));

    $themeId = ThemeGenerator::themeId('wordpal-fixture');
    $entityRepository = $this->container->get('entity.repository');
    foreach (['home', 'not_found'] as $route) {
      $node = $entityRepository->loadEntityByUuid('node', TemplatePage::uuid($themeId, $route));
      self::assertNotNull($node, "The $route node is imported.");
      self::assertNotEmpty($node->get('field_display_full')->getValue(), "The $route node carries its override field content.");
      self::assertSame(TemplatePage::alias($themeId, $route), $node->get('path')->alias, "The $route node keeps its alias.");
    }

    $display = $this->container->get('entity_display.repository')->getViewDisplay('node', 'blog', 'full');
    self::assertNotEmpty($display->getThirdPartySetting('display_builder', 'sources'), "The blog bundle's full display carries the single Template's sources.");
    self::assertNotEmpty($display->getThirdPartySetting('display_builder', 'override_field'), "The blog bundle's full display still carries the static pages' override field.");

    foreach (self::createdIdsOfType($created, 'pattern_preset') as $id) {
      $preset = PatternPreset::load($id);
      self::assertNotNull($preset, "Pattern preset $id is imported.");
      self::assertSame(OwnedEntityUuid::uuid('pattern_preset', $id), $preset->uuid(), "Pattern preset $id keeps its deterministic uuid.");
    }
    foreach (self::createdIdsOfType($created, 'view') as $id) {
      $view = View::load($id);
      self::assertNotNull($view, "Query view $id is imported.");
      self::assertSame(OwnedEntityUuid::uuid('view', $id), $view->uuid(), "Query view $id keeps its deterministic uuid.");
    }
    foreach (self::createdIdsOfType($created, 'page_layout') as $id) {
      self::assertNotNull(PageLayout::load($id), "Page layout $id is imported.");
    }
  }

}
