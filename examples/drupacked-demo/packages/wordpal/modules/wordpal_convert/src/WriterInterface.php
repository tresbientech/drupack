<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

use Drupal\Core\Entity\EntityInterface;
use Drupal\views\ViewEntityInterface;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\Theme\ResolvedTemplate;

/**
 * Writes a Conversion's trees into one Builder target.
 *
 * `wordpal_canvas` implements this for Canvas; `wordpal_display_builder`
 * implements it for Display Builder. ConversionRunner holds the orchestration
 * both share: Query sharing, skip handling, Demo content, ownership and
 * recipe writing. Each write method returns the entities it saved, so the
 * runner can report them and record them in the ownership manifest.
 */
interface WriterInterface {

  /**
   * Lists the blocks a tree writes as Frozen blocks, once per occurrence.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, whose dropped concepts write no block.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The Frozen blocks, in tree order. Their children are not listed.
   */
  public function frozenNodes(array $nodes, ContentMapping $mapping): array;

  /**
   * Returns the HTML of every Frozen block in a tree, keyed by its key.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, whose dropped concepts write no block.
   *
   * @return array<string, string>
   *   What the generated theme stores for the tree's Frozen blocks.
   */
  public function frozenHtml(array $nodes, ContentMapping $mapping): array;

  /**
   * Returns the name of every Frozen block in a tree, keyed by its key.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, whose dropped concepts write no block.
   *
   * @return array<string, string>
   *   Block names keyed by the key of their HTML.
   */
  public function frozenKeys(array $nodes, ContentMapping $mapping): array;

  /**
   * Returns the machine name of this target's runtime companion module.
   *
   * SiteRecipeWriter installs it on every conversion for this target, not
   * only when some Owned config happens to depend on one of its plugins.
   * The runtime module is required regardless of what a conversion's
   * blocks resolved to.
   */
  public function runtimeModule(): string;

  /**
   * Returns the Builder target's name as the conversion report prints it.
   */
  public function label(): string;

  /**
   * Returns the path where an editor changes a listing Template.
   *
   * @param \Drupal\Core\Entity\EntityInterface $pattern
   *   The pattern writeTermTemplate() or writeTemplateView() saved.
   * @param \Drupal\views\ViewEntityInterface|null $view
   *   The page View writeTemplateView() saved, NULL for a term Template.
   *
   * @return string|null
   *   The path, or NULL when the Template has no editor of its own.
   */
  public function templatePath(EntityInterface $pattern, ?ViewEntityInterface $view): ?string;

  /**
   * Returns the entity type id a pattern or preset saves as on this target.
   */
  public function patternEntityType(): string;

  /**
   * Returns the entity type id a home or 404 page saves as on this target.
   */
  public function pageEntityType(): string;

  /**
   * Returns the entity type id a page frame saves as on this target.
   */
  public function pageFrameEntityType(): string;

  /**
   * Returns whether this target writes a tree's Query blocks yet.
   *
   * A Pattern or Template whose tree holds a Query block is skipped, named,
   * until this returns TRUE.
   */
  public function supportsQueryLoops(): bool;

  /**
   * Returns the config name a bundle's full display becomes owned under.
   *
   * NULL for a target that tracks the full content template as its own
   * distinct entity instead (Canvas's content_template, already tracked
   * through the "content_template" entity type). A target that returns a
   * name here writes and owns that bundle's `core.entity_view_display`
   * config directly; ConversionOwnership refuses a name whose full display
   * already holds unmanaged sources, and never deletes the config itself on
   * `--replace`, only the sources this target wrote.
   */
  public function fullDisplayConfigName(string $bundle): ?string;

  /**
   * Returns the deterministic ids one bundle's runtime-component presets use.
   *
   * One id per runtime component this target preset-binds for that bundle,
   * skipping a component whose binding the mapping drops the same way
   * writeRuntimePresets() does, so ownership tracks only ids it actually
   * saves. Empty for a target with no separate preset step.
   *
   * @return string[]
   *   The preset ids.
   */
  public function runtimePresetIds(string $theme, string $bundle, ContentMapping $mapping): array;

  /**
   * Writes one preset per runtime component for a mapped bundle.
   *
   * Each preset lives in a group named after the theme and the bundle, its
   * runtime bindings resolved against that bundle through the Content
   * mapping, so an editor can place it on any page layout and see the
   * routed node's own data. Empty for a target with no separate preset
   * step: Canvas binds a runtime component's fields only where the
   * component already sits inside a tree saved against a bundle (see
   * BlockSettings::dynamicFieldBindings()), never as a standalone,
   * bundle-scoped placeable of its own.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The saved presets.
   */
  public function writeRuntimePresets(string $theme, string $bundle, ContentMapping $mapping): array;

