<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Content\ContentMappingLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the external Content mapping file boundary.
 */
#[CoversClass(ContentMapping::class)]
#[CoversClass(ContentMappingLoader::class)]
#[Group('wordpal')]
final class ContentMappingLoaderTest extends UnitTestCase {

  /**
   * The mapping loader under test.
   */
  private ContentMappingLoader $loader;

  /**
   * Creates the mapping loader.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->loader = new ContentMappingLoader();
  }

  /**
   * Tests the committed mapping fixture and drop reporting.
   */
  public function testLoadsSharedFixture(): void {
    $mapping = $this->loader->load(dirname(__DIR__, 2) . '/fixtures/content/content-mapping.yml');

    self::assertSame('wordpal_post', $mapping->target('post'));
    self::assertSame(['vocabulary' => 'wordpal_category', 'field' => 'field_categories'], $mapping->target('category'));
    self::assertSame('field_comments', $mapping->target('comments'));
    self::assertTrue($mapping->drops('navigation'));
    self::assertTrue($mapping->drops('search'));

    $mapping->recordDroppedBlocks('navigation', 2);
    $mapping->recordDroppedBlocks('navigation');
    self::assertSame(['navigation' => 3], $mapping->droppedBlocks());
  }

  /**
   * Tests that no mapping path resolves to the Drupal CMS mapping.
   */
  public function testResolvesDefaultMapping(): void {
    $mapping = $this->loader->resolve(NULL);

    self::assertSame('blog', $mapping->target('post'));
    self::assertSame('field_content', $mapping->target('post_body'));
    self::assertSame('page', $mapping->target('page'));
    self::assertSame('field_content', $mapping->target('page_body'));
    self::assertSame('field_description', $mapping->target('page_excerpt'));
    self::assertSame(['vocabulary' => 'tags', 'field' => 'field_tags'], $mapping->target('tag'));
    self::assertSame('field_featured_image', $mapping->target('featured_image'));
    self::assertSame('field_description', $mapping->target('excerpt'));
    self::assertSame('main', $mapping->target('navigation'));
    self::assertSame(['path' => '/search', 'parameter' => 'keywords', 'index' => 'content'], $mapping->target('search'));
    foreach (['category', 'comments', 'author_biography'] as $concept) {
      self::assertTrue($mapping->drops($concept), "The default mapping drops $concept.");
    }
    foreach (ContentMapping::CONCEPTS as $concept) {
      self::assertTrue($mapping->has($concept), "The default mapping supplies $concept.");
    }
  }

  /**
   * Tests that an override file replaces the default mapping whole.
   */
  public function testResolvesOverrideWhole(): void {
    $path = $this->mappingFile("post: article
page_body: body
page: page
");

    try {
      $mapping = $this->loader->resolve($path, ['post']);
      self::assertSame('article', $mapping->target('post'));
      self::assertSame('body', $mapping->target('page_body'));
      self::assertFalse($mapping->has('post_body'), 'An override takes nothing from the default mapping.');
      self::assertFalse($mapping->has('navigation'));
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Tests that each dropped concept names the Drupal feature providing it.
   */
  public function testDroppedConceptProviders(): void {
    self::assertSame([
      'category' => 'a core Taxonomy vocabulary',
      'author_biography' => 'a user text field',
      'comments' => 'a comment field on the post bundle',
    ], $this->loader->resolve(NULL)->providers());

    $path = $this->mappingFile("post: article
comments: field_comments
category: ~
");
    try {
      self::assertSame(
        ['category' => 'a core Taxonomy vocabulary'],
        $this->loader->resolve($path, ['post'])->providers(),
        'Only concepts mapped to ~ name a provider.',
      );
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Tests that one failure reports every absent concept.
   */
  public function testReportsAllMissingConcepts(): void {
    $path = $this->mappingFile("post: article\ncomments: ~\n");

    try {
      $this->loader->load($path);
      self::fail('An incomplete mapping should fail.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('post_body, page, page_body, page_excerpt, category, tag, featured_image, excerpt, author_biography, navigation, search', $exception->getMessage());
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Tests that Theme model concepts determine the required keys.
   */
  public function testRequiresOnlyUsedConcepts(): void {
    $path = $this->mappingFile("post: article\ncomments: ~\n");

    try {
      $mapping = $this->loader->load($path, ['post', 'comments']);
      self::assertSame('article', $mapping->target('post'));
      self::assertTrue($mapping->drops('comments'));
      self::assertFalse($mapping->has('page'));
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Tests that every content concept may be dropped explicitly.
   */
  public function testAllowsNullForEveryConcept(): void {
    $yaml = implode(": ~\n", ContentMapping::CONCEPTS) . ": ~\n";
    $path = $this->mappingFile($yaml);

    try {
      $mapping = $this->loader->load($path);
      foreach (ContentMapping::CONCEPTS as $concept) {
        self::assertTrue($mapping->drops($concept));
      }
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Tests that invalid nested values are reported together.
   */
  public function testReportsAllShapeErrors(): void {
    $path = $this->mappingFile(<<<'YAML'
post: ''
page: page
page_body: [body]
category:
  vocabulary: categories
tag: tags
featured_image: field_image
excerpt: field_excerpt
author_biography: field_biography
comments: false
navigation: ~
search:
  path: search
  extra: value
YAML);

    try {
      $this->loader->load($path);
      self::fail('An invalid mapping should fail.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('post must be a non-empty string or null.', $exception->getMessage());
      self::assertStringContainsString('page_body must be a non-empty string or null.', $exception->getMessage());
      self::assertStringContainsString('category.field must be a non-empty string.', $exception->getMessage());
      self::assertStringContainsString('tag must be a mapping with vocabulary and field.', $exception->getMessage());
      self::assertStringContainsString('comments must be a non-empty string or null.', $exception->getMessage());
      self::assertStringContainsString('search.parameter must be a non-empty string.', $exception->getMessage());
      self::assertStringContainsString('search has unknown keys: extra.', $exception->getMessage());
      self::assertStringContainsString('search.path must start with a slash.', $exception->getMessage());
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Writes YAML to an isolated file for one boundary test.
   */
  private function mappingFile(string $yaml): string {
    $path = sys_get_temp_dir() . '/wordpal-content-mapping-' . bin2hex(random_bytes(6)) . '.yml';
    file_put_contents($path, $yaml);
    return $path;
  }

}
