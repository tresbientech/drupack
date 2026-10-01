<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder_runtime\EventSubscriber;

use Drupal\wordpal\Plugin\Block\PageTitleBlock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records the main content's own title for the WordPal page title block.
 *
 * Display Builder passes that title to its own page title source only and
 * sets no block's title.
 */
final class MainContentTitle implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Before MainContentViewSubscriber renders the main content at 0.
    return [KernelEvents::VIEW => [['onView', 10]]];
  }

  /**
   * Stores the controller result's title in the request.
   */
  public function onView(ViewEvent $event): void {
    $result = $event->getControllerResult();
    if (is_array($result) && isset($result['#title'])) {
      $event->getRequest()->attributes->set(PageTitleBlock::MAIN_CONTENT_TITLE, $result['#title']);
    }
  }

}
