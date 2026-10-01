<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Content\MediaSourceField;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\TreeConcepts;

/**
 * Binds a runtime component's props to the routed node's own fields.
 *
 * Each case below names a chain: a field property, an entity link, or a
 * referenced entity's field property, resolved against the Content
 * mapping's field names and built as UI Patterns `entity_field`/
 * `entity_reference` source configuration (verified against the installed
 * ui_patterns 2.0.21: a "value" key holds either a leaf source directly, or
 * another derivable-context source of the same shape, one hop per
 * reference crossed). A dropped concept (no target at all, such as the
 * default mapping's `category: null`) omits the block, the same as
 * TreeConcepts::concept() drives elsewhere; a concept that IS bound but
 * resolves to a field the bundle lacks fails the conversion and names the
 * prop, since that is a mapping mistake, not a deliberate absence. Post
 * Featured Image and Post Terms are the exceptions: WordPress prints nothing
 * for them when a post has no thumbnail or no terms, so omission() treats a
 * bundle with no mapped field the same as a bundle whose posts carry none.
 * Post Featured Image is also reported as dropped, by the SourceTreeBuilder
 * walk that omits it.
 */
final class RuntimeBindings {

  public function __construct(
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns how a content template omits this block, or NULL to keep it.
   *
   * Mirrors TreeConcepts::concept(): the same concepts that block gathers
   * for reporting elsewhere are the ones a runtime binding needs, so a
   * dropped one is omitted here rather than bound to nothing. Post Featured
   * Image and Post Terms also omit when the concept is bound but this
   * particular bundle has no such field (see the class doc comment); the
   * other cases fall through to props(), which fails loudly on the same
   * shortfall since it is a mapping mistake for them. Only the missing
   * image is reported, and SourceTreeBuilder records it.
   */
  public function omission(BlockNode $node, string $bundle, ContentMapping $mapping): ?Omission {
    $isPost = $mapping->has('post') && $bundle === $mapping->target('post');
    $concept = match ($node->name) {
      'core/post-content' => $isPost ? 'post_body' : 'page_body',
      'core/post-excerpt' => $isPost ? 'excerpt' : 'page_excerpt',
      'core/post-featured-image' => 'featured_image',
      'core/post-terms' => TreeConcepts::concept($node),
      default => NULL,
    };
    if ($concept === NULL) {
      return NULL;
    }
    // Not $mapping->drops(): a concept no Template or pattern ever required
    // (checked-in bindings for a runtime component the theme's trees never
    // use) never reaches ContentMapping's own targets, and drops() would
    // read past it. !binds() treats that the same as an explicit null
    // target: omitted either way.
    if (!$mapping->binds($concept)) {
      return Omission::Silent;
    }
    $printsNothingWhenEmpty = in_array($node->name, ['core/post-featured-image', 'core/post-terms'], TRUE);
    if (!$printsNothingWhenEmpty || $this->fieldFor($mapping, $concept, $bundle) !== NULL) {
      return NULL;
    }
    return $node->name === 'core/post-featured-image' ? Omission::MissingImage : Omission::Silent;
  }

  /**
   * Returns whether a content template must omit this block entirely.
   *
   * Records nothing: writeRuntimePresets() and runtimePresetIds() call it
   * for every bundle, whether or not a real tree ever uses the block.
   */
  public function omits(BlockNode $node, string $bundle, ContentMapping $mapping): bool {
    return $this->omission($node, $bundle, $mapping) !== NULL;
  }

  /**
   * Returns one block's runtime prop sources, or NULL for a static block.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param string $bundle
   *   The node bundle the content template renders.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets the bindings resolve against.
   *
   * @return array<string, array>|null
   *   UI Patterns sources keyed by prop id, overlaid onto the tree's own
   *   static props for those keys; NULL when the block name has no runtime
   *   binding.
   *
   * @throws \UnexpectedValueException
   *   When a binding names a concept the mapping drops, or a field the
   *   bundle lacks.
   */
  public function props(BlockNode $node, string $bundle, ContentMapping $mapping): ?array {
    $isPost = $mapping->has('post') && $bundle === $mapping->target('post');
    return match ($node->name) {
      'core/post-title' => [
        'title' => self::field('node', $bundle, 'title', self::leaf('field_property:node:title:value')),
        'url' => self::entityLink(),
      ],
      'core/post-date' => $this->postDate($node, $bundle),
      'core/post-author-name', 'core/post-author', 'core/avatar' => [
        'author_id' => self::field('node', $bundle, 'uid', self::leaf('field_property:node:uid:target_id')),
      ],
      'core/read-more' => [
        'title' => self::field('node', $bundle, 'title', self::leaf('field_property:node:title:value')),
        'url' => self::entityLink(),
      ],
      'core/post-content' => [
        'content' => self::field('node', $bundle, $field = $this->concept($node->name, 'content', $mapping, $isPost ? 'post_body' : 'page_body', $bundle), self::leaf("field_property:node:$field:processed")),
      ],
      'core/post-excerpt' => $this->excerpt($node, $bundle, $mapping, $isPost),
      'core/post-featured-image' => $this->featuredImage($node, $bundle, $mapping),
      'core/cover' => ($node->attributes['useFeaturedImage'] ?? FALSE) ? $this->coverImage($node, $bundle, $mapping) : NULL,
      'core/media-text' => ($node->attributes['useFeaturedImage'] ?? FALSE) ? $this->mediaTextImage($node, $bundle, $mapping) : NULL,
      'core/post-terms' => $this->terms($node, $bundle, $mapping),
      default => NULL,
    };
  }

  /**
   * Returns one block's runtime slot sources, or NULL for a static block.
   *
   * Post Terms fills its slot with one Post Term link per term the node
   * references, through UI Patterns' per-item field formatter, which sets
   * the field delta each link's reference reads.
   *
   * @return array<string, array[]>|null
   *   UI Patterns sources keyed by slot id; NULL when the block name has no
   *   runtime slot.
   *
   * @throws \UnexpectedValueException
   *   When the mapping drops the block's term concept, or the bundle lacks
   *   its field.
   */
  public function slots(BlockNode $node, string $bundle, ContentMapping $mapping): ?array {
    if ($node->name !== 'core/post-terms') {
      return NULL;
    }
    $concept = TreeConcepts::concept($node);
    $field = $this->resolvedField($node->name, 'content', $mapping, $concept, $bundle)->getName();
    $vocabulary = $mapping->target($concept)['vocabulary'];
    // Unlike `entity_reference`, this source keeps the per-item formatter's
    // field delta when it switches to the referenced term.
    $context = self::referenceContext('node', $bundle, $field, 'taxonomy_term', $vocabulary);
    $term = static fn (array $leaf): array => [
      'source_id' => "entity:field_property:node:$field:target_id",
      'source' => ['derivable_context' => $context, $context => ['value' => $leaf]],
    ];
    $links = [
      'source_id' => "field_formatter:node:$bundle:$field",
      'source' => [
        'type' => 'ui_patterns_component_per_item',
        'settings' => [
          'ui_patterns' => [
            'component_id' => SourceTreeBuilder::PROVIDER . ':' . ComponentSet::POST_TERM_SLUG,
            'props' => [
              'label' => $term(self::leaf('field_property:taxonomy_term:name:value')),
              'url' => $term(self::entityLink()),
            ],
            'slots' => [],
          ],
        ],
      ],
    ];
    return ['content' => [$links]];
  }

  /**
   * Builds Post Date bindings, modified or published.
   *
   * Not "date": the checked-in post-date.twig formats "timestamp" itself
   * and never reads "date", whose declared `format: date` (YYYY-MM-DD)
   * ui_patterns' own component validator enforces strictly, with no
   * timestamp-to-date-string adapter to satisfy it, unlike "url"'s stream
   * wrapper conversion (UrlPropType::normalize()). Only "modified" also
   * binds published_timestamp, matching WordPress's own post-data.php
   * modified source.
   */
  private function postDate(BlockNode $node, string $bundle): array {
    $modified = ($node->attributes['displayType'] ?? 'date') === 'modified';
    $sourceField = $modified ? 'changed' : 'created';
    $bindings = [
      'timestamp' => self::field('node', $bundle, $sourceField, self::leaf("field_property:node:$sourceField:value")),
      'url' => self::entityLink(),
    ];
    if ($modified) {
      $bindings['published_timestamp'] = self::field('node', $bundle, 'created', self::leaf('field_property:node:created:value'));
    }
    return $bindings;
  }

  /**
   * Builds excerpt bindings for text and plain string fields.
   */
  private function excerpt(BlockNode $node, string $bundle, ContentMapping $mapping, bool $isPost): array {
    $field = $this->resolvedField($node->name, 'excerpt', $mapping, $isPost ? 'excerpt' : 'page_excerpt', $bundle);
    $property = in_array($field->getType(), ['text', 'text_long'], TRUE) ? 'processed' : 'value';
    return [
      'excerpt' => self::field('node', $bundle, $field->getName(), self::leaf("field_property:node:{$field->getName()}:$property")),
      'url' => self::entityLink(),
    ];
  }

  /**
   * Builds featured image field bindings.
   *
   * A plain `image` field's own item carries alt, width and height
   * directly, one hop into its own File for "src". An `entity_reference`
   * to Media crosses into the media bundle's own source field for alt,
   * width and height, and a further hop into the File it references for
   * "src". Either way, "src"'s raw file URI is not a usable link on its
   * own: the WordPal component declares it uri-reference, and UI Patterns'
   * own "url" prop type resolves a stream-wrapper URI through the file URL
   * generator at render time (UrlPropType::normalize()), so the file's own
   * "uri" property is enough.
   */
  private function featuredImage(BlockNode $node, string $bundle, ContentMapping $mapping): array {
    $field = $this->resolvedField($node->name, 'src', $mapping, 'featured_image', $bundle);
    $fieldName = $field->getName();
    if ($field->getType() !== 'entity_reference') {
      return [
        'src' => self::field('node', $bundle, $fieldName, self::reference('node', $bundle, $fieldName, 'file', 'file', self::leaf('field_property:file:uri:value'))),
        'alt' => self::field('node', $bundle, $fieldName, self::leaf("field_property:node:$fieldName:alt")),
        'image_width' => self::field('node', $bundle, $fieldName, self::leaf("field_property:node:$fieldName:width")),
        'image_height' => self::field('node', $bundle, $fieldName, self::leaf("field_property:node:$fieldName:height")),
        'url' => self::entityLink(),
      ];
    }
    [$mediaBundle, $sourceField] = MediaSourceField::resolve($field, $this->entityTypeManager);
    $toMedia = static fn (array $leaf): array => self::field('node', $bundle, $fieldName, self::reference('node', $bundle, $fieldName, 'media', $mediaBundle, $leaf));
    return [
      'src' => $toMedia(self::reference('media', $mediaBundle, $sourceField, 'file', 'file', self::leaf('field_property:file:uri:value'))),
      'alt' => $toMedia(self::leaf("field_property:media:$sourceField:alt")),
      'image_width' => $toMedia(self::leaf("field_property:media:$sourceField:width")),
      'image_height' => $toMedia(self::leaf("field_property:media:$sourceField:height")),
      'url' => self::entityLink(),
    ];
  }

  /**
   * Builds a Cover's background from the featured image, for useFeaturedImage.
   */
  private function coverImage(BlockNode $node, string $bundle, ContentMapping $mapping): array {
    $image = $this->featuredImage($node, $bundle, $mapping);
    return [
      'url' => $image['src'],
      'alt' => $image['alt'],
      'image_width' => $image['image_width'],
      'image_height' => $image['image_height'],
    ];
  }

  /**
   * Builds a Media & Text featured image from the post, for useFeaturedImage.
   *
   * Media-text.php prints the featured image's own attachment id as a
   * `wp-image-N` class, unlike Cover's featured image.
   */
  private function mediaTextImage(BlockNode $node, string $bundle, ContentMapping $mapping): array {
    $image = $this->featuredImage($node, $bundle, $mapping);
    $fieldName = $this->resolvedField($node->name, 'media_id', $mapping, 'featured_image', $bundle)->getName();
    return [
      'media_url' => $image['src'],
      'media_alt' => $image['alt'],
      'media_id' => self::field('node', $bundle, $fieldName, self::leaf("field_property:node:$fieldName:target_id")),
      'media_type' => ['source_id' => 'textfield', 'source' => ['value' => 'image']],
    ];
  }

  /**
   * Builds taxonomy term bindings for category or tag blocks.
   *
   * The term links come from slots(); the list props stay for Canvas.
   */
  private function terms(BlockNode $node, string $bundle, ContentMapping $mapping): array {
    return [
      'taxonomy' => ['source_id' => 'textfield', 'source' => ['value' => $node->attributes['term'] ?? 'category']],
    ];
  }

  /**
   * Returns the mapped field a binding needs, or fails naming the prop.
   */
  private function resolvedField(string $blockName, string $prop, ContentMapping $mapping, string $concept, string $bundle): FieldDefinitionInterface {
    if (!$mapping->binds($concept)) {
      throw new \UnexpectedValueException("$blockName: the Content mapping drops $concept, needed for the $prop prop.");
    }
    $field = $this->fieldFor($mapping, $concept, $bundle);
    if ($field === NULL) {
      throw new \UnexpectedValueException("$blockName: $bundle has no " . self::fieldName($mapping->target($concept)) . " field, needed for the $prop prop.");
    }
    return $field;
  }

  /**
   * Returns the field a bound concept resolves to on a bundle, or NULL.
   */
  private function fieldFor(ContentMapping $mapping, string $concept, string $bundle): ?FieldDefinitionInterface {
    return $this->fieldManager->getFieldDefinitions('node', $bundle)[self::fieldName($mapping->target($concept))] ?? NULL;
  }

  /**
   * Returns a concept target's field name, whether a plain or reference one.
   */
  private static function fieldName(string|array $target): string {
    return is_array($target) ? $target['field'] : $target;
  }

  /**
   * Returns the field name a concept resolves to, or fails naming the prop.
   */
  private function concept(string $blockName, string $prop, ContentMapping $mapping, string $concept, string $bundle): string {
    return $this->resolvedField($blockName, $prop, $mapping, $concept, $bundle)->getName();
  }

  /**
   * Wraps a leaf source in a field's own derivable context.
   */
  private static function field(string $entityType, string $bundle, string $field, array $leaf): array {
    $context = "field:$entityType:$bundle:$field";
    return [
      'source_id' => 'entity_field',
      'source' => [
        'derivable_context' => $context,
        $context => ['value' => $leaf],
      ],
    ];
  }

  /**
   * Wraps a leaf source in a crossed-reference's derivable context.
   */
  private static function reference(string $entityType, string $bundle, string $field, string $targetType, string $targetBundle, array $leaf): array {
    $context = self::referenceContext($entityType, $bundle, $field, $targetType, $targetBundle);
    return [
      'source_id' => 'entity_reference',
      'source' => [
        'derivable_context' => $context,
        $context => ['value' => $leaf],
      ],
    ];
  }

  /**
   * Returns the derivable context id for crossing a reference field.
   */
  private static function referenceContext(string $entityType, string $bundle, string $field, string $targetType, string $targetBundle): string {
    return "entity_reference:$entityType:$bundle:$field:$targetType:$targetBundle";
  }

  /**
   * Returns a leaf UI Patterns source configuration.
   */
  private static function leaf(string $sourceId, array $source = []): array {
    return $source === [] ? ['source_id' => $sourceId] : ['source_id' => $sourceId, 'source' => $source];
  }

  /**
   * Returns the routed node's own canonical URL.
   *
   * Not `absolute: FALSE`: the installed ui_patterns 2.0.21 schema for this
   * source (`ui_patterns_source.entity_link`) declares only `template`, and
   * a Views row's own display_options.row.options schema, unlike a content
   * template's own third-party settings, validates strictly against it.
   * Leaving the key out relies on EntityLinksSource::defaultSettings()'s own
   * FALSE default, the same value.
   */
  private static function entityLink(): array {
    return self::leaf('entity_link', ['template' => 'canonical']);
  }

}
