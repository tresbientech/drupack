<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal\Theme\SiteBlocks;
use Drupal\wordpal\Theme\StyleVariation;
use Drupal\wordpal_convert\Support\PathValidator;
use Drupal\wordpal_convert\WordPress\Snapshot;
use Drupal\wordpal_convert\WordPress\Source;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a Drupal theme from a WordPress snapshot.
 */
final class ThemeGenerator {

  /**
   * Starterkit files, keyed by source path under starterkit/.
   *
   * Valued by the theme-relative target, with "theme" standing for the
   * theme id. The PHP and YAML sources carry a ".starterkit" suffix so the
   * repository's pre-commit hook does not parse their unfilled tokens as
   * PHP or YAML.
   */
  public const STARTERKIT_FILES = [
    'theme.theme.starterkit' => 'theme.theme',
    'theme-settings.php.starterkit' => 'theme-settings.php',
    'config/schema/theme.schema.yml.starterkit' => 'config/schema/theme.schema.yml',
    'templates/page.html.twig' => 'templates/page.html.twig',
  ];

  /**
   * The file map outputs() built, for the snapshot and Frozen HTML it built.
   */
  private ?array $outputsCache = NULL;

  /**
   * The snapshot outputsCache was built from.
   */
  private ?Snapshot $outputsCacheSnapshot = NULL;

  /**
   * The Frozen HTML outputsCache was built from.
   */
  private ?array $outputsCacheFrozen = NULL;

  /**
   * Returns the app-root-relative directory of one generated theme.
   */
  public static function directory(string $themeId): string {
    return "themes/custom/$themeId/";
  }

