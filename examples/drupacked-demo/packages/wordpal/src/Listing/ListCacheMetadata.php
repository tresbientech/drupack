<?php

declare(strict_types=1);

namespace Drupal\wordpal\Listing;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * Builds the cache metadata shared by node-grants-scoped listings and checks.
 *
 * A node or term listing adds its own tag on top of this. A node-access
 * check uses it alone, with no tag. Both vary by the same node-grants
 * context pair, since a grant module can appear or change at any time.
 */
final class ListCacheMetadata {

  /**
   * Returns the cache metadata of a check against one node's view access.
   */
  public static function forNodeAccess(): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheContexts(['user.permissions', 'user.node_grants:view']);
  }

  /**
   * Returns the cache metadata of a listing scoped to one node bundle.
   *
   * @param string $bundle
   *   The node bundle.
   */
  public static function forNodes(string $bundle): CacheableMetadata {
    return self::forNodeAccess()->addCacheTags(['node_list:' . $bundle]);
  }

  /**
   * Returns the cache metadata of a listing scoped to one vocabulary.
   */
  public static function forTerms(string $vocabularyId): CacheableMetadata {
    return self::forNodeAccess()
      ->addCacheTags(['taxonomy_term_list:' . $vocabularyId, 'node_list']);
  }

}
