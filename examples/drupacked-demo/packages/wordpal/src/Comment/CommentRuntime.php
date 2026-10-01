<?php

declare(strict_types=1);

namespace Drupal\wordpal\Comment;

use Drupal\comment\CommentInterface;
use Drupal\comment\CommentLazyBuilders;
use Drupal\comment\CommentingStatus;
use Drupal\comment\CommentPreviewMode;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\wordpal\Layout\LayoutRenderer;
use Drupal\wordpal\Listing\ListCacheMetadata;
use Drupal\wordpal\Support\BlockSupports;
use Drupal\wordpal\Support\Cacheable;

/**
 * Builds access-checked Drupal comments for WordPress-compatible rendering.
 */
final class CommentRuntime implements TrustedCallbackInterface {

  use StringTranslationTrait;

  public const DEFAULT_DATE_FORMAT = 'F j, Y';

  /**
   * The pager element the root-comment query and its links share.
   */
  private const PAGER_ELEMENT = 0;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountInterface $currentUser,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PagerManagerInterface $pagerManager,
    private readonly CommentLazyBuilders $commentLazyBuilders,
    private readonly BlockSupports $blockSupports,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the complete comment runtime state for the current node.
   */
  public function build(string $fieldName, array $settings): array {
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface) {
      return [
        'node' => NULL,
        'comments' => [],
        'count' => 0,
        'pager' => FALSE,
        'open' => FALSE,
        'form' => [],
        '#cache' => ['contexts' => ['route']],
      ];
    }
    return $this->buildForNode($node, $fieldName, $settings);
  }

  /**
   * Builds comment state for an explicit node and mapped field.
   */
  private function buildForNode(NodeInterface $node, string $fieldName, array $settings): array {
    if (!$node->hasField($fieldName)) {
      // WordPress prints nothing for comments when a post type does not
      // support them; a bundle with no mapped comment field renders the
      // same way, and stays in sync if the field is added to it later.
      $metadata = (new CacheableMetadata())
        ->addCacheableDependency($node)
        ->addCacheTags(['entity_field_info']);
      $build = [
        'node' => $node,
        'comments' => [],
        'count' => 0,
        'pager' => FALSE,
        'open' => FALSE,
        'form' => [],
      ];
      $metadata->applyTo($build);
      return $build;
    }
    $field = $node->get($fieldName);
    $definition = $field->getFieldDefinition();
    $fieldSettings = $definition->getSettings();
    $metadata = ListCacheMetadata::forNodeAccess()
      ->addCacheContexts([
        'route',
        'timezone',
        'url.query_args.pagers:' . self::PAGER_ELEMENT,
        'user.roles:authenticated',
      ])
      ->addCacheTags(['comment_list', 'comment_list:' . $node->getEntityTypeId() . ':' . $node->id()])
      ->addCacheableDependency($node)
      ->addCacheableDependency($definition->getConfig($node->bundle()));

    if (!Cacheable::allowed($node->access('view', $this->currentUser, TRUE), $metadata)) {
      $build = [
        'node' => $node,
        'comments' => [],
        'count' => 0,
        'pager' => FALSE,
        'open' => FALSE,
        'form' => [],
      ];
      $metadata->applyTo($build);
      return $build;
    }

    $commentingStatus = CommentingStatus::tryFrom((int) $field->status);
    if ($commentingStatus === CommentingStatus::Hidden) {
      $build = [
        'node' => $node,
        'comments' => [],
        'count' => 0,
        'pager' => FALSE,
        'open' => FALSE,
        'form' => [],
      ];
      $metadata->applyTo($build);
      return $build;
    }

    $comments = [];
    $hasPager = FALSE;
    $count = 0;
    if ($this->currentUser->hasPermission('access comments')) {
      $perPage = (int) $fieldSettings['per_page'];
      $loaded = $this->loadPage($node, $fieldName, $perPage);
      $comments = $this->thread($loaded, $node, $settings, $metadata);
      if ($perPage > 0) {
        $hasPager = $this->pagerManager->getPager(self::PAGER_ELEMENT)->getTotalPages() > 1;
      }
      $count = $this->totalCount($node, $fieldName);
    }
    $form = [];
    if (Cacheable::allowed($this->formAccess($node, $fieldName), $metadata)) {
      $form = [
        '#lazy_builder' => [
          'Drupal\wordpal\Comment\CommentRuntime:renderCommentForm',
          [$node->getEntityTypeId(), (string) $node->id(), $fieldName, $fieldSettings['comment_type']],
        ],
        '#create_placeholder' => TRUE,
      ];
    }
    $build = [
      'node' => $node,
      'comments' => $comments,
      'count' => $count,
      'pager' => $hasPager,
      'open' => $commentingStatus === CommentingStatus::Open,
      'form' => $form,
    ];
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Builds one directional link from the active root-comment pager.
   */
  public function pagerDirection(string $direction, string $label): array {
    $pager = $this->pagerManager->getPager(self::PAGER_ELEMENT);
    $build = ['#cache' => ['contexts' => ['url.query_args.pagers:' . self::PAGER_ELEMENT]]];
    if ($pager === NULL) {
      return $build;
    }
    $target = $pager->getCurrentPage() + ($direction === 'previous' ? -1 : 1);
    if ($target < 0 || $target >= $pager->getTotalPages()) {
      return $build;
    }
    $url = $this->pageUrl($target);
    $metadata = new CacheableMetadata();
    Cacheable::url($url, $metadata);
    $build += [
      '#type' => 'link',
      '#title' => $label,
      '#url' => $url,
      '#attributes' => ['class' => ['wp-block-comments-pagination-' . $direction]],
    ];
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Builds the page number links of the active root-comment pager.
   */
  public function pagerNumbers(): array {
    $build = ['#cache' => ['contexts' => ['url.query_args.pagers:' . self::PAGER_ELEMENT]]];
    $pager = $this->pagerManager->getPager(self::PAGER_ELEMENT);
    if ($pager === NULL || $pager->getTotalPages() < 2) {
      return $build;
    }
    $current = $pager->getCurrentPage();
    $last = $pager->getTotalPages() - 1;
    $metadata = new CacheableMetadata();
    $pages = [];
    $dots = FALSE;
    // paginate_links() defaults: end_size 1 and mid_size 2, one "…" per gap.
    foreach (range(0, $last) as $page) {
      if ($page === 0 || $page === $last || abs($page - $current) <= 2) {
        $href = $page === $current ? NULL : Cacheable::url($this->pageUrl($page), $metadata);
        $pages[] = ['number' => $page + 1, 'url' => $href];
        $dots = TRUE;
      }
      elseif ($dots) {
        $pages[] = ['number' => NULL, 'url' => NULL];
        $dots = FALSE;
      }
    }
    $build += ['#theme' => 'wordpal_comments_pagination_numbers', '#pages' => $pages];
    CacheableMetadata::createFromRenderArray($build)->merge($metadata)->applyTo($build);
    return $build;
  }

  /**
   * Returns the current page's URL showing one root-comment page.
   */
  private function pageUrl(int $page): Url {
    return Url::fromRoute('<current>', [], [
      'query' => $this->pagerManager->getUpdatedParameters([], self::PAGER_ELEMENT, $page),
    ]);
  }

  /**
   * Builds a themed render array for one comment fragment.
   */
  public function fragment(string $themeHook, array $variables): array {
    $build = ['#theme' => $themeHook];
    foreach ($variables as $name => $value) {
      $build['#' . $name] = $value;
    }
    return $build;
  }

  /**
   * Lazy builder that renders and WordPal-themes the comment form.
   *
   * A #lazy_builder callback always re-executes at render time, even when
   * the block around it is served from the render cache, so the form is
   * never rendered stale, and no marker is needed to find it again. Access
   * fails closed here too: this callback is itself the boundary a forged
   * or replayed placeholder invocation would cross, so it checks again
   * rather than trusting that buildForNode() already did.
   */
  public function renderCommentForm(string $entityTypeId, string $entityId, string $fieldName, string $commentTypeId): array {
    $node = $this->entityTypeManager->getStorage($entityTypeId)->load($entityId);
    $access = $node instanceof NodeInterface
      ? $this->formAccess($node, $fieldName)
      : AccessResult::forbidden('The commented entity is not a node.');
    $metadata = new CacheableMetadata();
    if (!Cacheable::allowed($access, $metadata)) {
      $build = [];
      $metadata->applyTo($build);
      return $build;
    }
    $form = $this->commentLazyBuilders->renderForm($entityTypeId, $entityId, $fieldName, $commentTypeId);
    $previewMode = CommentPreviewMode::tryFrom((int) $node->get($fieldName)->getFieldDefinition()->getSetting('preview'));
    $themed = $this->themeCommentForm($form, $previewMode);
    $metadata->applyTo($themed);
    return $themed;
  }

  /**
   * Checks whether $fieldName's comment form may render for the current user.
   *
   * Shared by buildForNode(), which decides whether to attach the lazy
   * builder, and renderCommentForm(), the lazy builder itself: the same
   * failing-closed check, run in both places, not two copies of it.
   */
  private function formAccess(NodeInterface $node, string $fieldName): AccessResultInterface {
    if (!$node->hasField($fieldName)) {
      return AccessResult::forbidden("The comments field $fieldName does not exist.")
        ->addCacheableDependency($node);
    }
    $commentingStatus = CommentingStatus::tryFrom((int) $node->get($fieldName)->status);
    return $node->access('view', $this->currentUser, TRUE)
      ->andIf(AccessResult::allowedIf($commentingStatus === CommentingStatus::Open))
      ->andIf(AccessResult::allowedIfHasPermissions($this->currentUser, ['access comments', 'post comments']))
      ->addCacheableDependency($node);
  }

  /**
   * Lazy builder that retains one core access-checked comment action.
   *
   * Comment-reply-link and comment-edit-link's own typography and color
   * supports land on the wrapper Drupal's generic links theme system has no
   * room for: template_preprocess_links() folds every other $link key into
   * the rendered link's own options, so a support class computed here
   * reaches the template as its own wrapper_attributes variable instead.
   */
  public function renderActionLinks(string $commentId, string $langcode, string $action, string $settingsJson = '{}'): array {
    $settings = Json::decode($settingsJson);
    $build = $this->commentLazyBuilders->renderLinks($commentId, 'default', $langcode, FALSE);
    $key = 'comment-' . $action;
    $link = $build['comment']['#links'][$key] ?? NULL;
    if ($link === NULL) {
      return ['#cache' => $build['#cache'] ?? []];
    }
    $comment = $this->entityTypeManager->getStorage('comment')->load($commentId);
    $link['attributes']['class'][] = $action === 'reply' ? 'comment-reply-link' : 'comment-edit-link';
    if ($action === 'reply') {
      $author = $comment->getAuthorName();
      $link['attributes'] += [
        'rel' => 'nofollow',
        'data-commentid' => $commentId,
        'data-postid' => $comment->getCommentedEntityId(),
        'data-belowelement' => 'comment-' . $commentId,
        'data-respondelement' => 'respond',
        'data-replyto' => 'Reply to ' . $author,
        'aria-label' => 'Reply to ' . $author,
      ];
    }
    $output = $this->blockSupports->render('core/comment-' . $action . '-link', $settings, FALSE);
    $wrapperAttributes = (new Attribute())->addClass('wp-block-comment-' . $action . '-link')->addClass($output->classes);
    if ($output->styles !== []) {
      $wrapperAttributes->setAttribute('style', $output->style());
    }
    return [
      '#theme' => 'links__wordpal_comment',
      // Core's renderLinks() sets the callbacks.
      // @phpstan-ignore renderCallback.expectsArrayOfCallbacks
      '#pre_render' => $build['#pre_render'],
      '#links' => [$key => $link],
      '#wrapper_attributes' => $wrapperAttributes,
      '#cache' => $build['#cache'] ?? [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['renderActionLinks', 'renderCommentForm'];
  }

  /**
   * Applies the WordPal comment-form theme, once, to a built core form.
   */
  private function themeCommentForm(array $form, ?CommentPreviewMode $previewMode): array {
    $form['#theme'] = 'wordpal_comment_form';
    $form['#id'] = 'commentform';
    $form['#attributes']['class'] = ['comment-form'];
    $value = &$form['comment_body']['widget'][0]['value'];
    $value['#theme_wrappers'] = [];
    $value['#theme'] = 'wordpal_textarea';
    unset($value['#prefix'], $value['#suffix'], $value['#placeholder']);
    $value['#cols'] = 45;
    $value['#rows'] = 8;
    $value['#maxlength'] = 65525;
    $value['#resizable'] = FALSE;
    $value['#attributes']['class'] = [];
    unset($value);
    $form['comment_body']['widget'][0]['format']['#access'] = FALSE;
    // WordPress's own comment_form() has no subject line; hide Drupal's
    // regardless of the comment type's form display, the same way the text
    // format selector above is hidden unconditionally.
    $form['subject']['#access'] = FALSE;
    $form['author']['name']['#theme_wrappers'] = [];
    $form['author']['name']['#attributes'] = ['autocomplete' => 'name'];
    $form['author']['mail']['#theme_wrappers'] = [];
    $form['author']['mail']['#attributes'] = [
      'autocomplete' => 'email',
      'aria-describedby' => 'email-notes',
    ];
    $form['author']['homepage']['#theme_wrappers'] = [];
    $form['author']['homepage']['#attributes'] = ['autocomplete' => 'url'];
    // WordPress's comment_form() has one submit only. Drupal's own Preview
    // step is skippable when the field setting is optional, so hide it;
    // when the field requires preview, Save only appears after a preview,
    // so Preview stays.
    if ($previewMode === CommentPreviewMode::Optional) {
      $form['actions']['preview']['#access'] = FALSE;
    }
    $form['actions']['#theme_wrappers'] = [];
    $form['actions']['submit']['#attributes']['class'] = [
      'wp-block-button__link',
      'wp-element-button',
    ];
    $form['actions']['submit']['#value'] = $this->t('Post Comment');
    return $form;
  }

  /**
   * Loads one root-paged page of comments, each with its full reply tree.
   *
   * WordPress pages by root thread and keeps every reply with its root.
   * CommentStorage::loadThread()'s pager limits the flat, already-threaded
   * row list, which can split a thread's replies across two pages, so
   * paging happens on the root query instead: load one page of root
   * comment IDs, then load their replies at every depth.
   */
  private function loadPage(NodeInterface $node, string $fieldName, int $perPage): array {
    $storage = $this->entityTypeManager->getStorage('comment');
    $rootQuery = $this->commentQuery($node, $fieldName)
      ->condition('pid', NULL, 'IS NULL')
      ->sort('cid');
    if ($perPage > 0) {
      $rootQuery->pager($perPage, self::PAGER_ELEMENT);
    }
    $ids = $rootQuery->execute();
    $frontier = $ids;
    while ($frontier !== []) {
      $frontier = $this->commentQuery($node, $fieldName)
        ->condition('pid', $frontier, 'IN')
        ->execute();
      $ids += $frontier;
    }
    if ($ids === []) {
      return [];
    }
    $comments = $storage->loadMultiple($ids);
    ksort($comments);
    return $comments;
  }

  /**
   * Counts every access-visible comment, independent of the current page.
   *
   * The query from commentQuery() already applies the published filter for
   * non-admins, and the caller already checked node access and the "access
   * comments" permission before reaching here, so no per-comment check is
   * left to run.
   */
  private function totalCount(NodeInterface $node, string $fieldName): int {
    return (int) $this->commentQuery($node, $fieldName)->count()->execute();
  }

  /**
   * Returns one access-scoped comment query for a node's comment field.
   *
   * Access check stays off here: thread() below runs the real, failing-
   * closed $comment->access('view', ...) check on every loaded comment,
   * the same as CommentStorage::loadThread() leaves to its callers.
   */
  private function commentQuery(NodeInterface $node, string $fieldName): QueryInterface {
    $query = $this->entityTypeManager->getStorage('comment')->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_id', $node->id())
      ->condition('entity_type', $node->getEntityTypeId())
      ->condition('field_name', $fieldName)
      ->condition('default_langcode', 1)
      ->addTag('entity_access')
      ->addTag('comment_filter');
    if (!$this->currentUser->hasPermission('administer comments')) {
      $query->condition('status', CommentInterface::PUBLISHED);
    }
    return $query;
  }

  /**
   * Converts comments into a nested, render-ready tree.
   */
  private function thread(array $comments, NodeInterface $node, array $settings, CacheableMetadata $metadata): array {
    $items = [];
    $sequence = 0;
    foreach ($comments as $comment) {
      if (!Cacheable::allowed($comment->access('view', $this->currentUser, TRUE), $metadata)) {
        continue;
      }
      $metadata->addCacheableDependency($comment);
      $items[$comment->id()] = $this->item($comment, $node, $settings, $metadata);
      $items[$comment->id()]['parity'] = ++$sequence % 2 === 1 ? 'even' : 'odd alt';
    }
    $roots = [];
    foreach ($items as &$item) {
      $parentId = $item['parent_id'];
      if ($parentId !== NULL && isset($items[$parentId])) {
        $items[$parentId]['children'][] = &$item;
      }
      else {
        $roots[] = &$item;
      }
    }
    $template = $this->markerChildren($settings['layout'], 'comment-template');
    $this->addContent($roots, $template, $settings);
    return $roots;
  }

  /**
   * Builds one comment item and its action URLs.
   */
  private function item(CommentInterface $comment, NodeInterface $node, array $settings, CacheableMetadata $metadata): array {
    $permalink = Cacheable::url($node->toUrl('canonical', ['fragment' => 'comment-' . $comment->id()]), $metadata);
    $created = (int) $comment->getCreatedTime();
    $owner = $comment->getOwner();
    $metadata->addCacheableDependency($owner);
    $body = $comment->get('comment_body');
    return [
      'id' => (string) $comment->id(),
      'parent_id' => $comment->get('pid')->target_id
        ? (string) $comment->get('pid')->target_id
        : NULL,
      'author' => $comment->getAuthorName(),
      'avatar_url' => Cacheable::picture($owner, $metadata)?->createFileUrl(),
      'created' => $created,
      'date' => $this->dateFormatter->format($created, 'custom', $settings['comment_date']['format'] ?: self::DEFAULT_DATE_FORMAT),
      'datetime' => gmdate(DATE_W3C, $created),
      'permalink' => $permalink,
      'body' => Cacheable::allowed($body->access('view', $this->currentUser, TRUE), $metadata) ? [
        '#type' => 'processed_text',
        '#text' => $body->value,
        '#format' => $body->format,
      ] : [],
      'langcode' => $comment->language()->getId(),
      'children' => [],
    ];
  }

  /**
   * Finds the children stored on one dynamic layout marker.
   */
  public function markerChildren(array $nodes, string $marker): array {
    foreach ($nodes as $node) {
      if (($node['marker'] ?? NULL) === $marker) {
        return $node['children'] ?? [];
      }
      $children = $this->markerChildren($node['children'] ?? [], $marker);
      if ($children !== []) {
        return $children;
      }
    }
    return [];
  }

  /**
   * Adds the repeated child layout to every threaded comment.
   */
  private function addContent(array &$comments, array $layout, array $settings): void {
    foreach ($comments as &$comment) {
      $comment['content'] = LayoutRenderer::build($layout, [
        'avatar' => $this->fragment('wordpal_comment_avatar', ['comment' => $comment, 'settings' => $settings['avatar']]),
        'comment-author-name' => $this->fragment('wordpal_comment_author_name', [
          'comment' => $comment,
          'settings' => $settings['comment_author_name'],
        ]),
        'comment-date' => $this->fragment('wordpal_comment_date', [
          'comment' => $comment,
          'settings' => $settings['comment_date'],
        ]),
        'comment-content' => $this->fragment('wordpal_comment_content', [
          'comment' => $comment,
          'settings' => $settings['comment_content'],
        ]),
        'comment-edit-link' => $this->action($comment, 'edit', $settings['comment_edit_link']),
        'comment-reply-link' => $this->action($comment, 'reply', $settings['comment_reply_link']),
      ]);
      $this->addContent($comment['children'], $layout, $settings);
    }
  }

  /**
   * Builds one lazy access-checked comment action.
   *
   * A #lazy_builder callback's arguments must be scalar, so $settings
   * travels as a JSON string.
   */
  private function action(array $comment, string $action, array $settings): array {
    return [
      '#lazy_builder' => [
        'Drupal\wordpal\Comment\CommentRuntime:renderActionLinks',
        [$comment['id'], $comment['langcode'], $action, Json::encode($settings)],
      ],
      '#create_placeholder' => TRUE,
    ];
  }

}
