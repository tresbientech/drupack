<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Kernel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\taxonomy\Traits\TaxonomyTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\display_builder\Entity\Profile;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\wordpal\Traits\ThemeSettingsTrait;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_display_builder\DisplayBuilderWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests writeContentTemplate() and its runtime bindings against real fields.
 *
 * Builds a "blog" bundle with the field shapes the default Drupal CMS
 * mapping uses (a text_long body, a plain string excerpt, an
 * entity_reference-to-Media featured image, and an entity_reference-to-term
 * tags field), writes a full display through the real writer, then renders
 * its saved sources against a real node to prove each runtime binding reads
 * that node's own field values, not Sample content.
 */
#[CoversClass(DisplayBuilderWriter::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ContentTemplateWritingTest extends KernelTestBase {

  use ThemeSettingsTrait;
  use MediaTypeCreationTrait;
  use TaxonomyTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system', 'user', 'comment', 'node', 'filter', 'link', 'text', 'field',
    'image', 'file', 'media', 'taxonomy', 'path_alias',
    'wordpal', 'wordpal_convert', 'wordpal_display_builder', 'display_builder',
    'display_builder_entity_view', 'ui_patterns', 'ui_patterns_field', 'ui_patterns_field_formatters',
  ];

  /**
   * The writer under test.
   */
  private DisplayBuilderWriter $writer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'image', 'media', 'ui_patterns']);
    Profile::create(['id' => 'default', 'label' => 'Default', 'description' => ''])->save();
    EntityViewMode::create(['id' => 'node.full', 'targetEntityType' => 'node', 'label' => 'Full content'])->save();
    $this->writer = $this->container->get(DisplayBuilderWriter::class);
    $this->setUpCurrentUser([], ['access content', 'view media']);

    NodeType::create(['type' => 'blog', 'name' => 'Blog'])->save();
    $this->addField('blog', 'field_content', 'text_long');
    $this->addField('blog', 'field_description', 'string_long');
    $this->createVocabulary(['vid' => 'tags']);
    $this->addField('blog', 'field_tags', 'entity_reference', [
      'target_type' => 'taxonomy_term',
      'handler_settings' => ['target_bundles' => ['tags' => 'tags']],
    ], FieldStorageConfig::CARDINALITY_UNLIMITED);
    $this->writeThemeSettings();
    $this->createMediaType('image', ['id' => 'image']);
    $this->addField('blog', 'field_featured_image', 'entity_reference', [
      'target_type' => 'media',
      'handler_settings' => ['target_bundles' => ['image' => 'image']],
    ]);
  }

  /**
   * Adds a field to the blog bundle.
   */
  private function addField(string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'settings' => $settings,
    ])->save();
  }

  /**
   * Returns the Content mapping of the default Drupal CMS shape.
   */
  private function mapping(): ContentMapping {
    $mapping = new ContentMapping([
      'post' => 'blog',
      'post_body' => 'field_content',
      'page' => NULL,
      'page_body' => NULL,
      'page_excerpt' => NULL,
      'category' => NULL,
      'tag' => ['vocabulary' => 'tags', 'field' => 'field_tags'],
      'featured_image' => 'field_featured_image',
      'excerpt' => 'field_description',
      'author_biography' => NULL,
      'comments' => NULL,
      'navigation' => NULL,
      'search' => NULL,
    ]);
    $mapping->setThemeId('wordpal_fixture');
    return $mapping;
  }

  /**
   * Creates a real image Media entity.
   */
  private function createImageMedia(): Media {
    $file = File::create(['uri' => 'public://blog-image.png', 'filename' => 'blog-image.png']);
    $file->setPermanent();
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Blog image',
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'A blog image', 'width' => 200, 'height' => 100],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Renders a full display's saved sources against a real node.
   *
   * Takes the display writeContentTemplate() itself returned rather than
   * reloading it: the entity display repository memoizes a display by
   * bundle and mode on first access within a request, which a kernel test
   * making its first access mid-test (here, inside writeContentTemplate()
   * itself) can leave pointed at a pre-save copy.
   */
  private function renderFullDisplay(EntityViewDisplayInterface $display, Node $node): string {
    $sources = $display->getThirdPartySetting('display_builder', 'sources');
    $contexts = ['entity' => EntityContext::fromEntity($node)];
    $builder = $this->container->get('ui_patterns.component_element_builder');
    $data = [];
    foreach ($sources as $source) {
      $build = $builder->buildSource($data, 'content', [], $source, $contexts);
      $data[] = $build['#slots']['content'][0] ?? [];
    }
    return (string) $this->container->get('renderer')->renderInIsolation($data);
  }

  /**
   * Tests title, date, content, excerpt and read-more bind to the node.
   */
  public function testTitleDateContentExcerptAndReadMoreBindToTheNode(): void {
    $node = Node::create([
      'type' => 'blog',
      'title' => 'A walk through the city',
      'field_content' => ['value' => '<p>Body copy.</p>', 'format' => 'plain_text'],
      'field_description' => 'A short teaser.',
      'status' => TRUE,
    ]);
    $node->save();
    $this->container->get('router.builder')->rebuild();
    $nodes = [
      new BlockNode('core/post-title', [], '', []),
      new BlockNode('core/post-date', [], '', []),
      new BlockNode('core/post-content', [], '', []),
      new BlockNode('core/post-excerpt', [], '', []),
      new BlockNode('core/read-more', [], '', []),
    ];

    $display = $this->writer->writeContentTemplate('blog', 'full', $nodes, $this->mapping(), 'ignored');
    $html = $this->renderFullDisplay($display, $node);

    self::assertStringContainsString('A walk through the city', $html);
    self::assertStringContainsString('Body copy.', $html);
    self::assertStringContainsString('A short teaser.', $html);
    self::assertStringContainsString($node->toUrl()->toString(), $html);
  }

  /**
   * Tests bound body text keeps markup its text format allows.
   *
   * Xss::filterAdmin() strips the style attribute, so its survival proves
   * the field source passes the processed text to wordpal_post_content()
   * as markup.
   */
  public function testContentKeepsFormatAllowedMarkup(): void {
    FilterFormat::create(['format' => 'unfiltered', 'name' => 'Unfiltered'])->save();
    $node = Node::create([
      'type' => 'blog',
      'title' => 'A walk through the city',
      'field_content' => ['value' => '<p style="color:red">Body copy.</p>', 'format' => 'unfiltered'],
      'status' => TRUE,
    ]);
    $node->save();

    $display = $this->writer->writeContentTemplate('blog', 'full', [new BlockNode('core/post-content', [], '', [])], $this->mapping(), 'ignored');

    self::assertStringContainsString('<p style="color:red" class="wp-block-paragraph">Body copy.</p>', $this->renderFullDisplay($display, $node));
  }

  /**
   * Tests a featured image binds through its Media reference to a real File.
   */
  public function testFeaturedImageBindsThroughMediaToTheFile(): void {
    $media = $this->createImageMedia();
    $node = Node::create([
      'type' => 'blog',
      'title' => 'A post with an image',
      'field_featured_image' => ['target_id' => $media->id()],
      'status' => TRUE,
    ]);
    $node->save();

    $display = $this->writer->writeContentTemplate('blog', 'full', [new BlockNode('core/post-featured-image', [], '', [])], $this->mapping(), 'ignored');
    $html = $this->renderFullDisplay($display, $node);

    self::assertStringContainsString('blog-image.png', $html);
    self::assertStringContainsString('A blog image', $html);
    self::assertStringContainsString('width="200"', $html);
    self::assertStringContainsString('height="100"', $html);
  }

  /**
   * Tests a Cover with useFeaturedImage shows the post's featured image.
   */
  public function testFeaturedImageCoverBindsTheFile(): void {
    $media = $this->createImageMedia();
    $node = Node::create([
      'type' => 'blog',
      'title' => 'A post with a cover',
      'field_featured_image' => ['target_id' => $media->id()],
      'status' => TRUE,
    ]);
    $node->save();

    $display = $this->writer->writeContentTemplate('blog', 'full', [new BlockNode('core/cover', ['useFeaturedImage' => TRUE], '', [])], $this->mapping(), 'ignored');
    $html = $this->renderFullDisplay($display, $node);

    self::assertStringContainsString('blog-image.png', $html);
  }

  /**
   * Tests Media & Text with useFeaturedImage shows the post's featured image.
   */
  public function testFeaturedImageMediaTextBindsTheFile(): void {
    $media = $this->createImageMedia();
    $node = Node::create([
      'type' => 'blog',
      'title' => 'A post with a media-text image',
      'field_featured_image' => ['target_id' => $media->id()],
      'status' => TRUE,
    ]);
    $node->save();

    $display = $this->writer->writeContentTemplate('blog', 'full', [new BlockNode('core/media-text', ['useFeaturedImage' => TRUE], '', [])], $this->mapping(), 'ignored');
    $html = $this->renderFullDisplay($display, $node);

    self::assertStringContainsString('blog-image.png', $html);
    self::assertStringContainsString('wp-image-' . $media->id(), $html);
  }

  /**
   * Tests Post Terms prints the node's own terms, one link each.
   */
  public function testPostTermsListsTheNodeTerms(): void {
    $city = Term::create(['vid' => 'tags', 'name' => 'City']);
    $city->save();
    $walking = Term::create(['vid' => 'tags', 'name' => 'Walking']);
    $walking->save();
    $node = Node::create([
      'type' => 'blog',
      'title' => 'A tagged post',
      'status' => TRUE,
      'field_tags' => [$city->id(), $walking->id()],
    ]);
    $node->save();
    $this->container->get('router.builder')->rebuild();

    $display = $this->writer->writeContentTemplate('blog', 'full', [new BlockNode('core/post-terms', ['term' => 'post_tag'], '', [])], $this->mapping(), 'ignored');
    $html = $this->renderFullDisplay($display, $node);

    self::assertStringContainsString('<a href="' . $city->toUrl()->toString() . '" rel="tag">City</a><span class="wp-block-post-terms__separator">, </span><a href="' . $walking->toUrl()->toString() . '" rel="tag">Walking</a></div>', $html);
    self::assertStringNotContainsString('Journal', $html, 'The Sample content term stays out.');
  }

  /**
   * Tests a bindings entry naming a field the bundle lacks fails, named.
   *
   * Post Featured Image is the one binding that degrades instead of
   * failing: see testFeaturedImageWithNoBundleFieldDropsAndReports().
   */
  public function testMissingFieldFailsNamingTheProp(): void {
    $mapping = new ContentMapping(['post' => 'blog', 'excerpt' => 'field_no_such_field'] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $mapping->setThemeId('wordpal_fixture');

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('blog has no field_no_such_field field, needed for the excerpt prop.');
    $this->writer->writeContentTemplate('blog', 'full', [new BlockNode('core/post-excerpt', [], '', [])], $mapping, 'ignored');
  }

  /**
   * Tests a bundle with no image field drops the block, and reports it.
   *
   * WordPress itself prints nothing for Post Featured Image when a post has
   * no thumbnail, so a bundle with no mapped image field renders the same
   * as one whose posts simply carry none (RuntimeBindings's class doc
   * comment); Post Title proves the template still writes.
   */
  public function testFeaturedImageWithNoBundleFieldDropsAndReports(): void {
    $mapping = new ContentMapping(['post' => 'blog', 'featured_image' => 'field_no_such_field'] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $mapping->setThemeId('wordpal_fixture');

    $display = $this->writer->writeContentTemplate('blog', 'full', [
      new BlockNode('core/post-title', [], '', []),
      new BlockNode('core/post-featured-image', [], '', []),
    ], $mapping, 'ignored');

    $tree = $display->getThirdPartySetting('display_builder', 'sources');
    self::assertCount(1, $tree, 'Post Featured Image is dropped, Post Title is written.');
    self::assertSame('wordpal:post-title', $tree[0]['source']['component']['component_id']);
    self::assertSame(['featured_image' => 1], $mapping->droppedBlocks());
  }

  /**
   * Tests an image inside a Frozen block, which the builder never visits.
   */
  public function testFrozenParentDoesNotCountUnvisitedImage(): void {
    $mapping = new ContentMapping(['post' => 'blog', 'featured_image' => 'field_no_such_field'] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $mapping->setThemeId('wordpal_fixture');

    $display = $this->writer->writeContentTemplate('blog', 'full', [
      new BlockNode('acme/wrapper', [], '', [new BlockNode('core/post-featured-image', [], '', [])], '<div class="acme-wrapper"></div>'),
    ], $mapping, 'ignored');

    $tree = $display->getThirdPartySetting('display_builder', 'sources');
    self::assertSame('wordpal:' . FrozenBlock::SLUG, $tree[0]['source']['component']['component_id']);
    self::assertSame([], $mapping->droppedBlocks());
  }

  /**
   * Tests a Cover over the featured image fails on a bundle with no field.
   *
   * Only Post Featured Image degrades; the Cover aborts the conversion, so
   * no drop is recorded for it.
   */
  public function testCoverWithoutImageFieldThrowsWithoutCount(): void {
    $mapping = new ContentMapping(['post' => 'blog', 'featured_image' => 'field_no_such_field'] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $mapping->setThemeId('wordpal_fixture');

    try {
      $this->writer->writeContentTemplate('blog', 'full', [new BlockNode('core/cover', ['useFeaturedImage' => TRUE], '', [])], $mapping, 'ignored');
      self::fail('A Cover bound to a missing image field fails.');
    }
    catch (\UnexpectedValueException $e) {
      self::assertStringContainsString('blog has no field_no_such_field field', $e->getMessage());
    }
    self::assertSame([], $mapping->droppedBlocks());
  }

  /**
   * Tests a bundle with no terms field omits Post Terms without a report.
   */
  public function testMissingTermsFieldOmitsWithoutReporting(): void {
    $tag = ['vocabulary' => 'tags', 'field' => 'field_no_such_field'];
    $mapping = new ContentMapping(['post' => 'blog', 'tag' => $tag] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $mapping->setThemeId('wordpal_fixture');

    $display = $this->writer->writeContentTemplate('blog', 'full', [
      new BlockNode('core/post-title', [], '', []),
      new BlockNode('core/post-terms', ['term' => 'post_tag'], '', []),
    ], $mapping, 'ignored');

    self::assertCount(1, $display->getThirdPartySetting('display_builder', 'sources'), 'Post Terms is dropped, Post Title is written.');
    self::assertSame([], $mapping->droppedBlocks());
  }

  /**
   * Tests runtime-preset eligibility skips a missing image without a report.
   */
  public function testRuntimePresetEligibilityDoesNotReportDrops(): void {
    $mapping = new ContentMapping(['post' => 'blog', 'featured_image' => 'field_no_such_field'] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $mapping->setThemeId('wordpal_fixture');

    $ids = $this->writer->runtimePresetIds('wordpal-fixture', 'blog', $mapping);
    $presets = $this->writer->writeRuntimePresets('wordpal-fixture', 'blog', $mapping);

    self::assertNotContains('wordpal_fixture_blog_post_featured_image', $ids);
    self::assertCount(count($ids), $presets);
    self::assertSame([], $mapping->droppedBlocks());
  }

}
