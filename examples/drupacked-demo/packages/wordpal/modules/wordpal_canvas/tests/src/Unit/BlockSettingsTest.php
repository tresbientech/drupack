<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Tests\UnitTestCase;
use Drupal\canvas\PropSource\PropSource;
use Drupal\wordpal_canvas\BlockSettings;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Component\BlockPluginInputs;
use Drupal\wordpal_convert\Component\CommentBlockInputs;
use Drupal\wordpal_convert\Component\ComponentInputs;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\NavigationMenus;
use Drupal\wordpal_convert\Theme\BlockNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests a Block plugin's settings and a dynamic block's field bindings.
 *
 * Tests each block name's settings on their own, without writePattern() or
 * writeContentTemplate().
 */
#[CoversClass(BlockSettings::class)]
#[Group('wordpal')]
final class BlockSettingsTest extends UnitTestCase {

  /**
   * Returns a mapping binding the concepts these tests read.
   */
  private function mapping(): ContentMapping {
    return new ContentMapping([
      'post' => 'wordpal_post',
      'page' => 'wordpal_page',
      'post_body' => 'body',
      'page_body' => 'page_body',
      'excerpt' => 'field_excerpt',
      'page_excerpt' => 'field_page_excerpt',
      'featured_image' => 'field_image',
      'navigation' => 'main',
      'search' => ['path' => '/search', 'parameter' => 'keywords', 'index' => 'content'],
      'comments' => 'field_comments',
    ]);
  }

  /**
   * Returns a BlockSettings with a field manager stubbed for these tests.
   *
   * @param array<string, string> $fieldTypes
   *   Field types keyed by field name, as getFieldDefinitions() returns
   *   them for the "wordpal_post" bundle.
   */
  private function blockSettings(array $fieldTypes = []): BlockSettings {
    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $definitions = [];
    foreach ($fieldTypes as $name => $type) {
      $definition = $this->createMock(FieldDefinitionInterface::class);
      $definition->method('getType')->willReturn($type);
      $definitions[$name] = $definition;
    }
    $fieldManager->method('getFieldDefinitions')->with('node', 'wordpal_post')->willReturn($definitions);
    $componentPluginManager = $this->createMock(ComponentPluginManager::class);
    $commentBlockInputs = new CommentBlockInputs(new AttributeFlattener(), $componentPluginManager, new ComponentInputs($componentPluginManager, new AttributeFlattener()));
    // The wordpal module root, whose generated block-supports.json the
    // inputs read.
    $root = dirname(__DIR__, 5);
    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->with('wordpal')->willReturn(basename($root));
    $blockPluginInputs = new BlockPluginInputs(new AttributeFlattener(), new ComponentSet(dirname($root), $moduleList), $commentBlockInputs);
    return new BlockSettings($blockPluginInputs, $commentBlockInputs, $fieldManager, $this->createMock(EntityTypeManagerInterface::class));
  }

  /**
   * Tests an allowed attribute overrides the component's own default.
   *
   * A support prop goes under "supports", from the block's generated list.
   */
  public function testPluginInputsOverridesDefaultsWithAllowedAttributes(): void {
    $node = new BlockNode('core/site-tagline', [
      'level' => 3,
      'style' => ['typography' => ['textAlign' => 'center']],
      'unrelated' => 'x',
    ], '', []);

    $inputs = $this->blockSettings()->pluginInputs($node, ['level' => 2, 'supports' => [], 'other' => 'keep'], $this->mapping());

    self::assertSame(3, $inputs['level']);
    self::assertSame(['text_align' => 'center'], $inputs['supports']);
    self::assertSame('keep', $inputs['other'], 'A default the block does not override stays.');
    self::assertArrayNotHasKey('unrelated', $inputs, 'An attribute outside the allowed list is dropped.');
  }

  /**
   * Tests an unrecognized block name fails loudly.
   */
  public function testPluginInputsRejectsUnsupportedBlock(): void {
    $this->expectException(\UnexpectedValueException::class);

    $this->blockSettings()->pluginInputs(new BlockNode('core/unknown', [], '', []), [], $this->mapping());
  }

