<?php

declare(strict_types=1);

namespace Drupal\wordpal\Layout;

/**
 * Renders a stored layout tree of markers and components.
 *
 * A node is a marker, replaced by a render array from the caller, or a
 * component with props and children that fill its content slot. The comments
 * block, the Query views and the navigation's trailing blocks store this shape.
 */
final class LayoutRenderer {

  /**
   * Builds static components and runtime markers in source order.
   *
   * @param array $nodes
   *   The layout tree, one level of it.
   * @param array $markers
   *   The render array of each marker, keyed by marker name.
   */
  public static function build(array $nodes, array $markers = []): array {
    $build = [];
    foreach ($nodes as $node) {
      if (isset($node['marker'])) {
        // SDC slots require a render property even when the marker is empty.
        $build[] = $markers[$node['marker']] ?: ['#markup' => ''];
        continue;
      }
      $component = [
        '#type' => 'component',
        '#component' => $node['component'],
        '#props' => $node['props'],
      ];
      if ($node['children'] !== []) {
        $component['#slots']['content'] = self::build($node['children'], $markers);
      }
      $build[] = $component;
    }
    return $build;
  }

}
