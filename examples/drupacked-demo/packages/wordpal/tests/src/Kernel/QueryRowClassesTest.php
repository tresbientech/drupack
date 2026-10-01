<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\ResultRow;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the WordPress classes a Query row prints for its terms.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class QueryRowClassesTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'system', 'user', 'field', 'node', 'text', 'filter', 'views', 'taxonomy',
    'comment', 'link', 'menu_link_content',
  ];

  /**
   * Tests a row names only the terms the visitor may view, and caches on each.
   */
  public function testTermClassesFollowAccessAndCacheOnTerms(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter', 'node']);
    $this->setUpCurrentUser([], ['access content']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create(['field_name' => 'field_tags', 'entity_type' => 'node', 'bundle' => 'article'])->save();
    $published = Term::create(['vid' => 'tags', 'name' => 'Public News']);
    $published->save();
    $unpublished = Term::create(['vid' => 'tags', 'name' => 'Secret Plan', 'status' => 0]);
    $unpublished->save();
    $node = Node::create(['type' => 'article', 'title' => 'Post', 'field_tags' => [$published, $unpublished]]);
    $node->save();

    $view = View::create(['id' => 'wordpal_query_test', 'tag' => 'wordpal_query']);
    $fields = [
      'type' => 'post',
      'category_field' => NULL,
      'tag_field' => 'field_tags',
      'image_field' => NULL,
    ];
    $view->setThirdPartySetting('wordpal', 'query', [
      'query' => [],
      'post_template' => [],
      'layout' => [],
      'bundles' => ['article' => $fields],
      'pagination' => [],
    ]);
    $executable = $this->container->get('views.executable')->get($view);
    $executable->result = [new ResultRow(['_entity' => $node])];
    $variables = ['view' => $executable, 'rows' => [['content' => []]]];

    wordpal_preprocess_views_view_unformatted($variables);

    self::assertContains('tag-public-news', $variables['rows'][0]['wordpal_classes']);
    self::assertNotContains('tag-secret-plan', $variables['rows'][0]['wordpal_classes']);
    self::assertContains('taxonomy_term:' . $published->id(), $variables['#cache']['tags']);
    self::assertContains('taxonomy_term:' . $unpublished->id(), $variables['#cache']['tags']);
    self::assertContains('user.permissions', $variables['#cache']['contexts']);
  }

  /**
   * Tests a row carries its Interactivity API key only when enabled.
   */
  public function testEnhancedPaginationKeysRowsByEntityId(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node']);
    $this->setUpCurrentUser([], ['access content']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $node = Node::create(['type' => 'article', 'title' => 'Post']);
    $node->save();
    $fields = [
      'type' => 'post',
      'category_field' => NULL,
      'tag_field' => NULL,
      'image_field' => NULL,
    ];

    $enabled = View::create(['id' => 'wordpal_query_test_enhanced', 'tag' => 'wordpal_query']);
    $enabled->setThirdPartySetting('wordpal', 'query', [
      'query' => ['enhanced_pagination' => TRUE],
      'post_template' => [],
      'layout' => [],
      'bundles' => ['article' => $fields],
      'pagination' => [],
    ]);
    $executable = $this->container->get('views.executable')->get($enabled);
    $executable->result = [new ResultRow(['_entity' => $node])];
    $variables = ['view' => $executable, 'rows' => [['content' => []]]];
    wordpal_preprocess_views_view_unformatted($variables);
    self::assertSame('post-template-item-' . $node->id(), $variables['rows'][0]['wordpal_key']);

    $disabled = View::create(['id' => 'wordpal_query_test_plain', 'tag' => 'wordpal_query']);
    $disabled->setThirdPartySetting('wordpal', 'query', [
      'query' => [],
      'post_template' => [],
      'layout' => [],
      'bundles' => ['article' => $fields],
      'pagination' => [],
    ]);
    $executable = $this->container->get('views.executable')->get($disabled);
    $executable->result = [new ResultRow(['_entity' => $node])];
    $variables = ['view' => $executable, 'rows' => [['content' => []]]];
    wordpal_preprocess_views_view_unformatted($variables);
    self::assertNull($variables['rows'][0]['wordpal_key']);
  }

}
