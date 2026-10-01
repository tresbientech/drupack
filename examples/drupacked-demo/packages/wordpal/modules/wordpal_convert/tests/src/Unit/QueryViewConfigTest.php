<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Query\QueryViewConfig;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\QueryLoop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the View configuration a Query saves, for either target.
 */
#[CoversClass(QueryViewConfig::class)]
#[Group('wordpal')]
final class QueryViewConfigTest extends UnitTestCase {

  /**
   * The Query settings each captured fixture was built from.
   *
   * Values: per page, offset, order, exclude sticky, pager element, empty
   * pattern id, route.
   */
  private const QUERY_CASES = [
    'plain' => [3, 0, 'DESC', FALSE, 0, NULL, NULL],
    'sticky_offset_empty' => [5, 2, 'ASC', TRUE, 1, 'empty_x', NULL],
    'archive' => [3, 0, 'DESC', FALSE, 0, 'empty_x', 'archive'],
    'search' => [3, 0, 'DESC', FALSE, 0, 'empty_x', 'search'],
  ];

  /**
   * The route and term patterns each captured template fixture was built from.
   */
  private const TEMPLATE_CASES = [
    'archive_terms' => ['archive', ['tags' => 'wordpal_fixture_tag_template']],
    'archive' => ['archive', []],
    'search' => ['search', []],
  ];

  /**
   * Each target's pattern area plugin id.
   */
  private const AREA_PLUGINS = [
    'canvas' => 'wordpal_pattern',
    'display_builder' => 'wordpal_pattern_preset',
  ];

  /**
   * Returns a mapping binding post, page, tag, category and search.
   */
  private function mapping(): ContentMapping {
    return new ContentMapping([
      'post' => 'wordpal_post',
      'page' => 'wordpal_page',
      'tag' => ['vocabulary' => 'tags', 'field' => 'field_tags'],
      'category' => ['vocabulary' => 'category', 'field' => 'field_category'],
      'search' => ['path' => '/search', 'parameter' => 'keywords', 'index' => 'content'],
    ]);
  }

  /**
   * Returns a Query loop with the given paging, order and sticky settings.
   */
  private static function loop(int $perPage, int $offset, string $order, bool $excludeSticky, int $pagerElement, int $pages = 0, string $orderBy = 'date'): QueryLoop {
    $postTemplate = new BlockNode('core/post-template', [], '', []);
    return new QueryLoop(new BlockNode('core/query', [], '', [$postTemplate]), $postTemplate, NULL, NULL, $perPage, $offset, $order, $excludeSticky, FALSE, $pagerElement, $pages, $orderBy);
  }

  /**
   * Returns a Canvas-style Query View for the given settings.
   */
  private function canvasView(string $bundle, int $perPage, int $offset, bool $excludeSticky, int $pagerElement, ?string $emptyPatternId, ?string $route): array {
    $row = ['type' => 'entity:node', 'options' => ['view_mode' => 'card_1']];
    return QueryViewConfig::queryView('wordpal_view', 'Home query', $bundle, self::loop($perPage, $offset, 'desc', $excludeSticky, $pagerElement), $route, $this->mapping(), $row, 'wordpal_pattern', $emptyPatternId, []);
  }

  /**
   * Returns each captured fixture with the inputs that built it.
   */
  public static function queryFixtures(): \Generator {
    foreach (self::AREA_PLUGINS as $target => $areaPlugin) {
      foreach (self::QUERY_CASES as $case => $settings) {
        yield "$target $case" => [$target, $case, $areaPlugin, ...$settings];
      }
    }
  }

