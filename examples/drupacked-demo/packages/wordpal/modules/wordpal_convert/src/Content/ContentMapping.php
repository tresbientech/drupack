<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Content;

/**
 * Holds validated Drupal targets for WordPress content concepts.
 */
final class ContentMapping {

  public const CONCEPTS = [
    'post',
    'post_body',
    'page',
    'page_body',
    'page_excerpt',
    'category',
    'tag',
    'featured_image',
    'excerpt',
    'author_biography',
    'comments',
    'navigation',
    'search',
  ];

  /**
   * The Drupal feature a site builder adds to map each droppable concept.
   */
  public const PROVIDERS = [
    'category' => 'a core Taxonomy vocabulary',
    'author_biography' => 'a user text field',
    'comments' => 'a comment field on the post bundle',
  ];

  /**
   * Counts of blocks dropped by concept.
   *
   * @var array<string, int>
   */
  private array $droppedBlocks = [];

  /**
   * The generated theme a conversion writes, which scopes its own menus.
   */
  private string $themeId;

  public function __construct(private readonly array $targets) {}

  /**
   * Sets the generated theme the conversion writes.
   */
  public function setThemeId(string $themeId): void {
    $this->themeId = $themeId;
  }

  /**
   * Returns the generated theme the conversion writes.
   */
  public function themeId(): string {
    return $this->themeId;
  }

  /**
   * Returns the target for a content concept.
   */
  public function target(string $concept): string|array|null {
    return $this->targets[$concept];
  }

  /**
   * Returns whether a concept was supplied for this conversion.
   */
  public function has(string $concept): bool {
    return array_key_exists($concept, $this->targets);
  }

  /**
   * Returns whether blocks bound to a concept must be dropped.
   */
  public function drops(string $concept): bool {
    return $this->target($concept) === NULL;
  }

  /**
   * Returns whether a concept was supplied and points to a real target.
   */
  public function binds(string $concept): bool {
    return $this->has($concept) && !$this->drops($concept);
  }

  /**
   * Returns the provider of each concept this mapping sets to null.
   *
   * @return array<string, string>
   *   Provider descriptions keyed by content concept.
   */
  public function providers(): array {
    $providers = [];
    foreach (self::PROVIDERS as $concept => $provider) {
      if ($this->has($concept) && $this->drops($concept)) {
        $providers[$concept] = $provider;
      }
    }
    return $providers;
  }

  /**
   * Records blocks removed because their concept has no target.
   */
  public function recordDroppedBlocks(string $concept, int $count = 1): void {
    $this->droppedBlocks[$concept] = ($this->droppedBlocks[$concept] ?? 0) + $count;
  }

  /**
   * Returns dropped block counts by content concept.
   */
  public function droppedBlocks(): array {
    return $this->droppedBlocks;
  }

}
