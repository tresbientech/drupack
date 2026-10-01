<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockParser;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal_canvas\CanvasWriter;
use Drupal\wordpal_evaluate\Parity\MarkupComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Twig\Error\RuntimeError;

/**
 * Checks WordPress component rendering and attachment caching.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ComponentRenderTest extends CanvasKernelTestBase {

  /**
   * The components expected in the saved patterns.
   */
  private const COMPONENTS = [
    'button', 'buttons', 'column', 'columns', 'cover', 'group', 'heading',
    'image', 'list-item', 'list', 'paragraph', 'separator', 'spacer',
  ];

  /**
   * Raw and rendered Twenty Twenty-Four 1.6 patterns from WordPress 7.1.1.
   */
  private const FIXTURES = [
    'cta-pricing.html',
    'text-alternating-images.html',
    'gallery-full-screen-image.html',
  ];

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
    $ids = array_map(static fn (string $slug): string => 'wordpal:' . $slug, self::COMPONENTS);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', $ids);
  }

  /**
   * Checks every fixture and expected component ID.
   */
  public function testSavedTwentyTwentyFourPatternsRenderEveryComponent(): void {
    $seen = [];
    foreach (self::FIXTURES as $fixture) {
      $seen = [...$seen, ...$this->assertFixtureRender($fixture)];
    }
    self::assertEqualsCanonicalizing(array_map(static fn (string $slug): string => 'sdc.wordpal.' . $slug, self::COMPONENTS), array_unique($seen));
  }

  /**
   * Renders and checks one saved WordPress fixture.
   */
  private function assertFixtureRender(string $fixture): array {
    $pattern = $this->writeFixture($fixture);
    $tree = $pattern->getComponentTree();
    $seen = array_column($tree->getValue(), 'component_id');
    $build = $tree->toRenderable($pattern);
    $build['#cache']['keys'] = ['wordpal', 'saved-pattern', $fixture];
    $rendered = $this->assertLayoutAttachmentsSurviveRenderCache($build);
    $this->assertWordPressMarkup($fixture, $rendered['markup']);
    $this->assertProcessedLayoutStylesAreDeduplicated($rendered);
    if ($fixture === 'text-alternating-images.html') {
      $this->assertRepeatedLayoutSharesOneCssRule($rendered);
    }
    return $seen;
  }

  /**
   * Checks post-date's timestamp formatting bubbles the timezone context.
   *
   * ComponentCatalogTest checks every rich-text prop's CKEditor field, but
   * this bubbling is a render-time effect of WordpalExtension::date(), only
   * observable by actually rendering the component: WordpalExtensionTest
   * covers the same behavior with a mocked renderer.
   */
  public function testPostDateBubblesTimezoneCacheContext(): void {
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:post-date']);
    $build = [
      '#type' => 'component',
      '#component' => 'wordpal:post-date',
      '#props' => ['timestamp' => 1705314600, 'format' => 'F j, Y', 'is_link' => FALSE, 'url' => ''],
    ];
    $this->container->get(RendererInterface::class)->renderRoot($build);
    self::assertContains('timezone', $build['#cache']['contexts']);
  }

  /**
   * Tests a saved `datetime` attribute prints verbatim and skips the post.
   *
   * Post-date.php prints a saved `datetime` attribute as-is and never
   * consults the post's own date, so it also skips the modified-date gate
   * that published_timestamp otherwise applies.
   */
  public function testPostDateUsesLiteralDatetimeOverPostTimestamp(): void {
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:post-date']);
    $html = $this->renderSdc('wordpal:post-date', [
      'datetime' => '2024-02-01T10:00:00',
      'timestamp' => 1705314600,
      'published_timestamp' => 1705314600 + 1,
      'is_link' => FALSE,
      'url' => '',
    ]);
    self::assertStringContainsString('<time datetime="2024-02-01T10:00:00">', $html);
  }

  /**
   * Tests that post-title's link matches WordPress: target, never rel.
   */
  public function testPostTitleLinkMatchesWordPress(): void {
    $html = $this->renderSdc('wordpal:post-title', [
      'title' => 'A walk through the city',
      'url' => '/2024/01/15/a-walk-through-the-city/',
      'is_link' => TRUE,
    ]);
    self::assertStringContainsString('<a href="/2024/01/15/a-walk-through-the-city/" target="_self">A walk through the city</a>', $html);
    self::assertStringNotContainsString('rel="bookmark"', $html);
  }

  /**
   * Tests that post-terms's links match WordPress: one rel="tag" per term.
   */
  public function testPostTermsLinksMatchWordPress(): void {
    $html = $this->renderSdc('wordpal:post-terms', [
      'terms' => ['Journal'],
      'term_urls' => ['/category/journal/'],
      'taxonomy' => 'category',
      'prefix' => 'in ',
    ]);
    self::assertStringContainsString('<span class="wp-block-post-terms__prefix">in </span><a href="/category/journal/" rel="tag">Journal</a>', $html);
    $none = $this->renderSdc('wordpal:post-terms', [
      'terms' => [],
      'term_urls' => [],
      'taxonomy' => 'post_tag',
    ]);
    self::assertSame('', trim($none), 'A post with no terms prints nothing.');
  }

  /**
   * Tests the Featured Image style follows post-featured-image.php:42-66.
   */
  public function testFeaturedImageStyleMatchesWordPress(): void {
    $image = fn (array $props): string => $this->renderSdc(
      'wordpal:post-featured-image',
      ['src' => 'https://example.com/x.jpg', 'scale' => 'cover'] + $props,
    );
    self::assertStringContainsString(
      'style="height:auto;width:100%;object-fit:cover;"',
      $image(['width' => '100%']),
    );
    self::assertStringContainsString(
      'style="aspect-ratio:3/4;width:100%;height:10rem;object-fit:cover;"',
      $image(['aspect_ratio' => '3/4', 'height' => '10rem']),
    );
    self::assertStringContainsString('style="width:100%;object-fit:cover;"', $image(['aspect_ratio' => 'auto']));
    self::assertStringContainsString(
      'decoding="async" loading="lazy" width="640" height="480"',
      $image(['image_width' => 640, 'image_height' => 480]),
    );
  }

  /**
   * Tests a Featured Image's border radius styles the <img>, not the figure.
   *
   * WordPress's get_block_core_post_featured_image_border_attributes() feeds
   * get_the_post_thumbnail() the border, which lands on the <img>.
   */
  public function testFeaturedImageBorderRadiusStylesTheImage(): void {
    $html = $this->renderSdc('wordpal:post-featured-image', [
      'src' => 'https://example.com/x.jpg',
      'border_radius' => '10px',
    ]);
    self::assertStringContainsString('<img src="https://example.com/x.jpg" class="attachment-post-thumbnail size-post-thumbnail wp-post-image" alt="" style="border-radius:10px;"', $html);
    self::assertMatchesRegularExpression('/<figure[^>]*>/', $html);
    preg_match('/<figure[^>]*>/', $html, $figure);
    self::assertStringNotContainsString('border-radius', $figure[0]);
  }

  /**
   * Tests a Featured Image's per-corner border radius also styles the <img>.
   *
   * A theme can save one radius per corner instead of the uniform value
   * `testFeaturedImageBorderRadiusStylesTheImage()` covers; WordPress's
   * get_block_core_post_featured_image_border_attributes() feeds both shapes
   * to the same style-engine call, so both land on the <img>, not the figure.
   */
  public function testFeaturedImageCornerBorderRadiusStylesTheImage(): void {
    $html = $this->renderSdc('wordpal:post-featured-image', [
      'src' => 'https://example.com/x.jpg',
      'border_top_left_radius' => '12px',
      'border_top_right_radius' => '12px',
      'border_bottom_left_radius' => '0px',
      'border_bottom_right_radius' => '0px',
    ]);
    self::assertStringContainsString(
      '<img src="https://example.com/x.jpg" class="attachment-post-thumbnail size-post-thumbnail wp-post-image" alt="" style="border-top-left-radius:12px;border-top-right-radius:12px;border-bottom-left-radius:0px;border-bottom-right-radius:0px;"',
      $html,
    );
    preg_match('/<figure[^>]*>/', $html, $figure);
    self::assertStringNotContainsString('radius', $figure[0]);
  }

  /**
   * Tests a featured-image Cover prints get_the_post_thumbnail() markup.
   *
   * WordPress 7.1.2 blocks/cover.php:152 prints the featured image through
   * get_the_post_thumbnail(), which adds wp-post-image, the attachment's
   * width and height, and the loading attributes. A saved image prints none.
   */
  public function testCoverFeaturedImageMatchesWordPress(): void {
    $featured = $this->renderSdc('wordpal:cover', [
      'url' => 'https://example.com/x.jpg',
      'use_featured_image' => TRUE,
      'image_width' => 640,
      'image_height' => 480,
    ]);
    self::assertStringContainsString('<img width="640" height="480" class="wp-block-cover__image-background wp-post-image" alt="" src="https://example.com/x.jpg" data-object-fit="cover" decoding="async" loading="lazy" />', $featured);
    $saved = $this->renderSdc('wordpal:cover', ['url' => 'https://example.com/x.jpg']);
    self::assertStringContainsString('<img class="wp-block-cover__image-background" alt="" src="https://example.com/x.jpg" data-object-fit="cover" />', $saved);
  }

  /**
   * Tests a parallax Cover's background div carries no role attribute.
   */
  public function testCoverParallaxBackgroundHasNoRole(): void {
    $html = $this->renderSdc('wordpal:cover', ['url' => 'https://example.com/x.jpg', 'has_parallax' => TRUE]);
    self::assertStringContainsString('<div class="wp-block-cover__image-background has-parallax"', $html);
    self::assertStringNotContainsString('role="img"', $html);
  }

  /**
   * Tests the classes WordPress's saved Cover markup carries for a position.
   */
  public function testCoverContentPositionMatchesWordPress(): void {
    $html = $this->renderSdc('wordpal:cover', ['content_position' => 'bottom center']);
    self::assertMatchesRegularExpression('/class="[^"]*has-custom-content-position is-position-bottom-center/', $html);
    self::assertStringNotContainsString('is-position-', $this->renderSdc('wordpal:cover', ['content_position' => 'center center']));
  }

  /**
   * Tests a Cover's gradient overlay classes the span, not the root.
   *
   * Cover's own gradient attribute is a preset slug; customGradient a raw
   * CSS gradient. Neither belongs on the root, which the generic color
   * support would otherwise class from either.
   */
  public function testCoverGradientOverlayMatchesWordPress(): void {
    $preset = $this->renderSdc('wordpal:cover', ['gradient' => 'primary-fade-1']);
    self::assertStringContainsString(
      '<span aria-hidden="true" class="wp-block-cover__background has-background-dim has-background-gradient has-primary-fade-1-gradient-background"></span>',
      $preset,
    );
    preg_match('/^<div[^>]*>/', $preset, $root);
    self::assertStringNotContainsString('gradient', $root[0], 'The root gets no gradient class of its own.');

    $custom = $this->renderSdc('wordpal:cover', ['custom_gradient' => 'linear-gradient(90deg, #000 0%, #fff 100%)']);
    self::assertStringContainsString(
      '<span aria-hidden="true" class="wp-block-cover__background has-background-dim has-background-gradient" style="background:linear-gradient(90deg, #000 0%, #fff 100%)"></span>',
      $custom,
    );
    $overImage = $this->renderSdc('wordpal:cover', [
      'gradient' => 'primary-fade-1',
      'url' => 'https://example.com/x.jpg',
    ]);
    self::assertStringContainsString('wp-block-cover__gradient-background', $overImage, 'The save function keeps the class over a saved image.');
  }

  /**
   * Tests a Cover's custom overlay color styles the overlay span.
   */
  public function testCoverCustomOverlayColorMatchesWordPress(): void {
    $html = $this->renderSdc('wordpal:cover', ['custom_overlay_color' => '#564e4f', 'dim_ratio' => 0]);
    self::assertStringContainsString(
      '<span aria-hidden="true" class="wp-block-cover__background has-background-dim has-background-dim-0" style="background-color:#564e4f"></span>',
      $html,
    );
  }

  /**
   * Tests a Group's background image, sized "contain" with no position.
   *
   * WordPress's wp_render_background_support() defaults the position to
   * "50% 50%" only when the size is "contain" and no position is set.
   */
  public function testGroupBackgroundImageMatchesWordPress(): void {
    $html = $this->renderSdc('wordpal:group', [
      'background_image' => '/themes/custom/spike_business/assets/images/banner-img4.png',
      'background_size' => 'contain',
    ]);
    self::assertStringContainsString('has-background', $html);
    self::assertStringContainsString(
      'style="background-image:url(/themes/custom/spike_business/assets/images/banner-img4.png);background-position:50% 50%;background-size:contain"',
      $html,
    );
  }

  /**
   * Tests a Quote's background image, after its own inline styles.
   */
  public function testQuoteBackgroundImageMatchesWordPress(): void {
    $html = $this->renderSdc('wordpal:quote', [
      'background_image' => '/sites/default/files/quote.png',
      'padding_top' => '12px',
    ]);
    self::assertStringContainsString('class="wp-block-quote has-background ', $html);
    self::assertStringContainsString('style="background-image:url(/sites/default/files/quote.png);background-size:cover;padding-top:12px"', $html);
  }

  /**
   * Tests Post Content's tag allowlist and its empty main content.
   */
  public function testPostContentTagAndEmptyContent(): void {
    $section = $this->renderSdc('wordpal:post-content', ['content' => '<p>Body</p>', 'tag_name' => 'SECTION']);
    self::assertStringStartsWith('<section ', trim($section));
    $span = $this->renderSdc('wordpal:post-content', ['content' => '<p>Body</p>', 'tag_name' => 'span']);
    self::assertStringStartsWith('<div ', trim($span));
    $build = [
      '#type' => 'component',
      '#component' => 'wordpal:post-content',
      '#props' => [],
      '#slots' => ['page_content' => ['#cache' => ['tags' => ['node:1']]]],
    ];
    self::assertSame('', trim((string) $this->container->get(RendererInterface::class)->renderRoot($build)));
  }

  /**
   * Tests the ariaLabel support on a block that declares it.
   */
  public function testAccordionPrintsItsAriaLabel(): void {
    self::assertStringContainsString('aria-label="Questions"', $this->renderSdc('wordpal:accordion', ['aria_label' => 'Questions']));
  }

  /**
   * Tests the excerpt more link, on its own line and inline.
   */
  public function testExcerptMoreLinkMatchesWordPress(): void {
    $props = [
      'excerpt' => 'A walk.',
      'url' => '/walk-through-city',
      'more_text' => 'READ MORE <span aria-hidden="true">→</span>',
    ];
    self::assertStringContainsString('<p class="wp-block-post-excerpt__excerpt">A walk.</p><p class="wp-block-post-excerpt__more-text"><a class="wp-block-post-excerpt__more-link" href="/walk-through-city">READ MORE <span aria-hidden="true">→</span></a></p>', $this->renderSdc('wordpal:post-excerpt', $props));
    self::assertStringContainsString('<p class="wp-block-post-excerpt__excerpt">A walk. <a class="wp-block-post-excerpt__more-link"', $this->renderSdc('wordpal:post-excerpt', ['show_more_on_new_line' => FALSE] + $props));
    self::assertStringContainsString('<p class="wp-block-post-excerpt__excerpt">A walk. </p>', $this->renderSdc('wordpal:post-excerpt', ['excerpt' => 'A walk.']));
  }

  /**
   * Tests the legacy flex columns of a Post Template.
   */
  public function testPostTemplateLegacyFlexColumns(): void {
    self::assertMatchesRegularExpression('/<ul[^>]*class="wp-block-post-template is-flex-container columns-2/', $this->renderSdc('wordpal:post-template', ['display_layout_columns' => 2]));
  }

  /**
   * Tests a columnCount only names a class on a grid layout, not a default one.
   *
   * WordPress's post-template.php scopes the backwards-compatibility
   * classname to a grid layout.
   */
  public function testPostTemplateColumnCountNeedsGridLayout(): void {
    self::assertStringContainsString('columns-2', $this->renderSdc('wordpal:post-template', [
      'column_count' => 2,
      'layout_type' => 'grid',
    ]));
    self::assertStringNotContainsString('columns-2', $this->renderSdc('wordpal:post-template', [
      'column_count' => 2,
      'layout_type' => 'default',
    ]));
  }

  /**
   * Tests a grid Post Template's minimum column width and column count.
   *
   * WordPress's wp_get_layout_style() gives a grid its auto-fill columns
   * from minimumColumnWidth, unless columnCount is set, which wins.
   */
  public function testPostTemplateMinimumColumnWidth(): void {
    $render = fn (array $props): string => $this->processedLayoutHead($this->renderPostTemplateWithAttachments($props));
    $css = $render(['layout_type' => 'grid', 'minimum_column_width' => '18rem']);
    self::assertStringContainsString('grid-template-columns:repeat(auto-fill, minmax(min(18rem, 100%), 1fr))', $css);
    self::assertStringContainsString('container-type:inline-size', $css);
    $css = $render(['layout_type' => 'grid', 'minimum_column_width' => '18rem', 'column_count' => 3]);
    self::assertStringContainsString('repeat(3, minmax(0, 1fr))', $css);
    self::assertStringNotContainsString('auto-fill', $css);
  }

  /**
   * Tests a minimum column width that breaks out of the rule is refused.
   */
  public function testPostTemplateMinimumColumnWidthIsSanitized(): void {
    $this->expectException(RuntimeError::class);
    $this->expectExceptionMessage('Not a valid CSS value');
    $width = '1px,1fr))}x{background:url(//evil/a)}.y{';
    $this->renderPostTemplateWithAttachments(['layout_type' => 'grid', 'minimum_column_width' => $width]);
  }

  /**
   * Tests a grid Post Template with no width or count uses WordPress's 12rem.
   */
  public function testPostTemplateGridDefaultsToTwelveRem(): void {
    $css = $this->processedLayoutHead($this->renderPostTemplateWithAttachments(['layout_type' => 'grid']));
    self::assertStringContainsString('grid-template-columns:repeat(auto-fill, minmax(min(12rem, 100%), 1fr))', $css);
    self::assertStringContainsString('container-type:inline-size', $css);
  }

  /**
   * Tests that pagination links carry their typography supports.
   */
  public function testPaginationLinksCarryTypography(): void {
    $style = 'font-style:normal;font-weight:500';
    $typography = ['font_style' => 'normal', 'font_weight' => '500'];
    $next = $this->renderSdc('wordpal:query-pagination-next', ['url' => '/?page=1', 'label' => 'Next'] + $typography);
    $numbers = $this->renderSdc('wordpal:query-pagination-numbers', ['pages' => [], 'current' => 1] + $typography);
    self::assertStringContainsString($style, $next);
    self::assertStringContainsString($style, $numbers);
  }

  /**
   * Rejects unsafe component input or removes unsafe markup.
   */
  #[DataProvider('unsafeInputs')]
  public function testUnsafePropsDoNotCreateUnsafeMarkup(string $component, array $props, array $forbidden, bool $rejected = FALSE): void {
    if ($rejected) {
      $this->expectException(RuntimeError::class);
    }
    $html = $this->renderSdc($component, $props);
    foreach ($forbidden as $needle) {
      self::assertStringNotContainsString($needle, $html);
    }
  }

  /**
   * Supplies hostile values at the SDC rendering boundary.
   */
  public static function unsafeInputs(): iterable {
    yield 'rich text' => [
      'wordpal:paragraph',
      ['content' => '<script>alert(1)</script><a href="javascript:alert(1)" onclick="run()">Open</a>'],
      ['<script', 'javascript:', 'onclick='],
    ];
    yield 'tag name' => ['wordpal:button', ['tag_name' => 'script', 'text' => 'Open'], [], TRUE];
    yield 'URL' => [
      'wordpal:button',
      ['tag_name' => 'a', 'url' => 'javascript:alert(1)', 'text' => 'Open'],
      ['href="javascript:'],
    ];
    yield 'image aspect ratio' => [
      'wordpal:image',
      ['url' => 'https://example.com/x.jpg', 'aspect_ratio' => '1/1;background:url(//evil)'],
      [],
      TRUE,
    ];
    yield 'image scale' => [
      'wordpal:image',
      ['url' => 'https://example.com/x.jpg', 'scale' => 'cover}body{background:red'],
      [],
      TRUE,
    ];
    yield 'column width' => [
      'wordpal:column',
      ['width' => '50%;background:url(//evil)'],
      [],
      TRUE,
    ];
    yield 'cover min height unit' => [
      'wordpal:cover',
      ['min_height' => 300, 'min_height_unit' => 'px;background:url(//evil)'],
      [],
      TRUE,
    ];
  }

  /**
   * Tests a Template part's saved Cover and Media & Text images decode async.
   */
  public function testTemplatePartSavedImagesDecodeAsync(): void {
    $cover = ['url' => 'https://example.com/b.jpg'];
    self::assertStringContainsString('<img decoding="async" class="wp-block-cover__image-background"', $this->renderSdc('wordpal:cover', $cover + ['in_template_part' => TRUE]));
    self::assertStringNotContainsString('decoding', $this->renderSdc('wordpal:cover', $cover));
    $media = ['media_type' => 'image', 'media_url' => 'https://example.com/c.jpg'];
    self::assertStringContainsString('<img decoding="async" src=', $this->renderSdc('wordpal:media-text', $media + ['in_template_part' => TRUE]));
    self::assertStringNotContainsString('decoding', $this->renderSdc('wordpal:media-text', $media));
  }

  /**
   * Tests a Template part reference's own align and className survive.
   *
   * Of everything a Group carries, core/template-part supports only align
   * and a custom className: WordPress's className support defaults to
   * enabled for any block that does not turn it off, template-part included.
   */
  public function testTemplatePartAlignAndClassNamePrint(): void {
    $aligned = $this->renderSdc('wordpal:group', ['template_part' => TRUE, 'align' => 'full']);
    self::assertStringContainsString('wp-block-template-part', $aligned);
    self::assertStringContainsString('alignfull', $aligned);

    $classed = $this->renderSdc('wordpal:group', ['template_part' => TRUE, 'css_class' => 'site-header']);
    self::assertStringContainsString('site-header', $classed);
    self::assertStringNotContainsString('wp-block-group', $classed);

    $bare = $this->renderSdc('wordpal:group', ['template_part' => TRUE]);
    self::assertStringContainsString('class="wp-block-template-part"', $bare);
    self::assertStringNotContainsString('align', $bare);
  }

  /**
   * Tests a Query's saved tagName prints as its wrapper element.
   *
   * WordPress's core/query carries the same tagName attribute as core/group
   * (block-library/query/index.php prints it as the loop wrapper).
   */
  public function testQueryTagNamePrintsTheSavedElement(): void {
    $main = $this->renderSdc('wordpal:query', ['tag_name' => 'main']);
    self::assertMatchesRegularExpression('#^<main[^>]*class="wp-block-query#', $main);
    self::assertStringContainsString('</main>', $main);

    $default = $this->renderSdc('wordpal:query', []);
    self::assertMatchesRegularExpression('#^<div[^>]*class="wp-block-query#', $default);
  }

  /**
   * Tests an Image with an empty link prints its anchor, as WordPress does.
   */
  public function testImageEmptyLinkPrintsAnchor(): void {
    $linked = $this->renderSdc('wordpal:image', ['url' => 'https://example.com/x.jpg', 'href' => '']);
    self::assertStringContainsString('<a href=""><img', $linked);
    self::assertStringNotContainsString('<a', $this->renderSdc('wordpal:image', ['url' => 'https://example.com/x.jpg']));
  }

  /**
   * Writes parsed source HTML as a Canvas pattern.
   */
  private function writeFixture(string $fixture): Pattern {
    $path = dirname(__DIR__, 2) . '/fixtures/components/' . $fixture;
    $nodes = $this->container->get(BlockParser::class)->parse((string) file_get_contents($path));
    $id = str_replace('.html', '', $fixture);
    $canvas_id = 'fixture_' . str_replace('-', '_', $id);
    return $this->container->get(CanvasWriter::class)->writePattern($canvas_id, $id, $nodes, new ContentMapping([]));
  }

  /**
   * Checks stored CSS and a second render from the cache entry.
   */
  private function assertLayoutAttachmentsSurviveRenderCache(array $build): array {
    $cacheHitBuild = ['#cache' => $build['#cache']];
    $first = $this->renderWithAttachments($build);
    $cached = $this->container->get('render_cache')->get($build);
    self::assertIsArray($cached, var_export($build['#cache'] ?? [], TRUE));
    // The style values are Markup objects, equal by value only.
    self::assertEquals($first['attachments'], $cached['#attached'] ?? []);
    $cacheHit = $this->renderWithAttachments($cacheHitBuild);
    self::assertSame($first['markup'], $cacheHit['markup']);
    self::assertEquals($first['attachments'], $cacheHit['attachments']);
    $ids = array_column(array_values(array_filter($first['attachments']['html_head'] ?? [], static fn (array $item): bool => str_starts_with($item[1], 'wordpal-'))), 1);
    self::assertNotEmpty($ids);
    self::assertNotSame('', $first['markup']);
    return $first;
  }

  /**
   * Checks Drupal's response processor deduplicates keyed styles.
   */
  private function assertProcessedLayoutStylesAreDeduplicated(array $rendered): void {
    $ids = array_column(array_values(array_filter($rendered['attachments']['html_head'] ?? [], static fn (array $item): bool => str_starts_with($item[1], 'wordpal-'))), 1);
    $content = $this->processedLayoutHead($rendered);
    self::assertSame(count(array_unique($ids)), substr_count($content, '<style>'));
  }

  /**
   * Processes head attachments through Drupal's HTML response pipeline.
   */
  private function processedLayoutHead(array $rendered): string {
    $response = new HtmlResponse('<html><head><head-placeholder></head><body></body></html>');
    $response->setAttachments($rendered['attachments'] + ['html_response_attachment_placeholders' => ['head' => '<head-placeholder>']]);
    return (string) $this->container->get('html_response.attachments_processor')->processAttachments($response)->getContent();
  }

  /**
   * Compares rendered markup with WordPress's saved render.
   */
  private function assertWordPressMarkup(string $fixture, string $actual): void {
    $reference_path = dirname(__DIR__, 2) . '/fixtures/components/' . str_replace('.html', '.rendered.html', $fixture);
    $reference = file_get_contents($reference_path);
    $differences = (new MarkupComparator())->compare((string) $reference, $actual);
    self::assertSame([], $differences, implode("\n", $differences));
  }

  /**
   * Checks repeated column layouts share one generated rule.
   */
  private function assertRepeatedLayoutSharesOneCssRule(array $rendered): void {
    preg_match_all('/wp-container-core-columns-is-layout-[a-f0-9]{8}/', $rendered['markup'], $matches);
    $container = array_search(2, array_count_values($matches[0]), TRUE);
    self::assertNotFalse($container);
    $css = $this->processedLayoutHead($rendered);
    self::assertSame(1, substr_count($css, '.' . $container));
  }

  /**
   * Renders a build and returns its markup and attachments.
   */
  private function renderWithAttachments(array &$build): array {
    $renderer = $this->container->get(RendererInterface::class);
    $markup = $renderer->renderRoot($build);
    return ['markup' => (string) $markup, 'attachments' => $build['#attached'] ?? []];
  }

  /**
   * Renders a Post Template and returns its markup and attachments.
   */
  private function renderPostTemplateWithAttachments(array $props): array {
    $build = ['#type' => 'component', '#component' => 'wordpal:post-template', '#props' => $props];
    return $this->renderWithAttachments($build);
  }

  /**
   * Renders one component with hostile input props.
   */
  private function renderSdc(string $component, array $props): string {
    $build = ['#type' => 'component', '#component' => $component, '#props' => $props];
    return (string) $this->container->get(RendererInterface::class)->renderRoot($build);
  }

}
