<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\WordPress;

use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Support\PathValidator;
use Drupal\wordpal_convert\Support\SvgCheck;
use Drupal\wordpal_convert\Theme\DesignTokens;

/**
 * What one WordPress run produced for a theme.
 */
final class Snapshot {

  /**
   * The slug of the theme's own styles among its style variations.
   */
  public const DEFAULT_VARIATION = 'default';

  /**
   * The block library stylesheet, which WordPress prints first.
   *
   * It holds the blocks' theme.css too when the theme supports
   * wp-block-styles.
   */
  public const BLOCK_LIBRARY_CSS = 'styles/core-blocks.css';

  /**
   * The shape of a WordPress script or style handle.
   */
  public const HANDLE_PATTERN = '/^[A-Za-z0-9_-]+$/';

  /**
   * The theme file extensions the snapshot copies as assets.
   */
  public const ASSET_EXTENSIONS = [
    'avif', 'eot', 'gif', 'jpeg', 'jpg', 'otf', 'png', 'svg', 'ttf',
    'webp', 'woff', 'woff2',
  ];

  /**
   * The theme-relative paths of a theme's Legal files.
   *
   * The root readme.txt, and license files at the root or under assets/.
   */
  public const LEGAL_FILES = '#^(?:readme\.txt|(?:assets/(?:[^/]+/)*)?(?:licen[cs]e|copying|ofl)(?:\.(?:txt|md))?)\z#i';

  /**
   * The names of a plugin's Legal files at its directory root.
   */
  public const PLUGIN_LEGAL_FILES = '#^(?:readme\.txt|(?:licen[cs]e|copying)(?:\.(?:txt|md))?)\z#i';

  /**
   * The snapshot directory holding the Legal files.
   */
  public const LEGAL_PREFIX = 'legal/';

  /**
   * The snapshot file names a theme screenshot may have.
   *
   * WP_Theme::get_screenshot() looks for these extensions.
   */
  public const SCREENSHOT = '/^screenshot\.(?:png|gif|jpg|jpeg|webp|avif)\z/';

  /**
   * The snapshot path prefix a plugin stylesheet's own url() assets get.
   */
  public const PLUGIN_ASSETS = 'assets/_plugin/';

  /**
   * The snapshot path prefix a plugin stylesheet's own file gets.
   */
  public const PLUGIN_STYLE_PREFIX = 'styles/plugin-';

  /**
   * The decoded patterns.json, once read.
   */
  private ?array $patternsCache = NULL;

  /**
   * Patterns from patternsCache, indexed by slug, once built.
   */
  private ?array $patternsBySlug = NULL;

  /**
   * The decoded render-index.json, once read.
   */
  private ?array $renderIndexCache = NULL;

  /**
   * The assets and skipped entries readAssets() built, once built.
   */
  private ?array $assetsCache = NULL;

  /**
   * The Legal files and skipped entries readLegalFiles() built, once built.
   */
  private ?array $legalFilesCache = NULL;

  /**
   * The variations and skipped entries readVariations() built, once built.
   */
  private ?array $variationsCache = NULL;

  /**
   * Constructs a snapshot.
   *
   * @param string $themeSlug
   *   The WordPress.org theme slug.
   * @param string $themeName
   *   The theme name from its style.css.
   * @param string $themeVersion
   *   The theme version.
   * @param string $wordPressVersion
   *   The WordPress version that produced this snapshot.
   * @param string $directory
   *   Directory holding the snapshot.
   */
  public function __construct(
    public readonly string $themeSlug,
    public readonly string $themeName,
    public readonly string $themeVersion,
    public readonly string $wordPressVersion,
    public readonly string $directory,
  ) {}

  /**
   * Returns the markup of one template, such as "single".
   */
  public function template(string $name): string {
    return $this->read(self::templatePath($name));
  }

  /**
   * Returns the names of the theme's templates, such as "single".
   *
   * @return string[]
   *   Template names, sorted.
   */
  public function templateNames(): array {
    $names = array_map(static fn (string $path): string => basename($path, '.html'), glob($this->directory . '/templates/*.html') ?: []);
    sort($names);
    return $names;
  }

  /**
   * Returns the HTML WordPress rendered for each block of one template.
   *
   * @return array<string, string>
   *   Rendered HTML keyed by block path, as BlockParser::parse() reads it.
   */
  public function templateRenders(string $name): array {
    return $this->renders(self::templatePath($name));
  }

