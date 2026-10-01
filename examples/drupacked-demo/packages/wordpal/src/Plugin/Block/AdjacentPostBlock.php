<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\wordpal\Listing\ListCacheMetadata;
use Drupal\wordpal\Support\Cacheable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders one adjacent post link in WordPress markup.
 */
#[Block(
  id: 'wordpal_adjacent_post',
  admin_label: new TranslatableMarkup('WordPal adjacent post'),
)]
final class AdjacentPostBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RouteMatchInterface $routeMatch,
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
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'direction' => 'next',
      'label' => '',
      'show_title' => FALSE,
      'link_label' => FALSE,
      'arrow' => 'none',
      'supports' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Direction'),
      '#options' => ['previous' => $this->t('Previous'), 'next' => $this->t('Next')],
      '#default_value' => $this->configuration['direction'],
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->configuration['label'],
    ];
    $form['show_title'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show post title'),
      '#default_value' => $this->configuration['show_title'],
    ];
    $form['link_label'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link label'),
      '#default_value' => $this->configuration['link_label'],
    ];
    $form['arrow'] = [
      '#type' => 'select',
      '#title' => $this->t('Arrow'),
      '#options' => [
        'none' => $this->t('None'),
        'arrow' => $this->t('Arrow'),
        'chevron' => $this->t('Chevron'),
      ],
      '#default_value' => $this->configuration['arrow'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    foreach (['direction', 'label', 'show_title', 'link_label', 'arrow'] as $key) {
      $this->configuration[$key] = $form_state->getValue($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->routeMatch->getParameter('node');
    // WordPress prints nothing outside a single post, such as on a listing.
    if (!$node instanceof NodeInterface) {
      return ['#cache' => ['contexts' => ['route']]];
    }
    $previous = $this->configuration['direction'] === 'previous';
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $node->bundle())
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('created', $node->getCreatedTime(), $previous ? '<' : '>')
      ->sort('created', $previous ? 'DESC' : 'ASC')
      ->range(0, 1)
      ->execute();
    $build = [];
    $cacheability = ListCacheMetadata::forNodes($node->bundle())
      ->addCacheContexts(['route'])
      ->addCacheableDependency($node);
    if ($ids === []) {
      $cacheability->applyTo($build);
      return $build;
    }
    // Entity queries key their results by revision ID.
    $neighbor = $this->entityTypeManager->getStorage('node')->load(reset($ids));
    $cacheability->addCacheableDependency($neighbor);
    $build += [
      '#theme' => 'wordpal_adjacent_post',
      '#direction' => $this->configuration['direction'],
      '#label' => $this->configuration['label'],
      '#show_title' => $this->configuration['show_title'],
      '#link_label' => $this->configuration['link_label'],
      '#arrow' => $this->configuration['arrow'],
      '#supports' => $this->configuration['supports'],
      '#title' => $neighbor->label(),
      '#url' => Cacheable::url($neighbor->toUrl(), $cacheability),
    ];
    $cacheability->applyTo($build);
    return $build;
  }

}
