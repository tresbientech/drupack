<?php

declare(strict_types=1);

namespace Drupal\wordpal\Query;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\node\NodeInterface;
use Drupal\wordpal\Support\Cacheable;

/**
 * The WordPress post classes of one Query row.
 */
final class PostClasses {

  /**
   * Returns one row's WordPress post classes, with term access applied.
   *
   * A denied term adds no class but still enters `$cacheability` through its
   * access result, so the row varies by the viewer who can see the term.
   */
  public static function for(NodeInterface $entity, QuerySettings $settings, CacheableMetadata $cacheability): array {
    // A search index can hold bundles the Content mapping does not list.
    $fields = $settings->bundles[$entity->bundle()] ?? [
      'type' => $entity->bundle(),
      'category_field' => NULL,
      'tag_field' => NULL,
      'image_field' => NULL,
    ];
    $type = $fields['type'];
    $classes = [
      'wp-block-post',
      $type,
      $type . '-' . $entity->id(),
      'type-' . $type,
      $entity->isPublished() ? 'status-publish' : 'status-draft',
    ];
    // WordPress pages support no post formats.
    if ($type === 'post') {
      $classes[] = 'format-standard';
    }
    $classes[] = 'hentry';
    foreach (['category' => $fields['category_field'], 'tag' => $fields['tag_field']] as $prefix => $field) {
      if ($field === NULL || !Cacheable::allowed($entity->get($field)->access('view', NULL, TRUE), $cacheability)) {
        continue;
      }
      foreach ($entity->get($field)->referencedEntities() as $term) {
        $cacheability->addCacheableDependency($term);
        if (Cacheable::allowed($term->access('view', NULL, TRUE), $cacheability)) {
          $classes[] = $prefix . '-' . Html::getClass($term->label());
        }
      }
    }
    if ($fields['image_field'] !== NULL && !$entity->get($fields['image_field'])->isEmpty()) {
      $classes[] = 'has-post-thumbnail';
    }
    return $classes;
  }

}
