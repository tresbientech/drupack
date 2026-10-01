<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\WordPress\Source;
use Drupal\wordpal_convert\WordPress\SourceType;
use Drupal\wordpal_convert\WordPress\ThemeSource;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests resolving slugs, zips and URLs into Playground sources.
 */
#[CoversClass(ThemeSource::class)]
#[CoversClass(Source::class)]
#[Group('wordpal')]
final class ThemeSourceTest extends UnitTestCase {

  /**
   * Scratch directory for zips and downloads.
   */
  private string $directory;

  /**
   * Requests the mock client received.
   */
  private array $requests = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = sys_get_temp_dir() . '/wordpal-source-' . bin2hex(random_bytes(6));
    mkdir($this->directory, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    (new Filesystem())->remove($this->directory);
    parent::tearDown();
  }

  /**
   * A slug reads its version from the WordPress.org API of its type.
   */
  #[DataProvider('types')]
  public function testSlugReadsWordPressOrg(SourceType $type, string $api, array $step): void {
    $source = $this->themeSource([new Response(200, [], json_encode(['name' => 'Acme', 'requires' => '6.7'], JSON_THROW_ON_ERROR))])
      ->resolve('acme', $type, $this->directory);

    self::assertSame('acme', $source->slug);
    self::assertSame('6.7', $source->requires);
    self::assertNull($source->zip);
    self::assertSame($api, rawurldecode((string) $this->requests[0]['request']->getUri()));
    self::assertSame($step, $source->installStep('/sources'));
  }

  /**
   * Supplies the theme and plugin API and install step of the slug "acme".
   */
  public static function types(): array {
    return [
      'theme' => [
        SourceType::Theme,
        'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]=acme',
        [
          'step' => 'installTheme',
          'themeData' => ['resource' => 'wordpress.org/themes', 'slug' => 'acme'],
          'options' => ['activate' => TRUE],
        ],
      ],
      'plugin' => [
        SourceType::Plugin,
        'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=acme',
        [
          'step' => 'installPlugin',
          'pluginData' => ['resource' => 'wordpress.org/plugins', 'slug' => 'acme'],
          'options' => ['activate' => TRUE],
        ],
      ],
    ];
  }

  /**
   * An unknown slug fails.
   */
  public function testUnknownSlugFails(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('WordPress.org has no plugin named acme.');
    $this->themeSource([new Response(200, [], '{"error":"Plugin not found."}')])
      ->resolve('acme', SourceType::Plugin, $this->directory);
  }

  /**
   * A slug that could name a path fails before any request.
   */
  public function testInvalidSlugFails(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Invalid WordPress theme slug: ../acme');
    $this->themeSource([])->resolve('../acme', SourceType::Theme, $this->directory);
  }

  /**
   * A theme zip reads its slug from its directory and its version header.
   */
  public function testThemeZip(): void {
    $zip = $this->zip('theme.zip', [
      'acme/style.css' => "/*\nTheme Name: Acme\nRequires at least: 6.5\nVersion: 1.0\n*/",
      'acme/templates/index.html' => '<!-- wp:paragraph /-->',
    ]);

    $source = $this->themeSource([])->resolve($zip, SourceType::Theme, $this->directory);

    self::assertSame('acme', $source->slug);
    self::assertSame('6.5', $source->requires);
    self::assertSame($zip, $source->zip);
    self::assertSame('', $source->license);
    self::assertSame('', $source->licenseUri);
    self::assertSame(
      [
        'step' => 'installTheme',
        'themeData' => ['resource' => 'vfs', 'path' => '/sources/theme-acme.zip'],
        'options' => ['activate' => TRUE],
      ],
      $source->installStep('/sources'),
    );
  }

  /**
   * A theme zip reads License and License URI apart.
   */
  public function testThemeZipReadsLicense(): void {
    $zip = $this->zip('theme.zip', [
      'acme/style.css' => "/*\nTheme Name: Acme\nLicense URI: https://example.com/terms\nLicense: Acme Commercial\n*/",
      'acme/templates/index.html' => '',
    ]);

    $source = $this->themeSource([])->resolve($zip, SourceType::Theme, $this->directory);

    self::assertSame('Acme Commercial', $source->license);
    self::assertSame('https://example.com/terms', $source->licenseUri);
  }

