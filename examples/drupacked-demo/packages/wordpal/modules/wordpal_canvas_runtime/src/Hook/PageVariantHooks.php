<?php

declare(strict_types=1);

namespace Drupal\wordpal_canvas_runtime\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\wordpal\Theme\SiteBlocks;
use Drupal\wordpal\Theme\ThemeSettings;

/**
 * Keeps Drupal's page chrome out of the wp-site-blocks sibling chain.
 *
 * A converted theme's canvas-page-variant.html.twig prints content.messages
 * before its wp-site-blocks wrapper and the rest inside it. A theme's CSS
 * selects on the siblings WordPress prints there, such as
 * `header:has(+ .wp-block-cover)`.
 */
final class PageVariantHooks {

  public function __construct(
    private readonly ThemeSettings $themeSettings,
  ) {}

  /**
   * Implements hook_preprocess_HOOK() for canvas_page_variant.
   *
   * A messages block at the top level of the frame moves to
   * content.messages. One an editor placed inside a component stays there.
   * With no messages block in its tree, Canvas sets content.messages itself.
   */
  #[Hook('preprocess_canvas_page_variant')]
  public function preprocessCanvasPageVariant(array &$variables): void {
    if (!$this->themeSettings->activeThemeIsConverted()) {
      return;
    }
    foreach (Element::children($variables['content']) as $root) {
      foreach (Element::children($variables['content'][$root]) as $key) {
        if (($variables['content'][$root][$key]['#component']['#plugin_id'] ?? NULL) === 'system_messages_block') {
          $variables['content']['messages'] = $variables['content'][$root][$key];
          unset($variables['content'][$root][$key]);
          break 2;
        }
      }
    }
    $variables['content']['#post_render'][] = [SiteBlocks::class, 'markMainContent'];
  }

}
