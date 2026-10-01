<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

use Drupal\wordpal_convert\Component\AttributeFlattener;

/**
 * Hashes a block tree or a Query's shape into a short, deterministic id.
 *
 * Shared by every target's writer: a card, empty state, or Query wrapper
 * that would render the same way hashes to the same id regardless of which
 * writer built it, so a second conversion reuses the same View, view mode,
 * or pattern instead of writing a duplicate.
 */
final class TreeHashing {

  public function __construct(
    private readonly AttributeFlattener $flattener,
  ) {}

  /**
   * Returns the theme part of generated View and view mode ids.
   *
   * Views and view mode ids stay under Drupal's 32-character limits.
   */
  public static function idPrefix(string $theme): string {
    return self::truncatedThemeId(ThemeGenerator::themeId($theme));
  }

  /**
   * Truncates an already-generated theme id under Drupal's id length limits.
   */
  public static function truncatedThemeId(string $themeId): string {
    return strlen($themeId) <= 15 ? $themeId : substr($themeId, 0, 6) . '_' . substr(hash('sha256', $themeId), 0, 8);
  }

  /**
   * Returns a short hash of a Query's paging, sort, and subtree shape.
   */
  public function queryViewKey(QueryLoop $loop, string $bundle, ?string $route): string {
    $scalars = [
      $bundle,
      $loop->perPage,
      $loop->offset,
      $loop->order,
      $loop->excludeSticky,
      $loop->pagerElement,
      $loop->pages,
      $loop->orderBy,
    ];
    // A listing route's View reads the route, so it shares nothing with a
    // Query outside it.
    if ($route !== NULL) {
      $scalars[] = $route;
    }
    return substr(hash('sha256', json_encode($scalars, JSON_THROW_ON_ERROR) . $this->treeKey([$loop->query])), 0, 12);
  }

  /**
   * Returns a short hash of a block tree's own shape.
   *
   * Node names and the same attribute values addNodes() turns into
   * component props, never a generated UUID or a WordPress per-request
   * identifier, so two trees that would render the same card, empty
   * state, or Query wrapper hash the same.
   */
  public function treeKey(array $nodes): string {
    return substr(hash('sha256', json_encode($this->canonicalTree($nodes), JSON_THROW_ON_ERROR)), 0, 12);
  }

  /**
   * Returns a block tree as plain arrays, ready to hash.
   */
  private function canonicalTree(array $nodes): array {
    $canonical = [];
    foreach ($nodes as $node) {
      $attributes = $this->flattener->flatten($node->attributes, $node->name);
      ksort($attributes);
      $canonical[] = [
        'name' => $node->name,
        'attributes' => $attributes,
        'children' => $this->canonicalTree($node->children),
      ];
    }
    return $canonical;
  }

}
