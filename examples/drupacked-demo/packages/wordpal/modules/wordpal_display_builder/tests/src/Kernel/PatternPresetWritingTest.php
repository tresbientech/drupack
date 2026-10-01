<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Kernel;

use Drupal\display_builder\Entity\PatternPreset;
use Drupal\KernelTests\KernelTestBase;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\ThemeTrees;
use Drupal\wordpal_convert\WordPress\PlaygroundRunner;
use Drupal\wordpal_display_builder\DisplayBuilderWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Writes the fixture theme's own patterns as Display Builder presets.
 *
 * Drives DisplayBuilderWriter directly against real resolved trees from the
 * fixture snapshot (ThemeTrees::pattern()), rather than through
 * ConversionRunner, whose own full-pipeline coverage of this writer lives in
 * wordpal_convert's DisplayBuilderConversionTest.
 */
#[CoversClass(DisplayBuilderWriter::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class PatternPresetWritingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'user', 'comment', 'node', 'views', 'menu_link_content', 'filter', 'link', 'text', 'wordpal', 'wordpal_convert',
    'wordpal_display_builder', 'display_builder', 'ui_patterns', 'ui_patterns_field',
  ];

  /**
   * The writer under test.
   */
  private DisplayBuilderWriter $writer;

  /**
   * An empty Content mapping: none of the fixture's plain patterns bind one.
   */
  private ContentMapping $mapping;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ui_patterns']);
    $this->writer = $this->container->get(DisplayBuilderWriter::class);
    $this->mapping = new ContentMapping(array_fill_keys(ContentMapping::CONCEPTS, NULL));
    $this->mapping->setThemeId('wordpal_fixture');
  }

  /**
   * Returns the resolved nodes of one fixture pattern.
   */
  private function patternNodes(string $slug): array {
    $directory = dirname(__DIR__, 4) . '/wordpal_convert/tests/fixtures/snapshot/wordpal-fixture';
    $version = $this->container->get(ComponentSet::class)->wordPressVersion();
    $snapshot = $this->container->get(PlaygroundRunner::class)->load($directory, 'wordpal-fixture', $version);
    self::assertNotNull($snapshot, 'The fixture snapshot must match the pinned WordPress version.');
    return $this->container->get(ThemeTrees::class)->pattern($snapshot, $slug)->nodes;
  }

  /**
   * Tests an Inserter pattern's tree becomes a preset in the theme's group.
   */
  public function testPatternBecomesPresetInTheThemeGroup(): void {
    $preset = $this->writer->writePattern('wordpal_fixture_welcome', 'Welcome', $this->patternNodes('wordpal-fixture/welcome'), $this->mapping);

    self::assertInstanceOf(PatternPreset::class, $preset);
    self::assertSame('wordpal_fixture_welcome', $preset->id());
    self::assertSame('wordpal_fixture', $preset->getGroup(), "The preset's group must be the theme.");
    self::assertNotNull(PatternPreset::load('wordpal_fixture_welcome'));
    self::assertSame('component', $preset->getSources()['source_id'], 'A preset stores a single root source node.');
  }

  /**
   * Tests a multi-root pattern holds its top-level blocks in one Fragment.
   */
  public function testMultiRootPatternWrapsInFragmentComponent(): void {
    $nodes = [
      new BlockNode('core/paragraph', [], '<p>A</p>', []),
      new BlockNode('core/paragraph', [], '<p>B</p>', []),
    ];

    $preset = $this->writer->writePattern('wordpal_fixture_tags', 'Tags', $nodes, $this->mapping);

    $sources = $preset->getSources();
    self::assertSame('wordpal:fragment', $sources['source']['component']['component_id']);
    self::assertCount(2, $sources['source']['component']['slots']['content']['sources']);
  }

  /**
   * Tests the Fragment component prints its slot with no element of its own.
   */
  public function testFragmentPrintsOnlyItsSlot(): void {
    $build = [
      '#type' => 'component',
      '#component' => 'wordpal:fragment',
      '#slots' => ['content' => ['#markup' => '<p>A</p><p>B</p>']],
    ];

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertSame('<p>A</p><p>B</p>', trim(preg_replace('/<!--.*?-->/s', '', $html)));
  }

  /**
   * Tests a single-root pattern keeps that root, with no invented wrapper.
   */
  public function testSingleRootPatternKeepsItsOwnRoot(): void {
    $nodes = [new BlockNode('core/paragraph', [], '<p>Sample</p>', [])];

    $preset = $this->writer->writePattern('wordpal_fixture_single_root', 'Single root', $nodes, $this->mapping);

    self::assertSame('wordpal:paragraph', $preset->getSources()['source']['component']['component_id']);
  }

  /**
   * Tests a pattern whose tree builds no block fails loudly, not silently.
   *
   * ConversionRunner catches exactly \UnexpectedValueException from a
   * pattern write to skip it and list it in the report, the same contract
   * CanvasWriter's own savePattern() failures use.
   */
  public function testUnbuildablePatternThrows(): void {
    $nodes = [new BlockNode('acme/rating', [], '', [])];

    $this->expectException(\UnexpectedValueException::class);
    $this->writer->writePattern('wordpal_fixture_broken', 'Broken', $nodes, $this->mapping);
  }

  /**
   * Tests a written preset renders through the writer.
   */
  public function testWrittenPresetRendersThroughTheWriter(): void {
    $this->writer->writePattern('wordpal_fixture_welcome', 'Welcome', $this->patternNodes('wordpal-fixture/welcome'), $this->mapping);

    $build = $this->writer->renderPattern('wordpal_fixture_welcome');

    self::assertNotNull($build);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertNotSame('', trim($html));
  }

  /**
   * Tests rendering a pattern with no matching preset returns NULL.
   */
  public function testRenderingMissingPatternReturnsNull(): void {
    self::assertNull($this->writer->renderPattern('wordpal_fixture_no_such_pattern'));
  }

}
