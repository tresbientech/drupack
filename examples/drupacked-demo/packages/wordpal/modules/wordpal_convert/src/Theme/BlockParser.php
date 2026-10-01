<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

use Drupal\wordpal_convert\Support\PlatformCredit;
use Drupal\wordpal_convert\WordPress\WpBlockParser;

/**
 * Parses WordPress block markup into a tree of block nodes.
 */
final class BlockParser {

  /**
   * WordPress's fallback names for a block its own parser cannot resolve.
   *
   * `getFreeformContentHandlerName()` names unregistered classic content,
   * and `getUnregisteredTypeHandlerName()` names any other unregistered
   * block name. Neither carries the real block's attributes.
   */
  private const UNRESOLVED_NAMES = ['core/freeform', 'core/missing'];

  public function __construct(
    private readonly AttributeSourceInterface $attributeSource,
  ) {}

  /**
   * Parses a template, template part or pattern.
   *
   * @param string $html
   *   Block markup, with any pattern PHP already executed.
   * @param array<string, string> $renders
   *   The block render index entries of this markup: rendered HTML keyed by
   *   block path. A path joins the block's position in each parsed list with
   *   dots, top level first, counting the whitespace between top-level
   *   blocks, as WordPress's parse_blocks() does.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The top level blocks.
   */
  public function parse(string $html, array $renders = []): array {
    // A Frozen block prints its rendered HTML.
    $renders = array_map(PlatformCredit::rewriteHtml(...), $renders);
    return $this->toNodes((new WpBlockParser())->parse($html), $this->attributeSource->forest($html), $renders, '');
  }

  /**
   * Converts the WordPress parser output to block nodes.
   *
   * @param array $blocks
   *   This level's blocks, as WpBlockParser returns them.
   * @param array[] $forest
   *   WordPress's own parsed forest for this level: one entry per block
   *   $blocks holds, a purely whitespace freeform entry aside, in the same
   *   order.
   * @param array<string, string> $renders
   *   The block render index, keyed by path.
   * @param string $prefix
   *   The path prefix of this level.
   */
  private function toNodes(array $blocks, array $forest, array $renders, string $prefix): array {
    $nodes = [];
    $position = 0;
    foreach ($blocks as $block) {
      $path = $prefix . $position;
      $position++;
      $name = $block['blockName'];
      if ($name === NULL) {
        // Markup outside any block. Theme files hold whitespace there.
        if (trim($block['innerHTML']) === '') {
          continue;
        }
        $name = 'core/freeform';
      }
      $parsed = array_shift($forest);
      if ($parsed !== NULL && $parsed['name'] !== $name && !in_array($parsed['name'], self::UNRESOLVED_NAMES, TRUE)) {
        // WordPress's parser renamed the block, as core/post-comments became
        // core/comments. The renamed block is the current save.
        $nodes[] = $this->fromForest($parsed, $renders[$path] ?? NULL);
        continue;
      }
      [$attributes, $isValid, $children] = $this->resolve($name, (array) $block['attrs'], $parsed);
      $nodes[] = new BlockNode(
        $name,
        $attributes,
        $block['innerHTML'],
        $this->toNodes($block['innerBlocks'], $children, $renders, "$path."),
        $renders[$path] ?? NULL,
        $isValid,
      );
    }
    // Blocks WordPress's parser added after the grammar's own: a
    // deprecation's migrate() builds them, and they are the current save.
    foreach ($forest as $migrated) {
      $nodes[] = $this->fromForest($migrated);
    }
    return $nodes;
  }

  /**
   * Builds a node from an entry of WordPress's own parsed forest.
   *
   * @param array $parsed
   *   The forest entry: name, attributes, isValid and innerBlocks.
   * @param string|null $rendered
   *   The block render index entry at the entry's path, if any.
   */
  private function fromForest(array $parsed, ?string $rendered = NULL): BlockNode {
    return new BlockNode(
      $parsed['name'],
      $parsed['attributes'],
      '',
      array_map($this->fromForest(...), $parsed['innerBlocks']),
      $rendered,
      $parsed['isValid'],
    );
  }

  /**
   * Returns one block's attributes, validity and inner forest.
   *
   * @param string $name
   *   The block name the grammar parser found.
   * @param array $rawAttributes
   *   The attributes from the block comment.
   * @param array|null $parsed
   *   The matching entry of WordPress's own parsed forest with the same name
   *   or a fallback name, or NULL when the forest holds no more entries at
   *   this level.
   *
   * @return array{0: array, 1: bool, 2: array[]}
   *   The attributes to keep, whether WordPress's parser found the block
   *   valid, and its own parsed inner forest.
   */
  private function resolve(string $name, array $rawAttributes, ?array $parsed): array {
    if ($parsed === NULL) {
      return [$rawAttributes, TRUE, []];
    }
    if ($parsed['name'] !== $name) {
      // No registered block type matches this name, in the pinned release:
      // WordPress's own parser carries no real attributes for it either.
      return [$rawAttributes, TRUE, []];
    }
    return [$parsed['attributes'], $parsed['isValid'], $parsed['innerBlocks']];
  }

}
