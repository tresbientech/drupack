<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Support\PathValidator;
use Drupal\wordpal_convert\WordPress\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests required snapshot metadata files.
 */
#[CoversClass(Snapshot::class)]
#[Group('wordpal')]
final class SnapshotTest extends UnitTestCase {

  /**
   * Tests that missing settings fail as an incomplete snapshot.
   */
  public function testMissingSettingsFail(): void {
    $directory = sys_get_temp_dir() . '/wordpal-missing-snapshot-' . bin2hex(random_bytes(6));
    $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Snapshot of theme fixture has no settings.json.');
    $snapshot->settings();
  }

  /**
   * Tests that invalid pattern metadata fails JSON decoding.
   */
  public function testInvalidPatternsFail(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory . '/patterns.json', '{');

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      $snapshot->patterns();
      self::fail('Invalid pattern metadata should fail.');
    }
    catch (\JsonException $exception) {
      self::assertSame(JSON_ERROR_SYNTAX, $exception->getCode());
    }
    finally {
      unlink($directory . '/patterns.json');
      rmdir($directory);
    }
  }

  /**
   * Tests the duotone filter SVG WP_Duotone prints passes the settings check.
   */
  public function testAcceptsWordPressDuotoneFilter(): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 0" width="0" height="0" focusable="false" role="none" style="visibility: hidden; position: absolute; left: -9999px; overflow: hidden;" ><defs><filter id="wp-duotone-dark-grayscale"><feColorMatrix color-interpolation-filters="sRGB" type="matrix" values=" .299 .587 .114 0 0 .299 .587 .114 0 0 .299 .587 .114 0 0 .299 .587 .114 0 0 " /><feComponentTransfer color-interpolation-filters="sRGB" ><feFuncR type="table" tableValues="0 0.49803921568627" /><feFuncG type="table" tableValues="0 0.49803921568627" /><feFuncB type="table" tableValues="0 0.49803921568627" /><feFuncA type="table" tableValues="1 1" /></feComponentTransfer><feComposite in2="SourceGraphic" operator="in" /></filter></defs></svg>';
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents("$directory/settings.json", json_encode([
      'layout' => ['wideSize' => '1200px'],
      'position' => ['sticky' => TRUE, 'fixed' => FALSE],
      'duotone_filters' => ['dark-grayscale' => $svg],
      'use_root_padding_aware_alignments' => FALSE,
      'block_gap_support' => FALSE,
      'block_gaps' => [],
      'root_block_gap' => NULL,
      'button_widths' => [],
      'typography' => ['fluid' => FALSE],
      'styled_variations' => [],
      'registered_variations' => [],
    ], JSON_THROW_ON_ERROR));

    try {
      $settings = (new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory))->settings();
      self::assertSame($svg, $settings['duotone_filters']['dark-grayscale']);
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests a settings, presets or patterns file of the wrong shape fails.
   */
  #[DataProvider('invalidThemeFiles')]
  public function testRejectsInvalidThemeFiles(string $file, mixed $contents, string $message): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents("$directory/$file.json", json_encode($contents, JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      $snapshot->$file();
      self::fail("An invalid $file.json should fail.");
    }
    catch (\UnexpectedValueException $exception) {
      self::assertSame($message, $exception->getMessage());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Returns malformed settings, presets and patterns files.
   */
  public static function invalidThemeFiles(): array {
    $settings = [
      'layout' => ['wideSize' => '1200px'],
      'position' => ['sticky' => TRUE, 'fixed' => FALSE],
      'duotone_filters' => [],
      'use_root_padding_aware_alignments' => FALSE,
      'block_gap_support' => FALSE,
      'block_gaps' => [],
      'root_block_gap' => NULL,
      'button_widths' => [],
      'typography' => ['fluid' => FALSE],
      'styled_variations' => [],
      'registered_variations' => [],
    ];
    $presets = ['color' => [], 'gradient' => [], 'font-size' => [], 'font-family' => [], 'spacing' => []];
    $pattern = ['slug' => 'fixture/cta', 'title' => 'CTA', 'categories' => ['featured'], 'inserter' => TRUE];
    $settingsError = 'Snapshot settings.json has an invalid shape. Run with --refresh.';
    return [
      'settings list' => ['settings', ['x'], $settingsError],
      'settings wide size' => ['settings', ['layout' => ['wideSize' => 12]] + $settings, $settingsError],
      'settings alignment flag' => [
        'settings',
        ['use_root_padding_aware_alignments' => 'yes'] + $settings,
        $settingsError,
      ],
      'settings block gap flag' => [
        'settings',
        ['block_gap_support' => 'yes'] + $settings,
        $settingsError,
      ],
      'settings block gaps' => [
        'settings',
        ['block_gaps' => ['core/columns' => 3]] + $settings,
        $settingsError,
      ],
      'settings block gap sides' => [
        'settings',
        ['block_gaps' => ['core/columns' => ['top' => 3]]] + $settings,
        $settingsError,
      ],
      'settings root block gap' => [
        'settings',
        ['root_block_gap' => 3] + $settings,
        $settingsError,
      ],
      'settings without root block gap' => [
        'settings',
        array_diff_key($settings, ['root_block_gap' => TRUE]),
        $settingsError,
      ],
      'settings without registered variations' => [
        'settings',
        array_diff_key($settings, ['registered_variations' => TRUE]),
        $settingsError,
      ],
      'settings registered variation names' => [
        'settings',
        ['registered_variations' => ['core/button' => [1]]] + $settings,
        $settingsError,
      ],
      'settings button widths' => [
        'settings',
        ['button_widths' => ['50' => 50]] + $settings,
        $settingsError,
      ],
      'settings fluid' => ['settings', ['typography' => ['fluid' => 'on']] + $settings, $settingsError],
      'settings position' => ['settings', ['position' => ['sticky' => 1, 'fixed' => FALSE]] + $settings, $settingsError],
      'settings duotone filter' => ['settings', ['duotone_filters' => ['x' => '<script>']] + $settings, $settingsError],
      'settings duotone active content' => [
        'settings',
        ['duotone_filters' => ['x' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script></svg>']] + $settings,
        $settingsError,
      ],
      'settings variation class' => [
        'settings',
        ['styled_variations' => ['core/button/outline' => 1]] + $settings,
        $settingsError,
      ],
      'presets scalar' => ['presets', 'x', 'Snapshot presets.json has no "color" list. Run with --refresh.'],
      'presets missing group' => [
        'presets',
        array_diff_key($presets, ['spacing' => TRUE]),
        'Snapshot presets.json has no "spacing" list. Run with --refresh.',
      ],
      'presets invalid preset' => [
        'presets',
        ['font-size' => [['slug' => 'small', 'name' => 'Small', 'size' => 12]]] + $presets,
        'Snapshot presets.json lists an invalid "font-size" preset. Run with --refresh.',
      ],
      'patterns scalar' => ['patterns', 'x', 'Snapshot patterns.json must contain a map. Run with --refresh.'],
      'patterns key' => [
        'patterns',
        ['other' => $pattern],
        'Snapshot patterns.json has an invalid entry for other. Run with --refresh.',
      ],
      'patterns categories' => [
        'patterns',
        ['fixture--cta' => ['categories' => 'featured'] + $pattern],
        'Snapshot patterns.json has an invalid entry for fixture--cta. Run with --refresh.',
      ],
      'patterns inserter' => [
        'patterns',
        ['fixture--cta' => ['inserter' => NULL] + $pattern],
        'Snapshot patterns.json has an invalid entry for fixture--cta. Run with --refresh.',
      ],
    ];
  }

  /**
   * Tests a preset with no name is read: theme.json makes the name optional.
   */
  public function testReadsPresetWithNoName(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $presets = ['color' => [], 'gradient' => [], 'font-size' => [], 'spacing' => []];
    $presets['font-family'] = [['slug' => 'heading', 'fontFamily' => 'Frank Ruhl Libre']];
    file_put_contents($directory . '/presets.json', json_encode($presets, JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame($presets['font-family'], $snapshot->presets()['font-family']);
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests asset names in retina and variable-font naming are read.
   */
  public function testReadsAssetNamesWithAtAndBrackets(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    $paths = ['assets/fonts/sans[wght].woff2', 'assets/images/glass@2x.jpg'];
    foreach ($paths as $path) {
      mkdir(dirname($directory . '/' . $path), 0777, TRUE);
      file_put_contents($directory . '/' . $path, $path);
    }
    file_put_contents($directory . '/assets.json', json_encode($paths, JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame(array_combine($paths, $paths), $snapshot->assets());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that a plugin asset must sit in an installed plugin's directory.
   */
  #[DataProvider('pluginAssetProvider')]
  public function testPluginAssetNeedsInstalledPlugin(string $path, bool $valid): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    (new Filesystem())->mkdir(dirname($directory . '/' . $path));
    file_put_contents($directory . '/' . $path, 'png');
    file_put_contents($directory . '/assets.json', json_encode([$path], JSON_THROW_ON_ERROR));
    self::writePluginHeaders($directory, ['woocommerce' => []]);

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      if (!$valid) {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Snapshot manifest assets.json lists a plugin asset outside an installed plugin directory: $path");
      }
      self::assertSame([$path => 'png'], $snapshot->assets());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Provides plugin asset paths and whether the snapshot accepts each.
   */
  public static function pluginAssetProvider(): array {
    return [
      'installed plugin' => ['assets/_plugin/woocommerce/images/cart.png', TRUE],
      'plugin not installed' => ['assets/_plugin/other/images/cart.png', FALSE],
      'plugins root' => ['assets/_plugin/cart.png', FALSE],
    ];
  }

  /**
   * Tests that Legal files are read by theme path, and odd names skipped.
   */
  public function testReadsLegalFiles(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    $paths = [
      'legal/LICENSE',
      'legal/assets/fonts/Fira Code/OFL.txt',
      'legal/assets/fonts/inter/LICENSE.md',
      'legal/readme.txt',
    ];
    foreach ($paths as $path) {
      (new Filesystem())->mkdir(dirname($directory . '/' . $path));
      file_put_contents($directory . '/' . $path, basename($path));
    }
    file_put_contents($directory . '/legal.json', json_encode($paths, JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame([
        'LICENSE' => 'LICENSE',
        'assets/fonts/inter/LICENSE.md' => 'LICENSE.md',
        'readme.txt' => 'readme.txt',
      ], $snapshot->legalFiles());
      self::assertSame(['Legal file "legal/assets/fonts/Fira Code/OFL.txt": its name has characters a Drupal theme path does not allow.'], $snapshot->skippedLegalFiles());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that a plugin's root readme.txt is a Legal file of that plugin.
   */
  public function testReadsPluginLegalFiles(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    $path = 'legal/assets/_plugin/woocommerce/readme.txt';
    (new Filesystem())->mkdir(dirname($directory . '/' . $path));
    file_put_contents($directory . '/' . $path, 'readme');
    file_put_contents($directory . '/legal.json', json_encode([$path], JSON_THROW_ON_ERROR));
    self::writePluginHeaders($directory, ['woocommerce' => []]);

    try {
      self::assertSame(['assets/_plugin/woocommerce/readme.txt' => 'readme'], (new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory))->legalFiles());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that a plugin readme.txt counts only at an installed plugin's root.
   */
  #[DataProvider('invalidPluginLegalFileProvider')]
  public function testRejectsOtherPluginReadme(string $path): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    (new Filesystem())->mkdir(dirname($directory . '/' . $path));
    file_put_contents($directory . '/' . $path, 'readme');
    file_put_contents($directory . '/legal.json', json_encode([$path], JSON_THROW_ON_ERROR));
    self::writePluginHeaders($directory, ['woocommerce' => []]);

    try {
      $this->expectException(\UnexpectedValueException::class);
      $this->expectExceptionMessage("Snapshot manifest legal.json lists a file that is not a Legal file: $path");
      (new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory))->legalFiles();
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Provides plugin readme paths that are not a plugin's root Legal file.
   */
  public static function invalidPluginLegalFileProvider(): array {
    return [
      'plugin not installed' => ['legal/assets/_plugin/other/readme.txt'],
      'readme below the plugin root' => ['legal/assets/_plugin/woocommerce/docs/readme.txt'],
      'license below the plugin root' => ['legal/assets/_plugin/woocommerce/assets/LICENSE'],
    ];
  }

  /**
   * Tests the plugin headers and which plugins came from WordPress.org.
   */
  public function testReadsPluginHeaders(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory);
    self::writePluginHeaders($directory, ['woocommerce' => [], 'acme' => ['name' => 'Acme']], ['plugin:woocommerce' => NULL]);

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame('Acme', $snapshot->pluginHeaders()['acme']['name']);
      self::assertTrue($snapshot->pluginFromWordPressOrg('woocommerce'));
      self::assertFalse($snapshot->pluginFromWordPressOrg('acme'), 'A plugin the conversion did not name has no source entry.');
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that legal.json cannot list a file outside the Legal files.
   */
  public function testRejectsOtherFileAsLegalFile(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory . '/legal/parts', 0777, TRUE);
    file_put_contents($directory . '/legal/parts/notes.txt', 'notes');
    file_put_contents($directory . '/legal.json', json_encode(['legal/parts/notes.txt'], JSON_THROW_ON_ERROR));

    try {
      $this->expectException(\UnexpectedValueException::class);
      $this->expectExceptionMessage('Snapshot manifest legal.json lists a file that is not a Legal file: legal/parts/notes.txt');
      (new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory))->legalFiles();
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that an unsafe SVG asset is left out and reported.
   */
  public function testSkipsUnsafeSvgAsset(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    $files = [
      'assets/icons/safe.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>',
      'assets/icons/unsafe.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
    ];
    mkdir($directory . '/assets/icons', 0777, TRUE);
    foreach ($files as $path => $contents) {
      file_put_contents($directory . '/' . $path, $contents);
    }
    file_put_contents($directory . '/assets.json', json_encode(array_keys($files), JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame(['assets/icons/safe.svg' => $files['assets/icons/safe.svg']], $snapshot->assets());
      self::assertSame(['Asset assets/icons/unsafe.svg: holds the event handler attribute onload'], $snapshot->skippedAssets());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that registered block names must be namespaced.
   */
  public function testRejectsUnnamespacedRegisteredBlock(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory . '/blocks.json', json_encode(['core/paragraph' => [], 'paragraph' => []], JSON_THROW_ON_ERROR));

    try {
      $this->expectException(\UnexpectedValueException::class);
      $this->expectExceptionMessage('Snapshot blocks.json has an invalid entry for paragraph.');
      (new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory))->registeredBlocks();
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that a block's supports value must be an array.
   */
  public function testRejectsNonArrayBlockSupports(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory . '/blocks.json', json_encode(['core/paragraph' => 'yes'], JSON_THROW_ON_ERROR));

    try {
      $this->expectException(\UnexpectedValueException::class);
      $this->expectExceptionMessage('Snapshot blocks.json has an invalid entry for core/paragraph.');
      (new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory))->registeredBlocks();
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that registered block names are read from blocks.json.
   */
  public function testReadsRegisteredBlocks(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory . '/blocks.json', json_encode([
      'core/button' => ['color' => ['gradients' => TRUE]],
      'core/heading' => ['color' => ['gradients' => TRUE, 'link' => TRUE]],
    ], JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame(['core/button', 'core/heading'], $snapshot->registeredBlocks());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that manifest paths cannot leave their snapshot directory.
   */
  public function testRejectsUnsafeManifestPath(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory . '/styles.json', json_encode(['styles/../../outside.css'], JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      $snapshot->styles();
      self::fail('An unsafe manifest path should fail.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertStringContainsString('unsafe path', $exception->getMessage());
    }
    finally {
      unlink($directory . '/styles.json');
      rmdir($directory);
    }
  }

  /**
   * Tests that variations read in order with their stylesheets.
   */
  public function testReadsVariations(): void {
    $directory = $this->variationSnapshot([
      ['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css'],
      ['slug' => 'ember', 'title' => 'Ember', 'path' => 'styles/variation-ember.css'],
    ]);

    try {
      self::assertSame([
        'default' => ['title' => 'Default', 'path' => 'styles/global.css', 'css' => 'styles/global.css'],
        'ember' => ['title' => 'Ember', 'path' => 'styles/variation-ember.css', 'css' => 'styles/variation-ember.css'],
      ], (new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory))->variations());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that a variation with an unusable slug is left out and listed.
   */
  public function testSkipsVariationsWithUnusableSlugs(): void {
    $directory = $this->variationSnapshot([
      ['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css'],
      ['slug' => 'caf%c3%a9', 'title' => 'Café', 'path' => 'styles/variation-ember.css'],
      ['slug' => 'default', 'title' => 'Default', 'path' => 'styles/variation-ember.css'],
      ['slug' => 'ember', 'title' => 'Ember', 'path' => 'styles/variation-ember.css'],
    ]);

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame(['default', 'ember'], array_keys($snapshot->variations()));
      self::assertSame([
        'Style variation Café: slug caf%c3%a9 is not usable',
        "Style variation Default: slug default repeats another variation's slug",
      ], $snapshot->skippedVariations());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests that variations.json rejects a bad shape, order or path.
   */
  #[DataProvider('invalidVariations')]
  public function testRejectsInvalidVariations(array $variations, string $message): void {
    $directory = $this->variationSnapshot($variations);

    try {
      $this->expectException(\UnexpectedValueException::class);
      $this->expectExceptionMessage($message);
      (new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory))->variations();
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Provides variations.json lists the snapshot refuses.
   */
  public static function invalidVariations(): array {
    $default = ['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css'];
    $ember = ['slug' => 'ember', 'title' => 'Ember', 'path' => 'styles/variation-ember.css'];
    $outside = ['slug' => 'default', 'title' => 'Default', 'path' => 'styles/../../outside.css'];
    return [
      'missing title' => [[$default, ['slug' => 'ember', 'path' => 'styles/variation-ember.css']], 'invalid variation'],
      'own styles not first' => [[$ember, $default], 'own styles first'],
      'path outside styles' => [[$outside], 'unsafe path'],
    ];
  }

  /**
   * Writes a snapshot directory with two stylesheets and a variations.json.
   */
  private function variationSnapshot(array $variations): string {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory . '/styles', 0777, TRUE);
    file_put_contents($directory . '/styles/global.css', 'styles/global.css');
    file_put_contents($directory . '/styles/variation-ember.css', 'styles/variation-ember.css');
    file_put_contents($directory . '/variations.json', json_encode($variations, JSON_THROW_ON_ERROR));
    return $directory;
  }

  /**
   * Tests that an asset symlink cannot escape the snapshot directory.
   */
  public function testRejectsLinkedAsset(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory . '/assets', 0777, TRUE);
    symlink(__FILE__, $directory . '/assets/linked.woff2');
    file_put_contents($directory . '/assets.json', json_encode(['assets/linked.woff2'], JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      $snapshot->assets();
      self::fail('A linked asset should fail.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertStringContainsString('missing or linked path', $exception->getMessage());
    }
    finally {
      unlink($directory . '/assets/linked.woff2');
      unlink($directory . '/assets.json');
      rmdir($directory . '/assets');
      rmdir($directory);
    }
  }

  /**
   * Tests that template and part reads reject traversal and escaping links.
   */
  public function testTemplateAndPartReadsStayInsideSnapshot(): void {
    $directory = sys_get_temp_dir() . '/wordpal-snapshot-' . bin2hex(random_bytes(6));
    mkdir($directory . '/templates', 0777, TRUE);
    mkdir($directory . '/parts', 0777, TRUE);
    $outside = $directory . '-outside.html';
    file_put_contents($outside, 'outside');
    symlink($outside, $directory . '/templates/linked.html');
    symlink($outside, $directory . '/parts/linked.html');

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      foreach (['template', 'part'] as $reader) {
        try {
          $snapshot->{$reader}('../../' . basename($outside, '.html'));
          self::fail("The $reader traversal should fail.");
        }
        catch (\UnexpectedValueException $exception) {
          self::assertStringContainsString('escapes theme directory', $exception->getMessage());
        }
        try {
          $snapshot->{$reader}('linked');
          self::fail("The linked $reader should fail.");
        }
        catch (\UnexpectedValueException $exception) {
          self::assertStringContainsString('escapes theme directory', $exception->getMessage());
        }
      }
    }
    finally {
      unlink($directory . '/templates/linked.html');
      unlink($directory . '/parts/linked.html');
      unlink($outside);
      rmdir($directory . '/templates');
      rmdir($directory . '/parts');
      rmdir($directory);
    }
  }

  /**
   * Tests the block render index is read per file and its shape checked.
   */
  public function testReadsRenderIndex(): void {
    $directory = sys_get_temp_dir() . '/wordpal-render-index-' . bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory . '/patterns.json', json_encode([
      'fixture--cta' => ['slug' => 'fixture/cta', 'title' => 'CTA', 'categories' => [], 'inserter' => TRUE],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/render-index.json', json_encode([
      'patterns/fixture--cta.html' => ['0' => '<p></p>', '0.1' => '<b></b>'],
      'parts/header.html' => ['../0' => '<p></p>'],
    ], JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame(['0' => '<p></p>', '0.1' => '<b></b>'], $snapshot->patternRenders('fixture/cta'));
      try {
        $snapshot->templateRenders('single');
        self::fail('A file the index lacks must fail.');
      }
      catch (\UnexpectedValueException $exception) {
        self::assertSame('Snapshot render-index.json has no entry for templates/single.html.', $exception->getMessage());
      }
      $this->expectException(\UnexpectedValueException::class);
      $this->expectExceptionMessage('Snapshot render-index.json has an invalid entry for parts/header.html.');
      $snapshot->partRenders('header');
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests plugin stylesheets and the blocks needing them are read.
   */
  public function testReadsPluginStyles(): void {
    $directory = $this->pluginStyleSnapshot([
      'wc-blocks-style' => [
        'path' => 'styles/plugin-wc-blocks-style.css',
        'plugin' => 'woocommerce',
        'dependencies' => [],
      ],
      'wc-blocks-style-mini-cart' => [
        'path' => 'styles/plugin-wc-blocks-style-mini-cart.css',
        'plugin' => 'woocommerce',
        'dependencies' => ['wc-blocks-style'],
      ],
    ], [FrozenBlock::key('<div></div>') => ['wc-blocks-style-mini-cart']]);

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame([
        'wc-blocks-style' => [
          'path' => 'styles/plugin-wc-blocks-style.css',
          'plugin' => 'woocommerce',
          'dependencies' => [],
          'css' => '.a {}',
        ],
        'wc-blocks-style-mini-cart' => [
          'path' => 'styles/plugin-wc-blocks-style-mini-cart.css',
          'plugin' => 'woocommerce',
          'dependencies' => ['wc-blocks-style'],
          'css' => '.a {}',
        ],
      ], $snapshot->pluginStyles());
      self::assertSame([FrozenBlock::key('<div></div>') => ['wc-blocks-style-mini-cart']], $snapshot->blockStyles());
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests the CSS of a handle with no file of its own is read like any other.
   */
  public function testInlineOnlyHandleShipsItsCss(): void {
    $directory = $this->pluginStyleSnapshot([
      'gutenverse-frontend-button-style' => [
        'path' => 'styles/plugin-gutenverse-frontend-button-style.css',
        'plugin' => 'gutenverse',
        'dependencies' => [],
        'css' => '.guten-button { color: red; }',
      ],
    ], [FrozenBlock::key('<a></a>') => ['gutenverse-frontend-button-style']], 'gutenverse');

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame('.guten-button { color: red; }', $snapshot->pluginStyles()['gutenverse-frontend-button-style']['css']);
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Tests a theme file outside assets/ is copied and the HTML points at it.
   */
  public function testThemeFileOutsideAssetsIsCopiedAndRewritten(): void {
    [$theme, $out] = $this->themeFilesDirectories(['inc/patterns/images/a.png', 'inc/patterns/images/notes.txt']);
    $html = '<img src="https://wp.test/theme/inc/patterns/images/a.png?v=1" srcset="https://wp.test/theme/inc/patterns/images/a.png 2x">'
      . '<div style="background:url(https://wp.test/theme/inc/patterns/images/a.png)"></div>'
      . '<a href="https://wp.test/theme/inc/patterns/images/notes.txt">n</a><img src="https://wp.test/theme/missing/b.png">'
      . '<img src="https://wp.test/theme/../outside.png">';
    $assets = [];

    $rewritten = wordpal_theme_file_references($html, 'https://wp.test/theme', $theme, $out, '/themes/custom/fixture', $assets);

    self::assertSame(['assets/_theme/inc/patterns/images/a.png'], array_values(array_unique($assets)));
    self::assertFileExists("$out/assets/_theme/inc/patterns/images/a.png");
    self::assertStringContainsString('<img src="/themes/custom/fixture/assets/_theme/inc/patterns/images/a.png?v=1" srcset="/themes/custom/fixture/assets/_theme/inc/patterns/images/a.png 2x">', $rewritten);
    self::assertStringContainsString('url(/themes/custom/fixture/assets/_theme/inc/patterns/images/a.png)', $rewritten);
    self::assertStringContainsString('href="/themes/custom/fixture/inc/patterns/images/notes.txt"', $rewritten);
    self::assertStringContainsString('src="/themes/custom/fixture/missing/b.png"', $rewritten);
    self::assertStringContainsString('src="/themes/custom/fixture/../outside.png"', $rewritten);
    self::assertFileDoesNotExist("$out/assets/_theme/inc/patterns/images/notes.txt");
    self::assertFileDoesNotExist("$out/assets/_theme/missing/b.png");
    (new Filesystem())->remove([dirname($theme)]);
  }

  /**
   * Tests a file under assets/ keeps its path.
   */
  public function testAssetsDirectoryFileKeepsItsPath(): void {
    [$theme, $out] = $this->themeFilesDirectories(['assets/images/b.jpg']);
    $assets = [];

    $rewritten = wordpal_theme_file_references('<img src="https://wp.test/theme/assets/images/b.jpg">', 'https://wp.test/theme', $theme, $out, '/themes/custom/fixture', $assets);

    self::assertSame(['assets/images/b.jpg'], $assets);
    self::assertSame('<img src="/themes/custom/fixture/assets/images/b.jpg">', $rewritten);
    self::assertFileExists("$out/assets/images/b.jpg");
    (new Filesystem())->remove([dirname($theme)]);
  }

  /**
   * Tests a file name outside the snapshot path shape keeps the plain replace.
   */
  public function testThemeFileWithUnsafeNameIsNotListed(): void {
    [$theme, $out] = $this->themeFilesDirectories(['inc/my pic.png', 'inc/a+b.png']);
    $assets = [];
    $html = '<img src="https://wp.test/theme/inc/my%20pic.png"><img src="https://wp.test/theme/inc/a+b.png">';

    $rewritten = wordpal_theme_file_references($html, 'https://wp.test/theme', $theme, $out, '/themes/custom/fixture', $assets);

    self::assertSame([], $assets);
    self::assertSame('<img src="/themes/custom/fixture/inc/my%20pic.png"><img src="/themes/custom/fixture/inc/a+b.png">', $rewritten);
    self::assertFileDoesNotExist("$out/assets/_theme/inc/my%20pic.png");
    (new Filesystem())->remove([dirname($theme)]);
  }

  /**
   * Tests the snapshot path comes from the resolved file, not the URL text.
   */
  public function testThemeFilePathIsDerivedFromTheResolvedFile(): void {
    [$theme, $out] = $this->themeFilesDirectories(['assets/x.png', 'inc/deep/y.png']);
    symlink("$theme/inc/deep", "$theme/shortcut");
    $assets = [];
    $html = '<img src="https://wp.test/theme/inc/../assets/x.png"><img src="https://wp.test/theme/shortcut/../deep/y.png">';

    $rewritten = wordpal_theme_file_references($html, 'https://wp.test/theme', $theme, $out, '/themes/custom/fixture', $assets);

    self::assertSame(['assets/x.png', 'assets/_theme/inc/deep/y.png'], $assets);
    self::assertSame('<img src="/themes/custom/fixture/assets/x.png"><img src="/themes/custom/fixture/assets/_theme/inc/deep/y.png">', $rewritten);
    self::assertFileExists("$out/assets/x.png");
    self::assertDirectoryDoesNotExist(dirname($out) . '/_theme');
    (new Filesystem())->remove([dirname($theme)]);
  }

  /**
   * Tests has() reports no file whose name escapes the snapshot.
   */
  public function testHasRefusesAnEscapingPath(): void {
    $directory = sys_get_temp_dir() . '/wordpal-has-' . bin2hex(random_bytes(6));
    mkdir("$directory/snap", 0777, TRUE);
    file_put_contents("$directory/outside.txt", 'x');
    file_put_contents("$directory/snap/inside.txt", 'x');
    $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', "$directory/snap");

    try {
      self::assertTrue($snapshot->has('inside.txt'));
      $this->expectException(\UnexpectedValueException::class);
      $snapshot->has('../outside.txt');
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Creates a theme directory holding $files and an empty snapshot output.
   *
   * @return array{0: string, 1: string}
   *   The theme directory and the output directory.
   */
  private function themeFilesDirectories(array $files): array {
    require_once dirname(__DIR__, 3) . '/src/WordPress/playground/theme-files.php';
    if (!defined('WORDPAL_ASSET_EXTENSIONS')) {
      define('WORDPAL_ASSET_EXTENSIONS', ['png', 'jpg', 'svg']);
    }
    if (!defined('WORDPAL_SAFE_PATH')) {
      define('WORDPAL_SAFE_PATH', PathValidator::SAFE_PATH);
    }
    $root = sys_get_temp_dir() . '/wordpal-theme-files-' . bin2hex(random_bytes(6));
    foreach ($files as $file) {
      (new Filesystem())->dumpFile("$root/theme/$file", 'bytes');
    }
    file_put_contents("$root/outside.png", 'bytes');
    mkdir("$root/out", 0777, TRUE);
    return ["$root/theme", "$root/out"];
  }

  /**
   * Tests plugin-styles.json and block-styles.json refuse a bad entry.
   */
  #[DataProvider('invalidPluginStylesProvider')]
  public function testRejectsInvalidPluginStyles(array $pluginStyles, array $blockStyles, string $message): void {
    $directory = $this->pluginStyleSnapshot($pluginStyles, $blockStyles);

    try {
      $this->expectException(\UnexpectedValueException::class);
      $this->expectExceptionMessage($message);
      (new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory))->blockStyles();
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Provides plugin and block style entries the snapshot refuses.
   */
  public static function invalidPluginStylesProvider(): array {
    $style = [
      'path' => 'styles/plugin-wc-blocks-style.css',
      'plugin' => 'woocommerce',
      'dependencies' => [],
    ];
    return [
      'path other than the handle' => [
        ['wc-blocks-style' => ['path' => 'styles/plugin-other.css'] + $style],
        [],
        'Snapshot plugin-styles.json has an invalid entry for wc-blocks-style.',
      ],
      'plugin not installed' => [
        ['wc-blocks-style' => ['plugin' => 'other'] + $style],
        [],
        'Snapshot plugin-styles.json has an invalid entry for wc-blocks-style.',
      ],
      'unknown dependency' => [
        ['wc-blocks-style' => ['dependencies' => ['missing']] + $style],
        [],
        'Snapshot plugin-styles.json has an invalid entry for wc-blocks-style.',
      ],
      'key not a hash' => [
        ['wc-blocks-style' => $style],
        ['../header' => ['wc-blocks-style']],
        'Snapshot block-styles.json has an invalid entry for ../header.',
      ],
      'unknown handle' => [
        ['wc-blocks-style' => $style],
        [FrozenBlock::key('') => ['missing']],
        'Snapshot block-styles.json has an invalid entry for ' . FrozenBlock::key('') . '.',
      ],
    ];
  }

  /**
   * Writes a snapshot directory with plugin stylesheets.
   */
  private function pluginStyleSnapshot(array $pluginStyles, array $blockStyles, string $plugin = 'woocommerce'): string {
    $directory = sys_get_temp_dir() . '/wordpal-plugin-styles-' . bin2hex(random_bytes(6));
    mkdir($directory . '/styles', 0777, TRUE);
    foreach ($pluginStyles as $style) {
      file_put_contents($directory . '/' . $style['path'], $style['css'] ?? '.a {}');
    }
    file_put_contents($directory . '/plugin-styles.json', json_encode((object) $pluginStyles, JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/block-styles.json', json_encode((object) $blockStyles, JSON_THROW_ON_ERROR));
    self::writePluginHeaders($directory, [$plugin => []]);
    return $directory;
  }

  /**
   * Writes a metadata.json holding only the plugin headers.
   *
   * @param string $directory
   *   The snapshot directory.
   * @param array<string, array<string, string>> $plugins
   *   Headers to override, keyed by plugin directory.
   * @param array<string, null> $sources
   *   The source entries, such as "plugin:woocommerce" => NULL.
   */
  private static function writePluginHeaders(string $directory, array $plugins, array $sources = []): void {
    $headers = array_map(static fn (array $headers): array => $headers + [
      'name' => 'WooCommerce',
      'version' => '9.3.1',
      'plugin_uri' => '',
      'author' => '',
      'author_uri' => '',
      'license' => 'GPLv3',
      'license_uri' => '',
    ], $plugins);
    $metadata = ['plugin_headers' => (object) $headers, 'sources' => (object) $sources];
    file_put_contents($directory . '/metadata.json', json_encode($metadata, JSON_THROW_ON_ERROR));
  }

  /**
   * Tests scripts.json is read in order and refuses a path of another name.
   */
  public function testReadsThemeScripts(): void {
    $directory = sys_get_temp_dir() . '/wordpal-scripts-' . bin2hex(random_bytes(6));
    mkdir($directory . '/scripts', 0777, TRUE);
    file_put_contents($directory . '/scripts/theme-main.js', 'main();');
    $script = [
      'handle' => 'main',
      'path' => 'scripts/theme-main.js',
      'dependencies' => ['jquery'],
      'footer' => TRUE,
      'strategy' => 'defer',
    ];
    file_put_contents($directory . '/scripts.json', json_encode([$script], JSON_THROW_ON_ERROR));

    try {
      $snapshot = new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $directory);
      self::assertSame([$script + ['js' => 'main();']], $snapshot->themeScripts());
      file_put_contents($directory . '/scripts.json', json_encode([['path' => 'scripts/theme-other.js'] + $script], JSON_THROW_ON_ERROR));
      $this->expectException(\UnexpectedValueException::class);
      $this->expectExceptionMessage('Snapshot scripts.json lists an invalid script.');
      $snapshot->themeScripts();
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

}
