<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Content;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Resolves the media bundle and source field an entity reference targets.
 */
final class MediaSourceField {

  /**
   * Returns the first target media bundle and its source field name.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   An entity_reference field targeting media.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the target media type.
   *
   * @return array{0: string, 1: string}
   *   The media bundle machine name and its source field name.
   */
  public static function resolve(FieldDefinitionInterface $field, EntityTypeManagerInterface $entityTypeManager): array {
    $mediaBundle = array_key_first($field->getSetting('handler_settings')['target_bundles']);
    $mediaType = $entityTypeManager->getStorage('media_type')->load($mediaBundle);
    return [$mediaBundle, $mediaType->getSource()->getSourceFieldDefinition($mediaType)->getName()];
  }

}