  /**
   * Returns the markup of one template part, such as "header".
   */
  public function part(string $name): string {
    return $this->read(self::partPath($name));
  }

  /**
   * Returns the HTML WordPress rendered for each block of one template part.
   *
   * @return array<string, string>
   *   Rendered HTML keyed by block path, as BlockParser::parse() reads it.
   */
  public function partRenders(string $name): array {
    return $this->renders(self::partPath($name));
  }

  /**
   * Returns whether the snapshot contains a template part.
   */
  public function hasPart(string $name): bool {
    return is_file($this->directory . '/' . self::partPath($name));
  }

  /**
   * Returns the block markup of one pattern, its PHP already run.
   */
  public function pattern(string $slug): string {
    return $this->read(self::patternPath($this->patternMetadata($slug)['slug']));
  }

  /**
   * Returns the HTML WordPress rendered for each block of one pattern.
   *
   * @return array<string, string>
   *   Rendered HTML keyed by block path, as BlockParser::parse() reads it.
   */
  public function patternRenders(string $slug): array {
    return $this->renders(self::patternPath($this->patternMetadata($slug)['slug']));
  }

  /**
   * Returns whether the snapshot contains a pattern.
   */
  public function hasPattern(string $slug): bool {
    return $this->findPattern($slug) !== NULL;
  }

