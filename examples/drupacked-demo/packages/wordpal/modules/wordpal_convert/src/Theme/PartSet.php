<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * An ordered set of template parts around one page-content marker.
 */
final readonly class PartSet {

  /**
   * Constructs an ordered part set.
   *
   * @param array[] $placements
   *   Ordered part placements, one messages placement for Drupal's status
   *   messages, and one marker placement. A Drupal-owned route's frame holds
   *   a body placement, the page Template's main area around the main
   *   content, in place of the marker.
   */
  public function __construct(
    public array $placements,
  ) {}

  /**
   * Returns a stable identifier for this ordered set.
   */
  public function id(string $theme): string {
    $canonical = [];
    foreach ($this->placements as $placement) {
      if ($placement['type'] !== 'part') {
        $canonical[] = ['type' => $placement['type']];
        continue;
      }
      $attributes = $placement['attributes'];
      ksort($attributes);
      $canonical[] = [
        'type' => 'part',
        'slug' => $placement['slug'],
        'attributes' => $attributes,
      ];
    }
    return substr(ThemeGenerator::themeId($theme), 0, 40) . '_frame_' . substr(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)), 0, 12);
  }

  /**
   * Returns all expanded template-part and body nodes in frame order.
   */
  public function nodes(): array {
    $nodes = [];
    foreach ($this->placements as $placement) {
      if ($placement['type'] === 'part' || $placement['type'] === 'body') {
        $nodes = [...$nodes, ...$placement['nodes']];
      }
    }
    return $nodes;
  }

  /**
   * Returns each part's tree without its unconvertible Queries, and its loops.
   *
   * @param int $mainPerPage
   *   The posts per page of WordPress's main query.
   * @param int $firstPagerElement
   *   The pager element of the first part Query that pages on its own. Later
   *   Queries take the next elements, across the parts in order.
   * @param string|null $leaveOutReason
   *   Why to leave every part Query out, or NULL to keep the convertible ones.
   *
   * @return array<int, array{nodes: \Drupal\wordpal_convert\Theme\BlockNode[], loops: \Drupal\wordpal_convert\Theme\QueryLoop[], dropped: string[]}>
   *   QueryLoop::allFromTree()'s result for each part placement, keyed by
   *   the placement's index.
   */
  public function loops(int $mainPerPage, int $firstPagerElement, ?string $leaveOutReason): array {
    $trees = [];
    foreach ($this->placements as $index => $placement) {
      if ($placement['type'] !== 'part') {
        continue;
      }
      $trees[$index] = QueryLoop::allFromTree($placement['nodes'], $mainPerPage, $firstPagerElement, $leaveOutReason);
      foreach ($trees[$index]['loops'] as $loop) {
        $firstPagerElement += $loop->inherit ? 0 : 1;
      }
    }
    return $trees;
  }

  /**
   * Returns a label naming this set's own template parts and page body.
   */
  public function label(): string {
    $slugs = [];
    foreach ($this->placements as $placement) {
      if ($placement['type'] === 'part') {
        $slugs[] = $placement['slug'];
      }
      elseif ($placement['type'] === 'body') {
        $slugs[] = 'page';
      }
    }
    return implode(' + ', $slugs);
  }

}
