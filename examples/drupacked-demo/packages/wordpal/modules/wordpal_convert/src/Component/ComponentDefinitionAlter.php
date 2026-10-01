<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

/**
 * Overlays Canvas entity-binding props onto a generated component.
 */
final class ComponentDefinitionAlter {

  /**
   * Components whose data props come from Canvas entity bindings.
   *
   * A query title's props hold the content mapping's vocabularies and
   * search page.
   */
  public const RUNTIME_PROPS = [
    'avatar' => ['author_id'],
    'cover' => ['image_width', 'image_height'],
    'post-author' => ['author_id', 'author_bio'],
    'post-author-name' => ['author_id'],
    'post-content' => ['content'],
    'post-date' => ['date', 'timestamp', 'published_timestamp', 'url'],
    'post-excerpt' => ['excerpt', 'url'],
    'post-featured-image' => ['src', 'alt', 'title', 'image_width', 'image_height', 'url'],
    'post-terms' => ['terms', 'term_urls', 'taxonomy'],
    'post-title' => ['title', 'url'],
    'query-pagination-next' => ['url', 'label'],
    'query-pagination-numbers' => ['current', 'previous_ellipsis', 'next_ellipsis'],
    'query-pagination-previous' => ['url', 'label'],
    'query-no-results' => [],
    'query-title' => [
      'tag_vocabulary', 'category_vocabulary', 'search_path', 'search_parameter', 'post_bundle', 'listing_bundle',
    ],
    'read-more' => ['url', 'title'],
  ];

  /**
   * Overlays checked-in runtime props onto a generated WordPress definition.
   *
   * Runtime props hold the post data a block shows, so they lead, ahead of
   * the Content panel. They carry no inspector panel: Canvas compares the
   * whole schema of an array prop against the shapes it can store, and an
   * extra key leaves it with no field type.
   */
  public static function overlay(array $generated, array $checkedIn, array $runtimeProps): array {
    $generated['slots'] = array_replace($generated['slots'] ?? [], $checkedIn['slots'] ?? []);
    $runtime = [];
    foreach ($runtimeProps as $prop) {
      $runtime[$prop] = $checkedIn['props']['properties'][$prop];
      unset($generated['props']['properties'][$prop], $generated['slots'][$prop]);
    }
    if (($generated['slots'] ?? []) === []) {
      unset($generated['slots']);
    }
    $generated['props']['properties'] = $runtime + $generated['props']['properties'];
    $required = array_values(array_unique([
      ...($generated['props']['required'] ?? []),
      ...array_intersect($checkedIn['props']['required'] ?? [], $runtimeProps),
    ]));
    if ($required !== []) {
      $generated['props']['required'] = $required;
    }
    return $generated;
  }

}
