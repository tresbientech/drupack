<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\wordpal\Layout\LayoutRenderer;
use Drupal\wordpal_canvas_runtime\Plugin\views\area\PatternArea;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal_canvas\CanvasWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests query pager and empty-result runtime markup.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class QueryRuntimeTest extends CanvasKernelTestBase {

  /**
   * Modules required for query component rendering.
   *
   * @var string[]
   */
  protected static $modules = ['comment', 'wordpal', 'wordpal_convert', 'wordpal_canvas', 'wordpal_canvas_runtime'];

  /**
   * Generates the query runtime components.
   */
  protected function setUp(): void {
    parent::setUp();
    $theme = $this->container->get('theme.manager')->getActiveTheme()->getName();
    $this->container->get('config.factory')->getEditable(ThemeSettings::configName($theme))->save();
    $components = [
      'query',
      'post-template',
      'query-pagination',
      'query-pagination-previous',
      'query-pagination-numbers',
      'query-pagination-next',
      'query-no-results',
      'paragraph',
      'group',
      'spacer',
    ];
    $ids = array_map(static fn (string $slug): string => 'wordpal:' . $slug, $components);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', $ids);
  }

  /**
   * Tests the ellipses sit on their own line, as paginate_links() prints them.
   */
  public function testEllipsesJoinWithNewlines(): void {
    $this->container->get('request_stack')->getCurrentRequest()->query->set('page', '9');
    $this->container->get('pager.manager')->createPager(20, 1, 0);
    $build = [
      '#type' => 'pager',
      '#theme' => 'pager__wordpal_query_settings',
      '#element' => 0,
      '#quantity' => 5,
      '#wordpal_settings' => $this->settings(),
    ];

    $markup = (string) $this->container->get(RendererInterface::class)->renderRoot($build);

    self::assertMatchesRegularExpression('#<div[^>]* class="wp-block-query-pagination-numbers"><span class="page-numbers dots">…</span>\n<a class="page-numbers" href="[^"]+">8</a>\n#', $markup);
    self::assertMatchesRegularExpression('#>12</a>\n<span class="page-numbers dots">…</span></div>#', $markup);
  }

  /**
   * Tests the pagination's own supports stay off its links.
   *
   * WordPress 7.1.2 prints each link block's own get_block_wrapper_attributes()
   * (query-pagination-previous.php, -numbers.php, -next.php).
   */
  public function testPaginationValuesStayOffLinks(): void {
    $this->container->get('request_stack')->getCurrentRequest()->query->set('page', '1');
    $this->container->get('pager.manager')->createPager(3, 1, 0);
    $settings = $this->settings();
    $settings['pagination'] += ['font_style' => 'italic', 'css_class' => 'is-pager'];
    $settings['previous'] += ['font_weight' => '500'];
    $build = [
      '#type' => 'pager',
      '#theme' => 'pager__wordpal_query_settings',
      '#element' => 0,
      '#quantity' => 5,
      '#wordpal_settings' => $settings,
    ];

    $document = new \DOMDocument();
    @$document->loadHTML('<body>' . $this->container->get(RendererInterface::class)->renderRoot($build) . '</body>');
    $xpath = new \DOMXPath($document);
    $pagination = $xpath->query('//nav')->item(0);
    self::assertStringContainsString('is-pager', $pagination->getAttribute('class'));
    self::assertStringContainsString('font-style:italic', $pagination->getAttribute('style'));
    $previous = $xpath->query('//a[contains(@class, "wp-block-query-pagination-previous")]')->item(0);
    self::assertSame('font-weight:500', $previous->getAttribute('style'));
    foreach (['previous', 'numbers', 'next'] as $link) {
      $element = $xpath->query("//*[contains(@class, 'wp-block-query-pagination-$link')]")->item(0);
      self::assertStringNotContainsString('is-pager', $element->getAttribute('class'));
      self::assertStringNotContainsString('italic', $element->getAttribute('style'));
    }
  }

  /**
   * Tests previous, numbered, and next links on page two.
   */
  public function testSecondPageMarkup(): void {
    $this->container->get('request_stack')->getCurrentRequest()->query->set('page', '1');
    $this->container->get('pager.manager')->createPager(3, 1, 0);
    $build = [
      '#type' => 'pager',
      '#theme' => 'pager__wordpal_query_settings',
      '#element' => 0,
      '#quantity' => 5,
      '#wordpal_settings' => $this->settings(),
    ];

    $markup = (string) $this->container->get(RendererInterface::class)->renderRoot($build);

    self::assertStringContainsString('wp-block-query-pagination', $markup);
    self::assertStringContainsString('wp-block-query-pagination-previous', $markup);
    // WordPress's paginate_links() joins the numbers with a newline.
    self::assertMatchesRegularExpression('#>1</a>\n<span aria-current="page" class="page-numbers current">2</span>\n<a class="page-numbers" href="[^"]+">3</a></div>#', $markup);
    self::assertStringContainsString('wp-block-query-pagination-next', $markup);
    $previous = 'wp-block-query-pagination-previous-arrow is-arrow-arrow" '
      . 'aria-hidden="true">←</span>Previous Page';
    self::assertStringContainsString($previous, $markup);
    $next = 'Next Page<span class="wp-block-query-pagination-next-arrow is-arrow-arrow"';
    self::assertStringContainsString($next, $markup);
  }

  /**
   * Tests the empty View content keeps its WordPress wrapper.
   */
  public function testEmptyResultsMarkup(): void {
    $build = $this->emptyPattern('empty_fixture');
    self::assertContains('config:canvas.pattern.empty_fixture', $build['#cache']['tags']);

    $markup = (string) $this->container->get(RendererInterface::class)->renderRoot($build);

    self::assertStringContainsString('class="wp-block-query-no-results"', $markup);
    self::assertStringContainsString('class="wp-block-paragraph">No posts were found.</p>', $markup);
  }

  /**
   * Tests nested components preserve the query child and marker order.
   */
  public function testQueryLayoutMarkerOrder(): void {
    $layout = [
      [
        'component' => 'wordpal:group',
        'props' => [],
        'children' => [
          ['component' => 'wordpal:spacer', 'props' => ['height' => '10px'], 'children' => []],
          ['marker' => 'rows'],
          ['component' => 'wordpal:spacer', 'props' => ['height' => '20px'], 'children' => []],
        ],
      ],
      ['marker' => 'pager'],
      ['marker' => 'empty'],
    ];
    $rows = ['#markup' => '<div class="runtime-rows">Rows</div>'];
    $pager = ['#markup' => '<nav class="runtime-pager">Pager</nav>'];
    $build = LayoutRenderer::build($layout, ['rows' => $rows, 'pager' => $pager, 'empty' => []]);

    $markup = (string) $this->container->get(RendererInterface::class)->renderRoot($build);

    self::assertStringContainsString('height:10px', $markup);
    self::assertStringContainsString('height:20px', $markup);
    self::assertLessThan(strpos($markup, 'runtime-rows'), strpos($markup, 'height:10px'));
    self::assertLessThan(strpos($markup, 'height:20px'), strpos($markup, 'runtime-rows'));
    self::assertLessThan(strpos($markup, 'runtime-pager'), strpos($markup, 'height:20px'));
    self::assertStringNotContainsString('wp-block-query-no-results', $markup);

    $empty = $this->emptyPattern('layout_empty_fixture');
    $build = LayoutRenderer::build($layout, ['rows' => [], 'pager' => [], 'empty' => $empty]);
    $markup = (string) $this->container->get(RendererInterface::class)->renderRoot($build);
    self::assertStringNotContainsString('runtime-rows', $markup);
    self::assertStringNotContainsString('runtime-pager', $markup);
    self::assertLessThan(strpos($markup, 'wp-block-query-no-results'), strpos($markup, 'height:20px'));
  }

  /**
   * Creates and renders one Pattern that owns the no-results wrapper.
   */
  private function emptyPattern(string $id): array {
    $pattern = $this->container->get(CanvasWriter::class)->writePattern(
      $id,
      'Empty fixture',
      [
        new BlockNode('core/query-no-results', [], '', [
          new BlockNode('core/paragraph', ['content' => 'No posts were found.'], '', []),
        ]),
      ],
      new ContentMapping([]),
    );
    $plugin = new PatternArea([], 'wordpal_pattern', [], $this->container->get('entity_type.manager'));
    $plugin->options = ['pattern_id' => $pattern->id(), 'vocabularies' => [], 'negate' => FALSE];
    return $plugin->render(TRUE);
  }

  /**
   * Returns the View third-party settings used by the pager template.
   */
  private function settings(): array {
    return [
      'pagination' => [
        'pagination_arrow' => 'arrow',
        'layout_type' => 'flex',
        'justify_content' => 'space-between',
      ],
      'previous' => ['label' => 'Previous Page'],
      'numbers' => [],
      'next' => ['label' => 'Next Page'],
      'pagination_order' => ['previous', 'numbers', 'next'],
    ];
  }

}