  /**
   * Returns whether the home and 404 Templates need the page mapping bound.
   *
   * Display Builder writes them as nodes of the mapped page bundle; Canvas
   * writes them as its own Page entity, which needs no bundle.
   */
  public function requiresPageMapping(): bool;

  /**
   * Returns the routes this target does not write a Template for yet.
   *
   * A route named here is skipped, named, before any of its outputs are
   * written or recorded in the ownership manifest.
   *
   * @return string[]
   *   Route names, from \Drupal\wordpal_convert\Theme\TemplateResolver.
   */
  public function unsupportedRoutes(): array;

  /**
   * Writes one block tree as a pattern.
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
  public function writePattern(string $id, string $label, array $nodes, ContentMapping $mapping): EntityInterface;

  /**
   * Returns the render array of one pattern or preset writePattern() saved.
   *
   * @param string $id
   *   Machine name of the pattern, as writePattern() or writeQueryPattern()
   *   saved it.
   *
   * @return array|null
   *   The render array, or NULL when no pattern or preset with this id
   *   exists on this target.
   */
  public function renderPattern(string $id): ?array;

  /**
   * Writes a pattern whose Query blocks become distinct listing outputs.
   *
   * @param string $id
   *   Machine name of the pattern.
   * @param string $label
   *   Human name of the pattern.
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The blocks of the pattern.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop[] $loops
   *   The tree's Query blocks.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used by mapped runtime blocks.
   * @param string $theme
   *   The theme slug.
   *
   * @return array
   *   "pattern" holds the saved pattern entity, and "queries" holds one
   *   entry per Query block.
   */
  public function writeQueryPattern(string $id, string $label, array $nodes, array $loops, ContentMapping $mapping, string $theme): array;

  /**
   * Writes one page frame from an ordered set of Template parts.
   *
   * @param \Drupal\wordpal_convert\Theme\PartSet $partSet
   *   The Template parts around the main area.
   * @param string $theme
   *   The theme slug.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used by mapped runtime blocks.
   * @param array[] $partTrees
   *   `PartSet::loops()`'s result: each part's tree without its
   *   unconvertible Queries, and the Query loops that become Views.
   * @param string|null $listingRoute
   *   The listing route ("archive" or "search") of the Templates using this
   *   Part set, whose main query an inheriting part Query shows, or NULL.
   * @param array<string, string[]> $routesByPartSet
   *   Route names using each Part set, keyed by `PartSet::id()`. A target
   *   whose frame selection has no notion of routes ignores this.
   *
   * @return array{variant: \Drupal\Core\Entity\EntityInterface, queries: array[]}
   *   The frame, and one entry per part Query as saveQuery() returns them.
   */
  public function writePageVariant(PartSet $partSet, string $theme, ContentMapping $mapping, array $partTrees, ?string $listingRoute, array $routesByPartSet): array;

  /**
   * Returns report notes about a route this target could not condition.
   *
   * Canvas selects a page's frame through one sitewide default setting, so
   * it has nothing to report here. A target that conditions a frame on its
   * routes (see writePageVariant()) returns one line per route left out:
   * one whose path or bundle no installed condition plugin expresses, or
   * one whose condition collides with another frame's.
   *
   * @param array<string, string[]> $routesByPartSet
   *   Route names using each Part set, keyed by `PartSet::id()`.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used to resolve a route's mapped bundle or path.
   *
   * @return string[]
   *   Report lines, empty when every route converted to a condition.
   */
  public function pageVariantNotes(array $routesByPartSet, ContentMapping $mapping): array;

  /**
   * Writes a static home or 404 Template as its page.
   *
   * @param string $route
   *   The route the page renders, "home" or "not_found".
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The Template's main tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used by mapped runtime blocks.
   * @param string $theme
   *   The theme slug.
   * @param string $pageVariant
   *   Id of the page's frame.
   */
  public function writeStaticPage(string $route, array $nodes, ContentMapping $mapping, string $theme, string $pageVariant): EntityInterface;

