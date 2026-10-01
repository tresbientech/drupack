<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\comment\Entity\CommentType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Content\ContentMappingLoader;
use Drupal\wordpal_convert\Content\ContentMappingTargetValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Content mapping validation against Drupal entity definitions.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ContentMappingTest extends KernelTestBase {

  /**
   * Modules needed to define mapped content targets.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'file',
    'image',
    'media',
    'comment',
    'node',
    'taxonomy',
  ];

  /**
   * Creates the node bundles, vocabularies, and fields used by the fixture.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('comment');
    $this->installConfig(['field', 'node', 'taxonomy']);

    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    NodeType::create(['type' => 'wordpal_page', 'name' => 'WordPal page'])->save();
    Vocabulary::create(['vid' => 'wordpal_category', 'name' => 'WordPal category'])->save();
    Vocabulary::create(['vid' => 'wordpal_tags', 'name' => 'WordPal tags'])->save();
    CommentType::create([
      'id' => 'wordpal_comment',
      'label' => 'WordPal comment',
      'target_entity_type_id' => 'node',
    ])->save();

    $this->createField('node', 'wordpal_post', 'field_categories', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ], [
      'handler_settings' => ['target_bundles' => ['wordpal_category' => 'wordpal_category']],
    ]);
    $this->createField('node', 'wordpal_post', 'field_tags', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ], [
      'handler_settings' => ['target_bundles' => ['wordpal_tags' => 'wordpal_tags']],
    ]);
    $this->createField('node', 'wordpal_post', 'field_featured_image', 'image');
    $this->createField('node', 'wordpal_post', 'field_featured_images', 'image', [], [], -1);
    $this->createField('node', 'wordpal_post', 'field_media', 'entity_reference', [
      'target_type' => 'media',
    ]);
    $this->createField('node', 'wordpal_post', 'field_excerpt', 'text_long');
    $this->createField('node', 'wordpal_page', 'field_page_body', 'text_long');
    $this->createField('node', 'wordpal_page', 'field_page_summary', 'string_long');
    $this->createField('user', 'user', 'field_biography', 'text_long');
    $this->createField('node', 'wordpal_post', 'field_comments', 'comment', [
      'comment_type' => 'wordpal_comment',
    ]);
  }

  /**
   * Tests that comment mappings require a real comment field.
   */
  public function testCommentFieldTarget(): void {
    $valid = new ContentMapping(['post' => 'wordpal_post', 'comments' => 'field_comments']);
    $this->validator()->validate($valid);

    $invalid = new ContentMapping(['post' => 'wordpal_post', 'comments' => 'field_excerpt']);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('comments field field_excerpt has type text_long; expected comment.');
    $this->validator()->validate($invalid);
  }

  /**
   * Tests that page_body must name a text field on the page bundle.
   */
  public function testPageBodyTarget(): void {
    $this->validator()->validate(new ContentMapping(['page' => 'wordpal_page', 'page_body' => 'field_page_body']));

    $mapping = new ContentMapping(['page' => 'wordpal_page', 'page_body' => 'field_excerpt']);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('page_body names a missing field on node.wordpal_page: field_excerpt.');
    $this->validator()->validate($mapping);
  }

  /**
   * Tests that page_excerpt must name a text field on the page bundle.
   */
  public function testPageExcerptTarget(): void {
    $this->validator()->validate(new ContentMapping(['page' => 'wordpal_page', 'page_excerpt' => 'field_page_summary']));

    $mapping = new ContentMapping(['page' => 'wordpal_page', 'page_excerpt' => 'field_excerpt']);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('page_excerpt names a missing field on node.wordpal_page: field_excerpt.');
    $this->validator()->validate($mapping);
  }

  /**
   * Tests the shared fixture against real entity and field APIs.
   */
  public function testSharedMappingTargets(): void {
    $mapping = (new ContentMappingLoader())->load(
      dirname(__DIR__, 2) . '/fixtures/content/content-mapping.yml',
    );

    $this->validator()->validate($mapping);
    self::assertSame('field_comments', $mapping->target('comments'));
    self::assertTrue($mapping->drops('navigation'));
    self::assertTrue($mapping->drops('search'));
  }

  /**
   * Tests required-key aggregation and explicit null targets in Drupal.
   */
  public function testMissingAndNullMappings(): void {
    $path = sys_get_temp_dir() . '/wordpal-kernel-mapping-' . bin2hex(random_bytes(6)) . '.yml';
    file_put_contents($path, "comments: ~\nnavigation: ~\nsearch: ~\n");

    try {
      (new ContentMappingLoader())->load($path);
      self::fail('Missing concepts should fail before entity validation.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('post, post_body, page, page_body, page_excerpt, category, tag, featured_image, excerpt, author_biography', $exception->getMessage());
    }
    finally {
      unlink($path);
    }

    $mapping = new ContentMapping(array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $this->validator()->validate($mapping);
    self::assertTrue($mapping->drops('post'));
  }

  /**
   * Tests that real wrong target types are reported in one failure.
   */
  public function testWrongTargetsFailTogether(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'page' => 'missing_page',
      'category' => ['vocabulary' => 'wordpal_tags', 'field' => 'field_categories'],
      'tag' => ['vocabulary' => 'wordpal_tags', 'field' => 'field_excerpt'],
      'featured_image' => 'field_media',
      'author_biography' => 'missing_biography',
    ]);

    try {
      $this->validator()->validate($mapping);
      self::fail('Wrong Drupal targets should fail.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('page names a missing node bundle: missing_page.', $exception->getMessage());
      self::assertStringContainsString('category field field_categories does not allow vocabulary wordpal_tags.', $exception->getMessage());
      self::assertStringContainsString('tag field field_excerpt must reference taxonomy terms.', $exception->getMessage());
      self::assertStringContainsString('featured_image media field field_media must restrict its allowed media bundles.', $exception->getMessage());
      self::assertStringContainsString('author_biography names a missing field', $exception->getMessage());
    }
  }

  /**
   * Tests scalar content bindings require compatible single-value fields.
   */
  public function testScalarBindingRequirements(): void {
    $mapping = new ContentMapping([
      'post' => 'wordpal_post',
      'post_body' => 'body',
      'featured_image' => 'field_featured_images',
    ]);

    try {
      $this->validator()->validate($mapping, TRUE);
      self::fail('Missing body and multi-value image fields should fail.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('post_body names a missing field on node.wordpal_post: body.', $exception->getMessage());
      self::assertStringContainsString('featured_image field field_featured_images must have cardinality 1.', $exception->getMessage());
    }
  }

  /**
   * Creates the validator from real Drupal entity services.
   */
  private function validator(): ContentMappingTargetValidator {
    return new ContentMappingTargetValidator(
      $this->container->get('entity_type.bundle.info'),
      $this->container->get('entity_field.manager'),
      $this->container->get('entity_type.manager'),
      $this->container->get('path.validator'),
    );
  }

  /**
   * Creates one configurable field on an existing bundle.
   */
  private function createField(string $entityType, string $bundle, string $name, string $type, array $storageSettings = [], array $fieldSettings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'entity_type' => $entityType,
      'field_name' => $name,
      'type' => $type,
      'settings' => $storageSettings,
      'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'field_name' => $name,
      'label' => $name,
      'settings' => $fieldSettings,
    ])->save();
  }

}
