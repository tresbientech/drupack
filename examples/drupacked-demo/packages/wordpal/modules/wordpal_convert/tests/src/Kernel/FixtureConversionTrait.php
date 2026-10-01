<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DefaultContent\AdminAccountSwitcher;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Content\ContentMappingLoader;
use Drupal\wordpal_convert\Content\ContentMappingTargetValidator;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal_convert\Theme\ThemeTrees;
use Drupal\wordpal_convert\WordPress\PlaygroundRunner;
use Drupal\wordpal_convert\WordPress\Snapshot;
use Drupal\wordpal_canvas\CanvasWriter;
use Drupal\wordpal_convert\ConversionOwnership;
use Drupal\wordpal_convert\ConversionReport;
use Drupal\wordpal_convert\ConversionRunner;
use Drupal\wordpal_convert\NavigationMenus;
use Drupal\wordpal_convert\EventSubscriber\SiteRecipeExport;
use Drupal\wordpal_convert\SiteRecipeWriter;
use Drupal\wordpal_convert\WriterSelector;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Converts the fixture snapshot into an isolated theme and recipes root.
 *
 * The using class sets $themeRoot, the Drupal root, and names the fixture
 * theme in THEME_ID. Site recipes go to the recipes directory beside it.
 */
trait FixtureConversionTrait {

