<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\system\Entity\Menu;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests runtime blocks that supply the WordPress page frame.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class SiteFrameBlockTest extends KernelTestBase {

  /**
   * Modules needed for identity and menu rendering.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'link',
    'node',
    'menu_link_content',
    'comment',
    'text',
    'wordpal',
  ];

  /**
   * Tests identity values and their configuration cache dependency.
   */
  public function testSiteIdentityBuilds(): void {
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('stark.settings')
      ->set('logo.use_default', FALSE)
      ->set('logo.path', 'public://fixture-logo.png')
      ->save();
    $this->container->get('cache.memory')->invalidateTags(['config:stark.settings']);
    $this->container->get('theme.manager')->resetActiveTheme();
    $this->config('system.site')
      ->set('name', 'Fixture site')
      ->set('slogan', 'Fixture tagline')
      ->save();
    $manager = $this->container->get('plugin.manager.block');

    $title = $manager->createInstance('wordpal_site_title', [])->build();
    self::assertSame('Fixture site', $title['#title']);
    self::assertContains('config:system.site', $title['#cache']['tags']);
    self::assertSame(1, $title['#level']);

    $tagline = $manager->createInstance('wordpal_site_tagline', [])->build();
    self::assertSame('Fixture tagline', $tagline['#tagline']);
    self::assertContains('config:system.site', $tagline['#cache']['tags']);

    $logoBlock = $manager->createInstance('wordpal_site_logo', []);
    $logo = $logoBlock->build();
    self::assertStringEndsWith('/fixture-logo.png', $logo['#logo_url']);
    self::assertSame('Fixture site', $logo['#alt']);
    self::assertContains('config:system.site', $logo['#cache']['tags']);
    self::assertContains('config:stark.settings', $logo['#cache']['tags']);
    self::assertContains('theme', $logoBlock->getCacheContexts());
  }

  /**
   * Tests that the site title link is current on the front page only.
   */
  public function testSiteTitleCurrentOnFrontPage(): void {
    $this->installConfig(['system']);
    $this->config('system.site')->set('name', 'Fixture site')->save();
    $manager = $this->container->get('plugin.manager.block');
    $renderer = $this->container->get('renderer');

    $title = $manager->createInstance('wordpal_site_title', [])->build();
    self::assertFalse($title['#current']);
    self::assertContains('url.path.is_front', $title['#cache']['contexts']);
    self::assertStringContainsString('rel="home">', (string) $renderer->renderInIsolation($title));

    $pathMatcher = $this->createMock(PathMatcherInterface::class);
    $pathMatcher->method('isFrontPage')->willReturn(TRUE);
    $this->container->set('path.matcher', $pathMatcher);
    $title = $manager->createInstance('wordpal_site_title', [])->build();
    self::assertStringContainsString('rel="home" aria-current="page">', (string) $renderer->renderInIsolation($title));
  }

  /**
   * Tests the site title link color.
   *
   * WordPress 7.1.2 block-supports/elements.php adds has-link-color and a
   * wp-elements class to the site title, whose block.json supports color.link.
   */
  public function testSiteTitleLinkColor(): void {
    $this->installConfig(['system']);
    $this->config('system.site')->set('name', 'Fixture site')->save();
    $build = $this->container->get('plugin.manager.block')
      ->createInstance('wordpal_site_title', [
        'level' => 0,
        'supports' => ['link_color' => 'var:preset|color|header-foreground'],
      ])
      ->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertMatchesRegularExpression('/^<p class="wp-block-site-title has-link-color wp-elements-[0-9a-f]{8}">/', $html);
  }

  /**
   * Tests the site title prints its saved className.
   */
  public function testSiteTitleCssClass(): void {
    $this->installConfig(['system']);
    $this->config('system.site')->set('name', 'Fixture site')->save();
    $build = $this->container->get('plugin.manager.block')
      ->createInstance('wordpal_site_title', [
        'level' => 0,
        'is_link' => FALSE,
        'supports' => ['css_class' => 'siteframe-base-copyright'],
      ])
      ->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertSame('<p class="wp-block-site-title siteframe-base-copyright">Fixture site</p>', $html);
  }

  /**
   * Tests the site title's color and typography support.
   *
   * WordPress 7.1.2 site-title/block.json supports color (background, text,
   * gradient, link) and the full typography set, all through the style
   * engine (core/site-title is a STYLE_ENGINE_BLOCKS entry).
   */
  public function testSiteTitleColorAndTypography(): void {
    $this->installConfig(['system']);
    $this->config('system.site')->set('name', 'Fixture site')->save();
    $build = $this->container->get('plugin.manager.block')
      ->createInstance('wordpal_site_title', [
        'level' => 0,
        'supports' => [
          'text_color' => 'accent',
          'font_style' => 'normal',
          'font_weight' => '600',
        ],
      ])
      ->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertStringContainsString('has-text-color', $html);
    self::assertStringContainsString('has-accent-color', $html);
    self::assertStringContainsString('font-style:normal', $html);
    self::assertStringContainsString('font-weight:600', $html);
  }

  /**
   * Tests the site title's child layout support.
   *
   * WordPress 7.1.2 block-supports/layout.php gives any block a
   * wp-container-content-<hash> class for a fixed flex-basis or a fill,
   * the same class LayoutCss::childLayout() already gives a component.
   */
  public function testSiteTitleChildLayout(): void {
    $this->installConfig(['system']);
    $this->config('system.site')->set('name', 'Fixture site')->save();
    $build = $this->container->get('plugin.manager.block')
      ->createInstance('wordpal_site_title', [
        'level' => 0,
        'supports' => ['self_stretch' => 'fixed', 'flex_size' => '30%'],
      ])
      ->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertMatchesRegularExpression('/class="wp-block-site-title wp-container-content-[0-9a-f]{8}"/', $html);
  }

  /**
   * Tests sorted menu links, access filtering, and cacheability.
   */
  public function testNavigationFiltersDeniedLinks(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'system']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $denied = Node::create(['type' => 'page', 'title' => 'Hidden', 'status' => 0]);
    $denied->save();
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    MenuLinkContent::create([
      'title' => 'Visible',
      'link' => ['uri' => 'internal:/visible'],
      'menu_name' => 'wordpal_main',
      'weight' => -10,
      'enabled' => TRUE,
    ])->save();
    MenuLinkContent::create([
      'title' => 'Hidden',
      'link' => ['uri' => 'entity:node/' . $denied->id()],
      'menu_name' => 'wordpal_main',
      'enabled' => TRUE,
    ])->save();

    $build = $this->container->get('plugin.manager.block')->createInstance(
      'wordpal_navigation',
      ['menu' => 'wordpal_main', 'supports' => ['justify_content' => 'right']],
    )->build();
    self::assertSame(['Visible'], array_column($build['#items'], 'title'));
    self::assertSame('right', $build['#supports']['justify_content']);
    self::assertSame('WordPal main', $build['#aria_label']);
    $unnamed = $this->container->get('plugin.manager.block')->createInstance(
      'wordpal_navigation',
      ['menu' => 'wordpal_main', 'menu_label' => FALSE],
    )->build();
    self::assertSame('', $unnamed['#aria_label'], 'A block with its own links and no ariaLabel has no label, as in WordPress.');
    self::assertStringStartsWith('modal-wordpal-main', $build['#modal_id']);
    self::assertContains('config:system.menu.wordpal_main', $build['#cache']['tags']);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
  }

  /**
   * Tests that only the link to the current page is marked current.
   */
  public function testNavigationMarksCurrentLink(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['user', 'field', 'node', 'system']);
    user_role_grant_permissions('anonymous', ['access content']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $current = Node::create(['type' => 'page', 'title' => 'Current', 'status' => 1]);
    $current->save();
    $other = Node::create(['type' => 'page', 'title' => 'Other', 'status' => 1]);
    $other->save();
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    $parent = MenuLinkContent::create([
      'title' => 'Other',
      'link' => ['uri' => 'entity:node/' . $other->id()],
      'menu_name' => 'wordpal_main',
      'expanded' => TRUE,
      'enabled' => TRUE,
    ]);
    $parent->save();
    MenuLinkContent::create([
      'title' => 'Current',
      'link' => ['uri' => 'entity:node/' . $current->id()],
      'menu_name' => 'wordpal_main',
      'parent' => $parent->getPluginId(),
      'enabled' => TRUE,
    ])->save();

    // Route-match through the real router, the way the kernel does for a
    // live request, so upcast and raw parameters both match what the menu
    // link system compares against.
    $request = Request::create('/node/' . $current->id());
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->add($this->container->get('router.no_access_checks')->matchRequest($request));
    $this->container->get('request_stack')->push($request);
    $this->container->get('menu.active_trail')->clear();

    $build = $this->container->get('plugin.manager.block')->createInstance(
      'wordpal_navigation',
      ['menu' => 'wordpal_main'],
    )->build();
    $otherItem = $build['#items'][array_key_first($build['#items'])];
    self::assertSame('Other', $otherItem['title']);
    self::assertNotSame($build['#active_link'], $otherItem['original_link']->getPluginId(), 'An ancestor of the current page is not current.');
    self::assertTrue($otherItem['in_active_trail']);
    $currentItem = $otherItem['below'][array_key_first($otherItem['below'])];
    self::assertSame('Current', $currentItem['title']);
    self::assertSame($build['#active_link'], $currentItem['original_link']->getPluginId());
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertSame(1, substr_count($html, 'aria-current="page"'));
    self::assertSame(1, substr_count($html, 'current-menu-item'));
    self::assertSame(1, substr_count($html, 'current-menu-ancestor'));
    self::assertMatchesRegularExpression('/<li class="[^"]*has-child[^"]*current-menu-ancestor[^"]*"/', $html);
    self::assertContains('route.menu_active_trails:wordpal_main', $build['#cache']['contexts']);
  }

  /**
   * Tests a link to a route without a post or term is never current.
   *
   * WordPress marks a Navigation Link current only through the post or term
   * its id names, so a custom URL link stays unmarked on its own page.
   */
  public function testNavigationLeavesCustomLinkUnmarked(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system', 'user']);
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    MenuLinkContent::create([
      'title' => 'Log in',
      'link' => ['uri' => 'internal:/user/login'],
      'menu_name' => 'wordpal_main',
      'enabled' => TRUE,
    ])->save();
    $request = Request::create('/user/login');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->add($this->container->get('router.no_access_checks')->matchRequest($request));
    $this->container->get('request_stack')->push($request);
    $this->container->get('menu.active_trail')->clear();

    $build = $this->container->get('plugin.manager.block')
      ->createInstance('wordpal_navigation', ['menu' => 'wordpal_main'])
      ->build();

    self::assertNull($build['#active_link']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertStringNotContainsString('current-menu-item', $html);
    self::assertStringNotContainsString('aria-current', $html);
  }

  /**
   * Tests the navigation hands core's menu tree items to its template.
   */
  public function testNavigationUsesCoreMenuBuild(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system']);
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    MenuLinkContent::create([
      'title' => 'Visible',
      'link' => ['uri' => 'internal:/visible'],
      'menu_name' => 'wordpal_main',
      'enabled' => TRUE,
    ])->save();
    $build = $this->container->get('plugin.manager.block')
      ->createInstance('wordpal_navigation', ['menu' => 'wordpal_main'])
      ->build();
    $item = $build['#items'][array_key_first($build['#items'])];
    self::assertInstanceOf(Url::class, $item['url']);
    self::assertInstanceOf(MenuLinkInterface::class, $item['original_link']);
    self::assertArrayHasKey('in_active_trail', $item);
    self::assertArrayHasKey('#active_link', $build);
  }

  /**
   * Tests the classes and labels WordPress's Navigation block prints.
   *
   * WordPress 7.1.2 blocks/navigation.php: get_layout_class() adds only
   * is-vertical, block_core_navigation_build_css_colors() builds the overlay
   * classes, and the link list takes aria-label from ariaLabel alone. The
   * list container also carries the nav's own support classes (color,
   * typography, is-vertical, no-wrap): confirmed against neve-fse's reference
   * render, where the <ul> repeats is-responsive, items-justified-right and
   * wp-block-navigation from the <nav>.
   */
  public function testNavigationMarkupFollowsWordPress(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system']);
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    MenuLinkContent::create([
      'title' => 'Visible',
      'link' => ['uri' => 'internal:/visible'],
      'menu_name' => 'wordpal_main',
      'enabled' => TRUE,
    ])->save();
    $render = function (array $configuration): \DOMXPath {
      $build = $this->container->get('plugin.manager.block')
        ->createInstance('wordpal_navigation', ['menu' => 'wordpal_main'] + $configuration)
        ->build();
      $document = new \DOMDocument();
      @$document->loadHTML('<body>' . $this->container->get('renderer')->renderInIsolation($build) . '</body>');
      return new \DOMXPath($document);
    };
    $classes = static fn (\DOMXPath $xpath, string $query): array => explode(' ', $xpath->query($query)->item(0)->getAttribute('class'));

    $responsive = $render([
      'overlay_background_color' => 'base',
      'overlay_text_color' => 'contrast',
    ]);
    self::assertNotContains('is-horizontal', $classes($responsive, '//nav'), 'A block with no layout orientation prints none.');
    // WordPress 7.1.2 prints flex-wrap only for nowrap (layout.php:735).
    self::assertEmpty(preg_grep('/^wp-container-/', $classes($responsive, '//nav')), 'A wrapping flex layout with no gap prints no container class.');
    self::assertNotContains('is-horizontal', $classes($responsive, '//ul'));
    self::assertSame(
      [
        'wp-block-navigation__responsive-container',
        'has-text-color',
        'has-contrast-color',
        'has-background',
        'has-base-background-color',
      ],
      $classes($responsive, '//div[contains(@class, "wp-block-navigation__responsive-container")]'),
    );
    self::assertFalse($responsive->query('//ul')->item(0)->hasAttribute('aria-label'), 'The list has no ariaLabel to print.');

    $custom = $render([
      'custom_overlay_background_color' => '#000000',
      'custom_overlay_text_color' => '#ffffff',
    ]);
    $container = $custom->query('//div[contains(@class, "wp-block-navigation__responsive-container")]')->item(0);
    self::assertSame('color: #ffffff;background-color: #000000;', $container->getAttribute('style'));
    self::assertSame(['wp-block-navigation__responsive-container', 'has-text-color', 'has-background'], explode(' ', $container->getAttribute('class')));

    $plain = $render(['overlay_menu' => 'never', 'menu_label' => FALSE, 'supports' => ['orientation' => 'vertical']]);
    self::assertFalse($plain->query('//ul')->item(0)->hasAttribute('aria-label'), 'A block with its own links and no ariaLabel prints no list label.');
    self::assertContains('is-vertical', $classes($plain, '//nav'));
    self::assertContains('is-vertical', $classes($plain, '//ul'));

    $labelled = $render([
      'overlay_menu' => 'never',
      'supports' => ['aria_label' => 'Social', 'orientation' => 'horizontal'],
    ]);
    self::assertSame('Social', $labelled->query('//ul')->item(0)->getAttribute('aria-label'));
    self::assertContains('is-horizontal', $classes($labelled, '//nav'), 'Layout support prints the orientation the block sets.');
    self::assertNotContains('is-horizontal', $classes($labelled, '//ul'));

    $colored = $render(['text_color' => 'header-foreground', 'supports' => ['text_transform' => 'uppercase']]);
    foreach (['//nav', '//ul'] as $query) {
      self::assertContains('has-header-foreground-color', $classes($colored, $query));
      self::assertContains('has-text-color', $classes($colored, $query));
      self::assertStringContainsString('text-transform:uppercase', $colored->query($query)->item(0)->getAttribute('style'));
    }
  }

  /**
   * Tests the nowrap class and the font-family support on the nav wrapper.
   *
   * WordPress 7.1.2 navigation.php's own get_layout_class() adds "no-wrap"
   * only for a nowrap flexWrap, alongside the generic layout support's own
   * "is-nowrap"; font-family is a plain preset-slug typography support, the
   * same mechanism as font-size.
   */
  public function testNavigationFlexWrapAndFontFamily(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system']);
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    MenuLinkContent::create([
      'title' => 'Visible',
      'link' => ['uri' => 'internal:/visible'],
      'menu_name' => 'wordpal_main',
      'enabled' => TRUE,
    ])->save();
    $render = function (array $configuration): string {
      $build = $this->container->get('plugin.manager.block')
        ->createInstance('wordpal_navigation', ['menu' => 'wordpal_main'] + $configuration)
        ->build();
      return (string) $this->container->get('renderer')->renderInIsolation($build);
    };

    $wrapping = $render(['supports' => ['flex_wrap' => 'wrap']]);
    self::assertStringNotContainsString('no-wrap', $wrapping);

    $nowrap = $render(['supports' => ['flex_wrap' => 'nowrap']]);
    self::assertStringContainsString('no-wrap', $nowrap);
    self::assertSame(2, substr_count($nowrap, 'no-wrap'), 'The <nav> and its list both carry no-wrap.');

    $fonted = $render(['supports' => ['font_family' => 'system']]);
    self::assertStringContainsString('has-system-font-family', $fonted);
  }

  /**
   * Tests the three remaining typography supports core/navigation declares.
   *
   * WordPress 7.1.2's navigation/block.json declares lineHeight and
   * __experimentalLetterSpacing as generic typography styles. It skips the
   * serialization of __experimentalTextDecoration, which navigation.php
   * prints as a has-text-decoration-<value> class.
   */
  public function testNavigationRemainingTypographySupports(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system']);
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    MenuLinkContent::create([
      'title' => 'Visible',
      'link' => ['uri' => 'internal:/visible'],
      'menu_name' => 'wordpal_main',
      'enabled' => TRUE,
    ])->save();
    $render = function (array $configuration): string {
      $build = $this->container->get('plugin.manager.block')
        ->createInstance('wordpal_navigation', ['menu' => 'wordpal_main'] + $configuration)
        ->build();
      return (string) $this->container->get('renderer')->renderInIsolation($build);
    };

    $styled = $render([
      'supports' => [
        'text_decoration' => 'underline',
        'letter_spacing' => '1px',
        'line_height' => '1.5',
      ],
    ]);
    self::assertSame(2, substr_count($styled, 'has-text-decoration-underline'), 'The <nav> and its list print the decoration as a class.');
    self::assertStringNotContainsString('text-decoration:underline', $styled);
    self::assertStringContainsString('letter-spacing:1px', $styled);
    self::assertStringContainsString('line-height:1.5', $styled);
  }

  /**
   * Tests the toggle icon and the justification class without an overlay.
   *
   * WordPress 7.1.2 navigation.php prints the 3-path "menu" icon with no
   * aria-hidden/focusable only when the block's icon attribute is "menu",
   * and get_layout_class() adds items-justified-<x> from the layout alone,
   * regardless of whether the menu collapses into an overlay.
   */
  public function testNavigationIconAndJustificationWithoutOverlay(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system']);
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    MenuLinkContent::create([
      'title' => 'Visible',
      'link' => ['uri' => 'internal:/visible'],
      'menu_name' => 'wordpal_main',
      'enabled' => TRUE,
    ])->save();
    $render = function (array $configuration): string {
      $build = $this->container->get('plugin.manager.block')
        ->createInstance('wordpal_navigation', ['menu' => 'wordpal_main'] + $configuration)
        ->build();
      return (string) $this->container->get('renderer')->renderInIsolation($build);
    };

    $plain = $render(['overlay_menu' => 'never', 'supports' => ['justify_content' => 'left']]);
    self::assertStringContainsString('items-justified-left', $plain, 'The class applies without an overlay.');

    $handle = $render(['icon' => 'handle']);
    self::assertStringContainsString('aria-hidden="true" focusable="false"><path d="M4 7.5h16v1.5H4z">', $handle);

    $menu = $render(['icon' => 'menu']);
    self::assertStringContainsString('viewBox="0 0 24 24"><path d="M5 5v1.5h14V5H5z"></path><path d="M5 12.8h14v-1.5H5v1.5z"></path><path d="M5 19h14v-1.5H5V19z">', $menu);
    self::assertStringNotContainsString('aria-hidden="true" focusable="false"><path d="M5 5v1.5h14V5H5z">', $menu);

    // navigation.php prints text toggles with no aria-label when hasIcon is
    // false, and marks an overlay that always shows.
    $text = $render(['has_icon' => FALSE, 'overlay_menu' => 'always']);
    self::assertStringContainsString('<button aria-haspopup="dialog" class="wp-block-navigation__responsive-container-open always-shown"', $text);
    self::assertStringContainsString('Menu</button>', $text);
    self::assertStringContainsString('<button class="wp-block-navigation__responsive-container-close" data-wp-on--click="actions.closeMenuOnClick">Close</button>', $text);
    self::assertStringContainsString('wp-block-navigation__responsive-container hidden-by-default', $text);
  }

  /**
   * Tests that an unsafe overlay color fails before it reaches markup.
   */
  public function testNavigationRejectsUnsafeOverlayColors(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system']);
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    $manager = $this->container->get('plugin.manager.block');
    $safe = [
      'custom_overlay_text_color' => '#000',
      'custom_overlay_background_color' => 'rgb(0 0 0)',
      'overlay_text_color' => 'base',
      'overlay_background_color' => 'contrast',
    ];
    $build = $manager->createInstance('wordpal_navigation', ['menu' => 'wordpal_main'] + $safe)->build();
    self::assertSame('wordpal_navigation', $build['#theme'], 'Safe overlay values build with the same menu.');
    $rejected = [];
    foreach ([
      'custom_overlay_text_color' => 'red;position:fixed;inset:0',
      'custom_overlay_background_color' => '#000;background:url(//x.test/a)',
      'overlay_text_color' => 'base injected',
      'overlay_background_color' => 'base"',
    ] as $key => $value) {
      try {
        $manager->createInstance('wordpal_navigation', ['menu' => 'wordpal_main', $key => $value])->build();
      }
      catch (\UnexpectedValueException) {
        $rejected[] = $key;
      }
    }
    self::assertSame([
      'custom_overlay_text_color',
      'custom_overlay_background_color',
      'overlay_text_color',
      'overlay_background_color',
    ], $rejected);
  }

}
