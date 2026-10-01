<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Listing;

use Drupal\views\Entity\View;
use Drupal\wordpal\Theme\ViewTags;
use Drupal\wordpal_convert\OwnedEntityUuid;
use Drupal\wordpal_convert\Theme\TreeHashing;

/**
 * Builds the View configuration array a listing block's Conversion saves.
 *
 * Shared by both writer targets: a Canvas and a Display Builder conversion
 * of the same theme resolve a listing block to the same kind and settings
 * (CanvasWriter and Display Builder's ListingBlocks each call
 * \Drupal\wordpal_convert\Listing\ListingBlockSettings::resolve()), so
 * viewId() names their shared View the same, and forKind() builds the same
 * View configuration for it. Each target still places the View its own way:
 * Canvas wraps it in a Component, Display Builder points a `block` source at
 * its views_block plugin directly.
 */
final class ListingViewConfig {

  /**
   * Returns the id one listing kind and settings' View saves under.
   *
   * Two listing blocks with the same kind and settings hash the same, so
   * either target's writer saves their shared View once and reuses it.
   */
  public static function viewId(string $theme, string $kind, array $settings): string {
    ksort($settings);
    $key = substr(hash('sha256', $kind . json_encode($settings, JSON_THROW_ON_ERROR)), 0, 12);
    return 'wordpal_' . TreeHashing::truncatedThemeId($theme) . '_listing_' . $key;
  }

  /**
   * Returns the generated View configuration for one listing kind.
   *
   * @param string $kind
   *   The listing kind: latest_posts, page_list, or latest_comments.
   * @param string $id
   *   The View's machine name, from viewId().
   * @param array $settings
   *   The listing block's resolved settings, from
   *   \Drupal\wordpal_convert\Listing\ListingBlockSettings::resolve().
   */
  public static function forKind(string $kind, string $id, array $settings): array {
    return match ($kind) {
      'latest_posts' => self::latestPosts(
        $id,
        'Latest posts',
        $settings['bundle'],
        $settings['posts_to_show'],
        $settings['order_by'] === 'title' ? 'title' : 'created',
        $settings['order'] === 'asc' ? 'asc' : 'desc',
      ),
      'page_list' => self::pageList($id, 'Page list', $settings['bundle']),
      'latest_comments' => self::latestComments($id, 'Latest comments', $settings['field_name'], $settings['comments_to_show']),
    };
  }

  /**
   * Returns an unsaved View for one listing kind, uuid and setting stamped.
   *
   * Shared by both writer targets so the deterministic uuid a --replace
   * reconversion matches against, and the `wordpal.listing` third-party
   * setting a saved View's kind and settings are read back from, are set in
   * one place instead of once per target.
   */
  public static function create(string $kind, string $id, array $settings): View {
    $values = self::forKind($kind, $id, $settings);
    $values['uuid'] = OwnedEntityUuid::uuid('view', $id);
    $view = View::create($values);
    $view->setThirdPartySetting('wordpal', 'listing', ['kind' => $kind, 'settings' => $settings]);
    return $view;
  }

