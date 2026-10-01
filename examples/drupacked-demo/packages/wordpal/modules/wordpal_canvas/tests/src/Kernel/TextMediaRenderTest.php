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
 * Checks the text and media components print what WordPress's save prints.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class TextMediaRenderTest extends CanvasKernelTestBase {

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
   * Seeds theme settings and generates component definitions.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('request_stack')->getCurrentRequest()->setMethod('GET');
    $settings = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/components/settings.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $theme = $this->container->get('theme.manager')->getActiveTheme()->getName();
    $this->container->get('config.factory')->getEditable(ThemeSettings::configName($theme))->setData($settings)->save();
    $ids = array_map(static fn (string $slug): string => 'wordpal:' . $slug, [
      'cover', 'details', 'image', 'list', 'media-text', 'paragraph', 'spacer', 'video',
    ]);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', $ids);
  }

  /**
   * Tests a drop cap is not printed on centered or right-aligned text.
   *
   * Paragraph's save() turns has-drop-cap off for those alignments.
   */
  public function testParagraphDropCapSkipsCenterAndRight(): void {
    $drop = function (?string $align): string {
      $props = ['content' => 'Hello', 'drop_cap' => TRUE];
      if ($align) {
        $props['text_align'] = $align;
      }
      return $this->renderSdc('wordpal:paragraph', $props);
    };
    self::assertStringContainsString('has-drop-cap', $drop(NULL));
    self::assertStringContainsString('has-drop-cap', $drop('left'));
    self::assertStringNotContainsString('has-drop-cap', $drop('center'));
    self::assertStringNotContainsString('has-drop-cap', $drop('right'));
  }

  /**
   * Tests an ordered list prints its list style type and a start of 0.
   */
  public function testListPrintsTypeAndZeroStart(): void {
    $html = $this->renderSdc('wordpal:list', ['ordered' => TRUE, 'type' => 'upper-roman', 'start' => 0]);
    self::assertStringContainsString('list-style-type:upper-roman', $html);
    self::assertStringContainsString('start="0"', $html);
    $decimal = $this->renderSdc('wordpal:list', ['ordered' => TRUE, 'type' => 'decimal']);
    self::assertStringNotContainsString('list-style-type', $decimal);
    self::assertStringNotContainsString('start=', $decimal);
    $unordered = $this->renderSdc('wordpal:list', ['ordered' => FALSE, 'type' => 'upper-roman']);
    self::assertStringNotContainsString('list-style-type', $unordered);
  }

  /**
   * Tests a spacer prints no height when it fills or fits a flex parent.
   */
  public function testSpacerHeightSkipsFillAndFit(): void {
    self::assertStringContainsString('height:100px', $this->renderSdc('wordpal:spacer', ['height' => '100px']));
    $fixed = $this->renderSdc('wordpal:spacer', ['height' => '100px', 'self_stretch' => 'fixed']);
    self::assertStringContainsString('height:100px', $fixed);
    foreach (['fill', 'fit'] as $stretch) {
      $html = $this->renderSdc('wordpal:spacer', ['height' => '100px', 'self_stretch' => $stretch]);
      self::assertStringNotContainsString('height:100px', $html);
    }
  }

  /**
   * Tests a video background prints a video, and an embed prints no image.
   */
  public function testCoverVideoBackground(): void {
    $video = $this->renderSdc('wordpal:cover', [
      'url' => 'https://example.com/a.mp4',
      'background_type' => 'video',
      'poster' => 'https://example.com/p.jpg',
      'focal_point' => '20% 80%',
    ]);
    self::assertStringContainsString('<video class="wp-block-cover__video-background intrinsic-ignore" autoplay muted loop playsinline src="https://example.com/a.mp4" poster="https://example.com/p.jpg" style="object-position:20% 80%" data-object-fit="cover" data-object-position="20% 80%"></video>', $video);
    self::assertStringNotContainsString('<img', $video);
    $embed = $this->renderSdc('wordpal:cover', [
      'url' => 'https://www.youtube.com/watch?v=abc',
      'background_type' => 'embed-video',
    ]);
    self::assertStringNotContainsString('<img', $embed);
    self::assertStringNotContainsString('<video', $embed);
  }

  /**
   * Tests a parallax background keeps the focal point and the image class.
   */
  public function testCoverParallaxKeepsFocalPointAndImageClass(): void {
    $html = $this->renderSdc('wordpal:cover', [
      'url' => 'https://example.com/a.jpg',
      'id' => 5,
      'has_parallax' => TRUE,
      'focal_point' => '20% 80%',
    ]);
    self::assertStringContainsString('background-position:20% 80%', $html);
    self::assertStringContainsString('wp-block-cover__image-background wp-image-5', $html);
  }

  /**
   * Tests an image fill prints object-position, with the center as default.
   */
  public function testMediaTextImageFillPrintsObjectPosition(): void {
    $props = ['media_url' => 'https://example.com/a.jpg', 'media_type' => 'image', 'image_fill' => TRUE];
    $focused = $this->renderSdc('wordpal:media-text', $props + ['focal_point' => '10% 90%']);
    self::assertStringContainsString('style="object-position:10% 90%"', $focused);
    $centered = $this->renderSdc('wordpal:media-text', $props);
    self::assertStringContainsString('style="object-position:50% 50%"', $centered);
    $off = $this->renderSdc('wordpal:media-text', ['image_fill' => FALSE] + $props);
    self::assertStringNotContainsString('object-position', $off);
  }

  /**
   * Tests a video prints the aspect ratio of its saved width and height.
   */
  public function testVideoPrintsAspectRatio(): void {
    $html = $this->renderSdc('wordpal:video', ['src' => 'https://example.com/a.mp4', 'width' => 640, 'height' => 360]);
    self::assertStringContainsString('style="aspect-ratio:640 / 360"', $html);
    self::assertStringNotContainsString('aspect-ratio', $this->renderSdc('wordpal:video', ['src' => 'https://example.com/a.mp4']));
  }

  /**
   * Tests a closed Details block marks its images fetchpriority low.
   */
  public function testDetailsLowersImagePriorityWhenClosed(): void {
    $slots = ['content' => ['#markup' => '<figure class="wp-block-image"><img src="a.jpg" alt=""></figure>']];
    $closed = $this->renderSdc('wordpal:details', ['summary' => 'More', 'show_content' => FALSE], $slots);
    self::assertStringContainsString('fetchpriority="low"', $closed);
    $props = ['summary' => 'More <img src="b.jpg" alt="">', 'show_content' => FALSE];
    $inSummary = $this->renderSdc('wordpal:details', $props);
    self::assertMatchesRegularExpression('#<summary>More <img[^>]*fetchpriority="low"></summary>#', $inSummary);
    $open = $this->renderSdc('wordpal:details', ['summary' => 'More', 'show_content' => TRUE], $slots);
    self::assertStringNotContainsString('fetchpriority', $open);
  }

  /**
   * Tests an image prints its link class and the alignnone class.
   */
  public function testImagePrintsLinkClassAndAlignNone(): void {
    $html = $this->renderSdc('wordpal:image', [
      'url' => 'https://example.com/a.jpg',
      'href' => 'https://example.com',
      'link_class' => 'my-link',
      'align' => 'none',
    ]);
    self::assertStringContainsString('<a class="my-link" href="https://example.com">', $html);
    self::assertStringContainsString('alignnone', $html);
  }

  /**
   * Renders one component.
   */
  private function renderSdc(string $component, array $props, array $slots = []): string {
    $build = ['#type' => 'component', '#component' => $component, '#props' => $props, '#slots' => $slots];
    return (string) $this->container->get(RendererInterface::class)->renderRoot($build);
  }

}
