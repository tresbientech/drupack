<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\Core\Path\PathMatcher;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\AliasPathMatcher;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\wordpal\Theme\BodyClasses;
use Drupal\wordpal\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the body classes a generated theme prints on each Template route.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class BodyClassesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'system', 'user', 'field', 'node', 'text', 'filter', 'views', 'taxonomy',
    'comment', 'link', 'menu_link_content', 'search_api', 'search_api_db',
    'path_alias',
  ];

  /**
   * Tests each route's classes against what WordPress 7.1.2 prints.
   *
   * The expected lists are the Reference's body classes with logged-in,
   * admin-bar and no-customize-support removed, which WordPress prints for
   * its own users only.
   */
  public function testRouteClasses(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('search_api_task');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['system', 'node', 'search_api']);
    NodeType::create(['type' => 'blog', 'name' => 'Blog'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    NodeType::create(['type' => 'other', 'name' => 'Other'])->save();
    foreach (['tags', 'places'] as $vocabulary) {
      Vocabulary::create(['vid' => $vocabulary, 'name' => $vocabulary])->save();
    }
    Server::create([
      'id' => 'database',
      'name' => 'Database',
      'backend' => 'search_api_db',
      'backend_config' => ['database' => 'default:default', 'min_chars' => 1, 'matching' => 'words'],
    ])->save();
    $index = Index::create([
      'id' => 'content',
      'name' => 'Content',
      'server' => 'database',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
      'field_settings' => [
        'title' => ['label' => 'Title', 'datasource_id' => 'entity:node', 'property_path' => 'title', 'type' => 'text'],
      ],
      'options' => ['index_directly' => FALSE],
    ]);
    $index->save();
    $post = Node::create(['type' => 'blog', 'title' => 'A walk through the city']);
    $post->save();
    $page = Node::create(['type' => 'page', 'title' => 'About the walks']);
    $page->save();
    $front = Node::create(['type' => 'other', 'title' => 'Front']);
    $front->save();
    $notFound = Node::create(['type' => 'other', 'title' => 'Not found']);
    $notFound->save();
    $tag = Term::create(['vid' => 'tags', 'name' => 'City Walks']);
    $tag->save();
    $place = Term::create(['vid' => 'places', 'name' => 'Old Town']);
    $place->save();
    $index->indexItems();
    $this->container->get('router.builder')->rebuild();
    $this->config('system.site')->set('page.front', '/node/' . $front->id())->set('page.404', '/node/' . $notFound->id())->save();
    $theme = $this->container->get('theme.manager')->getActiveTheme()->getName();
    $this->config(ThemeSettings::configName($theme))->setData([
      'body' => [
        'classes' => ['wp-embed-responsive', 'wp-theme-twentytwentyfour'],
        'bundles' => ['blog' => 'single', 'page' => 'page'],
        'vocabularies' => ['tags' => 'tag'],
        'search' => ['path' => '/search', 'parameter' => 'keywords', 'index' => 'content'],
      ],
    ])->save();
    $theme = ['wp-embed-responsive', 'wp-theme-twentytwentyfour'];

    self::assertSame(['home', 'blog', ...$theme], $this->classes('/node/' . $front->id())['classes']);
    $post_id = $post->id();
    self::assertSame(
      [
        'wp-singular', 'post-template-default', 'single', 'single-post', "postid-$post_id", 'single-format-standard',
        ...$theme,
      ],
      $this->classes("/node/$post_id")['classes'],
    );
    self::assertSame(
      ['wp-singular', 'page-template-default', 'page', 'page-id-' . $page->id(), ...$theme],
      $this->classes('/node/' . $page->id())['classes'],
    );
    self::assertSame(
      ['archive', 'tag', 'tag-city-walks', 'tag-' . $tag->id(), ...$theme],
      $this->classes('/taxonomy/term/' . $tag->id())['classes'],
    );
    self::assertSame(
      ['archive', 'tax-places', 'term-old-town', 'term-' . $place->id(), ...$theme],
      $this->classes('/taxonomy/term/' . $place->id())['classes'],
    );
    $search = $this->classes('/search?keywords=city');
    self::assertSame(['search', 'search-results', ...$theme], $search['classes']);
    self::assertContains('url.query_args:keywords', $search['contexts']);
    self::assertContains('search_api_list:content', $search['tags']);
    self::assertSame(['search', 'search-no-results', ...$theme], $this->classes('/search?keywords=harbour')['classes']);
    self::assertSame(['error404', ...$theme], $this->classes('/node/' . $notFound->id())['classes']);
    $node = $this->classes("/node/$post_id");
    self::assertContains('route', $node['contexts']);
    self::assertContains('config:system.site', $node['tags']);
    self::assertContains('config:' . ThemeSettings::configName($this->container->get('theme.manager')->getActiveTheme()->getName()), $node['tags']);
  }

  /**
   * Tests the front and 404 classes when both settings are path aliases.
   *
   * A Site recipe points page.front and page.404 at the pages' aliases,
   * because imported pages get new ids.
   */
  public function testSitePagesByAlias(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system', 'node']);
    NodeType::create(['type' => 'other', 'name' => 'Other'])->save();
    $front = Node::create(['type' => 'other', 'title' => 'Front']);
    $front->save();
    $notFound = Node::create(['type' => 'other', 'title' => 'Not found']);
    $notFound->save();
    PathAlias::create(['path' => '/node/' . $front->id(), 'alias' => '/site-home'])->save();
    PathAlias::create(['path' => '/node/' . $notFound->id(), 'alias' => '/site-404'])->save();
    $this->container->get('router.builder')->rebuild();
    $this->config('system.site')->set('page.front', '/site-home')->set('page.404', '/site-404')->save();
    $theme = $this->container->get('theme.manager')->getActiveTheme()->getName();
    $this->config(ThemeSettings::configName($theme))->setData([
      'body' => ['classes' => [], 'bundles' => [], 'vocabularies' => [], 'search' => NULL],
    ])->save();

    self::assertSame(['home', 'blog'], $this->classes('/node/' . $front->id())['classes']);
    self::assertSame(['error404'], $this->classes('/node/' . $notFound->id())['classes']);
  }

  /**
   * Routes a request and returns the body classes and cacheability printed.
   */
  private function classes(string $uri): array {
    $request = Request::create($uri);
    if ($request->getPathInfo() === '/search') {
      // The search Template's View owns this path on a converted site. The
      // route name only needs to exist for the front page check.
      $request->attributes->add(['_route' => 'system.admin', '_route_object' => new Route('/search')]);
    }
    else {
      $request->attributes->add($this->container->get('router.no_access_checks')->matchRequest($request));
    }
    $this->container->get('request_stack')->push($request);
    // The path matcher and the path_alias decorator around it keep their
    // answer for the request they first served.
    $matcher = $this->container->get('path.matcher');
    (new \ReflectionProperty(AliasPathMatcher::class, 'isCurrentFrontPage'))->setValue($matcher, NULL);
    $decorated = (new \ReflectionProperty(AliasPathMatcher::class, 'decorated'))->getValue($matcher);
    (new \ReflectionProperty(PathMatcher::class, 'isCurrentFrontPage'))->setValue($decorated, NULL);
    $variables = ['attributes' => []];
    $this->container->get(BodyClasses::class)->preprocessHtml($variables);
    $this->container->get('request_stack')->pop();
    return [
      'classes' => $variables['attributes']['class'],
      'contexts' => $variables['#cache']['contexts'],
      'tags' => $variables['#cache']['tags'],
    ];
  }

}
