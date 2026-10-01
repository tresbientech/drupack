<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Asset\LibraryDependencyResolver;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\system\Form\ThemeSettingsForm;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal_convert\WordPress\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the generated theme's style variation setting on rendered pages.
 *
 * The theme is generated into the Drupal root's themes directory, where
 * extension discovery finds it, and removed after the test.
 */
#[CoversClass(ThemeGenerator::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class StyleVariationTest extends KernelTestBase {

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
   * Generates and installs a theme with two style variations.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('user');
    $this->snapshotDirectory = sys_get_temp_dir() . '/wordpal-variation-' . bin2hex(random_bytes(6));
    $files = [
      'styles/core-blocks.css' => '.wp-block-button {}',
      'styles/block-styles.css' => '.is-style-outline {}',
      'styles/global.css' => ':root { --wp--preset--color--base: #f9f9f9; }',
      'styles/variation-ember.css' => ':root { --wp--preset--color--base: #1b1031; }',
      'styles.json' => json_encode(['styles/core-blocks.css', 'styles/block-styles.css']),
      'assets.json' => '[]',
      'plugin-styles.json' => '{}',
      'block-styles.json' => '{}',
      'scripts.json' => '[]',
      'presets.json' => json_encode(array_fill_keys(['color', 'gradient', 'font-size', 'font-family', 'spacing'], [])),
      'variations.json' => json_encode([
        ['slug' => 'default', 'title' => 'Default', 'path' => 'styles/global.css'],
        ['slug' => 'ember', 'title' => 'Ember', 'path' => 'styles/variation-ember.css'],
      ]),
    ];
    (new Filesystem())->mkdir($this->snapshotDirectory . '/styles');
    foreach ($files as $path => $contents) {
      file_put_contents("$this->snapshotDirectory/$path", $contents);
    }
    $slug = 'wordpal-variation-' . bin2hex(random_bytes(4));
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
    $snapshot = new Snapshot($slug, 'Variation test', '1.0', '7.1.1', $this->snapshotDirectory);
    $this->themeId = (new ThemeGenerator($this->root, $this->container->get('module_handler')))->generate($snapshot, []);

    // Extension discovery keeps each directory scan for the whole process.
    (new \ReflectionProperty(ExtensionDiscovery::class, 'files'))->setValue(NULL, []);
    $this->container->get('theme_installer')->install([$this->themeId]);
    // A conversion writes the settings the theme's body classes read.
    $this->config(ThemeSettings::configName($this->themeId))->set('body', [
      'classes' => [],
      'bundles' => [],
      'vocabularies' => [],
      'search' => NULL,
    ])->save();
    $this->config('system.theme')->set('default', $this->themeId)->save();
    $this->config('system.performance')->set('css.preprocess', FALSE)->save();
  }

  /**
   * Removes the generated theme and the snapshot.
   */
  protected function tearDown(): void {
    (new Filesystem())->remove([$this->snapshotDirectory, "$this->root/themes/custom/$this->themeId"]);
    parent::tearDown();
  }

  /**
   * Tests the settings form switches the one Global stylesheet pages load.
   *
   * The Canvas editor preview loads the active theme's info.yml libraries
   * without page attachments, so those switch too.
   */
  public function testSettingSwitchesVariation(): void {
    self::assertSame(['core-blocks', 'global', 'block-styles'], $this->stylesheets());
    self::assertSame(['core-blocks', 'global', 'block-styles'], $this->previewStylesheets());
    $this->endRequest();

    $formState = (new FormState())->setValues(['style_variation' => 'ember']);
    $this->container->get('form_builder')->submitForm(ThemeSettingsForm::class, $formState, $this->themeId);
    self::assertSame([], $formState->getErrors());
    $this->endRequest();

    self::assertSame(['core-blocks', 'variation-ember', 'block-styles'], $this->stylesheets());
    self::assertSame(['core-blocks', 'variation-ember', 'block-styles'], $this->previewStylesheets());
    $form = $this->container->get('form_builder')->getForm(ThemeSettingsForm::class, $this->themeId);
    self::assertSame(['default' => 'Default', 'ember' => 'Ember'], $form['style_variation']['#options']);
    self::assertSame('ember', $form['style_variation']['#default_value']);
  }

  /**
   * Stores the library definitions and drops what this request resolved.
   *
   * The next request reads the definitions from the cache, so a switch
   * shows only when the save invalidated them.
   */
  private function endRequest(): void {
    $discovery = $this->container->get('library.discovery');
    $discovery->destruct();
    $discovery->reset();
    $resolver = $this->container->get('library.dependency_resolver');
    (new \ReflectionProperty(LibraryDependencyResolver::class, 'librariesDependencies'))->setValue($resolver, []);
  }

  /**
   * Returns the theme stylesheets of the Canvas editor preview, in order.
   *
   * @see \Drupal\canvas\Controller\CanvasController::__invoke()
   */
  private function previewStylesheets(): array {
    $libraries = ['system/base', ...$this->container->get('theme.manager')->getActiveTheme()->getLibraries()];
    $css = $this->container->get('asset.resolver')->getCssAssets((new AttachedAssets())->setLibraries($libraries), FALSE);
    $names = [];
    foreach (array_keys($css) as $path) {
      if (preg_match("#^themes/custom/$this->themeId/styles/([a-z0-9-]+)\\.css$#", $path, $match)) {
        $names[] = $match[1];
      }
    }
    return $names;
  }

  /**
   * Returns the theme stylesheets the login page links, in page order.
   */
  private function stylesheets(): array {
    $response = $this->container->get('http_kernel')->handle(Request::create('/user/login'));
    self::assertSame(200, $response->getStatusCode());
    preg_match_all("#/themes/custom/$this->themeId/styles/([a-z0-9-]+)\\.css#", (string) $response->getContent(), $matches);
    return $matches[1];
  }

}