  public function __construct(
    private readonly string $appRoot,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Writes the generated theme and returns its Drupal machine name.
   *
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param array<string, string> $frozen
   *   Frozen block HTML keyed by FrozenBlock::key(), one theme file each.
   */
  public function generate(Snapshot $snapshot, array $frozen): string {
    $themeId = self::themeId($snapshot->themeSlug);
    $outputs = $this->outputs($snapshot, $frozen);
    $this->validatePaths($themeId, array_keys($outputs));
    foreach ($outputs as $path => $contents) {
      $this->write($this->appRoot . '/' . $path, $contents);
    }

    return $themeId;
  }

  /**
   * Lists every file this snapshot will place in the generated theme.
   *
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param array<string, string> $frozen
   *   Frozen block HTML keyed by FrozenBlock::key(), one theme file each.
   */
  public function filePaths(Snapshot $snapshot, array $frozen): array {
    $paths = array_keys($this->outputs($snapshot, $frozen));
    $this->validatePaths(self::themeId($snapshot->themeSlug), $paths);
    return $paths;
  }

  /**
   * Rejects paths outside this theme and links in their destination chain.
   */
  public function validatePaths(string $themeId, array $paths): void {
    $themeId = self::themeId(str_replace('_', '-', $themeId));
    $prefix = self::directory($themeId);
    foreach ($paths as $path) {
      if (!is_string($path) || !PathValidator::hasSafeShape($path, $prefix)) {
        throw new \UnexpectedValueException("Invalid generated theme path: $path");
      }
      $current = $this->appRoot;
      foreach (explode('/', $path) as $part) {
        $current .= '/' . $part;
        if (is_link($current)) {
          throw new \UnexpectedValueException("Generated theme path follows a link: $path");
        }
      }
    }
  }

  /**
   * Builds generated file contents keyed by their app-root-relative path.
   *
   * Both generate() and filePaths() need this map for the same conversion,
   * one to write it and one to list it for the ownership manifest.
   */
  private function outputs(Snapshot $snapshot, array $frozen): array {
    if ($this->outputsCache !== NULL && $this->outputsCacheSnapshot === $snapshot && $this->outputsCacheFrozen === $frozen) {
      return $this->outputsCache;
    }
    $themeId = self::themeId($snapshot->themeSlug);
    $styles = $snapshot->styles();
    $assets = $snapshot->assets();
    $variations = $snapshot->variations();
    $blockStyles = array_intersect_key($snapshot->blockStyles(), $frozen);
    $pluginStyles = self::shippedStyles($snapshot, $frozen);
    $plugins = self::shippedPlugins($snapshot, $frozen);
    $scripts = self::exportedScripts($snapshot, $themeId);
    $base = self::directory($themeId);
    $outputs = [
      $base . "$themeId.info.yml" => $this->info($snapshot, $themeId, $variations, array_keys($scripts)),
      $base . "$themeId.libraries.yml" => $this->libraries($themeId, array_keys($styles), $variations, $pluginStyles, $scripts),
      $base . "$themeId.tokens.yml" => DesignTokens::yaml($snapshot->presets()),
      $base . "config/install/$themeId.settings.yml" => Yaml::dump([StyleVariation::SETTING => Snapshot::DEFAULT_VARIATION]),
    ];
    foreach (self::STARTERKIT_FILES as $source => $target) {
      $contents = self::fill(file_get_contents(__DIR__ . '/../../starterkit/' . $source), $themeId);
      foreach (['{theme}', '{setting}', '{library}', '{site_blocks_class}'] as $token) {
        if (str_contains($contents, $token)) {
          throw new \LogicException("Starterkit $source has an unfilled token.");
        }
      }
      // "theme" stands for the theme id only right before a dot, so the
      // fixed Drupal filenames theme-settings.php and page.html.twig pass
      // through unchanged.
      $outputs[$base . preg_replace('/theme(?=\.)/', $themeId, $target)] = $contents;
    }
    $pluginCss = implode("\n", array_column($pluginStyles, 'css'));
    foreach ($styles + $assets as $path => $contents) {
      if (self::shipsAsset($path, $pluginCss)) {
        $outputs[$base . $path] = $contents;
      }
    }
    foreach ($variations as $variation) {
      $outputs[$base . $variation['path']] = $variation['css'];
    }
    foreach ($pluginStyles as $style) {
      $outputs[$base . $style['path']] = $style['css'];
    }
    foreach ($scripts as $script) {
      $outputs[$base . $script['path']] = $script['js'];
    }
    foreach ($frozen as $key => $html) {
      $outputs[$base . FrozenBlock::path($key)] = $html;
      if (isset($blockStyles[$key])) {
        $outputs[$base . FrozenBlock::librariesPath($key)] = Yaml::dump(array_map(self::pluginLibrary(...), $blockStyles[$key]));
      }
    }
    foreach (self::shippedLegalFiles($snapshot, $plugins) as $path => $contents) {
      $outputs[$base . $path] = $contents;
    }
    $screenshot = $snapshot->screenshot();
    if ($screenshot !== NULL) {
      $outputs[$base . $screenshot] = $snapshot->read($screenshot);
    }
    $outputs[$base . 'ATTRIBUTION.md'] = self::attribution($snapshot, $plugins);
    $this->moduleHandler->alter('wordpal_theme_files', $outputs, $themeId);
    $this->outputsCacheSnapshot = $snapshot;
    $this->outputsCacheFrozen = $frozen;
    return $this->outputsCache = $outputs;
  }

  /**
   * Returns the plugin stylesheets the Frozen blocks need, keyed by handle.
   */
  private static function shippedStyles(Snapshot $snapshot, array $frozen): array {
    $blockStyles = array_intersect_key($snapshot->blockStyles(), $frozen);
    return self::requiredStyles($snapshot->pluginStyles(), array_merge([], ...array_values($blockStyles)));
  }

  /**
   * Returns the plugin directories whose files ship in the generated theme.
   *
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param array<string, string> $frozen
   *   Frozen block HTML keyed by FrozenBlock::key().
   *
   * @return string[]
   *   Plugin directories, sorted.
   */
  public static function shippedPlugins(Snapshot $snapshot, array $frozen): array {
    $styles = self::shippedStyles($snapshot, $frozen);
    $pluginCss = implode("\n", array_column($styles, 'css'));
    $plugins = array_column($styles, 'plugin');
    // A stylesheet's url() can reach an asset in another plugin's directory.
    foreach (array_keys($snapshot->assets()) as $path) {
      $plugin = Snapshot::pluginDirectory($path);
      if ($plugin !== NULL && self::shipsAsset($path, $pluginCss)) {
        $plugins[] = $plugin;
      }
    }
    $plugins = array_values(array_unique($plugins));
    sort($plugins);
    return $plugins;
  }

  /**
   * Returns whether a snapshot style or asset ships in the generated theme.
   *
   * A plugin asset ships with the plugin stylesheets that reference it.
   */
  private static function shipsAsset(string $path, string $pluginCss): bool {
    return !str_starts_with($path, Snapshot::PLUGIN_ASSETS) || str_contains($pluginCss, "../$path");
  }

  /**
   * Returns the theme's Legal files and those of the shipped plugins.
   *
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param string[] $plugins
   *   The plugin directories whose files ship.
   */
  private static function shippedLegalFiles(Snapshot $snapshot, array $plugins): array {
    return array_filter(
      $snapshot->legalFiles(),
      static fn (string $path): bool => Snapshot::pluginDirectory($path) === NULL || in_array(Snapshot::pluginDirectory($path), $plugins, TRUE),
      ARRAY_FILTER_USE_KEY,
    );
  }

  /**
   * Returns the plugin stylesheets $handles need, with their dependencies.
   *
   * @param array<string, array> $pluginStyles
   *   The snapshot's plugin stylesheets, keyed by handle.
   * @param string[] $handles
   *   The handles the Frozen blocks attach.
   */
  private static function requiredStyles(array $pluginStyles, array $handles): array {
    $required = [];
    while ($handles !== []) {
      $handle = array_shift($handles);
      if (!isset($required[$handle])) {
        $required[$handle] = $pluginStyles[$handle];
        $handles = [...$handles, ...$pluginStyles[$handle]['dependencies']];
      }
    }
    ksort($required);
    return $required;
  }

  /**
   * Validates a WordPress.org slug and returns its Drupal machine name.
   */
  public static function themeId(string $themeSlug): string {
    if (!preg_match(Source::SLUG, $themeSlug)) {
      // The WordPress.org slug controls snapshot and generated theme paths.
      throw new \InvalidArgumentException("Invalid WordPress theme slug: $themeSlug");
    }
    return str_replace('-', '_', $themeSlug);
  }

  /**
   * Returns the WordPress.org slug a generated theme's machine name has.
   *
   * A slug holds no underscore (Source::SLUG), so themeId() is reversible.
   */
  public static function themeSlug(string $themeId): string {
    return str_replace('_', '-', $themeId);
  }

  /**
   * Builds the generated theme metadata.
   */
  private function info(Snapshot $snapshot, string $themeId, array $variations, array $scripts): string {
    $info = [
      'name' => $snapshot->themeName,
      'description' => self::description($snapshot),
      'type' => 'theme',
      'base theme' => FALSE,
      'core_version_requirement' => '^11.4',
      // The components, blocks and Twig extension the theme renders.
      'dependencies' => ['wordpal:wordpal'],
      // Drupal's own page elements take the theme's style, and theme scripts
      // load on every page, as WordPress enqueues them.
      'libraries' => [
        "$themeId/global",
        'wordpal/drupal_ui',
        ...array_map(static fn (string $handle): string => "$themeId/" . self::scriptLibrary($handle), $scripts),
      ],
      'regions' => ['content' => 'Content'],
      // The theme settings form lists these titles.
      'wordpal_variations' => array_map(static fn (array $variation): string => $variation['title'], $variations),
    ];
    // Drupal reads screenshot.png when the key is absent.
    $screenshot = $snapshot->screenshot();
    if ($screenshot !== NULL && $screenshot !== 'screenshot.png') {
      $info['screenshot'] = $screenshot;
    }
    return Yaml::dump($info, 4, 2);
  }

  /**
   * Returns the links to the original theme.
   *
   * A slug theme links its WordPress.org page, and its Theme URI when that
   * names another page. A zip or URL theme links its Theme URI.
   *
   * @return string[]
   *   The URLs, none when a zip or URL theme has no usable Theme URI.
   */
  public static function originalLinks(Snapshot $snapshot): array {
    return self::links($snapshot->fromWordPressOrg() ? 'https://wordpress.org/themes/' . $snapshot->themeSlug . '/' : NULL, $snapshot->themeHeaders()['theme_uri']);
  }

  /**
   * Returns a directory page, and a header URI when it names another page.
   *
   * @param string|null $directoryPage
   *   The WordPress.org page, or NULL for a zip or URL source.
   * @param string $uri
   *   The Theme URI or Plugin URI header.
   */
  private static function links(?string $directoryPage, string $uri): array {
    $links = $directoryPage === NULL ? [] : [$directoryPage];
    $headerUrl = self::url($uri);
    $page = static fn (string $url): string => rtrim((string) preg_replace('#^https?://(?:www\.)?#i', '', strtolower($url)), '/');
    if ($headerUrl !== NULL && !in_array($page($headerUrl), array_map($page, $links), TRUE)) {
      $links[] = $headerUrl;
    }
    return $links;
  }

  /**
   * Returns a header value as a link target, or NULL when it is not one.
   *
   * Headers come from the theme's style.css, which a zip supplies, so only
   * a plain http or https URL becomes a link.
   */
  private static function url(string $value): ?string {
    return preg_match('#^https?://[^\s<>"\'`\\\\]+\z#i', $value) ? $value : NULL;
  }

  /**
   * Returns a style.css header as plain text.
   *
   * WordPress keeps a few inline tags in the Author header.
   */
  private static function text(string $value): string {
    return trim(strip_tags($value));
  }

  /**
   * Builds the info.yml description the Appearance page shows.
   *
   * Drupal filters it with Xss::filterAdmin(), which keeps the links. Each
   * header is escaped first, since a zip supplies it.
   */
  private static function description(Snapshot $snapshot): string {
    $headers = $snapshot->themeHeaders();
    $description = 'Converted by WordPal from the WordPress theme ' . Html::escape($snapshot->themeName . ' ' . $snapshot->themeVersion);
    $author = self::text($headers['author']);
    if ($author !== '') {
      $description .= ' by ' . Html::escape($author);
    }
    $license = self::text($headers['license']);
    $description .= $license === '' ? '. License not stated.' : '. License: ' . Html::escape($license) . '.';
    $links = array_map(static fn (string $url): string => '<a href="' . Html::escape($url) . '">' . Html::escape($url) . '</a>', self::originalLinks($snapshot));
    if ($links !== []) {
      $description .= ' Original: ' . implode(', ', $links) . '.';
    }
    return $description;
  }

  /**
   * Builds ATTRIBUTION.md, the Attribution notice.
   *
   * The snapshot date stands for the date of the modification the GPL asks
   * a modified copy to state.
   */
  private static function attribution(Snapshot $snapshot, array $plugins): string {
    $legalFiles = array_keys(self::shippedLegalFiles($snapshot, $plugins));
    $lines = [
      '# Attribution',
      '',
      sprintf('This Drupal theme is a conversion of the WordPress theme %s %s. WordPal converted and modified it on %s.', self::markdown($snapshot->themeName), self::markdown($snapshot->themeVersion), $snapshot->snapshotDate()),
      '',
      ...self::credits($snapshot->themeHeaders(), self::originalLinks($snapshot), 'Original theme'),
    ];
    $themeFiles = array_filter($legalFiles, static fn (string $path): bool => Snapshot::pluginDirectory($path) === NULL);
    if ($themeFiles !== []) {
      $lines[] = '';
      $lines[] = 'The theme\'s own copyright and license notices are in ' . self::fileList($themeFiles) . '.';
    }
    if ($plugins !== []) {
      $lines[] = '';
      $lines[] = '## Plugins';
      $lines[] = '';
      $lines[] = 'The theme also holds stylesheets and assets copied from these WordPress plugins.';
    }
    $pluginHeaders = $snapshot->pluginHeaders();
    foreach ($plugins as $plugin) {
      $headers = $pluginHeaders[$plugin];
      $page = $snapshot->pluginFromWordPressOrg($plugin) ? "https://wordpress.org/plugins/$plugin/" : NULL;
      $lines[] = '';
      $lines[] = sprintf('### %s %s', self::markdown(self::text($headers['name'])), self::markdown($headers['version']));
      $lines[] = '';
      $lines = [...$lines, ...self::credits($headers, self::links($page, $headers['plugin_uri']), 'Original plugin')];
      $pluginFiles = array_filter($legalFiles, static fn (string $path): bool => Snapshot::pluginDirectory($path) === $plugin);
      if ($pluginFiles !== []) {
        $lines[] = '- Notices: ' . self::fileList($pluginFiles);
      }
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * Returns the author, license and link lines of a theme or plugin.
   *
   * @param array{author: string, author_uri: string, license: string, license_uri: string} $headers
   *   The style.css or plugin headers.
   * @param string[] $links
   *   The links to the original.
   * @param string $linkLabel
   *   The label of each link line.
   */
  private static function credits(array $headers, array $links, string $linkLabel): array {
    $lines = [];
    $author = self::text($headers['author']);
    $authorUri = self::url($headers['author_uri']);
    if ($author !== '' || $authorUri !== NULL) {
      $lines[] = '- Author: ' . trim(self::markdown($author) . ($authorUri === NULL ? '' : " <$authorUri>"));
    }
    $license = self::text($headers['license']);
    $licenseUri = self::url($headers['license_uri']);
    $lines[] = '- License: ' . ($license === '' ? 'not stated' : self::markdown($license)) . ($licenseUri === NULL ? '' : " <$licenseUri>");
    foreach ($links as $url) {
      $lines[] = "- $linkLabel: <$url>";
    }
    return $lines;
  }

  /**
   * Returns theme paths as a comma-separated list of code spans.
   */
  private static function fileList(array $paths): string {
    return implode(', ', array_map(static fn (string $path): string => "`$path`", $paths));
  }

  /**
   * Escapes a header for Markdown text, so it cannot open a link or tag.
   */
  private static function markdown(string $value): string {
    return (string) preg_replace('/([\\\\`*_\[\]<>])/', '\\\\$1', $value);
  }

  /**
   * Builds the library definitions for the generated theme.
   *
   * The global library holds every stylesheet but the Global stylesheet,
   * which each style variation replaces with its own library. The weights
   * keep WordPress's order: block library CSS, Global stylesheet, the rest.
   * Each plugin stylesheet is a library of its own, which the Frozen blocks
   * needing it attach. Each exported theme script is a library too.
   */
  private function libraries(string $themeId, array $styles, array $variations, array $pluginStyles, array $scripts): string {
    $css = [];
    foreach ($styles as $path) {
      $css[$path] = $path === Snapshot::BLOCK_LIBRARY_CSS ? ['weight' => -2] : [];
    }
    $libraries = [
      'global' => [
        'version' => '1.x',
        'css' => ['theme' => $css],
      ],
    ];
    foreach ($variations as $slug => $variation) {
      $libraries[self::variationLibrary($slug)] = [
        'version' => '1.x',
        'css' => ['theme' => [$variation['path'] => ['weight' => -1]]],
      ];
    }
    foreach ($pluginStyles as $handle => $style) {
      $libraries[self::pluginLibrary((string) $handle)] = [
        'version' => '1.x',
        'css' => ['theme' => [$style['path'] => []]],
        'dependencies' => array_map(static fn (string $dependency): string => "$themeId/" . self::pluginLibrary($dependency), $style['dependencies']),
      ];
    }
    foreach ($scripts as $handle => $script) {
      $attributes = $script['strategy'] === NULL ? [] : ['attributes' => [$script['strategy'] => TRUE]];
      $libraries[self::scriptLibrary((string) $handle)] = [
        'version' => '1.x',
        'header' => !$script['footer'],
        'js' => [$script['path'] => $attributes],
        'dependencies' => $script['libraries'],
      ];
    }
    return Yaml::dump($libraries, 5, 2);
  }

  /**
   * Returns the theme scripts the generated theme exports, keyed by handle.
   *
   * Each keeps its snapshot fields and gains the Drupal libraries it
   * depends on.
   */
  private static function exportedScripts(Snapshot $snapshot, string $themeId): array {
    $scripts = $snapshot->themeScripts();
    $libraries = ScriptDependencies::resolve($scripts, $themeId)['libraries'];
    $exported = [];
    foreach ($scripts as $script) {
      if (isset($libraries[$script['handle']])) {
        $exported[$script['handle']] = $script + ['libraries' => $libraries[$script['handle']]];
      }
    }
    return $exported;
  }

  /**
   * Returns the library name of one theme script.
   */
  public static function scriptLibrary(string $handle): string {
    return "script.$handle";
  }

  /**
   * Returns the library name of one plugin stylesheet.
   */
  private static function pluginLibrary(string $handle): string {
    return "plugin.$handle";
  }

  /**
   * Returns the library name of one style variation.
   */
  private static function variationLibrary(string $slug): string {
    return "variation.$slug";
  }

  /**
   * Fills the theme id, setting, library prefix and site blocks class.
   *
   * Fills every token a starterkit template holds.
   */
  private static function fill(string $template, string $themeId): string {
    return strtr($template, [
      '{theme}' => $themeId,
      '{setting}' => StyleVariation::SETTING,
      '{library}' => self::variationLibrary(''),
      '{site_blocks_class}' => SiteBlocks::SITE_BLOCKS_CLASS,
    ]);
  }

  /**
   * Writes a generated file and creates its parent directory.
   */
  private function write(string $path, string $contents): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, TRUE) && !is_dir($directory)) {
      throw new \RuntimeException("Could not create generated theme directory: $directory");
    }
    if (file_put_contents($path, $contents) === FALSE) {
      throw new \RuntimeException("Could not write generated theme file: $path");
    }
  }

}
