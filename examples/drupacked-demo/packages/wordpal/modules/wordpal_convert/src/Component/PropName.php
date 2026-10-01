<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

/**
 * Turns WordPress block attribute names into component prop names.
 */
final class PropName {

  /**
   * Attributes the block editor uses, which carry nothing for a page.
   *
   * Search is the exception: it prints its placeholder on the page, and
   * CanvasWriter::blockInputs() reads it from the block's attributes.
   */
  public const EDITOR_ONLY = [
    'placeholder',
    'levelOptions',
    'lock',
    'metadata',
    'allowedBlocks',
    'templateLock',
    '__unstableLocation',
  ];

  /**
   * Turns a WordPress attribute name into a prop name.
   *
   * WordPress writes attributes in camel case, such as "tagName". Props use
   * snake case, such as "tag_name".
   */
  public static function fromAttribute(string $attribute): string {
    return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', ltrim($attribute, '_')));
  }

}
