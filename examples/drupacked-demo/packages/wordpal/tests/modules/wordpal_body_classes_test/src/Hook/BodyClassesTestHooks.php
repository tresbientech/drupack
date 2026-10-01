<?php

declare(strict_types=1);

namespace Drupal\wordpal_body_classes_test\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\wordpal\Theme\BodyClasses;

/**
 * Prints the body classes as a generated theme's preprocess does.
 */
final class BodyClassesTestHooks {

  public function __construct(
    private readonly BodyClasses $bodyClasses,
  ) {}

  /**
   * Implements hook_preprocess_html().
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    $this->bodyClasses->preprocessHtml($variables);
  }

}
