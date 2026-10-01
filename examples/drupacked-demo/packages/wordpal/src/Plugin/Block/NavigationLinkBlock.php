<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wordpal\Support\Cacheable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders one access-checked Drupal menu link in WordPress markup.
 */
#[Block(id: 'wordpal_navigation_link', admin_label: new TranslatableMarkup('WordPal navigation link'))]
final class NavigationLinkBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected MenuLinkManagerInterface $menuLinkManager, protected MenuLinkTreeInterface $menuTree) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('plugin.manager.menu.link'), $container->get('menu.link_tree'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['menu_link' => '', 'css_class' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $pluginId = $this->configuration['menu_link'];
    if ($pluginId === '') {
      throw new \UnexpectedValueException('Navigation link requires a menu-link plugin ID.');
    }
    $link = $this->menuLinkManager->createInstance($pluginId);
    $tree = [$pluginId => new MenuLinkTreeElement($link, FALSE, 1, FALSE, [])];
    $tree = $this->menuTree->transform($tree, [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
    ]);
    $element = $tree[$pluginId];
    $metadata = (new CacheableMetadata())
      ->setCacheTags(['config:system.menu.' . $link->getMenuName()])
      ->addCacheableDependency($link);
    if (!Cacheable::allowed($element->access, $metadata)) {
      $build = [];
      $metadata->applyTo($build);
      return $build;
    }
    $build = [
      '#theme' => 'wordpal_navigation_link',
      '#title' => $link->getTitle(),
      '#url' => Cacheable::url($link->getUrlObject(), $metadata),
      '#css_class' => $this->configuration['css_class'],
    ];
    $metadata->applyTo($build);
    return $build;
  }

}