  /**
   * Tests the shared builder reproduces the arrays each target saved before.
   *
   * The fixtures hold each target's saved arrays, key order included. The
   * row and extenders are the target's own, passed through as given.
   */
  #[DataProvider('queryFixtures')]
  public function testQueryViewReproducesTheCapturedArray(string $target, string $case, string $areaPlugin, int $perPage, int $offset, string $order, bool $excludeSticky, int $pagerElement, ?string $emptyPatternId, ?string $route): void {
    $expected = Yaml::decode(file_get_contents(__DIR__ . "/../../fixtures/query_views/query_{$target}_$case.yml"));
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'page' => 'wordpal_page',
      'search' => ['path' => '/search', 'parameter' => 'keywords', 'index' => 'wordpal_content'],
    ]);
    $default = $expected['display']['default']['display_options'];

    $values = QueryViewConfig::queryView('wordpal_fixture_view_x', 'Owner query', 'wordpal_post', self::loop($perPage, $offset, $order, $excludeSticky, $pagerElement), $route, $mapping, $default['row'], $areaPlugin, $emptyPatternId, $default['display_extenders'] ?? []);

    self::assertSame($expected, $values);
  }

  /**
   * Returns each captured template fixture with the inputs that built it.
   */
  public static function templateFixtures(): \Generator {
    foreach (self::AREA_PLUGINS as $target => $areaPlugin) {
      foreach (self::TEMPLATE_CASES as $case => [$route, $termPatterns]) {
        yield "$target $case" => [$target, $case, $areaPlugin, $route, $termPatterns];
      }
    }
  }

  /**
   * Tests the shared builder reproduces each target's template View.
   */
  #[DataProvider('templateFixtures')]
  public function testTemplateViewReproducesTheCapturedArray(string $target, string $case, string $areaPlugin, string $route, array $termPatterns): void {
    $expected = Yaml::decode(file_get_contents(__DIR__ . "/../../fixtures/query_views/template_{$target}_$case.yml"));
    $search = ['path' => '/search', 'parameter' => 'keywords', 'index' => 'wordpal_content'];
    $mapping = new ContentMapping(['search' => $search]);

    $values = QueryViewConfig::templateView("wordpal_fixture_$route", $route, 'Archive', 'wordpal_fixture_template', $mapping, $termPatterns, $areaPlugin, $expected['display']['default']['display_options']['display_extenders'] ?? []);

    self::assertSame($expected, $values);
  }

  /**
   * Tests the archive listing Template View takes over the term path.
   */
  public function testTemplateViewValuesArchiveTakesOverTermPath(): void {
    $values = QueryViewConfig::templateView('wordpal_archive', 'archive', 'Archive', 'archive_pattern', $this->mapping(), [], 'wordpal_pattern', []);

    self::assertSame('taxonomy/term/%', $values['display']['page_1']['display_options']['path']);
    self::assertSame('archive_pattern', $values['display']['default']['display_options']['header']['wordpal_pattern']['pattern_id']);
    self::assertSame([], $values['display']['default']['display_options']['header']['wordpal_pattern']['vocabularies']);
  }

  /**
   * Tests a term Template's Pattern renders for its vocabulary only.
   */
  public function testTemplateViewValuesArchiveHeaderExcludesClaimedVocabularies(): void {
    $values = QueryViewConfig::templateView('wordpal_archive', 'archive', 'Archive', 'archive_pattern', $this->mapping(), ['tags' => 'tag_pattern'], 'wordpal_pattern', []);

    self::assertSame('tag_pattern', $values['display']['default']['display_options']['header']['wordpal_pattern_tags']['pattern_id']);
    self::assertSame(['tags'], $values['display']['default']['display_options']['header']['wordpal_pattern_tags']['vocabularies']);
    self::assertSame(['tags'], $values['display']['default']['display_options']['header']['wordpal_pattern']['vocabularies']);
    self::assertTrue($values['display']['default']['display_options']['header']['wordpal_pattern']['negate']);
  }

  /**
   * Tests the search listing Template View uses the mapped search path.
   */
  public function testTemplateViewValuesSearchUsesMappedPath(): void {
    $values = QueryViewConfig::templateView('wordpal_search', 'search', 'Search', 'search_pattern', $this->mapping(), [], 'wordpal_pattern', []);

    self::assertSame('search', $values['display']['page_1']['display_options']['path']);
    self::assertSame('node_field_data', $values['base_table']);
    self::assertArrayNotHasKey('arguments', $values['display']['default']['display_options']);
  }

  /**
   * Tests the base Query View's pager, row and filter values.
   */
  public function testViewValuesBuildsPagerAndFilters(): void {
    $values = $this->canvasView('wordpal_post', 3, 6, FALSE, 2, NULL, NULL);

    self::assertSame('wordpal_view', $values['id']);
    self::assertSame('Home query', $values['label']);
    self::assertSame('node_field_data', $values['base_table']);
    $pager = $values['display']['default']['display_options']['pager'];
    self::assertSame(3, $pager['options']['items_per_page']);
    self::assertSame(6, $pager['options']['offset']);
    self::assertSame(2, $pager['options']['id']);
    self::assertSame('card_1', $values['display']['default']['display_options']['row']['options']['view_mode']);
    self::assertSame(['wordpal_post' => 'wordpal_post'], $values['display']['default']['display_options']['filters']['type']['value']);
    self::assertArrayNotHasKey('sticky', $values['display']['default']['display_options']['filters']);
    self::assertArrayNotHasKey('arguments', $values['display']['default']['display_options'], 'A Query with no route takes no term argument.');
    self::assertSame([], $values['display']['default']['display_options']['empty'], 'No empty Pattern id means no empty area.');
  }

  /**
   * Tests a page cap and a title order reach the pager and the sort.
   */
  public function testViewValuesCapsPagesAndSortsByTitle(): void {
    $values = QueryViewConfig::queryView('wordpal_view', 'Home query', 'wordpal_post', self::loop(3, 0, 'ASC', FALSE, 1, 3, 'title'), NULL, $this->mapping(), [], 'wordpal_pattern', NULL, []);
    $options = $values['display']['default']['display_options'];

    self::assertSame(3, $options['pager']['options']['total_pages']);
    self::assertSame(['title'], array_keys($options['sorts']));
    self::assertSame('title', $options['sorts']['title']['field']);
    self::assertSame('ASC', $options['sorts']['title']['order']);
  }

  /**
   * Tests an empty Pattern id becomes the View's empty area.
   */
  public function testViewValuesAddsEmptyPatternArea(): void {
    $values = $this->canvasView('wordpal_post', 3, 0, FALSE, 0, 'wordpal_empty', NULL);

    self::assertSame(
      'wordpal_empty',
      $values['display']['default']['display_options']['empty']['wordpal_pattern']['pattern_id'],
    );
  }

  /**
   * Tests excludeSticky adds the sticky filter, and leaves it out otherwise.
   */
  public function testViewValuesExcludeStickyAddsFilter(): void {
    $values = $this->canvasView('wordpal_post', 3, 0, TRUE, 0, NULL, NULL);

    self::assertSame('0', $values['display']['default']['display_options']['filters']['sticky']['value']);
  }

  /**
   * Tests the archive route adds the inheriting term argument.
   */
  public function testViewValuesArchiveRouteAddsTermArgument(): void {
    $values = $this->canvasView('wordpal_post', 3, 0, FALSE, 0, NULL, 'archive');

    $argument = $values['display']['default']['display_options']['arguments']['tid'];
    self::assertSame('taxonomy_tid', $argument['default_argument_type']);
    self::assertSame('not found', $argument['validate']['fail'], 'A term the visitor cannot view is not found.');
    self::assertTrue($argument['validate_options']['access']);
  }

  /**
   * Tests the search route rewrites the View onto the mapped search index.
   */
  public function testViewValuesSearchRouteUsesSearchIndex(): void {
    $values = $this->canvasView('wordpal_post', 3, 0, FALSE, 0, NULL, 'search');

    self::assertSame('search_api_index_content', $values['base_table']);
    self::assertTrue($values['display']['block_1']['display_options']['exposed_block']);
    self::assertSame('keywords', $values['display']['default']['display_options']['filters']['search_api_fulltext']['expose']['identifier']);
  }

  /**
   * Tests the search route keeps the row the target passed.
   */
  public function testSearchRouteKeepsTheGivenRow(): void {
    $row = ['type' => 'search_api', 'options' => ['view_modes' => ['entity:node' => ['wordpal_post' => 'card_1']]]];

    $values = QueryViewConfig::queryView('id', 'label', 'wordpal_post', self::loop(3, 0, 'desc', FALSE, 0), 'search', $this->mapping(), $row, 'wordpal_pattern', NULL, []);

    self::assertSame($row, $values['display']['default']['display_options']['row']);
  }

  /**
   * Tests extenders go on the default and block displays, and none are kept.
   */
  public function testDisplayExtendersGoOnBothDisplays(): void {
    $extenders = ['display_builder' => ['profile' => 'default', 'sources' => []]];
    $loop = self::loop(3, 0, 'desc', FALSE, 0);

    $with = QueryViewConfig::queryView('id', 'label', 'wordpal_post', $loop, NULL, $this->mapping(), [], 'wordpal_pattern_preset', 'empty_x', $extenders);
    $without = QueryViewConfig::queryView('id', 'label', 'wordpal_post', $loop, NULL, $this->mapping(), [], 'wordpal_pattern', 'empty_x', []);

    self::assertSame($extenders, $with['display']['default']['display_options']['display_extenders']);
    self::assertSame($extenders, $with['display']['block_1']['display_options']['display_extenders']);
    self::assertSame('wordpal_pattern_preset', $with['display']['default']['display_options']['empty']['wordpal_pattern_preset']['plugin_id']);
    self::assertArrayNotHasKey('display_extenders', $without['display']['default']['display_options']);
    self::assertArrayNotHasKey('display_extenders', $without['display']['block_1']['display_options']);
  }

}
