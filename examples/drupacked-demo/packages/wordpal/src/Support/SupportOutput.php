<?php

declare(strict_types=1);

namespace Drupal\wordpal\Support;

/**
 * What the block supports of one block instance produce.
 */
final class SupportOutput {

  /**
   * Constructs the output of one block's supports.
   *
   * @param string[] $classes
   *   Classes for the block's root element.
   * @param array $styles
   *   Inline style declarations, keyed by CSS property.
   * @param array $cssRules
   *   CSS rules the page needs, keyed by selector.
   * @param array $attributes
   *   Other attributes for the root element, such as the anchor id.
   */
  public function __construct(
    public readonly array $classes,
    public readonly array $styles,
    public readonly array $cssRules,
    public readonly array $attributes = [],
  ) {}

  /**
   * Returns the inline style attribute value.
   *
   * Every value is validated, so a template that forgets its own wrapper
   * still cannot print a second declaration. A url() the extension built with
   * cssUrl() passes as it is: its argument has no quote, parenthesis or space.
   */
  public function style(): string {
    $declarations = [];
    foreach ($this->styles as $property => $value) {
      $value = (string) $value;
      if (!preg_match('/^url\([^()\s"\'\\\\]*\)$/D', $value)) {
        PresetValue::css($value);
      }
      $declarations[] = "$property:$value";
    }
    return implode(';', $declarations);
  }

  /**
   * Returns the CSS rules as a stylesheet.
   *
   * WordPress prints these rules after the theme's stylesheets, so they win
   * ties. Drupal prints head elements before its stylesheets. The "html"
   * prefix adds the lowest specificity step, so the rules still win ties and
   * still lose to any more specific selector.
   */
  public function stylesheet(): string {
    $rules = [];
    foreach ($this->cssRules as $selector => $declarations) {
      $rules[] = "html $selector{" . $declarations . '}';
    }
    return implode('', $rules);
  }

}
