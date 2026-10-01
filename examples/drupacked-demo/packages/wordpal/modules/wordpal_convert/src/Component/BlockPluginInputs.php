<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

use Drupal\wordpal\Plugin\Block\PageTitleBlock;
use Drupal\wordpal\Support\PropSchema;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\NavigationMenus;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\DrupalRouteFrame;
use Drupal\wordpal_convert\Theme\TreeConcepts;

/**
 * Builds a WordPal Block plugin's settings, shared by every Builder target.
 */
final class BlockPluginInputs {

  /**
   * The blocks whose Block plugin keeps its support props in one setting.
   */
  public const SUPPORT_BLOCKS = [
    'core/site-title',
    'core/site-tagline',
    'core/site-logo',
    'core/navigation',
    'core/search',
    'core/categories',
    'core/tag-cloud',
    'core/archives',
    'core/post-author-biography',
    'core/post-navigation-link',
  ];

  /**
   * The WordPal Block plugin id of each WordPress block a plugin renders.
   *
   * The comment blocks of CommentBlockInputs::BLOCK_NAMES all map to
   * wordpal_comments through pluginId().
   */
  public const PLUGINS = [
    'core/site-title' => 'wordpal_site_title',
    'core/site-tagline' => 'wordpal_site_tagline',
    'core/site-logo' => 'wordpal_site_logo',
    'core/navigation' => 'wordpal_navigation',
    'core/post-navigation-link' => 'wordpal_adjacent_post',
    'core/tag-cloud' => 'wordpal_tag_cloud',
    'core/archives' => 'wordpal_archives',
    'core/categories' => 'wordpal_categories',
    'core/search' => 'wordpal_search',
    'core/post-author-biography' => 'wordpal_post_author_biography',
    DrupalRouteFrame::PAGE_TITLE => 'wordpal_page_title',
  ];

  public function __construct(
    private readonly AttributeFlattener $flattener,
    private readonly ComponentSet $componentSet,
    private readonly CommentBlockInputs $commentBlockInputs,
  ) {}

