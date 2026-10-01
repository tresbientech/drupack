<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\WordPress;

use Drupal\Core\File\FileSystemInterface;
use Drupal\wordpal_convert\Support\PathValidator;
use Drupal\wordpal_convert\Theme\TemplateResolver;
use Drupal\wordpal_convert\Content\ContentFixtureLoader;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Runs WordPress through WordPress Playground to export a theme.
 */
final class PlaygroundRunner {

  /**
   * The Playground CLI release this module runs.
   */
  public const PLAYGROUND_VERSION = '3.1.54';

  /**
   * The PHP version the blueprint pins, matching the CLI's own default.
   */
  private const PHP_VERSION = '8.3';

  /**
   * The Playground mount point of the snapshot directory.
   */
  private const MOUNT = '/wordpal';

  /**
   * The snapshot subdirectory holding zip sources.
   */
  private const SOURCES = 'in/sources';

  /**
   * The snapshot subdirectory holding this run's copy of the PHP scripts.
   *
   * Playground mounts read-write only, and a theme's code runs before the
   * scripts finish, so it sees a copy and never the module's own files.
   */
  private const SCRIPTS = 'in/scripts';

  /**
   * The snapshot directory file PHP logs errors to, outside the snapshot.
   *
   * WordPress answers a fatal error with a generic critical error page, so
   * only this log names the failing file and line.
   */
  private const PHP_LOG = 'php-error.log';

  /**
   * Returns the on-disk directory holding one theme's snapshot.
   */
  public static function directory(FileSystemInterface $fileSystem, string $theme): string {
    return $fileSystem->getTempDirectory() . '/wordpal/' . $theme;
  }

  /**
   * Returns the directory URL theme and plugin references download to.
   */
  public static function downloadDirectory(FileSystemInterface $fileSystem): string {
    return $fileSystem->getTempDirectory() . '/wordpal/downloads';
  }

  /**
   * Refuses a source that requires a newer WordPress than the component set.
   */
  public function checkRequiredVersion(Source $source, string $wordPressVersion): void {
    if ($source->requires !== '' && version_compare($source->requires, $wordPressVersion, '>')) {
      throw new \RuntimeException(sprintf(
        '%s %s requires WordPress %s, and this component set is generated from WordPress %s.',
        $source->type->label(), $source->slug, $source->requires, $wordPressVersion,
      ));
    }
  }

  /**
   * Exports one theme from WordPress.
   *
   * @param \Drupal\wordpal_convert\WordPress\Source $theme
   *   The theme to install and activate.
   * @param \Drupal\wordpal_convert\WordPress\Source[] $plugins
   *   The plugins to install and activate before rendering.
   * @param string $wordPressVersion
   *   The WordPress version the component set was generated from.
   * @param string $directory
   *   Where to build the snapshot. Anything already there is replaced.
   * @param string $fixturePath
   *   Shared content fixture JSON to seed. Every run seeds one, since the
   *   block render index and the Reference renders render against it.
   * @param string[] $fixtures
   *   Block markup written into the block library input, keyed by fixture
   *   name; an extra script decides whether and how each renders. Empty for
   *   a Conversion, which mounts no block library input at all.
   * @param string|null $siteName
   *   The name a Reference render's Site Title should carry, in place of
   *   Playground's own default. NULL leaves that default in place.
   * @param string[] $extraScripts
   *   Absolute paths of PHP scripts to run, in order, after snapshot.php, in
   *   the same Playground request: they see its functions, constants and
   *   variables. Evaluation passes the script that writes Reference renders
   *   and fixtures; a Conversion passes none, so its snapshot holds neither.
   *
   * @return \Drupal\wordpal_convert\WordPress\Snapshot
   *   The snapshot.
   */
  public function snapshot(Source $theme, array $plugins, string $wordPressVersion, string $directory, string $fixturePath, array $fixtures = [], ?string $siteName = NULL, array $extraScripts = []): Snapshot {
    $this->prepare($directory, $theme, $plugins, $wordPressVersion, $fixturePath, $fixtures, $siteName, $extraScripts);
    $themeSlug = $theme->slug;

    $process = new Process([
      'npx',
      '-y',
      '@wp-playground/cli@' . self::PLAYGROUND_VERSION,
      'run-blueprint',
      '--blueprint=' . $directory . '/blueprint.json',
      // --mount-dir takes the host and VFS paths as two arguments, so the
      // colon of a Windows drive letter stays part of the host path.
      '--mount-dir',
      $directory,
      self::MOUNT,
      '--verbosity=quiet',
    ]);
    $process->setTimeout(900);
    $process->run();
    if (!$process->isSuccessful()) {
      $log = $directory . '/' . self::PHP_LOG;
      throw new \RuntimeException(self::failureMessage($themeSlug, (int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), is_file($log) ? (string) file_get_contents($log) : ''));
    }

    $metadataFile = $directory . '/out/metadata.json';
    if (!is_file($metadataFile)) {
      throw new \RuntimeException("WordPress wrote no snapshot for theme $themeSlug. Playground said:\n" . $process->getOutput());
    }
    $metadata = self::readMetadata($metadataFile);

    // The blueprint pins the exact wordpress.org release zip, but nothing
    // in Playground itself enforces that the pin held. A silent patch
    // drift here would validate and convert the theme against the wrong
    // WordPress release.
    if ($metadata['wordpress_version'] !== $wordPressVersion) {
      throw new \RuntimeException(sprintf(
        'Playground ran WordPress %s for theme %s, and this component set is generated from WordPress %s.',
        $metadata['wordpress_version'], $themeSlug, $wordPressVersion,
      ));
    }

    $requested = self::slugs($plugins);
    if ($metadata['plugins'] !== $requested) {
      throw new \RuntimeException(sprintf(
        'Playground activated plugins [%s] for theme %s, and the conversion named [%s].',
        implode(', ', $metadata['plugins']), $themeSlug, implode(', ', $requested),
      ));
    }

    return new Snapshot(
      $metadata['slug'],
      $metadata['name'],
      $metadata['version'],
      $metadata['wordpress_version'],
      $directory . '/out',
    );
  }

