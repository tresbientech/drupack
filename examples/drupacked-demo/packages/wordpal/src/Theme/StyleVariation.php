<?php

declare(strict_types=1);

namespace Drupal\wordpal\Theme;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rebuilds library definitions when a theme's style variation changes.
 *
 * A converted theme's hook_library_info_alter() makes its global library
 * depend on the variation its settings select. Pages and the Canvas editor
 * preview both load that library, and the library definitions are cached.
 */
final class StyleVariation implements EventSubscriberInterface {

  /**
   * The theme setting naming the active style variation.
   */
  public const SETTING = 'style_variation';

  public function __construct(
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Invalidates library definitions when a theme settings object changes it.
   */
  public function onSave(ConfigCrudEvent $event): void {
    if (str_ends_with($event->getConfig()->getName(), '.settings') && $event->isChanged(self::SETTING)) {
      $this->cacheTagsInvalidator->invalidateTags(['library_info']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

}
