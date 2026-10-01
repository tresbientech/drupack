<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder;

use Drupal\display_builder\Entity\PatternPreset;
use Drupal\views\Entity\View;
use Drupal\wordpal_convert\ConfigValidation;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\OwnedEntityUuid;
use Drupal\wordpal_convert\Query\QuerySettingsWriter;
use Drupal\wordpal_convert\Query\QueryViewConfig;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\Theme\ResolvedTemplate;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal_convert\Theme\TreeHashing;

/**
 * Writes a Query block's and a listing Template's Display Builder outputs.
 *
 * DisplayBuilderWriter owns Patterns, page layouts and content templates
 * built from a plain block tree; this class owns everything a Query block or
 * a listing Template needs instead: the Query's own View, its row component
 * and no-results preset, and a listing Template's disabled preset and page
 * View. DisplayBuilderWriter's writeQueryPattern(), writeQueryPage(),
 * writeQueryContentTemplate(), writeTemplateView() and writeTermTemplate()
 * each delegate here.
 *
 * A Query block becomes a page-scoped Views View: its rows are the UI
 * Patterns "component row" plugin holding the Post Template's card, its
 * pager and empty-result regions are Display Builder's own view_pager and
 * view_empty sources, and the owning tree places the View through a
 * `block` source pointing at the View's own block display. The archive, tag,
 * category and search Templates become a View whose header area renders the
 * Template's own disabled preset through PatternPresetArea, the same plugin
 * the Query View's empty region reuses for its no-results preset.
 */
final class ListingWriter {

  /**
   * The block plugin id prefix a View's block display resolves under.
   *
   * Public: ListingBlocks builds a listing View's own block id under the
   * same prefix.
   */
  public const VIEWS_BLOCK_PREFIX = 'views_block:';

  /**
   * The Display Builder profile every generated View display uses.
   */
  private const PROFILE = 'default';

  /**
   * The Views area plugin that renders a preset in a Query or Template View.
   */
  private const AREA_PLUGIN = 'wordpal_pattern_preset';

  /**
   * The title of each listing route's Template.
   */
  private const PAGE_TITLES = [
    'archive' => 'Archive',
    'tag' => 'Tag archive',
    'category' => 'Category archive',
    'search' => 'Search results',
  ];

  public function __construct(
    private readonly SourceTreeBuilder $sourceTreeBuilder,
    private readonly TreeHashing $treeHashing,
    private readonly QuerySettingsWriter $querySettingsWriter,
  ) {}

  /**
   * Builds, then saves, an outer tree whose Query blocks become Views.
   *
   * Build, then save: the owning tree and every Query's card and empty tree
   * are built and checked for slot mismatches, prop values and Frozen
   * blocks missing from the render index first, all before a single entity
   * is created, mirroring CanvasWriter::writeQueryOwnerTree(). Two Query
   * blocks whose card, pagination, empty-result content and settings hash
   * the same get the same ids from queryOutputIds(), so saveQuery() below
   * saves their shared View once and reuses it for every later occurrence.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The owning tree: a Pattern, a home/404 Template, or a listing
   *   Template's own main area.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop[] $loops
   *   The tree's Query blocks.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param string $theme
   *   The theme slug.
   * @param string $ownerLabel
   *   Human name of the Pattern or Template this Query belongs to. Labels
   *   the generated View the first time this content hash saves it; a
   *   later, shared occurrence keeps the first label.
   * @param string|null $route
   *   The listing route whose Template holds the tree, or NULL for a
   *   Pattern or a home/404 Template.
   * @param string|null $boundBundle
   *   The node bundle the outer tree's own runtime bindings resolve
   *   against, or NULL when the tree binds no routed node (a Pattern, or a
   *   home/404 or listing Template, none of which render for one bundle's
   *   own node). A single or page content template passes its bundle here,
   *   the same value writeContentTemplate() passes as its boundBundle.
   * @param string[] $rootPath
   *   See SourceTreeBuilder::build(). A home or 404 Template's own query
   *   page passes its route here, keeping its node ids off the page
   *   bundle's own shared content template.
   * @param bool $inTemplatePart
   *   Whether $nodes are a page frame's Template part.
   *
   * @return array{items: array, queries: array[]}
   *   "items" holds the owning tree's own source list, and "queries" one
   *   entry per Query block, shaped as saveQuery() returns them.
   */
  public function writeQueryOwnerTree(array $nodes, array $loops, ContentMapping $mapping, string $theme, string $ownerLabel, ?string $route, ?string $boundBundle = NULL, array $rootPath = [], bool $inTemplatePart = FALSE): array {
    $bundle = $mapping->target('post');
    $ids = [];
    $routes = [];
    $replacements = [];
    foreach ($loops as $index => $loop) {
      $routes[$index] = $loop->route($route);
      $ids[$index] = $this->queryOutputIds($theme, $loop, $bundle, $routes[$index]);
      $replacements[spl_object_id($loop->query)] = [
        'source_id' => 'block',
        'source' => [
          'plugin_id' => $ids[$index]['component'],
          $ids[$index]['component'] => [],
        ],
      ];
    }
    $items = $this->sourceTreeBuilder->build($nodes, $mapping, $replacements, boundBundle: $boundBundle, rootPath: $rootPath, inTemplatePart: $inTemplatePart);
    $queries = [];
    foreach ($loops as $index => $loop) {
      $queries[] = $this->saveQuery($bundle, $loop, $ownerLabel, $ids[$index], $mapping, $routes[$index]);
    }
    return ['items' => $items, 'queries' => $queries];
  }

