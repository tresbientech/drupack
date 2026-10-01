<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder_runtime\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\wordpal\Theme\FrameThemeGuard;

/**
 * Keeps a converted page layout to the theme it was converted for.
 */
final class PageLayoutThemeGuard extends FrameThemeGuard {

  public function __construct(
    ThemeManagerInterface $themeManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($themeManager);
  }

  /**
   * {@inheritdoc}
   */
  protected static function selectionPriority(): int {
    // Display Builder's PageVariantSubscriber::getSubscribedEvents().
    return -100;
  }

  /**
   * {@inheritdoc}
   */
  protected function frameTheme(PageDisplayVariantSelectionEvent $event): ?string {
    if ($event->getPluginId() !== 'display_builder_page_layout') {
      return NULL;
    }
    /** @var \Drupal\display_builder_page_layout\AccessControlHandler $access */
    $access = $this->entityTypeManager->getAccessControlHandler('page_layout');
    return $access->loadCurrentPageLayout()->getThirdPartySetting('wordpal_display_builder_runtime', 'theme');
  }

}
