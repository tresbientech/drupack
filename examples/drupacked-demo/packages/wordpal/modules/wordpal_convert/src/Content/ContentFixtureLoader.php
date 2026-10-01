<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Content;

/**
 * Validates the shared fixture at its external JSON boundary.
 */
final class ContentFixtureLoader {

  /**
   * Returns validated fixture data.
   */
  public function load(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
      throw new \InvalidArgumentException("Content fixture is not readable: $path.");
    }
    $data = json_decode((string) file_get_contents($path), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
      throw new \InvalidArgumentException('Content fixture must contain a JSON object.');
    }
    $errors = $this->errors($data);
    if ($errors !== []) {
      throw new \InvalidArgumentException("Invalid content fixture:\n- " . implode("\n- ", $errors));
    }
    return $data;
  }

  /**
   * Returns all structural fixture errors.
   */
  private function errors(array $data): array {
    $errors = [];
    foreach ([
      'author.username', 'author.display_name', 'author.biography',
      'post.title', 'post.slug', 'post.date', 'post.excerpt', 'post.content',
      'post.category', 'post.featured_image.filename',
      'page.title', 'page.slug', 'page.excerpt', 'page.content',
      'post.featured_image.mime_type', 'post.featured_image.alt',
      'post.featured_image.base64', 'neighbors.previous.title',
      'neighbors.previous.slug', 'neighbors.previous.date',
      'neighbors.next.title', 'neighbors.next.slug', 'neighbors.next.date',
      'navigation.name',
      'home_pattern',
    ] as $path) {
      if (!$this->nonEmptyPath($data, $path)) {
        $errors[] = "$path must be a non-empty string.";
      }
    }
    if ($this->nonEmptyPath($data, 'home_pattern') && !preg_match('#^[a-z0-9_-]+/[a-z0-9_-]+\z#', $data['home_pattern'])) {
      $errors[] = 'home_pattern must be a WordPress theme pattern slug.';
    }
    if (!isset($data['post']['tags']) || !is_array($data['post']['tags']) || $data['post']['tags'] === []) {
      $errors[] = 'post.tags must be a non-empty list.';
    }
    elseif (array_filter($data['post']['tags'], fn(mixed $tag): bool => !$this->nonEmptyString($tag)) !== []) {
      $errors[] = 'Every post.tags value must be a non-empty string.';
    }
    $navigationItems = $data['navigation']['items'] ?? NULL;
    if (!is_array($navigationItems) || !array_is_list($navigationItems) || $navigationItems === []) {
      $errors[] = 'navigation.items must be a non-empty list.';
    }
    else {
      foreach ($navigationItems as $index => $item) {
        if (!is_array($item) || !$this->nonEmptyString($item['title'] ?? NULL)) {
          $errors[] = "navigation.items.$index.title must be a non-empty string.";
        }
        // An item links the fixture page or a root-relative path.
        if (is_array($item) && array_key_exists('target', $item)) {
          if ($item['target'] !== 'page' || array_key_exists('path', $item)) {
            $errors[] = "navigation.items.$index.target must be \"page\", without a path.";
          }
          continue;
        }
        $path = is_array($item) ? ($item['path'] ?? NULL) : NULL;
        if (!is_string($path) || !preg_match('#^/(?!/)[^\x00-\x20]*\z#', $path) || str_contains($path, '\\')) {
          $errors[] = "navigation.items.$index.path must be a root-relative path.";
        }
      }
    }
    $errors = [...$errors, ...$this->imageErrors($data['post']['featured_image'] ?? [])];
    $errors = [...$errors, ...$this->commentErrors($data['comments'] ?? NULL)];
    foreach (['post.date', 'neighbors.previous.date', 'neighbors.next.date'] as $path) {
      if ($this->nonEmptyPath($data, $path) && strtotime($this->path($data, $path)) === FALSE) {
        $errors[] = "$path must be a valid date.";
      }
    }
    $olderPosts = $data['older_posts'] ?? NULL;
    if (!is_array($olderPosts) || !array_is_list($olderPosts) || $olderPosts === []) {
      $errors[] = 'older_posts must be a non-empty list.';
    }
    else {
      foreach ($olderPosts as $index => $post) {
        foreach (['title', 'slug', 'date'] as $field) {
          if (!is_array($post) || !$this->nonEmptyString($post[$field] ?? NULL)) {
            $errors[] = "older_posts.$index.$field must be a non-empty string.";
          }
        }
        if (is_array($post) && $this->nonEmptyString($post['date'] ?? NULL) && strtotime($post['date']) === FALSE) {
          $errors[] = "older_posts.$index.date must be a valid date.";
        }
      }
    }
    return $errors;
  }

  /**
   * Validates deterministic comments and their parent references.
   */
  private function commentErrors(mixed $comments): array {
    if (!is_array($comments) || !array_is_list($comments) || $comments === []) {
      return ['comments must be a non-empty list.'];
    }
    $errors = [];
    $ids = [];
    foreach ($comments as $index => $comment) {
      foreach (['id', 'author', 'email', 'body', 'date'] as $field) {
        if (!is_array($comment) || !$this->nonEmptyString($comment[$field] ?? NULL)) {
          $errors[] = "comments.$index.$field must be a non-empty string.";
        }
      }
      if (!is_array($comment)) {
        continue;
      }
      $id = $comment['id'] ?? NULL;
      if (is_string($id) && isset($ids[$id])) {
        $errors[] = "comments.$index.id must be unique.";
      }
      if ($this->nonEmptyString($comment['email'] ?? NULL) && filter_var($comment['email'], FILTER_VALIDATE_EMAIL) === FALSE) {
        $errors[] = "comments.$index.email must be a valid email address.";
      }
      if ($this->nonEmptyString($comment['date'] ?? NULL) && strtotime($comment['date']) === FALSE) {
        $errors[] = "comments.$index.date must be a valid date.";
      }
      if (!array_key_exists('parent', $comment)) {
        $errors[] = "comments.$index.parent must be present.";
      }
      $parent = $comment['parent'] ?? NULL;
      if ($parent !== NULL && (!is_string($parent) || !isset($ids[$parent]))) {
        $errors[] = "comments.$index.parent must reference an earlier comment.";
      }
      if (is_string($id)) {
        $ids[$id] = TRUE;
      }
    }
    return $errors;
  }

  /**
   * Validates the fixture image path, declared type, and decoded bytes.
   */
  private function imageErrors(array $image): array {
    $errors = [];
    $filename = $image['filename'] ?? NULL;
    $mime = $image['mime_type'] ?? NULL;
    $types = [
      'png' => 'image/png',
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'gif' => 'image/gif',
      'webp' => 'image/webp',
    ];
    $extension = is_string($filename) ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';
    if (!is_string($filename) || basename($filename) !== $filename || str_contains($filename, '\\') || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|gif|webp)\z/i', $filename)) {
      $errors[] = 'post.featured_image.filename must be a safe image basename.';
    }
    if (!isset($types[$extension]) || $mime !== $types[$extension]) {
      $errors[] = 'post.featured_image MIME type must match its image extension.';
    }
    $bytes = isset($image['base64']) && is_string($image['base64']) ? base64_decode($image['base64'], TRUE) : FALSE;
    if ($bytes === FALSE) {
      $errors[] = 'post.featured_image.base64 must be valid base64.';
    }
    elseif (($details = @getimagesizefromstring($bytes)) === FALSE || ($details['mime'] ?? NULL) !== $mime) {
      $errors[] = 'post.featured_image bytes must match its declared image MIME type.';
    }
    return $errors;
  }

  /**
   * Returns whether a nested path holds a non-empty string.
   */
  private function nonEmptyPath(array $data, string $path): bool {
    return $this->nonEmptyString($this->path($data, $path));
  }

  /**
   * Returns a dot-separated nested value.
   */
  private function path(array $data, string $path): mixed {
    $value = $data;
    foreach (explode('.', $path) as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) {
        return NULL;
      }
      $value = $value[$key];
    }
    return $value;
  }

  /**
   * Returns whether a value is a non-empty string.
   */
  private function nonEmptyString(mixed $value): bool {
    return is_string($value) && trim($value) !== '';
  }

}
