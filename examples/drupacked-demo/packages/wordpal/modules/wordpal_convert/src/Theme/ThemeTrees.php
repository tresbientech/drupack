<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

use Drupal\wordpal_convert\WordPress\Snapshot;

/**
 * Resolves the block tree of a Pattern, a Template, or a saved HTML blob.
 *
 * A Pattern can hold a reference to another pattern, and a Template's frame
 * holds template parts around its main area. Both need their references
 * inlined, then expanded, before a writer can walk them. This service hides
 * that two-phase protocol behind one typed result per Pattern and per
 * Template. The result also carries the frame split into main area and part
 * placements, and the Query loop numbering.
 */
final class ThemeTrees {

  public function __construct(
    private readonly BlockParser $parser,
    private readonly ReferenceExpander $expander,
  ) {}

  /**
   * Resolves one Inserter pattern's tree.
   *
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param string $slug
   *   The pattern slug.
   *
   * @throws \UnexpectedValueException
   *   On a structural fault in the Pattern.
   */
  public function pattern(Snapshot $snapshot, string $slug): ResolvedPattern {
    $tree = QueryLoop::allFromTree($this->patternNodes($snapshot, $slug), $snapshot->postsPerPage());
    return ResolvedPattern::resolved(self::patternMetadata($snapshot, $slug), $tree['nodes'], $tree['loops'], $tree['dropped']);
  }

  /**
   * Resolves one route's Template: its main area, Part set, and Query blocks.
   *
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param string $name
   *   The template name, such as "single".
   *
   * @throws \UnexpectedValueException
   *   On a structural fault in the Template.
   */
  public function template(Snapshot $snapshot, string $name): ResolvedTemplate {
    $frame = $this->frame($snapshot, $name);
    $nodes = [];
    foreach ($frame['main'] as ['node' => $node, 'patterns' => $patterns]) {
      $nodes = [...$nodes, ...$this->expander->expand([$node], $snapshot, $patterns)];
    }
    $partSet = new PartSet($this->partPlacements($snapshot, $name, $frame['placements']));
    $tree = QueryLoop::allFromTree($nodes, $snapshot->postsPerPage());
    return new ResolvedTemplate($tree['nodes'], $partSet, $tree['loops'], $tree['dropped']);
  }

  /**
   * Parses and expands one saved HTML blob, for block-library fixtures.
   *
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param string $html
   *   The saved block markup.
   * @param array<string, string> $renders
   *   The HTML WordPress rendered for each block, keyed by block path.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The blob's blocks, references expanded.
   */
  public function nodes(Snapshot $snapshot, string $html, array $renders): array {
    return $this->expander->expand($this->parser->parse($html, $renders), $snapshot);
  }

  /**
   * Returns the blocks of one pattern, references expanded.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The pattern's blocks, references expanded.
   */
  private function patternNodes(Snapshot $snapshot, string $slug): array {
    return $this->expander->expand($this->parser->parse($snapshot->pattern($slug), $snapshot->patternRenders($slug)), $snapshot, [$slug]);
  }

  /**
   * Finds one pattern's snapshot record by its slug.
   *
   * The lookup always finds a match: patternNodes() already read this
   * pattern through Snapshot::pattern(), which throws when no pattern of
   * this slug exists.
   */
  private static function patternMetadata(Snapshot $snapshot, string $slug): array {
    foreach ($snapshot->patterns() as $pattern) {
      if ($pattern['slug'] === $slug) {
        return $pattern;
      }
    }
    throw new \LogicException("Pattern $slug was read from the snapshot and is no longer listed in it.");
  }

  /**
   * Returns the ordered part and marker placements of a Template's frame.
   *
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot.
   * @param string $template
   *   The template name, for the exception message.
   * @param array[] $framePlacements
   *   The frame's placements, each part holding its top-level item.
   *
   * @return array[]
   *   Ordered placements, each part expanded to its nodes.
   */
  private function partPlacements(Snapshot $snapshot, string $template, array $framePlacements): array {
    $placements = [];
    foreach ($framePlacements as $placement) {
      if ($placement['type'] === 'marker') {
        // Drupal's status messages print right before the page content.
        $placements[] = ['type' => 'messages'];
        $placements[] = $placement;
        continue;
      }
      ['node' => $node, 'patterns' => $patterns] = $placement['item'];
      $slug = (string) ($node->attributes['slug'] ?? '');
      if ($slug === '') {
        throw new \UnexpectedValueException("Template $template has a template part without a slug.");
      }
      $attributes = $node->attributes;
      unset($attributes['slug']);
      $placements[] = [
        'type' => 'part',
        'slug' => $slug,
        'attributes' => $attributes,
        'nodes' => $this->expander->expand([$node], $snapshot, $patterns),
      ];
    }
    return $placements;
  }

  /**
   * Splits a template's top-level blocks into its frame and its main area.
   *
   * The main area's blocks follow each other, and one marker stands for
   * them among the template parts.
   *
   * @return array{main: array[], placements: array[]}
   *   The main area's top-level items, and the ordered part and marker
   *   placements, each part holding its top-level item.
   */
  private function frame(Snapshot $snapshot, string $template): array {
    $main = [];
    $placements = [];
    foreach ($this->topLevel($snapshot, $template) as $item) {
      if ($item['node']->name === ReferenceExpander::TEMPLATE_PART) {
        $placements[] = ['type' => 'part', 'item' => $item];
        continue;
      }
      if ($main !== [] && end($placements)['type'] !== 'marker') {
        throw new \UnexpectedValueException("Template $template holds page content on both sides of a template part.");
      }
      if ($main === []) {
        $placements[] = ['type' => 'marker'];
      }
      $main[] = $item;
    }
    if ($main === []) {
      // A template of parts alone holds its page content in the one part of
      // the uncategorized area, whose tag is div.
      $content = array_filter($placements, static fn (array $placement): bool => $snapshot->partTag((string) ($placement['item']['node']->attributes['slug'] ?? '')) === 'div');
      if (count($content) !== 1) {
        throw new \UnexpectedValueException("Template $template holds no page content between its template parts.");
      }
      $key = array_key_first($content);
      $main = [$placements[$key]['item']];
      $placements[$key] = ['type' => 'marker'];
    }
    return ['main' => $main, 'placements' => $placements];
  }

  /**
   * Returns a template's top-level blocks, top-level patterns inlined.
   *
   * @return array<int, array{node: \Drupal\wordpal_convert\Theme\BlockNode, patterns: string[]}>
   *   The page frame blocks, each with the pattern slugs open around it.
   */
  private function topLevel(Snapshot $snapshot, string $template): array {
    return $this->expander->inlinePatterns(
      $this->parser->parse($snapshot->template($template), $snapshot->templateRenders($template)),
      $snapshot,
    );
  }

}
