<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\wordpal\Support\Cacheable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the site title in WordPress markup.
 */
#[Block(id: 'wordpal_site_title', admin_label: new TranslatableMarkup('WordPal site title'))]
final class SiteTitleBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected PathMatcherInterface $pathMatcher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('path.matcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'level' => 1,
      'is_link' => TRUE,
      'link_target' => '_self',
      'supports' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $site = $this->configFactory->get('system.site');
    $metadata = CacheableMetadata::createFromObject($site);
    $front = Cacheable::url(Url::fromRoute('<front>'), $metadata);
    $build = [
      '#theme' => 'wordpal_site_title',
      '#title' => $site->get('name'),
      '#front_url' => $front,
      '#level' => $this->configuration['level'],
      '#is_link' => $this->configuration['is_link'],
      '#link_target' => $this->configuration['link_target'],
      '#current' => $this->pathMatcher->isFrontPage(),
      '#supports' => $this->configuration['supports'],
    ];
    $metadata
      ->addCacheContexts(['url.path.is_front'])
      ->applyTo($build);
    return $build;
  }

}
