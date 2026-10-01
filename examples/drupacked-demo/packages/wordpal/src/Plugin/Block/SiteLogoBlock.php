<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\wordpal\Support\Cacheable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the active theme logo in WordPress markup.
 */
#[Block(id: 'wordpal_site_logo', admin_label: new TranslatableMarkup('WordPal site logo'))]
final class SiteLogoBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected ThemeSettingsProvider $themeSettings, protected ConfigFactoryInterface $configFactory, protected ThemeManagerInterface $themeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get(ThemeSettingsProvider::class), $container->get('config.factory'), $container->get('theme.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['width' => 0, 'is_link' => TRUE, 'link_target' => '_self', 'supports' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if ($this->themeSettings->getSetting('logo.use_default')) {
      return [];
    }
    $url = $this->themeSettings->getSetting('logo.url');
    if (!is_string($url) || $url === '') {
      return [];
    }
    $theme = $this->themeManager->getActiveTheme()->getName();
    $site = $this->configFactory->get('system.site');
    $metadata = CacheableMetadata::createFromObject($site);
    $front = Cacheable::url(Url::fromRoute('<front>'), $metadata);
    $build = [
      '#theme' => 'wordpal_site_logo',
      '#logo_url' => $url,
      '#alt' => $site->get('name'),
      '#front_url' => $front,
      '#width' => $this->configuration['width'],
      '#is_link' => $this->configuration['is_link'],
      '#link_target' => $this->configuration['link_target'],
      '#supports' => $this->configuration['supports'],
    ];
    $metadata
      ->addCacheableDependency($this->configFactory->get('system.theme.global'))
      ->addCacheableDependency($this->configFactory->get('core.extension'))
      ->addCacheableDependency($this->configFactory->get("$theme.settings"))
      ->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['theme']);
  }

}
