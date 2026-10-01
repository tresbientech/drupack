<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\Tests\wordpal\Traits\ThemeSettingsTrait;
use Drupal\wordpal\Plugin\Block\NavigationBlock;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Component\CommentBlockInputs;
use Drupal\wordpal_convert\Component\ComponentInputs;
use Drupal\wordpal_convert\NavigationMenus;
use Drupal\wordpal_convert\Theme\BlockNode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Navigation blocks keep their submenus and Home link.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class NavigationMenusTest extends KernelTestBase {

  use ThemeSettingsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'link', 'node', 'menu_link_content',
    'comment', 'views', 'text', 'wordpal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system']);
    $this->writeThemeSettings();
  }

  /**
   * Tests a submenu's links follow it, and a Home link targets the front.
   */
  public function testCollectsSubmenusAndHomeLink(): void {
    $skipped = [];
    $links = NavigationMenus::links($this->navigation(), $skipped);

    self::assertSame([
      ['title' => 'Home', 'uri' => 'route:<front>', 'parent' => NULL, 'home' => TRUE, 'attributes' => []],
      ['title' => 'Walks', 'uri' => 'internal:/walks', 'parent' => NULL, 'home' => FALSE, 'attributes' => []],
      ['title' => 'City', 'uri' => 'internal:/walks/city', 'parent' => 1, 'home' => FALSE, 'attributes' => []],
      ['title' => 'More', 'uri' => 'route:<nolink>', 'parent' => 1, 'home' => FALSE, 'attributes' => []],
      ['title' => 'Coast', 'uri' => 'internal:/walks/coast', 'parent' => 3, 'home' => FALSE, 'attributes' => []],
      [
        'title' => 'About',
        'uri' => 'internal:/about',
        'parent' => NULL,
        'home' => FALSE,
        'attributes' => ['class' => 'buy', 'target' => '_blank', 'rel' => 'nofollow'],
      ],
    ], $links);
    self::assertSame(['core/navigation-link "Bad" (javascript:alert(1)): its URL is not a root-relative path, a fragment or an http(s) URL'], $skipped);
    self::assertNull(NavigationMenus::links(new BlockNode('core/navigation', [], '', [
      new BlockNode('core/navigation-submenu', ['label' => 'Walks'], '', [new BlockNode('core/site-logo', [], '', [])]),
    ])), 'A submenu holding another block renders the site navigation.');
  }

  /**
   * Tests the written menu renders WordPress's submenu and Home link.
   */
  public function testRendersSubmenusAndHomeLink(): void {
    $menus = NavigationMenus::collect(['header' => [$this->navigation()]], 'fixture', 'Fixture', TRUE)['menus'];
    (new NavigationMenus($this->container->get('entity_type.manager')))->write('fixture', $menus);
    $menuId = array_key_first($menus);

    $home = MenuLinkContent::load(array_key_first($this->container->get('entity_type.manager')->getStorage('menu_link_content')->loadByProperties(['title' => 'Home'])));
    self::assertTrue($home->get('link')->first()->getUrl()->getOption(NavigationBlock::HOME_LINK_OPTION));

    $build = $this->container->get('plugin.manager.block')->createInstance('wordpal_navigation', [
      'menu' => $menuId,
      'overlay_menu' => 'never',
      'show_submenu_icon' => TRUE,
      'submenu_visibility' => 'hover',
    ])->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertContains('wordpal/interactivity.navigation', $build['#attached']['library'], 'Submenus with a toggle make the block interactive.');
    self::assertStringContainsString('<li class="wp-block-navigation-item wp-block-home-link"><a class="wp-block-home-link__content wp-block-navigation-item__content" href="/" rel="home">Home</a></li>', $html);
    self::assertMatchesRegularExpression('#<li class="wp-block-navigation-item has-child open-on-hover-click wp-block-navigation-submenu" data-wp-interactive="core/navigation" data-wp-context=\'\\{ "submenuOpenedBy": \\{ "click": false, "hover": false, "focus": false \\}, "type": "submenu", "modal": null, "previousFocus": null \\}\' data-wp-watch="callbacks.initMenu" data-wp-on--focusout="actions.handleMenuFocusout" data-wp-on--keydown="actions.handleMenuKeydown" tabindex="-1" data-wp-on--pointerenter="actions.openMenuOnHover" data-wp-on--pointerleave="actions.closeMenuOnHover"><a class="wp-block-navigation-item__content" href="/walks"><span class="wp-block-navigation-item__label">Walks</span></a><button aria-label="Walks submenu" class="wp-block-navigation__submenu-icon wp-block-navigation-submenu__toggle" data-wp-on--click="actions.toggleMenuOnClick" data-wp-bind--aria-expanded="state.isSubmenuOpen"><svg #', $html);
    self::assertStringContainsString('<ul class="wp-block-navigation__submenu-container wp-block-navigation-submenu" data-wp-on--focus="actions.openMenuOnFocus">', $html);
    self::assertStringContainsString('<a class="wp-block-navigation-item__content"><span class="wp-block-navigation-item__label">More</span></a>', $html, 'A submenu without a URL prints no href.');
    self::assertSame(2, substr_count($html, 'wp-block-navigation-submenu__toggle'), 'The nested submenu has its toggle too.');
    self::assertStringContainsString('<li class="wp-block-navigation-item buy wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="/about" target="_blank" rel="nofollow"><span class="wp-block-navigation-item__label">About</span></a></li>', $html, 'A link keeps its class, new-tab target and rel.');

    $click = $this->container->get('plugin.manager.block')->createInstance('wordpal_navigation', [
      'menu' => $menuId,
      'overlay_menu' => 'never',
      'show_submenu_icon' => FALSE,
      'submenu_visibility' => 'click',
    ])->build();
    $clickHtml = (string) $this->container->get('renderer')->renderInIsolation($click);
    self::assertStringContainsString('<button aria-label="Walks submenu" class="wp-block-navigation-item__content wp-block-navigation-submenu__toggle" data-wp-on--click="actions.toggleMenuOnClick" data-wp-bind--aria-expanded="state.isSubmenuOpen"><span class="wp-block-navigation-item__label">Walks</span></button><span class="wp-block-navigation__submenu-icon"><svg', $clickHtml);
    self::assertStringContainsString('open-on-click', $clickHtml);

    $pageList = $this->container->get('plugin.manager.block')->createInstance('wordpal_navigation', [
      'menu' => $menuId,
      'overlay_menu' => 'never',
      'show_submenu_icon' => TRUE,
      'submenu_visibility' => 'hover',
      'page_list' => TRUE,
    ])->build();
    $pageListHtml = (string) $this->container->get('renderer')->renderInIsolation($pageList);
    self::assertMatchesRegularExpression('#<ul class="wp-block-navigation__container wp-block-navigation"><ul class="wp-block-page-list"><li class="wp-block-pages-list__item wp-block-navigation-item open-on-hover-click"><a class="wp-block-pages-list__item__link wp-block-navigation-item__content" href="/">Home</a></li>#', $pageListHtml);
    self::assertStringContainsString('<li class="wp-block-pages-list__item has-child wp-block-navigation-item open-on-hover-click"><a class="wp-block-pages-list__item__link wp-block-navigation-item__content" href="/walks">Walks</a><button aria-label="Walks submenu" class="wp-block-navigation__submenu-icon wp-block-navigation-submenu__toggle" aria-expanded="false"><svg', $pageListHtml);
    self::assertStringContainsString('<ul class="wp-block-navigation__submenu-container"><li class="wp-block-pages-list__item wp-block-navigation-item open-on-hover-click"><a class="wp-block-pages-list__item__link wp-block-navigation-item__content" href="/walks/city">City</a></li>', $pageListHtml);
  }

  /**
   * Tests Social Links after the links are owned, and before them are not.
   */
  public function testSocialLinksAfterLinksAreOwned(): void {
    $link = static fn (string $label): BlockNode => new BlockNode('core/navigation-link', [
      'label' => $label,
      'url' => '/' . strtolower($label),
    ], '', []);
    $social = new BlockNode('core/social-links', [], '', []);
    $node = new BlockNode('core/navigation', [], '', [$link('One'), $link('Two'), $social]);

    self::assertCount(2, NavigationMenus::links($node));
    self::assertSame([$social], NavigationMenus::trailing($node));
    $linksOnly = new BlockNode('core/navigation', [], '', [$link('One'), $link('Two')]);
    self::assertSame(NavigationMenus::menuId('fixture', $node), NavigationMenus::menuId('fixture', $linksOnly), 'The menu holds the links only.');
  }

  /**
   * Tests Social Links before a link render the mapped menu.
   */
  public function testSocialLinksBeforeLinksRenderMappedMenu(): void {
    $node = new BlockNode('core/navigation', [], '', [
      new BlockNode('core/social-links', [], '', []),
      new BlockNode('core/navigation-link', ['label' => 'One', 'url' => '/one'], '', []),
    ]);

    self::assertNull(NavigationMenus::links($node));
  }

  /**
   * Tests each trailing Social Icon reads the Social Icons' colors.
   */
  public function testTrailingSocialIconsCarryTheirParentColors(): void {
    $components = $this->container->get('plugin.manager.sdc');
    $inputs = new CommentBlockInputs(new AttributeFlattener(), $components, new ComponentInputs($components, new AttributeFlattener()));
    $social = new BlockNode('core/social-links', ['iconColor' => 'ti-fg', 'iconColorValue' => '#111111'], '', [
      new BlockNode('core/social-link', ['service' => 'facebook', 'url' => 'https://example.com'], '', []),
    ]);

    $props = $inputs->layout([$social])[0]['children'][0]['props'];

    self::assertSame('ti-fg', $props['icon_color']);
    self::assertSame('#111111', $props['icon_color_value']);
  }

  /**
   * Tests the trailing layout prints inside the link list.
   */
  public function testRendersTrailingSocialLinks(): void {
    $menus = NavigationMenus::collect(['header' => [$this->navigation()]], 'fixture', 'Fixture', TRUE)['menus'];
    (new NavigationMenus($this->container->get('entity_type.manager')))->write('fixture', $menus);
    $trailing = [
      [
        'marker' => NULL,
        'component' => 'wordpal:social-links',
        'props' => [],
        'children' => [
          [
            'marker' => NULL,
            'component' => 'wordpal:social-link',
            'props' => ['service' => 'mail', 'url' => 'mailto:a@example.com', 'label' => 'Mail'],
            'children' => [],
          ],
        ],
      ],
    ];
    $build = $this->container->get('plugin.manager.block')->createInstance('wordpal_navigation', [
      'menu' => array_key_first($menus),
      'overlay_menu' => 'never',
      'trailing' => $trailing,
    ])->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $dom = new \DOMXPath(Html::load($html));
    self::assertSame(1, $dom->query('//ul[contains(@class, "wp-block-navigation__container")]/li[contains(@class, "wp-block-navigation-item")]/ul[contains(@class, "wp-block-social-links")]')->length);
  }

  /**
   * Returns a Navigation block with a Home link and nested submenus.
   */
  private function navigation(): BlockNode {
    return new BlockNode('core/navigation', [], '', [
      new BlockNode('core/home-link', [], '', []),
      new BlockNode('core/navigation-submenu', ['label' => 'Walks', 'url' => '/walks'], '', [
        new BlockNode('core/navigation-link', ['label' => 'City', 'url' => '/walks/city'], '', []),
        new BlockNode('core/navigation-link', ['label' => 'Bad', 'url' => 'javascript:alert(1)'], '', []),
        new BlockNode('core/navigation-submenu', ['label' => 'More'], '', [
          new BlockNode('core/navigation-link', ['label' => 'Coast', 'url' => '/walks/coast'], '', []),
        ]),
      ]),
      new BlockNode('core/navigation-link', [
        'label' => 'About',
        'url' => '/about',
        'className' => 'buy',
        'opensInNewTab' => TRUE,
        'nofollow' => TRUE,
      ], '', []),
    ]);
  }

}
