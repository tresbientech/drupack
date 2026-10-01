<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Component\CommentBlockInputs;
use Drupal\wordpal_convert\Component\ComponentInputs;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the comments block's shared settings and layout-tree builder.
 *
 * Extracted from wordpal_canvas's BlockSettings: values() and layout() are
 * target-independent, reachable from both CanvasWriter and
 * Display Builder's SourceTreeBuilder.
 */
#[CoversClass(CommentBlockInputs::class)]
#[Group('wordpal')]
final class CommentBlockInputsTest extends UnitTestCase {

  /**
   * Returns a mapping binding the comments field these tests read.
   */
  private function mapping(): ContentMapping {
    return new ContentMapping(['comments' => 'field_comments']);
  }

  /**
   * Returns a CommentBlockInputs with no real SDC components discoverable.
   */
  private function commentBlockInputs(): CommentBlockInputs {
    $componentPluginManager = $this->createMock(ComponentPluginManager::class);
    $componentInputs = new ComponentInputs($componentPluginManager, new AttributeFlattener());
    return new CommentBlockInputs(new AttributeFlattener(), $componentPluginManager, $componentInputs);
  }

  /**
   * Tests a Comments root keeps its field, children and fragment settings.
   */
  public function testValuesKeepTheFieldAndChildren(): void {
    $node = new BlockNode('core/comments', [], '', [
      new BlockNode('core/comments-title', ['level' => 3], '', []),
    ]);
    // The plugin's own default settings carry a "layout" key too, as the
    // real block plugin's default configuration does.
    $defaults = ['field_name' => '', 'css_class' => '', 'layout' => []];
    $result = $this->commentBlockInputs()->values($node, $defaults, $this->mapping());
    self::assertSame('field_comments', $result['settings']['field_name']);
    self::assertSame(3, $result['settings']['comments_title']['level']);
    self::assertSame($node->children, $result['children']);
    self::assertArrayNotHasKey('layout', $result['settings']);
  }

  /**
   * Tests the root's and the fragments' supports reach their settings.
   */
  public function testValuesKeepTheRootSupports(): void {
    $node = new BlockNode('core/comments', [
      'className' => 'is-style-card',
      'style' => ['spacing' => ['margin' => ['top' => 'var:preset|spacing|70']]],
    ], '', [
      new BlockNode('core/comments-title', ['fontSize' => 'large'], '', []),
      new BlockNode('core/comment-template', [], '', [
        new BlockNode('core/comment-content', ['fontSize' => 'small'], '', []),
      ]),
    ]);
    $result = $this->commentBlockInputs()->values($node, ['field_name' => ''], $this->mapping());
    self::assertSame('is-style-card', $result['settings']['css_class']);
    self::assertSame('var:preset|spacing|70', $result['settings']['margin_top']);
    self::assertSame('large', $result['settings']['comments_title']['font_size']);
    self::assertSame('small', $result['settings']['comment_content']['font_size']);
  }

  /**
   * Tests a standalone form's padding stays on the form, off the wrapper.
   */
  public function testValuesKeepStandaloneFormSupportsOnTheForm(): void {
    $node = new BlockNode('core/post-comments-form', [
      'style' => ['spacing' => ['padding' => ['top' => '2rem']]],
    ], '', []);
    $result = $this->commentBlockInputs()->values($node, ['field_name' => ''], $this->mapping());
    self::assertSame('2rem', $result['settings']['post_comments_form']['padding_top']);
    self::assertArrayNotHasKey('padding_top', $result['settings']);
  }

  /**
   * Tests a legacy Comments root supplies the classic comment tree.
   */
  public function testValuesLegacyBlockSuppliesTheDefaultTree(): void {
    $result = $this->commentBlockInputs()->values(new BlockNode('core/comments', ['legacy' => TRUE], '', []), ['field_name' => ''], $this->mapping());
    self::assertSame(
      ['core/comments-title', 'core/comment-template', 'core/comments-pagination', 'core/post-comments-form'],
      array_map(static fn (BlockNode $node): string => $node->name, $result['children']),
    );
  }

  /**
   * Tests a comment fragment becomes a bare marker, keeping child order.
   */
  public function testLayoutMarksCommentFragmentsAndKeepsChildOrder(): void {
    $node = static fn (string $name, array $children = []): BlockNode => new BlockNode("core/$name", [], '', $children);

    $tree = $this->commentBlockInputs()->layout([
      $node('comments-title'),
      $node('comment-template', [$node('comment-author-name'), $node('comment-content')]),
    ]);

    self::assertSame(['comments-title', 'comment-template'], array_column($tree, 'marker'));
    self::assertNull($tree[0]['component']);
    self::assertSame(['comment-author-name', 'comment-content'], array_column($tree[1]['children'], 'marker'));
  }

  /**
   * Tests a block with no comment-fragment name freezes with its own HTML.
   */
  public function testLayoutFreezesAnUnmatchedBlockWithRenderedHtml(): void {
    $node = new BlockNode('acme/rating', [], '', [], '<div class="acme-rating">4.5</div>');

    $tree = $this->commentBlockInputs()->layout([$node]);

    self::assertNull($tree[0]['marker']);
    self::assertSame('wordpal:frozen-html', $tree[0]['component']);
    self::assertSame('acme/rating', $tree[0]['props']['block_name']);
  }

  /**
   * Tests a block whose rendered HTML fails the Frozen HTML check throws.
   *
   * A comments layout fails loud rather than silently dropping part of a
   * rendered comment, the one behavior CanvasWriter and Display Builder
   * share here.
   */
  public function testLayoutThrowsWhenFrozenHtmlFailsTheCheck(): void {
    $this->expectException(\UnexpectedValueException::class);

    $this->commentBlockInputs()->layout([new BlockNode('acme/rating', [], '', [], '<script>bad</script>')]);
  }

}
