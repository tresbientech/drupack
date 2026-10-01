<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Theme\FormButtons;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drupal's form buttons take the theme's button class once.
 */
#[CoversClass(FormButtons::class)]
#[Group('wordpal')]
final class FormButtonsTest extends UnitTestCase {

  /**
   * Tests buttons get the class, other inputs and styled buttons do not.
   */
  public function testButtonsTakeTheElementButtonClass(): void {
    $submit = ['element' => ['#type' => 'submit'], 'attributes' => ['class' => ['button', 'form-submit']]];
    FormButtons::preprocessInput($submit);
    self::assertSame(['button', 'form-submit', 'wp-element-button'], $submit['attributes']['class']);

    $button = ['element' => ['#type' => 'button'], 'attributes' => []];
    FormButtons::preprocessInput($button);
    self::assertSame(['wp-element-button'], $button['attributes']['class']);

    $comment = [
      'element' => ['#type' => 'submit'],
      'attributes' => ['class' => ['wp-block-button__link', 'wp-element-button']],
    ];
    FormButtons::preprocessInput($comment);
    self::assertSame(['wp-block-button__link', 'wp-element-button'], $comment['attributes']['class'], 'The comment form already carries it.');

    $text = ['element' => ['#type' => 'textfield'], 'attributes' => ['class' => ['form-text']]];
    FormButtons::preprocessInput($text);
    self::assertSame(['form-text'], $text['attributes']['class']);
  }

}
