<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Component\ComponentInputs;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Query\QuerySettingsWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shared builder of a Query View's `wordpal.query` setting.
 *
 * Its cardConcepts() is target-independent, so every writer's Query View
 * reads it from here, not from a target module.
 */
#[CoversClass(QuerySettingsWriter::class)]
#[Group('wordpal')]
final class QuerySettingsWriterTest extends UnitTestCase {

  /**
   * Returns a mapping binding post, tag, category and search.
   */
  private function mapping(): ContentMapping {
    return new ContentMapping([
      'post' => 'wordpal_post',
      'page' => 'wordpal_page',
      'tag' => ['vocabulary' => 'tags', 'field' => 'field_tags'],
      'category' => ['vocabulary' => 'category', 'field' => 'field_category'],
      'search' => ['path' => '/search', 'parameter' => 'keywords', 'index' => 'content'],
    ]);
  }

  /**
   * Tests cardConcepts adds the page bundle only on the search route.
   */
  public function testCardConceptsAddsPageOnlyOnSearch(): void {
    self::assertSame(['post' => 'wordpal_post'], QuerySettingsWriter::cardConcepts($this->mapping(), NULL));
    self::assertSame(
      ['post' => 'wordpal_post', 'page' => 'wordpal_page'],
      QuerySettingsWriter::cardConcepts($this->mapping(), 'search'),
    );
  }

  /**
   * Returns a writer whose ComponentInputs bundleField() never calls.
   *
   * ComponentInputs is final, so it is built for real rather than mocked;
   * bundleField() never reaches it.
   */
  private function writer(EntityFieldManagerInterface $fieldManager): QuerySettingsWriter {
    $componentInputs = new ComponentInputs($this->createMock(ComponentPluginManager::class), new AttributeFlattener());
    return new QuerySettingsWriter($componentInputs, $fieldManager);
  }

  /**
   * Tests bundleField reads the mapped field off the bundle's own fields.
   */
  public function testBundleFieldReadsTheMappedFieldFromTheBundle(): void {
    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->method('getFieldDefinitions')
      ->with('node', 'wordpal_post')
      ->willReturn(['field_tags' => $this->createMock(FieldDefinitionInterface::class)]);

    self::assertSame('field_tags', $this->writer($fieldManager)->bundleField($this->mapping(), 'tag', 'wordpal_post'));
  }

  /**
   * Tests bundleField returns NULL for a concept the mapping does not bind.
   */
  public function testBundleFieldReturnsNullWhenTheMappingHasNoConcept(): void {
    $mapping = new ContentMapping(array_fill_keys(ContentMapping::CONCEPTS, NULL) + ['post' => 'wordpal_post']);
    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);

    self::assertNull($this->writer($fieldManager)->bundleField($mapping, 'tag', 'wordpal_post'));
  }

  /**
   * Tests bundleField returns NULL when the bundle lacks the mapped field.
   */
  public function testBundleFieldReturnsNullWhenTheBundleLacksTheField(): void {
    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->method('getFieldDefinitions')->with('node', 'wordpal_page')->willReturn([]);

    self::assertNull($this->writer($fieldManager)->bundleField($this->mapping(), 'tag', 'wordpal_page'));
  }

}
