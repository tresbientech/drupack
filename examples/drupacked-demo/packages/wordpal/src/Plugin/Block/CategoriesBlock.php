<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wordpal\Listing\ListCacheMetadata;
use Drupal\wordpal\Listing\TermCounts;
use Drupal\wordpal\Support\Cacheable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the accessible terms in a mapped category vocabulary.
 */
#[Block(id: 'wordpal_categories', admin_label: new TranslatableMarkup('WordPal categories'))]
final class CategoriesBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager, protected AccountInterface $currentUser, protected TermCounts $termCounts, protected ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('current_user'), $container->get(TermCounts::class), $container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'vocabulary' => '',
      'taxonomy' => 'category',
      'show_count' => FALSE,
      'show_hierarchy' => FALSE,
      'show_empty' => FALSE,
      'display_as_dropdown' => FALSE,
      'supports' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $vocabularyId = $this->configuration['vocabulary'];
    if ($vocabularyId === '') {
      return ['#cache' => ['tags' => ['taxonomy_term_list']]];
    }
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vocabularyId);
    if ($vocabulary === NULL) {
      throw new \UnexpectedValueException("Categories vocabulary $vocabularyId does not exist.");
    }
    if (!$this->configFactory->get('taxonomy.settings')->get('maintain_index_table')) {
      throw new \RuntimeException('Category counts require taxonomy.settings.maintain_index_table.');
    }
    $metadata = ListCacheMetadata::forTerms($vocabularyId)
      ->addCacheContexts(['user.roles'])
      ->addCacheableDependency($vocabulary);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($vocabularyId, 0, NULL, TRUE);
    $counts = $this->termCounts->count(array_map(static fn($term): int => (int) $term->id(), $terms));
    $items = [];
    foreach ($terms as $term) {
      $url = Cacheable::link($term, $metadata, 'view', $this->currentUser);
      if ($url === NULL || (!$this->configuration['show_empty'] && ($counts[$term->id()] ?? 0) === 0)) {
        continue;
      }
      $items[] = [
        'id' => (string) $term->id(),
        'parent' => $this->configuration['show_hierarchy'] ? (string) ($term->parents[0] ?? 0) : '0',
        'name' => $term->label(),
        'url' => $url,
        'count' => $counts[$term->id()] ?? 0,
      ];
    }
    $build = [
      '#theme' => 'wordpal_categories',
      '#items' => $this->tree($this->reparent($items)),
      '#show_count' => $this->configuration['show_count'],
      '#taxonomy' => $this->configuration['taxonomy'],
      '#display_as_dropdown' => $this->configuration['display_as_dropdown'],
      // WordPress numbers the dropdown's label and select with
      // wp_unique_id() per request; the exact number is never compared.
      '#dropdown_id' => Html::getUniqueId('wp-block-categories'),
      '#supports' => $this->configuration['supports'],
    ];
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Nests accessible terms under their accessible parents.
   */
  private function tree(array $items): array {
    $children = [];
    foreach ($items as $item) {
      $children[$item['parent']][] = $item;
    }
    return $this->branch($children, '0');
  }

  /**
   * Returns the items under $parent, each with its own children nested.
   *
   * @param array<string, array[]> $children
   *   Items keyed by parent id, in vocabulary order.
   * @param string $parent
   *   The parent term id, or '0' for the top level.
   */
  private function branch(array $children, string $parent): array {
    $branch = [];
    foreach ($children[$parent] ?? [] as $item) {
      $item['children'] = $this->branch($children, $item['id']);
      $branch[] = $item;
    }
    return $branch;
  }

  /**
   * Promotes visible children when their parent was access-filtered.
   */
  private function reparent(array $items): array {
    $visible = array_fill_keys(array_column($items, 'id'), TRUE);
    foreach ($items as &$item) {
      if ($item['parent'] !== '0' && !isset($visible[$item['parent']])) {
        $item['parent'] = '0';
      }
    }
    return $items;
  }

}
