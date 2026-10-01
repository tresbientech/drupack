<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

use Drupal\wordpal_convert\WordPress\Snapshot;

/**
 * Expands pattern and template-part references in a WordPress block tree.
 *
 * A template's main area, the parts around it, and a pattern's own blocks
 * can each hold `core/pattern` and `core/template-part` references. One walk
 * resolves both kinds wherever they appear, so a template and a pattern see
 * the same handling of missing and cyclic references.
 */
final class ReferenceExpander {

  /**
   * The block that references a pattern.
   */
  public const PATTERN = 'core/pattern';

  /**
   * The block that references a template part.
   */
  public const TEMPLATE_PART = 'core/template-part';

  public function __construct(
    private readonly BlockParser $parser,
  ) {}

  /**
   * Expands references at and below one level of a block tree.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at one level of the tree.
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param string[] $patterns
   *   Pattern slugs already open, which stops a pattern including itself.
   * @param string[] $parts
   *   Template part slugs already open, which stops a part including itself.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The blocks, references expanded.
   */
  public function expand(array $nodes, Snapshot $snapshot, array $patterns = [], array $parts = []): array {
    $expanded = [];
    foreach ($nodes as $node) {
      if ($node->name === self::PATTERN) {
        $expanded = [...$expanded, ...$this->expandPattern($node, $snapshot, $patterns, $parts)];
        continue;
      }
      if ($node->name === self::TEMPLATE_PART) {
        $expanded = [...$expanded, ...$this->expandTemplatePart($node, $snapshot, $patterns, $parts)];
        continue;
      }
      $expanded[] = new BlockNode(
        $node->name,
        $node->attributes,
        $node->innerHtml,
        $this->expand($node->children, $snapshot, $patterns, $parts),
        $node->rendered,
        $node->isValid,
      );
    }
    return $expanded;
  }

  /**
   * Replaces the pattern references at one level with their patterns' blocks.
   *
   * Nested blocks and template-part references stay unexpanded. A template
   * whose file holds only a pattern reference, such as YITH Wonder's home,
   * gets its page frame from that pattern.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at one level of the tree.
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param string[] $patterns
   *   Pattern slugs already open, which stops a pattern including itself.
   *
   * @return array<int, array{node: \Drupal\wordpal_convert\Theme\BlockNode, patterns: string[]}>
   *   The blocks, top-level pattern references inlined, each with the
   *   pattern slugs open around it for expand().
   */
  public function inlinePatterns(array $nodes, Snapshot $snapshot, array $patterns = []): array {
    $inlined = [];
    foreach ($nodes as $node) {
      if ($node->name !== self::PATTERN) {
        $inlined[] = ['node' => $node, 'patterns' => $patterns];
        continue;
      }
      $slug = $this->patternSlug($node, $snapshot, $patterns);
      if ($slug === NULL) {
        continue;
      }
      $inner = $this->parser->parse($snapshot->pattern($slug), $snapshot->patternRenders($slug));
      $inlined = [...$inlined, ...$this->inlinePatterns($inner, $snapshot, [...$patterns, $slug])];
    }
    return $inlined;
  }

  /**
   * Replaces one pattern reference with its expanded blocks.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The referenced pattern's blocks, or none when the reference names no
   *   registered pattern.
   */
  private function expandPattern(BlockNode $node, Snapshot $snapshot, array $patterns, array $parts): array {
    $slug = $this->patternSlug($node, $snapshot, $patterns);
    if ($slug === NULL) {
      return [];
    }
    $inner = $this->parser->parse($snapshot->pattern($slug), $snapshot->patternRenders($slug));
    return $this->expand($inner, $snapshot, [...$patterns, $slug], $parts);
  }

  /**
   * Returns the slug a pattern reference names, or NULL when none resolves.
   *
   * WordPress renders nothing for a reference with no slug or an unregistered
   * one (render_block_core_pattern()), and leaves the rest of the tree as it
   * is. A slug with a stray leading space is unregistered.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The pattern reference.
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param string[] $patterns
   *   Pattern slugs already open.
   */
  private function patternSlug(BlockNode $node, Snapshot $snapshot, array $patterns): ?string {
    $slug = (string) ($node->attributes['slug'] ?? '');
    if ($slug === '' || !$snapshot->hasPattern($slug)) {
      return NULL;
    }
    if (in_array($slug, $patterns, TRUE)) {
      throw new \UnexpectedValueException('Cyclic pattern reference: ' . implode(' -> ', [...$patterns, $slug]) . '.');
    }
    return $slug;
  }

  /**
   * Wraps one template-part reference as an expanded group.
   *
   * The wrapper drops the `slug` attribute: it identifies the reference, not
   * the block, and every caller reads the part's slug separately.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The wrapped part as one expanded group, or none when the reference
   *   names a part the theme lacks, as WordPress renders it.
   */
  private function expandTemplatePart(BlockNode $node, Snapshot $snapshot, array $patterns, array $parts): array {
    $slug = (string) ($node->attributes['slug'] ?? '');
    if ($slug === '') {
      throw new \UnexpectedValueException('Template part reference has no slug.');
    }
    if (in_array($slug, $parts, TRUE)) {
      throw new \UnexpectedValueException('Cyclic template part reference: ' . implode(' -> ', [...$parts, $slug]));
    }
    if (!$snapshot->hasPart($slug)) {
      return [];
    }
    $attributes = $node->attributes;
    unset($attributes['slug']);
    if (empty($attributes['tagName'])) {
      $attributes['tagName'] = $snapshot->partTag($slug);
    }
    return [
      new BlockNode(
        'core/group',
        array_replace($attributes, ['templatePart' => TRUE]),
        '',
        $this->expand(
          $this->parser->parse($snapshot->part($slug), $snapshot->partRenders($slug)),
          $snapshot,
          $patterns,
          [...$parts, $slug],
        ),
      ),
    ];
  }

}
