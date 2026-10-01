<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal_convert\Component\ComponentInputs;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks layout and query components against WordPress's markup.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class LayoutQueryRenderTest extends CanvasKernelTestBase {

  /**
   * The components under test.
   */
  private const COMPONENTS = [
    'button', 'column', 'post-template', 'query-no-results',
    'query-pagination-next', 'query-pagination-previous', 'query-title',
    'social-link', 'social-links',
  ];

  /**
   * Modules required to exercise generated components.
   *
   * @var string[]
   */
  protected static $modules = [
    'comment',
    'node',
    'wordpal',
    'wordpal_convert',
    'wordpal_canvas', 'wordpal_canvas_runtime',
  ];

  /**
   * Seeds theme settings and generates component definitions.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('request_stack')->getCurrentRequest()->setMethod('GET');
    $settings = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/components/settings.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $theme = $this->container->get('theme.manager')->getActiveTheme()->getName();
    $this->container->get('config.factory')->getEditable(ThemeSettings::configName($theme))->setData($settings)->save();
    $ids = array_map(static fn (string $slug): string => 'wordpal:' . $slug, self::COMPONENTS);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', $ids);
  }

  /**
   * Tests a percentage width prints the percentage classes.
   */
  public function testButtonPercentageWidth(): void {
    $html = $this->renderSdc('wordpal:button', ['text' => 'One', 'width' => '50%']);
    self::assertStringContainsString('wp-block-button__width-50', $html);
    self::assertStringContainsString('--wp--block-button--width: 50;', $html);
    $html = $this->renderSdc('wordpal:button', ['text' => 'One', 'width' => '33%']);
    self::assertStringContainsString('wp-block-button__width', $html);
    self::assertStringContainsString('--wp--block-button--width: 33;', $html);
    self::assertStringNotContainsString('wp-block-button__width-33', $html);
  }

  /**
   * Tests a width preset the conversion left a reference prints its variable.
   */
  public function testButtonWidthPresetStaysReference(): void {
    $html = $this->renderSdc('wordpal:button', ['text' => 'One', 'width' => 'var:preset|dimension|wide']);
    self::assertStringContainsString('width: var(--wp--preset--dimension--wide);', $html);
    self::assertStringNotContainsString('wp-block-button__width', $html);
  }

  /**
   * Tests a button without text prints nothing.
   */
  public function testEmptyButtonPrintsNothing(): void {
    self::assertSame('', trim($this->renderSdc('wordpal:button', ['text' => '', 'background_color' => 'primary'])));
  }

  /**
   * Tests a button whose text is one space still prints.
   */
  public function testButtonWithSpaceTextPrints(): void {
    self::assertStringContainsString('wp-block-button__link', $this->renderSdc('wordpal:button', ['text' => ' ']));
  }

  /**
   * Tests a button tag keeps its type.
   */
  public function testButtonTagKeepsItsType(): void {
    $html = $this->renderSdc('wordpal:button', ['text' => 'Send', 'tag_name' => 'button', 'type' => 'submit']);
    self::assertStringContainsString('type="submit"', $html);
  }

  /**
   * Tests a zero border radius adds no-border-radius.
   */
  public function testButtonZeroRadiusClass(): void {
    $html = $this->renderSdc('wordpal:button', ['text' => 'Go', 'border_radius' => '0']);
    self::assertMatchesRegularExpression('/class="[^"]*\bno-border-radius\b/', $html);
    $rounded = $this->renderSdc('wordpal:button', ['text' => 'Go', 'border_radius' => '4px']);
    self::assertDoesNotMatchRegularExpression('/\bno-border-radius\b/', $rounded);
  }

  /**
   * Tests only a numeric zero radius reaches the button as no-border-radius.
   */
  public function testButtonStringZeroRadiusAddsNoClass(): void {
    $inputs = $this->container->get(ComponentInputs::class);
    $skipped = [];
    $number = $inputs->forNode(new BlockNode('core/button', ['style' => ['border' => ['radius' => 0]]], '', []), $skipped);
    $string = $inputs->forNode(new BlockNode('core/button', ['style' => ['border' => ['radius' => '0']]], '', []), $skipped);
    self::assertMatchesRegularExpression('/\bno-border-radius\b/', $this->renderSdc('wordpal:button', ['text' => 'Go'] + $number));
    self::assertDoesNotMatchRegularExpression('/\bno-border-radius\b/', $this->renderSdc('wordpal:button', ['text' => 'Go'] + $string));
  }

  /**
   * Tests a percentage column width rounds to twelve decimals.
   */
  public function testColumnWidthRounds(): void {
    $html = $this->renderSdc('wordpal:column', ['width' => '33.33333333333333%']);
    self::assertStringContainsString('flex-basis:33.333333333333%', $html);
  }

  /**
   * Tests a Social Icon without a service names the Icon service.
   */
  public function testSocialLinkWithoutServiceClass(): void {
    $html = $this->renderSdc('wordpal:social-link', ['url' => 'https://example.com']);
    self::assertStringContainsString('wp-social-link-Icon', $html);
  }

  /**
   * Tests the Logos Only style drops the icon background color.
   */
  public function testLogosOnlyDropsIconBackground(): void {
    $props = ['icon_background_color_value' => '#cf2e2e'];
    self::assertStringNotContainsString('has-icon-background-color', $this->renderSdc('wordpal:social-links', $props + ['css_class' => 'is-style-logos-only']));
    self::assertStringContainsString('has-icon-background-color', $this->renderSdc('wordpal:social-links', $props));
  }

  /**
   * Tests a hidden label moves to an aria-label for an arrow only.
   */
  public function testPaginationHiddenLabel(): void {
    $props = ['url' => '?page=2', 'label' => 'Next Page', 'show_label' => FALSE];
    $html = $this->renderSdc('wordpal:query-pagination-next', $props + ['pagination_arrow' => 'arrow']);
    self::assertStringContainsString('aria-label="Next Page"', $html);
    self::assertStringNotContainsString('>Next Page', $html);
    $previous = $this->renderSdc('wordpal:query-pagination-previous', [
      'url' => '?page=1',
      'label' => 'Previous Page',
      'show_label' => FALSE,
      'pagination_arrow' => 'chevron',
    ]);
    self::assertStringContainsString('aria-label="Previous Page"', $previous);
    $none = $this->renderSdc('wordpal:query-pagination-next', $props + ['pagination_arrow' => 'none']);
    self::assertStringNotContainsString('aria-label', $none);
    self::assertStringContainsString('Next Page</a>', $none);
  }

  /**
   * Tests an empty saved label takes the default label.
   */
  public function testPaginationEmptyLabelTakesDefault(): void {
    $inputs = $this->container->get(ComponentInputs::class);
    $skipped = [];
    $props = $inputs->forNode(new BlockNode('core/query-pagination-next', ['label' => ''], '', []), $skipped);
    self::assertSame('Next Page', $props['label']);
  }

  /**
   * Tests a grid with a minimum column width keeps its responsive columns.
   */
  public function testPostTemplateNativeResponsiveGrid(): void {
    $grid = ['layout_type' => 'grid', 'column_count' => 3];
    self::assertStringContainsString('has-native-responsive-grid', $this->renderSdc('wordpal:post-template', $grid + ['minimum_column_width' => '12rem']));
    self::assertStringNotContainsString('has-native-responsive-grid', $this->renderSdc('wordpal:post-template', $grid));
  }

  /**
   * Tests Query No Results merges block supports and hides when empty.
   */
  public function testQueryNoResults(): void {
    $html = $this->renderSdc('wordpal:query-no-results', [
      'align' => 'wide',
      'anchor' => 'none',
      'background_color' => 'var:preset|color|contrast',
      'text_color' => 'var:preset|color|base',
      'font_size' => 'var:preset|font-size|large',
    ], ['content' => '<p>No posts.</p>']);
    $expectedParts = [
      'wp-block-query-no-results', 'alignwide', 'has-contrast-background-color',
      'has-base-color', 'has-large-font-size', 'id="none"',
    ];
    foreach ($expectedParts as $expected) {
      self::assertStringContainsString($expected, $html);
    }
    self::assertSame('', trim($this->renderSdc('wordpal:query-no-results', ['background_color' => 'contrast'])));
  }

  /**
   * Tests the post-type title names the post bundle.
   */
  public function testQueryTitlePostType(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $props = ['type' => 'post-type', 'post_bundle' => 'article'];
    self::assertStringContainsString('Post Type: “Article”', $this->renderSdc('wordpal:query-title', $props + ['show_prefix' => TRUE]));
    $bare = $this->renderSdc('wordpal:query-title', $props + ['show_prefix' => FALSE]);
    self::assertStringContainsString('>Article</h1>', $bare);
  }

  /**
   * Tests only a Query's own title carries the mapped post bundle.
   */
  public function testQueryTitleTakesTheBundleInsideQuery(): void {
    $title = new BlockNode('core/query-title', [], '', []);
    $mapping = new ContentMapping(['post' => 'article']);
    self::assertSame('article', ComponentInputs::mapped($title, $mapping, TRUE)['post_bundle']);
    self::assertArrayNotHasKey('post_bundle', ComponentInputs::mapped($title, $mapping, FALSE));
    self::assertSame('article', ComponentInputs::mapped($title, $mapping, FALSE)['listing_bundle']);
    self::assertArrayNotHasKey('listing_bundle', ComponentInputs::mapped($title, $mapping, TRUE));
  }

  /**
   * Renders one component with props and slots.
   */
  private function renderSdc(string $component, array $props, array $slots = []): string {
    $build = ['#type' => 'component', '#component' => $component, '#props' => $props, '#slots' => $slots];
    return (string) $this->container->get(RendererInterface::class)->renderRoot($build);
  }

}
