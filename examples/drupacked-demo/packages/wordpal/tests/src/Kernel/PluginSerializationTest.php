<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests WordPal plugins survive the serialization the form cache applies.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class PluginSerializationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'filter',
    'text',
    'node',
    'taxonomy',
    'comment',
    'menu_link_content',
    'link',
    'views',
    'wordpal',
  ];

  /**
   * Tests every injected property of every WordPal block is restored.
   */
  public function testBlockPluginsRestoreInjectedServices(): void {
    $manager = $this->container->get('plugin.manager.block');
    $checked = 0;
    foreach ($manager->getDefinitions() as $id => $definition) {
      if ($definition['provider'] !== 'wordpal') {
        continue;
      }
      $copy = self::roundTrip($manager->createInstance($id));
      foreach ((new \ReflectionClass($copy))->getConstructor()->getParameters() as $parameter) {
        if (!$parameter->isPromoted()) {
          continue;
        }
        $property = new \ReflectionProperty($copy, $parameter->getName());
        self::assertTrue($property->isInitialized($copy), "$id keeps {$parameter->getName()} across serialization.");
        $checked++;
      }
    }
    self::assertSame(29, $checked, 'Every promoted property of the 13 blocks is covered.');
  }

  /**
   * Serializes and unserializes a plugin, as the form cache does.
   */
  private static function roundTrip(object $plugin): object {
    // The input is the plugin this test just serialized.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize
    return unserialize(serialize($plugin));
  }

}
