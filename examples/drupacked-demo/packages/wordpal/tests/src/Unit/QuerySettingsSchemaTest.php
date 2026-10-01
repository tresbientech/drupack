<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the Views query settings schema lists every component prop.
 *
 * The Query writer stores every prop a block sets, so a prop missing from
 * the schema fails config validation for the theme that sets it.
 */
#[Group('wordpal')]
final class QuerySettingsSchemaTest extends UnitTestCase {

  /**
   * Config schema types by component prop type.
   */
  private const TYPES = [
    'string' => 'string',
    'integer' => 'integer',
    'number' => 'float',
    'boolean' => 'boolean',
  ];

  /**
   * Tests one settings mapping against its component's props.
   */
  #[DataProvider('settings')]
  public function testMappingMirrorsComponentProps(string $key, string $component): void {
    $root = dirname(__DIR__, 3);
    $schema = Yaml::parseFile("$root/config/schema/wordpal.schema.yml");
    $type = $schema['views.view.*.third_party.wordpal']['mapping']['query']['mapping'][$key]['type'];
    $definition = Yaml::parseFile("$root/components/$component/$component.component.yml");

    $expected = [];
    foreach ($definition['props']['properties'] as $prop => $propSchema) {
      $expected[$prop] = self::TYPES[$propSchema['type']];
    }
    $actual = [];
    foreach ($schema[$type]['mapping'] as $prop => $propSchema) {
      self::assertTrue($propSchema['nullable'], "$type.$prop must be nullable.");
      $actual[$prop] = $propSchema['type'];
    }
    self::assertSame($expected, $actual);
  }

  /**
   * Supplies each settings key and the component whose inputs it stores.
   */
  public static function settings(): array {
    return [
      'query' => ['query', 'query'],
      'post_template' => ['post_template', 'post-template'],
      'pagination' => ['pagination', 'query-pagination'],
      'previous' => ['previous', 'query-pagination-previous'],
      'numbers' => ['numbers', 'query-pagination-numbers'],
      'next' => ['next', 'query-pagination-next'],
      'no_results' => ['no_results', 'query-no-results'],
    ];
  }

}
