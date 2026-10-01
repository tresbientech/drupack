<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Listing;

use Drupal\wordpal\Listing\ListingSettings;
use Drupal\wordpal\Support\PropSchema;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;

/**
 * Resolves a listing block's kind and settings, shared by both targets.
 *
 * CanvasWriter and Display Builder's ListingBlocks both call resolve() to
 * decide what a latest-posts, page-list or latest-comments block's listing
 * View saves: identical settings for identical WordPress input keep
 * ListingViewConfig::viewId() naming one View across either target, and the
 * settings a rendered listing reads (\Drupal\wordpal\Listing\ListingRows,
 * the shared `views-view--wordpal-listing` template) come from here too.
 */
final class ListingBlockSettings {

  /**
   * The listing kind of each WordPress listing block.
   */
  public const KINDS = [
    'core/latest-posts' => 'latest_posts',
    'core/page-list' => 'page_list',
    'core/latest-comments' => 'latest_comments',
  ];

  /**
   * The attributes each listing kind reads from its block.
   *
   * Beyond the settings a content mapping resolves on its own (bundle,
   * excerpt_field, image_field, field_name).
   */
  private const ALLOWED = [
    'latest_posts' => [
      'posts_to_show', 'order', 'order_by', 'display_post_date',
      'display_author', 'post_layout', 'columns', 'css_class',
      'display_post_content', 'excerpt_length', 'display_featured_image',
      'featured_image_size_slug', 'featured_image_size_width',
      'featured_image_size_height', 'featured_image_align',
      'add_link_to_featured_image',
      'font_size', 'font_family', 'line_height', 'font_weight', 'font_style',
      'text_transform', 'text_decoration', 'letter_spacing',
      'background_color', 'text_color', 'gradient', 'link_color',
      'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
      'margin_top', 'margin_right', 'margin_bottom', 'margin_left',
    ],
    'page_list' => ['css_class'],
    'latest_comments' => [
      'comments_to_show', 'display_avatar', 'display_date', 'display_content',
      'css_class',
    ],
  ];

  public function __construct(
    private readonly AttributeFlattener $flattener,
  ) {}

  /**
   * Returns one block's listing kind and resolved settings, or NULL.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets the mapped settings (bundle, excerpt_field,
   *   image_field, field_name) come from.
   *
   * @return array{kind: string, settings: array}|null
   *   NULL for a block that is not a listing block.
   */
  public function resolve(BlockNode $node, ContentMapping $mapping): ?array {
    $kind = self::KINDS[$node->name] ?? NULL;
    if ($kind === NULL) {
      return NULL;
    }
    $settings = ListingSettings::defaults($kind);
    $values = $this->flattener->flatten($node->attributes, $node->name);
    foreach (self::ALLOWED[$kind] as $name) {
      // See CanvasWriter::inputs(): the style engine drops a value that is
      // not a string.
      if (array_key_exists($name, $values) && (is_string($values[$name]) || !PropSchema::isStyleEngineValue($node->name, $name))) {
        $settings[$name] = $values[$name];
      }
    }
    $settings = match ($kind) {
      'latest_posts' => $this->latestPosts($settings, $mapping),
      'page_list' => $this->pageList($settings, $mapping),
      'latest_comments' => $this->latestComments($settings, $node, $mapping),
    };
    return ['kind' => $kind, 'settings' => $settings];
  }

  /**
   * Overlays a latest-posts block's mapped settings onto its attributes.
   */
  private function latestPosts(array $settings, ContentMapping $mapping): array {
    $settings['bundle'] = $mapping->target('post');
    foreach (['excerpt_field' => 'excerpt', 'image_field' => 'featured_image'] as $setting => $concept) {
      $settings[$setting] = $mapping->has($concept) && !$mapping->drops($concept) ? $mapping->target($concept) : '';
    }
    return $settings;
  }

  /**
   * Overlays a page-list block's mapped settings onto its attributes.
   */
  private function pageList(array $settings, ContentMapping $mapping): array {
    $settings['bundle'] = $mapping->target('page');
    return $settings;
  }

  /**
   * Overlays a latest-comments block's mapped settings onto its attributes.
   */
  private function latestComments(array $settings, BlockNode $node, ContentMapping $mapping): array {
    $settings['field_name'] = $mapping->target('comments');
    // WordPress reads the deprecated displayExcerpt before displayContent.
    if (isset($node->attributes['displayExcerpt'])) {
      $settings['display_content'] = $node->attributes['displayExcerpt'] ? 'excerpt' : 'none';
    }
    return $settings;
  }

}
