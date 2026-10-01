<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Pattern;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_canvas\CanvasWriter;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the writer validates a Pattern as the recipe installer does.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class PatternValidationTest extends CanvasKernelTestBase {

  /**
   * Kernel modules required by WordPal and its Canvas writer.
   *
   * @var string[]
   */
  protected static $modules = [
    'comment',
    'node',
    'views',
    'wordpal',
    'wordpal_convert',
    'wordpal_canvas', 'wordpal_canvas_runtime',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ComponentSourceManager::class)->generateComponents(
      'sdc',
      ['wordpal:button', 'wordpal:group', 'wordpal:image', 'wordpal:frozen-html'],
    );
  }

  /**
   * Tests a Pattern with an invalid prop value fails before it is saved.
   */
  public function testInvalidPatternFails(): void {
    $nodes = [new BlockNode('core/button', ['url' => 'a b', 'text' => 'Go'], '', [])];

    try {
      $this->container->get(CanvasWriter::class)->writePattern('invalid_url', 'Invalid URL', $nodes, new ContentMapping([]));
      self::fail('A URL the prop schema rejects must fail the Pattern.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertStringContainsString('canvas.pattern.invalid_url is invalid', $exception->getMessage());
      self::assertStringContainsString('Invalid URL format', $exception->getMessage());
    }
    self::assertNull(Pattern::load('invalid_url'));
  }

  /**
   * Tests a WordPress "#" placeholder link writes no URL.
   */
  public function testPlaceholderLinkWritesNoUrl(): void {
    $nodes = [new BlockNode('core/button', ['url' => '#', 'text' => 'Go'], '', [])];

    $pattern = $this->container->get(CanvasWriter::class)->writePattern('placeholder_url', 'Placeholder URL', $nodes, new ContentMapping([]));

    $inputs = array_values(Pattern::load($pattern->id())->get('component_tree'))[0]['inputs'];
    self::assertArrayNotHasKey('url', $inputs);
    self::assertSame('Go', $inputs['text']);
  }

  /**
   * Tests that an empty enum attribute writes no value.
   */
  public function testEmptyEnumWritesNoValue(): void {
    $nodes = [new BlockNode('core/group', ['align' => '', 'tagName' => 'section'], '', [])];

    $pattern = $this->container->get(CanvasWriter::class)->writePattern('empty_align', 'Empty align', $nodes, new ContentMapping([]));

    $inputs = array_values(Pattern::load($pattern->id())->get('component_tree'))[0]['inputs'];
    self::assertArrayNotHasKey('align', $inputs);
    self::assertSame('section', $inputs['tag_name']);
  }

  /**
   * Tests a saved `data:` URI image source freezes the block.
   *
   * Canvas's URL formats route a "url"-suffixed prop through a link field,
   * which only resolves a routed or external scheme, not `data:`
   * (Greenshift's inline SVG icons). WordPress renders the block with its
   * data: URI intact, so the block freezes instead of guessing or dropping
   * the value.
   */
  public function testDataUriUrlFreezes(): void {
    $rendered = '<figure class="wp-block-image"><img src="data:image/svg+xml,%3Csvg/%3E" alt="Check"/></figure>';
    $nodes = [new BlockNode('core/image', ['url' => 'data:image/svg+xml,%3Csvg/%3E', 'alt' => 'Check'], '', [], $rendered)];

    $pattern = $this->container->get(CanvasWriter::class)->writePattern('data_uri_image', 'Data URI image', $nodes, new ContentMapping([]));

    $item = array_values(Pattern::load($pattern->id())->get('component_tree'))[0];
    self::assertSame('sdc.wordpal.frozen-html', $item['component_id']);
    self::assertSame(['block_name' => 'core/image', 'key' => FrozenBlock::key($rendered)], $item['inputs']);
  }

}
