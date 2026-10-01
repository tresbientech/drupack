<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder;

use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Component\BlockPluginInputs;
use Drupal\wordpal_convert\Component\CommentBlockInputs;
use Drupal\wordpal_convert\Component\ComponentInputs;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Support\FrozenHoles;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\DrupalRouteFrame;
use Drupal\wordpal_convert\Theme\TreeConcepts;

/**
 * Turns a WordPal block tree into Display Builder's stored source tree.
 *
 * Saves nothing except a listing block's View, which ListingBlocks::place()
 * saves. A node holding a WordPal SDC component becomes a
 * `component` source, a WordPal block plugin becomes a `block` source, and a
 * block with neither becomes the frozen-html component, mirroring the three
 * cases CanvasWriter decides for Canvas. A bound tree resolves its runtime
 * blocks through RuntimeBindings: omission() drops a block the content
 * mapping excludes or the bundle has no field for, and props() overlays bound
 * values onto a matched component's own static ones. This walk is the only
 * one that counts dropped blocks. A Query block's own View is not this
 * class's job: it only substitutes the source node ListingWriter already
 * built at the block's tree position. A latest-posts, page-list or
 * latest-comments block's own View is ListingBlocks's job instead, resolved
 * inline since, unlike a Query, no earlier pass needs to know its id first.
 */
final class SourceTreeBuilder {

  /**
   * The module the source tree's components and blocks come from.
   */
  public const PROVIDER = 'wordpal';

  /**
   * The WordPal HTML text format a wysiwyg source stores its value under.
   */
  private const HTML_FORMAT = 'wordpal_html';

  /**
   * The WordPal component a multi-block tree wraps its root nodes in.
   *
   * `pattern_preset.sources` and a Views `ui_patterns` row both store a
   * single node, unlike a page layout or an override field, which store a
   * list. The Fragment component prints its children with no element of
   * its own.
   */
  public const WRAPPER_COMPONENT = self::PROVIDER . ':' . ComponentSet::FRAGMENT_SLUG;

  /**
   * The slot the wrapper component holds its children in.
   */
  public const WRAPPER_SLOT = 'content';

  /**
   * Whether the nodes being built belong to a Template part.
   */
  private bool $inTemplatePart = FALSE;

