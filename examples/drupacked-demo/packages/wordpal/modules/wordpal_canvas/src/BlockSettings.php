<?php

declare(strict_types=1);

namespace Drupal\wordpal_canvas;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\canvas\PropSource\PropSource;
use Drupal\wordpal_convert\Component\BlockPluginInputs;
use Drupal\wordpal_convert\Component\CommentBlockInputs;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Content\MediaSourceField;
use Drupal\wordpal_convert\Theme\AuthorBio;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\PostDate;

/**
 * Builds a Block plugin's settings and a dynamic block's field bindings.
 *
 * Each block name's settings are unit-testable on their own here, not only
 * reachable through writePattern() or writeContentTemplate(). The comments
 * block's own settings and layout tree are CommentBlockInputs's job
 * (wordpal_convert), shared with Display Builder.
 */
final class BlockSettings {

  public function __construct(
    private readonly BlockPluginInputs $blockPluginInputs,
    private readonly CommentBlockInputs $commentBlockInputs,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns flat Canvas inputs for a WordPal block component.
   *
   * The caller resolves a comments block (core/comments and its variants)
   * itself, since their layout walks the block tree the way addNodes() does.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param array $defaultSettings
   *   The component's own default settings, ready to override.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used by mapped attributes, such as the mapped menu.
   */
  public function pluginInputs(BlockNode $node, array $defaultSettings, ContentMapping $mapping): array {
    return $this->blockPluginInputs->inputs($node, $defaultSettings, $mapping, $this->commentBlockInputs->settingKeys()[$node->name] ?? NULL);
  }

  /**
   * Returns field bindings for one dynamic WordPress block, or NULL.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param string $bundle
   *   The node bundle the binding reads.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping the binding's field comes from.
   * @param array $staticInputs
   *   The block's own static inputs, from its saved attributes, ready to
   *   merge the field bindings over.
   * @param bool $isPostBundle
   *   Whether $bundle is the mapping's post bundle, which decides between a
   *   post's and a page's body and excerpt concept.
   */
  public function dynamicFieldBindings(BlockNode $node, string $bundle, ContentMapping $mapping, array $staticInputs, bool $isPostBundle): ?array {
    $field = static function (string $expression, ?string $adapter = NULL): array {
      $binding = ['sourceType' => PropSource::EntityField->value, 'expression' => $expression];
      if ($adapter !== NULL) {
        $binding['adapter'] = $adapter;
      }
      return $binding;
    };
    $hostUrl = ['sourceType' => PropSource::HostEntityUrl->value, 'absolute' => FALSE];
    $base = "ℹ︎␜entity:node:{$bundle}␝";
    return match ($node->name) {
      'core/post-title' => array_replace($staticInputs, ['title' => $field($base . 'title␞␟value'), 'url' => $hostUrl]),
      'core/post-date' => $this->postDateInputs($node, $staticInputs, $base, $field, $hostUrl),
      'core/post-author' => array_replace($staticInputs, [
        'author_id' => $field($base . 'uid␞␟target_id'),
      ], $this->authorBioInputs($node, $mapping, $base, $field)),
      'core/post-author-name', 'core/avatar' => array_replace($staticInputs, [
        'author_id' => $field($base . 'uid␞␟target_id'),
      ]),
      'core/read-more' => array_replace($staticInputs, ['title' => $field($base . 'title␞␟value'), 'url' => $hostUrl]),
      'core/post-featured-image' => $this->imageInputs(
        $staticInputs,
        $bundle,
        $base,
        (string) $mapping->target('featured_image'),
        $field,
        $hostUrl,
      ),
      'core/post-content' => array_replace($staticInputs, ['content' => $field($base . $mapping->target($isPostBundle ? 'post_body' : 'page_body') . '␞␟processed')]),
      'core/post-excerpt' => $this->excerptInputs($staticInputs, $bundle, $base, (string) $mapping->target($isPostBundle ? 'excerpt' : 'page_excerpt'), $field),
      'core/post-terms' => $this->termInputs($node, $staticInputs, $base, $mapping, $field),
      'core/cover' => ($node->attributes['useFeaturedImage'] ?? FALSE)
        ? $this->coverImageInputs($staticInputs, $bundle, $base, (string) $mapping->target('featured_image'), $field)
        : NULL,
      'core/media-text' => ($node->attributes['useFeaturedImage'] ?? FALSE)
        ? $this->mediaTextImageInputs($staticInputs, $bundle, $base, (string) $mapping->target('featured_image'), $field)
        : NULL,
      default => NULL,
    };
  }

  /**
   * Builds featured image field bindings.
   */
  private function imageInputs(array $staticInputs, string $bundle, string $base, string $fieldName, callable $field, array $hostUrl): array {
    $definition = $this->fieldManager->getFieldDefinitions('node', $bundle)[$fieldName];
    $imageBase = $base . "{$fieldName}␞␟";
    if ($definition->getType() === 'entity_reference') {
      [$mediaBundle, $sourceField] = MediaSourceField::resolve($definition, $this->entityTypeManager);
      $imageBase .= "entity␜␜entity:media:{$mediaBundle}␝{$sourceField}␞␟";
    }
    return array_replace($staticInputs, [
      'src' => $field($imageBase . 'src'),
      'alt' => $field($imageBase . 'alt'),
      'title' => $field($base . 'title␞␟value'),
      'image_width' => $field($imageBase . 'width'),
      'image_height' => $field($imageBase . 'height'),
      'url' => $hostUrl,
    ]);
  }

  /**
   * Builds a featured-image binding for a dynamic Cover background.
   */
  private function coverImageInputs(array $staticInputs, string $bundle, string $base, string $fieldName, callable $field): array {
    $definition = $this->fieldManager->getFieldDefinitions('node', $bundle)[$fieldName];
    $imageBase = $base . "{$fieldName}␞␟";
    if ($definition->getType() === 'entity_reference') {
      [$mediaBundle, $sourceField] = MediaSourceField::resolve($definition, $this->entityTypeManager);
      $imageBase .= "entity␜␜entity:media:{$mediaBundle}␝{$sourceField}␞␟";
    }
    return array_replace($staticInputs, [
      'url' => $field($imageBase . 'src'),
      'alt' => $field($imageBase . 'alt'),
      'image_width' => $field($imageBase . 'width'),
      'image_height' => $field($imageBase . 'height'),
    ]);
  }

  /**
   * Builds a Media & Text featured-image binding, for useFeaturedImage.
   *
   * Media-text.php prints the featured image's own attachment id as a
   * `wp-image-N` class, unlike Cover's featured image; media_size_slug
   * keeps the block's own saved value, defaulting through the component the
   * same way a static Media & Text does.
   */
  private function mediaTextImageInputs(array $staticInputs, string $bundle, string $base, string $fieldName, callable $field): array {
    $definition = $this->fieldManager->getFieldDefinitions('node', $bundle)[$fieldName];
    $srcBase = $base . "{$fieldName}␞␟";
    if ($definition->getType() === 'entity_reference') {
      [$mediaBundle, $sourceField] = MediaSourceField::resolve($definition, $this->entityTypeManager);
      $srcBase .= "entity␜␜entity:media:{$mediaBundle}␝{$sourceField}␞␟";
    }
    return array_replace($staticInputs, [
      'media_url' => $field($srcBase . 'src'),
      'media_alt' => $field($srcBase . 'alt'),
      'media_id' => $field($base . "{$fieldName}␞␟target_id"),
      'media_type' => 'image',
    ]);
  }

  /**
   * Builds Post Date bindings, modified or published.
   *
   * WordPress's post-date.php reads the node's changed field for a
   * "modified" datetime binding or displayType, published otherwise. Only
   * "modified" also binds published_timestamp, so the component can print
   * nothing the way post-data.php's modified source does for a post never
   * edited after publishing.
   */
  private function postDateInputs(BlockNode $node, array $staticInputs, string $base, callable $field, array $hostUrl): array {
    $modified = PostDate::isModified($node);
    $sourceField = $modified ? 'changed' : 'created';
    $bindings = [
      'date' => $field($base . $sourceField . '␞␟value', 'unix_to_date'),
      'timestamp' => $field($base . $sourceField . '␞␟value'),
      'url' => $hostUrl,
    ];
    if ($modified) {
      $bindings['published_timestamp'] = $field($base . 'created␞␟value');
    }
    return array_replace($staticInputs, $bindings);
  }

  /**
   * Builds the Author block's biography binding when it shows one.
   *
   * The biography is the mapped user field of the post's author; a mapping
   * that drops it leaves the prop unbound.
   */
  private function authorBioInputs(BlockNode $node, ContentMapping $mapping, string $base, callable $field): array {
    $source = AuthorBio::source($node, $mapping, $this->fieldManager);
    if ($source === NULL) {
      return [];
    }
    [$fieldName, $property] = $source;
    return ['author_bio' => $field($base . "uid␞␟entity␜␜entity:user␝{$fieldName}␞␟{$property}")];
  }

  /**
   * Builds excerpt bindings for text and plain string fields.
   */
  private function excerptInputs(array $staticInputs, string $bundle, string $base, string $fieldName, callable $field): array {
    $type = $this->fieldManager->getFieldDefinitions('node', $bundle)[$fieldName]->getType();
    $property = in_array($type, ['text', 'text_long'], TRUE) ? 'processed' : 'value';
    return array_replace($staticInputs, [
      'excerpt' => $field($base . $fieldName . '␞␟' . $property),
      'url' => ['sourceType' => PropSource::HostEntityUrl->value, 'absolute' => FALSE],
    ]);
  }

  /**
   * Builds taxonomy term bindings for category or tag blocks.
   *
   * Both bindings request every delta of the mapped field, which the
   * evaluator returns as an array for a multiple-cardinality field but
   * collapses to a scalar for a single-cardinality one. The `ensure_array`
   * adapter normalizes either shape to the list `terms`/`term_urls`
   * declare, so a legitimately single-valued mapped field still renders
   * instead of failing SDC's prop-shape validation.
   */
  private function termInputs(BlockNode $node, array $staticInputs, string $base, ContentMapping $mapping, callable $field): array {
    $concept = ($node->attributes['term'] ?? 'category') === 'category' ? 'category' : 'tag';
    $target = $mapping->target($concept);
    return array_replace($staticInputs, [
      'terms' => $field($base . $target['field'] . '␞␟entity␜␜entity:taxonomy_term:' . $target['vocabulary'] . '␝name␞␟value', 'ensure_array'),
      'term_urls' => $field($base . $target['field'] . '␞␟url', 'ensure_array'),
      'taxonomy' => $node->attributes['term'] ?? 'category',
    ]);
  }

}