  /**
   * Builds a Playground failure message with its exit code and stderr tail.
   *
   * A PHP fatal error can print a long stack trace to stderr; only its end
   * usually names the failing line, so the rest of it is dropped here. The
   * PHP error log tail follows when PHP logged anything.
   */
  private static function failureMessage(string $themeSlug, int $exitCode, string $stdout, string $stderr, string $phpLog): string {
    $message = sprintf(
      "WordPress Playground failed for theme %s (exit code %d):\n%s\n=== Stderr (last 20 lines) ===\n%s",
      $themeSlug,
      $exitCode,
      $stdout,
      trim($stderr) === '' ? '(empty)' : self::tail($stderr),
    );
    return trim($phpLog) === '' ? $message : $message . "\n=== PHP error log (last 20 lines) ===\n" . self::tail($phpLog);
  }

  /**
   * Returns the last 20 lines of a text.
   */
  private static function tail(string $text): string {
    return implode("\n", array_slice(preg_split('/\R/', trim($text)), -20));
  }

  /**
   * Returns the sorted slugs of sources, as metadata.json lists plugins.
   *
   * @param \Drupal\wordpal_convert\WordPress\Source[] $sources
   *   The sources.
   *
   * @return string[]
   *   Their slugs.
   */
  private static function slugs(array $sources): array {
    $slugs = array_map(static fn (Source $source): string => $source->slug, $sources);
    sort($slugs);
    return $slugs;
  }

  /**
   * Returns a snapshot built earlier, or NULL when that directory holds none.
   *
   * Refuses a snapshot of another theme than $themeSlug, or one built from a
   * different WordPress version than $wordPressVersion: reusing it silently
   * would convert the wrong theme or validate it against the wrong release.
   */
  public function load(string $directory, string $themeSlug, string $wordPressVersion): ?Snapshot {
    $metadataFile = $directory . '/out/metadata.json';
    if (!is_file($metadataFile)) {
      return NULL;
    }
    $metadata = self::readMetadata($metadataFile);
    if ($metadata['slug'] !== $themeSlug) {
      throw new \RuntimeException(sprintf(
        'Cached snapshot at %s is of theme %s, not the requested theme %s. Run with --refresh.',
        $directory, $metadata['slug'], $themeSlug,
      ));
    }
    if ($metadata['wordpress_version'] !== $wordPressVersion) {
      throw new \RuntimeException(sprintf(
        'Cached snapshot of theme %s was built from WordPress %s, and this component set is generated from WordPress %s. Run with --refresh.',
        $metadata['slug'], $metadata['wordpress_version'], $wordPressVersion,
      ));
    }
    if (!is_file($directory . '/out/render-index.json')) {
      throw new \RuntimeException(sprintf('Cached snapshot of theme %s has no block render index. Run with --refresh.', $metadata['slug']));
    }
    if (!is_file($directory . '/out/variations.json')) {
      throw new \RuntimeException(sprintf('Cached snapshot of theme %s has no style variations. Run with --refresh.', $metadata['slug']));
    }
    if (!is_file($directory . '/out/legal.json')) {
      throw new \RuntimeException(sprintf('Cached snapshot of theme %s has no Legal files manifest. Run with --refresh.', $metadata['slug']));
    }
    return new Snapshot(
      $metadata['slug'],
      $metadata['name'],
      $metadata['version'],
      $metadata['wordpress_version'],
      $directory . '/out',
    );
  }

