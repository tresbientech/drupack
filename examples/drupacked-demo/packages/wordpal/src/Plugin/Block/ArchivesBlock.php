<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Views;
use Drupal\wordpal\Listing\ListCacheMetadata;
use Drupal\wordpal\Support\Cacheable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders core's monthly archive in WordPress core/archives markup.
 */
#[Block(id: 'wordpal_archives', admin_label: new TranslatableMarkup('WordPal archives'))]
final class ArchivesBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Core's monthly archive View, which the WordPal recipe enables.
   */
  private const VIEW = 'archive';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected DateFormatterInterface $dateFormatter) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('date.formatter'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'bundle' => '',
      'show_post_counts' => FALSE,
      'display_as_dropdown' => FALSE,
      'supports' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $view = Views::getView(self::VIEW);
    if ($view === NULL || !$view->storage->status()) {
      throw new \RuntimeException('The archives block requires core\'s enabled archive View.');
    }
    $view->setDisplay('block_1');
    // WordPress lists the newest month first; the View's block lists the
    // oldest first.
    $arguments = $view->display_handler->getOption('arguments');
    $arguments['created_year_month']['summary']['sort_order'] = 'desc';
    $view->display_handler->overrideOption('arguments', $arguments);
    $view->addHandler('block_1', 'filter', 'node_field_data', 'type', ['value' => [$this->configuration['bundle'] => $this->configuration['bundle']]]);
    $metadata = ListCacheMetadata::forNodes($this->configuration['bundle'])
      ->addCacheableDependency($view->storage)
      ->addCacheContexts(['timezone']);
    $months = [];
    if ($view->access('block_1')) {
      $view->execute();
      foreach ($view->result as $row) {
        $months[] = [
          'label' => $this->dateFormatter->format(mktime(0, 0, 0, (int) substr($row->created_year_month, 4, 2), 1, (int) substr($row->created_year_month, 0, 4)), 'custom', 'F Y'),
          'url' => Cacheable::url($view->getUrl([$row->created_year_month], 'page_1'), $metadata),
          'count' => (int) $row->num_records,
        ];
      }
    }
    $build = [
      '#theme' => 'wordpal_archives',
      '#months' => $months,
      // WordPress numbers the dropdown's label and select with
      // wp_unique_id() per request; the exact number is never compared.
      '#dropdown_id' => Html::getUniqueId('wp-block-archives'),
      '#settings' => $this->configuration,
    ];
    $metadata->applyTo($build);
    return $build;
  }

}
