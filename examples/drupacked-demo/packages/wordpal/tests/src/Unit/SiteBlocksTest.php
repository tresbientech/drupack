<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Theme\SiteBlocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the skip link target on the first <main> element.
 */
#[CoversClass(SiteBlocks::class)]
#[Group('wordpal')]
final class SiteBlocksTest extends UnitTestCase {

  /**
   * Tests the first <main> without an id takes the target id.
   */
  public function testFirstMainTakesTheTargetId(): void {
    self::assertSame(
      '<header></header><main id="main-content" class="wp-block-group"><main></main></main>',
      SiteBlocks::markMainContent('<header></header><main class="wp-block-group"><main></main></main>'),
    );
    self::assertSame('<main id="main-content">x</main>', SiteBlocks::markMainContent('<main>x</main>'));
  }

  /**
   * Tests a <main> with its own id, and markup without one, stay unchanged.
   */
  public function testOwnIdAndNoMainStayUnchanged(): void {
    $ownId = '<main class="site" id="content"></main><main></main>';
    self::assertSame($ownId, SiteBlocks::markMainContent($ownId));
    $noMain = '<div class="maintenance"><mainly></mainly></div>';
    self::assertSame($noMain, SiteBlocks::markMainContent($noMain));
  }

}
