<?php

declare(strict_types=1);

namespace Drupal\wordpal\Component;

/**
 * Names the sides and corners of a box in a prop name.
 *
 * The conversion-only side of prop naming, WordPress attribute parsing, is
 * `Drupal\wordpal_convert\Component\PropName`.
 */
final class PropName {

  /**
   * The prop schema key holding the elements a duotone filter applies to.
   */
  public const DUOTONE_SELECTOR = 'x-wordpal-duotone-selector';

  /**
   * The sides of a box, as WordPress orders them in a prop name.
   */
  public const SIDES = ['top', 'right', 'bottom', 'left'];

  /**
   * The corners of a border radius, as prop name part => WordPress key.
   */
  public const CORNERS = [
    'top_left' => 'topLeft',
    'top_right' => 'topRight',
    'bottom_left' => 'bottomLeft',
    'bottom_right' => 'bottomRight',
  ];

}
