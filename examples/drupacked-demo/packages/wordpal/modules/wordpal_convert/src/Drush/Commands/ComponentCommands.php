<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Drush\Commands;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Component\BlockCoverage;
use Drupal\wordpal_convert\Component\BlockPluginInputs;
use Drupal\wordpal_convert\Component\BlockTable;
use Drupal\wordpal_convert\Component\CommentBlockInputs;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Component\DefinitionGenerator;
use Drupal\wordpal_convert\NavigationMenus;
use Drupal\wordpal_convert\WordPress\WordPressRelease;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Writes component definitions from a WordPress release.
 */
final class ComponentCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * The Interactivity runtime the view modules import.
   *
   * Paths are relative to WordPress's wp-includes/js/dist/script-modules.
   */
  private const RUNTIME = 'interactivity/index.js';

  /**
   * The view modules wordpal's Interactivity libraries load.
   */
  private const VIEW_MODULES = [
    'block-library/accordion/view.js',
    'block-library/navigation/view.js',
    'block-library/search/view.js',
    'block-editor/utils/fit-text-frontend.js',
  ];

  /**
   * The module specifier WordPress resolves through its import map.
   */
  private const RUNTIME_SPECIFIER = '"@wordpress/interactivity"';

  public function __construct(
    private readonly WordPressRelease $release,
    private readonly DefinitionGenerator $generator,
    private readonly ComponentSet $componentSet,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct();
  }

  /**
   * Writes a definition for every component that has a Twig template.
   */
  #[CLI\Command(name: 'wordpal:generate-components', aliases: ['wgc'])]
  #[CLI\Argument(name: 'version', description: 'WordPress version to read block definitions from.')]
  #[CLI\Usage(name: 'wordpal:generate-components 7.1', description: 'Generate definitions from WordPress 7.1')]
  public function generate(string $version): int {
    $alterModules = [];
    $this->moduleHandler->invokeAllWith('wordpal_component_definition_alter', static function (callable $hook, string $module) use (&$alterModules): void {
      $alterModules[] = $module;
    });
    $this->logger()->notice(dt('Modules altering component definitions: @modules', [
      '@modules' => $alterModules === [] ? 'none' : implode(', ', $alterModules),
    ]));
    $componentsDirectory = $this->componentSet->directory();
    $blocksDirectory = $this->release->blocks($version);

    $written = [];
    $skipped = [];
    $skippedItems = [];
    foreach (glob($componentsDirectory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
      $slug = basename($directory);
      if (in_array($slug, [FrozenBlock::SLUG, ComponentSet::FRAGMENT_SLUG, ComponentSet::POST_TERM_SLUG], TRUE)) {
        continue;
      }
      if (!is_file("$directory/$slug.twig")) {
        $this->logger()->warning(dt('Component @slug has no Twig template, skipping.', ['@slug' => $slug]));
        continue;
      }
      $blockJson = "$blocksDirectory/$slug/block.json";
      if (!is_file($blockJson)) {
        $this->logger()->error(dt('WordPress @version has no block @slug.', ['@version' => $version, '@slug' => $slug]));
        continue;
      }

      $parents = array_map(
        static fn (string $parent): array => self::blockJson($blocksDirectory, $parent),
        $this->generator->slotParents($slug),
      );
      $result = $this->generator->generate(json_decode((string) file_get_contents($blockJson), TRUE), $parents);
      $definition = $this->alteredDefinition($result['definition'], $slug);
      file_put_contents("$directory/$slug.component.yml", Yaml::encode($definition));
      $this->copyBlockCss("$blocksDirectory/$slug", "$directory/$slug.css");
      $written[] = $slug;
      $skippedItems["core/$slug"] = $result['skipped'];
      foreach ($result['skipped'] as $item) {
        $skipped[] = "$slug: $item";
      }
    }

    $blockSupports = [];
    foreach (BlockPluginInputs::SUPPORT_BLOCKS as $blockName) {
      $blockSupports[$blockName] = $this->generator->supportPropNames(self::blockJson($blocksDirectory, $blockName));
    }
    $this->componentSet->recordBlockSupports($blockSupports);
    $this->componentSet->recordBlockCoverage(BlockCoverage::rows(self::library($blocksDirectory), $written, self::parents(), $skippedItems));
    $this->writeSocialServices($blocksDirectory, $componentsDirectory . '/social-link/services.json');
    $this->copyScriptModules(dirname($blocksDirectory) . '/js/dist/script-modules', $this->componentSet->scriptModulesDirectory(), $version);
    $this->copyStyleEngine(dirname($blocksDirectory) . '/style-engine', $this->componentSet->styleEngineDirectory());
    $this->componentSet->recordWordPressVersion($version);

    $this->logger()->success(dt('Wrote @count definitions from WordPress @version: @list', [
      '@count' => count($written),
      '@version' => $version,
      '@list' => implode(', ', $written),
    ]));
    if ($skipped !== []) {
      $this->logger()->notice(dt("Left out of the definitions:\n@list", ['@list' => implode("\n", $skipped)]));
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Writes the block support table from the recorded block coverage.
   */
  #[CLI\Command(name: 'wordpal:block-table')]
  #[CLI\Usage(name: 'wordpal:block-table', description: 'Write docs/block-support.md')]
  public function blockTable(): int {
    $destination = dirname($this->componentSet->directory()) . '/docs/block-support.md';
    file_put_contents($destination, BlockTable::render($this->componentSet->wordPressVersion(), $this->componentSet->blockCoverage(), $this->componentSet->blockNotes(), $this->componentSet->blockUsage()));
    $this->logger()->success(dt('Wrote @file.', ['@file' => $destination]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Returns every core block's block.json, keyed by block name.
   */
  private static function library(string $blocksDirectory): array {
    $library = [];
    foreach (glob("$blocksDirectory/*/block.json") ?: [] as $file) {
      $block = json_decode((string) file_get_contents($file), TRUE, flags: JSON_THROW_ON_ERROR);
      $library[$block['name']] = $block;
    }
    return $library;
  }

  /**
   * Returns the parent of each block its parent's Drupal target consumes.
   */
  private static function parents(): array {
    return array_fill_keys(array_keys(CommentBlockInputs::settingKeys()), 'core/comments')
      + array_fill_keys(NavigationMenus::LINK_BLOCKS, 'core/navigation');
  }

  /**
   * Returns the block.json of one core block, such as "core/search".
   */
  private static function blockJson(string $blocksDirectory, string $blockName): array {
    return json_decode((string) file_get_contents("$blocksDirectory/" . substr($blockName, strlen('core/')) . '/block.json'), TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Applies hook_wordpal_component_definition_alter() to one definition.
   */
  private function alteredDefinition(array $definition, string $slug): array {
    $this->moduleHandler->alter('wordpal_component_definition', $definition, $slug);
    return $definition;
  }

  /**
   * Writes the name and icon WordPress shows for each social service.
   *
   * WordPress keeps them in PHP, in social-link.php. A separate PHP process
   * runs that file with stand-ins for the WordPress functions it calls, so
   * its global functions never load into Drupal.
   */
  private function writeSocialServices(string $blocksDirectory, string $destination): void {
    $script = <<<'PHP'
      function _x($text) { return $text; }
      function __($text) { return $text; }
      function add_action() {}
      function apply_filters($hook, $value) { return $value; }
      require $argv[1];
      $services = [];
      foreach (block_core_social_link_services() as $service => $data) {
        $services[$service] = ['name' => $data['name'], 'icon' => $data['icon']];
      }
      echo json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
      PHP;
    $process = new Process([PHP_BINARY, '-r', $script, "$blocksDirectory/social-link.php"]);
    $process->mustRun();
    file_put_contents($destination, $process->getOutput() . "\n");
  }

  /**
   * Copies the front-end CSS WordPress ships for one block.
   *
   * A single-directory component loads a CSS file that carries its own name.
   *
   * WordPress prints each block's style.css on every page. It prints the
   * blocks' theme.css only for a theme that supports wp-block-styles, so the
   * snapshot's block library stylesheet carries it for such a theme.
   */
  private function copyBlockCss(string $blockDirectory, string $destination): void {
    $file = "$blockDirectory/style.css";
    if (!is_file($file)) {
      if (is_file($destination)) {
        unlink($destination);
      }
      return;
    }
    file_put_contents($destination, "/* WordPress style.css */\n" . trim((string) file_get_contents($file)) . "\n");
  }

  /**
   * Copies the Interactivity runtime and the block view modules.
   *
   * Drupal core prints no import map, so each view module's import of
   * "@wordpress/interactivity" becomes a path relative to the view module.
   * Every view module then imports the same URL, and the page runs one copy
   * of the runtime. The version query changes that URL on each release.
   */
  private function copyScriptModules(string $source, string $destination, string $version): void {
    $scripts = [];
    foreach ([self::RUNTIME, ...self::VIEW_MODULES] as $path) {
      if (!is_file("$source/$path")) {
        throw new \RuntimeException("WordPress $version has no script module $path.");
      }
      $scripts[$path] = (string) file_get_contents("$source/$path");
    }
    foreach (self::VIEW_MODULES as $path) {
      $runtime = str_repeat('../', substr_count($path, '/')) . self::RUNTIME . '?ver=' . $version;
      $scripts[$path] = str_replace(self::RUNTIME_SPECIFIER, '"' . $runtime . '"', $scripts[$path], $count);
      if ($count === 0) {
        throw new \RuntimeException("WordPress $version script module $path does not import @wordpress/interactivity.");
      }
    }
    $filesystem = new Filesystem();
    $filesystem->remove($destination);
    foreach ($scripts as $path => $script) {
      $filesystem->dumpFile("$destination/$path", $script);
    }
  }

  /**
   * Copies WordPress's style engine classes into their own namespace.
   *
   * BlockSupports loads these at render time, so they carry a namespace of
   * their own rather than declaring the bare WP_Style_Engine* class names
   * PHP's autoloader would otherwise have to resolve globally. The shims for
   * the functions these classes call live outside this directory, in
   * Shims.php, so this copy never overwrites them.
   */
  private function copyStyleEngine(string $source, string $destination): void {
    $files = glob("$source/*.php") ?: [];
    if ($files === []) {
      throw new \RuntimeException("No style engine classes found at $source.");
    }
    $filesystem = new Filesystem();
    $filesystem->remove($destination);
    foreach ($files as $file) {
      $namespaced = preg_replace(
        '/^<\?php/',
        "<?php\n\nnamespace Drupal\\wordpal\\WordPress\\StyleEngine\\Vendor;\n\nuse AllowDynamicProperties;\n",
        (string) file_get_contents($file),
        1,
        $count,
      );
      if ($count !== 1) {
        throw new \RuntimeException('Could not namespace ' . basename($file) . '.');
      }
      $filesystem->dumpFile($destination . '/' . basename($file), $namespaced);
    }
  }

}
