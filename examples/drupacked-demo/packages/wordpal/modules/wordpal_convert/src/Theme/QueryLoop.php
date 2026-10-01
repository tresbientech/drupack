<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * The dynamic pieces held by one WordPress Query block.
 */
final readonly class QueryLoop {

  public function __construct(
    public BlockNode $query,
    public BlockNode $postTemplate,
    public ?BlockNode $pagination,
    public ?BlockNode $noResults,
    public int $perPage,
    public int $offset,
    public string $order,
    public bool $excludeSticky,
    public bool $inherit,
    public int $pagerElement,
    public int $pages,
    public string $orderBy,
  ) {}

  /**
   * The WordPress orderBy values a View sorts by.
   *
   * Each names its node field and the Views sort handler that plugin id
   * sorts it with.
   */
  public const ORDER_FIELDS = [
    'date' => ['field' => 'created', 'plugin' => 'date'],
    'title' => ['field' => 'title', 'plugin' => 'standard'],
  ];

  /**
   * Returns the listing route whose main query this Query shows, or NULL.
   *
   * Only an inheriting Query shows the route's main query, such as the
   * search results or a term's posts. Any other Query lists its own posts.
   */
  public function route(?string $templateRoute): ?string {
    return $this->inherit ? $templateRoute : NULL;
  }

  /**
   * Returns an owner tree's Query boundaries, without the unconvertible ones.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The owner tree.
   * @param int $mainPerPage
   *   The posts per page of WordPress's main query.
   * @param int $firstPagerElement
   *   The pager element of the first Query that pages on its own.
   * @param string|null $leaveOutReason
   *   Why to leave every Query out, or NULL to keep the convertible ones.
   *
   * @return array{nodes: \Drupal\wordpal_convert\Theme\BlockNode[], loops: self[], dropped: string[]}
   *   The tree without the Queries no View can reproduce, the other Query
   *   boundaries in source order, and one report line per Query left out.
   */
  public static function allFromTree(array $nodes, int $mainPerPage, int $firstPagerElement = 1, ?string $leaveOutReason = NULL): array {
    $queries = [];
    self::collect($nodes, $queries);
    // WordPress pages an inheriting Query with the main query and any other
    // Query with its own query-<id>-page parameter. Drupal pages each View by
    // its pager element: 0 for the main query, then one per other Query from
    // $firstPagerElement.
    $next = $firstPagerElement;
    $loops = [];
    $dropped = [];
    foreach ($queries as $position => $query) {
      $reason = $leaveOutReason;
      if ($reason === NULL) {
        try {
          $loop = self::fromNode($query, $mainPerPage, $next);
        }
        catch (\UnexpectedValueException $exception) {
          $reason = $exception->getMessage();
        }
      }
      if ($reason !== NULL) {
        $dropped[] = 'Query ' . ($position + 1) . " left out: $reason";
        $nodes = self::without($nodes, $query);
        continue;
      }
      $next += $loop->inherit ? 0 : 1;
      $loops[] = $loop;
    }
    return ['nodes' => $nodes, 'loops' => $loops, 'dropped' => $dropped];
  }

  /**
   * Validates one Query boundary and extracts its runtime pieces.
   */
  private static function fromNode(BlockNode $query, int $mainPerPage, int $nextPagerElement): self {
    $postTemplates = self::descendants($query, 'core/post-template');
    if (count($postTemplates) !== 1) {
      throw new \UnexpectedValueException('A Query must contain one Post Template block.');
    }
    // Each Pagination block renders the View's one pager; the first one's
    // settings configure it.
    $pagination = self::descendants($query, 'core/query-pagination');
    $noResults = self::descendants($query, 'core/query-no-results');
    if (count($noResults) > 1) {
      throw new \UnexpectedValueException('A Query contains more than one No results block.');
    }
    $settings = $query->attributes['query'] ?? [];
    // An inheriting Query shows the main query's posts, and WordPress
    // ignores the Query's own paging, filters and order. Any Query with no
    // numeric perPage falls back to the main query's count too, the one
    // WordPress takes from its posts_per_page option.
    $inherit = ($settings['inherit'] ?? FALSE) === TRUE;
    if ($inherit) {
      $settings = [];
    }
    // A taxonomy with no terms filters nothing.
    $settings['taxQuery'] = array_filter($settings['taxQuery'] ?? []);
    $perPage = $inherit || !is_numeric($settings['perPage'] ?? NULL) ? $mainPerPage : (int) $settings['perPage'];
    $offset = (int) ($settings['offset'] ?? 0);
    $order = strtoupper((string) ($settings['order'] ?? 'DESC'));
    $sticky = (string) ($settings['sticky'] ?? '');
    $orderBy = (string) ($settings['orderBy'] ?? 'date');
    $postType = (string) ($settings['postType'] ?? 'post');
    $unsupported = [];
    if ($postType !== 'post') {
      $unsupported[] = "postType $postType";
    }
    foreach (['author', 'search', 'exclude', 'taxQuery', 'parents', 'format'] as $name) {
      if (($settings[$name] ?? '') !== '' && ($settings[$name] ?? []) !== []) {
        $unsupported[] = $name;
      }
    }
    if (!in_array($sticky, ['', 'exclude', 'ignore'], TRUE)) {
      $unsupported[] = "sticky $sticky";
    }
    if (!isset(self::ORDER_FIELDS[$orderBy])) {
      $unsupported[] = "orderBy $orderBy";
    }
    if (!in_array($order, ['ASC', 'DESC'], TRUE)) {
      $unsupported[] = "order $order";
    }
    if ($perPage < 1) {
      $unsupported[] = "perPage $perPage";
    }
    if ($offset < 0) {
      $unsupported[] = "offset $offset";
    }
    if ($unsupported !== []) {
      throw new \UnexpectedValueException('A Query uses unsupported settings: ' . implode(', ', $unsupported) . '.');
    }
    // WordPress passes the Query's displayLayout to its Post Template as
    // block context; the Post Template component reads it as its own prop.
    $postTemplate = $postTemplates[0];
    if (isset($query->attributes['displayLayout'])) {
      $postTemplate = new BlockNode($postTemplate->name, $postTemplate->attributes + ['displayLayout' => $query->attributes['displayLayout']], $postTemplate->innerHtml, $postTemplate->children, $postTemplate->rendered, $postTemplate->isValid, $postTemplate->cut);
    }
    return new self(
      $query,
      $postTemplate,
      $pagination[0] ?? NULL,
      $noResults[0] ?? NULL,
      $perPage,
      $offset,
      $order,
      $sticky === 'exclude',
      $inherit,
      $inherit ? 0 : $nextPagerElement,
      (int) ($settings['pages'] ?? 0),
      $orderBy,
    );
  }

  /**
   * Returns a tree without one block, its ancestors rebuilt around the gap.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   * @param \Drupal\wordpal_convert\Theme\BlockNode $removed
   *   The block to leave out.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The tree; subtrees that never held the block stay the same objects.
   */
  private static function without(array $nodes, BlockNode $removed): array {
    $kept = [];
    foreach ($nodes as $node) {
      if ($node === $removed) {
        continue;
      }
      $children = self::without($node->children, $removed);
      if ($children === $node->children) {
        $kept[] = $node;
        continue;
      }
      $cut = $removed->rendered === NULL ? $node->cut : [...$node->cut, $removed->rendered];
      $kept[] = new BlockNode($node->name, $node->attributes, $node->innerHtml, $children, $node->rendered, $node->isValid, $cut);
    }
    return $kept;
  }

  /**
   * Collects Query blocks recursively.
   */
  private static function collect(array $nodes, array &$queries): void {
    foreach ($nodes as $node) {
      if ($node->name === 'core/query') {
        $queries[] = $node;
        continue;
      }
      self::collect($node->children, $queries);
    }
  }

  /**
   * Returns descendants with one WordPress block name.
   */
  public static function descendants(BlockNode $node, string $name): array {
    $matches = [];
    foreach ($node->children as $child) {
      if ($child->name === $name) {
        $matches[] = $child;
      }
      $matches = [...$matches, ...self::descendants($child, $name)];
    }
    return $matches;
  }

}
