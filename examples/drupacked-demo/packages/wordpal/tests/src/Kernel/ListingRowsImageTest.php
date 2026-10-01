<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\User;
use Drupal\wordpal\Listing\ListingRows;
use Drupal\wordpal_field_access_test\Hook\FieldAccessTestHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the latest-posts image through a media item checks every access.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ListingRowsImageTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'filter',
    'text',
    'node',
    'comment',
    'views',
    'wordpal',
    'wordpal_field_access_test',
  ];

  /**
   * The post whose featured image field references the media item.
   */
  private int $mediaPostId;

  /**
   * The post whose plain image field references the file.
   */
  private int $imagePostId;

  /**
   * The media item.
   */
  private int $mediaId;

  /**
   * The media item's source file, also the plain image field's file.
   */
  private int $fileId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'field', 'image', 'media', 'node']);
    User::create(['name' => 'anonymous', 'uid' => 0])->save();
    user_role_grant_permissions('anonymous', ['access content', 'view media']);
    $author = User::create(['name' => 'Morgan Reed', 'status' => 1]);
    $author->save();

    $mediaType = $this->createMediaType('image', ['id' => 'image']);
    $sourceField = $mediaType->getSource()->getSourceFieldDefinition($mediaType)->getName();
    self::assertSame('field_media_image', $sourceField);
    copy($this->root . '/core/tests/fixtures/files/image-test.png', 'public://city.png');
    $file = File::create(['uri' => 'public://city.png', 'status' => 1]);
    $file->save();
    $this->fileId = (int) $file->id();
    $image = ['target_id' => $file->id(), 'alt' => 'Street', 'width' => 40, 'height' => 30];
    $media = Media::create(['bundle' => 'image', 'name' => 'City', 'status' => 1, $sourceField => $image]);
    $media->save();
    $this->mediaId = (int) $media->id();

    NodeType::create(['type' => 'blog', 'name' => 'Blog'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_featured_image',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_featured_image', 'entity_type' => 'node', 'bundle' => 'blog'])->save();
    FieldStorageConfig::create(['field_name' => 'field_image', 'entity_type' => 'node', 'type' => 'image'])->save();
    FieldConfig::create(['field_name' => 'field_image', 'entity_type' => 'node', 'bundle' => 'blog'])->save();
    $post = ['type' => 'blog', 'uid' => $author->id(), 'status' => 1];
    $mediaPost = Node::create($post + ['title' => 'Walk', 'field_featured_image' => $media]);
    $mediaPost->save();
    $this->mediaPostId = (int) $mediaPost->id();
    $imagePost = Node::create($post + ['title' => 'Stroll', 'field_image' => $image]);
    $imagePost->save();
    $this->imagePostId = (int) $imagePost->id();
  }

  /**
   * Tests a viewer allowed everything gets the source field's image.
   */
  public function testAllowedMediaImageListsTheSourceImage(): void {
    $image = $this->image($this->mediaPostId, 'field_featured_image', new CacheableMetadata());

    self::assertStringEndsWith('city.png', $image['src']);
    self::assertSame(['width' => 40, 'height' => 30, 'alt' => 'Street'], array_diff_key($image, ['src' => TRUE]));
  }

  /**
   * Tests a denied media source field gives no image and varies by it.
   */
  public function testDeniedMediaSourceFieldGivesNoImage(): void {
    $this->container->get('state')->set(FieldAccessTestHooks::DENIED, ['media.field_media_image']);
    $cacheability = new CacheableMetadata();

    self::assertNull($this->image($this->mediaPostId, 'field_featured_image', $cacheability));
    self::assertContains(FieldAccessTestHooks::TAG . ':media.field_media_image', $cacheability->getCacheTags());
  }

  /**
   * Tests a denied media source file gives no image and varies by it.
   */
  public function testDeniedMediaFileGivesNoImage(): void {
    $this->container->get('state')->set(FieldAccessTestHooks::DENIED_FILES, [$this->fileId]);
    $cacheability = new CacheableMetadata();

    self::assertNull($this->image($this->mediaPostId, 'field_featured_image', $cacheability));
    self::assertContains(FieldAccessTestHooks::FILE_TAG, $cacheability->getCacheTags());
  }

  /**
   * Tests a denied file in a plain image field gives no image and varies by it.
   */
  public function testDeniedImageFieldFileGivesNoImage(): void {
    $allowed = $this->image($this->imagePostId, 'field_image', new CacheableMetadata());
    $this->container->get('state')->set(FieldAccessTestHooks::DENIED_FILES, [$this->fileId]);
    $cacheability = new CacheableMetadata();

    self::assertStringEndsWith('city.png', $allowed['src']);
    self::assertNull($this->image($this->imagePostId, 'field_image', $cacheability));
    self::assertContains(FieldAccessTestHooks::FILE_TAG, $cacheability->getCacheTags());
  }

  /**
   * Tests an unpublished media item gives no image and varies by permission.
   */
  public function testUnpublishedMediaGivesNoImage(): void {
    Media::load($this->mediaId)->setUnpublished()->save();
    $cacheability = new CacheableMetadata();

    self::assertNull($this->image($this->mediaPostId, 'field_featured_image', $cacheability));
    self::assertContains("media:$this->mediaId", $cacheability->getCacheTags());
    self::assertContains('user.permissions', $cacheability->getCacheContexts());
  }

  /**
   * Returns the row's image for the anonymous viewer, from a stored post.
   */
  private function image(int $nodeId, string $field, CacheableMetadata $cacheability): ?array {
    $entityTypeManager = $this->container->get('entity_type.manager');
    foreach (['node', 'media', 'file'] as $entityTypeId) {
      $entityTypeManager->getAccessControlHandler($entityTypeId)->resetCache();
    }
    $storage = $entityTypeManager->getStorage('node');
    $storage->resetCache();
    $settings = ['display_post_content' => FALSE, 'display_featured_image' => TRUE, 'image_field' => $field];
    return $this->container->get(ListingRows::class)->latestPost($storage->load($nodeId), $settings, $cacheability)['image'];
  }

}
