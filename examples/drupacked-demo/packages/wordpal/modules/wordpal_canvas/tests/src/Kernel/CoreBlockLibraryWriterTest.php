<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Pattern;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\wordpal\Theme\ViewTags;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\BlockParser;
use Drupal\wordpal_convert\Theme\TreeConcepts;
use Drupal\wordpal_canvas\CanvasWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the writer converts the core block library instead of freezing it.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class CoreBlockLibraryWriterTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'comment',
    'node',
    'views',
    'wordpal',
    'wordpal_convert',
    'wordpal_canvas', 'wordpal_canvas_runtime',
  ];

  /**
   * The mapping the Drupal CMS content model resolves to.
   */
  private ContentMapping $mapping;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', array_map(
      static fn (string $slug): string => "wordpal:$slug",
      [
        'group',
        'paragraph',
        'image',
        'frozen-html',
        'social-links',
        'social-link',
        'video',
        'media-text',
        'gallery',
        'query-title',
        'term-description',
      ],
    ));
    $this->container->get(ComponentSourceManager::class)->generateComponents('block', [
      'wordpal_tag_cloud', 'wordpal_archives', 'wordpal_comments', 'wordpal_search',
    ]);
    $this->mapping = new ContentMapping([
      'post' => 'blog',
      'page' => 'page',
      'tag' => ['vocabulary' => 'tags', 'field' => 'field_tags'],
      'category' => ['vocabulary' => 'topics', 'field' => 'field_topics'],
      'excerpt' => 'field_description',
      'featured_image' => NULL,
      'author_biography' => NULL,
      'search' => ['path' => '/search', 'parameter' => 'keywords', 'index' => 'content'],
      'comments' => 'comment',
    ]);
    $this->mapping->setThemeId('wordpal_fixture');
  }

  /**
   * Tests no block of the library freezes, and deprecated comments convert.
   */
  public function testLibraryBlocksDoNotFreeze(): void {
    $nodes = [
      new BlockNode('core/social-links', ['openInNewTab' => TRUE, 'iconColor' => 'contrast'], '', [
        new BlockNode('core/social-link', ['service' => 'x', 'url' => 'https://x.com'], '', []),
      ]),
      new BlockNode('core/video', ['src' => '/city.mp4'], '', []),
      new BlockNode('core/media-text', ['mediaUrl' => '/city.jpg', 'mediaType' => 'image'], '', [
        new BlockNode('core/paragraph', [], '<p>Text</p>', []),
      ]),
      new BlockNode('core/gallery', [], '', [new BlockNode('core/image', ['url' => '/city.jpg'], '', [])]),
      new BlockNode('core/query-title', ['type' => 'search'], '', []),
      new BlockNode('core/term-description', [], '', []),
      new BlockNode('core/latest-posts', ['postsToShow' => 3, 'displayPostDate' => TRUE], '', []),
      new BlockNode('core/page-list', [], '', []),
      new BlockNode('core/tag-cloud', ['showTagCounts' => TRUE], '', []),
      new BlockNode('core/archives', ['showPostCounts' => TRUE, 'displayAsDropdown' => TRUE], '', []),
      new BlockNode('core/comments', ['legacy' => TRUE], '', []),
      new BlockNode('core/comments', ['className' => 'wp-block-comments-query-loop'], '', [
        new BlockNode('core/comments-title', [], '', []),
        new BlockNode('core/comments-pagination', [], '', [new BlockNode('core/comments-pagination-numbers', [], '', [])]),
      ]),
      new BlockNode('core/search', ['label' => 'Search', 'placeholder' => 'Type here...', 'showLabel' => FALSE], '', []),
    ];
    $writer = $this->container->get(CanvasWriter::class);

    self::assertSame([], array_column($writer->frozenNodes($nodes, $this->mapping), 'name'));
    self::assertSame([
      'core/social-links: icon options are copied to each Social Icon, where editors change them',
    ], TreeConcepts::droppedOptions($nodes));

    $items = array_values(Pattern::load($writer->writePattern('library', 'Library', $nodes, $this->mapping)->id())->get('component_tree'));
    $components = array_column($items, 'component_id');
    $latestPostsComponent = $components[9];
    $pageListComponent = $components[10];
    self::assertStringStartsWith('block.views_block.', $latestPostsComponent);
    self::assertStringStartsWith('block.views_block.', $pageListComponent);
    $components[9] = 'block.wordpal_latest_posts';
    $components[10] = 'block.wordpal_page_list';
    $byComponent = [];
    foreach ($items as $item) {
      $byComponent[$item['component_id']][] = $item['inputs'];
    }
    self::assertSame([
      'sdc.wordpal.social-links', 'sdc.wordpal.social-link', 'sdc.wordpal.video',
      'sdc.wordpal.media-text', 'sdc.wordpal.paragraph', 'sdc.wordpal.gallery',
      'sdc.wordpal.image', 'sdc.wordpal.query-title', 'sdc.wordpal.term-description',
      'block.wordpal_latest_posts', 'block.wordpal_page_list', 'block.wordpal_tag_cloud',
      'block.wordpal_archives', 'block.wordpal_comments', 'block.wordpal_comments',
      'block.wordpal_search',
    ], $components);
    self::assertSame('Type here...', $byComponent['block.wordpal_search'][0]['placeholder']);
    self::assertFalse($byComponent['block.wordpal_search'][0]['show_label']);

    $link = $byComponent['sdc.wordpal.social-link'][0];
    self::assertTrue($link['open_in_new_tab']);
    self::assertSame('contrast', $link['icon_color']);
    self::assertSame(['/search', 'keywords', 'tags'], [
      $byComponent['sdc.wordpal.query-title'][0]['search_path'],
      $byComponent['sdc.wordpal.query-title'][0]['search_parameter'],
      $byComponent['sdc.wordpal.query-title'][0]['tag_vocabulary'],
    ]);
    self::assertSame('topics', $byComponent['sdc.wordpal.query-title'][0]['category_vocabulary']);
    $latestPostsView = View::load(substr($latestPostsComponent, strlen('block.views_block.'), -strlen('-block_1')));
    $latestPostsSettings = $latestPostsView->getThirdPartySetting('wordpal', 'listing')['settings'];
    self::assertSame(['blog', 3, TRUE], [
      $latestPostsSettings['bundle'],
      $latestPostsSettings['posts_to_show'],
      $latestPostsSettings['display_post_date'],
    ]);
    self::assertSame(['field_description', ''], [
      $latestPostsSettings['excerpt_field'],
      $latestPostsSettings['image_field'],
    ]);
    self::assertSame('blog', $byComponent['block.wordpal_archives'][0]['bundle']);
    $pageListView = View::load(substr($pageListComponent, strlen('block.views_block.'), -strlen('-block_1')));
    self::assertSame('page', $pageListView->getThirdPartySetting('wordpal', 'listing')['settings']['bundle']);
    self::assertSame('tags', $byComponent['block.wordpal_tag_cloud'][0]['vocabulary']);
    self::assertTrue($byComponent['block.wordpal_archives'][0]['show_post_counts']);
    [$legacy, $loop] = $byComponent['block.wordpal_comments'];
    self::assertSame(
      ['comments-title', 'comment-template', 'comments-pagination', 'post-comments-form'],
      array_column($legacy['layout'], 'marker'),
    );
    self::assertSame(['comments-title', 'comments-pagination'], array_column($loop['layout'], 'marker'));
    self::assertSame(['comments-pagination-numbers'], array_column($loop['layout'][1]['children'], 'marker'));
    self::assertSame('wp-block-comments-query-loop', $loop['css_class']);
  }

  /**
   * Tests an Author biography the mapping drops is reported.
   */
  public function testReportsDroppedAuthorBiography(): void {
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:post-author']);
    $writer = $this->container->get(CanvasWriter::class);

    $writer->writePattern('author', 'Author', [new BlockNode('core/post-author', ['showBio' => TRUE], '', [])], $this->mapping);

    self::assertSame(['author_biography' => 1], $this->mapping->droppedBlocks());
  }

  /**
   * Tests Latest Comments binds the mapped comment field.
   */
  public function testLatestCommentsBindsCommentField(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $attributes = ['commentsToShow' => 3, 'displayAvatar' => FALSE, 'displayExcerpt' => FALSE];
    $nodes = [new BlockNode('core/latest-comments', $attributes, '', [])];

    $items = array_values(Pattern::load($writer->writePattern('comments', 'Comments', $nodes, $this->mapping)->id())->get('component_tree'));

    self::assertStringStartsWith('block.views_block.', $items[0]['component_id']);
    $view = View::load(substr($items[0]['component_id'], strlen('block.views_block.'), -strlen('-block_1')));
    self::assertSame(ViewTags::LISTING, $view->get('tag'));
    $settings = $view->getThirdPartySetting('wordpal', 'listing');
    self::assertSame('latest_comments', $settings['kind']);
    self::assertSame(['comment', 3, FALSE, 'none'], [
      $settings['settings']['field_name'],
      $settings['settings']['comments_to_show'],
      $settings['settings']['display_avatar'],
      $settings['settings']['display_content'],
    ]);
  }

  /**
   * Tests Latest Comments drops, counted, under a mapping without comments.
   */
  public function testLatestCommentsDropsWithoutComments(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $mapping = new ContentMapping(['comments' => NULL]);
    $nodes = [
      new BlockNode('core/paragraph', [], '<p>Text</p>', []),
      new BlockNode('core/latest-comments', [], '', []),
    ];

    self::assertSame([], array_column($writer->frozenNodes($nodes, $mapping), 'name'));
    $items = array_values(Pattern::load($writer->writePattern('no_comments', 'No comments', $nodes, $mapping)->id())->get('component_tree'));

    self::assertSame(['sdc.wordpal.paragraph'], array_column($items, 'component_id'));
    self::assertSame(['comments' => 1], $mapping->droppedBlocks());
  }

  /**
   * Tests a standalone Post Comments Form uses the comments block plugin.
   *
   * Outside a core/comments wrapper, WordPress still renders the form on
   * its own; the comments block plugin's layout holds only its marker.
   */
  public function testStandalonePostCommentsFormUsesCommentsPlugin(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $nodes = [new BlockNode('core/post-comments-form', [], '', [])];

    self::assertSame([], array_column($writer->frozenNodes($nodes, $this->mapping), 'name'));
    $items = array_values(Pattern::load($writer->writePattern('form', 'Form', $nodes, $this->mapping)->id())->get('component_tree'));

    self::assertSame(['block.wordpal_comments'], array_column($items, 'component_id'));
    self::assertSame(['post-comments-form'], array_column($items[0]['inputs']['layout'], 'marker'));
    self::assertSame('comment', $items[0]['inputs']['field_name']);
  }

  /**
   * Tests a standalone Post Comments Form drops without a comments mapping.
   */
  public function testStandalonePostCommentsFormDropsWithoutComments(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $mapping = new ContentMapping(['comments' => NULL]);
    $nodes = [
      new BlockNode('core/paragraph', [], '<p>Text</p>', []),
      new BlockNode('core/post-comments-form', [], '', []),
    ];

    self::assertSame([], array_column($writer->frozenNodes($nodes, $mapping), 'name'));
    $items = array_values(Pattern::load($writer->writePattern('no_form', 'No form', $nodes, $mapping)->id())->get('component_tree'));

    self::assertSame(['sdc.wordpal.paragraph'], array_column($items, 'component_id'));
    self::assertSame(['comments' => 1], $mapping->droppedBlocks());
  }

  /**
   * Tests a Tag Cloud font size outside CSS lengths fails the conversion.
   */
  public function testRejectsTagCloudFontSize(): void {
    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Unsupported Tag Cloud font size: 8pt;color:red');

    $this->container->get(CanvasWriter::class)->writePattern('cloud', 'Cloud', [
      new BlockNode('core/tag-cloud', ['smallestFontSize' => '8pt;color:red'], '', []),
    ], $this->mapping);
  }

  /**
   * Tests identical latest-posts settings share one listing View.
   */
  public function testLatestPostsWritesOneViewPerSettings(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $tree = $this->container->get(BlockParser::class)->parse('<!-- wp:latest-posts {"postsToShow":3,"displayPostDate":true} /--><!-- wp:latest-posts {"postsToShow":3,"displayPostDate":true} /--><!-- wp:latest-posts {"postsToShow":7} /-->');

    $pattern = $writer->writePattern('t_listing', 'Listing', $tree, $this->mapping);
    $components = array_column(Pattern::load($pattern->id())->get('component_tree'), 'component_id');

    self::assertCount(3, $components);
    self::assertCount(2, array_unique($components), 'Identical settings share one View.');
    foreach (array_unique($components) as $id) {
      self::assertStringStartsWith('block.views_block.', $id);
      $view = View::load(substr($id, strlen('block.views_block.'), -strlen('-block_1')));
      self::assertSame(ViewTags::LISTING, $view->get('tag'));
      self::assertSame('latest_posts', $view->getThirdPartySetting('wordpal', 'listing')['kind']);
    }
  }

  /**
   * Tests a dropped concept's listing block is left out of listingOutputs().
   *
   * ConversionRunner's ownership manifest calls listingOutputs() to predict
   * the View and Component ids a tree's listing blocks will save; addNodes()
   * drops a listing block whose concept the mapping drops before it ever
   * reaches saveListingInfrastructure(), so listingOutputs() must skip it the
   * same way or the manifest names an id nothing ever creates.
   */
  public function testListingOutputsDropsWithoutComments(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $mapping = new ContentMapping(['comments' => NULL]);
    $mapping->setThemeId('wordpal_fixture');
    $nodes = [new BlockNode('core/latest-comments', [], '', [])];

    self::assertSame([], $writer->listingOutputs($nodes, $mapping));
  }

  /**
   * Tests a standalone page list writes a listing View.
   */
  public function testPageListWritesOneListingView(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $tree = [new BlockNode('core/page-list', [], '', [])];

    $pattern = $writer->writePattern('t_page_list', 'Page list', $tree, $this->mapping);
    $components = array_column(Pattern::load($pattern->id())->get('component_tree'), 'component_id');

    self::assertCount(1, $components);
    self::assertStringStartsWith('block.views_block.', $components[0]);
    $view = View::load(substr($components[0], strlen('block.views_block.'), -strlen('-block_1')));
    self::assertSame(ViewTags::LISTING, $view->get('tag'));
    self::assertSame('page_list', $view->getThirdPartySetting('wordpal', 'listing')['kind']);
  }

}
