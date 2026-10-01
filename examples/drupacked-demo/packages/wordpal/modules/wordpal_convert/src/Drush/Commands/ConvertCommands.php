<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Drush\Commands;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Content\ContentMappingLoader;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use Drupal\wordpal_convert\Content\SiteSetup;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal_convert\WordPress\PlaygroundRunner;
use Drupal\wordpal_convert\WordPress\Snapshot;
use Drupal\wordpal_convert\WordPress\Source;
use Drupal\wordpal_convert\WordPress\SourceResolver;
use Drupal\wordpal_convert\WordPress\SourceType;
use Drupal\wordpal_convert\WordPress\ThemeLicense;
use Drupal\wordpal_convert\WordPress\ThemeSource;
use Drupal\wordpal_convert\ConversionReport;
use Drupal\wordpal_convert\ConversionRunner;
use Drupal\wordpal_convert\SiteRecipeWriter;
use Drupal\wordpal_convert\WriterSelector;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Converts a WordPress block theme into a Builder target's configuration.
 */
final class ConvertCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * The module each Builder target needs enabled.
   */
  private const TARGET_MODULES = [
    'canvas' => 'wordpal_canvas',
    'display_builder' => 'wordpal_display_builder',
  ];

  public function __construct(
    private readonly PlaygroundRunner $runner,
    private readonly ComponentSet $componentSet,
    private readonly FileSystemInterface $fileSystem,
    private readonly ConversionRunner $conversionRunner,
    private readonly ContentMappingLoader $mappingLoader,
    private readonly SiteSetup $siteSetup,
    private readonly ThemeSource $themeSource,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly SourceResolver $sourceResolver,
    private readonly WriterSelector $writerSelector,
  ) {
    parent::__construct();
  }

  /**
   * Checks the target names a known Builder target with its module enabled.
   *
   * Without a target, returns the only target whose module is enabled.
   *
   * @throws \InvalidArgumentException
   */
  private function checkTarget(?string $target): string {
    if ($target === NULL) {
      $enabled = array_keys(array_filter(self::TARGET_MODULES, $this->moduleHandler->moduleExists(...)));
      if ($enabled === []) {
        throw new \InvalidArgumentException(sprintf('No Builder target is enabled. Enable %s.', implode(' or ', self::TARGET_MODULES)));
      }
      if (count($enabled) > 1) {
        throw new \InvalidArgumentException('Canvas and Display Builder are both enabled. Pass --target=canvas or --target=display_builder.');
      }
      return $enabled[0];
    }
    if (!isset(self::TARGET_MODULES[$target])) {
      throw new \InvalidArgumentException(sprintf('Unknown --target %s. Use canvas or display_builder.', $target));
    }
    $module = self::TARGET_MODULES[$target];
    if (!$this->moduleHandler->moduleExists($module)) {
      throw new \InvalidArgumentException(sprintf('The %s target needs the %s module enabled.', $target, $module));
    }
    return $target;
  }

  /**
   * Converts a WordPress theme's patterns and mapped content into a Builder.
   */
  #[CLI\Command(name: 'wordpal:convert', aliases: ['wpc'])]
  #[CLI\Argument(name: 'theme', description: 'WordPress.org theme slug, theme zip path or https URL to a theme zip.')]
  #[CLI\Option(name: 'target', description: 'The Builder target: canvas or display_builder. Required when both are enabled; otherwise the enabled one.')]
  #[CLI\Option(name: 'plugin', description: 'Plugin to install and activate in WordPress before rendering: a WordPress.org slug, zip path or https URL. Repeatable.')]
  #[CLI\Option(name: 'mapping', description: 'Content mapping YAML replacing the default Drupal CMS mapping. Missing targets fail the conversion.')]
  #[CLI\Option(name: 'refresh', description: 'Run WordPress again instead of using the snapshot on disk.')]
  #[CLI\Option(name: 'replace', description: 'Delete and recreate outputs owned by a previous conversion, Demo content included.')]
  #[CLI\Option(name: 'demo-content', description: 'Seed Demo content when the mapped post bundle has no nodes. Use --no-demo-content to skip it.')]
  #[CLI\Option(name: 'accept-license', description: 'Convert a zip or URL theme whose style.css license is missing or not on the GPL-compatible list. Pass it only if you hold the rights to copy the theme into this site.')]
  #[CLI\Option(name: 'activate', description: 'Install the generated theme, make it the default theme, make its home the front page and let visitors see comments. Use --no-activate to skip all four.')]
  #[CLI\Usage(name: 'wordpal:convert twentytwentyfour', description: 'Convert into the enabled Builder on the Drupal CMS content model, applying the WordPal recipe when its targets are missing')]
  #[CLI\Usage(name: 'wordpal:convert twentytwentyfour --target=display_builder', description: 'Convert into Display Builder')]
  #[CLI\Usage(name: 'wordpal:convert twentytwentyfour --no-activate --no-demo-content', description: 'Convert for review, leaving the site theme, front page and content unchanged')]
  #[CLI\Usage(name: 'wordpal:convert twentytwentyfour --mapping=/path/to/mapping.yml --replace', description: 'Repeat a conversion with an override mapping, recreating owned outputs')]
  #[CLI\Usage(name: 'wordpal:convert /path/to/theme.zip --plugin=woocommerce --plugin=https://example.com/plugin.zip', description: 'Convert a theme zip with two plugins active')]
  public function convert(
    string $theme,
    array $options = [
      'target' => self::OPT,
      'mapping' => self::OPT,
      'plugin' => [],
      'refresh' => FALSE,
      'replace' => FALSE,
      'demo-content' => TRUE,
      'activate' => TRUE,
      'accept-license' => FALSE,
    ],
  ): int {
    try {
      $target = $this->checkTarget($options['target']);
      if ($options['mapping'] !== NULL) {
        // The concepts a theme requires are known after the snapshot; this
        // checks the file and its shape before Playground runs.
        $this->mappingLoader->load($options['mapping'], []);
      }
      $identity = $this->themeSource->identify($theme, SourceType::Theme, $this->downloadDirectory());
      if (!$options['replace'] && $identity !== NULL) {
        $alreadyConverted = $this->conversionRunner->alreadyConverted($identity[0]);
        if ($alreadyConverted !== NULL) {
          // A repeat is a refusal, not a fault: a warning, with exit 1 so a
          // script sees that nothing ran.
          $this->logger()->warning($alreadyConverted);
          return self::EXIT_FAILURE;
        }
      }
      $label = $this->writerSelector->forTarget($target)->label();
      $this->logger()->notice(dt('Converting @theme into @target, with @mapping.', [
        '@theme' => $theme,
        '@target' => $label,
        '@mapping' => $options['mapping'] === NULL ? dt('the default Drupal CMS mapping') : dt('the mapping at @path', ['@path' => $options['mapping']]),
      ]));
      if ($options['replace']) {
        $this->logger()->notice(dt('--replace deletes and recreates the outputs of an earlier conversion.'));
      }
      $snapshot = $this->snapshot($theme, $options['plugin'], (bool) $options['refresh'], (bool) $options['accept-license']);
      if (ThemeGenerator::originalLinks($snapshot) === []) {
        $this->logger()->warning(dt('Attribution: no Theme URI, no link to the original theme.'));
      }
      $this->logger()->notice(dt('@name @version: @patterns patterns, @templates templates, @variations style variations. Writing @target configuration.', [
        '@name' => $snapshot->themeName,
        '@version' => $snapshot->themeVersion,
        '@patterns' => count($snapshot->patterns()),
        '@templates' => count($snapshot->templateNames()),
        '@variations' => count($snapshot->variations()),
        '@target' => $label,
      ]));
      $conversionRunner = $this->conversionRunner;
      if ($options['mapping'] === NULL) {
        $recipe = $this->siteSetup->ensureTargets($this->mappingLoader->resolve(NULL));
        if ($recipe !== NULL) {
          $this->logger()->notice(dt('Applied the WordPal recipe from @path.', ['@path' => $recipe]));
          // The recipe may have installed modules and rebuilt the container.
          // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
          $conversionRunner = \Drupal::service(ConversionRunner::class);
        }
      }
      $conversion = $conversionRunner->prepare($snapshot, $options['mapping'], $target, (bool) $options['replace'], (bool) $options['activate']);
      // prepare() installs the theme, which rebuilds the container, so write()
      // runs on a runner fetched after it.
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      $report = \Drupal::service(ConversionRunner::class)->write($conversion, (bool) $options['demo-content'], (bool) $options['activate']);
    }
    catch (\InvalidArgumentException | \RuntimeException $exception) {
      $this->logger()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }

    $themeId = ThemeGenerator::themeId($snapshot->themeSlug);
    $this->logger()->notice("Target: $target");
    $this->logger()->success(dt('Created @created outputs from @theme @version; skipped @skipped items; froze @frozen blocks; dropped @dropped mapped blocks.', [
      '@created' => count($report->created),
      '@theme' => $snapshot->themeName,
      '@version' => $snapshot->themeVersion,
      '@skipped' => count($report->skipped) + count($report->skippedPatterns),
      '@frozen' => count(array_merge(...array_values($report->frozen))),
      '@dropped' => array_sum($report->dropped),
    ]));
    $this->logger()->notice(dt('Snapshot: @directory', ['@directory' => $snapshot->directory]));
    $this->logger()->notice(dt('Generated Drupal theme: @theme', ['@theme' => $themeId]));
    $this->logger()->notice(dt("Created outputs:\n@list", ['@list' => implode("\n", $report->created)]));
    if ($report->demoContent !== NULL) {
      if ($report->demoContentLines() !== []) {
        $this->logger()->notice(dt("Seeded Demo content:\n@list", ['@list' => implode("\n", $report->demoContentLines())]));
      }
      if ($report->demoContent['skipped'] !== []) {
        $this->logger()->notice(dt("Demo content left out:\n@list", ['@list' => implode("\n", $report->demoContent['skipped'])]));
      }
    }
    if ($report->menus !== []) {
      $this->logger()->notice(dt("Menus holding Navigation blocks' own links:\n@list", ['@list' => implode("\n", $report->menuLines())]));
    }
    if ($report->takenOverTemplates !== []) {
      $this->logger()->notice(dt("Disabled content templates, replaced by the converted Templates:\n@list", ['@list' => implode("\n", $report->takenOverTemplates)]));
    }
    if ($report->disabledViews !== []) {
      $this->logger()->notice(dt("Disabled Views, replaced by the converted Templates:\n@list", ['@list' => implode("\n", $report->disabledViews)]));
    }
    if ($report->templatePaths !== []) {
      $this->logger()->notice(dt("Listing Templates, edited in @target at:\n@list", [
        '@target' => $report->targetLabel,
        '@list' => implode("\n", $report->templatePaths),
      ]));
    }
    // Drush runs from the Drupal root, and the recipes sit beside it.
    // Drush's autowiring resolves services only, not the app.root parameter.
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $appRoot = \Drupal::root();
    $this->logger()->notice(dt("Site recipe: @path\nApply it on a new production site with:\ndrush recipe ../@relative", [
      '@path' => SiteRecipeWriter::directory($appRoot, $themeId),
      '@relative' => basename(SiteSetup::recipesDirectory($appRoot)) . '/' . SiteRecipeWriter::name($themeId),
    ]));
    $this->logger()->notice(dt("Style variations, selected at /admin/appearance/settings/@theme:\n@list", [
      '@theme' => $themeId,
      '@list' => implode("\n", $report->variationLines()),
    ]));
    if ($report->droppedLines() !== []) {
      $this->logger()->notice(dt("Dropped concepts:\n@list", ['@list' => implode("\n", $report->droppedLines())]));
    }
    if ($report->droppedOptionLines() !== []) {
      $this->logger()->notice(dt("Block options without their WordPress behavior:\n@list", ['@list' => implode("\n", $report->droppedOptionLines())]));
    }
    if ($report->unreadStyleLines() !== []) {
      $this->logger()->notice(dt("WordPress controls with no prop, which keep their imported appearance:\n@list", ['@list' => implode("\n", $report->unreadStyleLines())]));
    }
    if ($report->frozen !== []) {
      $this->logger()->notice(dt("Frozen blocks, which editors cannot change:\n@list", ['@list' => implode("\n", $report->frozenLines())]));
      $this->logger()->notice(ConversionReport::PLUGIN_SCRIPTS);
    }
    if ($report->frozenStyles !== []) {
      $this->logger()->notice(dt("Plugin stylesheets of Frozen blocks:\n@list", ['@list' => implode("\n", $report->frozenStyleLines())]));
    }
    if ($report->pluginLicenses !== []) {
      $this->logger()->notice(dt("Plugins whose files the generated theme holds, credited in its ATTRIBUTION.md:\n@list", ['@list' => implode("\n", $report->pluginLicenses)]));
    }
    if ($report->unregistered !== []) {
      $this->logger()->warning(dt("Block namespaces no active plugin registers:\n@list", ['@list' => implode("\n", $report->unregisteredLines())]));
    }
    if ($report->invalid !== []) {
      $this->logger()->warning(dt("Blocks WordPress's own parser finds invalid:\n@list", ['@list' => implode("\n", $report->invalidLines())]));
    }
    if ($report->skipped !== []) {
      $this->logger()->warning(dt("Left out:\n@list", ['@list' => implode("\n", $report->skipped)]));
    }
    if ($report->skippedPatterns !== []) {
      $this->logger()->warning(dt("Skipped @count patterns:\n@list", [
        '@count' => count($report->skippedPatterns),
        '@list' => implode("\n", $report->skippedPatterns),
      ]));
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Returns the snapshot of a theme, running WordPress when one is needed.
   *
   * A cached snapshot is identified without the network, and is reused only
   * when it was rendered from the same theme and plugin zips or slugs.
   *
   * The theme license is checked once and its line logged: from a cached
   * snapshot's headers, from a fresh zip's headers before WordPress runs,
   * or after the run for a WordPress.org slug, which always passes.
   *
   * @param string $theme
   *   The theme reference.
   * @param string[] $plugins
   *   The plugin references WordPress renders with.
   * @param bool $refresh
   *   Whether to run WordPress even when a snapshot is on disk.
   * @param bool $acceptLicense
   *   Whether the site builder passed --accept-license.
   */
  private function snapshot(string $theme, array $plugins, bool $refresh, bool $acceptLicense): Snapshot {
    $version = $this->componentSet->wordPressVersion();
    $identities = $refresh ? NULL : $this->identities($theme, $plugins);
    if ($identities !== NULL) {
      $directory = $this->snapshotDirectory($identities['slug']);
      $snapshot = $this->runner->load($directory, $identities['slug'], $version);
      if ($snapshot !== NULL) {
        if ($snapshot->sources() !== $identities['sources']) {
          throw new \RuntimeException(sprintf(
            'Cached snapshot of theme %s was rendered from other theme or plugin sources, or a zip that has since changed. Run with --refresh.',
            $identities['slug'],
          ));
        }
        $this->logger()->notice(dt('Reusing the WordPress snapshot at @directory.', ['@directory' => $directory]));
        $headers = $snapshot->themeHeaders();
        $this->logger()->notice(ThemeLicense::check($snapshot->fromWordPressOrg(), $headers['license'], $headers['license_uri'], $acceptLicense));
        return $snapshot;
      }
    }

    [$source, $resolvedPlugins] = $this->sourceResolver->resolve($theme, $plugins);
    if ($source->zip !== NULL) {
      $this->logger()->notice(ThemeLicense::check(FALSE, $source->license, $source->licenseUri, $acceptLicense));
    }
    $this->logger()->notice(dt('Running WordPress @version through Playground, which takes a few minutes.', ['@version' => $version]));
    $snapshot = $this->runner->snapshot($source, $resolvedPlugins, $version, $this->snapshotDirectory($source->slug), DemoContentSeeder::FIXTURE);
    if ($source->zip === NULL) {
      $headers = $snapshot->themeHeaders();
      $this->logger()->notice(ThemeLicense::check(TRUE, $headers['license'], $headers['license_uri'], $acceptLicense));
    }
    return $snapshot;
  }

  /**
   * Identifies the theme and plugins as a snapshot records its sources.
   *
   * @return array{slug: string, sources: array<string, string|null>}|null
   *   The theme slug and the sources map, or NULL when a URL reference has
   *   not been downloaded yet.
   */
  private function identities(string $theme, array $plugins): ?array {
    $downloads = $this->downloadDirectory();
    $identity = $this->themeSource->identify($theme, SourceType::Theme, $downloads);
    if ($identity === NULL) {
      return NULL;
    }
    $slug = $identity[0];
    $sources = [Source::key(SourceType::Theme, $slug) => $identity[1]];
    foreach ($plugins as $plugin) {
      $identity = $this->themeSource->identify($plugin, SourceType::Plugin, $downloads);
      if ($identity === NULL) {
        return NULL;
      }
      $sources[Source::key(SourceType::Plugin, $identity[0])] = $identity[1];
    }
    ksort($sources);
    return ['slug' => $slug, 'sources' => $sources];
  }

  /**
   * Returns where URL references are downloaded to.
   */
  private function downloadDirectory(): string {
    return PlaygroundRunner::downloadDirectory($this->fileSystem);
  }

  /**
   * Returns the on-disk snapshot directory for one theme.
   */
  private function snapshotDirectory(string $theme): string {
    return PlaygroundRunner::directory($this->fileSystem, $theme);
  }

}
