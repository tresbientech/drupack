<?php

declare(strict_types=1);

namespace Drupal\wordpal\Theme;

/**
 * Gives Drupal's form buttons the theme's button style.
 */
final class FormButtons {

  /**
   * Adds the class WordPress's `elements.button` style targets.
   *
   * The generated theme calls this from its hook_preprocess_input().
   */
  public static function preprocessInput(array &$variables): void {
    $classes = $variables['attributes']['class'] ?? [];
    if (in_array($variables['element']['#type'], ['submit', 'button'], TRUE) && !in_array('wp-element-button', $classes, TRUE)) {
      $variables['attributes']['class'][] = 'wp-element-button';
    }
  }

}
