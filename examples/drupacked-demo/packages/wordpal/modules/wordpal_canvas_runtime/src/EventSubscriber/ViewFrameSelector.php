<?php

declare(strict_types=1);

namespace Drupal\wordpal_canvas_runtime\EventSubscriber;

use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Selects a converted listing View's own frame for the View's route.
 *
 * Canvas selects a frame for an entity route only; every other route gets
 * the site default, the frame of Drupal-owned routes, or none when the
 * theme has no page Template.
 */
final class ViewFrameSelector implements EventSubscriberInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After Canvas's own selection at -100, before FrameThemeGuard's at -150.
    return [RenderEvents::SELECT_PAGE_DISPLAY_VARIANT => [['select', -120]]];
  }

  /**
   * Selects the View's frame in place of the site default.
   */
  public function select(PageDisplayVariantSelectionEvent $event): void {
    $viewId = $event->getRouteMatch()->getRouteObject()?->getDefault('view_id');
    if ($viewId === NULL) {
      return;
    }
    $view = $this->entityTypeManager->getStorage('view')->load($viewId);
    $frame = $view->getThirdPartySetting('wordpal_canvas_runtime', 'frame');
    if ($frame === NULL) {
      return;
    }
    $event->addCacheableDependency($view);
    $configuration = $event->getPluginId() === CanvasPageVariant::PLUGIN_ID ? $event->getPluginConfiguration() : [CanvasPageVariant::PREVIEW_KEY => FALSE];
    $event->setPluginId(CanvasPageVariant::PLUGIN_ID);
    $event->setPluginConfiguration([CanvasPageVariant::VARIANT_ID_KEY => $frame] + $configuration);
  }

}
