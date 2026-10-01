<?php

declare(strict_types=1);

namespace Drupal\wordpal\Listing;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;

/**
 * Counts the published content the current user can view per term.
 */
final class TermCounts {

  public function __construct(
    private readonly Connection $database,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Counts published content assigned to each term.
   *
   * The taxonomy_index table only ever indexes published nodes, so the
   * remaining access boundary is per-node view grants, checked here the
   * same way $node->access('view', ...) checks it: the base 'access
   * content' permission, then the node_access query tag for any per-node
   * grants.
   *
   * @param int[] $termIds
   *   The terms to count.
   *
   * @return array<int|string, int|string>
   *   Counts keyed by term ID, leaving out terms with no content.
   */
  public function count(array $termIds): array {
    if ($termIds === [] || !$this->currentUser->hasPermission('access content')) {
      return [];
    }
    $query = $this->database->select('taxonomy_index', 'ti')
      ->addTag('node_access')
      ->addMetaData('op', 'view')
      ->addMetaData('account', $this->currentUser)
      ->addMetaData('base_table', 'taxonomy_index');
    $query->condition('ti.tid', $termIds, 'IN')->groupBy('ti.tid');
    $query->fields('ti', ['tid']);
    $query->addExpression('COUNT(*)', 'count');
    return $query->execute()->fetchAllKeyed();
  }

}
