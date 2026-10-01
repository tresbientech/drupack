<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\wordpal_convert\Component\ComponentSet;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks that wordpal:generate-components does not depend on wordpal_canvas.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ComponentGenerationTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['comment', 'wordpal', 'wordpal_convert', 'wordpal_canvas', 'wordpal_canvas_runtime'];

  /**
   * Tests the alter stage writes the same definitions without wordpal_canvas.
   *
   * The command's only extension point is
   * hook_wordpal_component_definition_alter().
   */
  public function testDefinitionAlterIsIndependentOfCanvas(): void {
    $this->installSchema('user', ['users_data']);
    $with = $this->alteredDefinitions();
    self::assertSame(['wordpal_convert'], $this->alterModules());

    $this->container->get('module_installer')->uninstall(['wordpal_canvas_runtime', 'wordpal_canvas']);
    self::assertFalse($this->container->get('module_handler')->moduleExists('wordpal_canvas'));

    self::assertSame(['wordpal_convert'], $this->alterModules());
    self::assertSame($with, $this->alteredDefinitions());
  }

  /**
   * Returns the modules implementing the definition alter hook.
   */
  private function alterModules(): array {
    $modules = [];
    $this->container->get('module_handler')->invokeAllWith('wordpal_component_definition_alter', static function (callable $hook, string $module) use (&$modules): void {
      $modules[] = $module;
    });
    return $modules;
  }

  /**
   * Returns each checked-in definition after the alter hooks, keyed by slug.
   */
  private function alteredDefinitions(): array {
    $directory = $this->container->get(ComponentSet::class)->directory();
    $altered = [];
    foreach (glob("$directory/*/*.component.yml") as $file) {
      $slug = basename(dirname($file));
      $definition = Yaml::decode((string) file_get_contents($file));
      $this->container->get('module_handler')->alter('wordpal_component_definition', $definition, $slug);
      $altered[$slug] = $definition;
    }
    self::assertNotEmpty($altered);
    return $altered;
  }

}
