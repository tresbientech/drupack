<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * One block of a parsed WordPress block tree.
 */
final class BlockNode {

  /**
   * Constructs a block node.
   *
   * @param string $name
   *   Block name, such as "core/paragraph".
   * @param array $attributes
   *   Attributes from the block comment. An empty JSON object stays a
   *   \stdClass, so re-encoding writes `{}` and not `[]`.
   * @param string $innerHtml
   *   Markup saved between the block delimiters, inner blocks removed.
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $children
   *   Inner blocks, in document order.
   * @param string|null $rendered
   *   The HTML WordPress rendered for the block and its inner blocks, from
   *   the block render index, or NULL for a block the index does not hold.
   * @param bool $isValid
   *   Whether WordPress's own parser found this block's saved markup still
   *   matching its `save()` output. TRUE for a node this codebase built
   *   itself rather than parsed, such as a Drupal-route frame wrapper.
   * @param string[] $cut
   *   Rendered HTML of left-out inner blocks, which $rendered still holds.
   *   A Frozen block prints $rendered without it (FrozenHoles).
   */
  public function __construct(
    public readonly string $name,
    public readonly array $attributes,
    public readonly string $innerHtml,
    public readonly array $children,
    public readonly ?string $rendered = NULL,
    public readonly bool $isValid = TRUE,
    public readonly array $cut = [],
  ) {}

  /**
   * Returns the local part of the block name, such as "paragraph".
   */
  public function slug(): string {
    return substr($this->name, (int) strpos($this->name, '/') + 1);
  }

}
