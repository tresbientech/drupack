<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the site slogan in WordPress markup.
 */
#[Block(id: 'wordpal_site_tagline', admin_label: new TranslatableMarkup('WordPal site tagline'))]
final class SiteTaglineBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['level' => 0, 'supports' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $site = $this->configFactory->get('system.site');
    $build = [
      '#theme' => 'wordpal_site_tagline',
      '#tagline' => $site->get('slogan'),
      '#level' => $this->configuration['level'],
      '#supports' => $this->configuration['supports'],
    ];
    CacheableMetadata::createFromObject($site)->applyTo($build);
    return $build;
  }

}
