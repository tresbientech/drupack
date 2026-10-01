<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder;

use Drupal\views\Entity\View;
use Drupal\wordpal_convert\Component\BlockPluginInputs;
use Drupal\wordpal_convert\ConfigValidation;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Listing\ListingBlockSettings;
use Drupal\wordpal_convert\Listing\ListingViewConfig;
use Drupal\wordpal_convert\Theme\BlockNode;

/**
 * Places a latest-posts, page-list or latest-comments block as a View block.
 *
 * SourceTreeBuilder::buildNode() calls place() for every block, ahead of its
 * own block-plugin and component lookups. A recognized listing block resolves
 * to its listing View's own block display, not to a block plugin or a
 * component, as CanvasWriter checks listingSettings() before componentId().
 * ListingBlockSettings::resolve() decides the kind and settings the same way
 * for both targets, so ListingViewConfig::viewId() names the shared View the
 * same whichever target converts it first.
 */
final class ListingBlocks {

  public function __construct(
    private readonly ListingBlockSettings $listingBlockSettings,
  ) {}

  /**
   * Returns whether a block is a listing block, regardless of its settings.
   *
   * DisplayBuilderWriter's own frozen-block walk checks this before its
   * block-plugin and component lookups, mirroring buildNode()'s branching
   * through place() without saving the View a report never uses.
   */
  public function isListing(BlockNode $node, ContentMapping $mapping): bool {
    return $this->listingBlockSettings->resolve($node, $mapping) !== NULL;
  }

  /**
   * Returns a listing block's `block` source, or NULL for any other block.
   *
   * Saves the block's View the first time its kind and settings occur in
   * this conversion; a later occurrence with the same kind and settings
   * loads the same View instead, the sharing ListingViewConfig::viewId()
   * gives Canvas's own saveListingInfrastructure() too.
   *
   * @return array{source_id: string, source: array}|null
   *   The shape SourceTreeBuilder::buildNode() gives a `block` source, minus
   *   its node_id; NULL when the block is not a listing block.
   */
  public function place(BlockNode $node, ContentMapping $mapping): ?array {
    $listing = $this->listingBlockSettings->resolve($node, $mapping);
    if ($listing === NULL) {
      return NULL;
    }
    $componentId = $this->save($mapping->themeId(), $listing['kind'], $listing['settings']);
    return [
      'source_id' => 'block',
      'source' => [
        'plugin_id' => $componentId,
        $componentId => [],
      ],
    ];
  }

  /**
   * Saves one listing kind and settings' View once, and returns its block id.
   */
  private function save(string $theme, string $kind, array $settings): string {
    $ids = $this->outputIds($theme, $kind, $settings);
    $view = View::load($ids['view']);
    if ($view === NULL) {
      $view = ListingViewConfig::create($kind, $ids['view'], $settings);
      ConfigValidation::assertValid($view);
      $view->save();
    }
    return $ids['component'];
  }

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
   *   "component" is the View's block display's own Drupal block plugin id;
   *   see DisplayBuilderWriter::listingComponentEntityType() for why it names
   *   no trackable entity of its own on this target, unlike Canvas's.
   */
  public function outputIds(string $theme, string $kind, array $settings): array {
    $view = ListingViewConfig::viewId($theme, $kind, $settings);
    return [
      'view' => $view,
      'component' => ListingWriter::VIEWS_BLOCK_PREFIX . "$view-block_1",
    ];
  }

  /**
   * Finds the listing View and block ids a tree's listing blocks save.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used to resolve each listing block's settings.
   *
   * @return array[]
   *   "view" and "component" ids, as outputIds() returns them, one entry per
   *   listing block occurrence.
   */
  public function outputs(array $nodes, ContentMapping $mapping): array {
    $ids = [];
    foreach ($nodes as $node) {
      $listing = $this->listingBlockSettings->resolve($node, $mapping);
      if ($listing !== NULL) {
        $ids[] = $this->outputIds($mapping->themeId(), $listing['kind'], $listing['settings']);
        continue;
      }
      if (BlockPluginInputs::pluginId($node->name) === NULL) {
        // A block-plugin component's own settings fold its children in;
        // buildNode() never recurses into them, so neither does this walk.
        $ids = [...$ids, ...$this->outputs($node->children, $mapping)];
      }
    }
    return $ids;
  }

}
