<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_canvas\CanvasWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests blocks with no component convert as Frozen blocks.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class FrozenBlocksTest extends CanvasKernelTestBase {

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
   * A plugin block whose HTML opens with its own style tag.
   */
  private const STYLED = '<style>.acme{color:red}</style><p class="acme">Styled</p>';

  /**
   * The rendered mini-cart, as the test theme's frozen file holds it.
   */
  private const MINI_CART = '<div class="wc-block-mini-cart wp-block-woocommerce-mini-cart" style="visibility:hidden" data-wp-interactive="woocommerce/mini-cart"><button class="wc-block-mini-cart__button" aria-label="Cart"><svg class="wc-block-mini-cart__icon" viewBox="0 0 32 32"><circle cx="12" cy="26" r="2"></circle></svg></button></div>';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', [
      'wordpal:group', 'wordpal:paragraph', 'wordpal:frozen-html',
    ]);
  }

  /**
   * Tests which blocks the report lists as frozen.
   *
   * A Frozen block's inner blocks are part of its HTML, and core/navigation
   * reads its inner blocks as settings, so neither lists them. The
   * core/comments layout lists a block with no component, while its comment
   * fragment blocks, such as core/avatar, stay markers.
   */
  public function testListsFrozenCoreAndPluginBlocks(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $this->container->get(ComponentSourceManager::class)
      ->generateComponents('block', ['wordpal_navigation', 'wordpal_comments']);
    $mapping = new ContentMapping(['navigation' => 'main', 'comments' => 'field_comments']);
    $inner = new BlockNode('core/unregistered-block', [], '', []);

    self::assertSame(['core/avatar', 'woocommerce/mini-cart', 'core/avatar', 'acme/rating'], array_column($writer->frozenNodes([
      new BlockNode('core/group', [], '', [
        new BlockNode('core/avatar', [], '', []),
        new BlockNode('core/paragraph', [], '<p>Text</p>', []),
      ]),
      new BlockNode('woocommerce/mini-cart', [], '', [$inner]),
      new BlockNode('core/navigation', [], '', [$inner]),
      new BlockNode('core/avatar', [], '', []),
      new BlockNode('core/comments', [], '', [
        new BlockNode('core/comment-template', [], '', [
          new BlockNode('core/avatar', [], '', []),
          new BlockNode('acme/rating', [], '', []),
        ]),
      ]),
    ], $mapping), 'name'));
  }

  /**
   * Tests Shortcode stays a Frozen block and Custom HTML converts.
   */
  public function testShortcodeFreezesAndHtmlConverts(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:html']);

    self::assertSame(['core/shortcode'], array_column($writer->frozenNodes([
      new BlockNode('core/html', [], '<div class="map"></div>', []),
      new BlockNode('core/shortcode', [], '[gallery]', []),
    ], new ContentMapping([])), 'name'));
  }

  /**
   * Tests a core and a plugin block freeze, and their tree converts.
   */
  public function testCoreAndPluginBlocksFreezeInTheirTree(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $avatar = '<div class="wp-block-avatar"><img alt="" src="/avatar.png" class="avatar avatar-80 photo wp-block-avatar__image" style="border-radius:16px;" height="80" width="80"></div>';
    $nodes = [
      new BlockNode('core/group', [], '<div class="wp-block-group"></div>', [
        new BlockNode('core/avatar', ['size' => 80], '', [], $avatar),
        new BlockNode('core/paragraph', [], '<p>Text</p>', []),
      ]),
      new BlockNode('woocommerce/mini-cart', [], '', [
        new BlockNode('woocommerce/mini-cart-contents', [], '', [], '<div class="contents"></div>'),
      ], self::MINI_CART),
    ];
    $mapping = new ContentMapping([]);

    $pattern = $writer->writePattern('frozen_fixture', 'Frozen fixture', $nodes, $mapping);

    $items = array_values(Pattern::load($pattern->id())->get('component_tree'));
    self::assertSame(
      ['sdc.wordpal.group', 'sdc.wordpal.frozen-html', 'sdc.wordpal.paragraph', 'sdc.wordpal.frozen-html'],
      array_column($items, 'component_id'),
    );
    self::assertSame($items[0]['uuid'], $items[1]['parent_uuid'], 'The frozen avatar stays in its group.');
    self::assertSame(['block_name' => 'core/avatar', 'key' => FrozenBlock::key($avatar)], $items[1]['inputs']);
    self::assertArrayNotHasKey('parent_uuid', $items[3], 'The mini-cart is one top-level item, its inner blocks inside its HTML.');
    self::assertSame(['block_name' => 'woocommerce/mini-cart', 'key' => FrozenBlock::key(self::MINI_CART)], $items[3]['inputs']);
    self::assertSame([
      FrozenBlock::key($avatar) => $avatar,
      FrozenBlock::key(self::MINI_CART) => self::MINI_CART,
    ], $writer->frozenHtml($nodes, $mapping));
  }

  /**
   * Tests a Frozen block prints its file from the default theme, unfiltered.
   */
  public function testFrozenBlockPrintsThemeFile(): void {
    $this->container->get('theme_installer')->install(['wordpal_frozen_test']);
    $this->config('system.theme')->set('default', 'wordpal_frozen_test')->save();

    $html = $this->renderFrozen(FrozenBlock::key(self::MINI_CART));

    foreach ([
      'style="visibility:hidden"',
      'data-wp-interactive="woocommerce/mini-cart"',
      '<button class="wc-block-mini-cart__button" aria-label="Cart">',
      '<svg class="wc-block-mini-cart__icon" viewBox="0 0 32 32">',
    ] as $expected) {
      self::assertStringContainsString($expected, $html);
    }
  }

  /**
   * Tests a hole slot prints at its hole in the Frozen HTML.
   */
  public function testHoleSlotPrintsAtItsHole(): void {
    $this->container->get('theme_installer')->install(['wordpal_frozen_test']);
    $this->config('system.theme')->set('default', 'wordpal_frozen_test')->save();
    $build = $this->frozenBuild(FrozenBlock::key('<div class="tabs"><ul class="posts"></ul><p>End</p></div>'));
    $build['#slots']['hole_1'] = ['#markup' => '<ul class="view">Rows</ul>'];

    $html = trim((string) $this->container->get('renderer')->renderInIsolation($build));

    self::assertSame('<div data-wordpal-frozen="woocommerce/mini-cart" class="tabs"><ul class="view">Rows</ul><p>End</p></div>', $html);
  }

  /**
   * Tests the mark lands on the first element that draws a box, escaped.
   */
  public function testMarkSkipsLeadingStyleAndEscapesTheBlockName(): void {
    $this->container->get('theme_installer')->install(['wordpal_frozen_test']);
    $this->config('system.theme')->set('default', 'wordpal_frozen_test')->save();
    $build = $this->frozenBuild(FrozenBlock::key(self::STYLED));
    $build['#props']['block_name'] = 'acme/"quote"';

    $html = trim((string) $this->container->get('renderer')->renderInIsolation($build));

    self::assertSame('<style>.acme{color:red}</style><p data-wordpal-frozen="acme/&quot;quote&quot;" class="acme">Styled</p>', $html);
    self::assertContains('core/components.wordpal--frozen-html', $build['#attached']['library']);
  }

  /**
   * Tests a Frozen block attaches the plugin stylesheets the theme lists.
   */
  public function testFrozenBlockAttachesPluginStyles(): void {
    $this->container->get('theme_installer')->install(['wordpal_frozen_test']);
    $this->config('system.theme')->set('default', 'wordpal_frozen_test')->save();

    $build = $this->frozenBuild(FrozenBlock::key(self::MINI_CART));
    $this->container->get('renderer')->renderInIsolation($build);

    self::assertSame(['wordpal_frozen_test/plugin.wc-blocks-style-mini-cart'], self::themeLibraries($build));
    $resolved = $this->container->get('asset.resolver')->getCssAssets(
      AttachedAssets::createFromRenderArray($build),
      FALSE,
    );
    self::assertSame([
      $this->themePath() . '/styles/plugin-wc-blocks-packages-style.css',
      $this->themePath() . '/styles/plugin-wc-blocks-style-mini-cart.css',
    ], array_values(array_filter(array_keys($resolved), static fn (string $path): bool => str_contains($path, '/plugin-'))));
  }

  /**
   * Tests a Frozen block with no plugin stylesheet attaches no library.
   */
  public function testFrozenBlockWithoutPluginStylesAttachesNothing(): void {
    $this->container->get('theme_installer')->install(['wordpal_frozen_test']);
    $this->config('system.theme')->set('default', 'wordpal_frozen_test')->save();

    $build = $this->frozenBuild(FrozenBlock::key('<div class="wp-block-avatar"></div>'));
    $html = trim((string) $this->container->get('renderer')->renderInIsolation($build));

    self::assertSame('<div data-wordpal-frozen="woocommerce/mini-cart" class="wp-block-avatar"></div>', $html);
    self::assertSame([], self::themeLibraries($build));
  }

  /**
   * Tests a key an editor could set never reads another file.
   */
  public function testInvalidOrUnknownKeyRendersNothing(): void {
    $this->container->get('theme_installer')->install(['wordpal_frozen_test']);
    $this->config('system.theme')->set('default', 'wordpal_frozen_test')->save();
    $key = FrozenBlock::key(self::MINI_CART);

    foreach ([
      '../wordpal_frozen_test.info',
      '../../../../../../../../etc/passwd',
      strtoupper($key),
      $key . "\n",
      FrozenBlock::key('no such block'),
    ] as $input) {
      self::assertSame('', $this->renderFrozen($input), "Key $input must render nothing.");
    }
  }

  /**
   * Tests a block WordPress rendered as nothing still converts.
   *
   * A dynamic block with no data at conversion time, such as an avatar
   * outside any post, renders an empty string.
   */
  public function testEmptyRenderedBlockFreezes(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $nodes = [new BlockNode('core/avatar', [], '', [], '')];

    $pattern = $writer->writePattern('frozen_empty_fixture', 'Frozen empty fixture', $nodes, new ContentMapping([]));

    $items = array_values(Pattern::load($pattern->id())->get('component_tree'));
    self::assertSame(['block_name' => 'core/avatar', 'key' => FrozenBlock::key('')], $items[0]['inputs']);
  }

  /**
   * Tests a block the render index lacks stops its tree before any write.
   */
  public function testBlockMissingFromIndexWritesNothing(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $nodes = [
      new BlockNode('core/paragraph', [], '<p>Text</p>', []),
      new BlockNode('core/avatar', [], '', []),
    ];

    try {
      $writer->writePattern('frozen_missing_fixture', 'Frozen missing fixture', $nodes, new ContentMapping([]));
      self::fail('A Frozen block without rendered HTML must fail its tree.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertStringContainsString('core/avatar: the block render index holds no HTML for it', $exception->getMessage());
    }
    self::assertNull(Pattern::load('frozen_missing_fixture'));
  }

  /**
   * Tests a block whose HTML can run script stops its tree and no file.
   */
  public function testUnsafeBlockWritesNothing(): void {
    $writer = $this->container->get(CanvasWriter::class);
    $unsafe = '<div class="acme-widget"><img src="/x.png" onerror="alert(1)"></div>';
    $nodes = [
      new BlockNode('core/paragraph', [], '<p>Text</p>', []),
      new BlockNode('acme/widget', [], '', [], $unsafe),
      new BlockNode('woocommerce/mini-cart', [], '', [], self::MINI_CART),
    ];
    $mapping = new ContentMapping([]);

    self::assertSame([FrozenBlock::key(self::MINI_CART) => self::MINI_CART], $writer->frozenHtml($nodes, $mapping));
    self::assertSame([FrozenBlock::key(self::MINI_CART) => 'woocommerce/mini-cart'], $writer->frozenKeys($nodes, $mapping));
    try {
      $writer->writePattern('frozen_unsafe_fixture', 'Frozen unsafe fixture', $nodes, $mapping);
      self::fail('A Frozen block with an event handler must fail its tree.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertStringContainsString('acme/widget: its rendered HTML holds the event handler attribute onerror', $exception->getMessage());
    }
    self::assertNull(Pattern::load('frozen_unsafe_fixture'));
  }

  /**
   * Renders the frozen-html component for one key.
   */
  private function renderFrozen(string $key): string {
    $build = $this->frozenBuild($key);
    return trim((string) $this->container->get('renderer')->renderInIsolation($build));
  }

  /**
   * Returns the render array of the frozen-html component for one key.
   */
  private function frozenBuild(string $key): array {
    return [
      '#type' => 'component',
      '#component' => 'wordpal:frozen-html',
      '#props' => ['block_name' => 'woocommerce/mini-cart', 'key' => $key],
    ];
  }

  /**
   * Returns the test theme's libraries a rendered build attached.
   */
  private static function themeLibraries(array $build): array {
    return array_values(array_filter($build['#attached']['library'], static fn (string $library): bool => str_starts_with($library, 'wordpal_frozen_test/')));
  }

  /**
   * Returns the test theme's path.
   */
  private function themePath(): string {
    return $this->container->get('extension.list.theme')->getPath('wordpal_frozen_test');
  }

}
