<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * Picks the Template WordPress renders for each converted Drupal route.
 */
final class TemplateResolver {

  /**
   * WordPress's Template hierarchy for each route, most specific first.
   *
   * Slug- and ID-specific Templates are left out: each route converts once,
   * for every page it serves. The tag and category routes serve the term
   * pages of the mapped tag and category vocabularies, and the archive
   * route the term pages of every other vocabulary, which WordPress calls
   * custom taxonomies.
   */
  public const HIERARCHY = [
    'home' => ['front-page', 'home', 'index'],
    'single' => ['single-post', 'single', 'singular', 'index'],
    'page' => ['page', 'singular', 'index'],
    'tag' => ['tag', 'archive', 'index'],
    'category' => ['category', 'archive', 'index'],
    'archive' => ['taxonomy', 'archive', 'index'],
    'search' => ['search', 'index'],
    'not_found' => ['404', 'index'],
  ];

  /**
   * Returns the name a route's own Template file carries.
   *
   * The 404 route is keyed `not_found`, since PHP turns a '404' array key
   * into an integer.
   */
  public static function templateName(string $route): string {
    return $route === 'not_found' ? '404' : $route;
  }

  /**
   * Returns the Template name used for each route.
   *
   * @param string[] $names
   *   The theme's Template names.
   *
   * @return array<string, string>
   *   Template names keyed by route. A route none of whose candidates the
   *   theme has is left out.
   */
  public static function resolve(array $names): array {
    $available = array_flip($names);
    $resolved = [];
    foreach (self::HIERARCHY as $route => $candidates) {
      foreach ($candidates as $candidate) {
        if (isset($available[$candidate])) {
          $resolved[$route] = $candidate;
          break;
        }
      }
    }
    return $resolved;
  }

}