  /**
   * Saves one Query's View and empty preset, or reuses an earlier save.
   *
   * @param string $bundle
   *   The node bundle the Query lists.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop $loop
   *   The Query's dynamic pieces.
   * @param string $ownerLabel
   *   See writeQueryOwnerTree().
   * @param array $ids
   *   The output ids queryOutputIds() returned for this Query.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping a search View reads its index and parameter from.
   * @param string|null $route
   *   The listing route whose Template holds the Query, or NULL.
   *
   * @return array{view_mode: null, template: null, page_template: null, view: \Drupal\views\Entity\View, component: null, empty_pattern: \Drupal\display_builder\Entity\PatternPreset|null}
   *   Shaped like CanvasWriter::saveQueryInfrastructure()'s return so
   *   ConversionRunner's report and ownership tracking read either target's
   *   entries the same way; the keys this target has no entity for are
   *   NULL.
   */
  private function saveQuery(string $bundle, QueryLoop $loop, string $ownerLabel, array $ids, ContentMapping $mapping, ?string $route): array {
    $emptyPattern = $ids['empty_pattern'] !== NULL
      ? $this->saveEmptyPreset($ownerLabel, $ids['empty_pattern'], $loop, $mapping)
      : NULL;
    $view = View::load($ids['view']);
    if ($view === NULL) {
      // On the search route this row reads the entity Search API sets on
      // each result row.
      $row = ['type' => 'ui_patterns', 'options' => ['ui_patterns' => $this->rowComponent($loop, $bundle, $mapping)]];
      $sources = $this->querySources($loop, $ids['empty_pattern'], $mapping);
      $extenders = ['display_builder' => ['profile' => self::PROFILE, 'sources' => $sources]];
      $values = QueryViewConfig::queryView($ids['view'], "$ownerLabel query", $bundle, $loop, $route, $mapping, $row, self::AREA_PLUGIN, $ids['empty_pattern'], $extenders);
      $values['uuid'] = OwnedEntityUuid::uuid('view', $ids['view']);
      $view = View::create($values);
      $skipped = [];
      $this->querySettingsWriter->write($view, $this->querySettingsWriter->build($loop, $mapping, $route, $skipped));
      if ($skipped !== []) {
        throw new \UnexpectedValueException('Query settings use unsupported values: ' . implode(', ', $skipped));
      }
      ConfigValidation::assertValid($view);
      $view->save();
    }
    return [
      'view_mode' => NULL,
      'template' => NULL,
      'page_template' => NULL,
      'view' => $view,
      'component' => NULL,
      'empty_pattern' => $emptyPattern,
    ];
  }

