<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

use Drupal\wordpal_convert\Support\StableUuid;
use Drupal\wordpal_convert\Theme\TemplateResolver;

/**
 * Identifies the page a theme's home or 404 Template converts to.
 */
final class TemplatePage {

  /**
   * The routes whose Template converts to a page.
   */
  public const ROUTES = ['home', 'not_found'];

  /**
   * Returns the stable UUID of a theme's page for one route.
   */
  public static function uuid(string $themeId, string $route): string {
    return StableUuid::fromName("page/$themeId/$route");
  }

  /**
   * Returns the path alias a theme's page for one route is created under.
   */
  public static function alias(string $themeId, string $route): string {
    return '/wordpal-' . str_replace('_', '-', $themeId) . '-' . TemplateResolver::templateName($route);
  }

  /**
   * Returns the description a route's page carries.
   */
  public static function description(string $route): string {
    return 'The ' . TemplateResolver::templateName($route) . ' Template converted from WordPress.';
  }

}
