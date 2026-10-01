<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\comment\Entity\CommentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\filter\FilterFormatRepositoryInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Demo content on a site that moderates the page bundle.
 */
#[CoversClass(DemoContentSeeder::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class DemoContentSeederTest extends KernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'taxonomy', 'comment',
    'file', 'link', 'menu_link_content', 'views', 'workflows', 'content_moderation',
    'wordpal', 'wordpal_convert',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'comment', 'content_moderation']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    FieldStorageConfig::create(['field_name' => 'field_summary', 'entity_type' => 'node', 'type' => 'string_long'])->save();
    FieldConfig::create(['field_name' => 'field_summary', 'entity_type' => 'node', 'bundle' => 'wordpal_page'])->save();
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'wordpal_page');
  }

  /**
   * Tests the seeded page on a moderated bundle is published, with its excerpt.
   *
   * The fixture's page menu item links the seeded page.
   */
  public function testSeedsPublishedPageOnModeratedBundle(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'page' => 'wordpal_page',
      'page_excerpt' => 'field_summary',
      'navigation' => 'main',
    ] + array_fill_keys(ContentMapping::CONCEPTS, NULL));

    $seeded = $this->container->get(DemoContentSeeder::class)->seed($mapping, 'fixture');

    $pages = Node::loadMultiple($this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'wordpal_page')
      ->execute());
    self::assertCount(1, $pages);
    $page = reset($pages);
    self::assertTrue($page->isPublished(), 'A moderated bundle starts in draft unless the seed sets the published state.');
    self::assertSame('published', $page->get('moderation_state')->value);
    self::assertSame('When the walks start, how long they last and what to bring.', $page->get('field_summary')->value, 'The page excerpt fills the mapped field.');
    $posts = Node::loadMultiple($this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'wordpal_post')
      ->condition('status', 1)
      ->execute());
    self::assertNotEmpty($posts, 'Posts on the unmoderated bundle stay published.');
    $links = [];
    foreach (MenuLinkContent::loadMultiple() as $link) {
      $links[$link->label()] = [$link->getMenuName(), $link->get('link')->uri];
    }
    self::assertSame(['Home' => ['main', 'internal:/'], 'About' => ['main', 'entity:node/' . $page->id()]], $links);
    self::assertSame([], $seeded['skipped']);
  }

  /**
   * Tests a comment body with no allowed formats takes the author's default.
   */
  public function testSeedsCommentsWithTheDefaultFormat(): void {
    CommentType::create(['id' => 'wordpal_comment', 'label' => 'WordPal comment', 'target_entity_type_id' => 'node'])->save();
    $this->container->get('comment.manager')->addBodyField('wordpal_comment');
    FieldStorageConfig::create([
      'field_name' => 'field_comments',
      'entity_type' => 'node',
      'type' => 'comment',
      'settings' => ['comment_type' => 'wordpal_comment'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_comments', 'entity_type' => 'node', 'bundle' => 'wordpal_post'])->save();
    $mapping = new ContentMapping(['post' => 'wordpal_post', 'comments' => 'field_comments'] + array_fill_keys(ContentMapping::CONCEPTS, NULL));

    $seeded = $this->container->get(DemoContentSeeder::class)->seed($mapping, 'fixture');

    $comment = $this->container->get('entity.repository')->loadEntityByUuid('comment', reset($seeded['uuids']['comment']));
    $expected = $this->container->get(FilterFormatRepositoryInterface::class)->getDefaultFormat($comment->getOwner())->id();
    self::assertSame($expected, $comment->get('comment_body')->format);
  }

  /**
   * Tests every seeded comment passes validation, the post author's included.
   */
  public function testSeededCommentsValidate(): void {
    CommentType::create(['id' => 'wordpal_comment', 'label' => 'WordPal comment', 'target_entity_type_id' => 'node'])->save();
    $this->container->get('comment.manager')->addBodyField('wordpal_comment');
    FieldStorageConfig::create([
      'field_name' => 'field_comments',
      'entity_type' => 'node',
      'type' => 'comment',
      'settings' => ['comment_type' => 'wordpal_comment'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_comments', 'entity_type' => 'node', 'bundle' => 'wordpal_post'])->save();
    $mapping = new ContentMapping(['post' => 'wordpal_post', 'comments' => 'field_comments'] + array_fill_keys(ContentMapping::CONCEPTS, NULL));

    $seeded = $this->container->get(DemoContentSeeder::class)->seed($mapping, 'fixture');

    foreach ($seeded['uuids']['comment'] as $uuid) {
      $comment = $this->container->get('entity.repository')->loadEntityByUuid('comment', $uuid);
      self::assertCount(0, $comment->validate(), "Comment by {$comment->getAuthorName()} validates.");
    }
  }

  /**
   * Tests a seeded comment takes its field's allowed text format.
   */
  public function testSeedsCommentsWithFieldsAllowedFormat(): void {
    FilterFormat::create(['format' => 'restricted', 'name' => 'Restricted'])->save();
    CommentType::create(['id' => 'wordpal_comment', 'label' => 'WordPal comment', 'target_entity_type_id' => 'node'])->save();
    $this->container->get('comment.manager')->addBodyField('wordpal_comment');
    FieldConfig::loadByName('comment', 'wordpal_comment', 'comment_body')
      ->setSetting('allowed_formats', ['restricted'])
      ->save();
    FieldStorageConfig::create([
      'field_name' => 'field_comments',
      'entity_type' => 'node',
      'type' => 'comment',
      'settings' => ['comment_type' => 'wordpal_comment'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_comments',
      'entity_type' => 'node',
      'bundle' => 'wordpal_post',
    ])->save();
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'comments' => 'field_comments',
    ] + array_fill_keys(ContentMapping::CONCEPTS, NULL));

    $seeded = $this->container->get(DemoContentSeeder::class)->seed($mapping, 'fixture');

    self::assertNotEmpty($seeded['uuids']['comment']);
    $comment = $this->container->get('entity.repository')->loadEntityByUuid('comment', reset($seeded['uuids']['comment']));
    self::assertSame('restricted', $comment->get('comment_body')->format, 'The comment body takes the format the field allows, not a hard-coded one.');
  }

}
