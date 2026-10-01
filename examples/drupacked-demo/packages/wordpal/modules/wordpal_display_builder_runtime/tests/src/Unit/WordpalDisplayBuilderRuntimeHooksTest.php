<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder_runtime\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Theme\SiteBlocks;
use Drupal\wordpal_display_builder_runtime\Hook\PageHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the hooks wordpal_display_builder_runtime implements on core.
 */
#[CoversClass(PageHooks::class)]
#[Group('wordpal')]
final class WordpalDisplayBuilderRuntimeHooksTest extends UnitTestCase {

  /**
   * Tests display_builder_content gains the wp-site-blocks wrapper.
   */
  public function testPreprocessPageWrapsDisplayBuilderContent(): void {
    $variables = ['page' => ['display_builder_content' => ['#instance_id' => 'page_layout__1', 'data' => []]]];
    (new PageHooks())->preprocessPage($variables);
    self::assertSame('<div class="wp-site-blocks">', $variables['page']['display_builder_content']['#prefix']);
    self::assertSame('</div>', $variables['page']['display_builder_content']['#suffix']);
    self::assertContains([SiteBlocks::class, 'markMainContent'], $variables['page']['display_builder_content']['#post_render']);
    self::assertArrayNotHasKey('#prefix', $variables['page']);
  }

  /**
   * Tests a top-level messages source moves before the wrapper.
   */
  public function testTopLevelMessagesMoveBeforeTheWrapper(): void {
    $messages = ['#type' => 'status_messages', '#include_fallback' => TRUE];
    $header = ['#markup' => '<header></header>'];
    $page = [
      '#sorted' => TRUE,
      'display_builder_content' => ['#instance_id' => 'page_layout__1', 'data' => [$header, $messages]],
    ];
    $variables = ['page' => $page];
    (new PageHooks())->preprocessPage($variables);
    self::assertSame(['wordpal_messages', '#sorted', 'display_builder_content'], array_keys($variables['page']));
    self::assertSame($messages, $variables['page']['wordpal_messages']);
    self::assertSame([$header], array_values($variables['page']['display_builder_content']['data']));
  }

  /**
   * Tests messages an editor placed inside a component stay there.
   */
  public function testNestedMessagesStay(): void {
    $group = ['#type' => 'component', 'content' => ['#type' => 'status_messages']];
    $variables = ['page' => ['display_builder_content' => ['#instance_id' => 'page_layout__1', 'data' => [$group]]]];
    (new PageHooks())->preprocessPage($variables);
    self::assertArrayNotHasKey('wordpal_messages', $variables['page']);
    self::assertSame([$group], $variables['page']['display_builder_content']['data']);
  }

  /**
   * Tests a Block Layout page, with no display_builder_content, is untouched.
   */
  public function testPreprocessPageLeavesBlockLayoutPageAlone(): void {
    $variables = ['page' => ['content' => ['#markup' => 'hello']]];
    $before = $variables;
    (new PageHooks())->preprocessPage($variables);
    self::assertSame($before, $variables);
  }

}
