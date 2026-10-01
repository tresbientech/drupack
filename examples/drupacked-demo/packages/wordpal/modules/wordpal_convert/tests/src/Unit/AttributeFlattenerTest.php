<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which style values reach no prop.
 */
#[CoversClass(AttributeFlattener::class)]
#[Group('wordpal')]
final class AttributeFlattenerTest extends UnitTestCase {

  /**
   * Tests a custom duotone is unread, and read styles are not.
   */
  public function testCustomDuotoneIsUnread(): void {
    $attributes = [
      'style' => [
        'color' => ['duotone' => ['#000000', '#ffffff'], 'text' => '#111111'],
        'spacing' => ['padding' => ['top' => '1rem']],
        'border' => ['radius' => ['topLeft' => '4px']],
      ],
    ];

    self::assertSame(['style.color.duotone'], (new AttributeFlattener())->unreadStyles($attributes, 'core/image'));
  }

  /**
   * Tests a preset duotone reaches its prop.
   */
  public function testPresetDuotoneIsRead(): void {
    $attributes = ['style' => ['color' => ['duotone' => 'var:preset|duotone|dark-grayscale']]];

    self::assertSame([], (new AttributeFlattener())->unreadStyles($attributes, 'core/image'));
  }

  /**
   * Tests empty values and a non-object style list nothing.
   */
  public function testEmptyValuesAreNotControls(): void {
    $flattener = new AttributeFlattener();

    self::assertSame([], $flattener->unreadStyles(['style' => ['layout' => [], 'color' => ['text' => NULL]]], 'core/spacer'));
    self::assertSame([], $flattener->unreadStyles(['style' => 'color: red'], 'core/group'));
  }

  /**
   * Tests a hover link color, which no prop holds, is unread.
   */
  public function testHoverLinkColorIsUnread(): void {
    $link = [
      'color' => ['text' => '#000000'],
      ':hover' => ['color' => ['text' => '#ff0000']],
    ];
    $attributes = ['style' => ['elements' => ['link' => $link]]];

    self::assertSame(['style.elements.link.:hover.color.text'], (new AttributeFlattener())->unreadStyles($attributes, 'core/group'));
  }

  /**
   * Tests a slug that starts with a digit is still a preset reference.
   */
  public function testDigitLeadingSlugIsPresetReference(): void {
    $props = (new AttributeFlattener())->flatten(['fontSize' => '2xl', 'backgroundColor' => '2'], 'core/paragraph');

    self::assertSame('var:preset|font-size|2-xl', $props['font_size']);
    self::assertSame('var:preset|color|2', $props['background_color']);
  }

  /**
   * Tests every preset attribute kebab-cases its slug as WordPress does.
   */
  public function testPresetSlugsAreKebabCased(): void {
    $props = (new AttributeFlattener())->flatten(['backgroundColor' => 'accent_1', 'textColor' => 'vividCyan'], 'core/paragraph');

    self::assertSame('var:preset|color|accent-1', $props['background_color']);
    self::assertSame('var:preset|color|vivid-cyan', $props['text_color']);
  }

}
