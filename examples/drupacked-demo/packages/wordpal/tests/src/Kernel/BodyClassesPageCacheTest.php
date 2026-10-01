<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\PageCache\ChainRequestPolicy;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\wordpal\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the 404 body class under Dynamic Page Cache.
 *
 * Core renders a 404 as a subrequest to the page.404 route, and Dynamic Page
 * Cache stores that subrequest's response under the route's own entry.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class BodyClassesPageCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'views', 'comment',
    'link', 'menu_link_content', 'dynamic_page_cache', 'wordpal',
    'wordpal_body_classes_test',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The default policy skips command-line requests, and tests run there.
    $container->getDefinition('dynamic_page_cache_request_policy')->setClass(ChainRequestPolicy::class);
  }

  /**
   * Tests the 404 page prints error404 whichever request fills the cache.
   */
  #[DataProvider('orders')]
  public function testNotFoundPageClasses(array $paths): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'node', 'filter']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous'])->grantPermission('access content')->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $page = Node::create(['type' => 'page', 'title' => 'Not found']);
    $page->save();
    $this->config('system.site')->set('page.404', '/node/' . $page->id())->save();
    $this->config(ThemeSettings::configName('stark'))->setData([
      'body' => ['classes' => [], 'bundles' => [], 'vocabularies' => [], 'search' => NULL],
    ])->save();

    $cache = [];
    foreach ($paths as $path) {
      $path = str_replace('{nid}', (string) $page->id(), $path);
      $response = $this->container->get('http_kernel')->handle(Request::create($path));
      $cache[] = $response->headers->get('X-Drupal-Dynamic-Cache');
      self::assertMatchesRegularExpression('/<body[^>]*class="[^"]*\berror404\b/', (string) $response->getContent(), "$path prints error404.");
    }
    self::assertStringContainsString('HIT', $cache[1], 'The second request reads the entry the first stored.');
  }

  /**
   * Returns the request orders: direct visit first, then a real 404 first.
   */
  public static function orders(): array {
    return [
      'direct first' => [['/node/{nid}', '/no-such-page']],
      'not found first' => [['/no-such-page', '/node/{nid}']],
    ];
  }

}
