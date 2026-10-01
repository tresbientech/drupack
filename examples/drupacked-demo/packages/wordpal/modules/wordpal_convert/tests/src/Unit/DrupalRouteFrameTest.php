<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\DrupalRouteFrame;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_convert\Theme\ResolvedTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the frame of Drupal-owned routes built from the page Template.
 */
#[CoversClass(DrupalRouteFrame::class)]
#[Group('wordpal')]
final class DrupalRouteFrameTest extends UnitTestCase {

  /**
   * Tests the body replaces the marker and shows no post.
   */
  public function testBodyShowsTheRouteInsteadOfPost(): void {
    $main = new BlockNode('core/group', ['tagName' => 'main'], '', [
      new BlockNode('core/post-featured-image', [], '', []),
      new BlockNode('core/post-title', ['level' => 1], '', []),
      new BlockNode('core/cover', ['useFeaturedImage' => TRUE, 'dimRatio' => 50], '', [
        new BlockNode('core/post-date', [], '', []),
        new BlockNode('core/paragraph', [], '<p>Kept</p>', []),
      ]),
      new BlockNode('core/post-content', ['layout' => ['type' => 'constrained']], '', []),
    ]);
    $page = new ResolvedTemplate([$main], self::partSet(), [], []);

    $frame = DrupalRouteFrame::partSet($page);

    self::assertSame(['part', 'body', 'part'], array_column($frame->placements, 'type'));
    [$group] = $frame->placements[1]['nodes'];
    self::assertSame([DrupalRouteFrame::PAGE_TITLE, 'core/cover', 'core/post-content'], array_column($group->children, 'name'));
    self::assertSame(['level' => 1], $group->children[0]->attributes);
    self::assertSame(['dimRatio' => 50], $group->children[1]->attributes, 'A Cover shows no featured image.');
    self::assertSame(['core/paragraph'], array_column($group->children[1]->children, 'name'));
    self::assertSame([DrupalRouteFrame::MAIN_CONTENT], array_column($group->children[2]->children, 'name'));
    self::assertNotSame(self::partSet()->id('acme'), $frame->id('acme'));
    self::assertSame('header + page + footer', $frame->label());
  }

  /**
   * Tests a body with no Post Content gives no frame.
   */
  public function testNoPostContentGivesNoFrame(): void {
    $page = new ResolvedTemplate([new BlockNode('core/post-title', [], '', [])], self::partSet(), [], []);

    self::assertNull(DrupalRouteFrame::partSet($page));
  }

  /**
   * Returns a page Template's Part set: a header, the marker, a footer.
   */
  private static function partSet(): PartSet {
    return new PartSet([
      ['type' => 'part', 'slug' => 'header', 'attributes' => [], 'nodes' => []],
      ['type' => 'marker'],
      ['type' => 'part', 'slug' => 'footer', 'attributes' => [], 'nodes' => []],
    ]);
  }

}
