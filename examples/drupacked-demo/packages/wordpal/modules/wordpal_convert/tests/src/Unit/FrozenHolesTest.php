<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Support\FrozenHoles;
use Drupal\wordpal_convert\Theme\BlockNode;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests a Frozen block's HTML gets a hole for each Query inside it.
 */
#[Group('wordpal')]
final class FrozenHolesTest extends UnitTestCase {

  /**
   * Tests a Query's rendered HTML becomes a numbered hole.
   */
  public function testQueryBecomesHole(): void {
    $first = $this->query('<ul class="first"></ul>');
    $second = $this->query('<ul class="second"></ul>');
    $node = new BlockNode('acme/tabs', [], '', [$first, new BlockNode('core/group', [], '', [$second])], '<div class="tabs"><ul class="first"></ul><div><ul class="second"></ul></div></div>');

    self::assertSame([
      'html' => '<div class="tabs"><!--wordpal:hole-1--><div><!--wordpal:hole-2--></div></div>',
      'queries' => [$first, $second],
    ], FrozenHoles::html($node));
  }

  /**
   * Tests HTML cut from a block leaves no hole.
   */
  public function testCutHtmlIsRemoved(): void {
    $node = new BlockNode('acme/tabs', [], '', [], '<div class="tabs"><h2>Products</h2><ul class="products"></ul></div>', TRUE, ['<ul class="products"></ul>']);

    self::assertSame(['html' => '<div class="tabs"><h2>Products</h2></div>', 'queries' => []], FrozenHoles::html($node));
  }

  /**
   * Tests a Query inside a Query renders inside its outer Query's View.
   */
  public function testNestedQueryIsPartOfItsOuterHole(): void {
    $outer = new BlockNode('core/query', [], '', [$this->query('<ul class="inner"></ul>')], '<div class="outer"><ul class="inner"></ul></div>');
    $node = new BlockNode('acme/tabs', [], '', [$outer], '<div class="tabs"><div class="outer"><ul class="inner"></ul></div></div>');

    self::assertSame(['html' => '<div class="tabs"><!--wordpal:hole-1--></div>', 'queries' => [$outer]], FrozenHoles::html($node));
  }

  /**
   * Tests HTML a block holds other than once fails.
   */
  public function testHtmlFoundOtherThanOnceFails(): void {
    $twice = new BlockNode('acme/tabs', [], '', [$this->query('<ul></ul>')], '<div><ul></ul><ul></ul></div>');
    $this->expectExceptionMessage('its rendered HTML holds the HTML of an inner core/query 2 times');

    FrozenHoles::html($twice);
  }

  /**
   * Tests HTML cut from a block must sit in it once.
   */
  public function testMissingCutHtmlFails(): void {
    $node = new BlockNode('acme/tabs', [], '', [], '<div></div>', TRUE, ['<ul class="products"></ul>']);
    $this->expectExceptionMessage('its rendered HTML holds the HTML of a left-out Query 0 times');

    FrozenHoles::html($node);
  }

  /**
   * Tests a block with more Queries than the component has slots fails.
   */
  public function testMoreQueriesThanSlotsFail(): void {
    $queries = array_map(fn (int $n): BlockNode => $this->query("<ul class=\"q$n\"></ul>"), range(1, 5));
    $html = '<div>' . implode('', array_map(static fn (BlockNode $query): string => $query->rendered, $queries)) . '</div>';
    $this->expectExceptionMessage('it holds 5 Queries, and a Frozen block places 4');

    FrozenHoles::html(new BlockNode('acme/tabs', [], '', $queries, $html));
  }

  /**
   * Tests WordPress HTML holding the hole marker fails.
   */
  public function testHtmlHoldingHoleMarkerFails(): void {
    $this->expectExceptionMessage('its rendered HTML holds a WordPal hole marker');

    FrozenHoles::html(new BlockNode('acme/tabs', [], '', [], '<div><!--wordpal:hole-1--></div>'));
  }

  /**
   * Tests Queries rendered in another order than their blocks fail.
   */
  public function testQueriesRenderedOutOfOrderFail(): void {
    $first = $this->query('<ul class="first"></ul>');
    $second = $this->query('<ul class="second"></ul>');
    $this->expectExceptionMessage('its rendered HTML holds its Queries in another order than its inner blocks');

    FrozenHoles::html(new BlockNode('acme/tabs', [], '', [$first, $second], '<div><ul class="second"></ul><ul class="first"></ul></div>'));
  }

  /**
   * Tests a cut that joins a hole marker gives no file.
   */
  public function testCutJoiningMarkerGivesNoFile(): void {
    $node = new BlockNode('acme/tabs', [], '', [], '<div><!--wordpal:<ul class="p"></ul>hole-1--></div>', TRUE, ['<ul class="p"></ul>']);

    self::assertSame('its hole markers do not each sit between elements', FrozenHoles::file($node));
  }

  /**
   * Tests a Query rendered inside raw text gives no file.
   */
  public function testQueryInsideRawTextGivesNoFile(): void {
    $node = new BlockNode('acme/tabs', [], '', [$this->query('<b>Posts</b>')], '<div><textarea><b>Posts</b></textarea></div>');

    self::assertSame('its hole markers do not each sit between elements', FrozenHoles::file($node));
  }

  /**
   * Returns a Query block with its rendered HTML.
   */
  private function query(string $rendered): BlockNode {
    return new BlockNode('core/query', [], '', [new BlockNode('core/post-template', [], '', [])], $rendered);
  }

}