  /**
   * Returns a copy of the fixture snapshot with more Templates.
   *
   * @param array<string, string> $mains
   *   Each Template's main block markup, keyed by Template name.
   */
  private function templateSnapshot(array $mains): Snapshot {
    $directory = $this->themeRoot . '/snapshots/wordpal-fixture';
    (new Filesystem())->mirror(dirname(__DIR__, 2) . '/fixtures/snapshot/wordpal-fixture', $directory);
    $index = json_decode(file_get_contents("$directory/out/render-index.json"), TRUE);
    foreach ($mains as $name => $main) {
      file_put_contents("$directory/out/templates/$name.html", '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->' . $main . '<!-- wp:template-part {"slug":"footer-safe","area":"footer","tagName":"footer"} /-->');
      $index["templates/$name.html"] = ['0' => ''];
    }
    file_put_contents("$directory/out/render-index.json", json_encode($index));
    return $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $this->container->get(ComponentSet::class)->wordPressVersion());
  }

  /**
   * Returns the fixture snapshot, loaded the way wordpal:convert loads it.
   */
  private function snapshot(): Snapshot {
    $directory = dirname(__DIR__, 2) . '/fixtures/snapshot/wordpal-fixture';
    $version = $this->container->get(ComponentSet::class)->wordPressVersion();
    $snapshot = $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $version);
    self::assertNotNull($snapshot, 'The fixture snapshot must match the pinned WordPress version.');
    return $snapshot;
  }

  /**
   * Returns the fixture theme's ownership manifest.
   */
  private function manifest(): array {
    return $this->container->get('config.factory')->get('wordpal_convert.conversion.' . self::THEME_ID)->getRawData();
  }

  /**
   * Writes a Content mapping binding every concept Demo content fills.
   *
   * Creates the post body, tags, image and excerpt fields it names.
   */
  private function demoMapping(): string {
    $path = $this->themeRoot . '/demo-mapping.yml';
    if (is_file($path)) {
      return $path;
    }
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $fields = [
      'body' => ['type' => 'text_long'],
      'field_tags' => [
        'type' => 'entity_reference',
        'storage' => [
          'settings' => ['target_type' => 'taxonomy_term'],
          'cardinality' => -1,
        ],
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => ['target_bundles' => ['tags' => 'tags']],
        ],
      ],
      'field_image' => ['type' => 'image'],
      'field_excerpt' => ['type' => 'string_long'],
    ];
    foreach ($fields as $name => $field) {
      $base = ['field_name' => $name, 'entity_type' => 'node'];
      FieldStorageConfig::create($base + ['type' => $field['type']] + ($field['storage'] ?? []))->save();
      FieldConfig::create($base + ['bundle' => 'wordpal_post', 'settings' => $field['settings'] ?? []])->save();
    }
    file_put_contents($path, <<<YAML
      post: wordpal_post
      post_body: body
      tag: { vocabulary: tags, field: field_tags }
      featured_image: field_image
      excerpt: field_excerpt
      navigation: main
      YAML);
    return $path;
  }

  /**
   * Returns the fixture theme's Site recipe directory.
   */
  private function recipeDirectory(): string {
    return SiteRecipeWriter::directory($this->themeRoot, self::THEME_ID);
  }

  /**
   * Returns a copy of a recipe directory, in a private temporary directory.
   */
  private function copyRecipe(string $directory): string {
    $copy = sys_get_temp_dir() . '/wordpal-site-recipe-' . bin2hex(random_bytes(8)) . '/' . basename($directory);
    (new Filesystem())->mirror($directory, $copy);
    return $copy;
  }

  /**
   * Mirrors the fixture theme into an isolated directory, the Tags pattern out.
   *
   * The copy drops the fixture's Tags pattern from patterns.json. Its only
   * block, Post Terms, needs a post, so Display Builder skips the pattern.
   *
   * @return string
   *   The mirrored copy's directory, safe for a test to add its own
   *   Template or part files to without touching the checked-in fixture.
   */
  private function mirroredFixtureDirectory(): string {
    $directory = $this->themeRoot . '/snapshots/wordpal-fixture';
    (new Filesystem())->mirror(dirname(__DIR__, 2) . '/fixtures/snapshot/wordpal-fixture', $directory);
    $patterns = json_decode(file_get_contents("$directory/out/patterns.json"), TRUE);
    unset($patterns['wordpal-fixture--tags']);
    file_put_contents("$directory/out/patterns.json", json_encode($patterns));
    return $directory;
  }

  /**
   * Returns a copy of the fixture snapshot under another theme slug.
   *
   * The copy's single Template uses the supported footer, so it converts to
   * the `node.<bundle>.full` content template.
   */
  private function themeSnapshot(string $slug): Snapshot {
    $directory = $this->themeRoot . "/snapshots/$slug";
    (new Filesystem())->mirror(dirname(__DIR__, 2) . '/fixtures/snapshot/wordpal-fixture', $directory);
    $metadata = json_decode(file_get_contents("$directory/out/metadata.json"), TRUE);
    $metadata['slug'] = $slug;
    $metadata['sources'] = ["theme:$slug" => NULL];
    file_put_contents("$directory/out/metadata.json", json_encode($metadata));
    $single = file_get_contents("$directory/out/templates/single.html");
    file_put_contents("$directory/out/templates/single.html", str_replace('"slug":"footer"', '"slug":"footer-safe"', $single));
    $version = $this->container->get(ComponentSet::class)->wordPressVersion();
    return $this->container->get(PlaygroundRunner::class)->load($directory, $slug, $version);
  }

  /**
   * Returns the fixture snapshot with extra Templates added, Tags pattern out.
   *
   * @param array<string, string> $mains
   *   Each extra Template's main block markup, keyed by Template name, the
   *   way templateSnapshot() adds one.
   */
  private function snapshotWithTemplates(array $mains): Snapshot {
    $directory = $this->mirroredFixtureDirectory();
    $index = json_decode(file_get_contents("$directory/out/render-index.json"), TRUE);
    foreach ($mains as $name => $main) {
      file_put_contents("$directory/out/templates/$name.html", '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->' . $main . '<!-- wp:template-part {"slug":"footer-safe","area":"footer","tagName":"footer"} /-->');
      $index["templates/$name.html"] = ['0' => ''];
    }
    file_put_contents("$directory/out/render-index.json", json_encode($index));
    $version = $this->container->get(ComponentSet::class)->wordPressVersion();
    return $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $version);
  }

  /**
   * Returns the ids of one entity type among a report's created lines.
   *
   * @return string[]
   *   The ids.
   */
  private static function createdIdsOfType(array $created, string $type): array {
    $ids = [];
    foreach ($created as $line) {
      if (str_starts_with($line, "$type:")) {
        $ids[] = substr($line, strlen("$type:"));
      }
    }
    return $ids;
  }

  /**
   * Returns the fixture theme's decoded recipe.yml.
   */
  private function recipe(): array {
    return Yaml::decode(file_get_contents($this->recipeDirectory() . '/recipe.yml'));
  }

  /**
   * Returns the fixture Content mapping path.
   */
  private function mappingPath(): string {
    return dirname(__DIR__, 2) . '/fixtures/snapshot/wordpal-fixture/mapping.yml';
  }

  /**
   * Converts as wordpal:convert does, on a runner built from the container.
   */
  private function convert(Snapshot $snapshot, string $mapping, bool $replace, bool $demoContent, bool $activate, string $target = 'canvas'): ConversionReport {
    $conversion = $this->runner()->prepare($snapshot, $mapping, $target, $replace, $activate);
    // Installing the theme rebuilt the container.
    return $this->runner()->write($conversion, $demoContent, $activate);
  }

  /**
   * Returns a writer selector for the modules enabled right now.
   *
   * Built fresh on every call, never cached: a test can enable
   * wordpal_display_builder (or wordpal_canvas) partway through, and the
   * next runner() or recipeWriter() call must see the writer that just
   * registered.
   */
  private function writerSelector(): WriterSelector {
    $writers = new WriterSelector();
    if ($this->container->has(CanvasWriter::class)) {
      $writers->addWriter($this->container->get(CanvasWriter::class), 'canvas');
    }
    $displayBuilderWriter = 'Drupal\wordpal_display_builder\DisplayBuilderWriter';
    if ($this->container->has($displayBuilderWriter)) {
      $writers->addWriter($this->container->get($displayBuilderWriter), 'display_builder');
    }
    return $writers;
  }

  /**
   * Builds a ConversionRunner wired to the isolated theme root.
   */
  private function runner(): ConversionRunner {
    // The Site recipe writer validates content as the first administrator.
    if (User::load(1) === NULL) {
      User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    }
    $themeGenerator = new ThemeGenerator($this->themeRoot, $this->container->get('module_handler'));
    $ownership = $this->ownership($themeGenerator);
    return new ConversionRunner(
      $this->container->get(ThemeTrees::class),
      $this->writerSelector(),
      $this->container->get(ContentMappingLoader::class),
      $this->container->get(ContentMappingTargetValidator::class),
      $themeGenerator,
      $this->container->get('config.factory'),
      $ownership,
      $this->container->get('entity_type.manager'),
      $this->container->get(DemoContentSeeder::class),
      $this->container->get('theme_installer'),
      $this->container->get('theme.manager'),
      $this->recipeWriter($ownership),
      $this->container->get(NavigationMenus::class),
      $this->container->get('theme.registry'),
      $this->container->get('cache_tags.invalidator'),
      $this->container->get('extension.list.theme'),
      $this->container->get('cache.bootstrap'),
      $this->container->get(AttributeFlattener::class),
      $this->container->get('extension.list.module'),
    );
  }

  /**
   * Builds a ConversionOwnership wired to the isolated theme root.
   */
  private function ownership(?ThemeGenerator $themeGenerator = NULL): ConversionOwnership {
    return new ConversionOwnership(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->themeRoot,
      $themeGenerator ?? new ThemeGenerator($this->themeRoot, $this->container->get('module_handler')),
      $this->container->get('entity.repository'),
      $this->container->has('trash.manager') ? $this->container->get('trash.manager') : NULL,
    );
  }

  /**
   * Builds a SiteRecipeWriter wired to the isolated theme root.
   */
  private function recipeWriter(?ConversionOwnership $ownership = NULL): SiteRecipeWriter {
    $ownership ??= $this->ownership();
    return new SiteRecipeWriter(
      $this->themeRoot,
      $ownership,
      $this->container->get('config.storage'),
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('entity.repository'),
      $this->container->get(Exporter::class),
      new AdminAccountSwitcher($this->container->get('account_switcher'), $this->container->get('entity_type.manager'), TRUE),
      $this->container->get(SiteRecipeExport::class),
      $this->writerSelector(),
    );
  }

}
