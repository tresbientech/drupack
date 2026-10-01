<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal_convert\WordPress\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests generated Drupal theme files.
 */
#[CoversClass(ThemeGenerator::class)]
#[Group('wordpal')]
final class ThemeGeneratorTest extends UnitTestCase {

  /**
   * Directory used by one test.
   */
  private string $temporaryDirectory;

  /**
   * Creates isolated Drupal root and snapshot directories.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->temporaryDirectory = sys_get_temp_dir() . '/wordpal-theme-' . bin2hex(random_bytes(6));
    mkdir($this->temporaryDirectory . '/snapshot/styles', 0777, TRUE);
    mkdir($this->temporaryDirectory . '/snapshot/assets/fonts', 0777, TRUE);
    mkdir($this->temporaryDirectory . '/snapshot/assets/images', 0777, TRUE);
    $presets = ['color' => [], 'gradient' => [], 'font-size' => [], 'font-family' => [], 'spacing' => []];
    $presets['color'][] = ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'];
    file_put_contents($this->temporaryDirectory . '/snapshot/presets.json', json_encode($presets, JSON_THROW_ON_ERROR));
    file_put_contents($this->temporaryDirectory . '/snapshot/plugin-styles.json', '{}');
    file_put_contents($this->temporaryDirectory . '/snapshot/block-styles.json', '{}');
    file_put_contents($this->temporaryDirectory . '/snapshot/scripts.json', '[]');
    file_put_contents($this->temporaryDirectory . '/snapshot/legal.json', '[]');
    $this->writeMetadata([]);
  }

  /**
   * Writes the snapshot's metadata.json: a WordPress.org theme by default.
   */
  private function writeMetadata(array $overrides): void {
    $metadata = $overrides + [
      'sources' => ['theme:twenty-twenty-four' => NULL],
      'license' => 'GNU General Public License v2 or later',
      'license_uri' => 'http://www.gnu.org/licenses/gpl-2.0.html',
      'theme_uri' => 'https://wordpress.org/themes/twenty-twenty-four',
      'author' => 'the WordPress team',
      'author_uri' => 'https://wordpress.org',
      'screenshot' => NULL,
      'plugin_headers' => [],
      'snapshot_date' => '2026-09-30',
    ];
    file_put_contents($this->temporaryDirectory . '/snapshot/metadata.json', json_encode($metadata, JSON_THROW_ON_ERROR));
  }

  /**
   * Returns a plugin's headers, WooCommerce's by default.
   */
  private static function pluginHeaders(array $overrides): array {
    return $overrides + [
      'name' => 'WooCommerce',
      'version' => '9.3.1',
      'plugin_uri' => 'https://woocommerce.com/',
      'author' => 'Automattic',
      'author_uri' => 'https://woocommerce.com',
      'license' => 'GPLv3',
      'license_uri' => 'https://www.gnu.org/licenses/gpl-3.0.html',
    ];
  }

