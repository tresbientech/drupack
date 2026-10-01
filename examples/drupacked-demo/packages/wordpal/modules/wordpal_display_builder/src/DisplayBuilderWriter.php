<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\display_builder\DisplayBuildableInterface;
use Drupal\display_builder\DisplayBuildableOverrideInterface;
use Drupal\display_builder\Entity\PatternPreset;
use Drupal\display_builder_page_layout\Entity\PageLayout;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\ui_patterns\Element\ComponentElementBuilder;
use Drupal\wordpal_convert\Component\BlockPluginInputs;
use Drupal\wordpal_convert\ConfigValidation;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\OwnedEntityUuid;
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
use Drupal\views\ViewEntityInterface;
use Drupal\wordpal_convert\WriterInterface;

/**
 * Writes parsed WordPress block trees into Display Builder configuration.
 *
 * Patterns, pages, queries, templates, content templates and listing blocks
 * all write. writeContentTemplate() for a view mode other than `full` throws
 * and names itself, so `wordpal:convert --target=display_builder` stops with
 * a reason instead of a fatal error.
 */
final class DisplayBuilderWriter implements WriterInterface {

  /**
   * The Display Builder profile every generated page layout and display uses.
   *
   * Only UI configuration for the builder chrome; ships with the base
   * display_builder module, so every writer method can reuse it.
   */
  private const PROFILE = 'default';

  /**
   * The title of each static route's node.
   */
  private const PAGE_TITLES = [
    'home' => 'Home',
    'not_found' => 'Page not found',
  ];

  /**
   * The `request_path` pattern of the archive, tag and category route View.
   *
   * `taxonomy/term/%` in ListingWriter's own, Views path syntax.
   */
  private const TERM_PATH = '/taxonomy/term/*';

  /**
   * The page layout weight of a frame conditioned on the front page.
   *
   * Lower sorts first (`AccessControlHandler::loadCurrentPageLayout()`
   * checks conditioned frames in ascending weight order), so home's frame
   * wins first among conditioned frames whenever more than one could match
   * a request.
   */
  private const WEIGHT_HOME = -30;

  /**
   * The page layout weight of a frame whose routes include a path.
   *
   * Between home's and a bundle-wide frame's: a listing or 404 path names
   * one specific page, more specific than a node bundle that spans every
   * node of that type. The 404 page is itself a node of the page bundle.
   */
  private const WEIGHT_PATH = -20;

  /**
   * The page layout weight of a frame whose routes are node bundles only.
   *
   * A bundle matches every node of that type, the widest match, so it
   * sorts last.
   */
  private const WEIGHT_BUNDLE = -10;

  /**
   * The runtime components RuntimeBindings binds, keyed by WordPal slug.
   *
   * Each entry names the WordPress block name RuntimeBindings::props()
   * switches on. writeRuntimePresets() saves one preset per mapped bundle
   * for each. The Query-bound runtime components (query-title,
   * query-pagination-*, query-no-results) are not here: they exist only
   * inside a Query loop's own tree, which this target does not write yet.
   */
  private const RUNTIME_COMPONENTS = [
    'post-title' => 'core/post-title',
    'post-date' => 'core/post-date',
    'post-author' => 'core/post-author',
    'post-author-name' => 'core/post-author-name',
    'avatar' => 'core/avatar',
    'post-content' => 'core/post-content',
    'post-excerpt' => 'core/post-excerpt',
    'post-featured-image' => 'core/post-featured-image',
    'post-terms' => 'core/post-terms',
    'read-more' => 'core/read-more',
  ];

