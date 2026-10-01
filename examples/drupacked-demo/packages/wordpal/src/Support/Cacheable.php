<?php

declare(strict_types=1);

namespace Drupal\wordpal\Support;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\user\UserInterface;

/**
 * Bridges an access check or a generated URL into cacheability.
 *
 * Each call adds the checked object's cacheability to the caller's metadata.
 */
final class Cacheable {

  /**
   * Adds the access result to $cacheability and returns whether it allows.
   */
  public static function allowed(AccessResultInterface $accessResult, CacheableMetadata $cacheability): bool {
    $cacheability->addCacheableDependency($accessResult);
    return $accessResult->isAllowed();
  }

  /**
   * Adds the generated URL's cacheability and returns the URL string.
   */
  public static function url(Url $url, CacheableMetadata $cacheability): string {
    $generatedUrl = $url->toString(TRUE);
    $cacheability->addCacheableDependency($generatedUrl);
    return $generatedUrl->getGeneratedUrl();
  }

  /**
   * Checks $operation on $entity, then returns its URL, or NULL when denied.
   */
  public static function link(EntityInterface $entity, CacheableMetadata $cacheability, string $operation = 'view', ?AccountInterface $account = NULL): ?string {
    $cacheability->addCacheableDependency($entity);
    if (!self::allowed($entity->access($operation, $account, TRUE), $cacheability)) {
      return NULL;
    }
    return self::url($entity->toUrl(), $cacheability);
  }

  /**
   * Returns the account's picture file, or NULL when there is none to show.
   *
   * NULL also covers a site without the user_picture field and a viewer
   * denied view access to the field or to its file.
   */
  public static function picture(UserInterface $account, CacheableMetadata $cacheability): ?FileInterface {
    if (!$account->hasField('user_picture') || !self::allowed($account->get('user_picture')->access('view', NULL, TRUE), $cacheability)) {
      return NULL;
    }
    $file = $account->get('user_picture')->entity;
    if ($file === NULL || !self::allowed($file->access('view', NULL, TRUE), $cacheability)) {
      return NULL;
    }
    $cacheability->addCacheableDependency($file);
    return $file;
  }

}
