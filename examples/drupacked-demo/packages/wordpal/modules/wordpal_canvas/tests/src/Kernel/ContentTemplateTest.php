<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\comment\Entity\CommentType;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_canvas\CanvasWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * Tests mapped Canvas content-template generation.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ContentTemplateTest extends CanvasKernelTestBase {

  /**
   * Modules required for mapped node fields.
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal',
    'wordpal_convert',
    'wordpal_canvas',
    'wordpal_canvas_runtime',
    'system',
    'user',
    'field',
    'node',
    'taxonomy',
    'filter',
    'text',
    'file',
    'image',
    'comment',
  ];

  /**
   * Creates a mapped post bundle and its Canvas components.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('comment');
    $this->installConfig(['field', 'node', 'taxonomy']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    Vocabulary::create(['vid' => 'categories', 'name' => 'Categories'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    CommentType::create([
      'id' => 'wordpal_comment',
      'label' => 'WordPal comment',
      'target_entity_type_id' => 'node',
    ])->save();
    $this->field('body', 'text_with_summary');
    $this->field('field_excerpt', 'text_long');
    $this->field('field_excerpt_plain', 'string_long');
    $this->field('field_image', 'image');
    $this->field('field_categories', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->field('field_other_categories', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->field('field_tags', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->field('field_comments', 'comment', ['comment_type' => 'wordpal_comment']);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:group', 'wordpal:heading']);
  }

  /**
   * Tests that one aggregate block retains the expanded comment layout.
   */
  public function testWritesAggregateCommentsBlock(): void {
    $mapping = $this->mapping('field_categories', comments: 'field_comments');
    $variant = $this->writeVariant(
      $this->container->get(CanvasWriter::class),
      $this->partSet(),
      'fixture',
      $mapping,
    );
    $result = $this->container->get(CanvasWriter::class)->writeContentTemplate(
      'wordpal_post',
      'full',
      [new BlockNode('core/comments', ['className' => 'wp-block-comments-query-loop'], '', [
        new BlockNode('core/comments-title', ['level' => 3], '', []),
        new BlockNode('core/comment-template', [], '', [
          new BlockNode(
          'core/group',
          [],
          '',
          [new BlockNode('core/avatar', ['size' => 40], '', [])],
          ),
        ]),
        new BlockNode('core/post-comments-form', [], '', []),
      ]),
      ],
      $mapping,
      $variant->id(),
    );

    $items = $result->getComponentTree()->getValue();
    self::assertCount(1, $items);
    self::assertSame('block.wordpal_comments', $items[0]['component_id']);
    self::assertSame('field_comments', $items[0]['inputs']['field_name']);
    self::assertSame('comments-title', $items[0]['inputs']['layout'][0]['marker']);
    self::assertSame('wordpal:group', $items[0]['inputs']['layout'][1]['children'][0]['component']);
    self::assertSame('avatar', $items[0]['inputs']['layout'][1]['children'][0]['children'][0]['marker']);
    self::assertSame('post-comments-form', $items[0]['inputs']['layout'][2]['marker']);
  }

  /**
   * Tests valid field bindings, null drops, navigation, and rebinding.
   */
  public function testWritesRebindableContentTemplate(): void {
    $mapping = $this->mapping('field_categories');
    $variant = $this->writeVariant(
      $this->container->get(CanvasWriter::class),
      $this->partSet(),
      'fixture',
      $mapping,
    );
    $result = $this->container->get(CanvasWriter::class)->writeContentTemplate(
      'wordpal_post',
      'full',
      $this->tree(),
      $mapping,
      $variant->id(),
    );

    self::assertSame(['comments' => 1], $mapping->droppedBlocks());
    self::assertTrue($result->status());
    self::assertSame($variant->id(), $result->get('page_variant'));
    self::assertContains('canvas.page_variant.' . $variant->id(), $result->getDependencies()['config']);
    $frameItems = $variant->getComponentTree()->getValue();
    self::assertSame([
      'sdc.wordpal.group',
      Marker::PAGE_CONTENT_COMPONENT_ID,
      'sdc.wordpal.group',
    ], array_column($frameItems, 'component_id'));
    self::assertSame('header', $frameItems[0]['inputs']['tag_name']);
    self::assertTrue($frameItems[0]['inputs']['template_part']);
    self::assertSame('footer', $frameItems[2]['inputs']['tag_name']);
    $items = $result->getComponentTree()->getValue();
    self::assertCount(2, array_filter($items, static fn (array $item): bool => $item['component_id'] === 'block.wordpal_adjacent_post'));
    self::assertStringContainsString('field_categories', $this->termExpression($items));
    self::assertStringContainsString('uid␞␟target_id', $this->inputExpression($items, 'sdc.wordpal.post-author-name', 'author_id'));
    self::assertStringContainsString('body␞␟processed', $this->inputExpression($items, 'sdc.wordpal.post-content', 'content'));
    self::assertStringContainsString('field_image␞␟src', $this->inputExpression($items, 'sdc.wordpal.cover', 'url'));

    // The writer trusts a caller not to collide on an id; rebinding through
    // the real wordpal:convert --replace flow deletes the owned template
    // first, so the test does too.
    ContentTemplate::load($result->id())->delete();
    $rewritten = $this->container->get(CanvasWriter::class)->writeContentTemplate(
      'wordpal_post',
      'full',
      $this->tree(),
      $this->mapping('field_other_categories', 'field_excerpt_plain'),
      $variant->id(),
    );
    $rewrittenItems = $rewritten->getComponentTree()->getValue();
    self::assertStringContainsString('field_other_categories', $this->termExpression($rewrittenItems));
    self::assertStringContainsString('field_excerpt_plain␞␟value', $this->inputExpression($rewrittenItems, 'sdc.wordpal.post-excerpt', 'excerpt'));

    // Confirm the rebind is what Canvas has on record, not only what the
    // writer's own return value reports.
    $reloadedItems = ContentTemplate::load($rewritten->id())->getComponentTree()->getValue();
    self::assertStringContainsString('field_other_categories', $this->termExpression($reloadedItems));
  }

  /**
   * Tests a page content template when the mapping has no post concept.
   *
   * A mapping override needs post only when some Template requires it, so
   * the page bundle's own content template must not compare itself against
   * a post target the mapping never declared.
   */
  public function testWritesPageContentTemplateWithoutPostMapping(): void {
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'page_text', 'type' => 'text_long'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'wordpal_page', 'field_name' => 'page_text'])->save();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:post-content']);
    $mapping = new ContentMapping(['page' => 'wordpal_page', 'page_body' => 'page_text']);
    $variant = $this->writeVariant($this->container->get(CanvasWriter::class), $this->partSet(), 'fixture', $mapping);

    $result = $this->container->get(CanvasWriter::class)->writeContentTemplate(
      'wordpal_page', 'full', [new BlockNode('core/post-content', [], '', [])], $mapping, $variant->id(),
    );

    $items = $result->getComponentTree()->getValue();
    self::assertSame('sdc.wordpal.post-content', $items[0]['component_id']);
    self::assertStringContainsString('page_text', $this->inputExpression($items, 'sdc.wordpal.post-content', 'content'));
  }

  /**
   * Tests invalid source attributes cannot disappear behind field bindings.
   */
  public function testRejectsUnsupportedBoundBlockValue(): void {
    $mapping = $this->mapping('field_categories');
    $variant = $this->writeVariant($this->container->get(CanvasWriter::class), $this->partSet(), 'fixture', $mapping);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:post-title']);

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('core/post-title: align="unsupported"');
    $this->container->get(CanvasWriter::class)->writeContentTemplate(
      'wordpal_post', 'full', [new BlockNode('core/post-title', ['align' => 'unsupported'], '', [])],
      $mapping, $variant->id(),
    );
  }

  /**
   * Tests that only a Template part's images print decoding="async".
   *
   * WordPress 7.1.2 runs wp_filter_content_tags() on a Template part's
   * content (blocks/template-part.php:153), and with context "template" on
   * the Template's own blocks, which adds none (media.php:6178).
   */
  public function testTemplatePartImageDecodesAsync(): void {
    $mapping = $this->mapping('field_categories');
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:image']);
    $image = new BlockNode('core/image', ['url' => 'https://example.com/logo.svg'], '', []);
    $writer = $this->container->get(CanvasWriter::class);
    $variant = $this->writeVariant($writer, new PartSet([
      ['type' => 'part', 'slug' => 'header', 'attributes' => ['tagName' => 'header'], 'nodes' => [$image]],
      ['type' => 'marker'],
    ]), 'fixture', $mapping);
    $template = $writer->writeContentTemplate('wordpal_post', 'full', [$image], $mapping, $variant->id());

    $inputs = static fn (array $items): array => current(array_filter($items, static fn (array $item): bool => $item['component_id'] === 'sdc.wordpal.image'))['inputs'];
    self::assertTrue($inputs($variant->getComponentTree()->getValue())['in_template_part']);
    self::assertArrayNotHasKey('in_template_part', $inputs($template->getComponentTree()->getValue()));
  }

  /**
   * Tests only a Gallery's own images get its in_gallery flag.
   *
   * WordPress copies each Gallery image's saved id onto a data-id attribute
   * before it renders (gallery.php,
   * block_core_gallery_data_id_backcompatibility()).
   */
  public function testGalleryImageGetsInGalleryFlag(): void {
    $mapping = $this->mapping('field_categories');
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:gallery', 'wordpal:image']);
    $inGallery = new BlockNode('core/image', ['id' => 12, 'url' => 'https://example.com/a.jpg'], '', []);
    $outside = new BlockNode('core/image', ['id' => 13, 'url' => 'https://example.com/b.jpg'], '', []);
    $gallery = new BlockNode('core/gallery', [], '', [$inGallery]);
    $writer = $this->container->get(CanvasWriter::class);
    $variant = $this->writeVariant($writer, $this->partSet(), 'fixture', $mapping);
    $template = $writer->writeContentTemplate('wordpal_post', 'full', [$gallery, $outside], $mapping, $variant->id());

    $items = $template->getComponentTree()->getValue();
    $images = array_values(array_filter($items, static fn (array $item): bool => $item['component_id'] === 'sdc.wordpal.image'));
    self::assertTrue($images[0]['inputs']['in_gallery']);
    self::assertArrayNotHasKey('in_gallery', $images[1]['inputs']);
  }

  /**
   * Tests a nested Template part's saved images print decoding="async".
   *
   * WordPress filters every image a Template part renders, wherever the part
   * sits: Image, Cover and Media & Text save their own <img>.
   */
  public function testNestedTemplatePartImagesDecodeAsync(): void {
    $mapping = $this->mapping('field_categories');
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', [
      'wordpal:image',
      'wordpal:cover',
      'wordpal:media-text',
      'wordpal:group',
    ]);
    $part = new BlockNode('core/group', ['tagName' => 'header', 'templatePart' => TRUE], '', [
      new BlockNode('core/group', [], '', [new BlockNode('core/image', ['url' => 'https://example.com/a.svg'], '', [])]),
      new BlockNode('core/cover', ['url' => 'https://example.com/b.jpg'], '', []),
      new BlockNode('core/media-text', ['mediaType' => 'image', 'mediaUrl' => 'https://example.com/c.jpg'], '', []),
    ]);
    $outside = new BlockNode('core/image', ['url' => 'https://example.com/d.svg'], '', []);
    $writer = $this->container->get(CanvasWriter::class);
    $variant = $this->writeVariant($writer, new PartSet([['type' => 'marker']]), 'fixture', $mapping);
    $template = $writer->writeContentTemplate('wordpal_post', 'full', [$part, $outside], $mapping, $variant->id());

    $flags = array_map(
      static fn (array $item): ?bool => $item['inputs']['in_template_part'] ?? NULL,
      array_values(array_filter($template->getComponentTree()->getValue(), static fn (array $item): bool => $item['component_id'] !== 'sdc.wordpal.group')),
    );
    self::assertSame([TRUE, TRUE, TRUE, NULL], $flags);
  }

  /**
   * Tests style values and the excerpt link the writer stores.
   *
   * WordPress 7.1.2 drops a style value that is not a string on a block
   * whose supports it renders at runtime
   * (WP_Style_Engine_CSS_Declarations::add_declaration()). It prints a
   * unitless border radius in pixels (block-supports/border.php:67-69). A
   * static block keeps the value its saved HTML holds.
   */
  public function testWritesStyleValuesAsWordPress(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => ['vocabulary' => 'categories', 'field' => 'field_categories'],
      'tag' => ['vocabulary' => 'tags', 'field' => 'field_tags'],
      'featured_image' => 'field_image',
      'excerpt' => 'field_excerpt',
      'comments' => NULL,
      'navigation' => 'main',
    ]);
    $writer = $this->container->get(CanvasWriter::class);
    $variant = $this->writeVariant($writer, $this->partSet(), 'fixture', $mapping);
    $numbers = ['style' => ['typography' => ['lineHeight' => 1.3, 'fontWeight' => 600], 'border' => ['radius' => 4]]];
    $template = $writer->writeContentTemplate('wordpal_post', 'full', [
      new BlockNode('core/post-title', $numbers, '', []),
      new BlockNode('core/heading', $numbers, '', []),
      new BlockNode('core/post-excerpt', [], '', []),
      new BlockNode('core/navigation', ['style' => ['typography' => ['fontWeight' => 600, 'fontStyle' => 'italic']]], '', []),
    ], $mapping, $variant->id());
    $items = $template->getComponentTree()->getValue();
    $inputs = static fn (string $component): array => current(array_filter($items, static fn (array $item): bool => $item['component_id'] === $component))['inputs'];

    $title = $inputs('sdc.wordpal.post-title');
    self::assertArrayNotHasKey('line_height', $title);
    self::assertArrayNotHasKey('font_weight', $title);
    self::assertSame('4px', $title['border_radius']);
    $heading = $inputs('sdc.wordpal.heading');
    self::assertSame('1.3', $heading['line_height']);
    self::assertSame('4', $heading['border_radius']);
    self::assertSame(['sourceType' => 'host-entity-url', 'absolute' => FALSE], $inputs('sdc.wordpal.post-excerpt')['url']);
    $supports = $inputs('block.wordpal_navigation')['supports'];
    self::assertArrayNotHasKey('font_weight', $supports);
    self::assertSame('italic', $supports['font_style']);
  }

  /**
   * Tests a Group's background image style writes its five flat props.
   *
   * The url arrives already resolved to the imported file, the way Cover's
   * own `url` attribute does: the writer copies it through unchanged.
   */
  public function testWritesGroupBackgroundImageStyle(): void {
    $mapping = $this->mapping('field_categories');
    $writer = $this->container->get(CanvasWriter::class);
    $variant = $this->writeVariant($writer, $this->partSet(), 'fixture', $mapping);
    $background = [
      'style' => [
        'background' => [
          'backgroundImage' => ['url' => 'https://example.com/wp-content/themes/spike-business/banner-img4.png'],
          'backgroundSize' => 'contain',
          'backgroundRepeat' => 'no-repeat',
          'backgroundPosition' => '50% 99%',
        ],
      ],
    ];
    $template = $writer->writeContentTemplate('wordpal_post', 'full', [
      new BlockNode('core/group', $background, '', []),
    ], $mapping, $variant->id());
    $items = $template->getComponentTree()->getValue();
    $group = current(array_filter($items, static fn (array $item): bool => $item['component_id'] === 'sdc.wordpal.group'))['inputs'];

    self::assertSame('https://example.com/wp-content/themes/spike-business/banner-img4.png', $group['background_image']);
    self::assertSame('contain', $group['background_size']);
    self::assertSame('no-repeat', $group['background_repeat']);
    self::assertSame('50% 99%', $group['background_position']);
  }

  /**
   * Tests a Spacer's unitless height and width print in pixels.
   *
   * A Spacer's save() interpolates its height/width attributes straight
   * into the style attribute, and a bare number becomes a pixel value the
   * way any numeric inline style does (spacer/save.js).
   */
  public function testWritesSpacerHeightAndWidthAsPixels(): void {
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:spacer']);
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'comments' => NULL,
      'navigation' => 'main',
    ]);
    $writer = $this->container->get(CanvasWriter::class);
    $variant = $this->writeVariant($writer, $this->partSet(), 'fixture', $mapping);
    $template = $writer->writeContentTemplate('wordpal_post', 'full', [
      new BlockNode('core/spacer', ['height' => 66, 'width' => 40], '', [], '<div style="height:66px" aria-hidden="true" class="wp-block-spacer"></div>'),
    ], $mapping, $variant->id());
    $items = $template->getComponentTree()->getValue();
    $spacer = current(array_filter($items, static fn (array $item): bool => $item['component_id'] === 'sdc.wordpal.spacer'))['inputs'];

    self::assertSame('66px', $spacer['height']);
    self::assertSame('40px', $spacer['width']);
  }

  /**
   * Tests post-terms links for a multiple-cardinality mapped field.
   *
   * A mapped category or tag field of unlimited cardinality matches
   * WordPress's taxonomy model. WordPress renders
   * one unclassed `<a href="..." rel="tag">Term</a>` per term. Writes a
   * content template holding just `core/post-terms`, binds it to a real
   * node's two real terms through BlockSettings::termInputs(), and renders it
   * through the real PropSource::EntityField evaluation.
   */
  public function testPostTermsRendersLinksForMultipleCardinalityField(): void {
    FieldStorageConfig::loadByName('node', 'field_categories')->setCardinality(-1)->save();
    $term = Term::create(['vid' => 'categories', 'name' => 'Journal']);
    $term->save();
    $second = Term::create(['vid' => 'categories', 'name' => 'Travel']);
    $second->save();
    $markup = $this->renderPostTerms([$term->id(), $second->id()]);
    self::assertStringContainsString('rel="tag">Journal</a>', $markup);
    self::assertStringContainsString('rel="tag">Travel</a>', $markup);
  }

  /**
   * Tests post-terms still renders when the mapped field holds one value.
   *
   * A cardinality-1 field is a valid Content mapping target that
   * ContentMappingTargetValidator does not reject. Canvas's expression
   * evaluator collapses a single-cardinality field's evaluated value to a
   * scalar rather than an array, regardless of the expression requesting
   * every delta (Evaluator::doEvaluate()'s `$cardinality === 1` branch).
   * BlockSettings::termInputs() routes both term props through the
   * EnsureArrayAdapter, so the array-typed `terms`/`term_urls` props pass
   * SDC's prop-shape validation for a single-valued field too.
   */
  public function testPostTermsRendersLinkForSingleCardinalityField(): void {
    FieldStorageConfig::loadByName('node', 'field_categories')->setCardinality(1)->save();
    $term = Term::create(['vid' => 'categories', 'name' => 'Journal']);
    $term->save();
    $markup = $this->renderPostTerms([$term->id()]);
    self::assertStringContainsString('rel="tag">Journal</a>', $markup);
  }

  /**
   * Tests bound body text keeps markup its text format allows.
   *
   * Xss::filterAdmin() strips the style attribute, so its survival proves
   * Canvas passes the processed text to wordpal_post_content() as markup.
   */
  public function testPostContentKeepsFormatAllowedMarkup(): void {
    $markup = $this->renderPostContent('<p style="color:red">Body copy.</p>');
    self::assertStringContainsString('<p style="color:red" class="wp-block-paragraph">Body copy.</p>', $markup);
  }

  /**
   * Tests Post Date's modified display type prints nothing when unmodified.
   *
   * WordPress's post-data.php modified source returns empty unless the
   * modified time is later than the published time, and post-date.php
   * then prints nothing for an empty value.
   */
  public function testPostDateModifiedPrintsNothingWhenNeverModified(): void {
    self::assertStringNotContainsString('wp-block-post-date', $this->renderPostDate(1000, 1000));
  }

  /**
   * Tests Post Date's modified display type prints the changed time.
   */
  public function testPostDateModifiedPrintsWhenModified(): void {
    $markup = $this->renderPostDate(1000, 2000);
    self::assertStringContainsString('wp-block-post-date', $markup);
    self::assertStringContainsString('datetime="1970-01-01T00:33:20+00:00"', $markup);
  }

  /**
   * Writes a Post Date content template bound to displayType "modified".
   *
   * ChangedItem::preSave() keeps an explicit changed value that differs
   * from the node's original, so the second save carries the given
   * modified time without Drupal resetting it to request time.
   */
  private function renderPostDate(int $created, int $changed): string {
    $mapping = $this->mapping('field_categories');
    $variant = $this->writeVariant($this->container->get(CanvasWriter::class), $this->partSet(), 'fixture', $mapping);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:post-date']);
    $result = $this->container->get(CanvasWriter::class)->writeContentTemplate(
      'wordpal_post',
      'full',
      [new BlockNode('core/post-date', ['displayType' => 'modified'], '', [])],
      $mapping,
      $variant->id(),
    );

    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['user']);
    user_role_grant_permissions('anonymous', ['access content']);
    $node = Node::create([
      'type' => 'wordpal_post',
      'title' => 'A walk through the city',
      'status' => 1,
      'created' => $created,
      'changed' => $created,
    ]);
    $node->save();
    if ($changed !== $created) {
      // A second save with the same changed value counts, to Drupal, as an
      // edit that left "changed" untouched, which bumps it to request time.
      $node->setChangedTime($changed)->save();
    }

    $build = $result->getComponentTree($node)->toRenderable($result, FALSE);
    $renderer = $this->container->get(RendererInterface::class);
    return (string) $renderer->executeInRenderContext(new RenderContext(), fn () => $renderer->render($build));
  }

  /**
   * Writes a post-content template and renders it for a node with $body.
   *
   * The body's text format has no filters, so the processed text equals
   * $body.
   */
  private function renderPostContent(string $body): string {
    $mapping = $this->mapping('field_categories');
    $variant = $this->writeVariant($this->container->get(CanvasWriter::class), $this->partSet(), 'fixture', $mapping);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:post-content']);
    $result = $this->container->get(CanvasWriter::class)->writeContentTemplate(
      'wordpal_post',
      'full',
      [new BlockNode('core/post-content', [], '', [])],
      $mapping,
      $variant->id(),
    );

    $this->installEntitySchema('path_alias');
    $this->installConfig(['user']);
    user_role_grant_permissions('anonymous', ['access content']);
    FilterFormat::create(['format' => 'unfiltered', 'name' => 'Unfiltered'])->save();
    $node = Node::create([
      'type' => 'wordpal_post',
      'title' => 'A walk through the city',
      'status' => 1,
      'body' => ['value' => $body, 'format' => 'unfiltered'],
    ]);
    $node->save();

    $build = $result->getComponentTree($node)->toRenderable($result, FALSE);
    $renderer = $this->container->get(RendererInterface::class);
    return (string) $renderer->executeInRenderContext(new RenderContext(), fn () => $renderer->render($build));
  }

  /**
   * Writes a post-terms content template, binds it to a node, and renders it.
   */
  private function renderPostTerms(array $termIds): string {
    $mapping = $this->mapping('field_categories');
    $variant = $this->writeVariant($this->container->get(CanvasWriter::class), $this->partSet(), 'fixture', $mapping);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:post-terms']);
    $result = $this->container->get(CanvasWriter::class)->writeContentTemplate(
      'wordpal_post',
      'full',
      [new BlockNode('core/post-terms', ['term' => 'category'], '', [])],
      $mapping,
      $variant->id(),
    );

    $this->installEntitySchema('path_alias');
    $this->installConfig(['user']);
    user_role_grant_permissions('anonymous', ['access content']);
    $node = Node::create([
      'type' => 'wordpal_post',
      'title' => 'A walk through the city',
      'status' => 1,
      'field_categories' => array_map(static fn (int $id): array => ['target_id' => $id], $termIds),
    ]);
    $node->save();

    $spy = new class() extends AbstractLogger {
      /**
       * Messages logged during rendering, newest last.
       */
      public array $messages = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, $message, array $context = []): void {
        $this->messages[] = strtr((string) $message, $context);
      }

    };
    $this->container->get('logger.factory')->addLogger($spy);
    $build = $result->getComponentTree($node)->toRenderable($result, FALSE);
    $renderer = $this->container->get(RendererInterface::class);
    $markup = (string) $renderer->executeInRenderContext(new RenderContext(), fn () => $renderer->render($build));
    self::assertStringNotContainsString('Oops, something went wrong', $markup, implode("\n", $spy->messages));
    return $markup;
  }

  /**
   * Writes a page variant whose Queries number their pagers from 1.
   */
  private function writeVariant(CanvasWriter $writer, PartSet $partSet, string $theme, ContentMapping $mapping): PageVariant {
    return $writer->writePageVariant($partSet, $theme, $mapping, $partSet->loops(10, 1, NULL), NULL, [])['variant'];
  }

  /**
   * Returns a page frame around one content marker.
   */
  private function partSet(): PartSet {
    return new PartSet([
      [
        'type' => 'part',
        'slug' => 'header',
        'attributes' => ['tagName' => 'header'],
        'nodes' => [new BlockNode('core/group', ['tagName' => 'header', 'templatePart' => TRUE], '', [])],
      ],
      ['type' => 'marker'],
      [
        'type' => 'part',
        'slug' => 'footer',
        'attributes' => ['tagName' => 'footer'],
        'nodes' => [new BlockNode('core/group', ['tagName' => 'footer', 'templatePart' => TRUE], '', [])],
      ],
    ]);
  }

  /**
   * Returns the dynamic blocks used by a WordPress single template.
   */
  private function tree(): array {
    $children = [
      new BlockNode('core/post-title', [], '', []),
      new BlockNode('core/post-date', [], '', []),
      new BlockNode('core/post-author-name', [], '', []),
      new BlockNode('core/post-featured-image', [], '', []),
      new BlockNode('core/cover', ['useFeaturedImage' => TRUE], '', []),
      new BlockNode('core/post-excerpt', [], '', []),
      new BlockNode('core/post-terms', ['term' => 'category'], '', []),
      new BlockNode('core/post-terms', ['term' => 'post_tag'], '', []),
      new BlockNode('core/post-content', [], '', []),
      new BlockNode('core/post-navigation-link', ['type' => 'previous', 'showTitle' => TRUE], '', []),
      new BlockNode('core/post-navigation-link', ['showTitle' => TRUE], '', []),
      new BlockNode('core/comments', [], '', []),
    ];
    return [new BlockNode('core/group', ['tagName' => 'main'], '', $children)];
  }

  /**
   * Returns a mapping with one selectable category field.
   */
  private function mapping(string $categoryField, string $excerptField = 'field_excerpt', ?string $comments = NULL): ContentMapping {
    return new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'category' => ['vocabulary' => 'categories', 'field' => $categoryField],
      'tag' => ['vocabulary' => 'tags', 'field' => 'field_tags'],
      'featured_image' => 'field_image',
      'excerpt' => $excerptField,
      'comments' => $comments,
    ]);
  }

  /**
   * Creates one configurable post field.
   */
  private function field(string $name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => $name,
      'type' => $type,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'wordpal_post',
      'field_name' => $name,
      'label' => $name,
    ])->save();
  }

  /**
   * Returns one component input expression.
   */
  private function inputExpression(array $items, string $component, string $prop): string {
    $item = current(array_filter($items, static fn (array $item): bool => $item['component_id'] === $component));
    return $item['inputs'][$prop]['expression'];
  }

  /**
   * Returns the category-term name expression.
   */
  private function termExpression(array $items): string {
    return $this->inputExpression($items, 'sdc.wordpal.post-terms', 'terms');
  }

}