  /**
   * Saves one no-results tree's disabled preset once.
   *
   * A later Query whose empty-result tree hashes the same reuses what this
   * saved.
   */
  private function saveEmptyPreset(string $ownerLabel, string $emptyPatternId, QueryLoop $loop, ContentMapping $mapping): PatternPreset {
    $existing = PatternPreset::load($emptyPatternId);
    if ($existing !== NULL) {
      return $existing;
    }
    $tree = $this->sourceTreeBuilder->build([$loop->noResults], $mapping, inQuery: TRUE);
    $preset = $this->savePreset($emptyPatternId, "$ownerLabel query empty state", $tree, $mapping->themeId());
    $preset->disable()->save();
    return $preset;
  }

  /**
   * Returns a Query's card as the component a Views row plugin stores.
   *
   * The row plugin's own config is a `component` source's inner value
   * (component_id, props, slots), not a full source node, so a multi-root
   * card still needs SourceTreeBuilder::wrapAsPatternRoot()'s Fragment root,
   * and even a natural single root must resolve to a `component` source:
   * unlike a page layout or a pattern preset, a Views row cannot fall back
   * to a `block` source for its root.
   *
   * Props bind the row's own entity through RuntimeBindings, the same table
   * DisplayBuilderWriter's entity view display resolves its own runtime
   * blocks against, here resolved against the Query's own post bundle
   * instead of a routed node's. A block bound to a field the bundle lacks
   * (Post Featured Image, on a bundle with no mapped image field) is
   * dropped from the card and reported, the same as on the page route.
   */
  private function rowComponent(QueryLoop $loop, string $bundle, ContentMapping $mapping): array {
    $tree = $this->sourceTreeBuilder->build($loop->postTemplate->children, $mapping, boundBundle: $bundle, inQuery: TRUE);
    if ($tree === []) {
      throw new \UnexpectedValueException('A Query card has no block Display Builder can render.');
    }
    $root = SourceTreeBuilder::wrapAsPatternRoot($tree);
    if ($root['source_id'] !== 'component') {
      throw new \UnexpectedValueException('A Query card must build at least one WordPal component.');
    }
    return $root['source']['component'];
  }

  /**
   * Replaces one owned preset with an already-built tree.
   */
  private function savePreset(string $id, string $label, array $tree, string $group): PatternPreset {
    $preset = PatternPreset::create([
      'id' => $id,
      'uuid' => OwnedEntityUuid::uuid('pattern_preset', $id),
      'label' => $label,
      'group' => $group,
      'sources' => SourceTreeBuilder::wrapAsPatternRoot($tree),
    ]);
    ConfigValidation::assertValid($preset);
    $preset->save();
    return $preset;
  }

  /**
   * Writes a listing Template as its route's View and disabled preset.
   *
   * @param string $route
   *   The listing route, "archive" or "search".
   * @param \Drupal\wordpal_convert\Theme\ResolvedTemplate $template
   *   The Template's main tree and Query blocks.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param string $theme
   *   The theme slug.
   * @param array<string, string> $termPatterns
   *   On the archive route, the presets writeTermTemplate() saved for the
   *   term pages of one vocabulary, keyed by vocabulary id.
   *
   * @return array
   *   "pattern" and "view" hold the saved preset and page View, and
   *   "queries" one entry per Query block.
   */
  public function writeTemplateView(string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme, array $termPatterns = []): array {
    $label = self::PAGE_TITLES[$route];
    ['items' => $items, 'queries' => $queries] = $this->writeQueryOwnerTree($template->nodes, $template->loops, $mapping, $theme, $label, $route);
    $ids = $this->templateOutputIds($theme, $route);
    $pattern = $this->savePreset($ids['pattern'], "$label Template", $items, $mapping->themeId());
    $pattern->disable()->save();
    // The header area alone renders the Template's content, through
    // PatternPresetArea.
    $extenders = [
      'display_builder' => [
        'profile' => self::PROFILE,
        'sources' => [
          ['node_id' => SourceTreeBuilder::nodeId(['view_header']), 'source_id' => 'view_header', 'source' => []],
        ],
      ],
    ];
    $values = QueryViewConfig::templateView($ids['view'], $route, $label, $ids['pattern'], $mapping, $termPatterns, self::AREA_PLUGIN, $extenders);
    $values['uuid'] = OwnedEntityUuid::uuid('view', $ids['view']);
    $view = View::create($values);
    $view->disable();
    ConfigValidation::assertValid($view);
    $view->save();
    return ['pattern' => $pattern, 'view' => $view, 'queries' => $queries];
  }

