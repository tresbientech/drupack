<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\wordpal_convert\Theme\AttributeSourceInterface;
use Drupal\wordpal_convert\Theme\BlockParser;

/**
 * Tests parsing WordPress block markup into block nodes.
 */
#[CoversClass(BlockParser::class)]
#[Group('wordpal')]
class BlockParserTest extends UnitTestCase {

  /**
   * Builds the parser under test, with no WordPress attributes to merge.
   */
  private function parser(): BlockParser {
    return $this->parserReturning([]);
  }

  /**
   * Builds the parser under test, returning a fixed forest for any markup.
   *
   * @param array[] $forest
   *   The forest WordPress's own parser is stubbed to have returned.
   */
  private function parserReturning(array $forest): BlockParser {
    return new BlockParser(new class($forest) implements AttributeSourceInterface {

      public function __construct(private readonly array $forest) {}

      /**
       * Returns the fixed forest, regardless of the markup asked for.
       */
      public function forest(string $html): array {
        return $this->forest;
      }

    });
  }

  /**
   * Tests that the tree and the comment attributes survive parsing.
   */
  public function testParsesTreeAndAttributes(): void {
    $markup = <<<HTML
    <!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
    <main class="wp-block-group">
      <!-- wp:paragraph {"align":"center"} -->
      <p class="has-text-align-center">Hello</p>
      <!-- /wp:paragraph -->
    </main>
    <!-- /wp:group -->
    HTML;

    $nodes = $this->parser()->parse($markup);

    $this->assertCount(1, $nodes);
    $group = $nodes[0];
    $this->assertSame('core/group', $group->name);
    $this->assertSame('group', $group->slug());
    $this->assertSame('main', $group->attributes['tagName']);
    $this->assertSame(['type' => 'constrained'], $group->attributes['layout']);

    $this->assertCount(1, $group->children);
    $paragraph = $group->children[0];
    $this->assertSame('core/paragraph', $paragraph->name);
    $this->assertSame('center', $paragraph->attributes['align']);
    $this->assertStringContainsString('Hello', $paragraph->innerHtml);
    $this->assertSame([], $paragraph->children);
  }

  /**
   * Tests that an empty JSON object stays an object.
   */
  public function testKeepsEmptyObject(): void {
    $markup = '<!-- wp:spacer {"height":"1rem","style":{"layout":{}}} /-->';

    $nodes = $this->parser()->parse($markup);

    $this->assertInstanceOf(\stdClass::class, $nodes[0]->attributes['style']['layout']);
    $this->assertSame('{"layout":{}}', json_encode($nodes[0]->attributes['style']));
  }

  /**
   * Tests that whitespace between blocks is dropped.
   */
  public function testDropsWhitespaceBetweenBlocks(): void {
    $markup = "\n\n<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n\n";

    $nodes = $this->parser()->parse($markup);

    $this->assertCount(1, $nodes);
    $this->assertSame('core/paragraph', $nodes[0]->name);
  }

  /**
   * Tests that markup outside any block is kept.
   */
  public function testKeepsMarkupOutsideBlocks(): void {
    $markup = '<hr class="loose"><!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->';

    $nodes = $this->parser()->parse($markup);

    $this->assertCount(2, $nodes);
    $this->assertSame('core/freeform', $nodes[0]->name);
    $this->assertSame('<hr class="loose">', $nodes[0]->innerHtml);
  }

  /**
   * Tests block render index keys for nested and repeated blocks.
   *
   * Top-level positions count the whitespace between blocks, as
   * WordPress's parse_blocks() does, and inner positions count inner blocks
   * only. Two identical sibling blocks keep their own entries.
   */
  public function testReadsRenderIndexByPath(): void {
    $markup = "\n<!-- wp:group --><div><!-- wp:avatar /--><!-- wp:avatar /--><!-- wp:group --><div><!-- wp:avatar /--></div><!-- /wp:group --></div><!-- /wp:group -->\n\n<!-- wp:avatar /--><!-- wp:audio /-->";
    $renders = [
      '1' => 'outer group',
      '1.0' => 'first avatar',
      '1.1' => 'second avatar',
      '1.2' => 'inner group',
      '1.2.0' => 'nested avatar',
      '3' => 'last avatar',
    ];

    $nodes = $this->parser()->parse($markup, $renders);

    self::assertSame(['core/group', 'core/avatar', 'core/audio'], array_column($nodes, 'name'));
    self::assertSame('outer group', $nodes[0]->rendered);
    self::assertSame(['first avatar', 'second avatar', 'inner group'], array_column($nodes[0]->children, 'rendered'));
    self::assertSame('nested avatar', $nodes[0]->children[2]->children[0]->rendered);
    self::assertSame('last avatar', $nodes[1]->rendered);
    self::assertNull($nodes[2]->rendered, 'A block the index lacks has no rendered HTML.');
  }

