<?php

declare(strict_types=1);

namespace Drupal\wordpal\Twig;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Xss;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\wordpal\Comment\CommentRuntime;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal\Support\BlockSupports;
use Drupal\wordpal\Support\Cacheable;
use Drupal\wordpal\Support\PresetValue;
use Drupal\wordpal\Support\PropSchema;
use Drupal\wordpal\Support\SupportOutput;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Yaml;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Gives components the attributes their block supports produce.
 */
final class WordpalExtension extends AbstractExtension {

  /**
   * The decoded WordPress social services, keyed by service.
   */
  private ?array $socialServices = NULL;

  /**
   * The opening tag name of a Frozen block's first element that draws a box.
   */
  private const FROZEN_ROOT = '/<(?!(?:style|script|link|meta|template|noscript)\b)[a-z][a-z0-9-]*/i';

  /**
   * The default theme's machine name and directory, for Frozen block lookups.
   *
   * @var array{0: string, 1: string}|null
   */
  private ?array $frozenTheme = NULL;

  public function __construct(
    private readonly BlockSupports $supports,
    private readonly RendererInterface $renderer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeExtensionList $themeList,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly ThemeSettings $themeSettings,
    #[Autowire(param: 'app.root')]
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('wordpal_attributes', $this->attributes(...)),
      new TwigFunction('wordpal_layout_attributes', $this->layoutAttributes(...)),
      new TwigFunction('wordpal_css_value', PresetValue::css(...)),
      new TwigFunction('wordpal_border_props', static fn (): array => PropSchema::BORDER_PROPS),
      new TwigFunction('wordpal_css_declarations', $this->declarations(...)),
      new TwigFunction('wordpal_html', $this->html(...), ['is_safe' => ['html']]),
      new TwigFunction('wordpal_post_content', $this->postContent(...), ['is_safe' => ['html']]),
      new TwigFunction('wordpal_date', $this->date(...)),
      new TwigFunction('wordpal_default_date_format', static fn (): string => CommentRuntime::DEFAULT_DATE_FORMAT),
      new TwigFunction('wordpal_author', $this->author(...)),
      new TwigFunction('wordpal_author_avatar', $this->authorAvatar(...)),
      new TwigFunction('wordpal_social_service', $this->socialService(...)),
      new TwigFunction('wordpal_query_title', $this->queryTitle(...)),
      new TwigFunction('wordpal_term_description', $this->termDescription(...)),
      new TwigFunction('wordpal_url', UrlHelper::stripDangerousProtocols(...)),
      new TwigFunction('wordpal_css_url', $this->cssUrl(...)),
      new TwigFunction('wordpal_background_style', $this->backgroundStyle(...)),
      new TwigFunction('wordpal_duotone_filter', $this->duotoneFilter(...)),
      new TwigFunction('wordpal_frozen_html', $this->frozenHtml(...)),
      new TwigFunction('wordpal_accordion_item', $this->accordionItem(...), ['is_safe' => ['html']]),
      new TwigFunction('wordpal_avatar', $this->avatar(...)),
    ];
  }

  /**
   * Returns a Frozen block's HTML from the default theme's frozen files.
   *
   * A Canvas editor can set the key, so only a value shaped like a SHA-256
   * hash reaches the file system, and an unknown key renders nothing. The
   * plugin stylesheet libraries the theme lists for the key bubble to the
   * page. The first element that draws a box carries `data-wordpal-frozen`,
   * holding the block name, which frozen-html.css marks with an outline.
   *
   * @return \Drupal\Component\Render\MarkupInterface[]
   *   The HTML split at its Query holes, which the template fills with the
   *   hole slots; empty for an unknown key.
   */
  public function frozenHtml(?string $key, ?string $blockName): array {
    if ($key === NULL || !FrozenBlock::isKey($key)) {
      return [];
    }
    if ($this->frozenTheme === NULL) {
      $theme = $this->configFactory->get('system.theme')->get('default');
      $this->frozenTheme = [$theme, $this->appRoot . '/' . $this->themeList->getPath($theme) . '/'];
    }
    [$theme, $directory] = $this->frozenTheme;
    $path = $directory . FrozenBlock::path($key);
    if (!is_file($path)) {
      return [];
    }
    $libraries = $directory . FrozenBlock::librariesPath($key);
    if (is_file($libraries)) {
      $build = ['#attached' => ['library' => array_map(static fn (string $library): string => "$theme/$library", Yaml::parseFile($libraries))]];
      $this->renderer->render($build);
    }
    // An editor can set the block name, so it is escaped.
    $mark = ' ' . FrozenBlock::MARK_ATTRIBUTE . '="' . Html::escape((string) $blockName) . '"';
    $html = (string) preg_replace(self::FROZEN_ROOT, '$0' . $mark, (string) file_get_contents($path), 1);
    return array_map(Markup::create(...), FrozenBlock::split($html));
  }

  /**
   * Links an accordion item's heading and panel, as WordPress does.
   *
   * WordPress's accordion item render callback gives the item a generated
   * id and the directives its view module reads. The heading button's
   * aria-expanded holds the value the Interactivity API computes on the
   * server from openByDefault. An item lacking its toggle or panel stays as
   * it is. $markup is the item's rendered component markup.
   */
  public function accordionItem(string|MarkupInterface $markup, bool $openByDefault): MarkupInterface {
    $document = Html::load((string) $markup);
    $xpath = new \DOMXPath($document);
    $find = static fn (string $class): ?\DOMElement => $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]')->item(0);
    $item = $find('wp-block-accordion-item');
    $toggle = $find('wp-block-accordion-heading__toggle');
    $panel = $find('wp-block-accordion-panel');
    if ($item === NULL || $toggle === NULL || $panel === NULL) {
      return Markup::create((string) $markup);
    }
    $id = Html::getUniqueId('accordion-item');
    $open = $openByDefault ? 'true' : 'false';
    foreach ([
      [$item, 'data-wp-context', '{ "id": "' . $id . '", "openByDefault": ' . $open . ' }'],
      [$item, 'data-wp-class--is-open', 'state.isOpen'],
      [$item, 'data-wp-init', 'callbacks.initAccordionItems'],
      [$item, 'data-wp-on-window--hashchange', 'callbacks.hashChange'],
      [$toggle, 'data-wp-on--click', 'actions.toggle'],
      [$toggle, 'id', $id],
      [$toggle, 'aria-controls', "$id-panel"],
      [$toggle, 'data-wp-bind--aria-expanded', 'state.isOpen'],
      [$toggle, 'aria-expanded', $open],
      [$panel, 'id', "$id-panel"],
      [$panel, 'aria-labelledby', $id],
      [$panel, 'data-wp-bind--hidden', 'state.isHidden'],
      [$panel, 'data-wp-on--beforematch', 'actions.handleBeforeMatch'],
    ] as [$element, $name, $value]) {
      $element->setAttribute($name, $value);
    }
    if (!$openByDefault) {
      foreach ($document->getElementsByTagName('img') as $image) {
        $image->setAttribute('fetchpriority', 'low');
      }
    }
    return Markup::create(Html::serialize($document));
  }

  /**
   * Filters HTML supplied by a rich-text prop.
   */
  public function html(string $html): string|MarkupInterface {
    return Markup::create(Xss::filterAdmin($html));
  }

  /**
   * Adds the WordPress paragraph class to post content.
   *
   * A MarkupInterface value is the field's processed text, already filtered
   * by its text format. Running Xss::filterAdmin() again would strip markup
   * that format allows, so it is only parsed and re-serialized. A plain
   * string comes from a source no text format filtered, such as a text
   * field a builder user typed, so it is filtered first.
   */
  public function postContent(MarkupInterface|string $html): MarkupInterface {
    $document = Html::load($html instanceof MarkupInterface ? (string) $html : Xss::filterAdmin($html));
    foreach ($document->getElementsByTagName('p') as $paragraph) {
      $paragraph->setAttribute('class', trim($paragraph->getAttribute('class') . ' wp-block-paragraph'));
    }
    return Markup::create(Html::serialize($document));
  }

  /**
   * Formats a timestamp in the effective timezone, varying the cache by it.
   *
   * DateFormatter::format() resolves the timezone Drupal already set for
   * this request, but that alone does not vary the render cache by it.
   * Bubbling the context here, the way attach() bubbles library metadata,
   * makes every caller of this function timezone-cacheable without each
   * one having to know to add the context itself.
   */
  public function date(int $timestamp, string $format): string {
    $build = ['#cache' => ['contexts' => ['timezone']]];
    $this->renderer->render($build);
    return $this->dateFormatter->format($timestamp, 'custom', $format);
  }

  /**
   * Builds an author label with a link when Drupal allows profile access.
   */
  public function author(int $id, bool $link, ?string $target = NULL, string $class = 'wp-block-post-author-name__link'): array {
    $target ??= '_self';
    $account = $this->entityTypeManager->getStorage('user')->load($id);
    if ($account === NULL) {
      throw new \UnexpectedValueException("Author account $id does not exist.");
    }
    $metadata = (new CacheableMetadata())->addCacheableDependency($account);
    $visible = Cacheable::allowed($account->access('view linked label', NULL, TRUE), $metadata);
    $build = ['#plain_text' => $account->getDisplayName()];
    if ($link && $visible) {
      $build = [
        '#type' => 'inline_template',
        '#template' => '<a href="{{ url }}" target="{{ target }}"{% if class %} class="{{ class }}"{% endif %}>{{ name }}</a>',
        '#context' => [
          'url' => Cacheable::url($account->toUrl(), $metadata),
          'target' => $target,
          'class' => $class,
          'name' => $account->getDisplayName(),
        ],
      ];
    }
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Builds an author's picture in WordPress avatar markup, or nothing.
   */
  public function authorAvatar(int $id, int $size): array {
    $account = $this->entityTypeManager->getStorage('user')->load($id);
    if ($account === NULL) {
      throw new \UnexpectedValueException("Author account $id does not exist.");
    }
    $metadata = (new CacheableMetadata())->addCacheableDependency($account);
    $build = [];
    $file = Cacheable::picture($account, $metadata);
    if ($file !== NULL) {
      $build = [
        '#type' => 'inline_template',
        '#template' => '<img alt="{{ name }}" src="{{ src }}" class="avatar avatar-{{ size }} photo" height="{{ size }}" width="{{ size }}" loading="lazy" decoding="async">',
        '#context' => ['name' => $account->getDisplayName(), 'src' => $file->createFileUrl(), 'size' => $size],
      ];
    }
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Builds an Avatar block's image of one author, or nothing.
   *
   * WordPress falls back to a Gravatar for an author with no picture.
   * Drupal core has no Gravatar service, so no image prints then. The link
   * to the author's page prints when Drupal allows profile access.
   *
   * @param int $id
   *   The author's user id.
   * @param int $size
   *   The image width and height, in pixels.
   * @param bool $link
   *   Whether the image links to the author's page.
   * @param string $target
   *   The link target.
   * @param \Drupal\Core\Template\Attribute $image
   *   The border classes and styles WordPress prints on the image.
   */
  public function avatar(int $id, int $size, bool $link, string $target, Attribute $image): array {
    $account = $this->entityTypeManager->getStorage('user')->load($id);
    if ($account === NULL) {
      throw new \UnexpectedValueException("Author account $id does not exist.");
    }
    $metadata = (new CacheableMetadata())->addCacheableDependency($account);
    $build = [];
    $file = Cacheable::picture($account, $metadata);
    if ($file !== NULL) {
      $image->addClass(['avatar', "avatar-$size", 'photo', 'wp-block-avatar__image'])
        ->setAttribute('alt', $account->getDisplayName() . ' Avatar')
        ->setAttribute('src', $file->createFileUrl())
        ->setAttribute('height', (string) $size)
        ->setAttribute('width', (string) $size)
        ->setAttribute('loading', 'lazy')
        ->setAttribute('decoding', 'async');
      $build = ['#markup' => Markup::create('<img' . $image . '>')];
      $visible = Cacheable::allowed($account->access('view linked label', NULL, TRUE), $metadata);
      if ($link && $visible) {
        $build = [
          '#type' => 'inline_template',
          '#template' => '<a href="{{ url }}" target="{{ target }}"{% if label %} aria-label="{{ label }}"{% endif %} class="wp-block-avatar__link">{{ image }}</a>',
          '#context' => [
            'url' => Cacheable::url($account->toUrl(), $metadata),
            'target' => $target,
            'label' => $target === '_blank' ? '(' . $account->getDisplayName() . ' author archive, opens in a new tab)' : '',
            'image' => $build,
          ],
        ];
      }
    }
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Returns the name and icon WordPress shows for one social service.
   *
   * WordPress shows its share icon for a service it does not know.
   */
  public function socialService(string $service): array {
    $this->socialServices ??= json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/components/social-link/services.json'), TRUE);
    $data = $this->socialServices[$service] ?? $this->socialServices['share'];
    return ['name' => $data['name'], 'icon' => Markup::create($data['icon'])];
  }

  /**
   * Builds the archive or search title of the current page.
   *
   * Follows get_the_archive_title(): a tag page reads "Tag:", a category
   * page "Category:", any other vocabulary its own name.
   *
   * @param string $type
   *   The page the title shows on: archive or search.
   * @param bool $showPrefix
   *   Whether an archive title names its vocabulary.
   * @param bool $showSearchTerm
   *   Whether a search title quotes the search keys.
   * @param string $tagVocabulary
   *   The vocabulary WordPress tags map to, or ''.
   * @param string $categoryVocabulary
   *   The vocabulary WordPress categories map to, or ''.
   * @param string $searchPath
   *   The path of the search page.
   * @param string $searchParameter
   *   The query parameter holding the search keys.
   *
   * @return array
   *   The title under #markup, or only its cacheability on any other page.
   */
  public function queryTitle(string $type, bool $showPrefix, bool $showSearchTerm, string $tagVocabulary, string $categoryVocabulary, string $searchPath, string $searchParameter): array {
    $metadata = (new CacheableMetadata())->addCacheContexts(['route', 'url.path', 'url.query_args:' . $searchParameter]);
    $build = [];
    $term = $this->currentTerm();
    $request = $this->requestStack->getCurrentRequest();
    if ($type === 'archive' && $term !== NULL) {
      $metadata->addCacheableDependency($term);
      $build['#markup'] = Html::escape($term->label());
      if ($showPrefix) {
        $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($term->bundle());
        $metadata->addCacheableDependency($vocabulary);
        $prefix = match ($term->bundle()) {
          $tagVocabulary => new TranslatableMarkup('Tag:'),
          $categoryVocabulary => new TranslatableMarkup('Category:'),
          default => new TranslatableMarkup('@vocabulary:', ['@vocabulary' => $vocabulary->label()]),
        };
        $build['#markup'] = new TranslatableMarkup('@prefix <span>@title</span>', [
          '@prefix' => $prefix,
          '@title' => $term->label(),
        ]);
      }
    }
    elseif ($type === 'search' && $searchPath !== '' && $request->getPathInfo() === $searchPath) {
      // The search keys arrive in the request.
      $keys = $request->query->all()[$searchParameter] ?? '';
      $build['#markup'] = $showSearchTerm && is_string($keys)
        ? new TranslatableMarkup('Search results for: “@keys”', ['@keys' => $keys])
        : new TranslatableMarkup('Search results');
    }
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Returns the current term page's filtered description, or ''.
   */
  public function termDescription(): string|MarkupInterface {
    $build = ['#cache' => ['contexts' => ['route']]];
    $term = $this->currentTerm();
    if ($term !== NULL) {
      CacheableMetadata::createFromRenderArray($build)->addCacheableDependency($term)->applyTo($build);
      if (!$term->get('description')->isEmpty()) {
        $build += [
          '#type' => 'processed_text',
          '#text' => $term->getDescription(),
          '#format' => $term->getFormat(),
        ];
      }
    }
    $html = $this->renderer->render($build);
    return trim((string) $html) === '' ? '' : $html;
  }

  /**
   * Returns the term of a term page, or NULL on any other page.
   */
  private function currentTerm(): ?TermInterface {
    if ($this->routeMatch->getRouteName() !== 'entity.taxonomy_term.canonical') {
      return NULL;
    }
    return $this->routeMatch->getParameter('taxonomy_term');
  }

  /**
   * Returns a filtered URL escaped for a quoted CSS url() value.
   */
  public function cssUrl(string $url): string {
    $url = UrlHelper::stripDangerousProtocols($url);
    $url = (string) preg_replace_callback('/[\s"\'()\\\\]/', static fn (array $match): string => rawurlencode($match[0]), $url);
    return 'url(' . $url . ')';
  }

  /**
   * Returns wp_render_background_support()'s five inline declarations.
   *
   * Prints in this order: image, position, repeat, size, attachment. Size
   * defaults to "cover"; position defaults to "50% 50%" only when the size
   * is "contain" with no position of its own. PresetValue::css()'s allowlist
   * excludes url(), so this builds the map directly.
   */
  public function backgroundStyle(?string $image, ?string $size, ?string $position, ?string $repeat, ?string $attachment): array {
    if (!$image) {
      return [];
    }
    $size ??= 'cover';
    return array_filter([
      'background-image' => $this->cssUrl($image),
      'background-position' => $size === 'contain' && !$position ? '50% 50%' : $position,
      'background-repeat' => $repeat,
      'background-size' => $size,
      'background-attachment' => $attachment,
    ]);
  }

  /**
   * Returns the SVG filter of a duotone preset, for printing after its block.
   *
   * WordPress prints it in the page footer instead. The SVG comes
   * from WordPress's own WP_Duotone at conversion time.
   */
  public function duotoneFilter(?string $duotone): Markup|string {
    $slug = PresetValue::duotoneSlug((string) $duotone);
    $svg = $slug === NULL ? NULL : $this->themeSettings->duotoneFilter($slug);
    return $svg === NULL ? '' : Markup::create($svg);
  }

  /**
   * Turns a map of CSS properties into a style attribute value.
   */
  public function declarations(array $styles): string {
    return (new SupportOutput([], $styles, []))->style();
  }

  /**
   * Returns the attributes for a block's root element.
   *
   * @param string $blockName
   *   The WordPress block name, such as "core/group".
   * @param array $props
   *   The component's Twig context.
   * @param bool $includeLayout
   *   FALSE when the layout belongs to an inner element, as in a cover block.
   * @param array $extraStyles
   *   Style declarations the block itself adds, such as a column width.
   * @param array $layoutDefaults
   *   The layout the block type ships, such as the flex row of a columns block.
   */
  public function attributes(string $blockName, array $props, bool $includeLayout = TRUE, array $extraStyles = [], array $layoutDefaults = []): Attribute {
    $output = $this->supports->render($blockName, $props, $includeLayout, $layoutDefaults);
    // wp_render_background_support() styles the root of any block that
    // carries a background image.
    $background = $this->backgroundStyle($props['background_image'] ?? NULL, $props['background_size'] ?? NULL, $props['background_position'] ?? NULL, $props['background_repeat'] ?? NULL, $props['background_attachment'] ?? NULL);
    if ($background !== []) {
      $output = new SupportOutput(['has-background', ...$output->classes], $output->styles, $output->cssRules, $output->attributes);
      $extraStyles += $background;
    }
    if ($extraStyles !== []) {
      $output = new SupportOutput($output->classes, $extraStyles + $output->styles, $output->cssRules, $output->attributes);
    }
    return $this->toAttribute($output);
  }

  /**
   * Returns the layout attributes, for a block that lays out an inner element.
   */
  public function layoutAttributes(string $blockName, array $props): Attribute {
    return $this->toAttribute($this->supports->layout($blockName, $props));
  }

  /**
   * Turns support output into attributes, and attaches the CSS it needs.
   */
  private function toAttribute(SupportOutput $output): Attribute {
    $this->attach($output);

    $attribute = new Attribute($output->attributes);
    if ($output->classes !== []) {
      $attribute->addClass($output->classes);
    }
    if ($output->styles !== []) {
      $attribute->setAttribute('style', $output->style());
    }
    return $attribute;
  }

  /**
   * Adds the CSS rules to the page head.
   *
   * Rendering an element with attachments bubbles them to the page, which is
   * how attach_library() works from a template. Blocks that share a layout
   * share the key, so the page carries one copy of the rule.
   */
  private function attach(SupportOutput $output): void {
    if ($output->cssRules === []) {
      return;
    }
    $stylesheet = $output->stylesheet();
    // A "<" could close the element. PresetValue or the prop enum rejects it
    // in every value, and the selectors are generated.
    if (str_contains($stylesheet, '<')) {
      throw new \UnexpectedValueException("Not a valid layout stylesheet: \"$stylesheet\".");
    }
    $key = 'wordpal-' . substr(md5($stylesheet), 0, 8);
    $build = [
      '#attached' => [
        'html_head' => [
          [
            // Markup keeps the child combinator ">", which the XSS filter
            // applied to a plain string value escapes.
            ['#tag' => 'style', '#value' => Markup::create($stylesheet)],
            $key,
          ],
        ],
      ],
    ];
    $this->renderer->render($build);
  }

}
