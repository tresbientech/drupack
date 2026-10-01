<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Content;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads and validates a Content mapping at the external file boundary.
 */
final class ContentMappingLoader {

  /**
   * The default mapping, which targets the Drupal CMS content model.
   */
  private const DRUPAL_CMS = [
    'post' => 'blog',
    'post_body' => 'field_content',
    'page' => 'page',
    'page_body' => 'field_content',
    'page_excerpt' => 'field_description',
    'category' => NULL,
    'tag' => ['vocabulary' => 'tags', 'field' => 'field_tags'],
    'featured_image' => 'field_featured_image',
    'excerpt' => 'field_description',
    'author_biography' => NULL,
    'comments' => NULL,
    'navigation' => 'main',
    'search' => ['path' => '/search', 'parameter' => 'keywords', 'index' => 'content'],
  ];

  /**
   * Returns the default mapping, or the override file at a path.
   *
   * An override replaces the default whole.
   */
  public function resolve(?string $path, array $requiredConcepts = ContentMapping::CONCEPTS): ContentMapping {
    return $path === NULL ? new ContentMapping(self::DRUPAL_CMS) : $this->load($path, $requiredConcepts);
  }

  /**
   * Loads a complete Content mapping from YAML.
   */
  public function load(string $path, array $requiredConcepts = ContentMapping::CONCEPTS): ContentMapping {
    if (!is_file($path) || !is_readable($path)) {
      throw new \InvalidArgumentException("Content mapping file is not readable: $path.");
    }
    $targets = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
    if (!is_array($targets)) {
      throw new \InvalidArgumentException('Content mapping must be a YAML mapping.');
    }

    $errors = $this->errors($targets, $requiredConcepts);
    if ($errors !== []) {
      throw new \InvalidArgumentException("Invalid Content mapping:\n- " . implode("\n- ", $errors));
    }
    return new ContentMapping($targets);
  }

  /**
   * Returns every structural error in a Content mapping.
   */
  private function errors(array $targets, array $requiredConcepts): array {
    $errors = [];
    $missing = array_values(array_diff($requiredConcepts, array_keys($targets)));
    if ($missing !== []) {
      $errors[] = 'Missing concepts: ' . implode(', ', $missing) . '.';
    }
    $unknown = array_values(array_diff(array_keys($targets), ContentMapping::CONCEPTS));
    if ($unknown !== []) {
      $errors[] = 'Unknown concepts: ' . implode(', ', $unknown) . '.';
    }

    $stringConcepts = [
      'post', 'post_body', 'page', 'page_body', 'page_excerpt', 'featured_image', 'excerpt',
      'author_biography', 'comments', 'navigation',
    ];
    foreach ($stringConcepts as $concept) {
      if (array_key_exists($concept, $targets) && $targets[$concept] !== NULL && !$this->nonEmptyString($targets[$concept])) {
        $errors[] = "$concept must be a non-empty string or null.";
      }
    }
    foreach (['category', 'tag'] as $concept) {
      if (array_key_exists($concept, $targets) && $targets[$concept] !== NULL) {
        $errors = [...$errors, ...$this->objectErrors($concept, $targets[$concept], ['vocabulary', 'field'])];
      }
    }
    if (array_key_exists('search', $targets) && $targets['search'] !== NULL) {
      $errors = [...$errors, ...$this->objectErrors('search', $targets['search'], ['path', 'parameter', 'index'])];
      if (is_array($targets['search']) && isset($targets['search']['path']) && is_string($targets['search']['path']) && !str_starts_with($targets['search']['path'], '/')) {
        $errors[] = 'search.path must start with a slash.';
      }
    }
    return $errors;
  }

  /**
   * Validates one fixed-shape nested mapping.
   */
  private function objectErrors(string $concept, mixed $value, array $keys): array {
    if (!is_array($value)) {
      return ["$concept must be a mapping with " . implode(' and ', $keys) . '.'];
    }
    $errors = [];
    foreach ($keys as $key) {
      if (!array_key_exists($key, $value) || !$this->nonEmptyString($value[$key])) {
        $errors[] = "$concept.$key must be a non-empty string.";
      }
    }
    $unknown = array_values(array_diff(array_keys($value), $keys));
    if ($unknown !== []) {
      $errors[] = "$concept has unknown keys: " . implode(', ', $unknown) . '.';
    }
    return $errors;
  }

  /**
   * Returns whether a value is a non-empty string.
   */
  private function nonEmptyString(mixed $value): bool {
    return is_string($value) && trim($value) !== '';
  }

}
