<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\wordpal\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks the post field components against WordPress's render callbacks.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class PostFieldsRenderTest extends CanvasKernelTestBase {

  /**
   * Modules required to exercise generated components.
   *
   * @var string[]
   */
  protected static $modules = [
    'comment',
    'wordpal',
    'wordpal_convert',
    'wordpal_canvas', 'wordpal_canvas_runtime',
  ];

  /**
   * Seeds theme settings and generates the post field components.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('request_stack')->getCurrentRequest()->setMethod('GET');
    $settings = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/components/settings.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $theme = $this->container->get('theme.manager')->getActiveTheme()->getName();
    $this->container->get('config.factory')->getEditable(ThemeSettings::configName($theme))->setData($settings)->save();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', [
      'wordpal:post-title',
      'wordpal:post-excerpt',
      'wordpal:post-author',
      'wordpal:post-terms',
      'wordpal:post-featured-image',
    ]);
  }

  /**
   * Level 0 prints a paragraph, as post-title.php does.
   */
  public function testPostTitleLevelZeroPrintsParagraph(): void {
    $html = $this->renderSdc('wordpal:post-title', ['title' => 'Walk', 'level' => 0, 'is_link' => FALSE]);
    self::assertMatchesRegularExpression('#<p [^>]*class="wp-block-post-title">Walk</p>#', $html);
  }

  /**
   * The link carries the saved target and rel.
   */
  public function testPostTitleLinkCarriesTargetAndRel(): void {
    $html = $this->renderSdc('wordpal:post-title', [
      'title' => 'Walk',
      'url' => '/walk',
      'is_link' => TRUE,
      'link_target' => '_blank',
      'rel' => 'nofollow',
    ]);
    self::assertStringContainsString('<a href="/walk" target="_blank" rel="nofollow">Walk</a>', $html);
  }

  /**
   * Trims the excerpt to excerpt_length words and ends with an ellipsis.
   */
  public function testPostExcerptTrimsToLength(): void {
    $props = ['excerpt' => '<p>One two three four five</p>', 'url' => '/x', 'excerpt_length' => 3];
    self::assertStringContainsString('>One two three…', $this->renderSdc('wordpal:post-excerpt', $props));
    $props['excerpt_length'] = 5;
    $html = $this->renderSdc('wordpal:post-excerpt', $props);
    self::assertStringContainsString('>One two three four five', $html);
    self::assertStringNotContainsString('…', $html);
  }

  /**
   * An empty separator prints a space.
   */
  public function testPostTermsEmptySeparatorPrintsSpace(): void {
    $html = $this->renderSdc('wordpal:post-terms', [
      'terms' => ['A', 'B'],
      'term_urls' => ['/a', '/b'],
      'taxonomy' => 'post_tag',
      'separator' => '',
    ]);
    self::assertStringContainsString('<span class="wp-block-post-terms__separator"> </span>', $html);
  }

  /**
   * Prefix and suffix keep their markup.
   */
  public function testPostTermsPrefixAndSuffixKeepMarkup(): void {
    $html = $this->renderSdc('wordpal:post-terms', [
      'terms' => ['A'],
      'term_urls' => ['/a'],
      'taxonomy' => 'category',
      'prefix' => '<strong>Filed under:</strong> ',
      'suffix' => ' <em>end</em>',
    ]);
    self::assertStringContainsString('<span class="wp-block-post-terms__prefix"><strong>Filed under:</strong> </span>', $html);
    self::assertStringContainsString('<span class="wp-block-post-terms__suffix"> <em>end</em></span>', $html);
  }

  /**
   * The featured image link carries the saved target and rel.
   */
  public function testFeaturedImageLinkCarriesTargetAndRel(): void {
    $html = $this->renderSdc('wordpal:post-featured-image', [
      'src' => 'https://example.com/x.jpg',
      'url' => '/walk',
      'is_link' => TRUE,
      'link_target' => '_blank',
      'rel' => 'noopener',
    ]);
    self::assertStringContainsString('<a href="/walk" target="_blank" rel="noopener">', $html);
  }

  /**
   * A linked image takes its alt from the post title.
   */
  public function testFeaturedImageLinkedAltIsPostTitle(): void {
    $props = ['src' => 'https://example.com/x.jpg', 'url' => '/walk', 'alt' => 'Media alt', 'title' => 'A <b>walk</b>'];
    $linked = $this->renderSdc('wordpal:post-featured-image', $props + ['is_link' => TRUE]);
    self::assertStringContainsString('alt="A walk"', $linked);
    $plain = $this->renderSdc('wordpal:post-featured-image', $props + ['is_link' => FALSE]);
    self::assertStringContainsString('alt="Media alt"', $plain);
  }

  /**
   * Renders one component with the given props.
   */
  private function renderSdc(string $component, array $props): string {
    $build = ['#type' => 'component', '#component' => $component, '#props' => $props];
    return (string) $this->container->get(RendererInterface::class)->renderRoot($build);
  }

}