  /**
   * Returns a WordPal Block plugin's settings for one WordPress block.
   *
   * A block of self::SUPPORT_BLOCKS keeps its support props under the
   * "supports" setting, and its own attributes as settings of their own.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param array $defaultSettings
   *   The plugin's own default settings, ready to override.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used by mapped attributes, such as the mapped menu.
   * @param string[]|null $allowed
   *   The setting keys the block keeps; NULL uses the block's own list.
   */
  public function inputs(BlockNode $node, array $defaultSettings, ContentMapping $mapping, ?array $allowed = NULL): array {
    if ($node->name === 'core/post-navigation-link') {
      return $this->navigationSettings($node);
    }
    $allowed ??= match ($node->name) {
      'core/site-title' => ['level', 'is_link', 'link_target'],
      'core/site-tagline' => ['level'],
      'core/site-logo' => ['width', 'is_link', 'link_target'],
      'core/navigation' => [
        'show_submenu_icon', 'submenu_visibility', 'overlay_menu', 'icon', 'has_icon',
        'text_color', 'custom_text_color', 'background_color', 'custom_background_color',
        'overlay_background_color', 'custom_overlay_background_color',
        'overlay_text_color', 'custom_overlay_text_color',
      ],
      'core/categories' => ['show_count', 'show_hierarchy', 'show_empty', 'display_as_dropdown'],
      'core/search' => ['label', 'show_label', 'button_text', 'button_position', 'button_use_icon'],
      'core/post-author-biography' => [],
      'core/tag-cloud' => [
        'number_of_tags', 'show_tag_counts', 'smallest_font_size', 'largest_font_size',
      ],
      'core/archives' => ['show_post_counts', 'display_as_dropdown'],
      DrupalRouteFrame::PAGE_TITLE => PageTitleBlock::PROPS,
      default => throw new \UnexpectedValueException("Unsupported WordPress block component: {$node->name}"),
    };
    $inputs = $defaultSettings;
    // The page title stands for a Post Title block and carries its attributes.
    $blockName = $node->name === DrupalRouteFrame::PAGE_TITLE ? 'core/post-title' : $node->name;
    $values = $this->flattener->flatten($node->attributes, $blockName);
    $attributes = $this->allowedValues($values, $allowed, $blockName);
    if (in_array($node->name, self::SUPPORT_BLOCKS, TRUE)) {
      $attributes['supports'] = $this->allowedValues($values, $this->componentSet->blockSupports()[$node->name], $blockName);
    }
    if ($node->name === 'core/navigation') {
      $menu = NavigationMenus::links($node) === NULL ? NULL : NavigationMenus::menuId($mapping->themeId(), $node);
      $attributes['menu'] = $menu ?? $mapping->target('navigation');
      // WordPress names a block by the site navigation's title only when it
      // renders that navigation: a ref or no inner blocks. Any other block is
      // named by its ariaLabel alone.
      $attributes['menu_label'] = isset($node->attributes['ref']) || $node->children === [];
      // A navigation holding a Page List prints the mapped menu in its markup.
      // Only a block with its own links prints what follows them.
      $attributes['trailing'] = $menu === NULL ? [] : $this->commentBlockInputs->layout(NavigationMenus::trailing($node));
      $attributes['page_list'] = $node->children !== [] && array_filter($node->children, static fn (BlockNode $child): bool => $child->name !== 'core/page-list') === [];
    }
    elseif ($node->name === 'core/categories') {
      $attributes['taxonomy'] = $node->attributes['taxonomy'] ?? 'category';
      $attributes['vocabulary'] = $mapping->target(TreeConcepts::concept($node))['vocabulary'];
    }
    elseif ($node->name === 'core/search') {
      $attributes['path'] = $mapping->target('search')['path'];
      $attributes['parameter'] = $mapping->target('search')['parameter'];
      // The flattener drops placeholder as editor-only, and Search prints it.
      if (isset($node->attributes['placeholder'])) {
        $attributes['placeholder'] = $node->attributes['placeholder'];
      }
      if (isset($node->attributes['width'], $node->attributes['widthUnit'])) {
        $unit = $node->attributes['widthUnit'];
        // WordPress block attributes are external snapshot input.
        if (!in_array($unit, ['%', 'px', 'em', 'rem', 'vw', 'vh'], TRUE)) {
          throw new \UnexpectedValueException("Unsupported Search width unit: $unit");
        }
        $attributes['width'] = (string) $node->attributes['width'] . $unit;
      }
    }
    elseif ($node->name === 'core/post-author-biography') {
      $attributes['field_name'] = $mapping->target('author_biography');
    }
    elseif ($node->name === 'core/archives') {
      $attributes['bundle'] = $mapping->target('post');
    }
    elseif ($node->name === 'core/tag-cloud') {
      $attributes['vocabulary'] = $mapping->target('tag')['vocabulary'];
      foreach (['smallest_font_size', 'largest_font_size'] as $size) {
        // WordPress block attributes are external snapshot input.
        if (isset($attributes[$size]) && !preg_match('/^\d+(\.\d+)?(pt|px|em|rem|%)$/', (string) $attributes[$size])) {
          throw new \UnexpectedValueException("Unsupported Tag Cloud font size: {$attributes[$size]}");
        }
      }
    }
    return array_replace($inputs, $attributes);
  }

  /**
   * Returns the WordPal Block plugin id for a WordPress block, or NULL.
   */
  public static function pluginId(string $blockName): ?string {
    if (in_array($blockName, CommentBlockInputs::BLOCK_NAMES, TRUE)) {
      return 'wordpal_comments';
    }
    return self::PLUGINS[$blockName] ?? NULL;
  }

  /**
   * Returns the flattened values of the allowed keys.
   */
  private function allowedValues(array $values, array $allowed, string $blockName): array {
    $kept = [];
    foreach ($allowed as $name) {
      // The style engine drops a value that is not a string.
      if (array_key_exists($name, $values) && (is_string($values[$name]) || !PropSchema::isStyleEngineValue($blockName, $name))) {
        $kept[$name] = $values[$name];
      }
    }
    return $kept;
  }

  /**
   * Maps WordPress adjacent-link options to block plugin settings.
   */
  private function navigationSettings(BlockNode $node): array {
    $values = $this->flattener->flatten($node->attributes, $node->name);
    return [
      'supports' => $this->allowedValues($values, $this->componentSet->blockSupports()[$node->name], $node->name),
      'label_display' => '0',
      'direction' => $node->attributes['type'] ?? 'next',
      'label' => $node->attributes['label'] ?? '',
      'show_title' => $node->attributes['showTitle'] ?? FALSE,
      'link_label' => $node->attributes['linkLabel'] ?? FALSE,
      'arrow' => $node->attributes['arrow'] ?? 'none',
    ];
  }

}
