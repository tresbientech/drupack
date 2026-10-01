<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder_runtime\Plugin\Condition;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\Condition\ConditionManager;
use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Matches the routes of one converted page frame: its paths or node bundles.
 *
 * A page layout ANDs its conditions, so core's request_path and
 * entity_bundle conditions cannot express a frame serving both a path and
 * a node bundle.
 */
#[Condition(
  id: 'wordpal_frame_routes',
  label: new TranslatableMarkup('WordPal frame routes'),
  context_definitions: [
    'node' => new EntityContextDefinition('entity:node', label: new TranslatableMarkup('Node'), required: FALSE),
  ],
)]
final class FrameRoutes extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly ConditionManager $conditionManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('plugin.manager.condition'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['pages' => '', 'bundles' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(): bool {
    if ($this->configuration['pages'] !== '' && $this->conditionManager->createInstance('request_path', ['pages' => $this->configuration['pages']])->evaluate()) {
      return TRUE;
    }
    $node = $this->getContextValue('node');
    return $node !== NULL && in_array($node->bundle(), $this->configuration['bundles'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    return new TranslatableMarkup('The routes of a converted WordPal page frame');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['url.path']);
  }

}
