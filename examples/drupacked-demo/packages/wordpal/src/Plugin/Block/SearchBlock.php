<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\wordpal\Support\Cacheable;
use Drupal\wordpal\Support\PresetValue;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders a mapped Drupal search endpoint as a WordPress search form.
 */
#[Block(id: 'wordpal_search', admin_label: new TranslatableMarkup('WordPal search'))]
final class SearchBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected RequestStack $requestStack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('request_stack'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'path' => '',
      'parameter' => '',
      'label' => 'Search',
      'show_label' => TRUE,
      'placeholder' => '',
      'button_text' => 'Search',
      'button_position' => 'button-outside',
      'button_use_icon' => FALSE,
      'width' => '',
      'supports' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if ($this->configuration['path'] === '') {
      return [];
    }
    $metadata = new CacheableMetadata();
    $action = Cacheable::url(Url::fromUserInput($this->configuration['path']), $metadata);
    $parameter = $this->configuration['parameter'];
    // Request parameters are external input. Block build() only ever runs
    // while handling a request, so a request is always on the stack here.
    $queryValue = $this->requestStack->getCurrentRequest()->query->all()[$parameter] ?? '';
    $width = $this->configuration['width'] === '' ? '' : PresetValue::css($this->configuration['width']);
    $build = [
      '#theme' => 'wordpal_search',
      // Html::getUniqueId() cleans `__` to `-`, and WordPress's input id
      // keeps the block's element name.
      '#input_id' => str_replace('wp-block-search-input', 'wp-block-search__input', Html::getUniqueId('wp-block-search__input')),
      '#action' => $action,
      '#parameter' => $parameter,
      '#value' => is_scalar($queryValue) ? (string) $queryValue : '',
      '#width' => $width,
      '#settings' => $this->configuration,
      '#cache' => ['contexts' => ['url.query_args:' . $parameter]],
    ];
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($metadata)
      ->applyTo($build);
    return $build;
  }

}
