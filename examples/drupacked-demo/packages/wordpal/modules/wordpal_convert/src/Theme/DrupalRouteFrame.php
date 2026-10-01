<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * Builds the frame of a Drupal-owned route from the theme's page Template.
 *
 * A Drupal-owned route shows no post. Post Title shows the route's title,
 * Post Content holds Drupal's main content, and the blocks that print the
 * current post are left out, as WordPress prints nothing for them there.
 */
final class DrupalRouteFrame {

  /**
   * Stands for a Post Title block showing the route's title.
   */
  public const PAGE_TITLE = 'wordpal/page-title';

  /**
   * Stands for Drupal's main content, in Post Content's page_content slot.
   */
  public const MAIN_CONTENT = 'wordpal/main-content';

  /**
   * Returns the page Template's Part set with its body in place of the marker.
   *
   * @return \Drupal\wordpal_convert\Theme\PartSet|null
   *   NULL when the body has no Post Content block to hold the main content.
   */
  public static function partSet(ResolvedTemplate $page): ?PartSet {
    $body = self::body($page->nodes);
    if (!self::holdsMainContent($body)) {
      return NULL;
    }
    $placements = [];
    foreach ($page->partSet->placements as $placement) {
      $placements[] = $placement['type'] === 'marker' ? ['type' => 'body', 'nodes' => $body] : $placement;
    }
    return new PartSet($placements);
  }

  /**
   * Returns whether rewritten blocks place Drupal's main content.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks of a body partSet() rewrote, or blocks within one.
   */
  public static function holdsMainContent(array $nodes): bool {
    foreach ($nodes as $node) {
      if ($node->name === self::MAIN_CONTENT || self::holdsMainContent($node->children)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Rewrites a page Template body for a route with no post.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The body's blocks.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The blocks a Drupal-owned route shows.
   */
  private static function body(array $nodes): array {
    $body = [];
    foreach ($nodes as $node) {
      if (TreeConcepts::readsPost($node)) {
        continue;
      }
      $body[] = match ($node->name) {
        'core/post-title' => new BlockNode(self::PAGE_TITLE, $node->attributes, $node->innerHtml, [], NULL, $node->isValid),
        'core/post-content' => new BlockNode($node->name, $node->attributes, $node->innerHtml, [new BlockNode(self::MAIN_CONTENT, [], '', [])], NULL, $node->isValid),
        // A Cover or Media & Text shows no featured image without a post.
        'core/cover', 'core/media-text' => new BlockNode($node->name, array_diff_key($node->attributes, ['useFeaturedImage' => TRUE]), $node->innerHtml, self::body($node->children), $node->rendered, $node->isValid, $node->cut),
        default => new BlockNode($node->name, $node->attributes, $node->innerHtml, self::body($node->children), $node->rendered, $node->isValid, $node->cut),
      };
    }
    return $body;
  }

}
