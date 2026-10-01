<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Query View pager prints the Query Pagination block.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class QueryPagerTest extends KernelTestBase {

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
   * Tests the pager renders the pagination settings the View holds.
   */
  public function testPagerRendersQueryPagination(): void {
    $view = View::create([
      'id' => 'wordpal_query_pager_test',
      'tag' => 'wordpal_query',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_options' => [
            'pager' => ['type' => 'wordpal_query', 'options' => ['items_per_page' => 1, 'id' => 0]],
          ],
        ],
      ],
    ]);
    $view->setThirdPartySetting('wordpal', 'query', [
      'post_template' => [],
      'bundles' => [],
      'pagination' => [],
      'next' => ['label' => 'Next Page'],
      'pagination_order' => ['next'],
    ]);
    $executable = $view->getExecutable();
    $executable->setDisplay('default');
    $executable->initPager();
    $this->container->get('pager.manager')->createPager(3, 1, 0);

    $build = $executable->pager->render([]);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertStringContainsString('wp-block-query-pagination', $html);
    self::assertStringContainsString('Next Page', $html);
    self::assertStringNotContainsString('pager__items', $html);
  }

}
