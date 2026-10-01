<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\comment\Entity\CommentType;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\wordpal\Comment\CommentRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests real form visibility, required markers, and preview actions.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class CommentFormRenderTest extends KernelTestBase {

  use CommentTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'filter', 'text', 'node', 'comment', 'wordpal',
  ];

  /**
   * Tests the rendered form against account and field configuration.
   */
  #[DataProvider('formCases')]
  public function testFormControls(bool $authenticated, int $contact, int $preview, int $authors, int $emails, int $required, int $submit, int $previewButtons): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'comment']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    CommentType::create(['id' => 'comment', 'label' => 'Comment', 'target_entity_type_id' => 'node'])->save();
    $this->addDefaultCommentField('node', 'article', 'field_comments');
    $field = $this->container->get('entity_type.manager')->getStorage('field_config')->load('node.article.field_comments');
    $field->setSetting('anonymous', $contact)->setSetting('preview', $preview)->save();
    foreach ([AccountInterface::ANONYMOUS_ROLE, AccountInterface::AUTHENTICATED_ROLE] as $roleId) {
      Role::load($roleId)
        ->grantPermission('access content')
        ->grantPermission('access comments')
        ->grantPermission('post comments')
        ->save();
    }
    User::create(['name' => 'admin', 'status' => 1])->save();
    $account = User::create(['name' => 'Reader', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($authenticated ? $account : new AnonymousUserSession());
    $node = Node::create(['type' => 'article', 'title' => 'Post', 'field_comments' => ['status' => 2]]);
    $node->save();
    $form = $this->container->get(CommentRuntime::class)->renderCommentForm('node', (string) $node->id(), 'field_comments', 'comment');
    $form['extension'] = ['#markup' => '<p class="extension-control">Extension</p>'];
    $markup = (string) $this->container->get('renderer')->renderRoot($form);
    $document = new \DOMDocument();
    @$document->loadHTML($markup);
    $xpath = new \DOMXPath($document);

    self::assertSame($authors, $xpath->query('//p[@class="comment-form-author"]')->length);
    self::assertSame($emails, $xpath->query('//p[@class="comment-form-email"]')->length);
    self::assertSame($emails, $xpath->query('//p[@class="comment-form-url"]')->length);
    self::assertSame($required, $xpath->query('//label/span[@class="required"]')->length);
    self::assertSame($required, $xpath->query('//input[@required]|//textarea[@required]')->length);
    self::assertSame($submit, $xpath->query('//input[@type="submit" and @value="Post Comment"]')->length);
    self::assertSame($previewButtons, $xpath->query('//input[@type="submit" and @value="Preview"]')->length);
    self::assertSame(1, $xpath->query('//input[@name="form_build_id"]')->length);
    self::assertSame(1, $xpath->query('//input[@name="form_id"]')->length);
    self::assertSame(1, $xpath->query('//p[@class="extension-control"]')->length);
    self::assertSame(1, $xpath->query('//form[@id="commentform"]')->length);
    if ($authors) {
      self::assertSame('60', $xpath->query('//input[@name="name"]')->item(0)->getAttribute('maxlength'));
    }
  }

  /**
   * Tests the reply wrapper's padding and cancel-reply heading markup.
   */
  #[DataProvider('paddingCases')]
  public function testPostCommentsFormWrapper(string $top, string $bottom, ?string $style): void {
    $build = [
      '#theme' => 'wordpal_post_comments_form',
      '#comment_form' => ['#markup' => '<form class="comment-form"></form>'],
      '#settings' => ['padding_top' => $top, 'padding_bottom' => $bottom],
    ];
    $document = new \DOMDocument();
    @$document->loadHTML((string) $this->container->get('renderer')->renderRoot($build));
    $xpath = new \DOMXPath($document);

    $respond = $xpath->query('//div[@id="respond"]')->item(0);
    self::assertSame($style, $respond->hasAttribute('style') ? $respond->getAttribute('style') : NULL);
    $cancel = $xpath->query('//h3[@id="reply-title"]/small/a[@id="cancel-comment-reply-link"]')->item(0);
    self::assertSame('nofollow', $cancel->getAttribute('rel'));
    self::assertSame('#respond', $cancel->getAttribute('href'));
    self::assertSame('display:none;', $cancel->getAttribute('style'));
  }

  /**
   * Returns padding settings and the style attribute they render.
   */
  public static function paddingCases(): array {
    return [
      'both sides' => [
        '1rem',
        'var:preset|spacing|20',
        'padding-top:1rem;padding-bottom:var(--wp--preset--spacing--20);',
      ],
      'only padding_top' => ['1rem', '', 'padding-top:1rem;'],
      'only padding_bottom' => ['', '2rem', 'padding-bottom:2rem;'],
      'no padding' => ['', '', NULL],
    ];
  }

  /**
   * Returns account and contact settings with expected visible controls.
   */
  public static function formCases(): array {
    return [
      'required contact' => [FALSE, 2, 0, 1, 1, 3, 1, 0],
      'optional contact' => [FALSE, 1, 0, 1, 1, 1, 1, 0],
      'forbidden contact' => [FALSE, 0, 0, 1, 0, 1, 1, 0],
      'authenticated required preview' => [TRUE, 2, 2, 0, 0, 1, 0, 1],
      'authenticated optional preview' => [TRUE, 2, 1, 0, 0, 1, 1, 0],
    ];
  }

}
