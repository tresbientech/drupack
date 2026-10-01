<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Content;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\comment\CommentInterface;
use Drupal\comment\CommentingStatus;
use Drupal\filter\FilterFormatRepositoryInterface;
use Drupal\trash\TrashManagerInterface;
use Drupal\wordpal_convert\Support\StableUuid;

/**
 * Seeds Demo content into an empty post bundle through a Content mapping.
 */
final class DemoContentSeeder {

  /**
   * The Demo content fixture, also the Reference render's WordPress content.
   */
  public const FIXTURE = __DIR__ . '/../../content/demo-content.json';

  /**
   * Seeded entity types, each listed before the types its entities reference.
   */
  public const ENTITY_TYPES = [
    'comment', 'menu_link_content', 'node', 'media', 'file', 'taxonomy_term', 'user',
  ];

  public function __construct(
    private readonly ContentFixtureLoader $fixtureLoader,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly PathValidatorInterface $pathValidator,
    private readonly MenuLinkTreeInterface $menuTree,
    private readonly ?TrashManagerInterface $trashManager,
    private readonly Connection $database,
    private readonly FilterFormatRepositoryInterface $formatRepository,
  ) {}

  /**
   * Returns the directory holding one theme's Demo content files.
   */
  public static function directory(string $themeId): string {
    return "public://wordpal-demo/$themeId";
  }

