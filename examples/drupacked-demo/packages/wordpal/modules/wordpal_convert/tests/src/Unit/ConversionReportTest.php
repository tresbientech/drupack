<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\ConversionReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the dropped-concept lines of the conversion report.
 */
#[CoversClass(ConversionReport::class)]
#[Group('wordpal')]
final class ConversionReportTest extends UnitTestCase {

  /**
   * Builds a report, defaulting every field a test does not name.
   */
  private static function report(array $fields = []): ConversionReport {
    return new ConversionReport(...$fields + [
      'created' => [],
      'skipped' => [],
      'skippedPatterns' => [],
      'dropped' => [],
      'providers' => [],
      'unregistered' => [],
      'invalid' => [],
      'frozen' => [],
      'demoContent' => NULL,
      'droppedOptions' => [],
      'disabledViews' => [],
      'takenOverTemplates' => [],
      'targetLabel' => 'Canvas',
      'templatePaths' => [],
      'variations' => [],
      'menus' => [],
      'frozenStyles' => [],
      'unreadStyles' => [],
      'pluginLicenses' => [],
    ]);
  }

  /**
   * Tests one line per concept with dropped blocks, naming its provider.
   */
  public function testDroppedLinesNameProviders(): void {
    $report = self::report([
      'dropped' => ['navigation' => 1, 'comments' => 3, 'author_biography' => 0],
      'providers' => [
        'category' => 'a core Taxonomy vocabulary',
        'author_biography' => 'a user text field',
        'comments' => 'a comment field on the post bundle',
      ],
    ]);

    self::assertSame([
      'navigation: 1 dropped block',
      'comments: 3 dropped blocks; add a comment field on the post bundle and map it',
    ], $report->droppedLines());
  }

  /**
   * Tests that the Demo content lines leave out entity types with none.
   */
  public function testDemoContentLinesSkipZeroCounts(): void {
    $report = self::report([
      'demoContent' => [
        'uuids' => ['comment' => [], 'node' => ['a', 'b'], 'user' => ['c']],
        'skipped' => [],
      ],
    ]);

    self::assertSame(['node: 2', 'user: 1'], $report->demoContentLines());
  }

  /**
   * Tests one line per unregistered namespace, naming its trees.
   */
  public function testUnregisteredLinesNameTrees(): void {
    $report = self::report([
      'unregistered' => [
        'woocommerce' => ['Template part header', 'theme/shop'],
        'wpforms' => ['theme/contact'],
      ],
    ]);

    self::assertSame([
      'woocommerce: Template part header, theme/shop',
      'wpforms: theme/contact',
    ], $report->unregisteredLines());
  }

  /**
   * Tests one line per tree holding a block WordPress's parser rejects.
   */
  public function testInvalidLinesNameBlocks(): void {
    $report = self::report([
      'invalid' => [
        'Template part header' => ['core/image'],
        'theme/hero' => ['core/paragraph', 'core/heading', 'core/paragraph'],
      ],
    ]);

    self::assertSame([
      'Template part header: core/image',
      'theme/hero: core/paragraph (2), core/heading',
    ], $report->invalidLines());
  }

  /**
   * Tests one line per tree holding Frozen blocks, naming its blocks.
   */
  public function testFrozenLinesNameBlocks(): void {
    $report = self::report([
      'frozen' => [
        'Template part header' => ['woocommerce/mini-cart'],
        'twentytwentyfour/page-home-blogging' => ['core/avatar', 'core/audio', 'core/avatar'],
      ],
    ]);

    self::assertSame([
      'Template part header: woocommerce/mini-cart',
      'twentytwentyfour/page-home-blogging: core/avatar (2), core/audio',
    ], $report->frozenLines());
  }

  /**
   * Tests one line per Frozen block carrying plugin stylesheets.
   */
  public function testFrozenStyleLinesNameStylesheets(): void {
    $report = self::report([
      'frozenStyles' => [
        'Template part header' => [
          'woocommerce/mini-cart: wc-blocks-style, wc-blocks-style-mini-cart',
          'woocommerce/mini-cart: wc-blocks-style, wc-blocks-style-mini-cart',
        ],
        'theme/shop' => ['woocommerce/breadcrumbs: wc-blocks-style-breadcrumbs'],
      ],
    ]);

    self::assertSame([
      'Template part header: woocommerce/mini-cart: wc-blocks-style, wc-blocks-style-mini-cart',
      'theme/shop: woocommerce/breadcrumbs: wc-blocks-style-breadcrumbs',
    ], $report->frozenStyleLines());
  }

  /**
   * Tests unread style lines name each tree once per block and path.
   */
  public function testUnreadStyleLinesNameTrees(): void {
    $report = self::report([
      'unreadStyles' => [
        'Template single' => ['core/image: style.color.duotone', 'core/image: style.color.duotone'],
        'theme/hero' => ['core/group: style.elements.link.:hover.color.text'],
      ],
    ]);

    self::assertSame([
      'Template single: core/image: style.color.duotone',
      'theme/hero: core/group: style.elements.link.:hover.color.text',
    ], $report->unreadStyleLines());
  }

  /**
   * Tests one line per block option written without its behavior.
   */
  public function testDroppedOptionLinesNameTrees(): void {
    $report = self::report([
      'droppedOptions' => [
        'Template part footer' => [
          'core/latest-posts: full post content, rendered as the excerpt',
          'core/latest-posts: full post content, rendered as the excerpt',
        ],
      ],
    ]);

    self::assertSame(['Template part footer: core/latest-posts: full post content, rendered as the excerpt'], $report->droppedOptionLines());
  }

  /**
   * Tests one line per style variation, naming its setting slug.
   */
  public function testVariationLinesNameSlugs(): void {
    $report = self::report([
      'variations' => [
        'default' => 'Default',
        'ember' => 'Ember',
      ],
    ]);

    self::assertSame(['default: Default', 'ember: Ember'], $report->variationLines());
  }

  /**
   * Tests one line per created menu, naming the trees that bind it.
   */
  public function testMenuLinesNameTrees(): void {
    $report = self::report([
      'menus' => [
        'wordpal-0123456789ab' => [
          'label' => 'Theme: About',
          'links' => [],
          'trees' => ['theme/footer', 'Template part footer'],
        ],
      ],
    ]);

    self::assertSame(['wordpal-0123456789ab (Theme: About): theme/footer, Template part footer'], $report->menuLines());
  }

}
