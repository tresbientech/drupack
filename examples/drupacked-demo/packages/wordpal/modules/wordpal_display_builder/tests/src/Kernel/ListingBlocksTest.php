<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Kernel;

use Drupal\comment\Entity\CommentType;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use Drupal\wordpal\Theme\ViewTags;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\OwnedEntityUuid;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_display_builder\DisplayBuilderWriter;
use Drupal\wordpal_display_builder\ListingBlocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests latest-posts, page-list and latest-comments on display_builder.
 *
 * Drives DisplayBuilderWriter::writePattern() directly against hand-built
 * BlockNode trees, the way wordpal_canvas's own CoreBlockLibraryWriterTest
 * exercises CanvasWriter for the same three blocks.
 */
#[CoversClass(ListingBlocks::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ListingBlocksTest extends KernelTestBase {

  use CommentTestTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'user', 'comment', 'node', 'views', 'menu_link_content', 'filter', 'link', 'taxonomy', 'block',
    'wordpal', 'wordpal_convert', 'wordpal_display_builder', 'wordpal_display_builder_runtime',
    'display_builder', 'display_builder_page_layout', 'display_builder_entity_view', 'display_builder_views',
    'ui_patterns', 'ui_patterns_field', 'ui_patterns_views',
    'system', 'field', 'text', 'file', 'image', 'path', 'path_alias',
  ];

  /**
   * The writer under test.
   */
  private DisplayBuilderWriter $writer;

  /**
   * A Content mapping binding the post, page and comments concepts.
   */
  private ContentMapping $mapping;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('path_alias');
    $this->installConfig([
      'field', 'filter', 'node', 'system', 'user', 'views', 'ui_patterns', 'display_builder', 'comment',
    ]);
    $this->installSchema('comment', ['comment_entity_statistics']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    CommentType::create(['id' => 'comment', 'label' => 'Comment', 'target_entity_type_id' => 'node'])->save();
    $this->addDefaultCommentField('node', 'wordpal_post', 'field_comments');
    $this->writer = $this->container->get(DisplayBuilderWriter::class);
    $targets = array_fill_keys(ContentMapping::CONCEPTS, NULL);
    $targets['post'] = 'wordpal_post';
    $targets['page'] = 'wordpal_page';
    $targets['comments'] = 'field_comments';
    $this->mapping = new ContentMapping($targets);
    $this->mapping->setThemeId('wordpal_fixture');
  }

  /**
   * Returns the View a placed listing block's block source points at.
   */
  private function placedView(string $pluginId): View {
    $viewId = substr($pluginId, strlen('views_block:'), -strlen('-block_1'));
    $view = View::load($viewId);
    self::assertInstanceOf(View::class, $view);
    return $view;
  }

  /**
   * Tests a Latest Posts block writes its View and places it as a block.
   */
  public function testLatestPostsWritesTheViewAndBlockSource(): void {
    $node = new BlockNode('core/latest-posts', ['postsToShow' => 3], '', []);
    $preset = $this->writer->writePattern('wordpal_fixture_latest', 'Latest', [$node], $this->mapping);

    $sources = $preset->getSources();
    self::assertSame('block', $sources['source_id']);
    self::assertStringStartsWith('views_block:', $sources['source']['plugin_id']);
    $view = $this->placedView($sources['source']['plugin_id']);
    self::assertSame(ViewTags::LISTING, $view->get('tag'));
    self::assertSame(OwnedEntityUuid::uuid('view', $view->id()), $view->uuid());
    $listing = $view->getThirdPartySetting('wordpal', 'listing');
    self::assertSame('latest_posts', $listing['kind']);
    self::assertSame('wordpal_post', $listing['settings']['bundle']);
    self::assertSame(3, $listing['settings']['posts_to_show']);
  }

  /**
   * Tests two identical Latest Posts blocks share one View.
   */
  public function testTwoIdenticalLatestPostsBlocksShareOneView(): void {
    $nodeA = new BlockNode('core/latest-posts', ['postsToShow' => 3], '', []);
    $nodeB = new BlockNode('core/latest-posts', ['postsToShow' => 3], '', []);

    $presetA = $this->writer->writePattern('wordpal_fixture_a', 'A', [$nodeA], $this->mapping);
    $presetB = $this->writer->writePattern('wordpal_fixture_b', 'B', [$nodeB], $this->mapping);

    self::assertSame($presetA->getSources()['source']['plugin_id'], $presetB->getSources()['source']['plugin_id'], 'Identical listing blocks reuse the same View.');
  }

  /**
   * Tests a Page List block writes a page_list listing View.
   */
  public function testPageListWritesItsKind(): void {
    $node = new BlockNode('core/page-list', [], '', []);
    $preset = $this->writer->writePattern('wordpal_fixture_pages', 'Pages', [$node], $this->mapping);

    $view = $this->placedView($preset->getSources()['source']['plugin_id']);
    self::assertSame(ViewTags::LISTING, $view->get('tag'));
    $listing = $view->getThirdPartySetting('wordpal', 'listing');
    self::assertSame('page_list', $listing['kind']);
    self::assertSame('wordpal_page', $listing['settings']['bundle']);
  }

  /**
   * Tests a Latest Comments block writes a latest_comments listing View.
   */
  public function testLatestCommentsWritesItsKind(): void {
    $node = new BlockNode('core/latest-comments', ['commentsToShow' => 4], '', []);
    $preset = $this->writer->writePattern('wordpal_fixture_comments', 'Comments', [$node], $this->mapping);

    $view = $this->placedView($preset->getSources()['source']['plugin_id']);
    self::assertSame(ViewTags::LISTING, $view->get('tag'));
    $listing = $view->getThirdPartySetting('wordpal', 'listing');
    self::assertSame('latest_comments', $listing['kind']);
    self::assertSame('field_comments', $listing['settings']['field_name']);
    self::assertSame(4, $listing['settings']['comments_to_show']);
  }

  /**
   * Tests listingOutputs() predicts the same ids writePattern() saved.
   */
  public function testListingOutputsMatchesThePlacedBlock(): void {
    $node = new BlockNode('core/latest-posts', ['postsToShow' => 3], '', []);
    $preset = $this->writer->writePattern('wordpal_fixture_predicted', 'Latest', [$node], $this->mapping);
    $pluginId = $preset->getSources()['source']['plugin_id'];

    $ids = $this->writer->listingOutputs([$node], $this->mapping);

    self::assertCount(1, $ids);
    self::assertSame($pluginId, $ids[0]['component']);
  }

  /**
   * Tests this target names no trackable entity type for a listing block.
   *
   * Unlike Canvas, whose listing block places a Canvas Component,
   * display_builder points a `block` source straight at the View's own
   * block plugin: nothing separate to own or delete on `--replace`.
   */
  public function testListingComponentEntityTypeIsNull(): void {
    self::assertNull($this->writer->listingComponentEntityType());
  }

}