  /**
   * A zip without a block theme's index Template fails.
   */
  public function testClassicThemeZipFails(): void {
    $zip = $this->zip('classic.zip', [
      'classic/style.css' => "/*\nTheme Name: Classic\n*/",
      'classic/index.php' => '<?php',
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("$zip holds no block theme: classic/templates/index.html is missing.");
    $this->themeSource([])->resolve($zip, SourceType::Theme, $this->directory);
  }

  /**
   * A plugin zip reads its version from the file naming the plugin.
   */
  public function testPluginZipReadsMainFile(): void {
    $zip = $this->zip('plugin.zip', [
      'acme-forms/uninstall.php' => "<?php\n// Requires at least: 1.0\n",
      'acme-forms/acme-forms.php' => "<?php\n/**\n * Plugin Name: Acme Forms\n * Requires at least: 6.6\n * License: GPLv2\n */\n",
      'acme-forms/includes/other.php' => "<?php\n/**\n * Plugin Name: Nested\n * Requires at least: 9.0\n */\n",
    ]);

    $source = $this->themeSource([])->resolve($zip, SourceType::Plugin, $this->directory);

    self::assertSame('acme-forms', $source->slug);
    self::assertSame('6.6', $source->requires);
    self::assertSame('', $source->license);
    self::assertSame('pluginData', array_keys($source->installStep('/sources'))[1]);
  }

  /**
   * A plugin zip with no file naming a plugin fails.
   */
  public function testPluginZipWithoutMainFileFails(): void {
    $zip = $this->zip('plugin.zip', ['acme/readme.txt' => 'Plugin Name: Acme']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("$zip holds no plugin: no PHP file in acme/ names a plugin.");
    $this->themeSource([])->resolve($zip, SourceType::Plugin, $this->directory);
  }

  /**
   * Header lines are read the way WordPress reads them.
   */
  #[DataProvider('headers')]
  public function testVersionHeaders(string $style, string $expected): void {
    $zip = $this->zip('theme.zip', [
      'acme/style.css' => $style,
      'acme/templates/index.html' => '',
    ]);

    self::assertSame($expected, $this->themeSource([])->resolve($zip, SourceType::Theme, $this->directory)->requires);
  }

  /**
   * Supplies stylesheet headers and the version each states.
   */
  public static function headers(): array {
    return [
      'plain' => ["/*\nRequires at least: 6.7\n*/", '6.7'],
      'starred' => ["/**\n * Requires at least: 6.4.2\n */", '6.4.2'],
      'closing comment on the line' => ["/* Requires at least: 6.3 */", '6.3'],
      'lowercase name' => ["/*\nrequires at least: 6.2\n*/", '6.2'],
      'absent' => ["/*\nTheme Name: Acme\n*/", ''],
      'empty' => ["/*\nRequires at least:\n*/", ''],
    ];
  }

  /**
   * A version header that is not a version fails.
   */
  public function testUnreadableVersionFails(): void {
    $zip = $this->zip('theme.zip', [
      'acme/style.css' => "/*\nRequires at least: latest\n*/",
      'acme/templates/index.html' => '',
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("$zip states an unreadable required WordPress version: latest");
    $this->themeSource([])->resolve($zip, SourceType::Theme, $this->directory);
  }

  /**
   * A zip must hold exactly one directory with a slug name.
   */
  #[DataProvider('badLayouts')]
  public function testZipLayoutFails(array $files, string $message): void {
    $zip = $this->zip('theme.zip', $files);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("$zip $message");
    $this->themeSource([])->resolve($zip, SourceType::Theme, $this->directory);
  }

  /**
   * Supplies zip layouts that name no single slug directory.
   */
  public static function badLayouts(): array {
    return [
      'files at the root' => [
        ['style.css' => '', 'templates/index.html' => ''],
        'must hold one top-level directory.',
      ],
      'two directories' => [
        ['one/style.css' => '', 'two/style.css' => ''],
        'must hold one top-level directory.',
      ],
      'not a slug' => [
        ['Acme Theme/style.css' => '', 'Acme Theme/templates/index.html' => ''],
        'holds the directory "Acme Theme", which is not a valid slug.',
      ],
    ];
  }

  /**
   * A missing zip path fails.
   */
  public function testMissingZipFails(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("No zip file at $this->directory/missing.zip.");
    $this->themeSource([])->resolve("$this->directory/missing.zip", SourceType::Theme, $this->directory);
  }

  /**
   * An https URL is downloaded and read as a zip.
   */
  public function testUrlDownloadsZip(): void {
    $zip = $this->zip('remote.zip', [
      'acme/style.css' => "/*\nRequires at least: 6.6\n*/",
      'acme/templates/index.html' => '',
    ]);
    $downloads = $this->directory . '/downloads';

    $source = $this->themeSource([new Response(200, [], (string) file_get_contents($zip))])
      ->resolve('https://example.com/acme.zip', SourceType::Theme, $downloads);

    self::assertSame('https://example.com/acme.zip', (string) $this->requests[0]['request']->getUri());
    self::assertSame('acme', $source->slug);
    self::assertSame('6.6', $source->requires);
    self::assertStringStartsWith($downloads . '/', (string) $source->zip);
    self::assertFileEquals($zip, (string) $source->zip);
  }

  /**
   * A plain http URL fails before any request.
   */
  public function testHttpUrlFails(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Only https URLs are accepted: http://example.com/acme.zip');
    $this->themeSource([])->resolve('http://example.com/acme.zip', SourceType::Theme, $this->directory);
  }

  /**
   * A redirect from https to http is refused.
   */
  public function testRedirectToHttpFails(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Could not download https://example.com/acme.zip.');
    $this->themeSource([new Response(302, ['Location' => 'http://example.com/acme.zip'])])
      ->resolve('https://example.com/acme.zip', SourceType::Theme, $this->directory);
  }

  /**
   * A failed download leaves no file behind.
   *
   * The server answers with an error body, which Guzzle writes to the sink
   * before it throws.
   */
  public function testFailedDownloadLeavesNoFile(): void {
    $downloads = $this->directory . '/downloads';
    try {
      $this->themeSource([new Response(500, [], 'Server error')])
        ->resolve('https://example.com/acme.zip', SourceType::Theme, $downloads);
      self::fail('A server error must fail the download.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('Could not download https://example.com/acme.zip.', $exception->getMessage());
    }
    self::assertSame([], array_diff(scandir($downloads), ['.', '..']));
    self::assertNull($this->themeSource([])->identify('https://example.com/acme.zip', SourceType::Theme, $downloads));
  }

  /**
   * A zip source carries the hash of its contents.
   */
  public function testZipCarriesHash(): void {
    $zip = $this->zip('theme.zip', ['acme/style.css' => '', 'acme/templates/index.html' => '']);

    self::assertSame(hash_file('sha256', $zip), $this->themeSource([])->resolve($zip, SourceType::Theme, $this->directory)->sha256);
  }

  /**
   * Identifying a reference reads no network.
   */
  public function testIdentifyStaysOffline(): void {
    $zip = $this->zip('remote.zip', ['acme/style.css' => '', 'acme/templates/index.html' => '']);
    $url = 'https://example.com/acme.zip';
    $downloads = $this->directory . '/downloads';
    $themeSource = $this->themeSource([]);

    self::assertSame(['acme-forms', NULL], $themeSource->identify('acme-forms', SourceType::Plugin, $downloads));
    self::assertSame(['acme', hash_file('sha256', $zip)], $themeSource->identify($zip, SourceType::Theme, $downloads));
    self::assertNull($themeSource->identify($url, SourceType::Theme, $downloads));

    $source = $this->themeSource([new Response(200, [], (string) file_get_contents($zip))])->resolve($url, SourceType::Theme, $downloads);
    self::assertSame(['acme', $source->sha256], $themeSource->identify($url, SourceType::Theme, $downloads));
    self::assertCount(1, $this->requests, 'Only the download reads the network.');
  }

  /**
   * Returns a resolver whose HTTP client answers with $responses.
   */
  private function themeSource(array $responses): ThemeSource {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->requests));
    return new ThemeSource(new Client(['handler' => $stack]));
  }

  /**
   * Writes a zip holding $files and returns its path.
   */
  private function zip(string $name, array $files): string {
    $path = $this->directory . '/' . $name;
    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    foreach ($files as $file => $contents) {
      $zip->addFromString($file, $contents);
    }
    $zip->close();
    return $path;
  }

}
