<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\comment\Entity\CommentType;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\wordpal\Traits\ThemeSettingsTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\wordpal\Comment\CommentRuntime;
use Drupal\wordpal\Plugin\Block\CommentsBlock;
use Drupal\wordpal\Support\BlockSupports;
use Drupal\wordpal_field_access_test\Hook\FieldAccessTestHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests threaded comments, root paging, lazy form rendering, and cacheability.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class CommentRuntimeTest extends KernelTestBase {

  use ThemeSettingsTrait;
  use CommentTestTrait;

  /**
   * Modules needed for real comment entities and fields.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'filter',
    'text',
    'node',
    'comment',
    'wordpal',
    'wordpal_field_access_test',
  ];

  /**
   * Tests that paging slices root threads and retains their replies.
   */
  public function testThreadPagingKeepsRepliesWithRoot(): void {
    $node = $this->setUpNode(perPage: 1);
    $rootA = $this->comment($node, 'Root A');
    $this->comment($node, 'Reply A', $rootA);
    $rootB = $this->comment($node, 'Root B');
    $this->comment($node, 'Reply B', $rootB);
    $runtime = $this->runtime($node);

    $build = $runtime->build('field_comments', $this->settings());
    self::assertSame(4, $build['count']);
    self::assertCount(1, $build['comments']);
    self::assertSame('Root A', $build['comments'][0]['body']['#text']);
    self::assertSame('Reply A', $build['comments'][0]['children'][0]['body']['#text']);
    self::assertTrue($build['pager']);
    self::assertContains('user.node_grants:view', $build['#cache']['contexts']);
    self::assertContains('timezone', $build['#cache']['contexts']);
    self::assertContains('url.query_args.pagers:0', $build['#cache']['contexts']);
    self::assertContains('node:' . $node->id(), $build['#cache']['tags']);

    $this->container->get('request_stack')->getCurrentRequest()->query->set('page', '1');
    $secondPage = $runtime->build('field_comments', $this->settings());
    self::assertCount(1, $secondPage['comments']);
    self::assertSame('Root B', $secondPage['comments'][0]['body']['#text']);
    self::assertSame('Reply B', $secondPage['comments'][0]['children'][0]['body']['#text']);
    self::assertSame(4, $secondPage['count']);
  }

  /**
   * Tests that a bundle without the mapped comment field renders empty.
   *
   * WordPress prints nothing for comments when a post type does not support
   * them; a shared content template that always includes the Comments block
   * must degrade the same way on a bundle with no mapped field, instead of
   * throwing.
   */
  public function testMissingCommentFieldRendersEmpty(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $account = User::create(['name' => 'admin', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
    $node = Node::create(['type' => 'page', 'title' => 'No comments here', 'status' => 1]);
    $node->save();
    $runtime = $this->runtime($node);

    $build = $runtime->build('field_comments', $this->settings());

    self::assertSame([], $build['comments']);
    self::assertSame(0, $build['count']);
    self::assertFalse($build['pager']);
    self::assertSame([], $build['form']);
    self::assertContains('entity_field_info', $build['#cache']['tags']);
    self::assertContains('node:' . $node->id(), $build['#cache']['tags']);
  }

  /**
   * Tests the Closed and Hidden commenting statuses.
   */
  public function testCommentingStatusGating(): void {
    $node = $this->setUpNode();
    $rootA = $this->comment($node, 'Root A');
    $runtime = $this->runtime($node);

    $node->get('field_comments')->status = 1;
    $node->save();
    $closed = $runtime->build('field_comments', $this->settings());
    self::assertSame(1, $closed['count']);
    self::assertSame([], $closed['form']);

    $node->get('field_comments')->status = 0;
    $node->save();
    $hidden = $runtime->build('field_comments', $this->settings());
    self::assertSame([], $hidden['comments']);
    self::assertSame([], $hidden['form']);
    unset($rootA);
  }

  /**
   * Tests that per-comment access filters unpublished and denied comments.
   */
  public function testAccessFiltersComments(): void {
    $node = $this->setUpNode();
    $rootA = $this->comment($node, 'Root A');
    $this->comment($node, 'Unpublished', $rootA, CommentInterface::NOT_PUBLISHED);
    $runtime = $this->runtime($node);

    $viewerRole = Role::create(['id' => 'comment_viewer', 'label' => 'Comment viewer']);
    $viewerRole->grantPermission('access content')->grantPermission('access comments')->save();
    $viewer = User::create(['name' => 'viewer', 'status' => 1, 'roles' => [$viewerRole->id()]]);
    $viewer->save();
    $this->container->get('current_user')->setAccount($viewer);
    $viewerState = $runtime->build('field_comments', $this->settings());
    self::assertCount(1, $viewerState['comments']);
    self::assertSame([], $viewerState['comments'][0]['children']);

    $deniedRole = Role::create(['id' => 'comment_only', 'label' => 'Comment only']);
    $deniedRole->grantPermission('access comments')->save();
    $deniedUser = User::create(['name' => 'denied', 'status' => 1, 'roles' => [$deniedRole->id()]]);
    $deniedUser->save();
    $this->container->get('current_user')->setAccount($deniedUser);
    $deniedState = $runtime->build('field_comments', $this->settings());
    self::assertSame([], $deniedState['comments']);
    self::assertContains('user.node_grants:view', $deniedState['#cache']['contexts']);
  }

  /**
   * Tests a denied comment body or author picture is left out.
   */
  public function testDeniedCommentFieldsAreLeftOut(): void {
    $node = $this->setUpNode();
    $this->addUserPictureField();
    $file = File::create(['uri' => 'public://avatar.png', 'status' => 1]);
    $file->save();
    $author = User::create(['name' => 'Pictured author', 'status' => 1, 'user_picture' => ['target_id' => $file->id()]]);
    $author->save();
    $this->comment($node, 'Root A', NULL, CommentInterface::PUBLISHED, (int) $author->id());
    $this->container->get('state')->set(FieldAccessTestHooks::DENIED, ['comment.comment_body', 'user.user_picture']);

    $state = $this->runtime($node)->build('field_comments', $this->settings());

    self::assertSame([], $state['comments'][0]['body']);
    self::assertNull($state['comments'][0]['avatar_url']);
    self::assertContains(FieldAccessTestHooks::TAG, $state['#cache']['tags']);
  }

  /**
   * Tests the avatar, text, and link fragments built from comment data.
   */
  public function testFragmentsRenderWordPressMarkup(): void {
    $node = $this->setUpNode();
    $rootA = $this->comment($node, 'Root A');
    $runtime = $this->runtime($node);
    $renderer = $this->container->get('renderer');
    $comment = $runtime->build('field_comments', $this->settings())['comments'][0];

    $avatarWithoutPicture = $runtime->fragment('wordpal_comment_avatar', [
      'comment' => $comment,
      'settings' => ['size' => 40],
    ]);
    $avatarMarkup = (string) $renderer->renderRoot($avatarWithoutPicture);
    self::assertStringNotContainsString('<img', $avatarMarkup);

    // Anonymous comments never load a stored picture: Comment::getOwner()
    // returns a fresh, field-less placeholder for uid 0. Use a real account.
    $this->addUserPictureField();
    $file = File::create(['uri' => 'public://avatar.png', 'status' => 1]);
    $file->save();
    $author = User::create(['name' => 'Pictured author', 'status' => 1]);
    $author->set('user_picture', ['target_id' => $file->id()]);
    $author->save();
    $rootWithPicture = $this->comment($node, 'Root with picture', NULL, CommentInterface::PUBLISHED, (int) $author->id());
    $withPicture = array_values(array_filter(
      $runtime->build('field_comments', $this->settings())['comments'],
      static fn (array $item): bool => $item['id'] === (string) $rootWithPicture->id(),
    ))[0];
    self::assertNotNull($withPicture['avatar_url']);
    $avatarBuild = $runtime->fragment('wordpal_comment_avatar', [
      'comment' => $withPicture,
      'settings' => ['size' => 40],
    ]);
    $avatarMarkupWithPicture = (string) $renderer->renderRoot($avatarBuild);
    self::assertStringContainsString('<img', $avatarMarkupWithPicture);
    self::assertStringContainsString($file->createFileUrl(), $avatarMarkupWithPicture);

    $contentBuild = $runtime->fragment('wordpal_comment_content', ['comment' => $comment, 'settings' => []]);
    $contentMarkup = (string) $renderer->renderRoot($contentBuild);
    self::assertStringContainsString('<p>Root A</p>', $contentMarkup);

    foreach (['wordpal_comment_author_name', 'wordpal_comment_date'] as $themeHook) {
      $fragmentBuild = $runtime->fragment($themeHook, ['comment' => $comment, 'settings' => []]);
      $fragmentMarkup = (string) $renderer->renderRoot($fragmentBuild);
      self::assertNotSame('', trim($fragmentMarkup));
    }

    // Author Name and Date carry their own block.json typography and color
    // supports, the same as any other component.
    $typedNameBuild = $runtime->fragment('wordpal_comment_author_name', [
      'comment' => $comment,
      'settings' => [
        'is_link' => FALSE,
        'font_size' => 'var:preset|font-size|small',
        'text_color' => 'var:preset|color|contrast',
      ],
    ]);
    $typedNameMarkup = (string) $renderer->renderRoot($typedNameBuild);
    self::assertStringContainsString('has-small-font-size', $typedNameMarkup);
    self::assertStringContainsString('has-contrast-color', $typedNameMarkup);

    $typedDateBuild = $runtime->fragment('wordpal_comment_date', [
      'comment' => $comment,
      'settings' => ['format' => 'F j, Y', 'is_link' => FALSE, 'font_weight' => '600'],
    ]);
    $typedDateMarkup = (string) $renderer->renderRoot($typedDateBuild);
    self::assertStringContainsString('font-weight:600', $typedDateMarkup);

    $edit = $runtime->renderActionLinks((string) $rootA->id(), 'en', 'edit');
    $editMarkup = (string) $renderer->renderRoot($edit);
    self::assertStringContainsString('wp-block-comment-edit-link', $editMarkup);
    self::assertStringNotContainsString('wp-block-comment-reply-link', $editMarkup);

    $reply = $runtime->renderActionLinks((string) $rootA->id(), 'en', 'reply');
    $replyMarkup = (string) $renderer->renderRoot($reply);
    self::assertStringContainsString('wp-block-comment-reply-link', $replyMarkup);
    self::assertStringContainsString('rel="nofollow"', $replyMarkup);

    // core/comment-reply-link carries its own block.json typography support
    // too, on the wrapper the generic links theme system builds around the
    // access-checked link itself.
    $typedReply = $runtime->renderActionLinks((string) $rootA->id(), 'en', 'reply', Json::encode(['font_size' => 'var:preset|font-size|small']));
    $typedReplyMarkup = (string) $renderer->renderRoot($typedReply);
    self::assertStringContainsString('wp-block-comment-reply-link has-small-font-size', $typedReplyMarkup);

    $titleBuild = $runtime->fragment('wordpal_comments_title', ['node' => $node, 'count' => 1, 'settings' => []]);
    $titleMarkup = (string) $renderer->renderRoot($titleBuild);
    self::assertStringContainsString('1', $titleMarkup);

    $templateBuild = $runtime->fragment('wordpal_comment_template', ['comments' => [$comment]]);
    $templateMarkup = (string) $renderer->renderRoot($templateBuild);
    self::assertStringContainsString('wp-block-comment-template', $templateMarkup);
    self::assertStringContainsString('<p>Root A</p>', $templateMarkup);
  }

  /**
   * Tests directional pagination links, including their page boundaries.
   */
  public function testPagerDirectionLinks(): void {
    $node = $this->setUpNode(perPage: 1);
    $rootA = $this->comment($node, 'Root A');
    $rootB = $this->comment($node, 'Root B');
    $runtime = $this->runtime($node);
    $runtime->build('field_comments', $this->settings());

    $previous = $runtime->pagerDirection('previous', 'Older fixture comments');
    self::assertArrayNotHasKey('#type', $previous);
    self::assertContains('url.query_args.pagers:0', $previous['#cache']['contexts']);

    $next = $runtime->pagerDirection('next', 'Newer fixture comments');
    self::assertSame('link', $next['#type']);
    self::assertSame('Newer fixture comments', $next['#title']);
    self::assertContains('wp-block-comments-pagination-next', $next['#attributes']['class']);

    $this->container->get('request_stack')->getCurrentRequest()->query->set('page', '1');
    $runtime->build('field_comments', $this->settings());
    $previousOnPageTwo = $runtime->pagerDirection('previous', 'Older fixture comments');
    self::assertSame('link', $previousOnPageTwo['#type']);
    $nextOnPageTwo = $runtime->pagerDirection('next', 'Newer fixture comments');
    self::assertArrayNotHasKey('#type', $nextOnPageTwo);
    unset($rootA, $rootB);
  }

  /**
   * Tests that the aggregate block renders WordPress pagination links only.
   */
  public function testAggregateBlockRendersWordPressPaginationLinks(): void {
    $node = $this->setUpNode(perPage: 1);
    $this->comment($node, 'Root A');
    $this->comment($node, 'Root B');
    $runtime = $this->runtime($node);

    $config = [
      'field_name' => 'field_comments',
      'comments_pagination_previous' => ['label' => 'Older fixture comments'],
      'comments_pagination_next' => ['label' => 'Newer fixture comments'],
      'layout' => [
        [
          'marker' => 'comments-pagination',
          'children' => [
            ['marker' => 'comments-pagination-previous', 'children' => []],
            ['marker' => 'comments-pagination-next', 'children' => []],
          ],
        ],
      ],
    ] + (new CommentsBlock([], 'wordpal_comments', ['provider' => 'wordpal'], $runtime))->defaultConfiguration();
    $block = new CommentsBlock($config, 'wordpal_comments', ['provider' => 'wordpal'], $runtime);
    $blockBuild = $block->build();
    $markup = (string) $this->container->get('renderer')->renderRoot($blockBuild);

    self::assertStringContainsString('wp-block-comments-pagination-next', $markup);
    self::assertStringContainsString('Newer fixture comments', $markup);
    self::assertStringNotContainsString('pager__items', $markup);
    self::assertStringNotContainsString('wp-block-comments-pagination-previous', $markup);
  }

  /**
   * Tests the comments wrapper prints its supports' style.
   */
  public function testWrapperPrintsItsSupports(): void {
    $node = $this->setUpNode();
    $runtime = $this->runtime($node);
    $config = [
      'field_name' => 'field_comments',
      'margin_top' => '2rem',
    ] + (new CommentsBlock([], 'wordpal_comments', ['provider' => 'wordpal'], $runtime))->defaultConfiguration();
    $block = new CommentsBlock($config, 'wordpal_comments', ['provider' => 'wordpal'], $runtime);
    $blockBuild = $block->build();
    $markup = (string) $this->container->get('renderer')->renderRoot($blockBuild);

    self::assertStringContainsString('<div class="wp-block-comments" style="margin-top:2rem">', $markup);
  }

  /**
   * Tests closed comments print nothing until the node has a comment.
   */
  public function testClosedCommentsWithNonePrintNothing(): void {
    $node = $this->setUpNode();
    $node->get('field_comments')->status = 1;
    $node->save();
    $runtime = $this->runtime($node);
    $config = ['field_name' => 'field_comments'] + (new CommentsBlock([], 'wordpal_comments', ['provider' => 'wordpal'], $runtime))->defaultConfiguration();
    $render = function () use ($config, $runtime): string {
      $build = (new CommentsBlock($config, 'wordpal_comments', ['provider' => 'wordpal'], $runtime))->build();
      return (string) $this->container->get('renderer')->renderRoot($build);
    };

    self::assertSame('', trim($render()));
    $this->comment($node, 'Root A');
    self::assertStringContainsString('wp-block-comments', $render());
  }

  /**
   * Tests the comments title and comment content print their supports.
   */
  public function testFragmentsPrintTheirSupports(): void {
    $node = $this->setUpNode();
    $this->comment($node, 'Root A');
    $runtime = $this->runtime($node);
    $defaults = (new CommentsBlock([], 'wordpal_comments', ['provider' => 'wordpal'], $runtime))->defaultConfiguration();
    $config = [
      'field_name' => 'field_comments',
      'comments_title' => ['padding_top' => '1rem'] + $defaults['comments_title'],
      'comment_content' => ['padding_top' => '2px'] + $defaults['comment_content'],
      'layout' => [
        ['marker' => 'comments-title', 'children' => []],
        ['marker' => 'comment-template', 'children' => [['marker' => 'comment-content', 'children' => []]]],
      ],
    ] + $defaults;
    $block = new CommentsBlock($config, 'wordpal_comments', ['provider' => 'wordpal'], $runtime);
    $blockBuild = $block->build();
    $markup = (string) $this->container->get('renderer')->renderRoot($blockBuild);

    self::assertStringContainsString('<h2 id="comments" class="wp-block-comments-title" style="padding-top:1rem">', $markup);
    self::assertStringContainsString('<div class="wp-block-comment-content" style="padding-top:2px">', $markup);
  }

  /**
   * Tests page numbers follow paginate_links(): ends, two either side, dots.
   */
  public function testPagerNumbers(): void {
    $node = $this->setUpNode(perPage: 1);
    foreach (range(1, 9) as $number) {
      $this->comment($node, "Root $number");
    }
    $runtime = $this->runtime($node);
    $renderer = $this->container->get('renderer');

    $runtime->build('field_comments', $this->settings());
    $numbers = $runtime->pagerNumbers();
    $markup = (string) $renderer->renderRoot($numbers);
    self::assertMatchesRegularExpression('#^<div class="wp-block-comments-pagination-numbers"><span aria-current="page" class="page-numbers current">1</span>\n<a class="page-numbers" href="[^"]*page=1">2</a>\n<a class="page-numbers" href="[^"]*page=2">3</a>\n<span class="page-numbers dots">…</span>\n<a class="page-numbers" href="[^"]*page=8">9</a></div>#', trim($markup));

    $this->container->get('request_stack')->getCurrentRequest()->query->set('page', '4');
    $runtime->build('field_comments', $this->settings());
    $middle = $runtime->pagerNumbers();
    $middleMarkup = (string) $renderer->renderRoot($middle);
    self::assertSame(2, substr_count($middleMarkup, 'dots'), 'Gaps before and after the window each get one dots item.');
    self::assertStringContainsString('<span aria-current="page" class="page-numbers current">5</span>', $middleMarkup);
    self::assertContains('url.query_args.pagers:0', $middle['#cache']['contexts']);
  }

  /**
   * Tests that the WordPal form theme survives a render-cache hit.
   */
  public function testFormThemeSurvivesRenderCacheHit(): void {
    $node = $this->setUpNode();
    $anonymous = Role::load(AccountInterface::ANONYMOUS_ROLE);
    $anonymous->grantPermission('access content')
      ->grantPermission('access comments')
      ->grantPermission('post comments')
      ->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $runtime = $this->runtime($node);
    $renderer = $this->container->get('renderer');
    $cacheKeys = ['wordpal_test', 'comment_form_render_cache_hit'];

    $firstBuild = [
      '#cache' => ['keys' => $cacheKeys, 'max-age' => Cache::PERMANENT],
      '#pre_render' => [
        // Adds a child holding the #lazy_builder placeholder, keeping the
        // rest of $element (notably #cache, with its bubbled required
        // contexts) intact: a real page nests CommentsBlock::build() the
        // same way, inside a Canvas component or #type => 'block' element
        // with its own #pre_render.
        function (array $element) use ($runtime): array {
          $element['form'] = $runtime->build('field_comments', $this->settings())['form'];
          return $element;
        },
      ],
    ];
    $first = (string) $renderer->renderRoot($firstBuild);
    self::assertStringContainsString('class="comment-form"', $first);
    self::assertStringContainsString('wp-block-button__link', $first);
    // setUpNode()'s default anonymous=2 matches WordPress's own
    // require_name_email: the Comment and Name/Email fields print
    // WordPress's required-field indicator and its required attribute.
    self::assertStringContainsString('<p class="comment-form-comment">', $first);
    self::assertStringContainsString('<p class="comment-form-author">', $first);
    self::assertStringContainsString('<p class="comment-form-email">', $first);
    // The Website (URL) field gets the same own-<p> wrapper as Name and
    // Email, matching WordPress's comment_form() markup for that field.
    self::assertStringContainsString('<p class="comment-form-url">', $first);
    self::assertStringContainsString('autocomplete="url"', $first);
    // WordPress's comment_form() has no subject line; Drupal's own comment
    // form display shows one by default, so the WordPal theme hides it.
    self::assertStringNotContainsString('name="subject[0][value]"', $first);
    self::assertSame(4, substr_count($first, '<span class="required">*</span>'), 'The notes paragraph and the Comment, Name and Email labels each carry one.');
    self::assertSame(3, substr_count($first, 'required="required"'), 'The Comment textarea and the Name and Email inputs are each required.');

    // A second render with the same cache keys must be a cache hit: this
    // #pre_render throws if the renderer ever calls it, proving the form
    // keeps its WordPal theme without CommentsBlock::build() running again.
    $secondBuild = [
      '#cache' => ['keys' => $cacheKeys, 'max-age' => Cache::PERMANENT],
      '#pre_render' => [
        static function (): array {
          throw new \LogicException('The element rebuilt: this was not a cache hit.');
        },
      ],
    ];
    // Drupal deduplicates repeated HTML ids within one request (--2
    // suffixes), so the two renders are not byte-identical; what matters is
    // that the second one still carries the WordPal theme, proven above.
    $second = (string) $renderer->renderRoot($secondBuild);
    self::assertStringContainsString('class="comment-form"', $second);
    self::assertStringContainsString('wp-block-button__link', $second);
  }

  /**
   * Tests that the lazy builder itself fails closed without permission.
   *
   * Calls renderCommentForm() directly, the way a forged or replayed
   * #lazy_builder placeholder would, bypassing buildForNode()'s own gate
   * entirely.
   */
  public function testRenderCommentFormDeniesWithoutPostPermission(): void {
    $node = $this->setUpNode();
    $readerRole = Role::create(['id' => 'comment_reader', 'label' => 'Comment reader']);
    $readerRole->grantPermission('access content')->grantPermission('access comments')->save();
    $reader = User::create(['name' => 'reader', 'status' => 1, 'roles' => [$readerRole->id()]]);
    $reader->save();
    $this->container->get('current_user')->setAccount($reader);
    $runtime = $this->runtime($node);

    $build = $runtime->renderCommentForm('node', (string) $node->id(), 'field_comments', 'comment');

    self::assertArrayNotHasKey('#theme', $build);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
    self::assertContains('node:' . $node->id(), $build['#cache']['tags']);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    self::assertSame('', trim($markup));
  }

  /**
   * Creates an article node with a threaded comment field.
   */
  private function setUpNode(int $perPage = 50, int $anonymous = 2): NodeInterface {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'comment']);
    $this->writeThemeSettings();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    CommentType::create(['id' => 'comment', 'label' => 'Comment', 'target_entity_type_id' => 'node'])->save();
    $this->addDefaultCommentField('node', 'article', 'field_comments');
    $field = $this->container->get('entity_type.manager')->getStorage('field_config')->load('node.article.field_comments');
    $field->setSetting('per_page', $perPage)->setSetting('anonymous', $anonymous)->save();

    $account = User::create(['name' => 'admin', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Threaded post',
      'status' => 1,
      'field_comments' => ['status' => 2],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Adds the optional image field WordPal reads for the comment avatar.
   */
  private function addUserPictureField(): void {
    FieldStorageConfig::create([
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'type' => 'image',
      'settings' => ['target_type' => 'file', 'uri_scheme' => 'public'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'bundle' => 'user',
    ])->save();
  }

  /**
   * Builds a CommentRuntime service bound to a fixed current-page node.
   */
  private function runtime(NodeInterface $node): CommentRuntime {
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getParameter')->with('node')->willReturn($node);
    return new CommentRuntime(
      $this->container->get('entity_type.manager'),
      $routeMatch,
      $this->container->get('current_user'),
      $this->container->get('date.formatter'),
      $this->container->get('pager.manager'),
      $this->container->get('comment.lazy_builders'),
      $this->container->get(BlockSupports::class),
      $this->container->get('string_translation'),
    );
  }

  /**
   * Creates one published comment, optionally as a reply.
   */
  private function comment(NodeInterface $node, string $body, ?CommentInterface $parent = NULL, int $status = CommentInterface::PUBLISHED, int $uid = 0): CommentInterface {
    $comment = Comment::create([
      'comment_type' => 'comment',
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_comments',
      'uid' => $uid,
      'name' => $body . ' author',
      'comment_body' => ['value' => $body, 'format' => 'plain_text'],
      'status' => $status,
      'pid' => $parent?->id(),
    ]);
    $comment->save();
    return $comment;
  }

  /**
   * Returns the aggregate settings used by the runtime service.
   */
  private function settings(): array {
    return [
      'layout' => [
        ['marker' => 'comment-template', 'children' => [['marker' => 'comment-content', 'children' => []]]],
      ],
      'avatar' => ['size' => 40, 'is_link' => FALSE],
      'comment_author_name' => ['is_link' => FALSE],
      'comment_date' => ['format' => 'F j, Y', 'is_link' => TRUE],
      'comment_content' => [],
      'comment_edit_link' => ['label' => 'Edit'],
      'comment_reply_link' => ['label' => 'Reply'],
    ];
  }

}
