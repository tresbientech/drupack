<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Functional;

use Drupal\comment\Entity\CommentType;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Submits a comment over HTTP through the WordPal comments block.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class CommentSubmissionTest extends BrowserTestBase {

  use CommentTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'file', 'image', 'text', 'node', 'comment', 'wordpal'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The layout every case places the comments block with.
   */
  private const LAYOUT = [
    ['marker' => 'comment-template', 'children' => []],
    ['marker' => 'post-comments-form', 'children' => []],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createContentType(['type' => 'article']);
    CommentType::create(['id' => 'comment', 'label' => 'Comment', 'target_entity_type_id' => 'node'])->save();
    $this->addDefaultCommentField('node', 'article', 'field_comments');
  }

  /**
   * Tests a permitted user posts a comment and sees it rendered back.
   */
  public function testAuthenticatedUserPostsComment(): void {
    $this->fieldComments()->setSetting('anonymous', 1)->save();
    $this->placeBlock('wordpal_comments', ['field_name' => 'field_comments', 'layout' => self::LAYOUT]);
    $node = $this->drupalCreateNode(['type' => 'article', 'field_comments' => ['status' => 2]]);
    $author = $this->drupalCreateUser(['access content', 'access comments', 'post comments', 'skip comment approval']);
    $this->drupalLogin($author);

    $this->drupalGet($node->toUrl());
    $this->submitForm(['comment_body[0][value]' => 'A WordPress-style comment.'], 'Post Comment');

    $this->assertSession()->pageTextContains('A WordPress-style comment.');
    $comments = \Drupal::entityTypeManager()->getStorage('comment')->loadByProperties(['entity_id' => $node->id()]);
    self::assertCount(1, $comments);
    self::assertSame('1', reset($comments)->get('status')->value);
  }

  /**
   * Tests anonymous submission fails closed without a required email.
   */
  public function testAnonymousWithoutRequiredEmailShowsFormError(): void {
    $this->fieldComments()->setSetting('anonymous', 2)->save();
    $this->placeBlock('wordpal_comments', ['field_name' => 'field_comments', 'layout' => self::LAYOUT]);
    $node = $this->drupalCreateNode(['type' => 'article', 'field_comments' => ['status' => 2]]);
    Role::load(AccountInterface::ANONYMOUS_ROLE)
      ->grantPermission('access content')
      ->grantPermission('access comments')
      ->grantPermission('post comments')
      ->save();

    $this->drupalGet($node->toUrl());
    $this->submitForm([
      'comment_body[0][value]' => 'A comment with no email.',
      'name' => 'A visitor',
    ], 'Post Comment');

    $this->assertSession()->statusMessageContains('Email field is required.', 'error');
    self::assertSame([], \Drupal::entityTypeManager()->getStorage('comment')->loadByProperties(['entity_id' => $node->id()]));
  }

  /**
   * Returns the fixture's comment field config.
   */
  private function fieldComments(): FieldConfig {
    return FieldConfig::loadByName('node', 'article', 'field_comments');
  }

}
