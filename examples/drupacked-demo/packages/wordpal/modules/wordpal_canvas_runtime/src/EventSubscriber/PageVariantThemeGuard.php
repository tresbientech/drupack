<?php

declare(strict_types=1);

namespace Drupal\wordpal_canvas_runtime\EventSubscriber;

use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\wordpal\Theme\FrameThemeGuard;

/**
 * Keeps a converted page variant to the theme it was converted for.
 */
final class PageVariantThemeGuard extends FrameThemeGuard {

  /**
   * {@inheritdoc}
   */
  protected static function selectionPriority(): int {
    // Canvas's PageVariantSelectorSubscriber::getSubscribedEvents().
    return -100;
  }

  /**
   * {@inheritdoc}
   */
  protected function frameTheme(PageDisplayVariantSelectionEvent $event): ?string {
    if ($event->getPluginId() !== CanvasPageVariant::PLUGIN_ID) {
      return NULL;
    }
    // Canvas's legacy page regions select no variant.
    $id = $event->getPluginConfiguration()[CanvasPageVariant::VARIANT_ID_KEY] ?? NULL;
    return $id === NULL ? NULL : PageVariant::load($id)->getThirdPartySetting('wordpal_canvas_runtime', 'theme');
  }

}
