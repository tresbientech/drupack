<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder_runtime\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\wordpal\Theme\SiteBlocks;

/**
 * Wraps a Display Builder page layout in wp-site-blocks, as WordPress does.
 */
final class PageHooks {

  /**
   * Implements hook_preprocess_HOOK() for page.
   *
   * The display_builder_page_layout module's own page.html.twig prints
   * `{{ page }}` with no wrapper, replacing the generated theme's
   * page.html.twig for every route a page layout matches. Core wraps every
   * #type => page element in an off_canvas_page_wrapper #theme_wrappers,
   * applied to the "page" theme hook's already-themed output, so wrapping
   * here, inside that same hook's preprocessing, lands wp-site-blocks inside
   * the off-canvas div, where the generated theme's page.html.twig prints it.
   *
   * The wrapper goes on the display_builder_content child, not on
   * $variables['page'] itself: ThemeManager::render() flags that array
   * #render_children to let the template print `{{ page }}` without
   * re-invoking the page hook, and Renderer::doRender() skips #prefix/#suffix
   * whenever #render_children is set. A Block Layout page (no matching page
   * layout) keeps the theme's own page.html.twig wrapper, unchanged.
   *
   * A theme's CSS selects on the siblings WordPress prints inside the
   * wrapper, such as `header:has(+ .wp-block-cover)`. A messages block at
   * the top level of the layout moves before the wrapper, as the first page
   * child: the page array is already sorted, so its order is final. One an
   * editor placed inside a component stays there.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    if (!isset($variables['page']['display_builder_content'])) {
      return;
    }
    $content = &$variables['page']['display_builder_content'];
    $content['#prefix'] = ($content['#prefix'] ?? '') . '<div class="' . SiteBlocks::SITE_BLOCKS_CLASS . '">';
    $content['#suffix'] = '</div>' . ($content['#suffix'] ?? '');
    $content['#post_render'][] = [SiteBlocks::class, 'markMainContent'];
    $messages = NULL;
    foreach ($content['data'] as $index => $source) {
      if (($source['#type'] ?? NULL) === 'status_messages') {
        $messages = $source;
        unset($content['data'][$index]);
        break;
      }
    }
    unset($content);
    if ($messages !== NULL) {
      $variables['page'] = ['wordpal_messages' => $messages] + $variables['page'];
    }
  }

}