  /**
   * Tests a navigation link block returns its adjacent-link settings alone.
   */
  public function testPluginInputsPostNavigationLinkReturnsNavigationSettings(): void {
    $node = new BlockNode('core/post-navigation-link', [
      'type' => 'previous',
      'label' => 'Older',
      'arrow' => 'arrow',
      'className' => 'is-style-blog-style',
      'style' => ['typography' => ['textAlign' => 'right', 'fontWeight' => '600']],
    ], '', []);

    $inputs = $this->blockSettings()->pluginInputs($node, ['unused_default' => TRUE], $this->mapping());

    self::assertSame('previous', $inputs['direction']);
    self::assertSame('Older', $inputs['label']);
    self::assertSame('arrow', $inputs['arrow']);
    $supports = array_intersect_key($inputs['supports'], array_flip(['css_class', 'text_align', 'font_weight']));
    self::assertEquals(['css_class' => 'is-style-blog-style', 'text_align' => 'right', 'font_weight' => '600'], $supports);
    self::assertArrayNotHasKey('unused_default', $inputs, 'Navigation settings do not merge the component defaults.');
  }

  /**
   * Tests a Navigation block with its own links binds its own menu.
   */
  public function testPluginInputsNavigationWithOwnLinksBindsItsOwnMenu(): void {
    $mapping = $this->mapping();
    $mapping->setThemeId('wordpal_fixture');
    $node = new BlockNode('core/navigation', [], '', [
      new BlockNode('core/navigation-link', ['label' => 'Home', 'url' => '/'], '', []),
    ]);

    $inputs = $this->blockSettings()->pluginInputs($node, [], $mapping);

    self::assertSame(NavigationMenus::menuId('wordpal_fixture', $node), $inputs['menu']);
    self::assertFalse($inputs['menu_label'], 'A block with inner links other than a ref names itself by ariaLabel.');
  }

  /**
   * Tests a Navigation block with a ref renders the mapped site menu.
   */
  public function testPluginInputsNavigationWithRefUsesMappedMenu(): void {
    $node = new BlockNode('core/navigation', ['ref' => 42], '', []);

    $inputs = $this->blockSettings()->pluginInputs($node, [], $this->mapping());

    self::assertSame('main', $inputs['menu']);
    self::assertTrue($inputs['menu_label']);
  }

  /**
   * Tests a Search block's width attribute prints with its unit.
   */
  public function testPluginInputsSearchAppendsWidthUnit(): void {
    $node = new BlockNode('core/search', ['width' => 50, 'widthUnit' => '%'], '', []);

    $inputs = $this->blockSettings()->pluginInputs($node, [], $this->mapping());

    self::assertSame('50%', $inputs['width']);
    self::assertSame('/search', $inputs['path']);
    self::assertSame('keywords', $inputs['parameter']);
  }

  /**
   * Tests a Search block with an unsupported width unit fails loudly.
   */
  public function testPluginInputsSearchRejectsUnsupportedWidthUnit(): void {
    $this->expectException(\UnexpectedValueException::class);

    $this->blockSettings()->pluginInputs(new BlockNode('core/search', ['width' => 50, 'widthUnit' => 'vmin'], '', []), [], $this->mapping());
  }

  /**
   * Tests a Post Title binds its title and host URL.
   */
  public function testDynamicFieldBindingsPostTitleBindsTitleAndUrl(): void {
    $bindings = $this->blockSettings()->dynamicFieldBindings(new BlockNode('core/post-title', [], '', []), 'wordpal_post', $this->mapping(), ['is_link' => TRUE], TRUE);

    self::assertTrue($bindings['is_link'], 'The static inputs survive the merge.');
    self::assertSame(PropSource::EntityField->value, $bindings['title']['sourceType']);
    self::assertSame(PropSource::HostEntityUrl->value, $bindings['url']['sourceType']);
  }