  /**
   * Returns the file name of one block library fixture.
   *
   * The name arrives from the command line. Evaluation's ReferenceRenders
   * reads a fixture's Reference render by the same name.
   */
  public static function fixtureFile(string $name): string {
    if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
      throw new \InvalidArgumentException("Invalid block library fixture name: $name");
    }
    return "$name.html";
  }

  /**
   * Returns snapshot stylesheets keyed by their generated theme path.
   */
  public function styles(): array {
    $styles = [];
    foreach ($this->manifest('styles.json', 'styles/', ['css']) as $path) {
      $styles[$path] = $this->read($path);
    }
    return $styles;
  }

  /**
   * Returns the plugin stylesheets rendered blocks enqueued, keyed by handle.
   *
   * @return array<string, array{path: string, plugin: string, dependencies: string[], css: string}>
   *   The snapshot path, the plugin directory it comes from, the handles of
   *   the plugin stylesheets it depends on and the CSS of each stylesheet.
   */
  public function pluginStyles(): array {
    $styles = $this->readPluginStyles();
    foreach ($styles as &$style) {
      $style['css'] = $this->read($style['path']);
    }
    unset($style);
    return $styles;
  }

  /**
   * Returns the plugin stylesheets each rendered block enqueued.
   *
   * @return array<string, string[]>
   *   Stylesheet handles, keyed by FrozenBlock::key() of the block's HTML.
   */
  public function blockStyles(): array {
    $entries = json_decode($this->read('block-styles.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    $handles = $this->readPluginStyles();
    if (!is_array($entries)) {
      throw new \UnexpectedValueException('Snapshot block-styles.json must contain a map.');
    }
    foreach ($entries as $key => $styles) {
      if (!FrozenBlock::isKey((string) $key) || !is_array($styles) || !array_is_list($styles) || array_diff($styles, array_keys($handles)) !== []) {
        throw new \UnexpectedValueException("Snapshot block-styles.json has an invalid entry for $key.");
      }
    }
    return $entries;
  }

  /**
   * Reads plugin-styles.json.
   *
   * Playground writes it, so its shape is checked here. A handle names a
   * generated theme library, its plugin an installed plugin directory, and
   * its dependencies listed handles.
   */
  private function readPluginStyles(): array {
    $entries = json_decode($this->read('plugin-styles.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($entries)) {
      throw new \UnexpectedValueException('Snapshot plugin-styles.json must contain a map.');
    }
    foreach ($entries as $handle => $entry) {
      $handle = (string) $handle;
      if (
        !preg_match(self::HANDLE_PATTERN, $handle)
        || ($entry['path'] ?? NULL) !== self::PLUGIN_STYLE_PREFIX . "$handle.css"
        || !isset($this->pluginHeaders()[$entry['plugin'] ?? ''])
        || !is_array($entry['dependencies'] ?? NULL)
        || !array_is_list($entry['dependencies'])
        || array_diff($entry['dependencies'], array_map('strval', array_keys($entries))) !== []
      ) {
        throw new \UnexpectedValueException("Snapshot plugin-styles.json has an invalid entry for $handle.");
      }
    }
    $this->checkPaths('plugin-styles.json', array_column($entries, 'path'), 'styles/', ['css']);
    return $entries;
  }

  /**
   * Returns the scripts the theme enqueues, in WordPress's print order.
   *
   * Playground writes scripts.json, so its shape is checked here. A handle
   * names a generated theme library.
   *
   * @return array<int, array{handle: string, path: string, dependencies: string[], footer: bool, strategy: ?string, js: string}>
   *   Each script's handle, snapshot path, WordPress dependency handles,
   *   whether WordPress prints it in the footer, its loading strategy and
   *   its code.
   */
  public function themeScripts(): array {
    $scripts = json_decode($this->read('scripts.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($scripts) || !array_is_list($scripts)) {
      throw new \UnexpectedValueException('Snapshot scripts.json must contain a list.');
    }
    foreach ($scripts as &$script) {
      $handle = $script['handle'] ?? NULL;
      if (
        !is_string($handle)
        || !preg_match(self::HANDLE_PATTERN, $handle)
        || ($script['path'] ?? NULL) !== "scripts/theme-$handle.js"
        || !is_array($script['dependencies'] ?? NULL)
        || !array_is_list($script['dependencies'])
        || array_filter($script['dependencies'], 'is_string') !== $script['dependencies']
        || !is_bool($script['footer'] ?? NULL)
        || !in_array($script['strategy'] ?? NULL, [NULL, 'defer', 'async'], TRUE)
      ) {
        throw new \UnexpectedValueException('Snapshot scripts.json lists an invalid script.');
      }
      $script['js'] = $this->read($script['path']);
    }
    unset($script);
    $this->checkPaths('scripts.json', array_column($scripts, 'path'), 'scripts/', ['js']);
    return $scripts;
  }

  /**
   * Returns the theme's style variations, its own styles first.
   *
   * @return array<string, array{title: string, path: string, css: string}>
   *   The title, generated theme path and Global stylesheet of each
   *   variation, keyed by slug.
   */
  public function variations(): array {
    return $this->readVariations()['variations'];
  }

  /**
   * Returns the style variations left out for a slug no setting can store.
   *
   * @return string[]
   *   Lines such as "Style variation Café: slug caf%c3%a9 is not usable".
   */
  public function skippedVariations(): array {
    return $this->readVariations()['skipped'];
  }

  /**
   * Reads variations.json.
   *
   * Playground writes it, so its shape is checked here. The theme's own
   * styles come first, under self::DEFAULT_VARIATION. A variation whose
   * title makes a slug outside [a-z0-9_-], or repeats one, is left out.
   */
  private function readVariations(): array {
    if ($this->variationsCache !== NULL) {
      return $this->variationsCache;
    }
    $entries = json_decode($this->read('variations.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
      throw new \UnexpectedValueException('Snapshot variations.json must contain a list.');
    }
    $variations = [];
    $skipped = [];
    foreach ($entries as $entry) {
      if (!is_array($entry) || !is_string($entry['slug'] ?? NULL) || !is_string($entry['title'] ?? NULL) || !is_string($entry['path'] ?? NULL)) {
        throw new \UnexpectedValueException('Snapshot variations.json lists an invalid variation.');
      }
      if ($variations === [] && $entry['slug'] !== self::DEFAULT_VARIATION) {
        throw new \UnexpectedValueException('Snapshot variations.json must list the theme\'s own styles first.');
      }
      if (!preg_match('/^[a-z0-9_-]+$/', $entry['slug'])) {
        $skipped[] = "Style variation {$entry['title']}: slug {$entry['slug']} is not usable";
        continue;
      }
      if (isset($variations[$entry['slug']])) {
        $skipped[] = "Style variation {$entry['title']}: slug {$entry['slug']} repeats another variation's slug";
        continue;
      }
      $variations[$entry['slug']] = ['title' => $entry['title'], 'path' => $entry['path']];
    }
    $this->checkPaths('variations.json', array_column($variations, 'path'), 'styles/', ['css']);
    foreach ($variations as &$variation) {
      $variation['css'] = $this->read($variation['path']);
    }
    unset($variation);
    return $this->variationsCache = ['variations' => $variations, 'skipped' => $skipped];
  }

  /**
   * Returns generated theme assets, keyed by snapshot-relative path.
   *
   * An SVG that SvgCheck rejects is left out.
   */
  public function assets(): array {
    return $this->readAssets()['assets'];
  }

  /**
   * Returns the SVG assets left out, with the reason for each.
   *
   * @return string[]
   *   Lines such as "Asset assets/icon.svg: holds a script element".
   */
  public function skippedAssets(): array {
    return $this->readAssets()['skipped'];
  }

  /**
   * Returns the theme's Legal files, keyed by theme-relative path.
   */
  public function legalFiles(): array {
    return $this->readLegalFiles()['files'];
  }

  /**
   * Returns the Legal files left out, with the reason for each.
   *
   * @return string[]
   *   Lines such as "Legal file legal/assets/Open Font License.txt: ...".
   */
  public function skippedLegalFiles(): array {
    return $this->readLegalFiles()['skipped'];
  }

  /**
   * Reads the files legal.json lists.
   *
   * A path whose characters a generated theme path does not allow, such as
   * a space, is left out rather than failing the conversion.
   */
  private function readLegalFiles(): array {
    if ($this->legalFilesCache !== NULL) {
      return $this->legalFilesCache;
    }
    $paths = json_decode($this->read('legal.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($paths) || !array_is_list($paths)) {
      throw new \UnexpectedValueException('Snapshot manifest legal.json must contain a list.');
    }
    $files = [];
    $skipped = [];
    foreach ($paths as $path) {
      if (is_string($path) && !PathValidator::hasSafeShape($path, self::LEGAL_PREFIX)) {
        // JSON encoding escapes control characters before the name reaches
        // the terminal.
        $skipped[] = sprintf('Legal file %s: its name has characters a Drupal theme path does not allow.', json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        continue;
      }
      $this->checkPaths('legal.json', [$path], self::LEGAL_PREFIX, ['txt', 'md', '']);
      $relative = substr($path, strlen(self::LEGAL_PREFIX));
      $legal = str_starts_with($relative, self::PLUGIN_ASSETS) ? $this->isPluginLegalFile($relative) : preg_match(self::LEGAL_FILES, $relative);
      if (!$legal) {
        throw new \UnexpectedValueException("Snapshot manifest legal.json lists a file that is not a Legal file: $path");
      }
      $files[$relative] = $this->read($path);
    }
    return $this->legalFilesCache = ['files' => $files, 'skipped' => $skipped];
  }

  /**
   * Returns whether a theme path names a plugin's root Legal file.
   */
  private function isPluginLegalFile(string $relative): bool {
    $plugin = self::pluginDirectory($relative);
    return $plugin !== NULL
      && isset($this->pluginHeaders()[$plugin])
      && preg_match(self::PLUGIN_LEGAL_FILES, substr($relative, strlen(self::PLUGIN_ASSETS . "$plugin/")));
  }

  /**
   * Returns the plugin directory a theme path under PLUGIN_ASSETS sits in.
   *
   * @return string|null
   *   The directory, or NULL for a path outside any plugin directory.
   */
  public static function pluginDirectory(string $path): ?string {
    if (!str_starts_with($path, self::PLUGIN_ASSETS)) {
      return NULL;
    }
    $parts = explode('/', substr($path, strlen(self::PLUGIN_ASSETS)), 2);
    return count($parts) === 2 ? $parts[0] : NULL;
  }

  /**
   * Reads the files assets.json lists and checks each SVG.
   */
  private function readAssets(): array {
    if ($this->assetsCache !== NULL) {
      return $this->assetsCache;
    }
    $assets = [];
    $skipped = [];
    foreach ($this->manifest('assets.json', 'assets/', self::ASSET_EXTENSIONS) as $path) {
      if (str_starts_with($path, self::PLUGIN_ASSETS) && !isset($this->pluginHeaders()[self::pluginDirectory($path) ?? ''])) {
        throw new \UnexpectedValueException("Snapshot manifest assets.json lists a plugin asset outside an installed plugin directory: $path");
      }
      $contents = $this->read($path);
      $problem = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg' ? SvgCheck::problem($contents) : NULL;
      if ($problem !== NULL) {
        $skipped[] = "Asset $path: $problem";
        continue;
      }
      $assets[$path] = $contents;
    }
    return $this->assetsCache = ['assets' => $assets, 'skipped' => $skipped];
  }

  /**
   * Returns what the theme states in its theme.json.
   *
   * Playground writes settings.json, so its shape is checked here. Each
   * duotone filter prints as trusted markup, so it must also pass SvgCheck.
   */
  public function settings(): array {
    $settings = json_decode($this->read('settings.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    $wideSize = $settings['layout']['wideSize'] ?? NULL;
    $fluid = $settings['typography']['fluid'] ?? NULL;
    $variations = $settings['styled_variations'] ?? NULL;
    $registered = $settings['registered_variations'] ?? NULL;
    $blockGaps = $settings['block_gaps'] ?? NULL;
    $buttonWidths = $settings['button_widths'] ?? NULL;
    $position = $settings['position'] ?? NULL;
    $duotoneFilters = $settings['duotone_filters'] ?? NULL;
    if (
      !is_array($settings)
      || !is_array($settings['layout'] ?? NULL)
      || ($wideSize !== NULL && !is_string($wideSize))
      || !is_bool($settings['use_root_padding_aware_alignments'] ?? NULL)
      || !is_bool($settings['block_gap_support'] ?? NULL)
      || (!is_bool($fluid) && !is_array($fluid))
      || !is_array($variations)
      || array_filter($variations, 'is_string') !== $variations
      || !is_array($registered)
      || array_filter($registered, static fn (mixed $names): bool => is_array($names) && array_filter($names, 'is_string') === $names) !== $registered
      || !is_array($blockGaps)
      || array_filter($blockGaps, self::isGap(...)) !== $blockGaps
      || !array_key_exists('root_block_gap', $settings)
      || !($settings['root_block_gap'] === NULL || self::isGap($settings['root_block_gap']))
      || !is_array($buttonWidths)
      || array_filter($buttonWidths, 'is_string') !== $buttonWidths
      || !is_bool($position['sticky'] ?? NULL)
      || !is_bool($position['fixed'] ?? NULL)
      || !is_array($duotoneFilters)
      || array_filter($duotoneFilters, static fn (mixed $svg): bool => is_string($svg) && str_starts_with($svg, '<svg') && SvgCheck::problem($svg) === NULL) !== $duotoneFilters
    ) {
      throw new \UnexpectedValueException('Snapshot settings.json has an invalid shape. Run with --refresh.');
    }
    return $settings;
  }

  /**
   * Returns the size of a dimension preset a Button width can name, or NULL.
   */
  public function buttonWidth(string $slug): ?string {
    return $this->settings()['button_widths'][$slug] ?? NULL;
  }

  /**
   * Returns the presets of the theme, as lists keyed by preset group.
   *
   * Playground writes presets.json, so its shape is checked here: a list
   * per group of DesignTokens::GROUPS, each preset with a string slug, name
   * and value.
   *
   * @see \Drupal\wordpal_convert\Theme\DesignTokens
   */
  public function presets(): array {
    $presets = json_decode($this->read('presets.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    foreach (DesignTokens::GROUPS as $group => $valueKey) {
      $list = is_array($presets) ? ($presets[$group] ?? NULL) : NULL;
      if (!is_array($list) || !array_is_list($list)) {
        throw new \UnexpectedValueException("Snapshot presets.json has no \"$group\" list. Run with --refresh.");
      }
      foreach ($list as $preset) {
        // theme.json makes a preset's name optional; WordPress shows none.
        if (!is_string($preset['slug'] ?? NULL) || !is_string($preset[$valueKey] ?? NULL) || (isset($preset['name']) && !is_string($preset['name']))) {
          throw new \UnexpectedValueException("Snapshot presets.json lists an invalid \"$group\" preset. Run with --refresh.");
        }
      }
    }
    return $presets;
  }

  /**
   * Returns the patterns of the theme, keyed by slug.
   *
   * Playground writes patterns.json, so its shape is checked here. A key is
   * the slug with "/" replaced by "--", as the pattern's file is named.
   */
  public function patterns(): array {
    if ($this->patternsCache !== NULL) {
      return $this->patternsCache;
    }
    $patterns = json_decode($this->read('patterns.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($patterns)) {
      throw new \UnexpectedValueException('Snapshot patterns.json must contain a map. Run with --refresh.');
    }
    foreach ($patterns as $key => $pattern) {
      $categories = $pattern['categories'] ?? NULL;
      if (
        !is_string($pattern['slug'] ?? NULL)
        || (string) $key !== str_replace('/', '--', $pattern['slug'])
        || !is_string($pattern['title'] ?? NULL)
        || !is_array($categories)
        || !array_is_list($categories)
        || array_filter($categories, 'is_string') !== $categories
        || !is_bool($pattern['inserter'] ?? NULL)
      ) {
        throw new \UnexpectedValueException("Snapshot patterns.json has an invalid entry for $key. Run with --refresh.");
      }
    }
    return $this->patternsCache = $patterns;
  }

  /**
   * Returns the theme and plugins WordPress rendered with.
   *
   * @return array<string, string|null>
   *   The SHA-256 hash of each zip source, NULL for a WordPress.org slug,
   *   keyed by Source::key() and sorted by key.
   */
  public function sources(): array {
    return $this->metadata()['sources'];
  }

  /**
   * Returns whether WordPress installed the theme from its WordPress.org slug.
   */
  public function fromWordPressOrg(): bool {
    return $this->sources()[Source::key(SourceType::Theme, $this->themeSlug)] === NULL;
  }

  /**
   * Returns the theme's style.css attribution headers, '' when not stated.
   *
   * @return array{license: string, license_uri: string, theme_uri: string, author: string, author_uri: string}
   *   The headers, keyed as metadata.json stores them.
   */
  public function themeHeaders(): array {
    $metadata = $this->metadata();
    return [
      'license' => $metadata['license'],
      'license_uri' => $metadata['license_uri'],
      'theme_uri' => $metadata['theme_uri'],
      'author' => $metadata['author'],
      'author_uri' => $metadata['author_uri'],
    ];
  }

  /**
   * Returns the attribution headers of each installed plugin directory.
   *
   * @return array<string, array{name: string, version: string, plugin_uri: string, author: string, author_uri: string, license: string, license_uri: string}>
   *   The headers, '' when not stated, keyed by plugin directory.
   */
  public function pluginHeaders(): array {
    return $this->metadata()['plugin_headers'];
  }

  /**
   * Returns whether WordPress installed a plugin from its WordPress.org slug.
   *
   * A plugin directory the conversion did not name has no source entry.
   */
  public function pluginFromWordPressOrg(string $plugin): bool {
    $key = Source::key(SourceType::Plugin, $plugin);
    return array_key_exists($key, $this->sources()) && $this->sources()[$key] === NULL;
  }

  /**
   * Returns the UTC date WordPress ran, such as "2026-09-30".
   */
  public function snapshotDate(): string {
    return $this->metadata()['snapshot_date'];
  }

  /**
   * Returns the theme screenshot's snapshot path, or NULL when it has none.
   */
  public function screenshot(): ?string {
    return $this->metadata()['screenshot'];
  }

  /**
   * Returns the posts per page of WordPress's main query.
   */
  public function postsPerPage(): int {
    return $this->metadata()['posts_per_page'];
  }

  /**
   * Returns the tag WordPress prints for a template part reference.
   *
   * A reference with no tagName prints its part's area tag, such as
   * "header".
   */
  public function partTag(string $name): string {
    return $this->metadata()['part_tags'][$name]
      ?? throw new \UnexpectedValueException("Snapshot metadata.json has no tag for template part $name.");
  }

  /**
   * Returns the decoded metadata.json.
   */
  private function metadata(): array {
    return json_decode($this->read('metadata.json'), TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Returns the block types WordPress registered, such as "core/paragraph".
   *
   * @return string[]
   *   Block names.
   */
  public function registeredBlocks(): array {
    return array_keys($this->blocks());
  }

  /**
   * Reads blocks.json: each registered block name to its declared supports.
   *
   * Uncached: a caller can rewrite blocks.json on disk between two calls,
   * such as a test that registers one more block on an existing Snapshot.
   */
  private function blocks(): array {
    $blocks = json_decode($this->read('blocks.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($blocks)) {
      throw new \UnexpectedValueException('Snapshot blocks.json must contain a map.');
    }
    foreach ($blocks as $name => $supports) {
      if (!is_string($name) || !str_contains($name, '/') || !is_array($supports)) {
        throw new \UnexpectedValueException("Snapshot blocks.json has an invalid entry for $name.");
      }
    }
    return $blocks;
  }

  /**
   * Returns the block render index entries of one snapshot file.
   *
   * Playground writes render-index.json, so its shape is checked here: one
   * map of block path to HTML per snapshot file. Public so Evaluation's
   * ReferenceRenders can read a fixture's recorded renders.
   */
  public function renders(string $relativePath): array {
    $this->renderIndexCache ??= json_decode($this->read('render-index.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    $renders = $this->renderIndexCache[$relativePath] ?? NULL;
    if (!is_array($renders)) {
      throw new \UnexpectedValueException("Snapshot render-index.json has no entry for $relativePath.");
    }
    foreach ($renders as $path => $html) {
      if (!preg_match('/^\d+(\.\d+)*$/', (string) $path) || !is_string($html)) {
        throw new \UnexpectedValueException("Snapshot render-index.json has an invalid entry for $relativePath.");
      }
    }
    return $renders;
  }

  /**
   * Returns whether a value is a block gap: a string, or its string sides.
   */
  private static function isGap(mixed $gap): bool {
    return is_string($gap) || (is_array($gap) && $gap !== [] && array_filter($gap, 'is_string') === $gap);
  }

  /**
   * Returns the snapshot path of one template.
   */
  private static function templatePath(string $name): string {
    return "templates/$name.html";
  }

  /**
   * Returns the snapshot path of one template part.
   */
  private static function partPath(string $name): string {
    return "parts/$name.html";
  }

  /**
   * Returns the snapshot path of one pattern, from its registered slug.
   */
  private static function patternPath(string $slug): string {
    return 'patterns/' . str_replace('/', '--', $slug) . '.html';
  }

  /**
   * Returns one pattern record by its slug.
   */
  private function patternMetadata(string $slug): array {
    $pattern = $this->findPattern($slug);
    if ($pattern === NULL) {
      throw new \RuntimeException("Snapshot of theme $this->themeSlug has no pattern $slug.");
    }
    return $pattern;
  }

  /**
   * Finds one pattern record by its slug.
   */
  private function findPattern(string $slug): ?array {
    $this->patternsBySlug ??= array_column($this->patterns(), NULL, 'slug');
    return $this->patternsBySlug[$slug] ?? NULL;
  }

  /**
   * Returns whether the snapshot holds one file.
   */
  public function has(string $relativePath): bool {
    $path = $this->directory . '/' . $relativePath;
    if (!is_file($path)) {
      return FALSE;
    }
    $this->checkContained($path, $relativePath);
    return TRUE;
  }

  /**
   * Reads one file of the snapshot.
   *
   * Public so Evaluation's ReferenceRenders can read a Reference render or
   * fixture through the same path-escape check.
   */
  public function read(string $relativePath): string {
    $path = $this->directory . '/' . $relativePath;
    if (!is_file($path)) {
      throw new \RuntimeException("Snapshot of theme $this->themeSlug has no $relativePath.");
    }
    $this->checkContained($path, $relativePath);
    return (string) file_get_contents($path);
  }

  /**
   * Rejects an existing snapshot file that resolves outside the snapshot.
   */
  private function checkContained(string $path, string $relativePath): void {
    $root = realpath($this->directory);
    $resolved = realpath($path);
    if ($root === FALSE || $resolved === FALSE || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
      throw new \UnexpectedValueException("Snapshot path escapes theme directory: $relativePath");
    }
  }

  /**
   * Reads and validates paths from a required snapshot manifest.
   */
  private function manifest(string $file, string $prefix, array $extensions): array {
    $paths = json_decode($this->read($file), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($paths) || !array_is_list($paths)) {
      throw new \UnexpectedValueException("Snapshot manifest $file must contain a list.");
    }
    $this->checkPaths($file, $paths, $prefix, $extensions);
    return $paths;
  }

  /**
   * Rejects manifest paths that escape the snapshot or are missing or linked.
   */
  private function checkPaths(string $file, array $paths, string $prefix, array $extensions): void {
    foreach ($paths as $path) {
      $extension = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
      if (!is_string($path) || !PathValidator::hasSafeShape($path, $prefix) || !in_array($extension, $extensions, TRUE)) {
        throw new \UnexpectedValueException("Snapshot manifest $file contains an unsafe path.");
      }
      $resolved = realpath($this->directory . '/' . $path);
      $root = realpath($this->directory);
      if ($resolved === FALSE || $root === FALSE || is_link($this->directory . '/' . $path) || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        throw new \UnexpectedValueException("Snapshot manifest $file contains a missing or linked path: $path");
      }
    }
  }

}
