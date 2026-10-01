<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\ComponentDefinitionAlter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests generated query component runtime props.
 */
#[CoversClass(ComponentDefinitionAlter::class)]
#[Group('wordpal')]
final class QueryComponentContractTest extends UnitTestCase {

  /**
   * Tests numbered pager data survives component regeneration.
   */
  public function testPaginationRuntimePropsSurviveGeneration(): void {
    $path = dirname(__DIR__, 5) . '/components/query-pagination-numbers/query-pagination-numbers.component.yml';
    $checkedIn = Yaml::decode((string) file_get_contents($path));
    $generated = [
      'props' => [
        'type' => 'object',
        'properties' => ['css_class' => ['title' => 'CSS class', 'type' => 'string', 'x-wordpal-panel' => 'advanced']],
      ],
    ];
    $runtime = ['current', 'previous_ellipsis', 'next_ellipsis'];

    $definition = ComponentDefinitionAlter::overlay($generated, $checkedIn, $runtime);

    self::assertSame(['current'], $definition['props']['required']);
    self::assertSame([1], $definition['props']['properties']['current']['examples']);
    self::assertArrayHasKey('css_class', $definition['props']['properties']);
  }

}
