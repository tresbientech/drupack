<?php

declare(strict_types=1);

namespace Drupal\wordpal_canvas;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Component\BlockPluginInputs;
use Drupal\wordpal_convert\Component\CommentBlockInputs;
use Drupal\wordpal_convert\Component\ComponentInputs;
use Drupal\wordpal_convert\ConfigValidation;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Listing\ListingBlockSettings;
use Drupal\wordpal_convert\Listing\ListingViewConfig;
use Drupal\wordpal_convert\OwnedEntityUuid;
use Drupal\wordpal_convert\Query\QuerySettingsWriter;
use Drupal\wordpal_convert\Query\QueryViewConfig;
use Drupal\wordpal_convert\Support\FrozenHoles;
use Drupal\wordpal_convert\TemplatePage;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\DrupalRouteFrame;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\Theme\ResolvedTemplate;
use Drupal\wordpal_convert\Theme\TemplateResolver;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal_convert\Theme\TreeConcepts;
use Drupal\wordpal_convert\Theme\TreeHashing;
use Drupal\views\Entity\View;
use Drupal\views\ViewEntityInterface;
use Drupal\wordpal_convert\WriterInterface;

/**
 * Writes parsed WordPress block trees into Canvas configuration.
 */
final class CanvasWriter implements WriterInterface {

  /**
   * The module holding the components for WordPress core blocks.
   */
  public const COMPONENT_PROVIDER = 'wordpal';

  /**
   * The Component id prefix a View's own views_block plugin gets.
   */
  private const VIEWS_BLOCK_COMPONENT_PREFIX = 'block.views_block.';

  /**
   * The Views area plugin that renders a Pattern in a Query or Template View.
   */
  private const AREA_PLUGIN = 'wordpal_pattern';

  /**
   * SDC slugs whose props bind to node fields inside a content template.
   */
  private const DYNAMIC_CONTENT_SLUGS = [
    'post-title', 'post-date', 'post-author-name', 'post-featured-image',
    'post-terms', 'post-content', 'post-excerpt', 'cover', 'post-author',
    'read-more',
  ];

  /**
   * SDC slugs the WordPal Views templates render a Query loop with.
   */
  private const QUERY_SDC_SLUGS = [
    'query', 'post-template', 'query-pagination',
    'query-pagination-previous', 'query-pagination-numbers',
    'query-pagination-next', 'query-no-results',
  ];

  /**
   * The title of each route's Canvas page or page View.
   */
  private const PAGE_TITLES = [
    'home' => 'Home',
    'not_found' => 'Page not found',
    'archive' => 'Archive',
    'tag' => 'Tag archive',
    'category' => 'Category archive',
    'search' => 'Search results',
  ];

  /**
   * Prop formats whose JSON Schema validation rejects a bare "#".
   */
  private const URL_FORMATS = ['uri', 'uri-reference'];

  /**
   * Whether the nodes being built belong to a Template part.
   */
  private bool $inTemplatePart = FALSE;

  /**
   * Whether the nodes being built belong to a Gallery.
   */
  private bool $inGallery = FALSE;

  /**
   * Whether the nodes being built sit inside a Query block.
   */
  private bool $inQuery = FALSE;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly ComponentSourceManager $componentSourceManager,
    private readonly AttributeFlattener $flattener,
    private readonly UuidInterface $uuid,
    private readonly BlockSettings $blockSettings,
    private readonly CommentBlockInputs $commentBlockInputs,
    private readonly TreeHashing $treeHashing,
    private readonly ListingBlockSettings $listingBlockSettings,
    private readonly ComponentInputs $componentInputs,
    private readonly QuerySettingsWriter $querySettingsWriter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function runtimeModule(): string {
    return 'wordpal_canvas_runtime';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Canvas';
  }

  /**
   * {@inheritdoc}
   */
  public function templatePath(EntityInterface $pattern, ?ViewEntityInterface $view): ?string {
    return '/canvas/pattern/' . $pattern->id();
  }

