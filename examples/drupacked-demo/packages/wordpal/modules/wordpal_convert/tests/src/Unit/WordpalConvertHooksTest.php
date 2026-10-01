<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Component\ComponentDefinitionAlter;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the extension points wordpal_convert implements on its own hooks.
 */
#[Group('wordpal')]
final class WordpalConvertHooksTest extends UnitTestCase {

  /**
   * Loads the hook implementation under test and the component set.
   */
  protected function setUp(): void {
    parent::setUp();
    $wordpalRoot = dirname(__DIR__, 5);
    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->with('wordpal')->willReturn(basename($wordpalRoot));
    $container = new ContainerBuilder();
    $container->set(ComponentSet::class, new ComponentSet(dirname($wordpalRoot), $moduleList));
    \Drupal::setContainer($container);
    require_once dirname(__DIR__, 3) . '/wordpal_convert.module';
  }

  /**
   * Tests that the component-definition hook overlays a runtime-prop block.
   */
  public function testComponentDefinitionAlterOverlaysRuntimeProps(): void {
    self::assertArrayHasKey('post-title', ComponentDefinitionAlter::RUNTIME_PROPS);
    $definition = [
      'props' => [
        'type' => 'object',
        'properties' => ['align' => ['title' => 'Align', 'type' => 'string', 'x-wordpal-panel' => 'settings']],
      ],
    ];

    wordpal_convert_wordpal_component_definition_alter($definition, 'post-title');

    self::assertArrayHasKey('title', $definition['props']['properties']);
    self::assertArrayHasKey('url', $definition['props']['properties']);
  }

  /**
   * Tests that the component-definition hook skips a block with no overlay.
   */
  public function testComponentDefinitionAlterSkipsUnknownBlocks(): void {
    $definition = ['props' => ['type' => 'object', 'properties' => []]];

    wordpal_convert_wordpal_component_definition_alter($definition, 'paragraph');

    self::assertSame(['props' => ['type' => 'object', 'properties' => []]], $definition);
  }

}
