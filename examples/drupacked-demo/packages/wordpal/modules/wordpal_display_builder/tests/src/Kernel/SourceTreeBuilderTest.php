<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Kernel;

use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Component\BlockPluginInputs;
use Drupal\wordpal_convert\Component\CommentBlockInputs;
use Drupal\wordpal_convert\Component\ComponentInputs;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Listing\ListingBlockSettings;
use Drupal\wordpal_convert\NavigationMenus;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_display_builder\ListingBlocks;
use Drupal\wordpal_display_builder\RuntimeBindings;
use Drupal\wordpal_display_builder\SourceTreeBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests turning a WordPal block tree into Display Builder's source tree.
 *
 * Real WordPal components are discovered from the enabled `wordpal` module,
 * the same components CanvasWriter's own tests build against, instead of
 * fixture components: `button`, `paragraph`, `columns` and `column` already
 * cover every prop kind and a slot this class needs to prove itself against.
 */
#[CoversClass(SourceTreeBuilder::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class SourceTreeBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'comment',
    'node',
    'views',
    'menu_link_content',
    'filter',
    'text',
    'wordpal',
    'wordpal_convert',
  ];

  /**
   * The builder under test.
   */
  private SourceTreeBuilder $builder;

  /**
   * A Content mapping binding navigation to the site's main menu.
   */
  private ContentMapping $mapping;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $runtimeBindings = new RuntimeBindings($this->container->get('entity_field.manager'), $this->container->get('entity_type.manager'));
    $listingBlocks = new ListingBlocks(new ListingBlockSettings(new AttributeFlattener()));
    $componentInputs = new ComponentInputs($this->container->get(ComponentPluginManager::class), new AttributeFlattener());
    $commentBlockInputs = new CommentBlockInputs(new AttributeFlattener(), $this->container->get(ComponentPluginManager::class), $componentInputs);
    $this->builder = new SourceTreeBuilder($this->container->get(ComponentPluginManager::class), $runtimeBindings, $listingBlocks, new BlockPluginInputs(new AttributeFlattener(), $this->container->get(ComponentSet::class), $commentBlockInputs), $componentInputs, $commentBlockInputs);
    $this->mapping = new ContentMapping(['navigation' => 'main'] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $this->mapping->setThemeId('wordpal_fixture');
  }

  /**
   * Tests each prop kind goes to its own ui_patterns source.
   *
   * Covers string, HTML, enum, boolean and number props in one pass.
   */
  public function testEachPropKindGoesToItsOwnSource(): void {
    $tree = $this->builder->build([
      new BlockNode('core/button', [
        'url' => '/pricing',
        'text' => '<strong>Buy</strong>',
        'tagName' => 'button',
      ], '', []),
      new BlockNode('core/paragraph', ['dropCap' => TRUE], '<p>Text</p>', []),
      new BlockNode('core/cover', ['dimRatio' => 20], '', []),
    ], $this->mapping);

    $props = $tree[0]['source']['component']['props'];
    self::assertSame(['source_id' => 'textfield', 'source' => ['value' => '/pricing']], $props['url']);
    self::assertSame([
      'source_id' => 'wysiwyg',
      'source' => ['value' => ['value' => '<strong>Buy</strong>', 'format' => 'wordpal_html']],
    ], $props['text']);
    self::assertSame(['source_id' => 'select', 'source' => ['value' => 'button']], $props['tag_name']);

    self::assertSame(['source_id' => 'checkbox', 'source' => ['value' => TRUE]], $tree[1]['source']['component']['props']['drop_cap']);
    self::assertSame(['source_id' => 'number', 'source' => ['value' => 20]], $tree[2]['source']['component']['props']['dim_ratio']);
  }

  /**
   * Tests a component's slot children keep their document order.
   */
  public function testSlotChildrenKeepOrder(): void {
    $tree = $this->builder->build([
      new BlockNode('core/columns', [], '', [
        new BlockNode('core/column', [], '', [new BlockNode('core/paragraph', [], '<p>A</p>', [])]),
        new BlockNode('core/column', [], '', [new BlockNode('core/paragraph', [], '<p>B</p>', [])]),
      ]),
    ], $this->mapping);

    $sources = $tree[0]['source']['component']['slots']['content']['sources'];
    self::assertCount(2, $sources);
    self::assertSame('wordpal:column', $sources[0]['source']['component']['component_id']);
    self::assertSame('wordpal:column', $sources[1]['source']['component']['component_id']);
    // Each column's own single child keeps its position too.
    $firstChild = $sources[0]['source']['component']['slots']['content']['sources'][0];
    $secondChild = $sources[1]['source']['component']['slots']['content']['sources'][0];
    self::assertNotSame($firstChild['node_id'], $secondChild['node_id']);
  }

  /**
   * Tests each Social Icon reads its Social Icons parent's options.
   */
  public function testSocialIconReadsParentContext(): void {
    $tree = $this->builder->build([
      new BlockNode('core/social-links', ['openInNewTab' => TRUE, 'iconColorValue' => '#404040'], '', [
        new BlockNode('core/social-link', ['url' => 'https://example.com', 'service' => 'x'], '', []),
      ]),
    ], $this->mapping);

    $props = $tree[0]['source']['component']['slots']['content']['sources'][0]['source']['component']['props'];
    self::assertTrue($props['open_in_new_tab']['source']['value']);
    self::assertSame('#404040', $props['icon_color_value']['source']['value']);
  }

  /**
   * Tests an image reads its Template part and Gallery ancestors.
   *
   * WordPress decodes a Template part's images async and gives a Gallery's
   * images their data-id; the Image component prints both from flags.
   */
  public function testImagesReadTemplatePartAndGalleryAncestors(): void {
    $image = static fn (): BlockNode => new BlockNode('core/image', ['id' => 5], '<figure class="wp-block-image"><img src="a.png" class="wp-image-5"/></figure>', []);
    $props = static fn (array $source): array => $source['source']['component']['props'];
    $child = static fn (array $source): array => $source['source']['component']['slots']['content']['sources'][0];

    $tree = $this->builder->build([
      new BlockNode('core/group', ['templatePart' => TRUE], '', [new BlockNode('core/group', [], '', [$image()])]),
      new BlockNode('core/gallery', [], '', [$image()]),
      $image(),
    ], $this->mapping);
    $part = $this->builder->build([$image()], $this->mapping, inTemplatePart: TRUE);

    self::assertSame(['source_id' => 'checkbox', 'source' => ['value' => TRUE]], $props($child($child($tree[0])))['in_template_part']);
    self::assertSame(['source_id' => 'checkbox', 'source' => ['value' => TRUE]], $props($child($tree[1]))['in_gallery']);
    self::assertArrayNotHasKey('in_template_part', $props($child($tree[1])));
    self::assertArrayNotHasKey('in_template_part', $props($tree[2]));
    self::assertArrayNotHasKey('in_gallery', $props($tree[2]));
    self::assertSame(['source_id' => 'checkbox', 'source' => ['value' => TRUE]], $props($part[0])['in_template_part'], 'A part placement decodes its images async.');
  }

  /**
   * Tests a Search block reads its path and parameter from the mapping.
   */
  public function testSearchBlockReadsMappedSearchTarget(): void {
    $mapping = new ContentMapping(['search' => ['path' => '/find', 'parameter' => 'q', 'index' => 'content']] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $tree = $this->builder->build([new BlockNode('core/search', ['label' => 'Search'], '', [])], $mapping);

    $settings = $tree[0]['source']['wordpal_search'];
    self::assertSame('/find', $settings['path']);
    self::assertSame('q', $settings['parameter']);
  }

  /**
   * Tests a Query Title reads the mapped search target.
   */
  public function testQueryTitleReadsMappedSearchTarget(): void {
    $mapping = new ContentMapping(['search' => ['path' => '/find', 'parameter' => 'q', 'index' => 'content']] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $tree = $this->builder->build([new BlockNode('core/query-title', ['type' => 'search'], '', [])], $mapping);

    $props = $tree[0]['source']['component']['props'];
    self::assertSame('q', $props['search_parameter']['source']['value']);
  }

  /**
   * Tests a number the style engine would not print is left out.
   */
  public function testNumberLineHeightIsLeftOut(): void {
    $tree = $this->builder->build([
      new BlockNode('core/post-template', ['style' => ['typography' => ['lineHeight' => 1.3]]], '', []),
    ], $this->mapping);

    self::assertArrayNotHasKey('line_height', $tree[0]['source']['component']['props']);
  }

  /**
   * Tests a runtime block plugin becomes a block source with its settings.
   */
  public function testRuntimeBlockPluginBecomesBlockSource(): void {
    $tree = $this->builder->build([
      new BlockNode('core/navigation', ['overlayMenu' => 'always', 'showSubmenuIcon' => TRUE], '', []),
    ], $this->mapping);

    self::assertSame([
      'node_id' => $tree[0]['node_id'],
      'source_id' => 'block',
      'source' => [
        'plugin_id' => 'wordpal_navigation',
        'wordpal_navigation' => [
          'show_submenu_icon' => TRUE,
          'overlay_menu' => 'always',
          'supports' => [],
          'menu' => 'main',
          'menu_label' => TRUE,
          'trailing' => [],
          'page_list' => FALSE,
        ],
      ],
    ], $tree[0]);
  }

  /**
   * Tests a Comments block becomes the wordpal_comments block source.
   */
  public function testCommentsBlockBecomesBlockSource(): void {
    $mapping = new ContentMapping(['navigation' => 'main', 'comments' => 'field_comments'] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $mapping->setThemeId('wordpal_fixture');
    $node = static fn (string $name, array $children = []): BlockNode => new BlockNode("core/$name", [], '', $children);
    $comments = new BlockNode('core/comments', [], '', [
      $node('comments-title'),
      $node('comment-template', [
        $node('avatar'),
        $node('comment-author-name'),
        $node('comment-date'),
        $node('comment-content'),
        $node('comment-reply-link'),
      ]),
      $node('comments-pagination', [
        $node('comments-pagination-previous'),
        $node('comments-pagination-next'),
      ]),
      $node('post-comments-form'),
    ]);

    $tree = $this->builder->build([$comments], $mapping);

    self::assertSame('block', $tree[0]['source_id']);
    self::assertSame('wordpal_comments', $tree[0]['source']['plugin_id']);
    $settings = $tree[0]['source']['wordpal_comments'];
    self::assertSame('field_comments', $settings['field_name']);
    self::assertSame(
      ['comments-title', 'comment-template', 'comments-pagination', 'post-comments-form'],
      array_column($settings['layout'], 'marker'),
    );
    self::assertSame(
      ['avatar', 'comment-author-name', 'comment-date', 'comment-content', 'comment-reply-link'],
      array_column($settings['layout'][1]['children'], 'marker'),
    );
  }

  /**
   * Tests a Navigation block with its own links resolves its own menu id.
   */
  public function testNavigationWithOwnLinksResolvesItsOwnMenu(): void {
    $link = static fn (string $url, string $label): BlockNode =>
      new BlockNode('core/navigation-link', ['url' => $url, 'label' => $label], '', []);
    $node = new BlockNode('core/navigation', [], '', [$link('/a', 'A'), $link('/b', 'B')]);

    $tree = $this->builder->build([$node], $this->mapping);

    $menu = $tree[0]['source']['wordpal_navigation']['menu'];
    self::assertSame(NavigationMenus::menuId('wordpal_fixture', $node), $menu);
    self::assertNotSame('main', $menu, "A Navigation block with its own links does not use the mapped menu.");
  }

  /**
   * Tests a block with no WordPal component becomes the frozen-html component.
   */
  public function testUnrenderableBlockBecomesTheFrozenHtmlComponent(): void {
    $html = '<div class="acme-rating">4.5</div>';
    $tree = $this->builder->build([
      new BlockNode('acme/rating', [], '', [], $html),
    ], $this->mapping);

    self::assertSame('wordpal:' . FrozenBlock::SLUG, $tree[0]['source']['component']['component_id']);
    self::assertSame('acme/rating', $tree[0]['source']['component']['props']['block_name']['source']['value']);
    self::assertSame(FrozenBlock::key($html), $tree[0]['source']['component']['props']['key']['source']['value']);
  }

  /**
   * Tests a Frozen block keeps an empty hole for a Query with no View.
   */
  public function testFrozenBlockKeepsHoleWithoutReplacement(): void {
    $query = new BlockNode('core/query', [], '', [new BlockNode('core/post-template', [], '', [])], '<ul></ul>');

    $tree = $this->builder->build([new BlockNode('acme/tabs', [], '', [$query], '<div><ul></ul></div>')], $this->mapping);

    self::assertSame('wordpal:' . FrozenBlock::SLUG, $tree[0]['source']['component']['component_id']);
    self::assertArrayNotHasKey('slots', $tree[0]['source']['component']);
  }

  /**
   * Tests a Frozen block the render index holds no HTML for is left out.
   */
  public function testUnrenderedFrozenBlockIsLeftOut(): void {
    $tree = $this->builder->build([new BlockNode('acme/rating', [], '', [])], $this->mapping);

    self::assertSame([], $tree);
  }

  /**
   * Tests a legacy `align` attribute redirects into `text_align`.
   *
   * Older WordPress releases aligned a Paragraph's text through the generic
   * `align` attribute; the Paragraph component's own `align` prop accepts
   * only `wide`/`full`, so `center` is not one of its values.
   */
  public function testLegacyAlignAttributeRedirectsToTextAlign(): void {
    $tree = $this->builder->build([
      new BlockNode('core/paragraph', ['align' => 'center'], '<p>Text</p>', []),
    ], $this->mapping);

    $props = $tree[0]['source']['component']['props'];
    self::assertArrayNotHasKey('align', $props);
    self::assertSame(['source_id' => 'select', 'source' => ['value' => 'center']], $props['text_align']);
  }

  /**
   * Tests a value an enum prop's own list rejects is left out.
   */
  public function testInvalidEnumValueIsLeftOut(): void {
    $tree = $this->builder->build([
      new BlockNode('core/paragraph', ['align' => 'bogus'], '<p>Text</p>', []),
    ], $this->mapping);

    $props = $tree[0]['source']['component']['props'];
    self::assertArrayNotHasKey('align', $props);
    self::assertArrayNotHasKey('text_align', $props);
  }

  /**
   * Tests an array prop goes to the list source, one item per line.
   */
  public function testArrayPropGoesToTheListSource(): void {
    $mapping = new ContentMapping(['category' => ['vocabulary' => 'topics', 'field' => 'field_topics']] + array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $mapping->setThemeId('wordpal_fixture');
    $tree = $this->builder->build([
      new BlockNode('core/post-terms', [], '', []),
    ], $mapping);

    $props = $tree[0]['source']['component']['props'];
    self::assertSame([
      'source_id' => 'list_textarea',
      'source' => ['value' => 'Journal'],
    ], $props['terms']);
    self::assertSame([
      'source_id' => 'list_textarea',
      'source' => ['value' => '/taxonomy/term/1'],
    ], $props['term_urls']);
  }

  /**
   * Tests a Spacer's unitless height and width reach the source in pixels.
   *
   * A Spacer's save() interpolates a bare height/width number straight into
   * its style attribute as pixels (spacer/save.js), the rule
   * AttributeFlattener applies before this builder ever sees the value.
   */
  public function testSpacerHeightAndWidthReachSourceInPixels(): void {
    $tree = $this->builder->build([
      new BlockNode('core/spacer', ['height' => 66, 'width' => 40], '', []),
    ], $this->mapping);

    $props = $tree[0]['source']['component']['props'];
    self::assertSame(['source_id' => 'textfield', 'source' => ['value' => '66px']], $props['height']);
    self::assertSame(['source_id' => 'textfield', 'source' => ['value' => '40px']], $props['width']);
  }

  /**
   * Tests a block bound to a concept the mapping drops yields no source.
   *
   * $this->mapping sets every concept but navigation to null: Post Terms
   * binds to "category", which TreeConcepts::concept() maps it to.
   */
  public function testDroppedConceptYieldsNoSource(): void {
    $tree = $this->builder->build([
      new BlockNode('core/post-terms', [], '', []),
    ], $this->mapping);

    self::assertSame([], $tree);
    self::assertSame(['category' => 1], $this->mapping->droppedBlocks());
  }

  /**
   * Tests a listing block bound to a dropped concept yields no View source.
   *
   * Latest Comments binds to "comments"; with no target, ListingBlocks::
   * place() never runs, so no listing View saves for it either.
   */
  public function testDroppedListingConceptYieldsNoSource(): void {
    $tree = $this->builder->build([
      new BlockNode('core/latest-comments', [], '', []),
    ], $this->mapping);

    self::assertSame([], $tree);
    self::assertSame(['comments' => 1], $this->mapping->droppedBlocks());
    self::assertSame([], View::loadMultiple(), 'No View was ever created for the dropped block.');
  }

  /**
   * Tests node ids are stable across separate builds of the same tree.
   */
  public function testNodeIdsAreStableAcrossRuns(): void {
    $nodes = [
      new BlockNode('core/paragraph', [], '<p>A</p>', []),
      new BlockNode('core/columns', [], '', [
        new BlockNode('core/column', [], '', [new BlockNode('core/paragraph', [], '<p>B</p>', [])]),
      ]),
    ];

    $first = $this->builder->build($nodes, $this->mapping);
    $second = $this->builder->build($nodes, $this->mapping);

    self::assertSame($first, $second);
    self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first[0]['node_id']);
    self::assertNotSame($first[0]['node_id'], $first[1]['node_id'], 'Two different positions get different ids.');
  }

  /**
   * Tests a root path keeps a tree's ids off another tree's own ids.
   *
   * NodeId() hashes only the path, so a home or 404 Template's own override
   * field tree and the page bundle's own shared content template would
   * otherwise land on the very same ids at matching positions: both start
   * building from an empty path, and WordPress often opens both with the
   * same block shape (a Group, then its first child). $rootPath is how
   * writeStaticPage() and writeQueryPage() keep the override field's ids
   * off the bundle's own display.
   */
  public function testRootPathKeepsIdsOffTheDefaultTree(): void {
    $nodes = [new BlockNode('core/paragraph', [], '<p>A</p>', [])];

    $default = $this->builder->build($nodes, $this->mapping);
    $rooted = $this->builder->build($nodes, $this->mapping, rootPath: ['home']);

    self::assertNotSame($default[0]['node_id'], $rooted[0]['node_id']);
  }

}