  /**
   * Writes a tag or category Template as the preset its term pages render.
   *
   * @param string $route
   *   The term route, "tag" or "category".
   * @param \Drupal\wordpal_convert\Theme\ResolvedTemplate $template
   *   The Template's main tree and Query blocks.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param string $theme
   *   The theme slug.
   *
   * @return array
   *   "pattern" holds the saved preset, and "queries" one entry per Query
   *   block.
   */
  public function writeTermTemplate(string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array {
    $label = self::PAGE_TITLES[$route];
    ['items' => $items, 'queries' => $queries] = $this->writeQueryOwnerTree($template->nodes, $template->loops, $mapping, $theme, $label, 'archive');
    $pattern = $this->savePreset($this->templateOutputIds($theme, $route)['pattern'], "$label Template", $items, $mapping->themeId());
    $pattern->disable()->save();
    return ['pattern' => $pattern, 'queries' => $queries];
  }

  /**
   * Returns the ids a listing Template's preset and page View are saved under.
   *
   * A tag or category Template saves only its preset.
   */
  public function templateOutputIds(string $theme, string $route): array {
    return [
      'pattern' => ThemeGenerator::themeId($theme) . "_{$route}_template",
      'view' => 'wordpal_' . TreeHashing::idPrefix($theme) . "_$route",
    ];
  }

  /**
   * Returns the deterministic ids one Query's outputs would be saved under.
   *
   * The view mode id hashes the card tree (the Query's Post Template
   * children); this target saves no view mode of its own, but the id still
   * names the shared View's row config uniquely. The View id hashes the
   * bundle, paging, sort, sticky filter, and the whole Query subtree, which
   * includes the card, pagination, and empty-result content the View's own
   * config is built from. Two Query blocks with the same values under each
   * hash get the same ids, so saveQuery() saves their shared View once.
   */
  public function queryOutputIds(string $theme, QueryLoop $loop, string $bundle, ?string $route = NULL): array {
    $prefix = TreeHashing::idPrefix($theme);
    $viewMode = "wordpal_{$prefix}_card_" . $this->treeHashing->treeKey($loop->postTemplate->children);
    $view = "wordpal_{$prefix}_view_" . $this->treeHashing->queryViewKey($loop, $bundle, $route);
    return [
      'view_mode' => $viewMode,
      'view' => $view,
      'component' => self::VIEWS_BLOCK_PREFIX . "$view-block_1",
      'empty_pattern' => $loop->noResults !== NULL ? "{$prefix}_empty_" . $this->treeHashing->treeKey([$loop->noResults]) : NULL,
    ];
  }

  /**
   * Returns the source tree a Query View's display extenders render.
   */
  private function querySources(QueryLoop $loop, ?string $emptyPatternId, ContentMapping $mapping): array {
    // The Query block's own component wraps its children, with the Post
    // Template, pagination and no-results blocks standing for the View's rows,
    // pager and empty region.
    // $loop->postTemplate is a copy when the Query saved a legacy
    // displayLayout; the replacement keys the node inside the Query tree.
    $postTemplate = QueryLoop::descendants($loop->query, 'core/post-template')[0];
    $replacements = [spl_object_id($postTemplate) => ['source_id' => 'view_rows', 'source' => []]];
    if ($loop->pagination !== NULL) {
      $replacements[spl_object_id($loop->pagination)] = ['source_id' => 'view_pager', 'source' => []];
    }
    if ($emptyPatternId !== NULL) {
      $replacements[spl_object_id($loop->noResults)] = ['source_id' => 'view_empty', 'source' => []];
    }
    return $this->sourceTreeBuilder->build([$loop->query], $mapping, $replacements);
  }

}
