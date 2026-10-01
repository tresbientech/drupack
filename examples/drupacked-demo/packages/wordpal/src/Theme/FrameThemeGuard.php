<?php

declare(strict_types=1);

namespace Drupal\wordpal\Theme;

use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps a converted page frame to the theme it was converted for.
 *
 * A frame's components read their theme's WordPal settings, so another
 * active theme (an admin theme a negotiator picks, say) cannot render it.
 * The page then gets the variant selected before the builder ran.
 */
abstract class FrameThemeGuard implements EventSubscriberInterface {

  /**
   * The selection before the builder's subscriber ran, per event.
   *
   * @var \WeakMap<\Drupal\Core\Render\PageDisplayVariantSelectionEvent, array>
   */
  private \WeakMap $before;

  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
  ) {
    $this->before = new \WeakMap();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // The guard runs after the runtime's own selectors too, which run within
    // 50 below the builder.
    return [
      RenderEvents::SELECT_PAGE_DISPLAY_VARIANT => [
        ['remember', static::selectionPriority() + 10],
        ['guard', static::selectionPriority() - 50],
      ],
    ];
  }

  /**
   * Returns the priority the builder selects its page display variant at.
   */
  abstract protected static function selectionPriority(): int;

  /**
   * Records the selection the builder may replace.
   */
  public function remember(PageDisplayVariantSelectionEvent $event): void {
    $this->before[$event] = [$event->getPluginId(), $event->getPluginConfiguration(), $event->getContexts()];
  }

  /**
   * Restores the earlier selection when the frame's theme is not active.
   */
  public function guard(PageDisplayVariantSelectionEvent $event): void {
    $theme = $this->frameTheme($event);
    if ($theme === NULL) {
      return;
    }
    $event->addCacheContexts(['theme']);
    if ($theme === $this->themeManager->getActiveTheme()->getName()) {
      return;
    }
    [$pluginId, $configuration, $contexts] = $this->before[$event];
    $event->setPluginId($pluginId);
    $event->setPluginConfiguration($configuration);
    $event->setContexts($contexts);
  }

  /**
   * Returns the theme of the converted frame the builder selected, if any.
   */
  abstract protected function frameTheme(PageDisplayVariantSelectionEvent $event): ?string;

}
