<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeAccessRebuild;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\node\Traits\NodeAccessTrait;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\wordpal\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Renders each core block of the library added for Drupal CMS themes.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class CoreBlockLibraryTest extends KernelTestBase {

  use NodeAccessTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'filter',
    'text',
    'node',
    'taxonomy',
    'comment',
    'views',
    'wordpal',
  ];

  /**
   * The post author.
   */
  private User $author;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'taxonomy', 'wordpal']);
    $theme = $this->container->get('theme.manager')->getActiveTheme()->getName();
    $this->container->get('config.factory')->getEditable(ThemeSettings::configName($theme))->set('styled_variations', [])->save();
    NodeType::create(['type' => 'blog', 'name' => 'Blog'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    User::create(['name' => 'anonymous', 'uid' => 0])->save();
    $this->author = User::create(['name' => 'Morgan Reed', 'status' => 1]);
    $this->author->save();
    user_role_grant_permissions('anonymous', ['access content', 'access user profiles']);
  }

  /**
   * Renders one component.
   */
  private function renderComponent(string $component, array $props, array $slots = []): string {
    $build = ['#type' => 'component', '#component' => "wordpal:$component", '#props' => $props, '#slots' => $slots];
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Renders one block plugin.
   */
  private function renderBlock(string $pluginId, array $configuration): string {
    $build = $this->container->get('plugin.manager.block')->createInstance($pluginId, $configuration)->build();
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Makes a request the current one, with an optional route.
   */
  private function visit(string $uri, ?string $routeName = NULL, array $parameters = []): void {
    $request = Request::create($uri);
    $request->setSession(new Session(new MockArraySessionStorage()));
    if ($routeName !== NULL) {
      $request->attributes->add([
        RouteObjectInterface::ROUTE_NAME => $routeName,
        RouteObjectInterface::ROUTE_OBJECT => new Route('/{' . implode('}/{', array_keys($parameters)) . '}'),
      ] + $parameters);
    }
    $this->container->get('request_stack')->push($request);
    $this->container->get('current_route_match')->resetRouteMatch();
  }

  /**
   * Creates a published node.
   */
  private function createNode(string $bundle, string $title, int $created, array $values = []): Node {
    $node = Node::create($values + [
      'type' => $bundle,
      'title' => $title,
      'uid' => $this->author->id(),
      'status' => 1,
      'created' => $created,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Tests Social Icons wraps its links in a flex list.
   */
  public function testSocialLinks(): void {
    $link = [
      '#type' => 'component',
      '#component' => 'wordpal:social-link',
      '#props' => ['service' => 'x', 'url' => 'https://x.com/wordpress'],
    ];
    $html = $this->renderComponent('social-links', [
      'css_class' => 'is-style-logos-only',
      'icon_color_value' => 'currentColor',
    ], ['content' => $link]);

    self::assertMatchesRegularExpression('/<ul data-component-id="wordpal:social-links" class="wp-block-social-links has-icon-color is-style-logos-only[^"]*is-layout-flex/', $html);
    self::assertStringContainsString('class="wp-social-link wp-social-link-x', $html);
  }

  /**
   * Tests a Social Icon link, its icon, label and parent context.
   */
  public function testSocialLink(): void {
    $html = $this->renderComponent('social-link', [
      'service' => 'instagram',
      'url' => 'instagram.com/wordpress',
      'open_in_new_tab' => TRUE,
      'icon_color' => 'contrast',
      'icon_color_value' => '#111',
    ]);

    self::assertStringContainsString('class="wp-social-link wp-social-link-instagram wp-block-social-link has-contrast-color" style="color:#111">', $html);
    self::assertStringContainsString('<a href="https://instagram.com/wordpress" class="wp-block-social-link-anchor" rel="noopener nofollow" target="_blank"><svg', $html);
    self::assertStringContainsString('<span class="wp-block-social-link-label screen-reader-text">Instagram</span></a></li>', $html);
    self::assertStringContainsString('href="mailto:hi@example.com"', $this->renderComponent('social-link', [
      'service' => 'mail',
      'url' => 'hi@example.com',
    ]));
    self::assertStringContainsString('<span class="wp-block-social-link-label screen-reader-text">Share Icon</span>', $this->renderComponent('social-link', [
      'service' => '../../x',
      'url' => 'https://example.com',
    ]));
    self::assertSame('', trim($this->renderComponent('social-link', ['service' => 'x'])));
  }

  /**
   * Tests free-text props that reach a style attribute reject extra CSS.
   */
  public function testStylePropsRejectCssDeclarations(): void {
    $injected = 'red;background:url(https://example.com/beacon)';
    $html = $this->renderComponent('cover', ['url' => '/a.jpg', 'focal_point' => '50% 13%']);
    self::assertStringContainsString('style="object-position:50% 13%"', $html);
    foreach ([
      ['cover', ['url' => '/a.jpg', 'focal_point' => $injected]],
      ['social-link', ['service' => 'x', 'url' => 'https://x.com', 'icon_color_value' => $injected]],
      ['social-link', ['service' => 'x', 'url' => 'https://x.com', 'icon_background_color_value' => $injected]],
    ] as [$component, $props]) {
      try {
        $this->renderComponent($component, $props);
        self::fail("$component must reject " . implode(', ', array_keys($props)));
      }
      catch (\Throwable $exception) {
        self::assertStringContainsString('Not a valid CSS value', $exception->getMessage());
      }
    }
  }

  /**
   * Tests a video in its figure.
   */
  public function testVideo(): void {
    $html = $this->renderComponent('video', [
      'src' => '/files/city.mp4',
      'controls' => TRUE,
      'loop' => TRUE,
      'muted' => TRUE,
      'width' => 640,
      'height' => 360,
      'plays_inline' => TRUE,
      'caption' => 'City',
    ]);

    self::assertStringContainsString('class="wp-block-video"><video controls loop muted src="/files/city.mp4" width="640" height="360" playsinline></video><figcaption class="wp-element-caption">City</figcaption></figure>', $html);
  }

  /**
   * Tests a list keeps start and reversed even when it renders as a ul.
   *
   * The save function passes both to useBlockProps regardless of the
   * ordered attribute.
   */
  public function testList(): void {
    $html = $this->renderComponent('list', ['start' => 1, 'reversed' => TRUE]);

    self::assertStringContainsString('<ul', $html);
    self::assertStringContainsString('start="1"', $html);
    self::assertStringContainsString('reversed=""', $html);
  }

  /**
   * Tests a separator's opacity class, tag and dropped gradient style.
   */
  public function testSeparator(): void {
    $cssOpacity = $this->renderComponent('separator', ['opacity' => 'css']);
    self::assertStringContainsString('has-css-opacity', $cssOpacity);
    self::assertStringNotContainsString('has-alpha-channel-opacity', $cssOpacity);

    $div = $this->renderComponent('separator', ['tag_name' => 'div']);
    self::assertStringContainsString('class="wp-block-separator has-alpha-channel-opacity"></div>', $div);
    self::assertStringStartsWith('<div', trim($div));

    // The style engine skips gradient serialization for the separator, so
    // only the has-background class survives, never the gradient value.
    $gradient = $this->renderComponent('separator', ['gradient' => 'linear-gradient(135deg,#1a2b3c 0%,#4d5e6f 100%)']);
    self::assertStringContainsString('has-background', $gradient);
    self::assertStringNotContainsString('linear-gradient', $gradient);
  }

  /**
   * Tests a cover rounds its dim ratio and marks the image's media size.
   */
  public function testCoverDimRatioAndSizeSlug(): void {
    $html = $this->renderComponent('cover', ['url' => '/a.jpg', 'dim_ratio' => 99, 'size_slug' => 'medium']);

    self::assertStringContainsString('has-background-dim-100', $html);
    self::assertStringNotContainsString('has-background-dim-99', $html);
    self::assertStringContainsString('size-medium', $html);
  }

  /**
   * Tests Buttons marks a preset or a custom font size.
   */
  public function testButtonsMarksItsFontSize(): void {
    self::assertStringContainsString('class="wp-block-buttons has-custom-font-size', $this->renderComponent('buttons', ['font_size' => 'small']));
    self::assertStringContainsString('class="wp-block-buttons has-custom-font-size', $this->renderComponent('buttons', ['font_size' => '13px']));
    self::assertStringNotContainsString('has-custom-font-size', $this->renderComponent('buttons', []));
  }

  /**
   * Tests an explicit aspect ratio unsets the cover's heights.
   */
  public function testCoverAspectRatio(): void {
    $html = $this->renderComponent('cover', ['url' => '/a.jpg', 'aspect_ratio' => '16/9', 'min_height' => 400]);
    self::assertStringContainsString('has-aspect-ratio', $html);
    self::assertStringContainsString('aspect-ratio:16/9;min-height:unset;height:unset', $html);

    $html = $this->renderComponent('cover', ['url' => '/a.jpg', 'aspect_ratio' => 'auto', 'min_height' => 400]);
    self::assertStringNotContainsString('has-aspect-ratio', $html);
    self::assertStringContainsString('min-height:400px;aspect-ratio:unset', $html);
  }

  /**
   * Tests Media & Text puts its media on the chosen side.
   */
  public function testMediaText(): void {
    $text = ['#type' => 'component', '#component' => 'wordpal:paragraph', '#props' => ['content' => 'Beside']];
    $html = $this->renderComponent('media-text', [
      'media_url' => '/files/city.jpg',
      'media_type' => 'image',
      'media_id' => 7,
      'media_position' => 'right',
      'media_width' => 40,
      'is_stacked_on_mobile' => TRUE,
    ], ['content' => $text]);

    self::assertStringContainsString('class="wp-block-media-text has-media-on-the-right is-stacked-on-mobile" style="grid-template-columns:auto 40%"', $html);
    self::assertMatchesRegularExpression('#<div class="wp-block-media-text__content">\s*<p data-component-id="wordpal:paragraph" class="wp-block-paragraph">Beside</p>\s*</div><figure class="wp-block-media-text__media"><img src="/files/city.jpg" alt="" class="wp-image-7 size-full"/></figure>#', $html);
  }

  /**
   * Tests Details prints its summary and inner blocks, open when set.
   */
  public function testDetails(): void {
    $text = ['#type' => 'component', '#component' => 'wordpal:paragraph', '#props' => ['content' => 'Answer']];
    $html = $this->renderComponent('details', [
      'summary' => 'Question <strong>one</strong>',
      'show_content' => TRUE,
      'name' => 'faq',
    ], ['content' => $text]);

    self::assertMatchesRegularExpression('#<details data-component-id="wordpal:details" class="wp-block-details is-layout-flow wp-block-details-is-layout-flow" name="faq" open><summary>Question <strong>one</strong></summary>\s*<p data-component-id="wordpal:paragraph" class="wp-block-paragraph">Answer</p>\s*</details>#', $html);
    self::assertStringNotContainsString(' open', $this->renderComponent('details', ['summary' => 'Closed'], ['content' => $text]));
  }

  /**
   * Tests Quote prints its inner blocks and its citation.
   */
  public function testQuote(): void {
    $text = ['#type' => 'component', '#component' => 'wordpal:paragraph', '#props' => ['content' => 'Walk slowly.']];
    $html = $this->renderComponent('quote', ['citation' => 'Morgan <em>Reed</em>', 'text_align' => 'center'], ['content' => $text]);

    self::assertMatchesRegularExpression('#<blockquote data-component-id="wordpal:quote" class="wp-block-quote has-text-align-center is-layout-flow wp-block-quote-is-layout-flow">\s*<p data-component-id="wordpal:paragraph" class="wp-block-paragraph">Walk slowly.</p>\s*<cite>Morgan <em>Reed</em></cite></blockquote>#', $html);
    self::assertStringNotContainsString('<cite>', $this->renderComponent('quote', [], ['content' => $text]));
  }

  /**
   * Tests Custom HTML keeps markup and drops script tags, handlers and style.
   */
  public function testCustomHtml(): void {
    $html = $this->renderComponent('html', [
      'content' => '<div class="map" id="where"><iframe src="https://maps.example.com/embed" width="100%" height="500"></iframe></div><p style="color:red" onclick="steal()">Meet <strong>here</strong></p><script>steal()</script><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5h16"></path></svg>',
    ]);

    self::assertStringContainsString('<div class="map" id="where"><iframe src="https://maps.example.com/embed" width="100%" height="500"></iframe></div>', $html);
    self::assertStringContainsString('<p>Meet <strong>here</strong></p>', $html);
    self::assertStringContainsString('<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5h16" /></svg>', $html);
    self::assertStringNotContainsString('<script', $html);
    self::assertStringNotContainsString('onclick', $html);
    self::assertStringNotContainsString('style=', $html);
  }

  /**
   * Tests an accordion links each heading to its panel, with its script.
   */
  public function testAccordion(): void {
    $item = fn (string $title, bool $open, string $side): array => [
      '#type' => 'component',
      '#component' => 'wordpal:accordion-item',
      '#props' => ['open_by_default' => $open],
      '#slots' => [
        'content' => [
          [
            '#type' => 'component',
            '#component' => 'wordpal:accordion-heading',
            '#props' => ['title' => $title, 'show_icon' => TRUE, 'icon_position' => $side, 'padding_top' => '1rem'],
          ],
          [
            '#type' => 'component',
            '#component' => 'wordpal:accordion-panel',
            '#props' => [],
            '#slots' => [
              'content' => [
                '#type' => 'component',
                '#component' => 'wordpal:paragraph',
                '#props' => ['content' => "$title answer"],
              ],
            ],
          ],
        ],
      ],
    ];
    $build = [
      '#type' => 'component',
      '#component' => 'wordpal:accordion',
      '#props' => ['autoclose' => TRUE],
      '#slots' => ['content' => [$item('When', TRUE, 'right'), $item('Where', FALSE, 'left')]],
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertContains('wordpal/interactivity.accordion', $build['#attached']['library']);
    self::assertMatchesRegularExpression('#<div data-component-id="wordpal:accordion" class="wp-block-accordion[^"]*" role="group" data-wp-interactive="core/accordion" data-wp-context="\{ &quot;autoclose&quot;: true, &quot;accordionItems&quot;: \[\] \}"#', $html);
    self::assertMatchesRegularExpression('#<div data-component-id="wordpal:accordion-item" class="wp-block-accordion-item is-open[^"]*" data-wp-context="\{ &quot;id&quot;: &quot;(accordion-item[-0-9]*)&quot;, &quot;openByDefault&quot;: true \}" data-wp-class--is-open="state.isOpen" data-wp-init="callbacks.initAccordionItems" data-wp-on-window--hashchange="callbacks.hashChange">#', $html);
    self::assertMatchesRegularExpression('#<h3 data-component-id="wordpal:accordion-heading" class="wp-block-accordion-heading has-icon has-icon-right"><button style="padding-top:1rem;?" class="wp-block-accordion-heading__toggle" type="button" data-wp-on--click="actions.toggle" id="(accordion-item[-0-9]*)" aria-controls="\1-panel" data-wp-bind--aria-expanded="state.isOpen" aria-expanded="true"><span class="wp-block-accordion-heading__toggle-title">When</span><span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">\+</span></button></h3>#', $html);
    self::assertMatchesRegularExpression('#<div data-component-id="wordpal:accordion-panel" class="wp-block-accordion-panel[^"]*" role="region" id="accordion-item[-0-9]*-panel" aria-labelledby="accordion-item[-0-9]*" data-wp-bind--hidden="state.isHidden" data-wp-on--beforematch="actions.handleBeforeMatch">#', $html);
    self::assertStringContainsString('aria-expanded="false"><span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span><span class="wp-block-accordion-heading__toggle-title">Where</span></button>', $html);
    preg_match_all('/ id="(accordion-item[-0-9]*)"/', $html, $ids);
    self::assertCount(2, array_unique($ids[1]));
  }

  /**
   * Tests a page without an accordion loads no accordion script.
   */
  public function testNoAccordionNoScript(): void {
    $build = ['#type' => 'component', '#component' => 'wordpal:paragraph', '#props' => ['content' => 'Plain']];
    $this->container->get('renderer')->renderInIsolation($build);

    self::assertNotContains('wordpal/interactivity.accordion', $build['#attached']['library'] ?? []);
  }

  /**
   * Tests Avatar prints the author's picture, and no image without one.
   */
  public function testAvatar(): void {
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    FieldStorageConfig::create([
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'type' => 'image',
      'settings' => ['target_type' => 'file', 'uri_scheme' => 'public'],
    ])->save();
    FieldConfig::create(['field_name' => 'user_picture', 'entity_type' => 'user', 'bundle' => 'user'])->save();
    $file = File::create(['uri' => 'public://morgan.png', 'status' => 1]);
    $file->save();
    User::load($this->author->id())->set('user_picture', ['target_id' => $file->id()])->save();
    $pictureless = User::create(['name' => 'Sam Hill', 'status' => 1]);
    $pictureless->save();

    $html = $this->renderComponent('avatar', [
      'author_id' => (int) $this->author->id(),
      'size' => 64,
      'is_link' => TRUE,
      'link_target' => '_blank',
      'border_radius' => '50%',
      'margin_top' => '1rem',
    ]);

    self::assertMatchesRegularExpression('#<div data-component-id="wordpal:avatar" class="wp-block-avatar" style="margin-top:1rem;?"><a href="/user/\d+" target="_blank" aria-label="\(Morgan Reed author archive, opens in a new tab\)" class="wp-block-avatar__link"><img style="border-radius:50%;?" class="avatar avatar-64 photo wp-block-avatar__image" alt="Morgan Reed Avatar" src="[^"]*/morgan.png" height="64" width="64" loading="lazy" decoding="async"></a></div>#', $html);
    self::assertMatchesRegularExpression('#<div data-component-id="wordpal:avatar" class="wp-block-avatar"></div>#', $this->renderComponent('avatar', ['author_id' => (int) $pictureless->id()]));
    self::assertSame('', trim($this->renderComponent('avatar', ['author_id' => 0])));
  }

  /**
   * Tests a gallery holds its images in a flex figure.
   */
  public function testGallery(): void {
    $image = ['#type' => 'component', '#component' => 'wordpal:image', '#props' => ['url' => '/files/city.jpg']];
    $html = $this->renderComponent('gallery', [
      'columns' => 2,
      'image_crop' => TRUE,
      'caption' => 'Walks',
    ], ['content' => $image]);

    self::assertMatchesRegularExpression('/<figure data-component-id="wordpal:gallery" class="wp-block-gallery has-nested-images columns-2 is-cropped[^"]*is-layout-flex/', $html);
    self::assertStringContainsString('<img src="/files/city.jpg"', $html);
    self::assertStringContainsString('<figcaption class="blocks-gallery-caption wp-element-caption">Walks</figcaption></figure>', $html);
  }

  /**
   * Tests Read More links its post, and renders nothing without one.
   */
  public function testReadMore(): void {
    $html = $this->renderComponent('read-more', [
      'url' => '/node/1',
      'title' => 'A walk',
      'content' => 'Continue',
      'link_target' => '_self',
    ]);

    self::assertStringContainsString('class="wp-block-read-more" href="/node/1" target="_self">Continue<span class="screen-reader-text">: A walk</span></a>', $html);
    self::assertSame('', trim($this->renderComponent('read-more', [])));
  }

  /**
   * Tests the author byline block names the bound author.
   */
  public function testPostAuthor(): void {
    $html = $this->renderComponent('post-author', [
      'author_id' => (int) $this->author->id(),
      'is_link' => FALSE,
      'link_target' => '_self',
      'show_avatar' => TRUE,
      'avatar_size' => 48,
      'byline' => 'Written by',
      'show_bio' => TRUE,
    ]);

    self::assertMatchesRegularExpression('#<div data-component-id="wordpal:post-author" class="wp-block-post-author"><div class="wp-block-post-author__avatar">\s*</div><div class="wp-block-post-author__content"><p class="wp-block-post-author__byline">Written by</p><p class="wp-block-post-author__name">Morgan Reed</p><p class="wp-block-post-author__bio"></p></div>#', $html);
  }

  /**
   * Tests the query title on a term page, the search page, and elsewhere.
   */
  public function testQueryTitle(): void {
    $tag = Term::create(['vid' => 'tags', 'name' => 'City']);
    $tag->save();
    $topic = Term::create(['vid' => 'topics', 'name' => 'Walks']);
    $topic->save();
    $mapped = [
      'show_prefix' => TRUE,
      'show_search_term' => TRUE,
      'tag_vocabulary' => 'tags',
      'category_vocabulary' => 'topics',
      'search_path' => '/search',
      'search_parameter' => 'keywords',
    ];

    $this->visit('/taxonomy/term/' . $tag->id(), 'entity.taxonomy_term.canonical', ['taxonomy_term' => $tag]);
    self::assertStringContainsString('class="wp-block-query-title">Tag: <span>City</span></h1>', $this->renderComponent('query-title', ['type' => 'archive'] + $mapped));
    self::assertStringContainsString('class="wp-block-query-title">City</h1>', $this->renderComponent('query-title', [
      'type' => 'archive',
      'show_prefix' => FALSE,
    ] + $mapped));
    self::assertSame('', trim($this->renderComponent('query-title', ['type' => 'search'] + $mapped)));

    $marked = Term::create(['vid' => 'tags', 'name' => '<em>x</em>']);
    $marked->save();
    $this->visit('/taxonomy/term/' . $marked->id(), 'entity.taxonomy_term.canonical', ['taxonomy_term' => $marked]);
    self::assertStringContainsString('class="wp-block-query-title">&lt;em&gt;x&lt;/em&gt;</h1>', $this->renderComponent('query-title', [
      'type' => 'archive',
      'show_prefix' => FALSE,
    ] + $mapped));

    $this->visit('/taxonomy/term/' . $topic->id(), 'entity.taxonomy_term.canonical', ['taxonomy_term' => $topic]);
    self::assertStringContainsString('Category: <span>Walks</span>', $this->renderComponent('query-title', ['type' => 'archive'] + $mapped));

    Vocabulary::create(['vid' => 'places', 'name' => 'Places'])->save();
    $place = Term::create(['vid' => 'places', 'name' => 'Harbour']);
    $place->save();
    $this->visit('/taxonomy/term/' . $place->id(), 'entity.taxonomy_term.canonical', ['taxonomy_term' => $place]);
    self::assertStringContainsString('Places: <span>Harbour</span>', $this->renderComponent('query-title', ['type' => 'archive'] + $mapped));

    $this->visit('/search?keywords=%3Cb%3Ecity');
    self::assertStringContainsString('class="wp-block-query-title">Search results for: “&lt;b&gt;city”</h1>', $this->renderComponent('query-title', ['type' => 'search'] + $mapped));
    self::assertSame('', trim($this->renderComponent('query-title', ['type' => 'archive'] + $mapped)));
  }

  /**
   * Tests the term description filters through the term's text format.
   */
  public function testTermDescription(): void {
    $term = Term::create([
      'vid' => 'tags',
      'name' => 'City',
      'description' => ['value' => '<p>Streets</p><script>x</script>', 'format' => 'plain_text'],
    ]);
    $term->save();
    $empty = Term::create(['vid' => 'tags', 'name' => 'Empty']);
    $empty->save();

    $this->visit('/taxonomy/term/' . $term->id(), 'entity.taxonomy_term.canonical', ['taxonomy_term' => $term]);
    $html = $this->renderComponent('term-description', ['text_align' => 'center']);
    self::assertStringContainsString('class="wp-block-term-description has-text-align-center">', $html);
    self::assertStringContainsString('&lt;p&gt;Streets&lt;/p&gt;&lt;script&gt;', $html);

    $this->visit('/taxonomy/term/' . $empty->id(), 'entity.taxonomy_term.canonical', ['taxonomy_term' => $empty]);
    self::assertSame('', trim($this->renderComponent('term-description', [])));
  }

  /**
   * Tests a term the account cannot view is absent, with grants cacheability.
   */
  public function testCategoriesBlock(): void {
    $visible = Term::create(['vid' => 'tags', 'name' => 'City']);
    $visible->save();
    $hidden = Term::create(['vid' => 'tags', 'name' => 'Hidden', 'status' => 0]);
    $hidden->save();
    foreach ([$visible, $hidden] as $term) {
      $node = $this->createNode('blog', $term->label() . ' post', 1000);
      $this->container->get('database')->insert('taxonomy_index')->fields([
        'nid' => $node->id(),
        'tid' => $term->id(),
        'status' => 1,
        'sticky' => 0,
        'created' => 1000,
      ])->execute();
    }

    $build = $this->container->get('plugin.manager.block')->createInstance('wordpal_categories', [
      'vocabulary' => 'tags',
      'show_empty' => TRUE,
    ])->build();
    self::assertContains('user.node_grants:view', $build['#cache']['contexts']);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertStringContainsString('City', $html);
    self::assertStringNotContainsString('Hidden', $html);
  }

  /**
   * Tests the tag cloud sizes tags by use and skips unused ones.
   */
  public function testTagCloud(): void {
    $terms = [];
    foreach (['City' => 3, 'Autumn' => 1, 'Unused' => 0] as $name => $uses) {
      $terms[$name] = Term::create(['vid' => 'tags', 'name' => $name]);
      $terms[$name]->save();
      for ($i = 0; $i < $uses; $i++) {
        $node = $this->createNode('blog', "$name $i", 1000 + $i);
        $this->container->get('database')->insert('taxonomy_index')->fields([
          'nid' => $node->id(),
          'tid' => $terms[$name]->id(),
          'status' => 1,
          'sticky' => 0,
          'created' => 1000,
        ])->execute();
      }
    }

    $html = $this->renderBlock('wordpal_tag_cloud', ['vocabulary' => 'tags']);

    self::assertStringContainsString('<p class="wp-block-tag-cloud"><a href="/taxonomy/term/2" class="tag-cloud-link tag-link-2 tag-link-position-1" style="font-size: 8pt;" aria-label="Autumn (1 item)">Autumn</a>' . "\n" . '<a href="/taxonomy/term/1" class="tag-cloud-link tag-link-1 tag-link-position-2" style="font-size: 22pt;" aria-label="City (3 items)">City</a></p>', $html);
    self::assertStringNotContainsString('Unused', $html);
    self::assertStringContainsString('<span class="tag-link-count"> (3)</span>', $this->renderBlock('wordpal_tag_cloud', [
      'vocabulary' => 'tags',
      'show_tag_counts' => TRUE,
    ]));
    self::assertStringContainsString('There’s no content to show here yet.', $this->renderBlock('wordpal_tag_cloud', ['vocabulary' => 'topics']));
  }

  /**
   * Tests the tag cloud font sizes accept a length and reject a declaration.
   */
  public function testTagCloudFontSizesAreLengths(): void {
    $typed = $this->container->get('config.typed');
    $block = $this->container->get('plugin.manager.block')->createInstance('wordpal_tag_cloud', ['vocabulary' => 'tags']);
    $violations = static fn (array $overrides) => $typed
      ->createFromNameAndData('block.settings.wordpal_tag_cloud', $overrides + $block->getConfiguration())
      ->validate();
    self::assertCount(0, $violations(['smallest_font_size' => '8pt', 'largest_font_size' => '1.5rem']));
    // 22pt is WordPress's own default for the largest size.
    self::assertCount(0, $violations(['largest_font_size' => '22pt']));
    $bad = $violations(['smallest_font_size' => '8pt;background:url(https://x/b)']);
    self::assertCount(1, $bad);
    self::assertSame('smallest_font_size', $bad->get(0)->getPropertyPath());
  }

  /**
   * Tests a term the account cannot view is absent, with grants cacheability.
   */
  public function testTagCloudBlock(): void {
    $visible = Term::create(['vid' => 'tags', 'name' => 'City']);
    $visible->save();
    $hidden = Term::create(['vid' => 'tags', 'name' => 'Hidden', 'status' => 0]);
    $hidden->save();
    foreach ([$visible, $hidden] as $term) {
      $node = $this->createNode('blog', $term->label() . ' post', 1000);
      $this->container->get('database')->insert('taxonomy_index')->fields([
        'nid' => $node->id(),
        'tid' => $term->id(),
        'status' => 1,
        'sticky' => 0,
        'created' => 1000,
      ])->execute();
    }

    $build = $this->container->get('plugin.manager.block')->createInstance('wordpal_tag_cloud', ['vocabulary' => 'tags'])->build();
    self::assertContains('user.node_grants:view', $build['#cache']['contexts']);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertStringContainsString('City', $html);
    self::assertStringNotContainsString('Hidden', $html);
  }

  /**
   * Tests archives lists the months of core's archive View.
   */
  public function testArchives(): void {
    View::load('archive')->setStatus(TRUE)->save();
    $this->container->get('router.builder')->rebuild();
    $this->createNode('blog', 'May', mktime(12, 0, 0, 5, 10, 2024));
    $this->createNode('blog', 'June', mktime(12, 0, 0, 6, 10, 2024));
    $this->createNode('blog', 'June again', mktime(12, 0, 0, 6, 12, 2024));
    $this->createNode('page', 'About', mktime(12, 0, 0, 7, 12, 2024));

    $html = $this->renderBlock('wordpal_archives', ['bundle' => 'blog', 'show_post_counts' => TRUE]);

    self::assertStringNotContainsString('July 2024', $html);

    self::assertStringContainsString('<ul class="wp-block-archives-list wp-block-archives"><li><a href="/archive/202406">June 2024</a>&nbsp;(2)</li>' . "\n" . '<li><a href="/archive/202405">May 2024</a>&nbsp;(1)</li>', $html);
  }

  /**
   * Tests a node denied by node grants leaves its month out, with cacheability.
   */
  public function testArchivesBlock(): void {
    $this->enableModules(['node_access_test']);
    $this->addPrivateField(NodeType::load('blog'));
    \Drupal::state()->set('node_access_test.private', TRUE);
    $this->container->get(NodeAccessRebuild::class)->rebuild();
    View::load('archive')->setStatus(TRUE)->save();
    $this->container->get('router.builder')->rebuild();
    $this->createNode('blog', 'June', mktime(12, 0, 0, 6, 10, 2024));
    $this->createNode('blog', 'Denied', mktime(12, 0, 0, 7, 10, 2024), ['private' => 1]);
    $this->container->get(NodeAccessRebuild::class)->rebuild();

    $build = $this->container->get('plugin.manager.block')
      ->createInstance('wordpal_archives', ['bundle' => 'blog', 'show_post_counts' => TRUE])
      ->build();
    self::assertContains('user.node_grants:view', $build['#cache']['contexts']);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertStringContainsString('June 2024', $html);
    self::assertStringNotContainsString('July 2024', $html);
  }

  /**
   * Tests the dropdown variant renders a select instead of a list.
   */
  public function testArchivesDropdown(): void {
    View::load('archive')->setStatus(TRUE)->save();
    $this->container->get('router.builder')->rebuild();
    $this->createNode('blog', 'June', mktime(12, 0, 0, 6, 10, 2024));

    $html = $this->renderBlock('wordpal_archives', ['bundle' => 'blog', 'display_as_dropdown' => TRUE]);

    self::assertStringNotContainsString('<ul', $html);
    self::assertMatchesRegularExpression('#<div class="wp-block-archives-dropdown wp-block-archives"><label for="(wp-block-archives[^"]*)" class="wp-block-archives__label">.*</label>\s*<select id="\1" name="archive-dropdown">\s*<option value="">.*</option>\s*<option value="/archive/202406">.*</option></select><script>#s', $html);
  }

  /**
   * Tests the taxonomy attribute picks the mapped vocabulary and its class.
   */
  public function testCategoriesTaxonomy(): void {
    $tag = Term::create(['vid' => 'tags', 'name' => 'City']);
    $tag->save();

    $html = $this->renderBlock('wordpal_categories', [
      'vocabulary' => 'tags',
      'taxonomy' => 'post_tag',
      'show_empty' => TRUE,
    ]);

    self::assertStringContainsString('wp-block-categories-taxonomy-post_tag', $html);
    self::assertStringNotContainsString('wp-block-categories-taxonomy-category', $html);
  }

  /**
   * Tests terms nest under their parents, or list flat without hierarchy.
   */
  public function testCategoriesTree(): void {
    $parent = Term::create(['vid' => 'tags', 'name' => 'Parent', 'weight' => 0]);
    $parent->save();
    $child = Term::create(['vid' => 'tags', 'name' => 'Child', 'parent' => $parent->id()]);
    $child->save();
    Term::create(['vid' => 'tags', 'name' => 'Grandchild', 'parent' => $child->id()])->save();
    Term::create(['vid' => 'tags', 'name' => 'Second', 'weight' => 1])->save();
    $names = static function (array $items) use (&$names): array {
      return array_map(static fn (array $item): array => [$item['name'] => $names($item['children'])], $items);
    };
    $build = fn (bool $hierarchy): array => $this->container->get('plugin.manager.block')->createInstance('wordpal_categories', [
      'vocabulary' => 'tags',
      'show_empty' => TRUE,
      'show_hierarchy' => $hierarchy,
    ])->build();

    self::assertSame([['Parent' => [['Child' => [['Grandchild' => []]]]]], ['Second' => []]], $names($build(TRUE)['#items']));
    self::assertSame([['Parent' => []], ['Child' => []], ['Grandchild' => []], ['Second' => []]], $names($build(FALSE)['#items']));
  }

  /**
   * Tests the dropdown variant renders a select instead of a list.
   */
  public function testCategoriesDropdown(): void {
    $parent = Term::create(['vid' => 'tags', 'name' => 'Parent']);
    $parent->save();
    $child = Term::create(['vid' => 'tags', 'name' => 'Child', 'parent' => $parent->id()]);
    $child->save();

    $html = $this->renderBlock('wordpal_categories', [
      'vocabulary' => 'tags',
      'show_empty' => TRUE,
      'show_hierarchy' => TRUE,
      'display_as_dropdown' => TRUE,
    ]);

    self::assertStringNotContainsString('<ul', $html);
    self::assertMatchesRegularExpression('#<div class="wp-block-categories-dropdown wp-block-categories-taxonomy-category wp-block-categories"><label class="wp-block-categories__label" for="(wp-block-categories[^"]*)">.*</label><select name="category_name" id="\1" class="postform">\s*<option value="-1">.*</option>\s*<option class="level-0" value="/taxonomy/term/\d+">.*</option>\s*<option class="level-1" value="/taxonomy/term/\d+">.*</option>\s*</select><script>#s', $html);
  }

}