  /**
   * Writes a page Template's query outputs and its page.
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
   *   "page" holds the saved page entity, and "queries" holds one entry
   *   per Query block.
   */
  public function writeQueryPage(string $route, string $bundle, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array;

  /**
   * Writes the full content template for a mapped node bundle.
   *
   * @param string $bundle
   *   The node bundle the template renders.
   * @param string $viewMode
   *   The view mode the template writes.
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The template's blocks.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used by mapped runtime blocks.
   * @param string|null $pageVariant
   *   Id of the bundle's page frame, required for the "full" view mode.
   */
  public function writeContentTemplate(string $bundle, string $viewMode, array $nodes, ContentMapping $mapping, ?string $pageVariant): EntityInterface;

  /**
   * Writes a full content template whose Query blocks become listing outputs.
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
   *   "template" holds the saved content template entity, and "queries"
   *   holds one entry per Query block.
   */
  public function writeQueryContentTemplate(string $bundle, string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array;

  /**
   * Writes a listing Template as its route's View and pattern.
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
   *   On the archive route, the patterns writeTermTemplate() saved for the
   *   term pages of one vocabulary, keyed by vocabulary id.
   *
   * @return array
   *   "pattern" and "view" hold the saved pattern and page View, and
   *   "queries" one entry per Query block.
   */
  public function writeTemplateView(string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme, array $termPatterns = []): array;

  /**
   * Writes a tag or category Template as the pattern its term pages render.
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
   *   "pattern" holds the saved pattern, and "queries" one entry per Query
   *   block.
   */
  public function writeTermTemplate(string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array;

  /**
   * Returns the ids a listing Template's pattern and page View are saved under.
   *
   * @param string $theme
   *   The theme slug.
   * @param string $route
   *   The listing route.
   *
   * @return array{pattern: string, view: string}
   *   The pattern and View ids.
   */
  public function templateOutputIds(string $theme, string $route): array;

  /**
   * Returns the deterministic ids one Query's outputs would be saved under.
   *
   * @param string $theme
   *   The theme slug.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop $loop
   *   The Query's dynamic pieces.
   * @param string $bundle
   *   The node bundle the Query lists.
   * @param string|null $route
   *   The listing route whose Template holds the Query, or NULL.
   *
   * @return array{view_mode: string, view: string, component: string, empty_pattern: string|null}
   *   The output ids. "view_mode" and "component" are ids on this target
   *   only when queryCardEntityTypes() names an entity type for them; a
   *   target with none still returns a stable string, unused past this
   *   return.
   */
  public function queryOutputIds(string $theme, QueryLoop $loop, string $bundle, ?string $route = NULL): array;

  /**
   * Returns the entity types a Query's card and its View block save as.
   *
   * ConversionRunner's ownership scan reserves queryOutputIds()'s
   * "view_mode" and "component" ids under these types before ConversionRunner
   * knows any Query's card actually saved. A target with no separate
   * card/component entity, whose View alone is the Query's saved output,
   * returns an empty array: no id in this target's queryOutputIds() return
   * is a real content_template, entity_view_mode, or Canvas Component id.
   *
   * @return array{view_mode: string, content_template: string, component: string}|array{}
   *   The entity type per key, or an empty array.
   */
  public function queryCardEntityTypes(): array;

  /**
   * Returns the deterministic ids one listing kind and settings save under.
   *
   * @param string $theme
   *   The generated theme id (ContentMapping::themeId()).
   * @param string $kind
   *   The listing kind: latest_posts, page_list, or latest_comments.
   * @param array $settings
   *   The listing block's resolved settings.
   *
   * @return array{view: string, component: string}
   *   The output ids.
   */
  public function listingOutputIds(string $theme, string $kind, array $settings): array;

  /**
   * Returns the entity type a listing block's own Component saves as, or NULL.
   *
   * ConversionRunner's ownership manifest records listingOutputs()'s
   * "component" id under this type. Canvas wraps a listing View's block
   * display in its own Component config entity and returns its entity type
   * here; a target with no separate entity for that placement, whose
   * "component" id from listingOutputIds() is a Drupal block plugin id
   * rather than an entity id, returns NULL, the same gate
   * queryCardEntityTypes() gives a Query's card.
   */
  public function listingComponentEntityType(): ?string;

  /**
   * Finds the listing View and Component ids a tree's listing blocks save.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used to resolve each listing block's settings.
   *
   * @return array[]
   *   "view" and "component" ids, as listingOutputIds() returns them, one
   *   entry per listing block occurrence.
   */
  public function listingOutputs(array $nodes, ContentMapping $mapping): array;

}
