<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\wordpal_convert\Content\ContentMapping;

/**
 * Reads where an Author block's biography comes from.
 */
final class AuthorBio {

  /**
   * Returns the user field and property holding the biography, or NULL.
   *
   * NULL when the block hides the biography or the Content mapping drops
   * author_biography. A formatted text field binds its processed property.
   *
   * @return array{0: string, 1: string}|null
   *   The user field name and the property to bind.
   */
  public static function source(BlockNode $node, ContentMapping $mapping, EntityFieldManagerInterface $fields): ?array {
    if (!($node->attributes['showBio'] ?? FALSE) || !$mapping->binds('author_biography')) {
      return NULL;
    }
    $fieldName = (string) $mapping->target('author_biography');
    $type = $fields->getFieldDefinitions('user', 'user')[$fieldName]->getType();
    return [$fieldName, in_array($type, ['text', 'text_long'], TRUE) ? 'processed' : 'value'];
  }

}
