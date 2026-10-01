<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\system\Entity\Menu;
use Drupal\Tests\wordpal\Traits\ThemeSettingsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Interactivity libraries and their attachment.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class InteractivityTest extends KernelTestBase {

  use ThemeSettingsTrait;

  /**
   * Modules needed for menu rendering.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'link',
    'node',
    'menu_link_content',
    'comment',
    'views',
    'text',
    'wordpal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->writeThemeSettings();
  }

  /**
   * Tests that the navigation library loads a module that reaches the runtime.
   */
  public function testLibraryLoadsModuleThatResolvesTheRuntime(): void {
    $discovery = $this->container->get('library.discovery');
    $root = $this->container->getParameter('app.root');
    $library = $discovery->getLibraryByName('wordpal', 'interactivity.navigation');
    self::assertCount(1, $library['js']);
    $js = $library['js'][0];
    self::assertSame('module', $js['attributes']['type']);
    self::assertFalse($js['preprocess']);
    $file = "$root/{$js['data']}";
    self::assertFileExists($file);
    self::assertSame(1, preg_match('/from "([^"?]+)\?ver=[0-9.]+"/', (string) file_get_contents($file), $import));
    self::assertFileExists(dirname($file) . '/' . $import[1]);
  }

  /**
   * Tests that a responsive menu attaches its view module and no state.
   */
  #[DataProvider('overlayMenus')]
  public function testNavigationAttachesViewModule(string $overlayMenu, bool $interactive): void {
    $this->installEntitySchema('menu_link_content');
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    $block = $this->container->get('plugin.manager.block')->createInstance(
      'wordpal_navigation',
      ['menu' => 'wordpal_main', 'overlay_menu' => $overlayMenu],
    );
    $renderer = $this->container->get('renderer');
    $keys = ['wordpal_navigation_test', $overlayMenu];
    $miss = $block->build() + ['#cache' => []];
    $miss['#cache']['keys'] = $keys;
    $html = (string) $renderer->renderRoot($miss);
    self::assertNotFalse($this->container->get('render_cache')->get($miss));
    // A render-cache hit returns the stored attachments.
    $hit = ['#cache' => ['keys' => $keys] + $miss['#cache']];
    self::assertSame($html, (string) $renderer->renderRoot($hit));

    foreach ([$miss, $hit] as $build) {
      $libraries = $build['#attached']['library'] ?? [];
      self::assertSame($interactive, in_array('wordpal/interactivity.navigation', $libraries, TRUE));
      // WordPress's navigation block keeps its state in data-wp-context and
      // calls no wp_interactivity_state(), so the page prints no state script.
      // A layout rule is the only head element the navigation can add.
      foreach ($build['#attached']['html_head'] ?? [] as [$element]) {
        self::assertSame('style', $element['#tag']);
      }
    }
    self::assertSame($interactive, str_contains($html, 'data-wp-interactive="core/navigation"'));
  }

  /**
   * Provides overlay settings and whether the menu is interactive.
   */
  public static function overlayMenus(): array {
    return [
      'mobile' => ['mobile', TRUE],
      'always' => ['always', TRUE],
      'never' => ['never', FALSE],
    ];
  }

}
