<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\KernelTests\KernelTestBase;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal_convert\WordPress\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the generated theme's script libraries.
 *
 * The theme is generated into the Drupal root's themes directory, where
 * extension discovery finds it, and removed after the test.
 */
#[CoversClass(ThemeGenerator::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ThemeScriptsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'comment', 'views',
    'link', 'menu_link_content', 'wordpal', 'wordpal_convert',
  ];

  /**
   * The snapshot directory.
   */
  private string $snapshotDirectory;

  /**
   * The generated theme's machine name.
   */
  private string $themeId;

  /**
   * Generates and installs a theme with three scripts, one of them skipped.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->snapshotDirectory = sys_get_temp_dir() . '/wordpal-scripts-' . bin2hex(random_bytes(6));
    $files = [
      'styles/global.css' => '',
      'scripts/theme-wow.js' => 'window.wow = true;',
      'scripts/theme-main.js' => 'jQuery(function () {});',
      'scripts/theme-i18n.js' => 'wp.i18n.__("x");',
      'styles.json' => '[]',
      'assets.json' => '[]',
      'plugin-styles.json' => '{}',
      'block-styles.json' => '{}',
      'scripts.json' => json_encode([
        [
          'handle' => 'wow',
          'path' => 'scripts/theme-wow.js',
          'dependencies' => ['jquery'],
          'footer' => FALSE,
          'strategy' => NULL,
        ],
        [
          'handle' => 'main',
          'path' => 'scripts/theme-main.js',
          'dependencies' => ['wow', 'jquery'],
          'footer' => TRUE,
          'strategy' => 'defer',
        ],
        [
          'handle' => 'i18n',
          'path' => 'scripts/theme-i18n.js',
          'dependencies' => ['wp-i18n'],
          'footer' => TRUE,
          'strategy' => NULL,
        ],
      ]),
      'presets.json' => json_encode(array_fill_keys(['color', 'gradient', 'font-size', 'font-family', 'spacing'], [])),
      'variations.json' => json_encode([['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css']]),
    ];
    (new Filesystem())->mkdir([$this->snapshotDirectory . '/styles', $this->snapshotDirectory . '/scripts']);
    foreach ($files as $path => $contents) {
      file_put_contents("$this->snapshotDirectory/$path", $contents);
    }
    $slug = 'wordpal-scripts-' . bin2hex(random_bytes(4));
    file_put_contents("$this->snapshotDirectory/legal.json", '[]');
    file_put_contents("$this->snapshotDirectory/metadata.json", json_encode([
      'sources' => ["theme:$slug" => NULL],
      'license' => '',
      'license_uri' => '',
      'theme_uri' => '',
      'author' => '',
      'author_uri' => '',
      'screenshot' => NULL,
      'plugin_headers' => [],
      'snapshot_date' => '2026-09-30',
    ]));
    $snapshot = new Snapshot($slug, 'Scripts test', '1.0', '7.1.1', $this->snapshotDirectory);
    $this->themeId = (new ThemeGenerator($this->root, $this->container->get('module_handler')))->generate($snapshot, []);

    // Extension discovery keeps each directory scan for the whole process.
    (new \ReflectionProperty(ExtensionDiscovery::class, 'files'))->setValue(NULL, []);
    $this->container->get('theme_installer')->install([$this->themeId]);
    $this->config('system.theme')->set('default', $this->themeId)->save();
    $this->config('system.performance')->set('js.preprocess', FALSE)->save();
  }

  /**
   * Removes the generated theme and the snapshot.
   */
  protected function tearDown(): void {
    (new Filesystem())->remove([$this->snapshotDirectory, "$this->root/themes/custom/$this->themeId"]);
    parent::tearDown();
  }

  /**
   * Tests every page loads the exported scripts after core's jQuery.
   */
  public function testPagesLoadExportedScripts(): void {
    $info = $this->container->get('extension.list.theme')->getExtensionInfo($this->themeId);
    self::assertSame([
      "$this->themeId/global",
      'wordpal/drupal_ui',
      "$this->themeId/script.wow",
      "$this->themeId/script.main",
    ], $info['libraries']);

    $discovery = $this->container->get('library.discovery');
    $main = $discovery->getLibraryByName($this->themeId, 'script.main');
    self::assertSame(["$this->themeId/script.wow", 'core/jquery'], $main['dependencies']);
    self::assertTrue($main['js'][0]['attributes']['defer']);
    self::assertFalse($main['header']);
    self::assertTrue($discovery->getLibraryByName($this->themeId, 'script.wow')['header']);
    self::assertFalse($discovery->getLibraryByName($this->themeId, 'script.i18n'));

    $assets = AttachedAssets::createFromRenderArray(['#attached' => ['library' => $info['libraries']]]);
    [$header, $footer] = $this->container->get('asset.resolver')->getJsAssets($assets, FALSE);
    $theme = $this->container->get('extension.list.theme')->getPath($this->themeId);
    $scripts = array_keys($header + $footer);
    self::assertLessThan(array_search("$theme/scripts/theme-wow.js", $scripts, TRUE), array_search('core/assets/vendor/jquery/jquery.min.js', $scripts, TRUE));
    self::assertLessThan(array_search("$theme/scripts/theme-main.js", $scripts, TRUE), array_search("$theme/scripts/theme-wow.js", $scripts, TRUE));
    self::assertCount(1, array_filter($scripts, static fn (string $path): bool => str_contains($path, 'jquery.min.js')));
    self::assertNotContains("$theme/scripts/theme-i18n.js", $scripts);
  }

}
