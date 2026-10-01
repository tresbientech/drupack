<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Listing\ListCacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shared cache contexts and tags of a node or term listing.
 */
#[CoversClass(ListCacheMetadata::class)]
#[Group('wordpal')]
final class ListCacheMetadataTest extends UnitTestCase {

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
   * Tests the node-grants context pair and the bundle's node_list tag.
   */
  public function testForNodesCarriesTheNodeGrantsContextAndBundleTag(): void {
    $metadata = ListCacheMetadata::forNodes('article');
    self::assertContains('user.permissions', $metadata->getCacheContexts());
    self::assertContains('user.node_grants:view', $metadata->getCacheContexts());
    self::assertContains('node_list:article', $metadata->getCacheTags());
  }

  /**
   * Tests the node-grants context pair and no cache tags.
   */
  public function testForNodeAccessCarriesTheNodeGrantsContextAndNoTags(): void {
    $metadata = ListCacheMetadata::forNodeAccess();
    self::assertContains('user.permissions', $metadata->getCacheContexts());
    self::assertContains('user.node_grants:view', $metadata->getCacheContexts());
    self::assertSame([], $metadata->getCacheTags());
  }

  /**
   * Tests the node-grants context pair and the vocabulary's term list tags.
   */
  public function testForTermsCarriesTheNodeGrantsContextAndVocabularyTag(): void {
    $metadata = ListCacheMetadata::forTerms('tags');
    self::assertContains('user.permissions', $metadata->getCacheContexts());
    self::assertContains('user.node_grants:view', $metadata->getCacheContexts());
    self::assertContains('taxonomy_term_list:tags', $metadata->getCacheTags());
    self::assertContains('node_list', $metadata->getCacheTags());
  }

}
