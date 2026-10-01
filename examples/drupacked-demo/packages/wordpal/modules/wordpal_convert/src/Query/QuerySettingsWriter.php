<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Query;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\views\ViewEntityInterface;
use Drupal\wordpal_convert\Component\ComponentInputs;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\QueryLoop;

/**
 * Builds and writes a Query View's `wordpal.query` third-party setting.
 *
 * Holds the WordPress Query block's own settings every target's View needs
 * to render its rows and pager as WordPress markup: the Post Template's own
 * inputs, the pagination region's and its children's, each listed bundle's
 * field mapping PostClasses reads, and the pagination child order. Canvas
 * adds two further keys itself, `query` and `layout`, which render only its
 * own `wordpal:query` embed; a target without them, such as Display
 * Builder's ListingWriter, leaves QuerySettings::fromView() to default them.
 */
final class QuerySettingsWriter {

  public function __construct(
    private readonly ComponentInputs $componentInputs,
    private readonly EntityFieldManagerInterface $fieldManager,
  ) {}

  /**
   * Returns the `wordpal.query` setting's target-independent keys.
   *
   * @param \Drupal\wordpal_convert\Theme\QueryLoop $loop
   *   The Query's dynamic pieces.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping a search View reads its index and parameter from.
   * @param string|null $route
   *   The listing route whose Template holds the Query, or NULL.
   * @param string[] $skipped
   *   Collects values no prop accepts, across every block the setting maps.
   */
  public function build(QueryLoop $loop, ContentMapping $mapping, ?string $route, array &$skipped): array {
    $settings = [
      'post_template' => $this->componentInputs->forNode($loop->postTemplate, $skipped),
      'pagination' => $loop->pagination === NULL ? [] : $this->componentInputs->forNode($loop->pagination, $skipped),
      'no_results' => $loop->noResults === NULL ? [] : $this->componentInputs->forNode($loop->noResults, $skipped),
      'bundles' => [],
    ];
    foreach (self::cardConcepts($mapping, $route) as $concept => $bundle) {
      $settings['bundles'][$bundle] = [
        'type' => $concept,
        'category_field' => $this->bundleField($mapping, 'category', $bundle),
        'tag_field' => $this->bundleField($mapping, 'tag', $bundle),
        'image_field' => $this->bundleField($mapping, 'featured_image', $bundle),
      ];
    }
    foreach ($loop->pagination?->children ?? [] as $child) {
      $key = match ($child->name) {
        'core/query-pagination-previous' => 'previous',
        'core/query-pagination-numbers' => 'numbers',
        'core/query-pagination-next' => 'next',
        default => NULL,
      };
      if ($key !== NULL) {
        $settings[$key] = $this->componentInputs->forNode($child, $skipped);
        foreach (match ($key) {
          'previous', 'next' => ['url', 'pagination_arrow'],
          'numbers' => ['pages', 'current', 'previous_ellipsis', 'next_ellipsis'],
        } as $runtimeKey) {
        unset($settings[$key][$runtimeKey]);
        }
        $settings['pagination_order'][] = $key;
      }
    }
    return $settings;
  }

  /**
   * Writes a Query's settings to its View's `wordpal.query` setting.
   */
  public function write(ViewEntityInterface $view, array $settings): void {
    $view->setThirdPartySetting('wordpal', 'query', $settings);
  }

  /**
   * Returns the bundles a Query's rows list, keyed by content concept.
   *
   * @return array<string, string>
   *   The post bundle, and the page bundle on the search route when the
   *   mapping binds it.
   */
  public static function cardConcepts(ContentMapping $mapping, ?string $route): array {
    $concepts = ['post' => $mapping->target('post')];
    if ($route === 'search' && $mapping->binds('page')) {
      $concepts['page'] = $mapping->target('page');
    }
    return $concepts;
  }

  /**
   * Returns the field a concept maps to on one bundle.
   *
   * @return string|null
   *   The mapped field name, or NULL when the mapping drops the concept or
   *   the bundle lacks the field. The validator checks the fields on the
   *   post bundle only, so the page bundle can lack them.
   */
  public function bundleField(ContentMapping $mapping, string $concept, string $bundle): ?string {
    if (!$mapping->binds($concept)) {
      return NULL;
    }
    $target = $mapping->target($concept);
    $field = is_array($target) ? $target['field'] : $target;
    return isset($this->fieldManager->getFieldDefinitions('node', $bundle)[$field]) ? $field : NULL;
  }

}
