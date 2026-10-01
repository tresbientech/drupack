<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\comment\Entity\CommentType;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\wordpal\Listing\ListingSettings;
use Drupal\wordpal_convert\Listing\ListingViewConfig;
use Drupal\wordpal_field_access_test\Hook\FieldAccessTestHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Renders a listing View through its views_block plugin.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ListingViewTest extends KernelTestBase {

  use CommentTestTrait;

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
    'filter',
    'text',
    'node',
    'comment',
    'views',
    'wordpal',
    'field_test',
    'wordpal_field_access_test',
  ];

  /**
   * The post author.
   */
  private User $author;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'wordpal']);
    NodeType::create(['type' => 'blog', 'name' => 'Blog'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    User::create(['name' => 'anonymous', 'uid' => 0])->save();
    $this->author = User::create(['name' => 'Morgan Reed', 'status' => 1]);
    $this->author->save();
    user_role_grant_permissions('anonymous', ['access content']);
  }

  /**
   * Creates a published node.
   */
  private function createNode(string $bundle, string $title, int $created, array $values = []): Node {
    $node = Node::create($values + [
      'type' => $bundle,
      'title' => $title,
      'uid' => $this->author->id(),
      'status' => 1,
      'created' => $created,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Makes a request the current one, with an optional route.
   */
  private function visit(string $uri, ?string $routeName = NULL, array $parameters = []): void {
    $request = Request::create($uri);
    $request->setSession(new Session(new MockArraySessionStorage()));
    if ($routeName !== NULL) {
      $request->attributes->add([
        RouteObjectInterface::ROUTE_NAME => $routeName,
        RouteObjectInterface::ROUTE_OBJECT => new Route('/{' . implode('}/{', array_keys($parameters)) . '}'),
      ] + $parameters);
    }
    $this->container->get('request_stack')->push($request);
    $this->container->get('current_route_match')->resetRouteMatch();
  }

  /**
   * Returns latest-posts listing defaults, with overrides applied.
   */
  private function latestPostsSettings(array $overrides): array {
    return array_replace(ListingSettings::defaults('latest_posts'), $overrides);
  }

  /**
   * Returns page-list listing defaults, with overrides applied.
   */
  private function pageListSettings(array $overrides): array {
    return array_replace(ListingSettings::defaults('page_list'), $overrides);
  }

  /**
   * Saves a page-list listing View with the given block settings.
   */
  private function savePageListView(string $id, array $settings): void {
    $view = View::create(ListingViewConfig::pageList($id, 'Page list', $settings['bundle']));
    $view->setThirdPartySetting('wordpal', 'listing', ['kind' => 'page_list', 'settings' => $settings]);
    $view->save();
    // The block plugin manager's discovery cache is a static, populated by
    // any earlier createInstance() call; the new View's derivative needs a
    // fresh scan to be found.
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
  }

  /**
   * Saves a latest-posts listing View with the given block settings.
   */
  private function saveLatestPostsView(string $id, array $settings, int $perPage = 5): void {
    $view = View::create(ListingViewConfig::latestPosts(
      $id,
      'Latest posts',
      $settings['bundle'],
      $perPage,
      $settings['order_by'] === 'title' ? 'title' : 'created',
      $settings['order'] === 'asc' ? 'asc' : 'desc',
    ));
    $view->setThirdPartySetting('wordpal', 'listing', ['kind' => 'latest_posts', 'settings' => $settings]);
    $view->save();
    // The block plugin manager's discovery cache is a static, populated by
    // any earlier createInstance() call; the new View's derivative needs a
    // fresh scan to be found.
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
  }

  /**
   * Renders a listing View's block display through the block manager.
   */
  private function renderListingBlock(string $viewId): string {
    $build = $this->container->get('plugin.manager.block')
      ->createInstance("views_block:$viewId-block_1", [])
      ->build();
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Renders a listing View's block through the block theme hook.
   *
   * Unlike renderListingBlock(), this goes through the same 'block' theme
   * hook a placed block renders through, so the block-level suggestion
   * applies and the wrapper it strips is actually exercised.
   */
  private function renderListingBlockThroughBlockTheme(string $viewId): string {
    $pluginId = "views_block:$viewId-block_1";
    $content = $this->container->get('plugin.manager.block')
      ->createInstance($pluginId, [])
      ->build();
    $build = [
      '#theme' => 'block',
      '#configuration' => ['provider' => 'views', 'label_display' => '0', 'label' => ''],
      '#plugin_id' => $pluginId,
      '#base_plugin_id' => 'views_block',
      '#derivative_plugin_id' => "$viewId-block_1",
      'content' => $content,
    ];
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * Tests latest posts lists the newest published posts of the bundle.
   */
  public function testLatestPostsViewListsPublishedPostsNewestFirst(): void {
    $this->createNode('blog', 'Older', 1000);
    $this->createNode('blog', 'Newer', 2000);
    $this->createNode('blog', 'Hidden draft', 3000, ['status' => 0]);
    $this->createNode('page', 'About', 4000);
    $settings = $this->latestPostsSettings([
      'bundle' => 'blog',
      'posts_to_show' => 5,
      'display_author' => TRUE,
      'display_post_date' => TRUE,
    ]);
    $this->saveLatestPostsView('wordpal_t_latest_posts', $settings, 5);

    $html = $this->renderListingBlock('wordpal_t_latest_posts');

    self::assertStringContainsString('<ul class="wp-block-latest-posts__list has-dates has-author wp-block-latest-posts is-layout-flow wp-block-latest-posts-is-layout-flow"><li><a class="wp-block-latest-posts__post-title" href="/node/2">Newer</a><div class="wp-block-latest-posts__post-author">by Morgan Reed</div><time datetime="', $html);
    self::assertLessThan(strpos($html, 'Older'), strpos($html, 'Newer'));
    self::assertStringNotContainsString('Hidden draft', $html);
    self::assertStringNotContainsString('About', $html);
  }

  /**
   * Tests latest posts shows the mapped excerpt and featured image.
   */
  public function testLatestPostsViewShowsExcerptAndImage(): void {
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    FieldStorageConfig::create([
      'field_name' => 'field_description',
      'entity_type' => 'node',
      'type' => 'string_long',
    ])->save();
    FieldConfig::create(['field_name' => 'field_description', 'entity_type' => 'node', 'bundle' => 'blog'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_featured_image',
      'entity_type' => 'node',
      'type' => 'image',
    ])->save();
    FieldConfig::create(['field_name' => 'field_featured_image', 'entity_type' => 'node', 'bundle' => 'blog'])->save();
    file_put_contents('public://city.png', 'image');
    $file = File::create(['uri' => 'public://city.png', 'status' => 1]);
    $file->save();
    $this->createNode('blog', 'Short', 1000, [
      'field_description' => 'A short walk.',
      'field_featured_image' => ['target_id' => $file->id(), 'alt' => 'Street', 'width' => 40, 'height' => 30],
    ]);
    $this->createNode('blog', 'Long', 2000, ['field_description' => 'One two three four five']);
    $settings = $this->latestPostsSettings([
      'bundle' => 'blog',
      'display_post_content' => TRUE,
      'excerpt_length' => 3,
      'display_featured_image' => TRUE,
      'featured_image_size_slug' => 'medium',
      'excerpt_field' => 'field_description',
      'image_field' => 'field_featured_image',
    ]);
    $this->saveLatestPostsView('wordpal_t_latest_excerpt', $settings);

    $html = $this->renderListingBlock('wordpal_t_latest_excerpt');

    self::assertStringContainsString('<div class="wp-block-latest-posts__post-excerpt">One two three … <a class="wp-block-latest-posts__read-more" href="/node/2" rel="noopener">Read more<span class="screen-reader-text">: Long</span></a></div>', $html);
    self::assertMatchesRegularExpression('#<li><div class="wp-block-latest-posts__featured-image"><img width="40" height="30" src="[^"]*city.png" class="attachment-medium size-medium wp-post-image" alt="Street" style="" decoding="async" loading="lazy" /></div><a class="wp-block-latest-posts__post-title" href="/node/1">Short</a><div class="wp-block-latest-posts__post-excerpt">A short walk.</div></li>#', $html);
  }

  /**
   * Tests the listing block adds its suggestions and renders a bare <ul>.
   *
   * WordPress prints a Latest Posts block as a bare <ul>; Drupal's block and
   * views-element-container wrappers must both be dropped to match.
   */
  public function testLatestPostsBlockRendersBareListElement(): void {
    $this->createNode('blog', 'Only post', 1000);
    $settings = $this->latestPostsSettings(['bundle' => 'blog']);
    $this->saveLatestPostsView('wordpal_t_latest_bare', $settings);

    $blockSuggestions = [];
    wordpal_theme_suggestions_block_alter($blockSuggestions, [
      'elements' => ['#plugin_id' => 'views_block:wordpal_t_latest_bare-block_1'],
    ]);
    self::assertSame(['block__views_block__wordpal_listing'], $blockSuggestions);

    $html = $this->renderListingBlockThroughBlockTheme('wordpal_t_latest_bare');

    self::assertStringNotContainsString('views-element-container', $html);
    self::assertStringNotContainsString('<div', $html);
    self::assertStringStartsWith('<ul', trim($html));
  }

  /**
   * Tests an excerpt field the user may not view prints empty, no error.
   */
  public function testLatestPostsViewWithoutFieldAccessPrintsEmptyExcerpt(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_no_view_access',
      'entity_type' => 'node',
      'type' => 'string_long',
    ])->save();
    FieldConfig::create(['field_name' => 'field_no_view_access', 'entity_type' => 'node', 'bundle' => 'blog'])->save();
    $this->createNode('blog', 'Guarded', 1000, ['field_no_view_access' => 'Secret body text.']);
    $settings = $this->latestPostsSettings([
      'bundle' => 'blog',
      'display_post_content' => TRUE,
      'excerpt_field' => 'field_no_view_access',
    ]);
    $this->saveLatestPostsView('wordpal_t_latest_guarded', $settings);

    $html = $this->renderListingBlock('wordpal_t_latest_guarded');

    self::assertStringContainsString('<div class="wp-block-latest-posts__post-excerpt"></div>', $html);
    self::assertStringNotContainsString('Secret body text.', $html);
  }

  /**
   * Tests a latest-comments excerpt stops at the site's summary length.
   */
  public function testLatestCommentsExcerptFollowsSummaryLength(): void {
    $this->installEntitySchema('comment');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['comment']);
    CommentType::create(['id' => 'comment', 'label' => 'Comment', 'target_entity_type_id' => 'node'])->save();
    $this->addDefaultCommentField('node', 'blog', 'field_comments');
    user_role_grant_permissions('anonymous', ['access comments']);
    $this->config('text.settings')->set('default_summary_length', 30)->save();
    $this->addComment($this->postWithComments('City walk', 1), 'A short first sentence. Then a much longer second sentence follows.', 'Bea', 300);
    $this->saveLatestCommentsView('wordpal_t_latest_summary', $this->latestCommentsSettings(['field_name' => 'field_comments']));

    $html = $this->renderListingBlock('wordpal_t_latest_summary');

    self::assertStringContainsString('<div class="wp-block-latest-comments__comment-excerpt"><p>A short first sentence.</p></div>', $html);
    self::assertStringNotContainsString('second sentence', $html);
  }

  /**
   * Tests the page list marks the current page and varies by route.
   */
  public function testPageListViewMarksCurrentPage(): void {
    $contact = $this->createNode('page', 'Contact', 1000);
    $this->createNode('page', 'About', 2000);
    $this->createNode('blog', 'A post', 3000);
    $settings = $this->pageListSettings(['bundle' => 'page']);
    $this->savePageListView('wordpal_t_page_list', $settings);
    $this->visit('/node/' . $contact->id(), 'entity.node.canonical', ['node' => $contact]);

    $build = $this->container->get('plugin.manager.block')
      ->createInstance('views_block:wordpal_t_page_list-block_1', [])
      ->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertContains('route', $build['#cache']['contexts']);
    self::assertStringContainsString('<ul class="wp-block-page-list"><li class="wp-block-pages-list__item"><a class="wp-block-pages-list__item__link" href="/node/2">About</a></li><li class="wp-block-pages-list__item current-menu-item"><a class="wp-block-pages-list__item__link" href="/node/1" aria-current="page">Contact</a></li></ul>', $html);
  }

  /**
   * Returns latest-comments listing defaults, with overrides applied.
   */
  private function latestCommentsSettings(array $overrides): array {
    return array_replace(ListingSettings::defaults('latest_comments'), $overrides);
  }

  /**
   * Saves a latest-comments listing View with the given block settings.
   */
  private function saveLatestCommentsView(string $id, array $settings, int $perPage = 5): void {
    $view = View::create(ListingViewConfig::latestComments($id, 'Latest comments', $settings['field_name'], $perPage));
    $view->setThirdPartySetting('wordpal', 'listing', ['kind' => 'latest_comments', 'settings' => $settings]);
    $view->save();
    // The block plugin manager's discovery cache is a static, populated by
    // any earlier createInstance() call; the new View's derivative needs a
    // fresh scan to be found.
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
  }

  /**
   * Creates a blog post with a comment field.
   */
  private function postWithComments(string $title, int $status): NodeInterface {
    $node = Node::create(['type' => 'blog', 'title' => $title, 'status' => $status, 'field_comments' => ['status' => 2]]);
    $node->save();
    return $node;
  }

  /**
   * Creates an anonymous comment.
   */
  private function addComment(NodeInterface $node, string $body, string $name, int $created, ?string $homepage = NULL, int $status = CommentInterface::PUBLISHED): void {
    Comment::create([
      'comment_type' => 'comment',
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_comments',
      'uid' => 0,
      'name' => $name,
      'homepage' => $homepage,
      'comment_body' => ['value' => $body, 'format' => 'plain_text'],
      'status' => $status,
      'created' => $created,
    ])->save();
  }

  /**
   * Tests the newest published comments list with author, post and excerpt.
   */
  public function testLatestCommentsViewListsNewestPublishedComments(): void {
    $this->installEntitySchema('comment');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['comment']);
    CommentType::create(['id' => 'comment', 'label' => 'Comment', 'target_entity_type_id' => 'node'])->save();
    $this->addDefaultCommentField('node', 'blog', 'field_comments');
    user_role_grant_permissions('anonymous', ['access comments']);

    $post = $this->postWithComments('City walk', 1);
    $draft = $this->postWithComments('Draft walk', 0);
    $this->addComment($post, 'Old comment', 'Ada', 100);
    $this->addComment($post, 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twentyone', 'Bea', 300, 'https://bea.example.com');
    $this->addComment($post, 'Hidden', 'Cy', 400, NULL, CommentInterface::NOT_PUBLISHED);
    $this->addComment($draft, 'On a draft', 'Di', 500);
    $this->addComment($post, 'Javascript', 'Ed', 200, 'javascript:alert(1)');
    $settings = $this->latestCommentsSettings(['field_name' => 'field_comments']);
    $this->saveLatestCommentsView('wordpal_t_latest_comments', $settings, 2);

    $html = $this->renderListingBlock('wordpal_t_latest_comments');

    self::assertStringContainsString('<ol class="has-avatars has-dates has-excerpts wp-block-latest-comments">', $html);
    self::assertSame(2, substr_count($html, '<li class="wp-block-latest-comments__comment">'));
    self::assertMatchesRegularExpression('#<a class="wp-block-latest-comments__comment-author" href="https://bea.example.com">Bea</a> on <a class="wp-block-latest-comments__comment-link" href="[^"]*\\#comment-\\d+">City walk</a><time datetime="[^"]+" class="wp-block-latest-comments__comment-date">#', $html);
    self::assertStringContainsString('<div class="wp-block-latest-comments__comment-excerpt"><p>one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twentyone</p></div>', $html);
    self::assertStringNotContainsString('javascript:', $html);
    self::assertStringContainsString('>Ed</a>', $html);
    self::assertStringNotContainsString('Hidden', $html);
    self::assertStringNotContainsString('On a draft', $html);
    self::assertStringNotContainsString('Old comment', $html);
  }

  /**
   * Tests a comment body the viewer may not view prints no excerpt text.
   */
  public function testLatestCommentsViewWithoutBodyAccessPrintsNoExcerpt(): void {
    $this->installEntitySchema('comment');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['comment']);
    CommentType::create(['id' => 'comment', 'label' => 'Comment', 'target_entity_type_id' => 'node'])->save();
    $this->addDefaultCommentField('node', 'blog', 'field_comments');
    user_role_grant_permissions('anonymous', ['access comments']);
    $this->addComment($this->postWithComments('City walk', 1), 'Secret comment text', 'Ada', 100);
    $this->container->get('state')->set(FieldAccessTestHooks::DENIED, ['comment.comment_body']);
    $this->saveLatestCommentsView('wordpal_t_latest_guarded_comments', $this->latestCommentsSettings(['field_name' => 'field_comments']));

    $html = $this->renderListingBlock('wordpal_t_latest_guarded_comments');

    self::assertStringContainsString('>Ada<', $html);
    self::assertStringNotContainsString('Secret comment text', $html);
  }

}
