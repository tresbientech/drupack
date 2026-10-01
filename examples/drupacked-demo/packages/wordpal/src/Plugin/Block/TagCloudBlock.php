<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wordpal\Listing\ListCacheMetadata;
use Drupal\wordpal\Listing\TermCounts;
use Drupal\wordpal\Support\Cacheable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the tag vocabulary in WordPress core/tag-cloud markup.
 */
#[Block(id: 'wordpal_tag_cloud', admin_label: new TranslatableMarkup('WordPal tag cloud'))]
final class TagCloudBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager, protected AccountInterface $currentUser, protected TermCounts $termCounts) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('current_user'), $container->get(TermCounts::class));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'vocabulary' => '',
      'number_of_tags' => 45,
      'show_tag_counts' => FALSE,
      'smallest_font_size' => '8pt',
      'largest_font_size' => '22pt',
      'supports' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Follows wp_generate_tag_cloud(): the most used tags, sorted by name, sized
   * linearly between the smallest and largest font size by use count.
   */
  public function build(): array {
    $vocabularyId = $this->configuration['vocabulary'];
    $metadata = ListCacheMetadata::forTerms($vocabularyId);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($vocabularyId, 0, NULL, TRUE);
    $counts = $this->termCounts->count(array_map(static fn($term): int => (int) $term->id(), $terms));
    $tags = [];
    foreach ($terms as $term) {
      $metadata->addCacheableDependency($term);
      $visible = Cacheable::allowed($term->access('view', $this->currentUser, TRUE), $metadata);
      $count = (int) ($counts[$term->id()] ?? 0);
      if ($visible && $count > 0) {
        $tags[] = ['term' => $term, 'count' => $count];
      }
    }
    usort($tags, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
    $tags = array_slice($tags, 0, $this->configuration['number_of_tags']);
    usort($tags, static fn (array $a, array $b): int => strnatcasecmp($a['term']->label(), $b['term']->label()));

    [$smallest, $unit] = $this->fontSize($this->configuration['smallest_font_size']);
    [$largest] = $this->fontSize($this->configuration['largest_font_size']);
    $counts = array_column($tags, 'count');
    $spread = $counts === [] ? 1 : max(1, max($counts) - min($counts));
    $step = max(1, $largest - $smallest) / $spread;
    $items = [];
    foreach ($tags as $position => $tag) {
      $items[] = [
        'id' => $tag['term']->id(),
        'position' => $position + 1,
        'name' => $tag['term']->label(),
        'url' => Cacheable::url($tag['term']->toUrl(), $metadata),
        'count' => $tag['count'],
        'size' => round($smallest + ($tag['count'] - min($counts)) * $step, 3) . $unit,
      ];
    }
    $build = [
      '#theme' => 'wordpal_tag_cloud',
      '#tags' => $items,
      '#settings' => $this->configuration,
    ];
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Splits a font size such as "8pt" into its number and unit.
   *
   * Config schema constrains the setting to a length, so both parts exist.
   */
  private function fontSize(string $size): array {
    preg_match('/^([\d.]+)(.+)$/', $size, $match);
    return [(float) $match[1], $match[2]];
  }

}
