<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\wordpal\Query\PostClasses;
use Drupal\wordpal\Query\QuerySettings;
use Drupal\wordpal_field_access_test\Hook\FieldAccessTestHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the WordPress post classes `PostClasses::for()` computes for a row.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class PostClassesTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'system', 'user', 'field', 'node', 'text', 'filter', 'views', 'taxonomy',
    'file', 'image', 'comment', 'link', 'menu_link_content', 'wordpal_field_access_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter', 'node']);
    $this->setUpCurrentUser([], ['access content']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Tests an unpublished node gets `status-draft`, not `status-publish`.
   */
  public function testDraftNodeGetsStatusDraft(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Draft post', 'status' => 0]);
    $node->save();
    $fields = ['type' => 'post', 'category_field' => NULL, 'tag_field' => NULL, 'image_field' => NULL];

    $classes = PostClasses::for($node, $this->settingsFor($fields), new CacheableMetadata());

    self::assertContains('status-draft', $classes);
    self::assertNotContains('status-publish', $classes);
  }

  /**
   * Tests a page bundle gets no `format-standard`; WordPress pages have none.
   */
  public function testPageBundleGetsNoFormatStandard(): void {
    $node = Node::create(['type' => 'article', 'title' => 'A page', 'status' => 1]);
    $node->save();
    $fields = ['type' => 'page', 'category_field' => NULL, 'tag_field' => NULL, 'image_field' => NULL];

    $classes = PostClasses::for($node, $this->settingsFor($fields), new CacheableMetadata());

    self::assertNotContains('format-standard', $classes);
  }

  /**
   * Tests a post bundle gets `format-standard`.
   */
  public function testPostBundleGetsFormatStandard(): void {
    $node = Node::create(['type' => 'article', 'title' => 'A post', 'status' => 1]);
    $node->save();
    $fields = ['type' => 'post', 'category_field' => NULL, 'tag_field' => NULL, 'image_field' => NULL];

    $classes = PostClasses::for($node, $this->settingsFor($fields), new CacheableMetadata());

    self::assertContains('format-standard', $classes);
  }

  /**
   * Tests a node with no term references gets no `category-` or `tag-` class.
   */
  public function testNoTermsGivesNoCategoryOrTagClass(): void {
    $this->addTermReferenceField('field_tags');
    $node = Node::create(['type' => 'article', 'title' => 'Untagged post', 'status' => 1]);
    $node->save();
    $fields = ['type' => 'post', 'category_field' => NULL, 'tag_field' => 'field_tags', 'image_field' => NULL];

    $classes = PostClasses::for($node, $this->settingsFor($fields), new CacheableMetadata());

    $termClasses = array_filter(
      $classes,
      static fn (string $class): bool => str_starts_with($class, 'category-') || str_starts_with($class, 'tag-'),
    );
    self::assertSame([], array_values($termClasses));
  }

  /**
   * Tests an empty image field gets no `has-post-thumbnail`.
   */
  public function testEmptyImageFieldGivesNoThumbnailClass(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_image',
      'entity_type' => 'node',
      'type' => 'image',
      'settings' => ['target_type' => 'file', 'uri_scheme' => 'public'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_image', 'entity_type' => 'node', 'bundle' => 'article'])->save();
    $node = Node::create(['type' => 'article', 'title' => 'Post with no image', 'status' => 1]);
    $node->save();
    $fields = ['type' => 'post', 'category_field' => NULL, 'tag_field' => NULL, 'image_field' => 'field_image'];

    $classes = PostClasses::for($node, $this->settingsFor($fields), new CacheableMetadata());

    self::assertNotContains('has-post-thumbnail', $classes);
  }

  /**
   * Tests a term the viewer cannot see adds no class but enters cacheability.
   */
  public function testDeniedTermAddsNoClassButEntersCacheability(): void {
    $this->addTermReferenceField('field_tags');
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $denied = Term::create(['vid' => 'tags', 'name' => 'Secret Plan', 'status' => 0]);
    $denied->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Tagged post',
      'status' => 1,
      'field_tags' => [$denied],
    ]);
    $node->save();
    $fields = ['type' => 'post', 'category_field' => NULL, 'tag_field' => 'field_tags', 'image_field' => NULL];
    $cacheability = new CacheableMetadata();

    $classes = PostClasses::for($node, $this->settingsFor($fields), $cacheability);

    self::assertNotContains('tag-secret-plan', $classes);
    self::assertContains('taxonomy_term:' . $denied->id(), $cacheability->getCacheTags());
    self::assertContains('user.permissions', $cacheability->getCacheContexts());
  }

  /**
   * Tests a term field the viewer cannot see adds no class.
   */
  public function testDeniedTermFieldAddsNoClass(): void {
    $this->addTermReferenceField('field_tags');
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $term = Term::create(['vid' => 'tags', 'name' => 'Walks']);
    $term->save();
    $node = Node::create(['type' => 'article', 'title' => 'Tagged post', 'status' => 1, 'field_tags' => [$term]]);
    $node->save();
    $this->container->get('state')->set(FieldAccessTestHooks::DENIED, ['node.field_tags']);
    $fields = ['type' => 'post', 'category_field' => NULL, 'tag_field' => 'field_tags', 'image_field' => NULL];
    $cacheability = new CacheableMetadata();

    $classes = PostClasses::for($node, $this->settingsFor($fields), $cacheability);

    self::assertNotContains('tag-walks', $classes);
    self::assertContains(FieldAccessTestHooks::TAG, $cacheability->getCacheTags());
  }

  /**
   * Adds an unlimited term-reference field to the article bundle.
   */
  private function addTermReferenceField(string $fieldName): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create(['field_name' => $fieldName, 'entity_type' => 'node', 'bundle' => 'article'])->save();
  }

  /**
   * Builds a `wordpal.query` settings reader mapping `article` to $fields.
   */
  private function settingsFor(array $fields): QuerySettings {
    $view = View::create(['id' => 'wordpal_post_classes_test', 'tag' => 'wordpal_query']);
    $view->setThirdPartySetting('wordpal', 'query', [
      'query' => [],
      'post_template' => [],
      'layout' => [],
      'bundles' => ['article' => $fields],
      'pagination' => [],
    ]);
    return QuerySettings::fromView($view);
  }

}
