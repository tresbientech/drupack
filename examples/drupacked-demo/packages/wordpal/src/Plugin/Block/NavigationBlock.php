<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wordpal\Layout\LayoutRenderer;
use Drupal\wordpal\Support\PresetValue;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a mapped Drupal menu in WordPress navigation markup.
 */
#[Block(id: 'wordpal_navigation', admin_label: new TranslatableMarkup('WordPal navigation'))]
final class NavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The link option marking a menu link converted from a Home Link block.
   */
  public const HOME_LINK_OPTION = 'wordpal_home_link';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected MenuLinkTreeInterface $menuTree, protected EntityTypeManagerInterface $entityTypeManager, protected MenuActiveTrailInterface $activeTrail) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('menu.link_tree'), $container->get('entity_type.manager'), $container->get('menu.active_trail'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'menu' => '',
      'overlay_menu' => 'mobile',
      'icon' => 'handle',
      'has_icon' => TRUE,
      'show_submenu_icon' => TRUE,
      'submenu_visibility' => 'hover',
      'page_list' => FALSE,
      'menu_label' => TRUE,
      'trailing' => [],
      'text_color' => '',
      'custom_text_color' => '',
      'background_color' => '',
      'custom_background_color' => '',
      'overlay_background_color' => '',
      'custom_overlay_background_color' => '',
      'overlay_text_color' => '',
      'custom_overlay_text_color' => '',
      'supports' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $menuId = $this->configuration['menu'];
    $menu = $this->entityTypeManager->getStorage('menu')->load($menuId);
    if ($menu === NULL) {
      throw new \UnexpectedValueException("Navigation menu $menuId does not exist.");
    }
    // WordPress prints every submenu, so the whole tree loads.
    $parameters = (new MenuTreeParameters())->setActiveTrail($this->activeTrail->getActiveTrailIds($menuId));
    $tree = $this->menuTree->load($menuId, $parameters);
    $tree = $this->menuTree->transform($tree, [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);
    $tree = $this->menuTree->build($tree);
    $metadata = CacheableMetadata::createFromRenderArray($tree)->addCacheContexts(['route.menu_active_trails:' . $menuId]);
    $items = $tree['#items'] ?? [];
    $ariaLabel = $this->configuration['supports']['aria_label'] ?? '';
    $build = [
      '#theme' => 'wordpal_navigation',
      '#items' => $items,
      '#active_link' => self::currentLink($this->activeTrail->getActiveLink($menuId)),
      '#modal_id' => Html::getUniqueId('modal-' . $menuId),
      '#overlay_menu' => $this->configuration['overlay_menu'],
      '#icon' => $this->configuration['icon'],
      '#has_icon' => $this->configuration['has_icon'],
      '#show_submenu_icon' => $this->configuration['show_submenu_icon'],
      '#submenu_visibility' => $this->configuration['submenu_visibility'],
      '#page_list' => $this->configuration['page_list'],
      '#trailing' => LayoutRenderer::build($this->configuration['trailing']),
      '#aria_label' => $ariaLabel ?: ($this->configuration['menu_label'] ? $menu->label() : ''),
      // WordPress labels the link list with the block's ariaLabel only.
      '#list_aria_label' => $ariaLabel,
      // navigation.php prints a preset color and falls back to the custom one.
      '#text_color' => $this->configuration['text_color'] ?: $this->configuration['custom_text_color'],
      '#background_color' => $this->configuration['background_color'] ?: $this->configuration['custom_background_color'],
      '#supports' => $this->configuration['supports'],
      '#overlay_background_color' => $this->overlaySlug('overlay_background_color'),
      '#custom_overlay_background_color' => $this->overlayCss('custom_overlay_background_color'),
      '#overlay_text_color' => $this->overlaySlug('overlay_text_color'),
      '#custom_overlay_text_color' => $this->overlayCss('custom_overlay_text_color'),
    ];
    $metadata->addCacheableDependency($menu)->applyTo($build);
    return $build;
  }

  /**
   * Returns the active link's plugin id when WordPress marks it current.
   *
   * WordPress marks a Navigation Link current only through the post or term
   * its id names, and a Home Link on the front page.
   */
  private static function currentLink(?MenuLinkInterface $link): ?string {
    if ($link === NULL) {
      return NULL;
    }
    $home = $link->getOptions()['wordpal_home_link'] ?? FALSE;
    return $home || in_array($link->getRouteName(), ['entity.node.canonical', 'entity.taxonomy_term.canonical'], TRUE) ? $link->getPluginId() : NULL;
  }

  /**
   * Returns an overlay color slug, which Twig prints into a class name.
   *
   * The value comes from the WordPress snapshot or a page editor.
   */
  private function overlaySlug(string $key): string {
    $slug = $this->configuration[$key];
    if ($slug !== '' && !PresetValue::isSlug($slug)) {
      throw new \UnexpectedValueException("Not a WordPress preset slug: \"$slug\".");
    }
    return $slug;
  }

  /**
   * Returns a custom overlay color, which Twig prints into a style attribute.
   *
   * The value comes from the WordPress snapshot or a page editor.
   */
  private function overlayCss(string $key): string {
    $value = $this->configuration[$key];
    return $value === '' ? '' : PresetValue::css($value);
  }

}
