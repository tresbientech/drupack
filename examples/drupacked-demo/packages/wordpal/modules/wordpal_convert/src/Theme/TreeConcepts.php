<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\NavigationMenus;

/**
 * Reads the content concepts and the dropped options of a WordPress block tree.
 */
final class TreeConcepts {

  /**
   * The blocks that print the current post, which a Drupal-owned route lacks.
   */
  private const READS_POST = [
    'core/post-featured-image', 'core/post-excerpt', 'core/post-terms', 'core/post-date',
    'core/post-author', 'core/post-author-name', 'core/post-author-biography', 'core/avatar',
    'core/read-more', 'core/post-navigation-link', 'core/post-time-to-read', 'core/footnotes',
    'core/comments', 'core/post-comments-form',
    'core/post-comments-count', 'core/post-comments-link',
  ];

  /**
   * The Social Icons context each Social Icon reads.
   */
  public const SOCIAL_LINK_CONTEXT = [
    'openInNewTab', 'showLabels', 'iconColor', 'iconColorValue',
    'iconBackgroundColor', 'iconBackgroundColorValue',
  ];

  /**
   * Returns a block's inner blocks, carrying the context they read.
   *
   * WordPress passes a Social Icons block's options to each Social Icon as
   * block context; a component reads them as its own props.
   */
  public static function childNodes(BlockNode $node): array {
    if ($node->name !== 'core/social-links') {
      return $node->children;
    }
    $context = array_intersect_key($node->attributes, array_flip(self::SOCIAL_LINK_CONTEXT));
    // The editor clears the icon background color of the Logos Only style on
    // load (social-links/edit.js).
    if (in_array('is-style-logos-only', explode(' ', $node->attributes['className'] ?? ''), TRUE)) {
      unset($context['iconBackgroundColor'], $context['iconBackgroundColorValue']);
    }
    return array_map(
      static fn (BlockNode $child): BlockNode => new BlockNode($child->name, $child->attributes + $context, $child->innerHtml, $child->children, $child->rendered, $child->isValid, $child->cut),
      $node->children,
    );
  }

  /**
   * Returns whether a block prints the current post and nothing without one.
   */
  public static function readsPost(BlockNode $node): bool {
    return in_array($node->name, self::READS_POST, TRUE);
  }

  /**
   * Maps a dynamic block to its content concept.
   */
  public static function concept(BlockNode $node): ?string {
    return match ($node->name) {
      'core/post-featured-image' => 'featured_image',
      'core/post-excerpt' => 'excerpt',
      'core/post-terms' => ($node->attributes['term'] ?? 'category') === 'category' ? 'category' : 'tag',
      'core/comments', 'core/latest-comments', 'core/post-comments-form' => 'comments',
      // A block with its own links binds its own menu, not the mapped one.
      'core/navigation' => NavigationMenus::links($node) === NULL ? 'navigation' : NULL,
      'core/tag-cloud' => 'tag',
      'core/page-list' => 'page',
      'core/latest-posts', 'core/post-author', 'core/avatar', 'core/read-more' => 'post',
      'core/search' => 'search',
      // WordPress block attributes are external snapshot input.
      'core/categories' => match ($node->attributes['taxonomy'] ?? 'category') {
        'category' => 'category',
        'post_tag' => 'tag',
        default => throw new \UnexpectedValueException("Unsupported Categories taxonomy: {$node->attributes['taxonomy']}"),
      },
      'core/post-author-biography' => 'author_biography',
      'core/cover', 'core/media-text' => ($node->attributes['useFeaturedImage'] ?? FALSE) ? 'featured_image' : NULL,
      'core/post-content' => 'post_body',
      'core/post-title', 'core/post-date', 'core/post-author-name', 'core/post-navigation-link' => 'post',
      default => NULL,
    };
  }

  /**
   * Returns whether a mapping drops content bound to a concept.
   *
   * The mapping supplies a concept and sets it to null: both Canvas and
   * Display Builder drop the block bound to it, and count it under
   * ContentMapping::recordDroppedBlocks(). A concept the mapping never
   * supplies at all is not dropped here (see RuntimeBindings::omission() for
   * where that different rule applies instead).
   */
  public static function dropped(?string $concept, ContentMapping $mapping): bool {
    return $concept !== NULL && $mapping->has($concept) && $mapping->drops($concept);
  }

  /**
   * Counts mapped content concepts used by a block tree.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   *
   * @return array<string, int>
   *   Counts keyed by concept.
   */
  public static function counts(array $nodes): array {
    $concepts = [];
    foreach ($nodes as $node) {
      $concept = self::concept($node);
      if ($concept !== NULL) {
        $concepts[$concept] = ($concepts[$concept] ?? 0) + 1;
      }
      foreach (self::counts($node->children) as $childConcept => $count) {
        $concepts[$childConcept] = ($concepts[$childConcept] ?? 0) + $count;
      }
    }
    return $concepts;
  }

  /**
   * Returns the mapped content concepts used by a block tree.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   *
   * @return string[]
   *   The concepts, such as "post" or "category".
   */
  public static function required(array $nodes): array {
    return array_keys(self::counts($nodes));
  }

  /**
   * Returns whether a block tree binds the mapped node body.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   */
  public static function requiresPostContent(array $nodes): bool {
    foreach ($nodes as $node) {
      if ($node->name === 'core/post-content' || self::requiresPostContent($node->children)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * What a conversion does with each block's options it cannot keep.
   */
  public const DROPPED_OPTIONS = [
    'core/latest-posts' => 'full post content, rendered as the excerpt',
    'core/social-links' => 'icon options are copied to each Social Icon, where editors change them',
  ];

  /**
   * Lists the block options a tree writes without their WordPress behavior.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   *
   * @return string[]
   *   One line per affected block, in tree order.
   */
  public static function droppedOptions(array $nodes): array {
    $lines = [];
    foreach ($nodes as $node) {
      $dropped = match ($node->name) {
        'core/latest-posts' => ($node->attributes['displayPostContentRadio'] ?? 'excerpt') === 'full_post' && ($node->attributes['displayPostContent'] ?? FALSE),
        'core/social-links' => array_intersect_key($node->attributes, array_flip(self::SOCIAL_LINK_CONTEXT)) !== [],
        default => FALSE,
      };
      if ($dropped) {
        $lines[] = $node->name . ': ' . self::DROPPED_OPTIONS[$node->name];
      }
      $lines = [...$lines, ...self::droppedOptions($node->children)];
    }
    return $lines;
  }

}