  /**
   * Returns the generated View configuration for a latest-posts listing.
   *
   * The row plugin is `fields` with no fields configured: the preprocess
   * branch for `ViewTags::LISTING` builds each row straight from `_entity`,
   * so no field handler ever renders.
   *
   * @param string $id
   *   The View's machine name.
   * @param string $label
   *   The View's human label.
   * @param string $bundle
   *   The listed node bundle.
   * @param int $perPage
   *   The number of posts to show.
   * @param string $orderBy
   *   The `node_field_data` column to sort on.
   * @param string $order
   *   Either `asc` or `desc`.
   */
  public static function latestPosts(string $id, string $label, string $bundle, int $perPage, string $orderBy, string $order): array {
    return [
      'id' => $id,
      'label' => $label,
      'description' => 'Posts converted from the WordPress Latest Posts block.',
      'tag' => ViewTags::LISTING,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
            'cache' => ['type' => 'tag', 'options' => []],
            'pager' => [
              'type' => 'some',
              'options' => [
                'items_per_page' => $perPage,
                'offset' => 0,
              ],
            ],
            'style' => ['type' => 'default', 'options' => []],
            'row' => ['type' => 'fields', 'options' => []],
            'fields' => [],
            'filters' => [
              'status' => [
                'id' => 'status',
                'table' => 'node_field_data',
                'field' => 'status',
                'entity_type' => 'node',
                'entity_field' => 'status',
                'plugin_id' => 'boolean',
                'value' => '1',
              ],
              'type' => [
                'id' => 'type',
                'table' => 'node_field_data',
                'field' => 'type',
                'entity_type' => 'node',
                'entity_field' => 'type',
                'plugin_id' => 'bundle',
                'value' => [$bundle => $bundle],
              ],
            ],
            'sorts' => [
              $orderBy => [
                'id' => $orderBy,
                'table' => 'node_field_data',
                'field' => $orderBy,
                'entity_type' => 'node',
                'entity_field' => $orderBy,
                'plugin_id' => $orderBy === 'created' ? 'date' : 'standard',
                'order' => $order,
              ],
            ],
          ],
        ],
        'block_1' => [
          'id' => 'block_1',
          'display_title' => 'Block',
          'display_plugin' => 'block',
          'position' => 1,
          'display_options' => ['display_extenders' => []],
        ],
      ],
    ];
  }

  /**
   * Returns the generated View configuration for a page-list listing.
   *
   * @param string $id
   *   The View's machine name.
   * @param string $label
   *   The View's human label.
   * @param string $bundle
   *   The listed node bundle.
   */
  public static function pageList(string $id, string $label, string $bundle): array {
    return [
      'id' => $id,
      'label' => $label,
      'description' => 'Pages converted from the WordPress Page List block.',
      'tag' => ViewTags::LISTING,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
            'cache' => ['type' => 'tag', 'options' => []],
            'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
            'style' => ['type' => 'default', 'options' => []],
            'row' => ['type' => 'fields', 'options' => []],
            'fields' => [],
            'filters' => [
              'status' => [
                'id' => 'status',
                'table' => 'node_field_data',
                'field' => 'status',
                'entity_type' => 'node',
                'entity_field' => 'status',
                'plugin_id' => 'boolean',
                'value' => '1',
              ],
              'type' => [
                'id' => 'type',
                'table' => 'node_field_data',
                'field' => 'type',
                'entity_type' => 'node',
                'entity_field' => 'type',
                'plugin_id' => 'bundle',
                'value' => [$bundle => $bundle],
              ],
            ],
            'sorts' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'standard',
                'order' => 'ASC',
              ],
            ],
          ],
        ],
        'block_1' => [
          'id' => 'block_1',
          'display_title' => 'Block',
          'display_plugin' => 'block',
          'position' => 1,
          'display_options' => ['display_extenders' => []],
        ],
      ],
    ];
  }

  /**
   * Returns the generated View configuration for a latest-comments listing.
   *
   * The relationship and filters mirror core's own comments_recent.yml: a
   * "node" relationship reaches the commented node so a status_node filter
   * can require it published, alongside the comment's own published status.
   *
   * @param string $id
   *   The View's machine name.
   * @param string $label
   *   The View's human label.
   * @param string $fieldName
   *   The comment field the listed comments belong to.
   * @param int $perPage
   *   The number of comments to show.
   */
  public static function latestComments(string $id, string $label, string $fieldName, int $perPage): array {
    return [
      'id' => $id,
      'label' => $label,
      'description' => 'Comments converted from the WordPress Latest Comments block.',
      'tag' => ViewTags::LISTING,
      'base_table' => 'comment_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'access' => ['type' => 'perm', 'options' => ['perm' => 'access comments']],
            'cache' => ['type' => 'tag', 'options' => []],
            'pager' => [
              'type' => 'some',
              'options' => [
                'items_per_page' => $perPage,
                'offset' => 0,
              ],
            ],
            'style' => ['type' => 'default', 'options' => []],
            'row' => ['type' => 'fields', 'options' => []],
            'fields' => [],
            'relationships' => [
              'node' => [
                'id' => 'node',
                'table' => 'comment_field_data',
                'field' => 'node',
                'plugin_id' => 'standard',
                'required' => TRUE,
              ],
            ],
            'filters' => [
              'status' => [
                'id' => 'status',
                'table' => 'comment_field_data',
                'field' => 'status',
                'entity_type' => 'comment',
                'entity_field' => 'status',
                'plugin_id' => 'boolean',
                'value' => '1',
              ],
              'entity_type' => [
                'id' => 'entity_type',
                'table' => 'comment_field_data',
                'field' => 'entity_type',
                'plugin_id' => 'string',
                'operator' => '=',
                'value' => 'node',
              ],
              'field_name' => [
                'id' => 'field_name',
                'table' => 'comment_field_data',
                'field' => 'field_name',
                'plugin_id' => 'string',
                'operator' => '=',
                'value' => $fieldName,
              ],
              'status_node' => [
                'id' => 'status_node',
                'table' => 'node_field_data',
                'field' => 'status',
                'relationship' => 'node',
                'entity_type' => 'node',
                'entity_field' => 'status',
                'plugin_id' => 'boolean',
                'value' => '1',
              ],
            ],
            'sorts' => [
              'created' => [
                'id' => 'created',
                'table' => 'comment_field_data',
                'field' => 'created',
                'entity_type' => 'comment',
                'entity_field' => 'created',
                'plugin_id' => 'date',
                'order' => 'DESC',
              ],
              'cid' => [
                'id' => 'cid',
                'table' => 'comment_field_data',
                'field' => 'cid',
                'entity_type' => 'comment',
                'entity_field' => 'cid',
                'plugin_id' => 'standard',
                'order' => 'DESC',
              ],
            ],
          ],
        ],
        'block_1' => [
          'id' => 'block_1',
          'display_title' => 'Block',
          'display_plugin' => 'block',
          'position' => 1,
          'display_options' => ['display_extenders' => []],
        ],
      ],
    ];
  }

}
