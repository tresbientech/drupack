<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Support\Cacheable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that Cacheable folds an access result or a URL into cacheability.
 */
#[CoversClass(Cacheable::class)]
#[Group('wordpal')]
final class CacheableTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Cache::mergeContexts() asserts each context through this service.
    $manager = $this->createMock(CacheContextsManager::class);
    $manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $manager);
    \Drupal::setContainer($container);
  }

  /**
   * Tests that a forbidden result's cache metadata merges in, denying.
   */
  public function testAllowedMergesForbiddenResultAndReturnsFalse(): void {
    $access = AccessResult::forbidden()->addCacheTags(['denied-reason']);
    $cacheability = new CacheableMetadata();
    self::assertFalse(Cacheable::allowed($access, $cacheability));
    self::assertContains('denied-reason', $cacheability->getCacheTags());
  }

  /**
   * Tests that an allowed result's cache metadata merges in, allowing.
   */
  public function testAllowedMergesAnAllowedResultAndReturnsTrue(): void {
    $access = AccessResult::allowed()->addCacheContexts(['user.roles']);
    $cacheability = new CacheableMetadata();
    self::assertTrue(Cacheable::allowed($access, $cacheability));
    self::assertContains('user.roles', $cacheability->getCacheContexts());
  }

  /**
   * Tests that a generated URL's cache metadata merges in.
   */
  public function testUrlMergesTheGeneratedUrlAndReturnsTheString(): void {
    $generatedUrl = (new GeneratedUrl())
      ->setGeneratedUrl('/example')
      ->addCacheTags(['url-tag']);
    $url = $this->createMock(Url::class);
    $url->method('toString')->with(TRUE)->willReturn($generatedUrl);
    $cacheability = new CacheableMetadata();
    self::assertSame('/example', Cacheable::url($url, $cacheability));
    self::assertContains('url-tag', $cacheability->getCacheTags());
  }

  /**
   * Tests that a denied entity check returns NULL but still merges metadata.
   */
  public function testLinkReturnsNullAndMergesTheEntityAndTheAccessResult(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn(['entity-tag']);
    $entity->method('getCacheMaxAge')->willReturn(-1);
    $entity->method('access')
      ->with('view', NULL, TRUE)
      ->willReturn(AccessResult::forbidden()->addCacheTags(['denied-reason']));
    $entity->expects(self::never())->method('toUrl');
    $cacheability = new CacheableMetadata();
    self::assertNull(Cacheable::link($entity, $cacheability));
    self::assertContains('entity-tag', $cacheability->getCacheTags());
    self::assertContains('denied-reason', $cacheability->getCacheTags());
  }

  /**
   * Tests that an allowed entity check returns its URL and merges metadata.
   */
  public function testLinkReturnsTheUrlWhenAllowed(): void {
    $generatedUrl = (new GeneratedUrl())->setGeneratedUrl('/entity/1');
    $url = $this->createMock(Url::class);
    $url->method('toString')->with(TRUE)->willReturn($generatedUrl);
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn(['entity-tag']);
    $entity->method('getCacheMaxAge')->willReturn(-1);
    $entity->method('access')
      ->with('edit', NULL, TRUE)
      ->willReturn(AccessResult::allowed());
    $entity->method('toUrl')->willReturn($url);
    $cacheability = new CacheableMetadata();
    self::assertSame('/entity/1', Cacheable::link($entity, $cacheability, 'edit'));
    self::assertContains('entity-tag', $cacheability->getCacheTags());
  }

}
