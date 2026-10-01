<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\WordPress\PlaygroundRunner;
use Drupal\wordpal_convert\WordPress\Source;
use Drupal\wordpal_convert\WordPress\SourceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the WordPress version check and the checks on cached snapshots.
 */
#[CoversClass(PlaygroundRunner::class)]
#[Group('wordpal')]
final class PlaygroundRunnerTest extends UnitTestCase {

  /**
   * Names the source and the version it requires.
   */
  #[DataProvider('newerSources')]
  public function testRequiredVersionFails(Source $source, string $message): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage($message);
    (new PlaygroundRunner())->checkRequiredVersion($source, '7.1.1');
  }

  /**
   * Supplies a theme and a plugin requiring a newer WordPress.
   */
  public static function newerSources(): array {
    return [
      'theme' => [
        new Source(SourceType::Theme, 'new-theme', '7.2', NULL, NULL, '', ''),
        'Theme new-theme requires WordPress 7.2, and this component set is generated from WordPress 7.1.1.',
      ],
      'plugin' => [
        new Source(SourceType::Plugin, 'new-plugin', '7.1.2', '/tmp/new-plugin.zip', 'abc', '', ''),
        'Plugin new-plugin requires WordPress 7.1.2, and this component set is generated from WordPress 7.1.1.',
      ],
    ];
  }

  /**
   * Accepts a source stating an older, equal or no required version.
   */
  public function testRequiredVersionPasses(): void {
    $runner = new PlaygroundRunner();
    foreach (['', '6.5', '7.1.1'] as $requires) {
      $runner->checkRequiredVersion(new Source(SourceType::Theme, 'theme', $requires, NULL, NULL, '', ''), '7.1.1');
    }
    $this->addToAssertionCount(3);
  }

  /**
   * Runs a per-run copy of the scripts and mounts no module directory.
   *
   * A named fixture lands in the block library input. An extra script a
   * caller names runs after snapshot.php, in the order given.
   */
  public function testBlueprintRunsCopiedScripts(): void {
    $directory = sys_get_temp_dir() . '/wordpal-runner-' . bin2hex(random_bytes(6));
    $extraScript = sys_get_temp_dir() . '/wordpal-extra-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($extraScript, '<?php // Test-only extra script.');
    $prepare = new \ReflectionMethod(PlaygroundRunner::class, 'prepare');
    $theme = new Source(SourceType::Theme, 'theme', '', NULL, NULL, '', '');
    $content = dirname(__DIR__, 3) . '/content/demo-content.json';
    $prepare->invoke(new PlaygroundRunner(), $directory, $theme, [], '7.1.1', $content, ['matrix-0001' => '<!-- wp:spacer /-->'], NULL, [$extraScript]);

    try {
      self::assertStringEqualsFile("$directory/in/block-library/matrix-0001.html", '<!-- wp:spacer /-->');
      $module = dirname(__DIR__, 3) . '/src/WordPress/playground';
      foreach (['snapshot.php', 'style-variations.php', 'theme-files.php'] as $script) {
        self::assertFileEquals("$module/$script", "$directory/in/scripts/$script");
      }
      self::assertFileEquals($extraScript, "$directory/in/scripts/" . basename($extraScript));
      $steps = json_decode((string) file_get_contents("$directory/blueprint.json"), TRUE)['steps'];
      $code = end($steps)['code'];
      self::assertStringContainsString(
        "require '/wordpal/in/scripts/snapshot.php'; require '/wordpal/in/scripts/" . basename($extraScript) . "';",
        $code,
      );
      self::assertSame([
        'step' => 'writeFile',
        'path' => '/wordpress/wp-content/mu-plugins/wordpal-error-log.php',
        'data' => "<?php add_action('plugins_loaded', static fn () => ini_set('error_log', '/wordpal/php-error.log'));",
      ], $steps[0]);
    }
    finally {
      (new Filesystem())->remove($directory);
      unlink($extraScript);
    }
  }

  /**
   * Reports the process's exit code and only the tail of a long stderr.
   *
   * A PHP fatal error can print a long stack trace to stderr; only its end
   * usually names the failing line, so the rest is not worth keeping.
   */
  public function testFailureMessageReportsExitCodeAndStderrTail(): void {
    $stderr = implode("\n", array_map(static fn (int $n): string => "line $n", range(1, 30)));
    $method = new \ReflectionMethod(PlaygroundRunner::class, 'failureMessage');
    $message = $method->invoke(NULL, 'theme', 255, 'stdout body', $stderr, '');

    self::assertStringContainsString('exit code 255', $message);
    self::assertStringContainsString('stdout body', $message);
    self::assertStringContainsString('line 11', $message);
    self::assertStringContainsString('line 30', $message);
    self::assertStringNotContainsString('line 5', $message);
  }

  /**
   * Reports stderr as empty rather than a blank tail section.
   */
  public function testFailureMessageReportsEmptyStderr(): void {
    $method = new \ReflectionMethod(PlaygroundRunner::class, 'failureMessage');
    $message = $method->invoke(NULL, 'theme', 255, 'stdout body', '', '');

    self::assertStringContainsString('(empty)', $message);
    self::assertStringNotContainsString('PHP error log', $message);
  }

  /**
   * Reports the tail of PHP's error log, which names the fatal error.
   *
   * WordPress answers a fatal error with a generic critical error page, so
   * only the log names the failing file and line.
   */
  public function testFailureMessageReportsPhpLogTail(): void {
    $log = implode("\n", array_map(static fn (int $n): string => "PHP error $n", range(1, 30)));
    $method = new \ReflectionMethod(PlaygroundRunner::class, 'failureMessage');
    $message = $method->invoke(NULL, 'theme', 1, 'critical error page', '', $log);

    self::assertStringContainsString("=== PHP error log (last 20 lines) ===\nPHP error 11\n", $message);
    self::assertStringContainsString('PHP error 30', $message);
    self::assertStringNotContainsString('PHP error 5', $message);
  }

  /**
   * Rejects a cached snapshot built from a different WordPress version.
   */
  public function testLoadRefusesMismatchedWordPressVersion(): void {
    $directory = sys_get_temp_dir() . '/wordpal-load-' . bin2hex(random_bytes(6));
    mkdir($directory . '/out', 0777, TRUE);
    file_put_contents($directory . '/out/metadata.json', json_encode([
      'slug' => 'new-theme',
      'name' => 'New Theme',
      'version' => '1.0',
      'wordpress_version' => '7.0.0',
      'plugins' => [],
      'sources' => ['theme:new-theme' => NULL],
      'posts_per_page' => 10,
      'part_tags' => ['header' => 'header'],
      'license' => 'GPLv2 or later',
      'license_uri' => '',
      'theme_uri' => '',
      'author' => '',
      'author_uri' => '',
      'screenshot' => NULL,
      'plugin_headers' => [],
      'snapshot_date' => '2026-09-30',
    ], JSON_THROW_ON_ERROR));

    try {
      $runner = new PlaygroundRunner();
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('Cached snapshot of theme new-theme was built from WordPress 7.0.0, and this component set is generated from WordPress 7.1.1.');
      $runner->load($directory, 'new-theme', '7.1.1');
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Rejects a cached snapshot of another theme.
   */
  public function testLoadRefusesOtherTheme(): void {
    $directory = sys_get_temp_dir() . '/wordpal-load-' . bin2hex(random_bytes(6));
    mkdir($directory . '/out', 0777, TRUE);
    file_put_contents($directory . '/out/metadata.json', json_encode([
      'slug' => 'other-theme',
      'name' => 'Other Theme',
      'version' => '1.0',
      'wordpress_version' => '7.1.1',
      'plugins' => [],
      'sources' => ['theme:new-theme' => NULL],
      'posts_per_page' => 10,
      'part_tags' => ['header' => 'header'],
      'license' => 'GPLv2 or later',
      'license_uri' => '',
      'theme_uri' => '',
      'author' => '',
      'author_uri' => '',
      'screenshot' => NULL,
      'plugin_headers' => [],
      'snapshot_date' => '2026-09-30',
    ], JSON_THROW_ON_ERROR));

    try {
      $runner = new PlaygroundRunner();
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage("Cached snapshot at $directory is of theme other-theme, not the requested theme new-theme.");
      $runner->load($directory, 'new-theme', '7.1.1');
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Rejects a cached snapshot from before style variations were exported.
   */
  public function testLoadRefusesSnapshotWithoutVariations(): void {
    $directory = sys_get_temp_dir() . '/wordpal-load-' . bin2hex(random_bytes(6));
    mkdir($directory . '/out', 0777, TRUE);
    file_put_contents($directory . '/out/metadata.json', json_encode([
      'slug' => 'new-theme',
      'name' => 'New Theme',
      'version' => '1.0',
      'wordpress_version' => '7.1.1',
      'plugins' => [],
      'sources' => ['theme:new-theme' => NULL],
      'posts_per_page' => 10,
      'part_tags' => ['header' => 'header'],
      'license' => 'GPLv2 or later',
      'license_uri' => '',
      'theme_uri' => '',
      'author' => '',
      'author_uri' => '',
      'screenshot' => NULL,
      'plugin_headers' => [],
      'snapshot_date' => '2026-09-30',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/out/render-index.json', '{}');

    try {
      $runner = new PlaygroundRunner();
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('Cached snapshot of theme new-theme has no style variations. Run with --refresh.');
      $runner->load($directory, 'new-theme', '7.1.1');
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Rejects a cached snapshot from before Legal files were exported.
   */
  public function testLoadRefusesSnapshotWithoutLegalFiles(): void {
    $directory = sys_get_temp_dir() . '/wordpal-load-' . bin2hex(random_bytes(6));
    mkdir($directory . '/out', 0777, TRUE);
    file_put_contents($directory . '/out/metadata.json', json_encode([
      'slug' => 'new-theme',
      'name' => 'New Theme',
      'version' => '1.0',
      'wordpress_version' => '7.1.1',
      'plugins' => [],
      'sources' => ['theme:new-theme' => NULL],
      'posts_per_page' => 10,
      'part_tags' => ['header' => 'header'],
      'license' => 'GPLv2 or later',
      'license_uri' => '',
      'theme_uri' => '',
      'author' => '',
      'author_uri' => '',
      'screenshot' => NULL,
      'plugin_headers' => [],
      'snapshot_date' => '2026-09-30',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/out/render-index.json', '{}');
    file_put_contents($directory . '/out/variations.json', '{}');

    try {
      $runner = new PlaygroundRunner();
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('Cached snapshot of theme new-theme has no Legal files manifest. Run with --refresh.');
      $runner->load($directory, 'new-theme', '7.1.1');
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Rejects metadata that fails to decode as JSON.
   */
  public function testLoadRejectsMalformedJson(): void {
    $directory = sys_get_temp_dir() . '/wordpal-metadata-' . bin2hex(random_bytes(6));
    mkdir($directory . '/out', 0777, TRUE);
    file_put_contents($directory . '/out/metadata.json', '{');

    try {
      $this->expectException(\JsonException::class);
      (new PlaygroundRunner())->load($directory, 'theme', '7.1.1');
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Rejects metadata with a missing, empty, or non-string required key.
   */
  #[DataProvider('invalidMetadata')]
  public function testLoadRejectsInvalidMetadata(array $metadata, string $badKey): void {
    $directory = sys_get_temp_dir() . '/wordpal-metadata-' . bin2hex(random_bytes(6));
    mkdir($directory . '/out', 0777, TRUE);
    file_put_contents($directory . '/out/metadata.json', json_encode($metadata, JSON_THROW_ON_ERROR));

    try {
      (new PlaygroundRunner())->load($directory, 'theme', '7.1.1');
      self::fail("Metadata with an invalid \"$badKey\" should fail.");
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString("\"$badKey\"", $exception->getMessage());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Supplies metadata missing a required key in three ways.
   */
  public static function invalidMetadata(): array {
    $complete = [
      'slug' => 'theme',
      'name' => 'Theme',
      'version' => '1.0',
      'wordpress_version' => '7.1.1',
      'plugins' => [],
      'sources' => ['theme:theme' => NULL],
      'posts_per_page' => 10,
      'part_tags' => ['header' => 'header'],
      'license' => 'GPLv2 or later',
      'license_uri' => '',
      'theme_uri' => '',
      'author' => '',
      'author_uri' => '',
      'screenshot' => NULL,
      'plugin_headers' => [],
      'snapshot_date' => '2026-09-30',
    ];
    $missingKey = $complete;
    unset($missingKey['name']);
    $emptyString = $complete;
    $emptyString['version'] = '';
    $nonString = $complete;
    $nonString['wordpress_version'] = 7.1;
    $noPlugins = $complete;
    unset($noPlugins['plugins']);
    $noSources = $complete;
    unset($noSources['sources']);
    $noPerPage = $complete;
    $noPerPage['posts_per_page'] = 0;
    $badTag = $complete;
    $badTag['part_tags'] = ['header' => 'header onclick'];
    $noLicense = $complete;
    unset($noLicense['license']);
    $badDate = $complete;
    $badDate['snapshot_date'] = '30/09/2026';
    $badScreenshot = $complete;
    $badScreenshot['screenshot'] = '../screenshot.png';
    $noScreenshot = $complete;
    unset($noScreenshot['screenshot']);
    $pluginHeaders = [
      'name' => 'Acme',
      'version' => '1.0',
      'plugin_uri' => '',
      'author' => '',
      'author_uri' => '',
      'license' => '',
      'license_uri' => '',
    ];
    $noPluginHeaders = $complete;
    unset($noPluginHeaders['plugin_headers']);
    $badPluginDirectory = $complete;
    $badPluginDirectory['plugin_headers'] = ['../acme' => $pluginHeaders];
    $noPluginLicense = $complete;
    $noPluginLicense['plugin_headers'] = ['acme' => array_diff_key($pluginHeaders, ['license' => TRUE])];
    return [
      'no plugin headers' => [$noPluginHeaders, 'plugin_headers'],
      'invalid plugin directory' => [$badPluginDirectory, 'plugin_headers'],
      'plugin without a license header' => [$noPluginLicense, 'plugin_headers'],
      'no license header' => [$noLicense, 'license'],
      'invalid snapshot date' => [$badDate, 'snapshot_date'],
      'invalid screenshot' => [$badScreenshot, 'screenshot'],
      'no screenshot key' => [$noScreenshot, 'screenshot'],
      'no posts per page' => [$noPerPage, 'posts_per_page'],
      'invalid part tag' => [$badTag, 'part_tags'],
      'no plugins' => [$noPlugins, 'plugins'],
      'no sources' => [$noSources, 'sources'],
      'missing key' => [$missingKey, 'name'],
      'empty string' => [$emptyString, 'version'],
      'non-string value' => [$nonString, 'wordpress_version'],
    ];
  }

  /**
   * Accepts a part tag with a hyphen, such as a plugin's custom element.
   *
   * WooCommerce registers the "mini-cart" template part area with an
   * `area_tag` of "mini-cart", a valid custom-element tag name. A hyphen is
   * not malformed data the snapshot boundary should reject.
   */
  public function testLoadAcceptsHyphenatedPartTag(): void {
    $directory = sys_get_temp_dir() . '/wordpal-load-' . bin2hex(random_bytes(6));
    mkdir($directory . '/out', 0777, TRUE);
    file_put_contents($directory . '/out/metadata.json', json_encode([
      'slug' => 'new-theme',
      'name' => 'New Theme',
      'version' => '1.0',
      'wordpress_version' => '7.1.1',
      'plugins' => ['woocommerce'],
      'sources' => ['theme:new-theme' => NULL],
      'posts_per_page' => 10,
      'part_tags' => ['mini-cart' => 'mini-cart'],
      'license' => 'GPLv2 or later',
      'license_uri' => '',
      'theme_uri' => '',
      'author' => '',
      'author_uri' => '',
      'screenshot' => NULL,
      'plugin_headers' => [],
      'snapshot_date' => '2026-09-30',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/out/render-index.json', '{}');
    file_put_contents($directory . '/out/variations.json', '{}');
    file_put_contents($directory . '/out/legal.json', '[]');

    try {
      $snapshot = (new PlaygroundRunner())->load($directory, 'new-theme', '7.1.1');
      self::assertNotNull($snapshot);
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

}
