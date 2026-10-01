<?php

declare(strict_types=1);

namespace Drupal\wordpal\Theme;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Prints the body classes WordPress's get_body_class() prints.
 *
 * Theme CSS selects on them, as it does on block classes. The classes follow
 * the route the Template resolver keys each Template to, for an anonymous
 * visitor: WordPress 7.1 prints logged-in and admin-bar for its own users
 * only. A conversion records the Content mapping the routes read under the
 * "body" key of the theme's WordPal settings.
 */
final class BodyClasses {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeManagerInterface $themeManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly PathMatcherInterface $pathMatcher,
    private readonly LanguageManagerInterface $languageManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Adds the body classes to the html template's variables.
   */
  public function preprocessHtml(array &$variables): void {
    $settings = $this->configFactory->get(ThemeSettings::configName($this->themeManager->getActiveTheme()->getName()));
    $body = $settings->get('body');
    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($settings)
      ->addCacheContexts(['route', 'languages:' . LanguageInterface::TYPE_INTERFACE]);
    $classes = [];
    if ($this->languageManager->getCurrentLanguage()->getDirection() === LanguageInterface::DIRECTION_RTL) {
      $classes[] = 'rtl';
    }
    $classes = [...$classes, ...$this->routeClasses($body, $cacheability), ...$body['classes']];
    foreach ($classes as $class) {
      $variables['attributes']['class'][] = $class;
    }
    $cacheability->merge(CacheableMetadata::createFromRenderArray($variables))->applyTo($variables);
  }

  /**
   * Returns the classes of the route the current request resolves to.
   */
  private function routeClasses(array $body, CacheableMetadata $cacheability): array {
    // The front and 404 pages are the routes system.site names.
    $cacheability->addCacheTags(['config:system.site']);
    if ($this->isNotFoundPage()) {
      return ['error404'];
    }
    // path_alias decorates the path matcher to match an aliased page.front.
    if ($this->pathMatcher->isFrontPage()) {
      return ['home', 'blog'];
    }
    $routeName = $this->routeMatch->getRouteName();
    if ($routeName === 'entity.node.canonical') {
      return $this->nodeClasses($this->routeMatch->getParameter('node'), $body['bundles']);
    }
    if ($routeName === 'entity.taxonomy_term.canonical') {
      // A Views page display serving term pages leaves the parameter raw.
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($this->routeMatch->getRawParameter('taxonomy_term'));
      $cacheability->addCacheableDependency($term);
      $prefix = $body['vocabularies'][$term->bundle()] ?? NULL;
      $slug = Html::getClass($term->label());
      return $prefix === NULL
        ? ['archive', 'tax-' . $term->bundle(), "term-$slug", 'term-' . $term->id()]
        : ['archive', $prefix, "$prefix-$slug", "$prefix-" . $term->id()];
    }
    if (isset($body['search']) && $this->routeMatch->getRouteObject()->getPath() === $body['search']['path']) {
      $results = $this->hasSearchResults($body['search'], $cacheability);
      return ['search', $results ? 'search-results' : 'search-no-results'];
    }
    return [];
  }

  /**
   * Returns TRUE when the current route is the site's 404 page.
   *
   * Core renders a 404 as a subrequest to the page.404 route, and Dynamic
   * Page Cache keys both requests by route alone, so the check reads the
   * route. A conversion sets page.404 to a path alias, and
   * Url::fromUserInput() resolves it to its internal path.
   */
  public function isNotFoundPage(): bool {
    $path = (string) $this->configFactory->get('system.site')->get('page.404');
    if ($path === '' || $this->routeMatch->getRouteName() === NULL) {
      return FALSE;
    }
    $page = Url::fromUserInput($path);
    return $page->isRouted() && $page->getInternalPath() === Url::fromRouteMatch($this->routeMatch)->getInternalPath();
  }

  /**
   * Returns the classes of a node's page.
   */
  private function nodeClasses(NodeInterface $node, array $bundles): array {
    $route = $bundles[$node->bundle()] ?? NULL;
    $id = $node->id();
    return match ($route) {
      'single' => [
        'wp-singular', 'post-template-default', 'single', 'single-post', "postid-$id", 'single-format-standard',
      ],
      'page' => ['wp-singular', 'page-template-default', 'page', "page-id-$id"],
      NULL => [],
    };
  }

  /**
   * Returns TRUE when the search keywords match an indexed item.
   *
   * WordPress checks its main query, which the search Template's inheriting
   * Query block lists; the search page's index is that query's source.
   */
  private function hasSearchResults(array $search, CacheableMetadata $cacheability): bool {
    $cacheability->addCacheContexts(['url.query_args:' . $search['parameter']]);
    $cacheability->addCacheTags(['search_api_list:' . $search['index']]);
    $keys = (string) $this->requestStack->getCurrentRequest()->query->get($search['parameter'], '');
    $query = $this->entityTypeManager->getStorage('search_api_index')->load($search['index'])->query();
    if ($keys !== '') {
      $query->keys($keys);
    }
    return $query->range(0, 1)->execute()->getResultCount() > 0;
  }

}