  /**
   * Decodes and validates a Playground metadata.json file.
   *
   * The Playground CLI subprocess writes this file, so a missing or empty
   * key fails closed rather than propagating a malformed snapshot.
   */
  private static function readMetadata(string $file): array {
    $metadata = json_decode((string) file_get_contents($file), TRUE, flags: JSON_THROW_ON_ERROR);
    foreach (['slug', 'name', 'version', 'wordpress_version'] as $key) {
      if (!is_array($metadata) || !isset($metadata[$key]) || !is_string($metadata[$key]) || $metadata[$key] === '') {
        throw new \RuntimeException("Playground metadata $file is missing a non-empty \"$key\".");
      }
    }
    if (!isset($metadata['plugins']) || !is_array($metadata['plugins']) || !array_is_list($metadata['plugins']) || array_filter($metadata['plugins'], 'is_string') !== $metadata['plugins']) {
      throw new \RuntimeException("Playground metadata $file has no \"plugins\" list. Run with --refresh.");
    }
    if (!isset($metadata['sources']) || !is_array($metadata['sources']) || $metadata['sources'] === []) {
      throw new \RuntimeException("Playground metadata $file has no \"sources\" map. Run with --refresh.");
    }
    foreach ($metadata['sources'] as $key => $sha256) {
      if (!is_string($key) || ($sha256 !== NULL && !is_string($sha256))) {
        throw new \RuntimeException("Playground metadata $file has an invalid \"sources\" map.");
      }
    }
    if (!isset($metadata['posts_per_page']) || !is_int($metadata['posts_per_page']) || $metadata['posts_per_page'] < 1) {
      throw new \RuntimeException("Playground metadata $file has no positive \"posts_per_page\". Run with --refresh.");
    }
    foreach (['license', 'license_uri', 'theme_uri', 'author', 'author_uri'] as $key) {
      if (!isset($metadata[$key]) || !is_string($metadata[$key])) {
        throw new \RuntimeException("Playground metadata $file has no \"$key\" header. Run with --refresh.");
      }
    }
    if (!isset($metadata['plugin_headers']) || !is_array($metadata['plugin_headers'])) {
      throw new \RuntimeException("Playground metadata $file has no \"plugin_headers\" map. Run with --refresh.");
    }
    foreach ($metadata['plugin_headers'] as $plugin => $headers) {
      $valid = preg_match(Source::SLUG, (string) $plugin) && is_array($headers);
      foreach (['name', 'version', 'plugin_uri', 'author', 'author_uri', 'license', 'license_uri'] as $key) {
        $valid = $valid && isset($headers[$key]) && is_string($headers[$key]);
      }
      if (!$valid) {
        throw new \RuntimeException("Playground metadata $file has an invalid \"plugin_headers\" entry for $plugin.");
      }
    }
    if (!isset($metadata['snapshot_date']) || !is_string($metadata['snapshot_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}\z/', $metadata['snapshot_date'])) {
      throw new \RuntimeException("Playground metadata $file has no \"snapshot_date\". Run with --refresh.");
    }
    if (!array_key_exists('screenshot', $metadata) || ($metadata['screenshot'] !== NULL && (!is_string($metadata['screenshot']) || !preg_match(Snapshot::SCREENSHOT, $metadata['screenshot'])))) {
      throw new \RuntimeException("Playground metadata $file has no valid \"screenshot\". Run with --refresh.");
    }
    if (!isset($metadata['part_tags']) || !is_array($metadata['part_tags'])) {
      throw new \RuntimeException("Playground metadata $file has no \"part_tags\" map. Run with --refresh.");
    }
    foreach ($metadata['part_tags'] as $slug => $tag) {
      // A plugin can register a template part area with a custom-element
      // tag, such as WooCommerce's "mini-cart", so hyphenated segments are
      // allowed alongside plain tag names like "header".
      if (!is_string($slug) || !is_string($tag) || !preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $tag)) {
        throw new \RuntimeException("Playground metadata $file has an invalid \"part_tags\" map.");
      }
    }
    return $metadata;
  }