  /**
   * Writes a minimal default-variation snapshot and returns it.
   */
  private function minimalSnapshot(): Snapshot {
    $this->writeVariations([['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css']]);
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/global.css', ':root {}');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles.json', '[]');
    file_put_contents($this->temporaryDirectory . '/snapshot/assets.json', '[]');
    return new Snapshot('twenty-twenty-four', 'Twenty Twenty-Four', '1.6', '7.1.1', $this->temporaryDirectory . '/snapshot');
  }

  /**
   * Removes the isolated directories.
   */
  protected function tearDown(): void {
    if (is_dir($this->temporaryDirectory)) {
      $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
      rmdir($this->temporaryDirectory);
    }
    parent::tearDown();
  }

  /**
   * Writes the snapshot's variations.json.
   */
  private function writeVariations(array $variations): void {
    file_put_contents($this->temporaryDirectory . '/snapshot/variations.json', json_encode($variations, JSON_THROW_ON_ERROR));
  }

  /**
   * Returns a generator whose module handler applies no alterations.
   */
  private function generator(string $root): ThemeGenerator {
    return new ThemeGenerator($root, $this->createMock(ModuleHandlerInterface::class));
  }

  /**
   * Tests the generated theme requires the core version the module requires.
   */
  public function testGeneratedThemeRequiresTheModulesCoreVersion(): void {
    $this->writeVariations([['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css']]);
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/global.css', ':root {}');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles.json', '[]');
    file_put_contents($this->temporaryDirectory . '/snapshot/assets.json', '[]');
    $snapshot = new Snapshot('twenty-twenty-four', 'Twenty Twenty-Four', '1.6', '7.1.1', $this->temporaryDirectory . '/snapshot');
    $themeId = $this->generator($this->temporaryDirectory)->generate($snapshot, []);

    $module = Yaml::parseFile(__DIR__ . '/../../../../../wordpal.info.yml');
    $theme = Yaml::parseFile($this->temporaryDirectory . "/themes/custom/$themeId/$themeId.info.yml");
    self::assertSame('^11.4', $module['core_version_requirement']);
    self::assertSame($module['core_version_requirement'], $theme['core_version_requirement']);
  }

  /**
   * Tests the complete minimum Drupal theme scaffold.
   */
  public function testGeneratesInstallableThemeScaffold(): void {
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/global.css', ':root { --wp--preset--color--base: #fff; }');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/block-styles.css', '.is-style-test { background:url("../assets/images/test.webp"); }');
    file_put_contents($this->temporaryDirectory . '/snapshot/assets/fonts/test.woff2', 'font-data');
    file_put_contents($this->temporaryDirectory . '/snapshot/assets/images/test.webp', 'image-data');
    file_put_contents(
      $this->temporaryDirectory . '/snapshot/styles.json',
      json_encode(['styles/core-blocks.css', 'styles/block-styles.css'], JSON_THROW_ON_ERROR),
    );
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/core-blocks.css', '.wp-block-button {}');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/variation-ember.css', ':root { --wp--preset--color--base: #000; }');
    $this->writeVariations([
      ['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css'],
      ['slug' => 'ember', 'title' => 'Ember', 'path' => 'styles/variation-ember.css'],
    ]);
    file_put_contents(
      $this->temporaryDirectory . '/snapshot/assets.json',
      json_encode(['assets/fonts/test.woff2', 'assets/images/test.webp'], JSON_THROW_ON_ERROR),
    );
    $snapshot = new Snapshot('twenty-twenty-four', 'Twenty Twenty-Four', '1.6', '7.1.1', $this->temporaryDirectory . '/snapshot');

    $frozen = [FrozenBlock::key('<div class="wp-block-avatar"></div>') => '<div class="wp-block-avatar"></div>'];
    $themeId = $this->generator($this->temporaryDirectory)->generate($snapshot, $frozen);
    $themeDirectory = $this->temporaryDirectory . '/themes/custom/twenty_twenty_four';

    self::assertSame('twenty_twenty_four', $themeId);
    self::assertEqualsCanonicalizing([
      'themes/custom/twenty_twenty_four/twenty_twenty_four.info.yml',
      'themes/custom/twenty_twenty_four/twenty_twenty_four.libraries.yml',
      'themes/custom/twenty_twenty_four/twenty_twenty_four.tokens.yml',
      'themes/custom/twenty_twenty_four/templates/page.html.twig',
      'themes/custom/twenty_twenty_four/twenty_twenty_four.theme',
      'themes/custom/twenty_twenty_four/theme-settings.php',
      'themes/custom/twenty_twenty_four/config/install/twenty_twenty_four.settings.yml',
      'themes/custom/twenty_twenty_four/config/schema/twenty_twenty_four.schema.yml',
      'themes/custom/twenty_twenty_four/styles/core-blocks.css',
      'themes/custom/twenty_twenty_four/styles/global.css',
      'themes/custom/twenty_twenty_four/styles/variation-ember.css',
      'themes/custom/twenty_twenty_four/styles/block-styles.css',
      'themes/custom/twenty_twenty_four/assets/fonts/test.woff2',
      'themes/custom/twenty_twenty_four/assets/images/test.webp',
      'themes/custom/twenty_twenty_four/frozen/' . array_key_first($frozen) . '.html',
      'themes/custom/twenty_twenty_four/ATTRIBUTION.md',
    ], $this->generator($this->temporaryDirectory)->filePaths($snapshot, $frozen));
    self::assertStringEqualsFile($themeDirectory . '/frozen/' . array_key_first($frozen) . '.html', '<div class="wp-block-avatar"></div>');
    $info = Yaml::parseFile($themeDirectory . '/twenty_twenty_four.info.yml');
    $libraries = Yaml::parseFile($themeDirectory . '/twenty_twenty_four.libraries.yml');
    $tokens = Yaml::parseFile($themeDirectory . '/twenty_twenty_four.tokens.yml');
    self::assertSame('#ffffff', $tokens['color']['base']['$value']);
    self::assertFalse($info['base theme']);
    self::assertSame(['wordpal:wordpal'], $info['dependencies']);
    self::assertSame(['twenty_twenty_four/global', 'wordpal/drupal_ui'], $info['libraries']);
    self::assertSame(['styles/core-blocks.css' => ['weight' => -2], 'styles/block-styles.css' => []], $libraries['global']['css']['theme']);
    self::assertSame(['styles/global.css' => ['weight' => -1]], $libraries['variation.default']['css']['theme']);
    self::assertSame(['styles/variation-ember.css' => ['weight' => -1]], $libraries['variation.ember']['css']['theme']);
    self::assertSame(['default' => 'Default', 'ember' => 'Ember'], $info['wordpal_variations']);
    self::assertSame(['style_variation' => 'default'], Yaml::parseFile($themeDirectory . '/config/install/twenty_twenty_four.settings.yml'));
    self::assertSame('theme_settings', Yaml::parseFile($themeDirectory . '/config/schema/twenty_twenty_four.schema.yml')['twenty_twenty_four.settings']['type']);
    self::assertSame(':root { --wp--preset--color--base: #fff; }', file_get_contents($themeDirectory . '/styles/global.css'));
    self::assertSame(':root { --wp--preset--color--base: #000; }', file_get_contents($themeDirectory . '/styles/variation-ember.css'));
    foreach (['twenty_twenty_four.theme', 'theme-settings.php'] as $file) {
      exec('php -l ' . escapeshellarg("$themeDirectory/$file"), $output, $status);
      self::assertSame(0, $status, "$file must parse.");
    }
    self::assertSame('font-data', file_get_contents($themeDirectory . '/assets/fonts/test.woff2'));
    self::assertStringContainsString('../assets/images/test.webp', (string) file_get_contents($themeDirectory . '/styles/block-styles.css'));
    self::assertSame('image-data', file_get_contents($themeDirectory . '/assets/images/test.webp'));
    self::assertStringContainsString('class="wp-site-blocks"', (string) file_get_contents($themeDirectory . '/templates/page.html.twig'));
    self::assertStringNotContainsString('<main', (string) file_get_contents($themeDirectory . '/templates/page.html.twig'));
    self::assertStringContainsString('{{ page.content }}', (string) file_get_contents($themeDirectory . '/templates/page.html.twig'));
    self::assertFileDoesNotExist($themeDirectory . '/templates/canvas-page-variant.html.twig');
  }

  /**
   * Tests every starterkit file reaches the theme with its tokens filled.
   */
  public function testCopiesTheStarterkitWithTokensFilled(): void {
    $this->writeVariations([['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css']]);
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/global.css', ':root {}');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles.json', '[]');
    file_put_contents($this->temporaryDirectory . '/snapshot/assets.json', '[]');
    $snapshot = new Snapshot('twenty-twenty-four', 'Twenty Twenty-Four', '1.6', '7.1.1', $this->temporaryDirectory . '/snapshot');
    $themeId = $this->generator($this->temporaryDirectory)->generate($snapshot, []);
    $theme = $this->temporaryDirectory . "/themes/custom/$themeId";
    foreach (ThemeGenerator::STARTERKIT_FILES as $source => $target) {
      $contents = file_get_contents($theme . '/' . preg_replace('/theme(?=\.)/', $themeId, $target));
      self::assertStringNotContainsString('{theme}', $contents, "$target has every token filled.");
      if ($source !== 'templates/page.html.twig') {
        self::assertStringContainsString($themeId, $contents);
      }
    }
    self::assertFileExists("$theme/$themeId.theme");
    self::assertFileExists("$theme/theme-settings.php");
    self::assertFileExists("$theme/config/schema/$themeId.schema.yml");
    self::assertFileExists("$theme/templates/page.html.twig");
  }

  /**
   * Tests plugin stylesheets become libraries a Frozen block lists.
   *
   * A block whose HTML the snapshot recorded no plugin stylesheet for gets
   * no libraries file. A stylesheet no Frozen block needs stays out.
   */
  public function testDeclaresPluginStyleLibrariesPerFrozenBlock(): void {
    file_put_contents($this->temporaryDirectory . '/snapshot/styles.json', '[]');
    file_put_contents($this->temporaryDirectory . '/snapshot/assets.json', json_encode([
      'assets/_plugin/woocommerce/assets/images/cart.svg',
      'assets/_plugin/woocommerce/assets/images/card.svg',
    ], JSON_THROW_ON_ERROR));
    mkdir($this->temporaryDirectory . '/snapshot/assets/_plugin/woocommerce/assets/images', 0777, TRUE);
    file_put_contents($this->temporaryDirectory . '/snapshot/assets/_plugin/woocommerce/assets/images/cart.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    file_put_contents($this->temporaryDirectory . '/snapshot/assets/_plugin/woocommerce/assets/images/card.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/global.css', '');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/plugin-wc-blocks-style.css', '.wc-block-components-button {}');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/plugin-wc-blocks-style-mini-cart.css', '.wc-block-mini-cart { background: url("../assets/_plugin/woocommerce/assets/images/cart.svg"); }');
    $this->writeVariations([['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css']]);
    $miniCart = '<div class="wc-block-mini-cart"></div>';
    $avatar = '<div class="wp-block-avatar"></div>';
    file_put_contents($this->temporaryDirectory . '/snapshot/plugin-styles.json', json_encode([
      'wc-blocks-style' => [
        'path' => 'styles/plugin-wc-blocks-style.css',
        'plugin' => 'woocommerce',
        'dependencies' => [],
      ],
      'wc-blocks-style-cart' => [
        'path' => 'styles/plugin-wc-blocks-style-cart.css',
        'plugin' => 'woocommerce',
        'dependencies' => ['wc-blocks-style'],
      ],
      'wc-blocks-style-mini-cart' => [
        'path' => 'styles/plugin-wc-blocks-style-mini-cart.css',
        'plugin' => 'woocommerce',
        'dependencies' => ['wc-blocks-style'],
      ],
    ], JSON_THROW_ON_ERROR));
    $this->writeMetadata(['plugin_headers' => ['woocommerce' => self::pluginHeaders([])]]);
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/plugin-wc-blocks-style-cart.css', '.wc-block-cart { background: url("../assets/_plugin/woocommerce/assets/images/card.svg"); }');
    file_put_contents($this->temporaryDirectory . '/snapshot/block-styles.json', json_encode([
      FrozenBlock::key($miniCart) => ['wc-blocks-style-mini-cart'],
      FrozenBlock::key('<div class="wc-block-cart"></div>') => ['wc-blocks-style-cart'],
    ], JSON_THROW_ON_ERROR));
    $snapshot = new Snapshot('twenty-twenty-four', 'Twenty Twenty-Four', '1.6', '7.1.1', $this->temporaryDirectory . '/snapshot');

    $this->generator($this->temporaryDirectory)->generate($snapshot, [
      FrozenBlock::key($miniCart) => $miniCart,
      FrozenBlock::key($avatar) => $avatar,
    ]);
    $themeDirectory = $this->temporaryDirectory . '/themes/custom/twenty_twenty_four';

    $libraries = Yaml::parseFile($themeDirectory . '/twenty_twenty_four.libraries.yml');
    self::assertSame(['styles/plugin-wc-blocks-style.css' => []], $libraries['plugin.wc-blocks-style']['css']['theme']);
    self::assertSame([], $libraries['plugin.wc-blocks-style']['dependencies']);
    self::assertSame(['styles/plugin-wc-blocks-style-mini-cart.css' => []], $libraries['plugin.wc-blocks-style-mini-cart']['css']['theme']);
    self::assertSame(['twenty_twenty_four/plugin.wc-blocks-style'], $libraries['plugin.wc-blocks-style-mini-cart']['dependencies']);
    self::assertSame(['plugin.wc-blocks-style-mini-cart'], Yaml::parseFile($themeDirectory . '/' . FrozenBlock::librariesPath(FrozenBlock::key($miniCart))));
    self::assertFileDoesNotExist($themeDirectory . '/' . FrozenBlock::librariesPath(FrozenBlock::key($avatar)));
    self::assertStringContainsString('url("../assets/_plugin/woocommerce/assets/images/cart.svg")', (string) file_get_contents($themeDirectory . '/styles/plugin-wc-blocks-style-mini-cart.css'));
    self::assertFileExists($themeDirectory . '/assets/_plugin/woocommerce/assets/images/cart.svg');
    self::assertArrayNotHasKey('plugin.wc-blocks-style-cart', $libraries);
    self::assertFileDoesNotExist($themeDirectory . '/styles/plugin-wc-blocks-style-cart.css');
    self::assertFileDoesNotExist($themeDirectory . '/assets/_plugin/woocommerce/assets/images/card.svg');
  }

  /**
   * Tests a plugin's inline CSS ships as a library a Frozen block lists.
   */
  public function testFrozenBlockLibraryListsInlinePluginStyle(): void {
    file_put_contents($this->temporaryDirectory . '/snapshot/styles.json', '[]');
    file_put_contents($this->temporaryDirectory . '/snapshot/assets.json', '[]');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/global.css', '');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/plugin-gutenverse-dynamic-frontend-style.css', '.guten-7FOFGx{color:red}');
    $this->writeVariations([['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css']]);
    $button = '<a class="guten-element guten-7FOFGx"></a>';
    file_put_contents($this->temporaryDirectory . '/snapshot/plugin-styles.json', json_encode([
      'gutenverse-dynamic-frontend-style' => [
        'path' => 'styles/plugin-gutenverse-dynamic-frontend-style.css',
        'plugin' => 'gutenverse',
        'dependencies' => [],
      ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($this->temporaryDirectory . '/snapshot/block-styles.json', json_encode([
      FrozenBlock::key($button) => ['gutenverse-dynamic-frontend-style'],
    ], JSON_THROW_ON_ERROR));
    $this->writeMetadata(['plugin_headers' => ['gutenverse' => self::pluginHeaders([])]]);
    $snapshot = new Snapshot('twenty-twenty-four', 'Twenty Twenty-Four', '1.6', '7.1.1', $this->temporaryDirectory . '/snapshot');

    $this->generator($this->temporaryDirectory)->generate($snapshot, [FrozenBlock::key($button) => $button]);
    $themeDirectory = $this->temporaryDirectory . '/themes/custom/twenty_twenty_four';

    self::assertSame(['plugin.gutenverse-dynamic-frontend-style'], Yaml::parseFile($themeDirectory . '/' . FrozenBlock::librariesPath(FrozenBlock::key($button))));
    self::assertFileExists($themeDirectory . '/styles/plugin-gutenverse-dynamic-frontend-style.css');
  }

  /**
   * Tests that generation passes the built files and theme ID to the hook.
   */
  public function testAltersGeneratedFilesThroughModuleHandler(): void {
    file_put_contents($this->temporaryDirectory . '/snapshot/styles.json', json_encode([], JSON_THROW_ON_ERROR));
    file_put_contents($this->temporaryDirectory . '/snapshot/assets.json', json_encode([], JSON_THROW_ON_ERROR));
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/global.css', '');
    $this->writeVariations([['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css']]);
    $snapshot = new Snapshot('twenty-twenty-four', 'Twenty Twenty-Four', '1.6', '7.1.1', $this->temporaryDirectory . '/snapshot');
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->expects(self::once())
      ->method('alter')
      ->with(
        'wordpal_theme_files',
        self::callback(static fn (array $files): bool => array_key_exists('themes/custom/twenty_twenty_four/twenty_twenty_four.info.yml', $files)),
        'twenty_twenty_four',
      );

    (new ThemeGenerator($this->temporaryDirectory, $moduleHandler))->generate($snapshot, []);
  }

  /**
   * Tests a WordPress.org theme's Legal files, screenshot and notice.
   */
  public function testAttributesWordPressOrgTheme(): void {
    $legal = ['legal/readme.txt', 'legal/assets/fonts/inter/OFL.txt'];
    foreach ($legal as $path) {
      mkdir(dirname($this->temporaryDirectory . '/snapshot/' . $path), 0777, TRUE);
      file_put_contents($this->temporaryDirectory . '/snapshot/' . $path, "text of $path");
    }
    file_put_contents($this->temporaryDirectory . '/snapshot/legal.json', json_encode($legal, JSON_THROW_ON_ERROR));
    file_put_contents($this->temporaryDirectory . '/snapshot/screenshot.png', 'png-data');
    $this->writeMetadata(['screenshot' => 'screenshot.png']);
    $snapshot = $this->minimalSnapshot();

    $this->generator($this->temporaryDirectory)->generate($snapshot, []);
    $themeDirectory = $this->temporaryDirectory . '/themes/custom/twenty_twenty_four';

    $info = Yaml::parseFile($themeDirectory . '/twenty_twenty_four.info.yml');
    self::assertSame('Converted by WordPal from the WordPress theme Twenty Twenty-Four 1.6 by the WordPress team. License: GNU General Public License v2 or later. Original: <a href="https://wordpress.org/themes/twenty-twenty-four/">https://wordpress.org/themes/twenty-twenty-four/</a>.', $info['description']);
    self::assertArrayNotHasKey('screenshot', $info);
    self::assertSame('png-data', file_get_contents($themeDirectory . '/screenshot.png'));
    self::assertSame('text of legal/readme.txt', file_get_contents($themeDirectory . '/readme.txt'));
    self::assertSame('text of legal/assets/fonts/inter/OFL.txt', file_get_contents($themeDirectory . '/assets/fonts/inter/OFL.txt'));
    self::assertSame(<<<'MD'
      # Attribution

      This Drupal theme is a conversion of the WordPress theme Twenty Twenty-Four 1.6. WordPal converted and modified it on 2026-09-30.

      - Author: the WordPress team <https://wordpress.org>
      - License: GNU General Public License v2 or later <http://www.gnu.org/licenses/gpl-2.0.html>
      - Original theme: <https://wordpress.org/themes/twenty-twenty-four/>

      The theme's own copyright and license notices are in `readme.txt`, `assets/fonts/inter/OFL.txt`.

      MD, file_get_contents($themeDirectory . '/ATTRIBUTION.md'));
  }

  /**
   * Tests that each plugin whose files ship is credited with its notices.
   *
   * A plugin whose stylesheets no Frozen block needs ships no files, so its
   * Legal files and credit stay out.
   */
  public function testAttributesShippedPlugins(): void {
    $legal = [
      'legal/assets/_plugin/unused/LICENSE',
      'legal/assets/_plugin/woocommerce/license.txt',
      'legal/assets/_plugin/woocommerce/readme.txt',
    ];
    foreach ($legal as $path) {
      (new Filesystem())->mkdir(dirname($this->temporaryDirectory . '/snapshot/' . $path));
      file_put_contents($this->temporaryDirectory . '/snapshot/' . $path, "text of $path");
    }
    file_put_contents($this->temporaryDirectory . '/snapshot/legal.json', json_encode($legal, JSON_THROW_ON_ERROR));
    foreach (['wc-blocks-style', 'acme-forms', 'unused-style'] as $handle) {
      file_put_contents($this->temporaryDirectory . "/snapshot/styles/plugin-$handle.css", '.a {}');
    }
    file_put_contents($this->temporaryDirectory . '/snapshot/plugin-styles.json', json_encode([
      'acme-forms' => ['path' => 'styles/plugin-acme-forms.css', 'plugin' => 'acme-forms', 'dependencies' => []],
      'unused-style' => ['path' => 'styles/plugin-unused-style.css', 'plugin' => 'unused', 'dependencies' => []],
      'wc-blocks-style' => [
        'path' => 'styles/plugin-wc-blocks-style.css',
        'plugin' => 'woocommerce',
        'dependencies' => [],
      ],
    ], JSON_THROW_ON_ERROR));
    $cart = '<div class="wc-block-cart"></div>';
    $form = '<form class="acme"></form>';
    file_put_contents($this->temporaryDirectory . '/snapshot/block-styles.json', json_encode([
      FrozenBlock::key($cart) => ['wc-blocks-style'],
      FrozenBlock::key($form) => ['acme-forms'],
      FrozenBlock::key('<div class="unused"></div>') => ['unused-style'],
    ], JSON_THROW_ON_ERROR));
    $this->writeMetadata([
      'sources' => ['theme:twenty-twenty-four' => NULL, 'plugin:woocommerce' => NULL, 'plugin:acme-forms' => 'abc'],
      'plugin_headers' => [
        'acme-forms' => self::pluginHeaders([
          'name' => 'Acme <b>Forms</b>',
          'version' => '1.2',
          'plugin_uri' => 'javascript:alert(1)',
          'author' => '',
          'author_uri' => '',
          'license' => 'Acme Commercial License',
          'license_uri' => '',
        ]),
        'unused' => self::pluginHeaders(['name' => 'Unused']),
        'woocommerce' => self::pluginHeaders([]),
      ],
    ]);
    $snapshot = $this->minimalSnapshot();
    $frozen = [FrozenBlock::key($cart) => $cart, FrozenBlock::key($form) => $form];

    self::assertSame(['acme-forms', 'woocommerce'], ThemeGenerator::shippedPlugins($snapshot, $frozen));
    $this->generator($this->temporaryDirectory)->generate($snapshot, $frozen);
    $themeDirectory = $this->temporaryDirectory . '/themes/custom/twenty_twenty_four';

    self::assertSame('text of legal/assets/_plugin/woocommerce/readme.txt', file_get_contents($themeDirectory . '/assets/_plugin/woocommerce/readme.txt'));
    self::assertFileDoesNotExist($themeDirectory . '/assets/_plugin/unused/LICENSE');
    self::assertSame(<<<'MD'
      # Attribution

      This Drupal theme is a conversion of the WordPress theme Twenty Twenty-Four 1.6. WordPal converted and modified it on 2026-09-30.

      - Author: the WordPress team <https://wordpress.org>
      - License: GNU General Public License v2 or later <http://www.gnu.org/licenses/gpl-2.0.html>
      - Original theme: <https://wordpress.org/themes/twenty-twenty-four/>

      ## Plugins

      The theme also holds stylesheets and assets copied from these WordPress plugins.

      ### Acme Forms 1.2

      - License: Acme Commercial License

      ### WooCommerce 9.3.1

      - Author: Automattic <https://woocommerce.com>
      - License: GPLv3 <https://www.gnu.org/licenses/gpl-3.0.html>
      - Original plugin: <https://wordpress.org/plugins/woocommerce/>
      - Original plugin: <https://woocommerce.com/>
      - Notices: `assets/_plugin/woocommerce/license.txt`, `assets/_plugin/woocommerce/readme.txt`

      MD, file_get_contents($themeDirectory . '/ATTRIBUTION.md'));
  }

  /**
   * Tests that a plugin is credited when another plugin's CSS ships its asset.
   */
  public function testCreditsPluginOfReferencedAsset(): void {
    mkdir($this->temporaryDirectory . '/snapshot/assets/_plugin/wc-icons', 0777, TRUE);
    file_put_contents($this->temporaryDirectory . '/snapshot/assets/_plugin/wc-icons/cart.png', 'png');
    file_put_contents($this->temporaryDirectory . '/snapshot/styles/plugin-wc-addon.css', '.a { background: url("../assets/_plugin/wc-icons/cart.png"); }');
    file_put_contents($this->temporaryDirectory . '/snapshot/plugin-styles.json', json_encode([
      'wc-addon' => ['path' => 'styles/plugin-wc-addon.css', 'plugin' => 'wc-addon', 'dependencies' => []],
    ], JSON_THROW_ON_ERROR));
    $block = '<div class="wc-addon"></div>';
    file_put_contents($this->temporaryDirectory . '/snapshot/block-styles.json', json_encode([FrozenBlock::key($block) => ['wc-addon']], JSON_THROW_ON_ERROR));
    $this->writeMetadata([
      'plugin_headers' => [
        'wc-addon' => self::pluginHeaders(['name' => 'Add-on']),
        'wc-icons' => self::pluginHeaders(['name' => 'Icons']),
      ],
    ]);
    $snapshot = $this->minimalSnapshot();
    file_put_contents($this->temporaryDirectory . '/snapshot/assets.json', json_encode(['assets/_plugin/wc-icons/cart.png'], JSON_THROW_ON_ERROR));

    self::assertSame(['wc-addon', 'wc-icons'], ThemeGenerator::shippedPlugins($snapshot, [FrozenBlock::key($block) => $block]));
    self::assertSame([], ThemeGenerator::shippedPlugins($snapshot, []));
  }

  /**
   * Tests that a WordPress.org theme also links a Theme URI of its own.
   */
  public function testLinksOwnThemeUriBesideWordPressOrg(): void {
    $this->writeMetadata(['theme_uri' => 'https://example.com/twenty']);

    self::assertSame(['https://wordpress.org/themes/twenty-twenty-four/', 'https://example.com/twenty'], ThemeGenerator::originalLinks($this->minimalSnapshot()));
  }

  /**
   * Tests that a zip theme links its Theme URI alone.
   */
  public function testZipThemeLinksItsThemeUri(): void {
    $this->writeMetadata([
      'sources' => ['theme:twenty-twenty-four' => 'abc'],
      'theme_uri' => 'https://example.com/twenty',
    ]);

    self::assertSame(['https://example.com/twenty'], ThemeGenerator::originalLinks($this->minimalSnapshot()));
  }

  /**
   * Tests that a zip theme's headers are escaped and unsafe URIs dropped.
   */
  public function testEscapesZipThemeHeaders(): void {
    file_put_contents($this->temporaryDirectory . '/snapshot/screenshot.jpg', 'jpg-data');
    $this->writeMetadata([
      'sources' => ['theme:twenty-twenty-four' => 'abc'],
      'license' => 'Own <b>terms</b> [x](https://evil.example)',
      'license_uri' => 'javascript:alert(1)',
      'theme_uri' => 'https://example.com/" onmouseover="alert(1)',
      'author' => 'A & B <script>alert(1)</script>',
      'author_uri' => '',
      'screenshot' => 'screenshot.jpg',
    ]);
    $snapshot = $this->minimalSnapshot();

    self::assertSame([], ThemeGenerator::originalLinks($snapshot));
    $this->generator($this->temporaryDirectory)->generate($snapshot, []);
    $themeDirectory = $this->temporaryDirectory . '/themes/custom/twenty_twenty_four';

    $info = Yaml::parseFile($themeDirectory . '/twenty_twenty_four.info.yml');
    self::assertSame('Converted by WordPal from the WordPress theme Twenty Twenty-Four 1.6 by A &amp; B alert(1). License: Own terms [x](https://evil.example).', $info['description']);
    self::assertSame('screenshot.jpg', $info['screenshot']);
    self::assertSame(<<<'MD'
      # Attribution

      This Drupal theme is a conversion of the WordPress theme Twenty Twenty-Four 1.6. WordPal converted and modified it on 2026-09-30.

      - Author: A & B alert(1)
      - License: Own terms \[x\](https://evil.example)

      MD, file_get_contents($themeDirectory . '/ATTRIBUTION.md'));
  }

  /**
   * Tests validation before a slug can control a generated path.
   */
  public function testRejectsUnsafeExternalSlug(): void {
    $snapshot = new Snapshot('../outside', 'Outside', '1.0', '7.1.1', $this->temporaryDirectory . '/snapshot');

    $this->expectException(\InvalidArgumentException::class);
    $this->generator($this->temporaryDirectory)->generate($snapshot, []);
  }

  /**
   * Tests that a generated theme's machine name gives back its slug.
   */
  public function testThemeSlugReversesThemeId(): void {
    self::assertSame('yith-wonder', ThemeGenerator::themeSlug(ThemeGenerator::themeId('yith-wonder')));
    self::assertSame('twentytwentyfive', ThemeGenerator::themeSlug('twentytwentyfive'));
  }

  /**
   * Tests that an incomplete snapshot creates no partial theme.
   */
  public function testRequiresStyleManifestBeforeWriting(): void {
    $snapshot = new Snapshot('incomplete', 'Incomplete', '1.0', '7.1.1', $this->temporaryDirectory . '/snapshot');

    try {
      $this->generator($this->temporaryDirectory)->generate($snapshot, []);
      self::fail('The incomplete snapshot should fail.');
    }
    catch (\RuntimeException) {
      self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/themes/custom/incomplete');
    }
  }

  /**
   * Tests destination links cannot redirect generated files outside the theme.
   */
  public function testRejectsLinkedThemeDestinationBeforeWriting(): void {
    mkdir($this->temporaryDirectory . '/themes/custom/linked', 0777, TRUE);
    mkdir($this->temporaryDirectory . '/outside');
    file_put_contents($this->temporaryDirectory . '/outside/target', 'unmanaged');
    symlink($this->temporaryDirectory . '/outside/target', $this->temporaryDirectory . '/themes/custom/linked/linked.info.yml');
    $generator = $this->generator($this->temporaryDirectory);

    $this->expectException(\UnexpectedValueException::class);
    $generator->validatePaths('linked', ['themes/custom/linked/linked.info.yml']);
  }

  /**
   * Tests a stored manifest cannot name a file outside its exact theme.
   */
  public function testRejectsManifestPathOutsideTheme(): void {
    $generator = $this->generator($this->temporaryDirectory);

    $this->expectException(\UnexpectedValueException::class);
    $generator->validatePaths('owned', ['themes/custom/other/other.info.yml']);
  }

}
