<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\wordpal\Query\QuerySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the `wordpal.query` third-party setting reader.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class QuerySettingsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'system', 'user', 'field', 'node', 'text', 'filter', 'views',
    'comment', 'link', 'menu_link_content',
  ];

  /**
   * Tests a missing always-written key throws, naming the View and the key.
   */
  #[DataProvider('requiredKeys')]
  public function testMissingKeyThrows(string $missingKey): void {
    $settings = [
      'query' => ['a' => 1],
      'post_template' => ['b' => 2],
      'layout' => [['marker' => 'rows']],
      'bundles' => ['article' => ['type' => 'post']],
      'pagination' => [],
    ];
    unset($settings[$missingKey]);
    $view = View::create(['id' => 'wordpal_query_settings_test', 'tag' => 'wordpal_query']);
    $view->setThirdPartySetting('wordpal', 'query', $settings);

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage("View \"wordpal_query_settings_test\" has no \"$missingKey\" in its wordpal.query setting.");

    QuerySettings::fromView($view);
  }

  /**
   * Returns each key `fromView()` requires from every writer.
   */
  public static function requiredKeys(): array {
    return [
      'post_template' => ['post_template'],
      'bundles' => ['bundles'],
      'pagination' => ['pagination'],
    ];
  }

  /**
   * Tests Canvas's own `query` and `layout` keys default when absent.
   *
   * A target with no `wordpal:query` embed of its own, such as Display
   * Builder's ListingWriter, writes no `query` or `layout` key.
   */
  public function testQueryAndLayoutDefaultWhenAbsent(): void {
    $view = View::create(['id' => 'wordpal_query_settings_no_layout_test', 'tag' => 'wordpal_query']);
    $view->setThirdPartySetting('wordpal', 'query', [
      'post_template' => [],
      'bundles' => [],
      'pagination' => [],
    ]);

    $settings = QuerySettings::fromView($view);

    self::assertSame([], $settings->query);
    self::assertSame([], $settings->layout);
  }

  /**
   * Tests the pager slice keeps only the pagination children the writer set.
   */
  public function testPagerSliceOmitsUnwrittenChildren(): void {
    $view = View::create(['id' => 'wordpal_query_settings_pager_test', 'tag' => 'wordpal_query']);
    $view->setThirdPartySetting('wordpal', 'query', [
      'query' => [],
      'post_template' => [],
      'layout' => [],
      'bundles' => [],
      'pagination' => ['pagination_arrow' => 'arrow'],
      'numbers' => [],
      'next' => ['label' => 'Next Page'],
      'pagination_order' => ['numbers', 'next'],
    ]);

    $settings = QuerySettings::fromView($view);

    self::assertSame([
      'pagination' => ['pagination_arrow' => 'arrow'],
      'numbers' => [],
      'next' => ['label' => 'Next Page'],
      'pagination_order' => ['numbers', 'next'],
    ], $settings->pagerSettings);
    self::assertArrayNotHasKey('previous', $settings->pagerSettings);
  }

}