  /**
   * Writes the blueprint and the input files Playground reads.
   */
  private function prepare(string $directory, Source $theme, array $plugins, string $wordPressVersion, string $fixturePath, array $fixtures, ?string $siteName, array $extraScripts): void {
    (new ContentFixtureLoader())->load($fixturePath);
    (new Filesystem())->remove([$directory . '/out', $directory . '/' . self::SOURCES, $directory . '/' . self::PHP_LOG]);
    foreach (['in', self::SOURCES, 'out'] as $subdirectory) {
      if (!is_dir($directory . '/' . $subdirectory)) {
        mkdir($directory . '/' . $subdirectory, 0777, TRUE);
      }
    }
    $steps = [];
    $sources = [];
    foreach ([$theme, ...$plugins] as $source) {
      if ($source->zip !== NULL) {
        copy($source->zip, $directory . '/' . self::SOURCES . '/' . $source->zipName());
      }
      $steps[] = $source->installStep(self::MOUNT . '/' . self::SOURCES);
      $sources[Source::key($source->type, $source->slug)] = $source->sha256;
    }
    ksort($sources);
    file_put_contents($directory . '/in/request.json', json_encode([
      'theme' => $theme->slug,
      'sources' => $sources,
      'templates' => TemplateResolver::HIERARCHY,
      'block_library_css' => Snapshot::BLOCK_LIBRARY_CSS,
      'default_variation' => Snapshot::DEFAULT_VARIATION,
      'handle_pattern' => Snapshot::HANDLE_PATTERN,
      'asset_extensions' => Snapshot::ASSET_EXTENSIONS,
      'safe_path' => PathValidator::SAFE_PATH,
      'plugin_assets' => Snapshot::PLUGIN_ASSETS,
      'plugin_style_prefix' => Snapshot::PLUGIN_STYLE_PREFIX,
      'legal_files' => Snapshot::LEGAL_FILES,
      'legal_prefix' => Snapshot::LEGAL_PREFIX,
      'plugin_legal_files' => Snapshot::PLUGIN_LEGAL_FILES,
      'site_name' => $siteName,
    ]));
    copy($fixturePath, $directory . '/in/fixture.json');
    (new Filesystem())->remove($directory . '/in/block-library');
    if ($fixtures !== []) {
      mkdir($directory . '/in/block-library', 0777, TRUE);
      foreach ($fixtures as $name => $markup) {
        file_put_contents($directory . '/in/block-library/' . Snapshot::fixtureFile($name), $markup);
      }
    }
    (new Filesystem())->mirror(__DIR__ . '/playground', $directory . '/' . self::SCRIPTS, options: ['delete' => TRUE]);
    foreach ($extraScripts as $script) {
      copy($script, $directory . '/' . self::SCRIPTS . '/' . basename($script));
    }

    // Playground's --wp CLI flag is not honored by run-blueprint once a
    // Blueprint declaration exists, so the exact release is pinned here
    // instead, as the direct wordpress.org zip: WordPress's own bundled
    // wp-includes/version.php then names the release Playground actually
    // ran, not "latest" or a nearby prefix match.
    // No landing page: Playground opens one in a browser.
    $requires = array_map(
      static fn (string $script): string => self::MOUNT . '/' . self::SCRIPTS . '/' . basename($script),
      [__DIR__ . '/playground/snapshot.php', ...$extraScripts],
    );
    $blueprint = [
      'preferredVersions' => [
        'php' => self::PHP_VERSION,
        'wp' => 'https://wordpress.org/wordpress-' . $wordPressVersion . '.zip',
      ],
      'steps' => [
        // A must-use plugin runs on every request, so the log also catches a
        // fatal error the theme raises on activation. Playground points
        // error_log at its own debug.log after must-use plugins load;
        // plugins_loaded fires after that and before any theme code.
        [
          'step' => 'writeFile',
          'path' => '/wordpress/wp-content/mu-plugins/wordpal-error-log.php',
          'data' => "<?php add_action('plugins_loaded', static fn () => ini_set('error_log', '" . self::MOUNT . '/' . self::PHP_LOG . "'));",
        ],
        ...$steps,
        [
          'step' => 'runPHP',
          'code' => "<?php require_once '/wordpress/wp-load.php'; "
          . implode(' ', array_map(static fn (string $path): string => "require '$path';", $requires)),
        ],
      ],
    ];
    file_put_contents($directory . '/blueprint.json', json_encode($blueprint, JSON_PRETTY_PRINT));
  }

}
