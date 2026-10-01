<?php

declare(strict_types=1);

namespace Drupal\wordpal\Listing;

use Drupal\comment\CommentInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\text\TextSummary;
use Drupal\wordpal\Support\Cacheable;

/**
 * Builds the row array each listing View kind's template reads.
 *
 * A listing View shares one row builder for every render of its kind.
 */
final class ListingRows {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly TextSummary $textSummary,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns one latest-posts row for the listing View template.
   */
  public function latestPost(NodeInterface $node, array $settings, CacheableMetadata $cacheability): array {
    $url = Cacheable::url($node->toUrl(), $cacheability);
    $cacheability->addCacheableDependency($node->getOwner());
    return [
      'title' => $node->label(),
      'url' => $url,
      'created' => (int) $node->getCreatedTime(),
      'author' => $node->getOwner()->getDisplayName(),
      'excerpt' => $settings['display_post_content'] ? $this->excerpt($node, $settings, $cacheability) : NULL,
      'image' => $settings['display_featured_image'] ? $this->image($node, $settings, $cacheability) : NULL,
    ];
  }

  /**
   * Returns a post's excerpt, cut to the excerpt length in words.
   *
   * @return array{text: string, trimmed: bool}
   *   The excerpt text, and whether words were cut from it.
   */
  private function excerpt(NodeInterface $node, array $settings, CacheableMetadata $cacheability): array {
    $field = $settings['excerpt_field'];
    if ($field === '') {
      return ['text' => '', 'trimmed' => FALSE];
    }
    $text = Cacheable::allowed($node->get($field)->access('view', NULL, TRUE), $cacheability)
      ? trim(strip_tags((string) $node->get($field)->value))
      : '';
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) <= $settings['excerpt_length']) {
      return ['text' => $text, 'trimmed' => FALSE];
    }
    return ['text' => implode(' ', array_slice($words, 0, $settings['excerpt_length'])), 'trimmed' => TRUE];
  }

  /**
   * Returns a post's featured image, or NULL when it has none to show.
   *
   * The mapped field holds an image, or a media item whose source is one.
   *
   * @return array{src: string, width: int|null, height: int|null, alt: string}|null
   *   The image the list shows.
   */
  private function image(NodeInterface $node, array $settings, CacheableMetadata $cacheability): ?array {
    $field = $settings['image_field'];
    if ($field === '') {
      return NULL;
    }
    $item = Cacheable::allowed($node->get($field)->access('view', NULL, TRUE), $cacheability)
      ? $node->get($field)->first()
      : NULL;
    if ($item !== NULL && $item->entity instanceof MediaInterface) {
      $media = $item->entity;
      $cacheability->addCacheableDependency($media);
      $source = $media->get($media->getSource()->getConfiguration()['source_field']);
      $item = Cacheable::allowed($media->access('view', NULL, TRUE)->andIf($source->access('view', NULL, TRUE)), $cacheability)
        ? $source->first()
        : NULL;
    }
    $file = $item?->entity;
    if (!$file instanceof FileInterface || !Cacheable::allowed($file->access('view', NULL, TRUE), $cacheability)) {
      return NULL;
    }
    $cacheability->addCacheableDependency($file);
    return [
      'src' => $file->createFileUrl(),
      'width' => $item->width === NULL ? NULL : (int) $item->width,
      'height' => $item->height === NULL ? NULL : (int) $item->height,
      'alt' => (string) $item->alt,
    ];
  }

  /**
   * Returns one page-list row for the listing View template.
   */
  public function page(NodeInterface $node, array $settings, CacheableMetadata $cacheability): array {
    $current = $this->routeMatch->getParameter('node');
    $currentId = $current instanceof NodeInterface ? $current->id() : NULL;
    return [
      'title' => $node->label(),
      'url' => Cacheable::url($node->toUrl(), $cacheability),
      'current' => $currentId !== NULL && (string) $node->id() === (string) $currentId,
    ];
  }

  /**
   * Returns one latest-comments row, or NULL when the viewer may not see it.
   *
   * The View's comment_field_data access filter covers published comment
   * and node status only; a comment on a node the viewer cannot otherwise
   * access still needs its own check.
   */
  public function comment(CommentInterface $comment, array $settings, CacheableMetadata $cacheability): ?array {
    $cacheability->addCacheableDependency($comment);
    if (!Cacheable::allowed($comment->access('view', NULL, TRUE), $cacheability)) {
      return NULL;
    }
    $host = $comment->getCommentedEntity();
    $cacheability->addCacheableDependency($host);
    $link = Cacheable::url($comment->permalink(), $cacheability);
    $owner = $comment->getOwner();
    // As core's username theme: an anonymous author links to their homepage,
    // a registered one to their account.
    $homepage = $comment->getHomepage();
    $authorUrl = $owner->isAnonymous()
      ? ($homepage ? UrlHelper::stripDangerousProtocols($homepage) : NULL)
      : Cacheable::link($owner, $cacheability, 'view linked label');
    $body = $comment->get('comment_body');
    $bodyVisible = Cacheable::allowed($body->access('view', NULL, TRUE), $cacheability);
    $text = $bodyVisible ? trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $body->value))) : '';
    return [
      'author' => $comment->getAuthorName(),
      'author_url' => $authorUrl,
      'avatar' => Cacheable::picture($owner, $cacheability)?->createFileUrl(),
      'post_title' => $host->label() ?: (string) new TranslatableMarkup('(no title)'),
      'link' => $link,
      'created' => (int) $comment->getCreatedTime(),
      'excerpt' => $this->summary($text, $cacheability),
      'body' => $bodyVisible ? [
        '#type' => 'processed_text',
        '#text' => $body->value,
        '#format' => $body->format,
      ] : [],
    ];
  }

  /**
   * Returns the text's summary at the site's default summary length.
   */
  private function summary(string $text, CacheableMetadata $cacheability): string {
    $settings = $this->configFactory->get('text.settings');
    $cacheability->addCacheableDependency($settings);
    return (string) $this->textSummary->generate($text, NULL, (int) $settings->get('default_summary_length'));
  }

}