  /**
   * Seeds one theme's Demo content when the mapped post bundle has no nodes.
   *
   * @return array{uuids: array<string, string[]>, skipped: string[]}|null
   *   UUIDs of the created entities keyed by self::ENTITY_TYPES in that order,
   *   and the menu items left out with why, or NULL when the mapping drops
   *   posts or the post bundle has nodes.
   */
  public function seed(ContentMapping $mapping, string $themeId): ?array {
    if (!$mapping->binds('post') || $this->hasPosts($mapping->target('post'))) {
      return NULL;
    }
    $fixture = $this->fixtureLoader->load(self::FIXTURE);
    // A partial seed would leave stable UUIDs that no manifest records.
    $transaction = $this->database->startTransaction();
    try {
      return $this->createAll($mapping, $themeId, $fixture);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Creates one theme's Demo content from the fixture.
   *
   * @return array{uuids: array<string, string[]>, skipped: string[]}
   *   As seed() returns it.
   */
  private function createAll(ContentMapping $mapping, string $themeId, array $fixture): array {
    $imageDirectory = self::directory($themeId);
    if (!$this->fileSystem->prepareDirectory($imageDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException("Demo content image directory cannot be prepared: $imageDirectory.");
    }
    $user = $this->create('user', $themeId, 'user/' . $fixture['author']['username'], [
      'name' => $fixture['author']['display_name'],
      'mail' => $fixture['author']['username'] . '@example.com',
      'status' => 1,
      ...($mapping->binds('author_biography')
        ? [$mapping->target('author_biography') => $fixture['author']['biography']]
        : []),
    ]);
    $category = $mapping->binds('category')
      ? $this->term($themeId, $mapping->target('category')['vocabulary'], $fixture['post']['category'])
      : NULL;
    $tags = $mapping->binds('tag')
      ? array_map(fn(string $name): object => $this->term($themeId, $mapping->target('tag')['vocabulary'], $name), $fixture['post']['tags'])
      : [];
    $image = $fixture['post']['featured_image'];
    $file = NULL;
    $media = NULL;
    $values = [];
    if ($mapping->binds('featured_image')) {
      $file = $this->create('file', $themeId, 'file/' . $image['filename'], [
        'uri' => $this->fileSystem->saveData(base64_decode($image['base64'], TRUE), $imageDirectory . '/' . $image['filename'], FileExists::Replace),
        'filename' => $image['filename'],
        'filemime' => $image['mime_type'],
        'uid' => $user->id(),
        'status' => 1,
      ]);
      [$values[$mapping->target('featured_image')], $media] = $this->imageTarget($themeId, $mapping, $file, $image);
    }

    $bundle = $mapping->target('post');
    $body = $mapping->binds('post_body') ? $mapping->target('post_body') : NULL;
    $format = $body !== NULL ? $this->bodyFormat('node', $bundle, $body, $user) : NULL;
    $common = [
      'type' => $bundle,
      'uid' => $user->id(),
      'status' => 1,
      ...($category !== NULL ? [$mapping->target('category')['field'] => [['target_id' => $category->id()]]] : []),
      ...($body !== NULL ? [$body => ['value' => '', 'format' => $format]] : []),
    ];
    if ($body !== NULL) {
      $values[$body] = ['value' => $fixture['post']['content'], 'format' => $format];
    }
    if ($mapping->binds('excerpt')) {
      $values[$mapping->target('excerpt')] = $fixture['post']['excerpt'];
    }
    if ($tags !== []) {
      $values[$mapping->target('tag')['field']] = array_map(static fn(object $term): array => ['target_id' => $term->id()], $tags);
    }
    if ($mapping->binds('comments')) {
      $values[$mapping->target('comments')] = ['status' => CommentingStatus::Open->value];
    }
    $posts = [
      $this->post($themeId, $common, $fixture['neighbors']['previous']),
      $this->post($themeId, $common, $fixture['neighbors']['next']),
      $current = $this->post($themeId, $common, $fixture['post'], $values),
      ...array_map(fn(array $post): object => $this->post($themeId, $common, $post), $fixture['older_posts']),
    ];
    $page = $mapping->binds('page') ? $this->page($themeId, $mapping, $fixture['page'], $user) : NULL;
    if ($page !== NULL) {
      $posts[] = $page;
    }
    $skipped = [];
    $seeded = [
      'comment' => $mapping->binds('comments')
        ? $this->comments($themeId, $current, (string) $mapping->target('comments'), $fixture['post']['slug'], $fixture['comments'], $user)
        : [],
      'menu_link_content' => $mapping->binds('navigation')
        ? $this->menuLinks($themeId, (string) $mapping->target('navigation'), $fixture['navigation']['items'], $page, $skipped)
        : [],
      'node' => $posts,
      'media' => $media !== NULL ? [$media] : [],
      'file' => $file !== NULL ? [$file] : [],
      'taxonomy_term' => [...($category !== NULL ? [$category] : []), ...$tags],
      'user' => [$user],
    ];
    return [
      'uuids' => array_map(
        static fn(array $entities): array => array_map(static fn(object $entity): string => $entity->uuid(), $entities),
        $seeded,
      ),
      'skipped' => $skipped,
    ];
  }

  /**
   * Returns whether a node bundle holds any node, unpublished or trashed.
   */
  private function hasPosts(string $bundle): bool {
    $count = fn(): int => (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->range(0, 1)
      ->count()
      ->execute();
    // Trash hides soft-deleted nodes from queries outside its ignore context.
    return (bool) ($this->trashManager === NULL ? $count() : $this->trashManager->executeInTrashContext('ignore', $count));
  }

  /**
   * Creates the threaded Demo comments on one post.
   *
   * @return \Drupal\comment\CommentInterface[]
   *   The comments, parents first.
   */
  private function comments(string $themeId, object $post, string $fieldName, string $postSlug, array $fixtures, AccountInterface $user): array {
    $commentType = $post->get($fieldName)->getFieldDefinition()->getSetting('comment_type');
    $format = $this->bodyFormat('comment', $commentType, 'comment_body', $user);
    $comments = [];
    foreach ($fixtures as $fixture) {
      $comments[$fixture['id']] = $this->create('comment', $themeId, "comment/$postSlug/{$fixture['id']}", [
        'comment_type' => $commentType,
        'entity_type' => 'node',
        'entity_id' => $post->id(),
        'field_name' => $fieldName,
        // The post author's own reply is a registered user's comment.
        'uid' => $fixture['author'] === $user->getAccountName() ? $user->id() : 0,
        'name' => $fixture['author'],
        'mail' => $fixture['email'],
        'status' => CommentInterface::PUBLISHED,
        'created' => strtotime($fixture['date']),
        'changed' => strtotime($fixture['date']),
        'pid' => $fixture['parent'] === NULL ? NULL : $comments[$fixture['parent']]->id(),
        'comment_body' => ['value' => $fixture['body'], 'format' => $format],
      ]);
    }
    return array_values($comments);
  }

  /**
   * Creates the Demo links the mapped menu does not already hold.
   *
   * @param string $themeId
   *   The converted theme.
   * @param string $menuId
   *   The mapped menu.
   * @param array[] $items
   *   The fixture's navigation items.
   * @param object|null $page
   *   The Demo page, or NULL when the mapping drops pages.
   * @param string[] $skipped
   *   Collects each item left out, with why.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   The links, in menu order.
   */
  private function menuLinks(string $themeId, string $menuId, array $items, ?object $page, array &$skipped): array {
    $existing = $this->menuLinkKeys($this->menuTree->load($menuId, new MenuTreeParameters()));
    $links = [];
    foreach ($items as $weight => $item) {
      $target = $item['path'] ?? 'the Demo page';
      if (isset($item['target']) && $page === NULL) {
        $skipped[] = "menu link {$item['title']} ($target): the Content mapping drops page";
        continue;
      }
      if (isset($item['path']) && $this->pathValidator->getUrlIfValidWithoutAccessCheck($item['path']) === FALSE) {
        $skipped[] = "menu link {$item['title']} ($target): the path does not resolve in Drupal";
        continue;
      }
      $uri = isset($item['target']) ? 'entity:node/' . $page->id() : 'internal:' . $item['path'];
      if (isset($existing['title'][$item['title']]) || isset($existing['path'][Url::fromUri($uri)->toString()])) {
        $skipped[] = "menu link {$item['title']} ($target): menu $menuId already links it";
        continue;
      }
      $links[] = $this->create('menu_link_content', $themeId, "menu-link/$menuId/" . ($item['path'] ?? $item['target']), [
        'menu_name' => $menuId,
        'title' => $item['title'],
        'link' => ['uri' => $uri],
        'enabled' => TRUE,
        'weight' => $weight,
      ]);
    }
    return $links;
  }

  /**
   * Returns the titles and paths of every link in a menu tree.
   *
   * A title counts as linked too, since a second link under the same title
   * reads as a duplicate in the menu.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   *   The menu tree.
   *
   * @return array{title: array<string, true>, path: array<string, true>}
   *   Titles and generated paths as keys.
   */
  private function menuLinkKeys(array $tree): array {
    $keys = ['title' => [], 'path' => []];
    foreach ($tree as $element) {
      $keys['title'][(string) $element->link->getTitle()] = TRUE;
      $keys['path'][$element->link->getUrlObject()->toString()] = TRUE;
      $children = $this->menuLinkKeys($element->subtree);
      $keys['title'] += $children['title'];
      $keys['path'] += $children['path'];
    }
    return $keys;
  }

  /**
   * Returns the mapped image or media reference value and any media created.
   *
   * @return array{0: array, 1: \Drupal\media\MediaInterface|null}
   *   The field value and the media entity it references, if any.
   */
  private function imageTarget(string $themeId, ContentMapping $mapping, object $file, array $image): array {
    $field = $this->fieldManager->getFieldDefinitions('node', $mapping->target('post'))[$mapping->target('featured_image')];
    if ($field->getType() === 'image') {
      return [['target_id' => $file->id(), 'alt' => $image['alt']], NULL];
    }
    [$mediaBundle, $sourceField] = MediaSourceField::resolve($field, $this->entityTypeManager);
    $media = $this->create('media', $themeId, 'media/' . $image['filename'], [
      'bundle' => $mediaBundle,
      'name' => $image['alt'],
      'status' => 1,
      $sourceField => ['target_id' => $file->id(), 'alt' => $image['alt']],
    ]);
    return [['target_id' => $media->id()], $media];
  }

  /**
   * Creates one Demo term.
   */
  private function term(string $themeId, string $vocabulary, string $name): object {
    return $this->create('taxonomy_term', $themeId, "term/$vocabulary/$name", ['vid' => $vocabulary, 'name' => $name]);
  }

  /**
   * Returns the text format the body field accepts for the Demo author.
   */
  private function bodyFormat(string $entityType, string $bundle, string $fieldName, AccountInterface $user): string {
    $allowed = $this->fieldManager->getFieldDefinitions($entityType, $bundle)[$fieldName]->getSetting('allowed_formats');
    return $allowed !== [] ? reset($allowed) : $this->formatRepository->getDefaultFormat($user)->id();
  }

  /**
   * Creates one Demo post.
   */
  private function post(string $themeId, array $common, array $fixture, array $values = []): object {
    return $this->create('node', $themeId, 'post/' . $fixture['slug'], array_replace($common, [
      'title' => $fixture['title'],
      'created' => strtotime($fixture['date']),
    ], $values));
  }

  /**
   * Creates the Demo page in the mapped page bundle.
   */
  private function page(string $themeId, ContentMapping $mapping, array $fixture, AccountInterface $user): object {
    $bundle = $mapping->target('page');
    $values = ['type' => $bundle, 'uid' => $user->id(), 'status' => 1, 'title' => $fixture['title']];
    if ($mapping->binds('page_body')) {
      $body = $mapping->target('page_body');
      $values[$body] = ['value' => $fixture['content'], 'format' => $this->bodyFormat('node', $bundle, $body, $user)];
    }
    if ($mapping->binds('page_excerpt')) {
      $values[$mapping->target('page_excerpt')] = $fixture['excerpt'];
    }
    return $this->create('node', $themeId, 'page/' . $fixture['slug'], $values);
  }

  /**
   * Creates one Demo entity under a UUID stable per theme and fixture name.
   */
  private function create(string $entityType, string $themeId, string $name, array $values): object {
    $entity = $this->entityTypeManager->getStorage($entityType)->create([
      'uuid' => StableUuid::fromName("demo/$themeId/$name"),
    ] + $values);
    // Content moderation sets the published status from the moderation
    // state, and a moderated bundle starts in its workflow's draft state.
    if ($entity instanceof FieldableEntityInterface && $entity->hasField('moderation_state') && ($values['status'] ?? NULL) === 1) {
      $entity->set('moderation_state', 'published');
    }
    $entity->save();
    return $entity;
  }

}
