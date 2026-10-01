<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\wordpal\Listing\ListCacheMetadata;
use Drupal\wordpal\Support\Cacheable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the current post author's mapped biography field.
 */
#[Block(
  id: 'wordpal_post_author_biography',
  admin_label: new TranslatableMarkup('WordPal post author biography'),
  context_definitions: [
    'node' => new EntityContextDefinition('entity:node', new TranslatableMarkup('Node'), required: FALSE),
  ],
)]
final class PostAuthorBiographyBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected RouteMatchInterface $routeMatch, protected AccountInterface $currentUser) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_route_match'), $container->get('current_user'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['field_name' => '', 'supports' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->getContextValue('node') ?? $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface) {
      return ['#cache' => ['contexts' => ['route']]];
    }
    $metadata = ListCacheMetadata::forNodeAccess()
      ->addCacheContexts(['route', 'user.roles'])
      ->addCacheableDependency($node);
    $nodeVisible = Cacheable::allowed($node->access('view', $this->currentUser, TRUE), $metadata);
    $author = $node->getOwner();
    $metadata->addCacheableDependency($author);
    $authorVisible = Cacheable::allowed($author->access('view', $this->currentUser, TRUE), $metadata);
    $fieldName = $this->configuration['field_name'];
    if (!$nodeVisible || !$authorVisible) {
      $build = [];
      $metadata->applyTo($build);
      return $build;
    }
    if ($fieldName === '') {
      $build = [];
      $metadata->applyTo($build);
      return $build;
    }
    if (!$author->hasField($fieldName)) {
      throw new \UnexpectedValueException("Author biography field $fieldName does not exist.");
    }
    $field = $author->get($fieldName);
    $build = [];
    if (Cacheable::allowed($field->access('view', $this->currentUser, TRUE), $metadata) && !$field->isEmpty()) {
      $item = $field->first();
      $biography = in_array($field->getFieldDefinition()->getType(), ['text', 'text_long', 'text_with_summary'], TRUE)
        ? [
          '#type' => 'processed_text',
          '#text' => $item->value,
          '#format' => $item->format,
        ]
        : ['#plain_text' => $item->value];
      $build = [
        '#theme' => 'wordpal_post_author_biography',
        '#biography' => $biography,
        '#settings' => $this->configuration,
      ];
    }
    $metadata->applyTo($build);
    return $build;
  }

}
