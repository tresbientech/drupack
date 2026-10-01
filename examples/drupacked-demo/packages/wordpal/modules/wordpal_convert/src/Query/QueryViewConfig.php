<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Query;

use Drupal\wordpal\Theme\ViewTags;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\QueryLoop;

/**
 * Builds the View configuration a Query or listing Template saves.
 *
 * Canvas and Display Builder select a Query's posts the same way: access,
 * pager, filters, sort, the archive term argument and the search rewrite
 * live here once. Each target renders them its own way, so the
 * caller passes its row, its area plugin id and its display extenders.
 * Both targets' listing Template Views come from here the same way.
 */
final class QueryViewConfig {

  /**
   * Returns the generated View configuration for a Query block.
   *
   * @param string $id
   *   The View's machine name.
   * @param string $label
   *   The View's label, also the block display's description.
   * @param string $bundle
   *   The node bundle the Query lists.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop $loop
   *   The Query's paging, order and sticky settings.
   * @param string|null $route
   *   The listing route whose main query the Query shows (QueryLoop::route()).
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping a search View reads its index and parameter from.
   * @param array $row
   *   The target's complete row plugin value, placed as given on every route.
   * @param string $areaPlugin
   *   The target's pattern area plugin id, for the empty area.
   * @param string|null $emptyPatternId
   *   The no-results pattern the empty area renders, or NULL for none.
   * @param array $displayExtenders
   *   The target's display extenders for the default and block displays, or
   *   none.
   */
  public static function queryView(string $id, string $label, string $bundle, QueryLoop $loop, ?string $route, ContentMapping $mapping, array $row, string $areaPlugin, ?string $emptyPatternId, array $displayExtenders): array {
    $order = QueryLoop::ORDER_FIELDS[$loop->orderBy];
    $default = [
      'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
      'cache' => ['type' => 'tag', 'options' => []],
      'pager' => [
        'type' => 'wordpal_query',
        'options' => [
          'offset' => $loop->offset,
          'pagination_heading_level' => 'h4',
          'items_per_page' => $loop->perPage,
          'total_pages' => $loop->pages,
          'id' => $loop->pagerElement,
          'tags' => [
            'next' => 'Next',
            'previous' => 'Previous',
            'first' => 'First',
            'last' => 'Last',
          ],
          'expose' => [
            'items_per_page' => FALSE,
            'items_per_page_label' => 'Items per page',
            'items_per_page_options' => '5, 10, 25, 50',
            'items_per_page_options_all' => FALSE,
            'items_per_page_options_all_label' => '- All -',
            'offset' => FALSE,
            'offset_label' => 'Offset',
          ],
          'quantity' => 9,
        ],
      ],
      'style' => ['type' => 'default', 'options' => []],
      'row' => $row,
      'filters' => [
        'status' => [
          'id' => 'status',
          'table' => 'node_field_data',
          'field' => 'status',
          'entity_type' => 'node',
          'entity_field' => 'status',
          'plugin_id' => 'boolean',
          'value' => '1',
        ],
        'type' => [
          'id' => 'type',
          'table' => 'node_field_data',
          'field' => 'type',
          'entity_type' => 'node',
          'entity_field' => 'type',
          'plugin_id' => 'bundle',
          'value' => [$bundle => $bundle],
        ],
      ],
      'sorts' => [
        $order['field'] => [
          'id' => $order['field'],
          'table' => 'node_field_data',
          'field' => $order['field'],
          'entity_type' => 'node',
          'entity_field' => $order['field'],
          'plugin_id' => $order['plugin'],
          'order' => $loop->order,
        ],
      ],
      'empty' => $emptyPatternId === NULL ? [] : [
        $areaPlugin => [
          'id' => $areaPlugin,
          'table' => 'views',
          'field' => $areaPlugin,
          'relationship' => 'none',
          'group_type' => 'group',
          'admin_label' => '',
          'plugin_id' => $areaPlugin,
          'empty' => TRUE,
          'pattern_id' => $emptyPatternId,
          'vocabularies' => [],
          'negate' => FALSE,
        ],
      ],
    ];
    $block = [
      'block_description' => $label,
      'allow' => ['items_per_page' => TRUE],
    ];
    if ($displayExtenders !== []) {
      // Display extenders are not one of Views' defaultable options, so the
      // block display carries its own copy.
      $default['display_extenders'] = $displayExtenders;
      $block['display_extenders'] = $displayExtenders;
    }
    if ($loop->excludeSticky) {
      $default['filters']['sticky'] = [
        'id' => 'sticky',
        'table' => 'node_field_data',
        'field' => 'sticky',
        'entity_type' => 'node',
        'entity_field' => 'sticky',
        'plugin_id' => 'boolean',
        'value' => '0',
      ];
    }
    $baseTable = 'node_field_data';
    if ($route === 'archive') {
      // The inheriting Query of an archive lists the posts of the route's
      // term, as WordPress's main query does.
      $default['arguments']['tid'] = [
        'id' => 'tid',
        'table' => 'taxonomy_index',
        'field' => 'tid',
        'plugin_id' => 'taxonomy_index_tid',
        'default_action' => 'default',
        'default_argument_type' => 'taxonomy_tid',
        'default_argument_options' => [
          'term_page' => '1',
          'node' => FALSE,
          'limit' => FALSE,
          'vids' => [],
          'anyall' => ',',
        ],
        'specify_validation' => TRUE,
        'validate' => ['type' => 'entity:taxonomy_term', 'fail' => 'not found'],
        'validate_options' => ['bundles' => [], 'access' => TRUE, 'operation' => 'view', 'multiple' => 0],
      ];
    }
    elseif ($route === 'search') {
      $baseTable = self::searchSelection($default, $mapping);
      // The route's own search form submits the parameter, so the block
      // prints no exposed form of its own.
      $block['exposed_block'] = TRUE;
    }
    return [
      'id' => $id,
      'label' => $label,
      'description' => 'Posts converted from the WordPress Query block.',
      'tag' => ViewTags::QUERY,
      'base_table' => $baseTable,
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => $default,
        ],
        'block_1' => [
          'id' => 'block_1',
          'display_title' => 'Block',
          'display_plugin' => 'block',
          'position' => 1,
          'display_options' => $block,
        ],
      ],
    ];
  }

  /**
   * Returns the page View rendering one listing Template on its route.
   *
   * Its base rows only decide the route's response: an archive validates
   * the term and its view access, and a search always has its one page. The
   * header areas alone render the Template's content.
   *
   * @param string $id
   *   The View's machine name.
   * @param string $route
   *   The listing route, "archive" or "search".
   * @param string $label
   *   The route's human title, such as "Archive".
   * @param string $patternId
   *   The route's own listing Template pattern id.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param array<string, string> $termPatterns
   *   On the archive route, the patterns saved for the term pages of one
   *   vocabulary, keyed by vocabulary id.
   * @param string $areaPlugin
   *   The target's pattern area plugin id, for the header areas.
   * @param array $displayExtenders
   *   The target's display extenders for the default display, or none.
   */
  public static function templateView(string $id, string $route, string $label, string $patternId, ContentMapping $mapping, array $termPatterns, string $areaPlugin, array $displayExtenders): array {
    $archive = $route === 'archive';
    $area = [
      'table' => 'views',
      'field' => $areaPlugin,
      'relationship' => 'none',
      'group_type' => 'group',
      'admin_label' => '',
      'plugin_id' => $areaPlugin,
      'empty' => TRUE,
      'vocabularies' => [],
      'negate' => FALSE,
    ];
    $header = [];
    foreach ($termPatterns as $vocabulary => $termPatternId) {
      $header["{$areaPlugin}_$vocabulary"] = [
        'id' => "{$areaPlugin}_$vocabulary",
        'pattern_id' => $termPatternId,
        'vocabularies' => [$vocabulary],
      ] + $area;
    }
    // The route's own Template renders every term page no vocabulary
    // Template claims.
    $header[$areaPlugin] = [
      'id' => $areaPlugin,
      'pattern_id' => $patternId,
      'vocabularies' => array_keys($termPatterns),
      'negate' => $termPatterns !== [],
    ] + $area;
    $options = [
      'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
      'cache' => ['type' => 'tag', 'options' => []],
      'pager' => ['type' => 'some', 'options' => ['items_per_page' => 1, 'offset' => 0]],
      'style' => ['type' => 'default', 'options' => []],
      'row' => ['type' => 'fields', 'options' => []],
      'fields' => [],
      'header' => $header,
      'title' => $archive ? '' : 'Search',
    ];
    if ($displayExtenders !== []) {
      $options['display_extenders'] = $displayExtenders;
    }
    if ($archive) {
      $options['arguments']['tid'] = [
        'id' => 'tid',
        'table' => 'taxonomy_term_field_data',
        'field' => 'tid',
        'entity_type' => 'taxonomy_term',
        'entity_field' => 'tid',
        'plugin_id' => 'taxonomy',
        'default_action' => 'not found',
        'title_enable' => TRUE,
        'title' => '{{ arguments.tid }}',
        'specify_validation' => TRUE,
        'validate' => ['type' => 'entity:taxonomy_term', 'fail' => 'not found'],
        'validate_options' => ['bundles' => [], 'access' => TRUE, 'operation' => 'view', 'multiple' => 0],
      ];
      $options['filters']['status'] = [
        'id' => 'status',
        'table' => 'taxonomy_term_field_data',
        'field' => 'status',
        'entity_type' => 'taxonomy_term',
        'entity_field' => 'status',
        'plugin_id' => 'boolean',
        'value' => '1',
      ];
    }
    return [
      'id' => $id,
      'label' => "$label Template",
      'description' => 'The ' . $route . ' Template converted from WordPress.',
      'tag' => ViewTags::TEMPLATE,
      'base_table' => $archive ? 'taxonomy_term_field_data' : 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => $options,
        ],
        'page_1' => [
          'id' => 'page_1',
          'display_title' => 'Page',
          'display_plugin' => 'page',
          'position' => 1,
          'display_options' => [
            'path' => $archive ? 'taxonomy/term/%' : ltrim($mapping->target('search')['path'], '/'),
          ],
        ],
      ],
    ];
  }

  /**
   * Turns a Query's default display into one listing search results.
   *
   * The results come from the mapped Search API index, filtered by the
   * mapped search parameter, as the search page itself filters them. Only
   * nodes list, of every indexed bundle, as WordPress search lists every
   * public post type. The View has no node status or bundle filter: Search
   * API's query checks each result's view access for the visitor, since
   * the View leaves skip_access off.
   *
   * @return string
   *   The index's Views base table.
   */
  private static function searchSelection(array &$options, ContentMapping $mapping): string {
    $search = $mapping->target('search');
    $table = 'search_api_index_' . $search['index'];
    $options['cache'] = ['type' => 'search_api_none', 'options' => []];
    $options['query'] = ['type' => 'views_query', 'options' => []];
    $options['exposed_form'] = [
      'type' => 'basic',
      'options' => [
        'submit_button' => 'Search',
        'reset_button' => FALSE,
        'reset_button_label' => 'Reset',
        'exposed_sorts_label' => 'Sort by',
        'expose_sort_order' => FALSE,
        'sort_asc_label' => 'Asc',
        'sort_desc_label' => 'Desc',
      ],
    ];
    $options['filters'] = [
      'search_api_fulltext' => [
        'id' => 'search_api_fulltext',
        'table' => $table,
        'field' => 'search_api_fulltext',
        'plugin_id' => 'search_api_fulltext',
        'operator' => 'and',
        'value' => '',
        'exposed' => TRUE,
        'expose' => [
          'operator_id' => 'search_api_fulltext_op',
          'label' => '',
          'identifier' => $search['parameter'],
          'required' => FALSE,
          'remember' => FALSE,
          'multiple' => FALSE,
          'remember_roles' => ['authenticated' => 'authenticated'],
        ],
        'parse_mode' => 'terms',
        'fields' => [],
      ],
      'search_api_datasource' => [
        'id' => 'search_api_datasource',
        'table' => $table,
        'field' => 'search_api_datasource',
        'plugin_id' => 'search_api_datasource',
        'operator' => 'or',
        'value' => ['entity:node' => 'entity:node'],
      ],
    ];
    $options['sorts'] = [
      'search_api_relevance' => [
        'id' => 'search_api_relevance',
        'table' => $table,
        'field' => 'search_api_relevance',
        'plugin_id' => 'search_api',
        'order' => 'DESC',
      ],
    ];
    return $table;
  }

}
