<?php

declare(strict_types=1);

namespace Drupal\wordpal_canvas;

use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Query\QuerySettingsWriter;

/**
 * Returns the row a Canvas Query View renders its cards with.
 */
final class QueryRow {

  /**
   * Returns the row plugin value for a Query on a route.
   *
   * A search result row lists posts and pages in the card view mode, and
   * nodes of other bundles the index holds in their default view mode.
   */
  public static function forRoute(?string $route, string $viewMode, ContentMapping $mapping): array {
    if ($route !== 'search') {
      return ['type' => 'entity:node', 'options' => ['view_mode' => $viewMode]];
    }
    return [
      'type' => 'search_api',
      'options' => ['view_modes' => ['entity:node' => array_fill_keys(array_values(QuerySettingsWriter::cardConcepts($mapping, 'search')), $viewMode)]],
    ];
  }

}
