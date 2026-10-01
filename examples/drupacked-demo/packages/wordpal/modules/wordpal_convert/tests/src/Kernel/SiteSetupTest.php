<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Content\ContentMappingTargetValidator;
use Drupal\wordpal_convert\Content\SiteSetup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that site setup applies the WordPal recipe only when targets miss.
 *
 * The fixture project has no `recipes/wordpal`. Site setup takes the recipe
 * from the wordpal package, which the stand-in `wordpal` recipe replaces here.
 * It pulls in a sibling base recipe, the way the real recipe pulls in the
 * Drupal CMS recipes.
 */
#[CoversClass(SiteSetup::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class SiteSetupTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'taxonomy',
    'comment', 'file', 'link', 'menu_link_content', 'views', 'wordpal', 'wordpal_convert',
  ];

  /**
   * Installs a node site without the blog bundle.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['field', 'filter', 'node', 'taxonomy']);
  }

  /**
   * Tests the first setup applies the recipe and the second applies nothing.
   */
  public function testAppliesRecipeOnce(): void {
    self::assertNull(NodeType::load('blog'));
    self::assertFalse($this->container->get('module_handler')->moduleExists('options'));

    self::assertSame($this->packageRecipe(), $this->siteSetup()->ensureTargets($this->mapping()));

    self::assertTrue($this->container->get('module_handler')->moduleExists('options'), 'The recipe must install a module, rebuilding the container.');
    self::assertNotNull(NodeType::load('blog'));
    self::assertNotNull(Vocabulary::load('tags'), 'The sibling base recipe must apply too.');
    $fields = $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'blog');
    self::assertSame('text_long', $fields['field_content']->getType());
    self::assertSame('entity_reference', $fields['field_tags']->getType());

    self::assertNull($this->siteSetup()->ensureTargets($this->mapping()), 'A second setup must apply nothing.');
  }

  /**
   * Tests a site that already has the targets keeps its blog config.
   */
  public function testExistingTargetsKeepTheirConfig(): void {
    NodeType::create(['type' => 'blog', 'name' => 'Byte post', 'description' => 'Byte site posts.'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Byte tags'])->save();
    $this->createField('field_content', 'text_long');
    $this->createField('field_tags', 'entity_reference', ['target_type' => 'taxonomy_term'], [
      'handler_settings' => ['target_bundles' => ['tags' => 'tags']],
    ]);
    $before = $this->config('node.type.blog')->getRawData();

    self::assertNull($this->siteSetup()->ensureTargets($this->mapping()));

    self::assertSame($before, $this->config('node.type.blog')->getRawData());
    self::assertSame('Byte tags', Vocabulary::load('tags')->label());
  }

  /**
   * Tests one missing target fills in and existing blog config stays.
   */
  public function testFillsGapAndKeepsExistingConfig(): void {
    NodeType::create(['type' => 'blog', 'name' => 'Byte post', 'description' => 'Byte site posts.'])->save();
    $this->createField('field_content', 'text_long');
    $before = $this->config('node.type.blog')->getRawData();

    self::assertSame($this->packageRecipe(), $this->siteSetup()->ensureTargets($this->mapping()));

    self::assertSame($before, $this->config('node.type.blog')->getRawData());
    $fields = $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'blog');
    self::assertArrayHasKey('field_tags', $fields);
  }

  /**
   * Tests a gap the recipe cannot fill fails after applying it.
   */
  public function testUnfilledGapFails(): void {
    $mapping = new ContentMapping(['post' => 'blog', 'navigation' => 'missing_menu']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('navigation names a missing menu: missing_menu.');
    $this->siteSetup()->ensureTargets($mapping);
  }

  /**
   * Returns the default mapping's post targets.
   */
  private function mapping(): ContentMapping {
    return new ContentMapping([
      'post' => 'blog',
      'post_body' => 'field_content',
      'tag' => ['vocabulary' => 'tags', 'field' => 'field_tags'],
    ]);
  }

  /**
   * Returns the stand-in for the recipe that ships in the wordpal package.
   */
  private function packageRecipe(): string {
    return dirname(__DIR__, 2) . '/fixtures/site/recipes/wordpal';
  }

  /**
   * Builds site setup around the stand-in recipe, from the current container.
   */
  private function siteSetup(): SiteSetup {
    $validator = new ContentMappingTargetValidator(
      $this->container->get('entity_type.bundle.info'),
      $this->container->get('entity_field.manager'),
      $this->container->get('entity_type.manager'),
      $this->container->get('path.validator'),
    );
    return new SiteSetup($validator, $this->packageRecipe());
  }

  /**
   * Creates one configurable field on the blog bundle.
   */
  private function createField(string $name, string $type, array $storageSettings = [], array $fieldSettings = []): void {
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => $name,
      'type' => $type,
      'settings' => $storageSettings,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'blog',
      'field_name' => $name,
      'settings' => $fieldSettings,
    ])->save();
  }

}
