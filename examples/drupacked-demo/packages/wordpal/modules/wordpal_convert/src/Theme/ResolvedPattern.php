<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * One Inserter pattern's resolved tree, or the fault that stopped it.
 */
final readonly class ResolvedPattern {

  private function __construct(
    public array $metadata,
    public ?array $nodes,
    public ?array $loops,
    public ?string $error,
    public array $dropped = [],
  ) {}

  /**
   * Returns a Pattern whose tree resolved.
   *
   * @param array $metadata
   *   The Pattern's snapshot record.
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The Pattern's tree, references expanded.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop[] $loops
   *   The tree's Query blocks.
   * @param string[] $dropped
   *   One report line per Query left out of the tree.
   */
  public static function resolved(array $metadata, array $nodes, array $loops, array $dropped): self {
    return new self($metadata, $nodes, $loops, NULL, $dropped);
  }

  /**
   * Returns a Pattern whose tree failed to resolve.
   */
  public static function failed(array $metadata, string $error): self {
    return new self($metadata, NULL, NULL, $error);
  }

  /**
   * Returns whether the Pattern's tree failed to resolve.
   */
  public function isFailed(): bool {
    return $this->error !== NULL;
  }

}