  /**
   * Tests a Post Date bound to "modified" also binds the published timestamp.
   */
  public function testDynamicFieldBindingsPostDateModifiedBindsPublishedTimestamp(): void {
    $modified = $this->blockSettings()->dynamicFieldBindings(new BlockNode('core/post-date', ['displayType' => 'modified'], '', []), 'wordpal_post', $this->mapping(), [], TRUE);
    $published = $this->blockSettings()->dynamicFieldBindings(new BlockNode('core/post-date', [], '', []), 'wordpal_post', $this->mapping(), [], TRUE);

    self::assertStringContainsString('changed', $modified['date']['expression']);
    self::assertArrayHasKey('published_timestamp', $modified);
    self::assertStringContainsString('created', $published['date']['expression']);
    self::assertArrayNotHasKey('published_timestamp', $published);
  }

  /**
   * Tests Post Content binds the post or page body by the host bundle.
   */
  public function testDynamicFieldBindingsPostContentUsesPostOrPageBody(): void {
    $node = new BlockNode('core/post-content', [], '', []);

    $post = $this->blockSettings()->dynamicFieldBindings($node, 'wordpal_post', $this->mapping(), [], TRUE);
    $page = $this->blockSettings()->dynamicFieldBindings($node, 'wordpal_page', $this->mapping(), [], FALSE);

    self::assertStringContainsString('body', $post['content']['expression']);
    self::assertStringContainsString('page_body', $page['content']['expression']);
  }

  /**
   * Tests a Cover binds its featured image only when it uses one.
   */
  public function testDynamicFieldBindingsCoverOnlyWhenUsingFeaturedImage(): void {
    $settings = $this->blockSettings(['field_image' => 'image']);
    $node = new BlockNode('core/cover', ['useFeaturedImage' => TRUE], '', []);

    self::assertNull($settings->dynamicFieldBindings(new BlockNode('core/cover', [], '', []), 'wordpal_post', $this->mapping(), [], TRUE));
    $bindings = $settings->dynamicFieldBindings($node, 'wordpal_post', $this->mapping(), [], TRUE);
    self::assertSame(PropSource::EntityField->value, $bindings['url']['sourceType']);
  }

  /**
   * Tests Media & Text binds its featured image only when it uses one.
   *
   * Media-text.php prints the featured image's own attachment id as a
   * `wp-image-N` class, so unlike Cover, the binding also carries media_id.
   */
  public function testDynamicFieldBindingsMediaTextOnlyWhenUsingFeaturedImage(): void {
    $settings = $this->blockSettings(['field_image' => 'image']);
    $node = new BlockNode('core/media-text', ['useFeaturedImage' => TRUE], '', []);

    self::assertNull($settings->dynamicFieldBindings(new BlockNode('core/media-text', [], '', []), 'wordpal_post', $this->mapping(), [], TRUE));
    $bindings = $settings->dynamicFieldBindings($node, 'wordpal_post', $this->mapping(), [], TRUE);
    self::assertSame(PropSource::EntityField->value, $bindings['media_url']['sourceType']);
    self::assertStringContainsString('target_id', $bindings['media_id']['expression']);
    self::assertSame('image', $bindings['media_type']);
  }

  /**
   * Tests a Post Featured Image binds a plain image field directly.
   */
  public function testDynamicFieldBindingsFeaturedImagePlainField(): void {
    $settings = $this->blockSettings(['field_image' => 'image']);

    $bindings = $settings->dynamicFieldBindings(new BlockNode('core/post-featured-image', [], '', []), 'wordpal_post', $this->mapping(), [], TRUE);

    self::assertStringContainsString('field_image', $bindings['src']['expression']);
    self::assertStringNotContainsString('entity␜␜entity:media', $bindings['src']['expression'], 'A plain image field has no media reference to traverse.');
  }

  /**
   * Tests a block with no dynamic binding returns NULL.
   */
  public function testDynamicFieldBindingsUnknownBlockReturnsNull(): void {
    self::assertNull($this->blockSettings()->dynamicFieldBindings(new BlockNode('core/paragraph', [], '', []), 'wordpal_post', $this->mapping(), [], TRUE));
  }

}