  public function __construct(
    private readonly SourceTreeBuilder $sourceTreeBuilder,
    private readonly ComponentElementBuilder $componentElementBuilder,
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly EntityDisplayRepositoryInterface $displayRepository,
    private readonly ListingWriter $listingWriter,
    private readonly ListingBlocks $listingBlocks,
    private readonly RuntimeBindings $runtimeBindings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function frozenHtml(array $nodes, ContentMapping $mapping): array {
    return array_column($this->storedFrozenNodes($nodes, $mapping), 'html', 'key');
  }

  /**
   * {@inheritdoc}
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
   * Mirrors SourceTreeBuilder::buildNode()'s own branching, so this lists
   * exactly the blocks it freezes: a listing block resolves to its listing
   * View's block, not to a block plugin or an SDC lookup; a block plugin's
   * children are its own settings and are not walked further; a WordPal
   * component's children sit in its slots and are walked for their own
   * Frozen blocks; anything else has no output and freezes.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at one level of the tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, whose dropped concepts write no block.
   */
  public function frozenNodes(array $nodes, ContentMapping $mapping): array {
    $frozen = [];
    foreach ($nodes as $node) {
      if ($node->name === DrupalRouteFrame::MAIN_CONTENT || TreeConcepts::dropped(TreeConcepts::concept($node), $mapping)) {
        continue;
      }
      if ($this->listingBlocks->isListing($node, $mapping)) {
        continue;
      }
      if (BlockPluginInputs::pluginId($node->name) !== NULL) {
        continue;
      }
      if ($this->componentPluginManager->hasDefinition(SourceTreeBuilder::PROVIDER . ':' . $node->slug())) {
        $frozen = [...$frozen, ...$this->frozenNodes($node->children, $mapping)];
        continue;
      }
      // The Views in the block's holes render the blocks inside them.
      $frozen = [...$frozen, $node, ...$this->frozenNodes(FrozenHoles::queries($node->children), $mapping)];
    }
    return $frozen;
  }

  /**
   * {@inheritdoc}
   */
  public function runtimeModule(): string {
    return 'wordpal_display_builder_runtime';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'the Display Builder';
  }

  /**
   * {@inheritdoc}
   */
  public function templatePath(EntityInterface $pattern, ?ViewEntityInterface $view): ?string {
    // The Display Builder edits a Template's page View, and a term Template
    // has none of its own.
    return $view === NULL ? NULL : '/admin/structure/views/view/' . $view->id() . '/display-builder/default';
  }

  /**
   * {@inheritdoc}
   */
  public function patternEntityType(): string {
    return 'pattern_preset';
  }

  /**
   * {@inheritdoc}
   */
  public function pageEntityType(): string {
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  public function pageFrameEntityType(): string {
    return 'page_layout';
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
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fullDisplayConfigName(string $bundle): ?string {
    return "core.entity_view_display.node.$bundle.full";
  }

  /**
   * {@inheritdoc}
   */
  public function runtimePresetIds(string $theme, string $bundle, ContentMapping $mapping): array {
    $themeId = ThemeGenerator::themeId($theme);
    $ids = [];
    foreach (self::RUNTIME_COMPONENTS as $slug => $blockName) {
      if (!$this->runtimeBindings->omits(new BlockNode($blockName, [], '', []), $bundle, $mapping)) {
        $ids[] = self::runtimePresetId($themeId, $bundle, $slug);
      }
    }
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function writeRuntimePresets(string $theme, string $bundle, ContentMapping $mapping): array {
    $themeId = ThemeGenerator::themeId($theme);
    $group = "$themeId $bundle";
    $presets = [];
    foreach (self::RUNTIME_COMPONENTS as $slug => $blockName) {
      $node = new BlockNode($blockName, [], '', []);
      if ($this->runtimeBindings->omits($node, $bundle, $mapping)) {
        continue;
      }
      $tree = $this->sourceTreeBuilder->build([$node], $mapping, boundBundle: $bundle);
      $id = self::runtimePresetId($themeId, $bundle, $slug);
      $preset = PatternPreset::create([
        'id' => $id,
        'uuid' => OwnedEntityUuid::uuid('pattern_preset', $id),
        'label' => $this->componentPluginManager->find(SourceTreeBuilder::PROVIDER . ":$slug")->metadata->name ?? $slug,
        'group' => $group,
        'sources' => SourceTreeBuilder::wrapAsPatternRoot($tree),
      ]);
      ConfigValidation::assertValid($preset);
      $preset->save();
      $presets[] = $preset;
    }
    return $presets;
  }

  /**
   * Returns the deterministic id one bundle's runtime component preset uses.
   */
  private static function runtimePresetId(string $themeId, string $bundle, string $slug): string {
    return $themeId . '_' . $bundle . '_' . str_replace('-', '_', $slug);
  }

  /**
   * {@inheritdoc}
   */
  public function writePattern(string $id, string $label, array $nodes, ContentMapping $mapping): EntityInterface {
    $tree = $this->sourceTreeBuilder->build($nodes, $mapping);
    if ($tree === []) {
      throw new \UnexpectedValueException("$label has no block Display Builder can render.");
    }
    $preset = PatternPreset::create([
      'id' => $id,
      'uuid' => OwnedEntityUuid::uuid('pattern_preset', $id),
      'label' => $label,
      'group' => $mapping->themeId(),
      'sources' => SourceTreeBuilder::wrapAsPatternRoot($tree),
    ]);
    ConfigValidation::assertValid($preset);
    $preset->save();
    return $preset;
  }

  /**
   * {@inheritdoc}
   */
  public function renderPattern(string $id): ?array {
    $preset = PatternPreset::load($id);
    if ($preset === NULL) {
      return NULL;
    }
    $build = $this->componentElementBuilder->buildSource([], 'content', [], $preset->getSources(), []);
    return $build['#slots']['content'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function writeQueryPattern(string $id, string $label, array $nodes, array $loops, ContentMapping $mapping, string $theme): array {
    ['queries' => $queries, 'items' => $tree] = $this->listingWriter->writeQueryOwnerTree($nodes, $loops, $mapping, $theme, $label, NULL);
    $preset = PatternPreset::create([
      'id' => $id,
      'uuid' => OwnedEntityUuid::uuid('pattern_preset', $id),
      'label' => $label,
      'group' => $mapping->themeId(),
      'sources' => SourceTreeBuilder::wrapAsPatternRoot($tree),
    ]);
    ConfigValidation::assertValid($preset);
    $preset->save();
    return ['pattern' => $preset, 'queries' => $queries];
  }

  /**
   * {@inheritdoc}
   *
   * A page layout with no `conditions` key is Display Builder's fallback,
   * checked after every conditioned one regardless of weight
   * (`AccessControlHandler::loadCurrentPageLayout()`). The frame of
   * Drupal-owned routes serves no converted route and is that fallback.
   * frameConditions() gives every other frame one `wordpal_frame_routes`
   * condition, which matches any of its routes' paths or node bundles. Two
   * frames that need the same path or bundle both drop it;
   * pageVariantNotes() reports it.
   */
  public function writePageVariant(PartSet $partSet, string $theme, ContentMapping $mapping, array $partTrees, ?string $listingRoute, array $routesByPartSet): array {
    $sources = [];
    $queries = [];
    foreach ($partSet->placements as $index => $placement) {
      if ($placement['type'] === 'messages') {
        $sources[] = [
          'node_id' => SourceTreeBuilder::nodeId(['__status_messages__']),
          'source_id' => 'block',
          'source' => ['plugin_id' => 'system_messages_block', 'system_messages_block' => []],
        ];
        continue;
      }
      if ($placement['type'] === 'marker') {
        $sources[] = [
          'node_id' => SourceTreeBuilder::nodeId(['__main_page_content__']),
          'source_id' => 'main_page_content',
          'source' => [],
        ];
        continue;
      }
      if ($placement['type'] === 'part') {
        ['items' => $built, 'queries' => $partQueries] = $this->listingWriter->writeQueryOwnerTree($partTrees[$index]['nodes'], $partTrees[$index]['loops'], $mapping, $theme, $partSet->label(), $listingRoute, inTemplatePart: TRUE);
        $queries = [...$queries, ...$partQueries];
      }
      else {
        $built = $this->sourceTreeBuilder->build($placement['nodes'], $mapping);
      }
      $sources = [...$sources, ...$built];
    }
    $layoutId = $partSet->id($theme);
    ['conditions' => $conditions, 'weight' => $weight] = self::frameConditions($layoutId, $routesByPartSet, $mapping);
    $layout = PageLayout::create([
      'id' => $layoutId,
      'uuid' => OwnedEntityUuid::uuid('page_layout', $layoutId),
      'label' => $partSet->label() . ' page frame',
      'profile' => self::PROFILE,
      'sources' => $sources,
      'weight' => $weight,
      'conditions' => $conditions,
      'third_party_settings' => ['wordpal_display_builder_runtime' => ['theme' => ThemeGenerator::themeId($theme)]],
    ]);
    $layout->enable();
    ConfigValidation::assertValid($layout);
    $layout->save();
    return ['variant' => $layout, 'queries' => $queries];
  }

  /**
   * {@inheritdoc}
   */
  public function pageVariantNotes(array $routesByPartSet, ContentMapping $mapping): array {
    $notes = [];
    foreach (array_keys($routesByPartSet) as $partSetId) {
      $notes = [...$notes, ...self::frameConditions($partSetId, $routesByPartSet, $mapping)['notes']];
    }
    return $notes;
  }

  /**
   * Returns the requirement one route's page needs a frame to hold.
   *
   * NULL means the route needs no condition of its own: single has no page
   * at all once the Content mapping drops post. Every other route
   * that reaches this point is guaranteed bound, the same way
   * ConversionRunner::writeOutputs() already reads its mapped bundle or
   * path unconditionally.
   *
   * @return array{type: string, value: string}|null
   *   "type" is "path" or "bundle"; "value" is the path pattern or the
   *   node bundle id `wordpal_frame_routes` matches.
   */
  private static function routeRequirement(string $route, ContentMapping $mapping): ?array {
    return match ($route) {
      'home' => ['type' => 'path', 'value' => '<front>'],
      'archive', 'tag', 'category' => ['type' => 'path', 'value' => self::TERM_PATH],
      'search' => ['type' => 'path', 'value' => '/' . ltrim($mapping->target('search')['path'], '/')],
      'page' => ['type' => 'bundle', 'value' => $mapping->target('page')],
      'single' => $mapping->binds('post') ? ['type' => 'bundle', 'value' => $mapping->target('post')] : NULL,
      // The site's 404 path serves the 404 Template's page node.
      'not_found' => ['type' => 'path', 'value' => TemplatePage::alias($mapping->themeId(), 'not_found')],
      default => NULL,
    };
  }

  /**
   * Returns the conditions, weight and report notes a frame's routes decide.
   *
   * Tag, category and archive share one Drupal route (`/taxonomy/term/*`,
   * ListingWriter's own View path), so a frame the archive Template does
   * not also use cannot be told apart from another vocabulary's frame at
   * that route: both frames drop that requirement, and the collision is
   * reported rather than a winner picked.
   *
   * @param string $partSetId
   *   The frame's own Part set id, `PartSet::id()`.
   * @param array<string, string[]> $routesByPartSet
   *   Route names using each Part set, keyed by Part set id.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, to resolve a route's mapped bundle or path.
   *
   * @return array{conditions: array, weight: int, notes: string[]}
   *   "notes" is empty once every route converts to a condition.
   */
  private static function frameConditions(string $partSetId, array $routesByPartSet, ContentMapping $mapping): array {
    $routes = $routesByPartSet[$partSetId] ?? [];
    $requirements = [];
    foreach ($routes as $route) {
      $requirement = self::routeRequirement($route, $mapping);
      if ($requirement !== NULL) {
        $requirements[$requirement['type'] . ':' . $requirement['value']] = $requirement;
      }
    }
    $notes = [];
    foreach ($requirements as $key => $requirement) {
      $collidesWith = self::collidingPartSet($partSetId, $key, $routesByPartSet, $mapping);
      if ($collidesWith === NULL) {
        continue;
      }
      unset($requirements[$key]);
      if ($partSetId < $collidesWith) {
        $notes[] = "Two frames both need the {$requirement['type']} condition '{$requirement['value']}'; leaving neither conditioned on it.";
      }
    }
    if ($requirements === []) {
      return ['conditions' => [], 'weight' => 0, 'notes' => $notes];
    }
    $pages = [];
    $bundles = [];
    foreach ($requirements as $requirement) {
      if ($requirement['type'] === 'path') {
        $pages[] = $requirement['value'];
      }
      else {
        $bundles[] = $requirement['value'];
      }
    }
    return [
      'conditions' => [
        'wordpal_frame_routes' => [
          'id' => 'wordpal_frame_routes',
          'negate' => FALSE,
          'context_mapping' => ['node' => '@node.node_route_context:node'],
          'pages' => implode("\n", $pages),
          'bundles' => $bundles,
        ],
      ],
      'weight' => match (TRUE) {
        in_array('home', $routes, TRUE) => self::WEIGHT_HOME,
        $pages !== [] => self::WEIGHT_PATH,
        default => self::WEIGHT_BUNDLE,
      },
      'notes' => $notes,
    ];
  }

  /**
   * Returns another frame's id that independently needs the same condition.
   *
   * @return string|null
   *   The colliding Part set id, or NULL when none of the other frames'
   *   routes need it.
   */
  private static function collidingPartSet(string $partSetId, string $requirementKey, array $routesByPartSet, ContentMapping $mapping): ?string {
    foreach ($routesByPartSet as $otherId => $otherRoutes) {
      if ($otherId === $partSetId) {
        continue;
      }
      foreach ($otherRoutes as $otherRoute) {
        $otherRequirement = self::routeRequirement($otherRoute, $mapping);
        if ($otherRequirement !== NULL && $otherRequirement['type'] . ':' . $otherRequirement['value'] === $requirementKey) {
          return $otherId;
        }
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * $pageVariant names Canvas's own page-to-frame link; a Display Builder
   * page selects its frame through the page_layout's own conditions (see
   * writePageVariant()), so this target has no use for the parameter.
   */
  public function writeStaticPage(string $route, array $nodes, ContentMapping $mapping, string $theme, string $pageVariant): EntityInterface {
    $bundle = $this->pageBundle($route, $mapping);
    $tree = $this->sourceTreeBuilder->build($nodes, $mapping, rootPath: [$route]);
    if ($tree === []) {
      throw new \UnexpectedValueException(TemplateResolver::templateName($route) . ' Template has no block Display Builder can render.');
    }
    return $this->savePageNode($route, $bundle, $tree, $theme, $mapping);
  }

  /**
   * {@inheritdoc}
   */
  public function writeQueryPage(string $route, string $bundle, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array {
    $pageBundle = $this->pageBundle($route, $mapping);
    ['items' => $tree, 'queries' => $queries] = $this->listingWriter->writeQueryOwnerTree($template->nodes, $template->loops, $mapping, $theme, self::PAGE_TITLES[$route], NULL, rootPath: [$route]);
    if ($tree === []) {
      throw new \UnexpectedValueException(TemplateResolver::templateName($route) . ' Template has no block Display Builder can render.');
    }
    return ['page' => $this->savePageNode($route, $pageBundle, $tree, $theme, $mapping), 'queries' => $queries];
  }

  /**
   * Returns the mapped page bundle a home or 404 Template's node saves as.
   */
  private function pageBundle(string $route, ContentMapping $mapping): string {
    if (!$mapping->binds('page')) {
      throw new \UnexpectedValueException(TemplateResolver::templateName($route) . ' Template requires a page mapping on the display_builder target.');
    }
    return $mapping->target('page');
  }

  /**
   * Saves one route's owned page node, in its bundle's override field.
   */
  private function savePageNode(string $route, string $bundle, array $tree, string $theme, ContentMapping $mapping): Node {
    $fieldName = $this->ensureOverrideField($bundle);
    $themeId = ThemeGenerator::themeId($theme);
    $values = [
      'type' => $bundle,
      'title' => self::PAGE_TITLES[$route],
      'uuid' => TemplatePage::uuid($themeId, $route),
      'status' => TRUE,
      'path' => ['alias' => TemplatePage::alias($themeId, $route)],
      $fieldName => $tree,
    ];
    if ($mapping->binds('page_excerpt')) {
      $values[$mapping->target('page_excerpt')] = TemplatePage::description($route);
    }
    $node = Node::create($values);
    // Not $node->validate(): the mapped bundle is the site's own content
    // type, whose other required fields and Content Moderation transition
    // permissions WordPal has no concept for, the same reason
    // DemoContentSeeder::create() saves without validating. Content
    // Moderation sets the published status from the moderation state
    // instead of the "status" property, mirroring that same method.
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', 'published');
    }
    self::keepAlias($node);
    $node->save();
    return $node;
  }

  /**
   * Keeps the alias this writer set against pathauto (contrib) overwriting it.
   *
   * Pathauto swaps the "path" field's item class for one that adds a
   * computed "pathauto" property; on a new node with no state yet stored,
   * that property defaults to CREATE whenever the bundle has a matching
   * pattern, and generates its own alias from the pattern on save. The
   * property exists only when pathauto is installed, so its presence on the
   * field's property definitions, not a module_exists() check, is the
   * boundary this guards.
   */
  private static function keepAlias(Node $node): void {
    $properties = $node->getFieldDefinition('path')->getFieldStorageDefinition()->getPropertyDefinitions();
    if (isset($properties['pathauto'])) {
      // 0 is \Drupal\pathauto\PathautoState::SKIP; not imported, to keep this
      // module free of a hard dependency on pathauto.
      $node->path->pathauto = 0;
    }
  }

  /**
   * Finds or creates a bundle's full-display override field.
   *
   * Mirrors EntityViewDisplayFormTrait::createOverrideField(): a
   * `ui_patterns_source` field, found by name so a second conversion, or
   * another theme mapping the same bundle, reuses it. The field is
   * site-wide content-model infrastructure the mapped bundle owns, like the
   * bundle itself, so ConversionOwnership never tracks or deletes it.
   *
   * @return string
   *   The field name.
   */
  private function ensureOverrideField(string $bundle): string {
    $fieldName = 'field_display_full';
    $storage = FieldStorageConfig::loadByName('node', $fieldName);
    if ($storage === NULL) {
      $storage = FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => 'ui_patterns_source',
      ]);
      $storage->setTranslatable(TRUE)->setCardinality(-1);
      // Not ConfigValidation::assertValid() here: FieldStorageConfig is
      // itself a FieldStorageDefinitionInterface, cached by object identity
      // in EntityLastInstalledSchemaRepository's installed definitions.
      // Validating first populates the entity's own getTypedData() cache
      // (a WeakReference, EntityBase::getTypedData()), which then travels
      // into that cache and fails the next request that serializes it.
      // The field's shape is fixed by this method, not external input, so
      // save()'s own internal checks are enough.
      $storage->save();
    }
    $field = FieldConfig::loadByName('node', $bundle, $fieldName);
    if ($field === NULL) {
      $field = FieldConfig::create([
        'field_storage' => $storage,
        'bundle' => $bundle,
        'field_name' => $fieldName,
        'label' => 'Full display override',
      ]);
      $field->setTranslatable(TRUE);
      $field->save();
    }
    $display = $this->displayRepository->getViewDisplay('node', $bundle, 'full');
    $display->setThirdPartySetting('display_builder', DisplayBuildableInterface::PROFILE_PROPERTY, self::PROFILE);
    $display->setThirdPartySetting('display_builder', DisplayBuildableOverrideInterface::OVERRIDE_FIELD_PROPERTY, $fieldName);
    $display->setThirdPartySetting('display_builder', DisplayBuildableOverrideInterface::OVERRIDE_PROFILE_PROPERTY, self::PROFILE);
    $display->save();
    return $fieldName;
  }

  /**
   * {@inheritdoc}
   *
   * Not $this->ensureOverrideField(): that field is per-node override
   * infrastructure for the home and 404 static pages (writeStaticPage()),
   * unrelated to a bundle's own full display, which every node of the
   * bundle shares. ConversionOwnership refuses to overwrite a full display
   * that already carries unmanaged sources before this ever runs, so this
   * always starts from either an empty or an already-owned display.
   */
  public function writeContentTemplate(string $bundle, string $viewMode, array $nodes, ContentMapping $mapping, ?string $pageVariant): EntityInterface {
    if ($viewMode !== 'full') {
      throw self::notYet(__FUNCTION__);
    }
    if ($pageVariant === NULL) {
      throw new \InvalidArgumentException('The full content template requires a page variant.');
    }
    $tree = $this->sourceTreeBuilder->build($nodes, $mapping, boundBundle: $bundle);
    if ($tree === []) {
      throw new \UnexpectedValueException("$bundle's full display has no block Display Builder can render.");
    }
    $display = $this->displayRepository->getViewDisplay('node', $bundle, 'full');
    $display->setThirdPartySetting('display_builder', DisplayBuildableInterface::PROFILE_PROPERTY, self::PROFILE);
    $display->setThirdPartySetting('display_builder', DisplayBuildableInterface::SOURCES_PROPERTY, $tree);
    ConfigValidation::assertValid($display);
    $display->save();
    return $display;
  }

  /**
   * {@inheritdoc}
   *
   * Route is always NULL in the writeQueryOwnerTree() call: a single or page
   * Query is never the route's own inheriting main query, the way an
   * archive, tag, category or search Template's Query can be (see
   * QueryLoop::route()). $template->partSet goes unused here for the same
   * reason writeContentTemplate() never uses its own $pageVariant: this
   * target selects a node's frame through the page_layout's own conditions,
   * never through anything the full display itself stores.
   */
  public function writeQueryContentTemplate(string $bundle, string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array {
    ['items' => $tree, 'queries' => $queries] = $this->listingWriter->writeQueryOwnerTree($template->nodes, $template->loops, $mapping, $theme, ucfirst($route), NULL, $bundle);
    if ($tree === []) {
      throw new \UnexpectedValueException("$bundle's full display has no block Display Builder can render.");
    }
    $display = $this->displayRepository->getViewDisplay('node', $bundle, 'full');
    $display->setThirdPartySetting('display_builder', DisplayBuildableInterface::PROFILE_PROPERTY, self::PROFILE);
    $display->setThirdPartySetting('display_builder', DisplayBuildableInterface::SOURCES_PROPERTY, $tree);
    ConfigValidation::assertValid($display);
    $display->save();
    return [
      'template' => $display,
      'queries' => $queries,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function writeTemplateView(string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme, array $termPatterns = []): array {
    return $this->listingWriter->writeTemplateView($route, $template, $mapping, $theme, $termPatterns);
  }

  /**
   * {@inheritdoc}
   */
  public function writeTermTemplate(string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array {
    return $this->listingWriter->writeTermTemplate($route, $template, $mapping, $theme);
  }

  /**
   * {@inheritdoc}
   */
  public function templateOutputIds(string $theme, string $route): array {
    return $this->listingWriter->templateOutputIds($theme, $route);
  }

  /**
   * {@inheritdoc}
   */
  public function queryOutputIds(string $theme, QueryLoop $loop, string $bundle, ?string $route = NULL): array {
    return $this->listingWriter->queryOutputIds($theme, $loop, $bundle, $route);
  }

  /**
   * {@inheritdoc}
   *
   * A Query's card is the View's own `ui_patterns` row, not a separate
   * content template and view mode; its View block is a core views_block
   * plugin id, not a Canvas Component. Neither is a trackable entity of its
   * own on this target.
   */
  public function queryCardEntityTypes(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function listingOutputIds(string $theme, string $kind, array $settings): array {
    return $this->listingBlocks->outputIds($theme, $kind, $settings);
  }

  /**
   * {@inheritdoc}
   */
  public function listingOutputs(array $nodes, ContentMapping $mapping): array {
    return $this->listingBlocks->outputs($nodes, $mapping);
  }

  /**
   * {@inheritdoc}
   *
   * A listing block's View places through a `block` source pointing at its
   * own views_block plugin directly; nothing wraps it in a trackable entity
   * of this target's own, the way Canvas's Component does.
   */
  public function listingComponentEntityType(): ?string {
    return NULL;
  }

  /**
   * Returns the exception for a method this target does not support.
   */
  private static function notYet(string $method): \LogicException {
    return new \LogicException("$method is not supported on the display_builder target.");
  }

}
