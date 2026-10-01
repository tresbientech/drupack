<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas_runtime\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Pattern area survives the serialization the form cache applies.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class PatternAreaSerializationTest extends KernelTestBase {

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
    'wordpal_canvas_runtime',
  ];

  /**
   * Tests the injected entity type manager is restored.
   */
  public function testPatternAreaRestoresInjectedServices(): void {
    $area = $this->container->get('plugin.manager.views.area')->createInstance('wordpal_pattern');
    // The input is the plugin this test just serialized.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize
    $copy = unserialize(serialize($area));
    self::assertTrue((new \ReflectionProperty($copy, 'entityTypeManager'))->isInitialized($copy));
  }

}