  /**
   * Tests that a matching name takes WordPress's own parsed attributes.
   */
  public function testMergesAttributesOnNameMatch(): void {
    $markup = '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">Hi</p><!-- /wp:paragraph -->';
    $forest = [
      [
        'name' => 'core/paragraph',
        'attributes' => ['content' => 'Hi', 'dropCap' => FALSE],
        'isValid' => TRUE,
        'innerBlocks' => [],
      ],
    ];

    [$node] = $this->parserReturning($forest)->parse($markup);

    $this->assertSame(['content' => 'Hi', 'dropCap' => FALSE], $node->attributes);
  }

  /**
   * Tests that a core/missing fallback keeps the grammar's own attributes.
   */
  public function testFallsBackOnUnresolvedName(): void {
    $markup = '<!-- wp:acme/widget {"title":"Hi"} /-->';
    $forest = [
      ['name' => 'core/missing', 'attributes' => ['originalContent' => '...'], 'isValid' => TRUE, 'innerBlocks' => []],
    ];

    $nodes = $this->parserReturning($forest)->parse($markup);

    $this->assertSame('acme/widget', $nodes[0]->name);
    $this->assertSame(['title' => 'Hi'], $nodes[0]->attributes);
    $this->assertTrue($nodes[0]->isValid);
  }

  /**
   * Tests that a block WordPress's parser renames becomes the renamed block.
   *
   * WordPress 6.1 renamed core/post-comments to core/comments, and its
   * parser loads an older save as core/comments with `legacy` set.
   */
  public function testTakesRenamedBlock(): void {
    $markup = '<!-- wp:post-comments /-->';
    $forest = [
      [
        'name' => 'core/comments',
        'attributes' => ['legacy' => TRUE],
        'isValid' => TRUE,
        'innerBlocks' => [
          ['name' => 'core/comment-template', 'attributes' => [], 'isValid' => TRUE, 'innerBlocks' => []],
        ],
      ],
    ];

    [$node] = $this->parserReturning($forest)->parse($markup, ['0' => '<div class="wp-block-comments"></div>']);

    $this->assertSame('core/comments', $node->name);
    $this->assertSame(['legacy' => TRUE], $node->attributes);
    $this->assertSame('<div class="wp-block-comments"></div>', $node->rendered);
    $this->assertSame(['core/comment-template'], array_map(static fn ($child) => $child->name, $node->children));
  }

  /**
   * Tests that blocks a deprecation's migrate() adds become child nodes.
   *
   * A quote saved before WordPress 6.0 holds its text as bare markup, which
   * the grammar reads as no inner blocks, while `parse()` migrates that text
   * into an inner core/paragraph. The migrated block is the current save.
   */
  public function testKeepsBlocksAddedByMigration(): void {
    $markup = '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Hi</p><cite>Me</cite></blockquote><!-- /wp:quote -->';
    $forest = [
      [
        'name' => 'core/quote',
        'attributes' => ['citation' => 'Me'],
        'isValid' => TRUE,
        'innerBlocks' => [
          [
            'name' => 'core/paragraph',
            'attributes' => ['content' => 'Hi'],
            'isValid' => TRUE,
            'innerBlocks' => [
              ['name' => 'core/list', 'attributes' => [], 'isValid' => TRUE, 'innerBlocks' => []],
            ],
          ],
        ],
      ],
    ];

    [$quote] = $this->parserReturning($forest)->parse($markup);

    $this->assertCount(1, $quote->children);
    $paragraph = $quote->children[0];
    $this->assertSame('core/paragraph', $paragraph->name);
    $this->assertSame(['content' => 'Hi'], $paragraph->attributes);
    $this->assertSame('', $paragraph->innerHtml);
    $this->assertNull($paragraph->rendered);
    $this->assertTrue($paragraph->isValid);
    $this->assertSame('core/list', $paragraph->children[0]->name);
  }

}