  /**
   * {@inheritdoc}
   */
  public function patternEntityType(): string {
    return Pattern::ENTITY_TYPE_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function pageEntityType(): string {
    return 'canvas_page';
  }

  /**
   * {@inheritdoc}
   */
  public function pageFrameEntityType(): string {
    return 'page_variant';
  }

  /**
   * {@inheritdoc}
   */
  public function supportsQueryLoops(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function unsupportedRoutes(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function requiresPageMapping(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function fullDisplayConfigName(string $bundle): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function runtimePresetIds(string $theme, string $bundle, ContentMapping $mapping): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function writeRuntimePresets(string $theme, string $bundle, ContentMapping $mapping): array {
    return [];
  }

  /**
   * Writes one block tree as a Canvas pattern.
   *
   * @param string $id
   *   Machine name of the pattern.
   * @param string $label
   *   Human name of the pattern.
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The blocks of the pattern.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used by mapped runtime blocks.
   */
  public function writePattern(string $id, string $label, array $nodes, ContentMapping $mapping): Pattern {
    $blockComponents = $this->blockComponentIds($nodes);
    if ($blockComponents !== []) {
      $this->componentSourceManager->generateComponents('block', $blockComponents);
    }
    $items = $this->buildItems($nodes, $mapping, 'Pattern');
    return $this->savePattern($id, $label, $items);
  }

  /**
   * {@inheritdoc}
   */
  public function renderPattern(string $id): ?array {
    $pattern = Pattern::load($id);
    return $pattern?->getComponentTree()->toRenderable($pattern, FALSE);
  }

  /**
   * Writes a pattern whose Query blocks become distinct Views blocks.
   *
   * @return array
   *   "pattern" holds the saved Pattern, and "queries" holds one entry per
   *   Query block, as returned by saveQueryInfrastructure().
   */
  public function writeQueryPattern(string $id, string $label, array $nodes, array $loops, ContentMapping $mapping, string $theme): array {
    ['items' => $items, 'queries' => $queries] = $this->writeQueryOwnerTree($nodes, $loops, $mapping, $theme, $label, $mapping->target('post'), 'Pattern');
    return ['pattern' => $this->savePattern($id, $label, $items), 'queries' => $queries];
  }

  /**
   * Replaces one owned Canvas Pattern with a component tree.
   */
  private function savePattern(string $id, string $label, array $items): Pattern {
    // The command deletes an id's owned outputs (--replace) or refuses to
    // run over unmanaged ones before any write reaches here, so a create()
    // collision is a real bug and fails loudly instead of overwriting.
    $pattern = Pattern::create([
      'id' => $id,
      'uuid' => OwnedEntityUuid::uuid(Pattern::ENTITY_TYPE_ID, $id),
      'label' => $label,
      'component_tree' => $items,
    ]);
    ConfigValidation::assertValid($pattern);
    $pattern->save();
    return $pattern;
  }

  /**
   * Builds and validates one tree's component_tree items.
   *
   * The one place every write method turns a block tree into items before
   * it saves anything: addNodes() collects every reason a block cannot
   * become an item (a Frozen block the render index lacks, a slot mismatch,
   * an enum value the prop lacks), and this throws before the caller creates
   * a single entity. Schema validation of each entity, prop formats
   * included, runs when that entity is saved.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at the top of the tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used by mapped runtime blocks.
   * @param string $label
   *   Names the tree in the exception message, such as "Pattern" or "Home
   *   Template".
   * @param array $queryReplacements
   *   Deterministic views_block component ids for this tree's Query
   *   blocks, keyed by Query object ID. A draft item, patched once its
   *   View is saved, stands in for the Query until then.
   * @param string|null $bundle
   *   The node bundle a content template binds to, or NULL for a Pattern
   *   or page frame, whose blocks always use their own saved content.
   */
  private function buildItems(array $nodes, ContentMapping $mapping, string $label, array $queryReplacements = [], ?string $bundle = NULL): array {
    $built = $this->buildItemsOrSkipped($nodes, $mapping, $queryReplacements, $bundle);
    if ($built['skipped'] !== []) {
      throw new \UnexpectedValueException("$label uses unavailable components: " . implode(', ', $built['skipped']));
    }
    return $built['items'];
  }

  /**
   * Builds one tree's component_tree items, without checking for skips.
   *
   * The shared buildItems() throws right away, naming its own tree.
   * writePageVariant() calls this directly so it can collect every
   * Template part's skips first and name each one in a single aggregated
   * error, instead of stopping at the first part that fails.
   *
   * @return array
   *   "items" holds the component_tree items built so far, and "skipped"
   *   the blocks addNodes() could not place.
   */
  private function buildItemsOrSkipped(array $nodes, ContentMapping $mapping, array $queryReplacements = [], ?string $bundle = NULL): array {
    $items = [];
    $skipped = [];
    $this->addNodes($nodes, NULL, NULL, $items, $skipped, $mapping, $queryReplacements, $bundle);
    return ['items' => $items, 'skipped' => $skipped];
  }

  /**
   * Writes the full content template for a mapped node bundle.
   */
  public function writeContentTemplate(string $bundle, string $viewMode, array $nodes, ContentMapping $mapping, ?string $pageVariant): ContentTemplate {
    if ($viewMode === 'full' && $pageVariant === NULL) {
      throw new \InvalidArgumentException('The full content template requires a page variant.');
    }
    $this->generateContentComponents($nodes);
    $items = $this->buildItems($nodes, $mapping, 'Content Template', [], $bundle);
    return $this->saveContentTemplate($bundle, $viewMode, $items, $pageVariant);
  }

  /**
   * Writes a full content template whose Query blocks become Views blocks.
   *
   * @param string $bundle
   *   The node bundle the template renders.
   * @param string $route
   *   The Template's route, "single" or "page".
   * @param \Drupal\wordpal_convert\Theme\ResolvedTemplate $template
   *   The Template's main tree, Part set, and Query blocks.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param string $theme
   *   The theme slug.
   *
   * @return array
   *   "template" holds the saved ContentTemplate, and "queries" one entry
   *   per Query block, as returned by saveQueryInfrastructure().
   */
  public function writeQueryContentTemplate(string $bundle, string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array {
    $this->generateContentComponents($template->nodes);
    $label = TemplateResolver::templateName($route) . ' Template';
    ['items' => $items, 'queries' => $queries] = $this->writeQueryOwnerTree($template->nodes, $template->loops, $mapping, $theme, ucfirst($route), $mapping->target('post'), $label, NULL, $bundle);
    return [
      'template' => $this->saveContentTemplate($bundle, 'full', $items, $template->partSet->id($theme)),
      'queries' => $queries,
    ];
  }

  /**
   * Generates the components a content template's blocks render through.
   */
  private function generateContentComponents(array $nodes): void {
    $this->componentSourceManager->generateComponents('sdc', array_map(static fn (string $id): string => self::COMPONENT_PROVIDER . ':' . $id, self::DYNAMIC_CONTENT_SLUGS));
    $blockComponents = $this->blockComponentIds($nodes);
    if ($blockComponents !== []) {
      $this->componentSourceManager->generateComponents('block', $blockComponents);
    }
  }

  /**
   * Replaces one owned content template with already-built items.
   */
  private function saveContentTemplate(string $bundle, string $viewMode, array $items, ?string $pageVariant): ContentTemplate {
    $id = "node.$bundle.$viewMode";
    // See savePattern(): the command clears an id's owned outputs first.
    $template = ContentTemplate::create([
      'id' => $id,
      'uuid' => OwnedEntityUuid::uuid(ContentTemplate::ENTITY_TYPE_ID, $id),
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => $bundle,
      'content_entity_type_view_mode' => $viewMode,
      'component_tree' => $items,
    ]);
    if ($pageVariant !== NULL) {
      $template->set('page_variant', $pageVariant);
    }
    $template->setStatus(TRUE);
    ConfigValidation::assertValid($template);
    $template->save();
    return $template;
  }

  /**
   * Writes one page variant from an ordered set of template parts.
   *
   * $routesByPartSet is unused: a page variant carries no conditions here,
   * since Canvas selects a page's frame through the sitewide
   * `canvas.settings.default_page_variant` setting instead (see
   * ConversionRunner::defaultFrame()).
   */
  public function writePageVariant(PartSet $partSet, string $theme, ContentMapping $mapping, array $partTrees, ?string $listingRoute, array $routesByPartSet): array {
    $this->componentSourceManager->generateComponents('block', [
      'system_messages_block',
      ...$this->blockComponentIds($partSet->nodes()),
    ]);
    $loops = [];
    foreach ($partTrees as $tree) {
      $loops = [...$loops, ...$tree['loops']];
    }
    // A mapping without a post target cannot hold a Query, and the runner
    // skips a frame that has one.
    $bundle = $loops === [] ? NULL : $mapping->target('post');
    $prepared = $this->buildQueries($loops, $mapping, $theme, $partSet->label(), $bundle, $listingRoute);
    $items = [];
    $skipped = [];
    foreach ($partSet->placements as $index => $placement) {
      if ($placement['type'] === 'messages') {
        $this->addContentItem('block.system_messages_block', $this->defaultSettings('block.system_messages_block'), NULL, NULL, $items);
        continue;
      }
      if ($placement['type'] === 'marker') {
        $this->addContentItem(Marker::PAGE_CONTENT_COMPONENT_ID, [], NULL, NULL, $items);
        continue;
      }
      // A body placement is the page Template's main area, not a part.
      $this->inTemplatePart = $placement['type'] === 'part';
      try {
        $built = $this->buildItemsOrSkipped($placement['type'] === 'part' ? $partTrees[$index]['nodes'] : $placement['nodes'], $mapping, $prepared['replacements']);
      }
      finally {
        $this->inTemplatePart = FALSE;
      }
      $items = [...$items, ...$built['items']];
      $label = $placement['type'] === 'part' ? $placement['slug'] : 'Drupal-route frame';
      foreach ($built['skipped'] as $block) {
        $skipped[] = "$label: $block";
      }
    }
    if ($skipped !== []) {
      throw new \UnexpectedValueException('Page frame uses unavailable components: ' . implode(', ', $skipped));
    }
    ['items' => $items, 'queries' => $queries] = $this->saveQueries($items, $loops, $prepared, $mapping, $partSet->label(), $bundle);
    $id = $partSet->id($theme);
    // See savePattern(): the command clears an id's owned outputs first.
    $variant = PageVariant::create([
      'id' => $id,
      'uuid' => OwnedEntityUuid::uuid(PageVariant::ENTITY_TYPE_ID, $id),
      'label' => $partSet->label() . ' page frame',
      'description' => 'Template parts converted from the WordPress theme.',
      'component_tree' => $items,
      'third_party_settings' => ['wordpal_canvas_runtime' => ['theme' => ThemeGenerator::themeId($theme)]],
    ]);
    $variant->setStatus(TRUE);
    ConfigValidation::assertValid($variant);
    $variant->save();
    return ['variant' => $variant, 'queries' => $queries];
  }

  /**
   * {@inheritdoc}
   *
   * A page variant carries no conditions on this target (see
   * writePageVariant()), so there is never a route to report.
   */
  public function pageVariantNotes(array $routesByPartSet, ContentMapping $mapping): array {
    return [];
  }

  /**
   * Writes a page Template's query item templates, Views, and Canvas page.
   *
   * The route is one of TemplatePage::ROUTES.
   *
   * @param string $route
   *   The route the page renders.
   * @param string $bundle
   *   The node bundle the Query lists.
   * @param \Drupal\wordpal_convert\Theme\ResolvedTemplate $template
   *   The Template's main tree, Part set, and Query blocks.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param string $theme
   *   The theme slug.
   *
   * @return array
   *   "page" holds the saved Canvas Page, and "queries" holds one entry per
   *   Query block, as returned by saveQueryInfrastructure().
   */
  public function writeQueryPage(string $route, string $bundle, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array {
    ['items' => $items, 'queries' => $queries] = $this->writeQueryOwnerTree($template->nodes, $template->loops, $mapping, $theme, self::PAGE_TITLES[$route], $bundle, TemplateResolver::templateName($route) . ' Template');
    $page = $this->saveTemplatePage($route, $theme, $template->partSet->id($theme), $items);
    return ['page' => $page, 'queries' => $queries];
  }

  /**
   * Writes a listing Template as its route's View and Canvas Pattern.
   *
   * The Pattern holds the Template's main tree, Query blocks placed as
   * their Views blocks. The View's page display takes the route's path and
   * renders the Pattern through the wordpal_pattern area. The route is
   * "archive" or "search". The View is saved disabled: activation enables
   * it, since an enabled View takes over its path.
   *
   * @param string $route
   *   The listing route.
   * @param \Drupal\wordpal_convert\Theme\ResolvedTemplate $template
   *   The Template's main tree and Query blocks.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param string $theme
   *   The theme slug.
   * @param array<string, string> $termPatterns
   *   On the archive route, the Patterns writeTermTemplate() saved for the
   *   term pages of one vocabulary, keyed by vocabulary id.
   *
   * @return array
   *   "pattern" and "view" hold the saved Pattern and page View, and
   *   "queries" one entry per Query block, as saveQueryInfrastructure()
   *   returns them.
   */
  public function writeTemplateView(string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme, array $termPatterns = []): array {
    $label = self::PAGE_TITLES[$route];
    ['items' => $items, 'queries' => $queries] = $this->writeQueryOwnerTree($template->nodes, $template->loops, $mapping, $theme, $label, $mapping->target('post'), "$route Template", $route);
    $ids = $this->templateOutputIds($theme, $route);
    $values = QueryViewConfig::templateView($ids['view'], $route, $label, $ids['pattern'], $mapping, $termPatterns, self::AREA_PLUGIN, []);
    $values['uuid'] = OwnedEntityUuid::uuid('view', $ids['view']);
    $view = View::create($values);
    $view->disable();
    // Canvas selects a frame for an entity route only; the runtime module
    // selects this one for the View's own route.
    $view->setThirdPartySetting('wordpal_canvas_runtime', 'frame', $template->partSet->id($theme));
    ConfigValidation::assertValid($view);
    $pattern = $this->savePattern($ids['pattern'], "$label Template", $items);
    $pattern->disable()->save();
    $view->save();
    return ['pattern' => $pattern, 'view' => $view, 'queries' => $queries];
  }

  /**
   * Writes a tag or category Template as the Pattern its term pages render.
   *
   * The archive route's View renders it for the mapped vocabulary. An
   * inheriting Query lists the route term's posts, as an archive's does, and
   * any other Query lists its own.
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
   *   "pattern" holds the saved Pattern, and "queries" one entry per Query
   *   block, as saveQueryInfrastructure() returns them.
   */
  public function writeTermTemplate(string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array {
    $label = self::PAGE_TITLES[$route];
    ['items' => $items, 'queries' => $queries] = $this->writeQueryOwnerTree($template->nodes, $template->loops, $mapping, $theme, $label, $mapping->target('post'), "$route Template", 'archive');
    $pattern = $this->savePattern($this->templateOutputIds($theme, $route)['pattern'], "$label Template", $items);
    $pattern->disable()->save();
    return ['pattern' => $pattern, 'queries' => $queries];
  }

  /**
   * Returns the ids a listing Template's Pattern and page View are saved under.
   *
   * A tag or category Template saves only its Pattern.
   */
  public function templateOutputIds(string $theme, string $route): array {
    return [
      'pattern' => ThemeGenerator::themeId($theme) . "_{$route}_template",
      'view' => 'wordpal_' . TreeHashing::idPrefix($theme) . "_$route",
    ];
  }

  /**
   * Builds, then saves, an outer tree whose Query blocks become Views.
   *
   * Build, then save: every Query's card tree, empty-result tree, and View
   * settings are built and checked for slot mismatches, prop values and
   * Frozen blocks missing from the render index first, with the
   * outer tree itself, all before a single entity is created. A generated
   * views_block Component only resolves once its own View is saved, so the
   * outer tree's Query placements start as draft items carrying only the
   * deterministic component id queryOutputIds() already knows; the save
   * phase below patches each one with its real version and inputs once its
   * View exists. Nothing from the save phase onward can raise a $skipped
   * error, because buildItems() already ruled that out for every tree.
   * Schema validation runs as each entity is saved, so a prop format a
   * later entity violates fails after the earlier Query outputs exist.
   *
   * Two Query blocks with the same card tree, empty-result tree, and
   * settings get the same ids from queryOutputIds(), so the save phase
   * below saves their shared View, view mode, template, and Pattern once
   * and reuses them for every later occurrence.
   *
   * $host names the node bundle a content template binds its outer tree
   * to, and is NULL for a Pattern or a Canvas page.
   *
   * @return array
   *   "items" holds the outer tree's component_tree items, and "queries"
   *   holds one entry per Query block, as returned by
   *   saveQueryInfrastructure().
   */
  private function writeQueryOwnerTree(array $nodes, array $loops, ContentMapping $mapping, string $theme, string $ownerLabel, string $bundle, string $label, ?string $route = NULL, ?string $host = NULL): array {
    $prepared = $this->buildQueries($loops, $mapping, $theme, $ownerLabel, $bundle, $route);
    $items = $this->buildItems($nodes, $mapping, $label, $prepared['replacements'], $host);
    return $this->saveQueries($items, $loops, $prepared, $mapping, $ownerLabel, $bundle);
  }

  /**
   * Builds every Query's outputs, saving none of them.
   *
   * This is writeQueryOwnerTree()'s build phase, apart so a frame can build
   * the Queries of several template parts at once.
   *
   * @param \Drupal\wordpal_convert\Theme\QueryLoop[] $loops
   *   The Query blocks of every tree sharing these outputs.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param string $theme
   *   The theme slug.
   * @param string $ownerLabel
   *   Human name of the owner, labelling the generated Views.
   * @param string|null $bundle
   *   The node bundle the Queries list.
   * @param string|null $route
   *   The listing route whose Template holds the trees, or NULL.
   *
   * @return array{ids: array[], built: array[], routes: array<int, ?string>, replacements: array<int, string>}
   *   Each Query's output ids, built trees and route, and the component id
   *   that stands in for each Query block, keyed by the block's object id.
   */
  private function buildQueries(array $loops, ContentMapping $mapping, string $theme, string $ownerLabel, ?string $bundle, ?string $route): array {
    $ids = [];
    $built = [];
    $replacements = [];
    $routes = [];
    foreach ($loops as $index => $loop) {
      $routes[$index] = $loop->route($route);
      $ids[$index] = $this->queryOutputIds($theme, $loop, $bundle, $routes[$index]);
      $built[$index] = $this->buildQueryItems($loop, $bundle, $mapping, $ownerLabel, $routes[$index]);
      $replacements[spl_object_id($loop->query)] = $ids[$index]['component'];
    }
    return ['ids' => $ids, 'built' => $built, 'routes' => $routes, 'replacements' => $replacements];
  }

  /**
   * Saves the Queries buildQueries() built, and patches their items.
   *
   * Every tree already built by now, so nothing here throws for a slot
   * mismatch, an enum value, or a missing Frozen block. Each save still
   * runs schema validation.
   *
   * @param array $items
   *   The component_tree items of every tree the Queries replace blocks in.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop[] $loops
   *   The Query blocks buildQueries() built.
   * @param array $prepared
   *   What buildQueries() returned.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param string $ownerLabel
   *   Human name of the owner, labelling the generated Views.
   * @param string|null $bundle
   *   The node bundle the Queries list.
   *
   * @return array
   *   "items" holds $items with each Query's View block filled in, and
   *   "queries" holds one entry per Query block.
   */
  private function saveQueries(array $items, array $loops, array $prepared, ContentMapping $mapping, string $ownerLabel, ?string $bundle): array {
    ['ids' => $ids, 'built' => $built, 'routes' => $routes] = $prepared;
    $queries = [];
    $patches = [];
    foreach ($loops as $index => $loop) {
      $query = $this->saveQueryInfrastructure($bundle, $loop, $ownerLabel, $ids[$index], $built[$index], $mapping, $routes[$index]);
      $queries[] = $query;
      $patches[$ids[$index]['component']] = $query['replacement'];
    }
    foreach ($items as &$item) {
      $patch = $patches[$item['component_id']] ?? NULL;
      if ($patch !== NULL) {
        $item['inputs'] = $patch[1];
        $item['component_version'] = Component::load($item['component_id'])->getActiveVersion();
      }
    }
    unset($item);
    return ['items' => $items, 'queries' => $queries];
  }

  /**
   * Builds and validates one Query's card tree, empty tree, and settings.
   *
   * Nothing here saves an entity: it builds and checks everything
   * saveQueryInfrastructure() later persists, so a slot mismatch, an enum
   * value the prop lacks, or a Frozen block missing from the render index
   * anywhere in a Query is caught before any entity for it, or the tree it
   * belongs to, exists. Schema validation runs at save.
   *
   * @return array
   *   "card" holds the post card's component_tree items, "page_card" the
   *   page bundle's card on the search route or NULL, "empty" holds the
   *   empty-result Pattern's items (or NULL when the Query has none), and
   *   "query_settings" holds the View's wordpal.query third-party setting.
   */
  private function buildQueryItems(QueryLoop $loop, string $bundle, ContentMapping $mapping, string $ownerLabel, ?string $route): array {
    $this->componentSourceManager->generateComponents('sdc', array_map(static fn (string $id): string => self::COMPONENT_PROVIDER . ':' . $id, self::DYNAMIC_CONTENT_SLUGS));
    $cardBlockComponents = $this->blockComponentIds($loop->postTemplate->children);
    if ($cardBlockComponents !== []) {
      $this->componentSourceManager->generateComponents('block', $cardBlockComponents);
    }
    $this->inQuery = TRUE;
    try {
      $card = $this->buildItems($loop->postTemplate->children, $mapping, "$ownerLabel query card", [], $bundle);
    }
    finally {
      $this->inQuery = FALSE;
    }
    // WordPress search results list pages with the same Post Template card.
    $pageCard = NULL;
    if ($route === 'search' && $mapping->binds('page')) {
      $this->inQuery = TRUE;
      try {
        $pageCard = $this->buildItems($loop->postTemplate->children, $mapping, "$ownerLabel query page card", [], $mapping->target('page'));
      }
      finally {
        $this->inQuery = FALSE;
      }
    }

    $empty = NULL;
    if ($loop->noResults !== NULL) {
      $emptyBlockComponents = $this->blockComponentIds([$loop->noResults]);
      if ($emptyBlockComponents !== []) {
        $this->componentSourceManager->generateComponents('block', $emptyBlockComponents);
      }
      $this->inQuery = TRUE;
      try {
        $empty = $this->buildItems([$loop->noResults], $mapping, "$ownerLabel query empty state");
      }
      finally {
        $this->inQuery = FALSE;
      }
    }
    return [
      'card' => $card,
      'page_card' => $pageCard,
      'empty' => $empty,
      'query_settings' => $this->querySettings($loop, $mapping, $route),
    ];
  }

  /**
   * Saves one Query's View, view mode, card template, and empty Pattern.
   *
   * Content-hashed ids (queryOutputIds()) make a shared View, view mode,
   * template, or Pattern collide with what an earlier Query in this same
   * conversion already saved. Entity::load() tells the two cases apart: a
   * hit reuses the earlier save, a miss creates it. The View is built and
   * validated before any of the three it can reference is saved, so a
   * rejected View leaves nothing behind.
   *
   * @param string $bundle
   *   The node bundle the Query lists.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop $loop
   *   The Query's dynamic pieces.
   * @param string $ownerLabel
   *   Human name of the Pattern or "Home" this Query belongs to. Labels the
   *   generated View, view mode, and Pattern the first time this content
   *   hash saves them; a later, shared occurrence keeps the first label.
   * @param array $ids
   *   The output ids queryOutputIds() returned for this Query.
   * @param array $built
   *   The "card", "page_card", "empty" and "query_settings"
   *   buildQueryItems() returned.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping a search View reads its index and parameter from.
   * @param string|null $route
   *   The listing route whose Template holds the Query, or NULL.
   */
  private function saveQueryInfrastructure(string $bundle, QueryLoop $loop, string $ownerLabel, array $ids, array $built, ContentMapping $mapping, ?string $route): array {
    // Shares this View between Query blocks of the current conversion only:
    // the content mapping is fixed for one wordpal:convert run, ids carry
    // the theme id, a second conversion collides and refuses, and --replace
    // deletes the first conversion's owned outputs before any write here.
    // A load() hit is never a stale entity from an earlier run.
    $view = View::load($ids['view']);
    if ($view === NULL) {
      $values = QueryViewConfig::queryView(
        $ids['view'],
        "$ownerLabel query",
        $bundle,
        $loop,
        $route,
        $mapping,
        QueryRow::forRoute($route, $ids['view_mode'], $mapping),
        self::AREA_PLUGIN,
        $ids['empty_pattern'],
        [],
      );
      $values['uuid'] = OwnedEntityUuid::uuid('view', $ids['view']);
      $view = View::create($values);
      $this->querySettingsWriter->write($view, $built['query_settings']);
      ConfigValidation::assertValid($view);
    }

    [$viewModeEntity, $template] = $this->saveQueryViewMode($bundle, $ownerLabel, $ids['view_mode'], $built['card']);
    $pageTemplate = NULL;
    if ($built['page_card'] !== NULL) {
      $pageBundle = $mapping->target('page');
      $pageTemplate = ContentTemplate::load("node.$pageBundle.{$ids['view_mode']}")
        ?? $this->saveContentTemplate($pageBundle, $ids['view_mode'], $built['page_card'], NULL);
    }
    $emptyPattern = $ids['empty_pattern'] !== NULL
      ? $this->saveQueryEmptyPattern($ownerLabel, $ids['empty_pattern'], $built['empty'])
      : NULL;

    $componentId = $ids['component'];
    if ($view->isNew()) {
      $view->save();
    }
    $component = Component::load($componentId);
    if ($component === NULL) {
      $this->componentSourceManager->generateComponents('block', ['views_block:' . substr($componentId, strlen(self::VIEWS_BLOCK_COMPONENT_PREFIX))]);
      $component = Component::load($componentId)->enable();
      ConfigValidation::assertValid($component);
      $component->save();
    }
    $viewInputs = $this->defaultSettings($componentId);
    $viewInputs['items_per_page'] = $loop->perPage;
    return [
      'view_mode' => $viewModeEntity,
      'template' => $template,
      'page_template' => $pageTemplate,
      'view' => $view,
      'component' => $component,
      'empty_pattern' => $emptyPattern,
      'replacement' => [$componentId, $viewInputs],
    ];
  }

  /**
   * Saves one card tree's view mode and content template once.
   *
   * A later Query whose card hashes the same reuses what this saved.
   */
  private function saveQueryViewMode(string $bundle, string $ownerLabel, string $viewMode, array $card): array {
    $viewModeEntity = EntityViewMode::load("node.$viewMode");
    if ($viewModeEntity !== NULL) {
      return [$viewModeEntity, ContentTemplate::load("node.$bundle.$viewMode")];
    }
    // See savePattern(): the command clears an id's owned outputs first.
    $viewModeEntity = EntityViewMode::create([
      'id' => "node.$viewMode",
      'uuid' => OwnedEntityUuid::uuid('entity_view_mode', "node.$viewMode"),
      'label' => "$ownerLabel query item",
      'targetEntityType' => 'node',
    ]);
    ConfigValidation::assertValid($viewModeEntity);
    $viewModeEntity->save();
    return [$viewModeEntity, $this->saveContentTemplate($bundle, $viewMode, $card, NULL)];
  }

  /**
   * Saves one empty-result tree's disabled Pattern once.
   *
   * A later Query whose empty-result tree hashes the same reuses what this
   * saved.
   */
  private function saveQueryEmptyPattern(string $ownerLabel, string $emptyPatternId, array $empty): Pattern {
    $emptyPattern = Pattern::load($emptyPatternId);
    if ($emptyPattern !== NULL) {
      return $emptyPattern;
    }
    $emptyPattern = $this->savePattern($emptyPatternId, "$ownerLabel query empty state", $empty);
    $emptyPattern->disable()->save();
    return $emptyPattern;
  }

  /**
   * Returns one listing block's kind and resolved settings, or NULL.
   *
   * Checked before componentId(): a recognized listing block resolves to a
   * listing View's views_block, saved by saveListingInfrastructure(), not to
   * its own block plugin component.
   */
  private function listingSettings(BlockNode $node, ContentMapping $mapping): ?array {
    // A Page List inside a Navigation is never reached here: addNodes() and
    // frozenNodes() never recurse into a block-plugin component's children,
    // and core/navigation's own pluginInputs() folds a Page List child into
    // the navigation's own page_list setting instead.
    return $this->listingBlockSettings->resolve($node, $mapping);
  }

  /**
   * Saves one listing kind and settings' View and views_block Component.
   *
   * Two listing blocks with the same kind and settings get the same ids
   * from listingOutputIds(), so a later occurrence in this conversion
   * reuses the first save.
   *
   * @return string
   *   The saved views_block Component's id.
   */
  private function saveListingInfrastructure(string $theme, string $kind, array $settings): string {
    $ids = $this->listingOutputIds($theme, $kind, $settings);
    $view = View::load($ids['view']);
    if ($view === NULL) {
      $view = ListingViewConfig::create($kind, $ids['view'], $settings);
      ConfigValidation::assertValid($view);
      $view->save();
    }
    $componentId = $ids['component'];
    $component = Component::load($componentId);
    if ($component === NULL) {
      $this->componentSourceManager->generateComponents('block', ['views_block:' . substr($componentId, strlen(self::VIEWS_BLOCK_COMPONENT_PREFIX))]);
      $component = Component::load($componentId)->enable();
      ConfigValidation::assertValid($component);
      $component->save();
    }
    return $componentId;
  }

  /**
   * {@inheritdoc}
   */
  public function queryCardEntityTypes(): array {
    return ['view_mode' => 'entity_view_mode', 'content_template' => 'content_template', 'component' => 'component'];
  }

  /**
   * Returns the deterministic ids one Query's outputs would be saved under.
   *
   * The view mode and template id hashes the card tree (the Query's Post
   * Template children). The View id hashes the bundle, paging, sort,
   * sticky filter, and the whole Query subtree, which includes the card,
   * pagination, and empty-result content the View's own config and
   * `wordpal.query` third-party setting are built from. Two Query blocks
   * with the same values under each hash get the same ids, so
   * saveQueryInfrastructure() saves their shared outputs once.
   */
  public function queryOutputIds(string $theme, QueryLoop $loop, string $bundle, ?string $route = NULL): array {
    $prefix = TreeHashing::idPrefix($theme);
    $viewMode = "wordpal_{$prefix}_card_" . $this->treeHashing->treeKey($loop->postTemplate->children);
    $view = "wordpal_{$prefix}_view_" . $this->treeHashing->queryViewKey($loop, $bundle, $route);
    return [
      'view_mode' => $viewMode,
      'view' => $view,
      'component' => self::VIEWS_BLOCK_COMPONENT_PREFIX . "$view-block_1",
      'empty_pattern' => $loop->noResults !== NULL ? "{$prefix}_empty_" . $this->treeHashing->treeKey([$loop->noResults]) : NULL,
    ];
  }

  /**
   * Returns the deterministic ids one listing kind and settings save under.
   *
   * Two listing blocks with the same kind and settings hash the same, so
   * saveListingInfrastructure() saves their shared View and Component once.
   *
   * @param string $theme
   *   The generated theme id (ContentMapping::themeId()), already safe to
   *   use as an id fragment.
   * @param string $kind
   *   The listing kind: latest_posts, page_list, or latest_comments.
   * @param array $settings
   *   The listing block's resolved settings.
   */
  public function listingOutputIds(string $theme, string $kind, array $settings): array {
    $view = ListingViewConfig::viewId($theme, $kind, $settings);
    return [
      'view' => $view,
      'component' => self::VIEWS_BLOCK_COMPONENT_PREFIX . "$view-block_1",
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function listingComponentEntityType(): ?string {
    return 'component';
  }

  /**
   * Finds the listing View and Component ids a tree's listing blocks save.
   *
   * One entry per listing block occurrence; two occurrences with the same
   * kind and settings return the same ids, as saveListingInfrastructure()
   * shares their save.
   *
   * @return array[]
   *   "view" and "component" ids, as listingOutputIds() returns them.
   */
  public function listingOutputs(array $nodes, ContentMapping $mapping): array {
    $ids = [];
    foreach ($nodes as $node) {
      if (TreeConcepts::dropped(TreeConcepts::concept($node), $mapping)) {
        // addNodes() drops this block before it reaches listingSettings(),
        // so no View or Component ever saves for it.
        continue;
      }
      $listing = $this->listingSettings($node, $mapping);
      if ($listing !== NULL) {
        $ids[] = $this->listingOutputIds($mapping->themeId(), $listing['kind'], $listing['settings']);
        continue;
      }
      if ($this->blockComponentId($node->name) === NULL) {
        // A block-plugin component's own settings fold its children in
        // (a navigation's Page List, a comments fragment): addNodes()
        // never recurses into them, so neither does this tree walk.
        $ids = [...$ids, ...$this->listingOutputs($node->children, $mapping)];
      }
    }
    return $ids;
  }

  /**
   * Writes a static home or 404 Template as its Canvas page.
   *
   * The route is one of TemplatePage::ROUTES.
   */
  public function writeStaticPage(string $route, array $nodes, ContentMapping $mapping, string $theme, string $pageVariant): Page {
    $items = $this->buildItems($nodes, $mapping, TemplateResolver::templateName($route) . ' Template');
    return $this->saveTemplatePage($route, $theme, $pageVariant, $items);
  }

  /**
   * Saves one route's owned Canvas page.
   */
  private function saveTemplatePage(string $route, string $theme, string $pageVariant, array $items): Page {
    $themeId = ThemeGenerator::themeId($theme);
    // See savePattern(): the command clears an id's owned outputs first.
    $page = Page::create([
      'title' => self::PAGE_TITLES[$route],
      'uuid' => TemplatePage::uuid($themeId, $route),
      'description' => TemplatePage::description($route),
      'status' => TRUE,
      'path' => ['alias' => TemplatePage::alias($themeId, $route)],
      'page_variant' => $pageVariant,
      'components' => $items,
    ]);
    $violations = $page->validate();
    if ($violations->count() > 0) {
      throw new \UnexpectedValueException((string) $violations);
    }
    $page->save();
    return $page;
  }

  /**
   * Returns component inputs consumed by the WordPal Views templates.
   *
   * QuerySettingsWriter builds every key Display Builder's ListingWriter
   * also writes; `query` and `layout` render only Canvas's own
   * `wordpal:query` embed, so this method alone adds them.
   */
  private function querySettings(QueryLoop $loop, ContentMapping $mapping, ?string $route): array {
    $this->componentSourceManager->generateComponents('sdc', array_map(static fn (string $slug): string => "wordpal:$slug", self::QUERY_SDC_SLUGS));
    $skipped = [];
    $settings = $this->querySettingsWriter->build($loop, $mapping, $route, $skipped);
    $settings['query'] = $this->inputs($loop->query, $skipped);
    $this->inQuery = TRUE;
    try {
      $settings['layout'] = $this->queryRuntimeTree($loop->query->children, $mapping, $skipped);
    }
    finally {
      $this->inQuery = FALSE;
    }
    if ($skipped !== []) {
      throw new \UnexpectedValueException('Query settings use unsupported values: ' . implode(', ', $skipped));
    }
    return $settings;
  }

  /**
   * Returns whether a bundle is the mapping's post bundle.
   *
   * A mapping override needs post only when some Template requires it, so
   * a bundle is never the post bundle when the mapping has none.
   */
  private function isPostBundle(string $bundle, ContentMapping $mapping): bool {
    return $mapping->has('post') && $bundle === $mapping->target('post');
  }

  /**
   * Returns TRUE when a content template block binds a field the bundle lacks.
   *
   * The Post Content and Post Excerpt blocks bind the page bundle's
   * `page_body` and `page_excerpt`, the concepts that bundle's fields map to.
   */
  private function bindsMissingField(BlockNode $node, ?string $concept, string $bundle, ContentMapping $mapping): bool {
    if ($node->name === 'core/post-content') {
      $concept = $this->isPostBundle($bundle, $mapping) ? 'post_body' : 'page_body';
    }
    elseif ($node->name === 'core/post-excerpt' && !$this->isPostBundle($bundle, $mapping)) {
      $concept = 'page_excerpt';
    }
    elseif (!in_array($node->name, [
      'core/post-featured-image', 'core/post-excerpt', 'core/post-terms', 'core/cover', 'core/media-text',
    ], TRUE) || $concept === NULL) {
      return FALSE;
    }
    return $this->querySettingsWriter->bundleField($mapping, $concept, $bundle) === NULL;
  }

  /**
   * Runs a callback with the flags a node's children sit under.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The parent block.
   * @param callable $walk
   *   Walks the children and returns what it builds.
   */
  private function inChildContext(BlockNode $node, callable $walk): mixed {
    $context = [$this->inTemplatePart, $this->inGallery, $this->inQuery];
    [$this->inTemplatePart, $this->inGallery, $this->inQuery] = ComponentInputs::childContext($node, ...$context);
    try {
      return $walk();
    }
    finally {
      [$this->inTemplatePart, $this->inGallery, $this->inQuery] = $context;
    }
  }

  /**
   * Serializes the ordered Query children around runtime View markers.
   */
  private function queryRuntimeTree(array $nodes, ContentMapping $mapping, array &$skipped): array {
    $tree = [];
    foreach ($nodes as $node) {
      if ($node->name === 'core/post-template') {
        $tree[] = ['marker' => 'rows'];
        continue;
      }
      if ($node->name === 'core/query-pagination') {
        $tree[] = ['marker' => 'pager'];
        continue;
      }
      if ($node->name === 'core/query-no-results') {
        $tree[] = ['marker' => 'empty'];
        continue;
      }
      $componentId = $this->componentId($node);
      if ($componentId === NULL) {
        $frozen = $this->frozenProps($node, $skipped);
        if ($frozen !== NULL && $frozen['queries'] !== []) {
          $skipped[] = "$node->name: it holds a Query inside a Query loop";
        }
        elseif ($frozen !== NULL) {
          $tree[] = [
            'component' => self::COMPONENT_PROVIDER . ':' . FrozenBlock::SLUG,
            'props' => $frozen['props'],
            'children' => [],
          ];
        }
        continue;
      }
      if (!str_starts_with($componentId, 'sdc.' . self::COMPONENT_PROVIDER . '.')) {
        $skipped[] = $node->name;
        continue;
      }
      $props = $this->inputs($node, $skipped) + ComponentInputs::mapped($node, $mapping, $this->inQuery);
      $tree[] = [
        'component' => self::COMPONENT_PROVIDER . ':' . $node->slug(),
        'props' => $props,
        'children' => $this->inChildContext($node, function () use ($node, $mapping, &$skipped): array {
          return $this->queryRuntimeTree($node->children, $mapping, $skipped);
        }),
      ];
    }
    return $tree;
  }

  /**
   * Returns the HTML of every Frozen block in a tree, keyed by its key.
   *
   * @return array<string, string>
   *   What the generated theme stores for the tree's Frozen blocks.
   */
  public function frozenHtml(array $nodes, ContentMapping $mapping): array {
    return array_column($this->storedFrozenNodes($nodes, $mapping), 'html', 'key');
  }

  /**
   * Returns the name of every Frozen block in a tree, keyed by its key.
   *
   * @return array<string, string>
   *   Block names keyed by the key of their HTML.
   */
  public function frozenKeys(array $nodes, ContentMapping $mapping): array {
    return array_column($this->storedFrozenNodes($nodes, $mapping), 'name', 'key');
  }

  /**
   * Returns the key, name and file HTML of each Frozen block the theme stores.
   */
  private function storedFrozenNodes(array $nodes, ContentMapping $mapping): array {
    return FrozenHoles::stored($this->frozenNodes($nodes, $mapping));
  }

  /**
   * Returns the blocks the write path freezes, in tree order.
   *
   * The componentId() method decides here and in addNodes() and
   * queryRuntimeTree() alike, so this lists exactly the blocks they freeze.
   * Canvas discovers block and SDC Component config at module install and
   * cache rebuild, so it exists before any conversion runs; a kernel test
   * that calls this directly generates or discovers the components its
   * tree needs.
   *
   * A Frozen block's inner blocks are part of its HTML. A block plugin
   * component reads its inner blocks as settings, except core/comments,
   * whose inner blocks CommentBlockInputs::layout() turns into a layout.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at one level of the tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, whose dropped concepts write no block.
   * @param bool $comments
   *   Whether the blocks sit in a core/comments layout, where comment
   *   fragment blocks are markers and no content concept is dropped.
   */
  public function frozenNodes(array $nodes, ContentMapping $mapping, bool $comments = FALSE): array {
    $frozen = [];
    foreach ($nodes as $node) {
      if ($comments && array_key_exists($node->name, $this->commentBlockInputs->settingKeys())) {
        $frozen = [...$frozen, ...$this->frozenNodes($node->children, $mapping, TRUE)];
        continue;
      }
      if ($node->name === DrupalRouteFrame::MAIN_CONTENT || (!$comments && TreeConcepts::dropped(TreeConcepts::concept($node), $mapping))) {
        continue;
      }
      if ($this->listingSettings($node, $mapping) !== NULL) {
        // A listing block resolves to a listing View's views_block, not to
        // componentId()'s block-plugin or SDC lookup.
        continue;
      }
      $componentId = $this->componentId($node);
      if ($componentId === NULL) {
        // The Views in the block's holes render the blocks inside them.
        $frozen = [...$frozen, $node, ...$this->frozenNodes(FrozenHoles::queries($node->children), $mapping, $comments)];
      }
      elseif (in_array($node->name, CommentBlockInputs::BLOCK_NAMES, TRUE)) {
        $frozen = [...$frozen, ...$this->frozenNodes($node->children, $mapping, TRUE)];
      }
      elseif (!str_starts_with($componentId, 'block.')) {
        $frozen = [...$frozen, ...$this->frozenNodes($node->children, $mapping, $comments)];
      }
    }
    return $frozen;
  }

  /**
   * Adds one component tree item and returns its UUID.
   */
  private function addContentItem(string $componentId, array $inputs, ?string $parentUuid, ?string $slot, array &$items): string {
    $component = Component::load($componentId);
    if ($component === NULL) {
      throw new \UnexpectedValueException("Canvas component $componentId is unavailable.");
    }
    $uuid = $this->uuid->generate();
    $item = [
      'uuid' => $uuid,
      'component_id' => $componentId,
      'component_version' => $component->getActiveVersion(),
      'inputs' => $inputs,
    ];
    if ($parentUuid !== NULL) {
      $item['parent_uuid'] = $parentUuid;
      $item['slot'] = $slot;
    }
    $items[] = $item;
    return $uuid;
  }

  /**
   * Returns field bindings for one dynamic WordPress block.
   */
  private function dynamicInputs(BlockNode $node, string $bundle, ContentMapping $mapping): ?array {
    $skipped = [];
    $staticInputs = $this->inputs($node, $skipped);
    if ($skipped !== []) {
      throw new \UnexpectedValueException('Unsupported bound block values: ' . implode(', ', $skipped));
    }
    return $this->blockSettings->dynamicFieldBindings($node, $bundle, $mapping, $staticInputs, $this->isPostBundle($bundle, $mapping));
  }

  /**
   * Turns block nodes into component tree items, depth first.
   *
   * One walker for every tree WordPal writes: a Pattern, a page frame, or
   * (with $bundle set) a content template, where a handful of blocks bind
   * node fields instead of the block's own saved static content.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at one level of the tree.
   * @param string|null $parentUuid
   *   UUID of the component holding them, or NULL at the top level.
   * @param string|null $slot
   *   The slot of that component they sit in.
   * @param array $items
   *   Collects the tree items, in render order.
   * @param string[] $skipped
   *   Collects the blocks left out.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used by mapped runtime blocks.
   * @param array $queryReplacements
   *   Deterministic views_block component ids for this tree's Query
   *   blocks, keyed by Query object ID. Its View does not exist yet when
   *   this runs (see writeQueryOwnerTree()), so a Query becomes a draft
   *   item here, patched with its real version and inputs once saved.
   * @param string|null $bundle
   *   The node bundle a content template binds to, or NULL for a Pattern
   *   or page frame, whose blocks always use their own saved content.
   */
  private function addNodes(array $nodes, ?string $parentUuid, ?string $slot, array &$items, array &$skipped, ContentMapping $mapping, array $queryReplacements = [], ?string $bundle = NULL): void {
    foreach ($nodes as $node) {
      $queryComponentId = $queryReplacements[spl_object_id($node)] ?? NULL;
      if ($node->name === 'core/query' && $queryComponentId !== NULL) {
        $item = [
          'uuid' => $this->uuid->generate(),
          'component_id' => $queryComponentId,
          'component_version' => NULL,
          'inputs' => [],
        ];
        if ($parentUuid !== NULL) {
          $item['parent_uuid'] = $parentUuid;
          $item['slot'] = $slot;
        }
        $items[] = $item;
        continue;
      }
      if ($node->name === DrupalRouteFrame::MAIN_CONTENT) {
        $this->addContentItem(Marker::PAGE_CONTENT_COMPONENT_ID, [], $parentUuid, $slot, $items);
        continue;
      }
      $concept = TreeConcepts::concept($node);
      if (TreeConcepts::dropped($concept, $mapping)) {
        $mapping->recordDroppedBlocks($concept);
        continue;
      }
      if ($bundle !== NULL && $this->bindsMissingField($node, $concept, $bundle, $mapping)) {
        // A page bundle without the field has nothing for the block to show.
        continue;
      }
      if ($node->name === 'core/post-author' && ($node->attributes['showBio'] ?? FALSE) && TreeConcepts::dropped('author_biography', $mapping)) {
        // The Author block shows the biography the mapping drops.
        $mapping->recordDroppedBlocks('author_biography');
      }
      $listing = $this->listingSettings($node, $mapping);
      if ($listing !== NULL) {
        $componentId = $this->saveListingInfrastructure($mapping->themeId(), $listing['kind'], $listing['settings']);
        $this->addContentItem($componentId, $this->defaultSettings($componentId), $parentUuid, $slot, $items);
        continue;
      }
      $componentId = $this->componentId($node);
      if ($componentId === NULL) {
        $this->addFrozenItem($node, $parentUuid, $slot, $items, $skipped, $mapping, $queryReplacements, $bundle);
        continue;
      }
      // Dynamic field bindings apply only to SDC components; a block-plugin
      // component such as core/comments has no SDC schema for inputs() to read.
      $dynamic = $bundle !== NULL && !str_starts_with($componentId, 'block.') ? $this->dynamicInputs($node, $bundle, $mapping) : NULL;
      $inputs = $dynamic ?? (str_starts_with($componentId, 'block.')
        ? $this->blockInputs($node, $componentId, $mapping)
        : $this->inputs($node, $skipped) + ComponentInputs::mapped($node, $mapping, $this->inQuery));
      $uuid = $this->addContentItem($componentId, $inputs, $parentUuid, $slot, $items);

      if (str_starts_with($componentId, 'block.')) {
        continue;
      }

      $childSlot = $this->slotName($node);
      if ($node->children !== [] && $childSlot !== NULL) {
        $this->inChildContext($node, function () use ($node, $uuid, $childSlot, &$items, &$skipped, $mapping, $queryReplacements, $bundle): void {
          $this->addNodes(TreeConcepts::childNodes($node), $uuid, $childSlot, $items, $skipped, $mapping, $queryReplacements, $bundle);
        });
      }
      elseif ($node->children !== []) {
        $skipped[] = $node->name . ': its component holds no slot';
      }
    }
  }

  /**
   * Adds a block with no component as one Frozen block item.
   *
   * The item holds the key of the theme file with the HTML WordPress rendered
   * for the block and its inner blocks, so they place no items of their own,
   * except a Query, whose View fills the item's hole slot.
   */
  private function addFrozenItem(BlockNode $node, ?string $parentUuid, ?string $slot, array &$items, array &$skipped, ContentMapping $mapping, array $queryReplacements, ?string $bundle): void {
    $frozen = $this->frozenProps($node, $skipped);
    if ($frozen === NULL) {
      return;
    }
    $uuid = $this->addContentItem('sdc.' . self::COMPONENT_PROVIDER . '.' . FrozenBlock::SLUG, $frozen['props'], $parentUuid, $slot, $items);
    foreach ($frozen['queries'] as $index => $query) {
      if (!isset($queryReplacements[spl_object_id($query)])) {
        $skipped[] = "$node->name: its inner core/query becomes no View here";
        continue;
      }
      $this->addNodes([$query], $uuid, FrozenBlock::holeSlot($index + 1), $items, $skipped, $mapping, $queryReplacements, $bundle);
    }
  }

  /**
   * Returns a Frozen block's props and hole Queries, or NULL without a file.
   *
   * The generated theme holds the block's file under its key, from
   * frozenHtml().
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block with no component.
   * @param string[] $skipped
   *   Collects the block when FrozenHoles::file() gives no file.
   *
   * @return array{props: array, queries: \Drupal\wordpal_convert\Theme\BlockNode[]}|null
   *   The component props, and the Query of hole N at index N - 1.
   */
  private function frozenProps(BlockNode $node, array &$skipped): ?array {
    $file = FrozenHoles::file($node);
    if (is_string($file)) {
      $skipped[] = "$node->name: $file";
      return NULL;
    }
    return [
      'props' => ['block_name' => $node->name, 'key' => FrozenBlock::key($node->rendered)],
      'queries' => $file['queries'],
    ];
  }

  /**
   * Returns the Canvas component for a block, or NULL when none exists.
   *
   * An SDC-mapped block whose value Canvas cannot store faithfully, such
   * as a `url`-suffixed prop holding a `data:` URI (Greenshift's inline
   * SVG icons), gets no component here, so it freezes instead of losing
   * that value. A block plugin component has no such prop schema to check.
   */
  private function componentId(BlockNode $node): ?string {
    $blockComponent = $this->blockComponentId($node->name);
    if ($blockComponent !== NULL) {
      return Component::load($blockComponent) === NULL ? NULL : $blockComponent;
    }
    if (!str_starts_with($node->name, 'core/')) {
      return NULL;
    }
    $componentId = 'sdc.' . self::COMPONENT_PROVIDER . '.' . $node->slug();
    if (Component::load($componentId) === NULL || $this->hasUnstorableUrl($node)) {
      return NULL;
    }
    return $componentId;
  }

  /**
   * Returns whether a block sets a URL-format prop to a `data:` URI.
   *
   * Canvas resolves a "url"-suffixed prop through a link field, which only
   * accepts a routed or external scheme.
   */
  private function hasUnstorableUrl(BlockNode $node): bool {
    $props = $this->componentPluginManager
      ->find(self::COMPONENT_PROVIDER . ':' . $node->slug())
      ->metadata->schema['properties'] ?? [];
    $values = $this->flattener->flatten($node->attributes, $node->name);
    foreach ($props as $prop => $schema) {
      $value = $values[$prop] ?? NULL;
      if (is_string($value) && str_starts_with($value, 'data:') && in_array($schema['format'] ?? NULL, self::URL_FORMATS, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the WordPal block component for one WordPress block name.
   */
  private function blockComponentId(string $blockName): ?string {
    // The comment fragment blocks (core/comments-title, core/avatar, and the
    // rest of CommentBlockInputs::settingKeys()) never place their own
    // Component; CommentBlockInputs::layout() consumes them as markers
    // inside the Comments one. A standalone core/post-comments-form is its
    // own one-marker layout.
    $pluginId = BlockPluginInputs::pluginId($blockName);
    return $pluginId === NULL ? NULL : 'block.' . $pluginId;
  }

  /**
   * Finds the block plugins used anywhere in a frame tree.
   */
  private function blockComponentIds(array $nodes): array {
    $ids = [];
    foreach ($nodes as $node) {
      $componentId = $this->blockComponentId($node->name);
      if ($componentId !== NULL) {
        $ids[] = substr($componentId, strlen('block.'));
        if ($node->name === 'core/navigation') {
          $ids[] = 'wordpal_navigation_link';
        }
      }
      $ids = [...$ids, ...$this->blockComponentIds($node->children)];
    }
    return array_values(array_unique($ids));
  }

  /**
   * Returns flat Canvas inputs for a WordPal block component.
   */
  private function blockInputs(BlockNode $node, string $componentId, ContentMapping $mapping): array {
    if (in_array($node->name, CommentBlockInputs::BLOCK_NAMES, TRUE)) {
      $comments = $this->commentBlockInputs->values($node, $this->defaultSettings('block.wordpal_comments'), $mapping);
      return $comments['settings'] + ['layout' => $this->commentBlockInputs->layout($comments['children'])];
    }
    return $this->blockSettings->pluginInputs($node, $this->defaultSettings($componentId), $mapping);
  }

  /**
   * Returns a block component's own default settings, ready to override.
   */
  private function defaultSettings(string $componentId): array {
    $settings = Component::load($componentId)->getSettings()['default_settings'];
    unset($settings['id'], $settings['provider']);
    return $settings;
  }

  /**
   * Returns the slot a component holds its inner blocks in.
   */
  private function slotName(BlockNode $node): ?string {
    $slots = $this->componentPluginManager
      ->find(self::COMPONENT_PROVIDER . ':' . $node->slug())
      ->metadata->slots;
    return array_key_first($slots) ?: NULL;
  }

  /**
   * Turns block attributes into component inputs.
   *
   * The Template part and Gallery flags are this walk's own state.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param string[] $skipped
   *   Collects values no prop accepts.
   */
  private function inputs(BlockNode $node, array &$skipped): array {
    return $this->componentInputs->forNode($node, $skipped) + ComponentInputs::contextInputs($node, $this->inTemplatePart, $this->inGallery);
  }

}
