<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * Reads which date a Post Date block shows.
 */
final class PostDate {

  /**
   * Returns whether a Post Date block shows the modified date.
   *
   * The Modified Date variation saves a datetime binding on the "modified"
   * field; post-date.php still reads the legacy displayType attribute when
   * no binding is saved.
   */
  public static function isModified(BlockNode $node): bool {
    $field = $node->attributes['metadata']['bindings']['datetime']['args']['field'] ?? NULL;
    return $field === 'modified' || ($field === NULL && ($node->attributes['displayType'] ?? NULL) === 'modified');
  }

}