  /**
   * Whether the nodes being built belong to a Gallery.
   */
  private bool $inGallery = FALSE;

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly RuntimeBindings $runtimeBindings,
    private readonly ListingBlocks $listingBlocks,
    private readonly BlockPluginInputs $blockPluginInputs,
    private readonly ComponentInputs $componentInputs,
    private readonly CommentBlockInputs $commentBlockInputs,
  ) {}

  /**
   * Returns the stored source tree for a block tree's top level.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at the top of the tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets: a Navigation block with no links of its own resolves
   *   its menu setting from the mapped navigation menu here, the same way
   *   CanvasWriter's own block settings do for Canvas.
   * @param array<int, array> $replacements
   *   A precomputed source node, keyed by `spl_object_id()` of the
   *   \Drupal\wordpal_convert\Theme\BlockNode it stands in for. Used to
   *   place a Query block's View as a `block` source instead of walking its
   *   own children: ListingWriter builds the replacement, this class only
   *   substitutes it at the node's own tree position.
   * @param callable|null $dynamicSource
   *   Resolves one prop of one node to a source node bound to runtime data,
   *   or NULL to fall back to the node's own static value. Signature:
   *   `fn(BlockNode $node, string $prop, array $schema): ?array`. Used by a
   *   Query row's card, whose props bind the row entity instead of the
   *   block's saved attributes.
   * @param string|null $boundBundle
   *   The node bundle a content template binds its runtime blocks to, or
   *   NULL for a Pattern or page frame, whose blocks always keep their own
   *   static or Sample content.
   * @param string[] $rootPath
   *   Path segments nodeId() prepends to every node in this tree, so a
   *   per-node override field's tree never shares an id with the bundle's
   *   own shared content template: both are plain position-keyed hashes
   *   (nodeId()), and a home or 404 override sits on the same "page" bundle
   *   a content template's own full display uses, so the two would collide
   *   node for node at matching positions without this. A content template
   *   is the bundle's only tree at that position, so it keeps the default.
   * @param bool $inTemplatePart
   *   Whether $nodes are a page frame's Template part.
   *
   * @return array
   *   A list of source nodes, in Display Builder's stored shape.
   */
  public function build(array $nodes, ContentMapping $mapping, array $replacements = [], ?callable $dynamicSource = NULL, ?string $boundBundle = NULL, array $rootPath = [], bool $inTemplatePart = FALSE): array {
    $context = [$this->inTemplatePart, $this->inGallery];
    // A Query card tree built inside a Template part stays inside it.
    $this->inTemplatePart = $this->inTemplatePart || $inTemplatePart;
    try {
      return $this->buildLevel($nodes, $rootPath, $mapping, $replacements, $dynamicSource, $boundBundle);
    }
    finally {
      [$this->inTemplatePart, $this->inGallery] = $context;
    }
  }

  /**
   * Returns one level of source nodes, in tree order.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at this level.
   * @param string[] $path
   *   The path segments of the level's parent, empty at the top.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, see build().
   * @param array<int, array> $replacements
   *   See build().
   * @param callable|null $dynamicSource
   *   See build().
   * @param string|null $boundBundle
   *   The bound bundle, see build().
   */
  private function buildLevel(array $nodes, array $path, ContentMapping $mapping, array $replacements, ?callable $dynamicSource, ?string $boundBundle): array {
    $level = [];
    foreach ($nodes as $index => $node) {
      $built = $this->buildNode($node, [...$path, (string) $index], $mapping, $replacements, $dynamicSource, $boundBundle);
      if ($built !== NULL) {
        $level[] = $built;
      }
    }
    return $level;
  }

  /**
   * Returns one block's source node, or NULL for a dropped or unwritable block.
   *
   * NULL comes from a concept the Content mapping drops (TreeConcepts,
   * recorded on $mapping the way CanvasWriter records it), a runtime block a
   * bound bundle has nothing to bind (RuntimeBindings::omission(), which
   * records a MissingImage omission on $mapping too), or an unwritable Frozen
   * block (buildFrozenNode()).
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param string[] $path
   *   The node's own path segments.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, see build().
   * @param array<int, array> $replacements
   *   See build().
   * @param callable|null $dynamicSource
   *   See build().
   * @param string|null $boundBundle
   *   The bound bundle, see build().
   */
  private function buildNode(BlockNode $node, array $path, ContentMapping $mapping, array $replacements, ?callable $dynamicSource, ?string $boundBundle): ?array {
    if ($node->name === DrupalRouteFrame::MAIN_CONTENT) {
      return ['node_id' => self::nodeId($path), 'source_id' => 'main_page_content', 'source' => []];
    }
    $concept = TreeConcepts::concept($node);
    if (TreeConcepts::dropped($concept, $mapping)) {
      // Mirrors CanvasWriter::addNodes(): a concept the mapping supplies and
      // sets to null yields no source on either target, counted the same way.
      $mapping->recordDroppedBlocks($concept);
      return NULL;
    }
    if ($boundBundle !== NULL) {
      $omission = $this->runtimeBindings->omission($node, $boundBundle, $mapping);
      if ($omission === Omission::MissingImage) {
        $mapping->recordDroppedBlocks($concept);
      }
      if ($omission !== NULL) {
        return NULL;
      }
    }
    $nodeId = self::nodeId($path);
    if (isset($replacements[spl_object_id($node)])) {
      return ['node_id' => $nodeId, ...$replacements[spl_object_id($node)]];
    }
    $listing = $this->listingBlocks->place($node, $mapping);
    if ($listing !== NULL) {
      return ['node_id' => $nodeId, ...$listing];
    }
    $blockPlugin = BlockPluginInputs::pluginId($node->name);
    if ($blockPlugin !== NULL) {
      // The block source merges the plugin's own defaults under these.
      $values = in_array($node->name, CommentBlockInputs::BLOCK_NAMES, TRUE)
        ? $this->commentValues($node, $mapping)
        : $this->blockPluginInputs->inputs($node, [], $mapping);
      return [
        'node_id' => $nodeId,
        'source_id' => 'block',
        'source' => [
          'plugin_id' => $blockPlugin,
          $blockPlugin => $values,
        ],
      ];
    }
    $componentId = self::PROVIDER . ':' . $node->slug();
    if ($this->componentPluginManager->hasDefinition($componentId)) {
      return $this->buildComponentNode($node, $componentId, $nodeId, $path, $mapping, $replacements, $dynamicSource, $boundBundle);
    }
    return $this->buildFrozenNode($node, $nodeId, $path, $mapping, $replacements, $dynamicSource, $boundBundle);
  }

  /**
   * Returns the `wordpal_comments` block plugin's settings for one node.
   *
   * `layout` holds the marker-or-component tree CommentBlockInputs::layout()
   * builds from the comments block's own inner blocks, the same shape
   * CanvasWriter stores under CommentBlockInputs on the Canvas target.
   */
  private function commentValues(BlockNode $node, ContentMapping $mapping): array {
    $comments = $this->commentBlockInputs->values($node, [], $mapping);
    return $comments['settings'] + ['layout' => $this->commentBlockInputs->layout($comments['children'])];
  }

  /**
   * Returns a `component` source node for a node with a WordPal component.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param string $componentId
   *   The WordPal SDC component id the block's slug resolved to.
   * @param string $nodeId
   *   The node's own id, from nodeId().
   * @param string[] $path
   *   The node's own path segments, extended for its slot child paths.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, see build().
   * @param array<int, array> $replacements
   *   See build().
   * @param callable|null $dynamicSource
   *   See build().
   * @param string|null $boundBundle
   *   The bound bundle, see build(). A runtime block's own props are
   *   overlaid with RuntimeBindings::props() when set, replacing this
   *   node's static values for the bound props only.
   */
  private function buildComponentNode(BlockNode $node, string $componentId, string $nodeId, array $path, ContentMapping $mapping, array $replacements, ?callable $dynamicSource, ?string $boundBundle): array {
    $metadata = $this->componentPluginManager->find($componentId)->metadata;
    $props = $this->props($node, $metadata->schema['properties'] ?? [], $dynamicSource, $mapping);
    if ($boundBundle !== NULL) {
      $bound = $this->runtimeBindings->props($node, $boundBundle, $mapping);
      if ($bound !== NULL) {
        $props = $bound + $props;
      }
    }
    $component = [
      'component_id' => $componentId,
      'props' => $props,
    ];
    if ($boundBundle !== NULL) {
      foreach ($this->runtimeBindings->slots($node, $boundBundle, $mapping) ?? [] as $slot => $sources) {
        foreach ($sources as $index => $source) {
          $component['slots'][$slot]['sources'][] = ['node_id' => self::nodeId([...$path, $slot, (string) $index])] + $source;
        }
      }
    }
    $slotId = array_key_first($metadata->slots);
    if ($slotId !== NULL && $node->children !== []) {
      $context = [$this->inTemplatePart, $this->inGallery];
      [$this->inTemplatePart, $this->inGallery] = ComponentInputs::childContext($node, ...$context);
      try {
        $component['slots'] = [
          $slotId => ['sources' => $this->buildLevel(TreeConcepts::childNodes($node), [...$path, $slotId], $mapping, $replacements, $dynamicSource, $boundBundle)],
        ];
      }
      finally {
        [$this->inTemplatePart, $this->inGallery] = $context;
      }
    }
    return [
      'node_id' => $nodeId,
      'source_id' => 'component',
      'source' => ['component' => $component],
    ];
  }

  /**
   * Returns one component's props as ui_patterns sources, keyed by prop id.
   *
   * Static values come from ComponentInputs::forNode(), the rules the Canvas
   * writer uses; a prop with no value renders with the component's own Twig
   * default.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param array<string, array> $properties
   *   The component's prop schemas, keyed by prop id.
   * @param callable|null $dynamicSource
   *   See build(). Checked before the block's own static value, so a bound
   *   prop wins over whatever WordPress saved for it.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets for the props a component takes from the mapping.
   */
  private function props(BlockNode $node, array $properties, ?callable $dynamicSource, ContentMapping $mapping): array {
    // This target reports no line for a value no prop accepts.
    $skipped = [];
    $values = $this->componentInputs->forNode($node, $skipped) + ComponentInputs::mapped($node, $mapping) + ComponentInputs::contextInputs($node, $this->inTemplatePart, $this->inGallery);
    $props = [];
    foreach ($properties as $prop => $schema) {
      $bound = $dynamicSource === NULL ? NULL : $dynamicSource($node, $prop, $schema);
      if ($bound !== NULL) {
        $props[$prop] = $bound;
      }
      elseif (isset($values[$prop])) {
        $props[$prop] = $this->propSource($schema, $values[$prop]);
      }
    }
    return $props;
  }

  /**
   * Returns one prop's value as the ui_patterns source its kind takes.
   *
   * HTML content media types go to the rich text source, storing the
   * WordPal HTML text format alongside the value. An enum prop, whatever
   * its underlying JSON Schema type, goes to the select source: the
   * component's own `meta:enum` already carries WordPress's labels. An
   * array prop, such as a Post Terms block's sample `terms`/`term_urls`,
   * goes to the list source, which splits its stored value one item per
   * line; a bare `(string) $value` on a PHP array prints nothing useful.
   */
  private function propSource(array $schema, mixed $value): array {
    if (($schema['contentMediaType'] ?? NULL) === 'text/html') {
      return [
        'source_id' => 'wysiwyg',
        'source' => ['value' => ['value' => (string) $value, 'format' => self::HTML_FORMAT]],
      ];
    }
    if (isset($schema['enum'])) {
      return ['source_id' => 'select', 'source' => ['value' => $value]];
    }
    $type = $schema['type'] ?? 'string';
    if (is_array($type)) {
      $type = $type[0];
    }
    return match ($type) {
      'boolean' => ['source_id' => 'checkbox', 'source' => ['value' => (bool) $value]],
      'integer' => ['source_id' => 'number', 'source' => ['value' => (int) $value]],
      'number' => ['source_id' => 'number', 'source' => ['value' => $value + 0]],
      'array' => ['source_id' => 'list_textarea', 'source' => ['value' => implode("\n", array_map('strval', $value))]],
      default => ['source_id' => 'textfield', 'source' => ['value' => (string) $value]],
    };
  }

  /**
   * Returns a `component` source node holding a block's Frozen HTML.
   *
   * Each Query inside the block fills a hole slot with its replacement, the
   * View ListingWriter built for it; a Query with no replacement leaves its
   * hole empty, as on Canvas. Returns NULL when FrozenHoles::file() gives no
   * file, which this target does not report.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param string $nodeId
   *   The node's own id, from nodeId().
   * @param string[] $path
   *   The node's own path segments, extended for its hole slot paths.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, see build().
   * @param array<int, array> $replacements
   *   See build().
   * @param callable|null $dynamicSource
   *   See build().
   * @param string|null $boundBundle
   *   The bound bundle, see build().
   */
  private function buildFrozenNode(BlockNode $node, string $nodeId, array $path, ContentMapping $mapping, array $replacements, ?callable $dynamicSource, ?string $boundBundle): ?array {
    $file = FrozenHoles::file($node);
    if (is_string($file)) {
      return NULL;
    }
    $component = [
      'component_id' => self::PROVIDER . ':' . FrozenBlock::SLUG,
      'props' => [
        'block_name' => ['source_id' => 'textfield', 'source' => ['value' => $node->name]],
        'key' => ['source_id' => 'textfield', 'source' => ['value' => FrozenBlock::key($node->rendered)]],
      ],
    ];
    foreach ($file['queries'] as $index => $query) {
      if (!isset($replacements[spl_object_id($query)])) {
        continue;
      }
      $slot = FrozenBlock::holeSlot($index + 1);
      $component['slots'][$slot]['sources'] = [$this->buildNode($query, [...$path, $slot, '0'], $mapping, $replacements, $dynamicSource, $boundBundle)];
    }
    return ['node_id' => $nodeId, 'source_id' => 'component', 'source' => ['component' => $component]];
  }

  /**
   * Returns a node id derived from its position in the tree.
   *
   * Deterministic and stable across runs, unlike Display Builder's own
   * random bin2hex(random_bytes(8)) ids: the same tree always yields the
   * same ids, hex and the same length.
   *
   * @param string[] $path
   *   The node's path segments from the tree root.
   */
  public static function nodeId(array $path): string {
    return substr(hash('sha256', implode('/', $path)), 0, 16);
  }

  /**
   * Returns a tree as the single root node a preset or a Views row stores.
   *
   * A single top-level block becomes the root, unchanged. More than one
   * becomes children of the Fragment component.
   *
   * @param array $tree
   *   A source tree, from build().
   */
  public static function wrapAsPatternRoot(array $tree): array {
    if (count($tree) === 1) {
      return $tree[0];
    }
    return [
      'node_id' => self::nodeId(['__pattern_root__']),
      'source_id' => 'component',
      'source' => [
        'component' => [
          'component_id' => self::WRAPPER_COMPONENT,
          'props' => [],
          'slots' => [self::WRAPPER_SLOT => ['sources' => $tree]],
        ],
      ],
    ];
  }

}
