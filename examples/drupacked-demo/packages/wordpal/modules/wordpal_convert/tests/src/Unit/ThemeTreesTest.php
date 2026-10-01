<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Theme\AttributeSourceInterface;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\BlockParser;
use Drupal\wordpal_convert\Theme\ReferenceExpander;
use Drupal\wordpal_convert\Theme\ThemeTrees;
use Drupal\wordpal_convert\WordPress\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests resolution of Pattern and Template block trees.
 */
#[CoversClass(ThemeTrees::class)]
#[Group('wordpal')]
final class ThemeTreesTest extends UnitTestCase {

  /**
   * The posts-per-page every test snapshot declares.
   */
  private const POSTS_PER_PAGE = 10;

  /**
   * Temporary snapshot directory.
   */
  private string $directory;

  /**
   * Area tags of template parts, keyed by slug; other parts print div.
   *
   * @var array<string, string>
   */
  private array $partTags = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = sys_get_temp_dir() . '/wordpal-theme-trees-' . bin2hex(random_bytes(6));
    foreach (['templates', 'parts', 'patterns'] as $name) {
      mkdir($this->directory . '/' . $name, 0777, TRUE);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->directory);
    parent::tearDown();
  }

  /**
   * Tests that a valid reference expands in place.
   */
  public function testExpandsValidReference(): void {
    $snapshot = $this->patternSnapshot([
      'fixture--outer' => '<!-- wp:pattern {"slug":"fixture/inner"} /-->',
      'fixture--inner' => '<!-- wp:paragraph --><p>Inner</p><!-- /wp:paragraph -->',
    ]);

    $resolved = $this->trees()->pattern($snapshot, 'fixture/outer');

    self::assertCount(1, $resolved->nodes);
    self::assertSame('core/paragraph', $resolved->nodes[0]->name);
    self::assertSame(['slug' => 'fixture/outer', 'title' => 'fixture/outer', 'categories' => [], 'inserter' => TRUE], $resolved->metadata, "metadata is the snapshot's record for this slug.");
  }

  /**
   * Tests that a missing reference renders nothing, as in WordPress.
   *
   * The slug carries a leading space, as YITH Wonder's headers do.
   */
  public function testMissingReferenceRendersNothing(): void {
    $snapshot = $this->patternSnapshot([
      'fixture--outer' => '<!-- wp:pattern {"slug":" fixture/inner"} /--><!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->',
      'fixture--inner' => '<!-- wp:paragraph --><p>Inner</p><!-- /wp:paragraph -->',
    ]);

    $resolved = $this->trees()->pattern($snapshot, 'fixture/outer');

    self::assertSame(['core/paragraph'], array_column($resolved->nodes, 'name'));
    self::assertStringContainsString('Kept', $resolved->nodes[0]->innerHtml);
  }

  /**
   * Tests that a reference cycle reports its full path.
   */
  public function testRejectsCyclicReference(): void {
    $snapshot = $this->patternSnapshot([
      'fixture--one' => '<!-- wp:pattern {"slug":"fixture/two"} /-->',
      'fixture--two' => '<!-- wp:pattern {"slug":"fixture/one"} /-->',
    ]);

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Cyclic pattern reference: fixture/one -> fixture/two -> fixture/one.');
    $this->trees()->pattern($snapshot, 'fixture/one');
  }

  /**
   * Tests that a reference without a slug renders nothing.
   */
  public function testEmptyReferenceRendersNothing(): void {
    $snapshot = $this->patternSnapshot([
      'fixture--outer' => '<!-- wp:pattern /--><!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->',
    ]);

    self::assertSame(['core/paragraph'], array_column($this->trees()->pattern($snapshot, 'fixture/outer')->nodes, 'name'));
  }

  /**
   * Tests that nodes() parses a fixture blob and expands its references.
   *
   * This is the parity fixture path: raw HTML in, expanded nodes out, each
   * carrying the render the caller passed for its position.
   */
  public function testNodesExpandsFixtureBlob(): void {
    $snapshot = $this->patternSnapshot([
      'fixture--inner' => '<!-- wp:paragraph --><p>Inner</p><!-- /wp:paragraph -->',
    ]);
    $html = '<!-- wp:pattern {"slug":"fixture/inner"} /--><!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->';

    $nodes = $this->trees()->nodes($snapshot, $html, ['1' => '<p>Rendered</p>']);

    self::assertSame(['core/paragraph', 'core/paragraph'], array_column($nodes, 'name'));
    self::assertSame('<p>Rendered</p>', $nodes[1]->rendered);
  }

  /**
   * Tests that a nested template part retains its wrapper and children.
   */
  public function testExpandsTemplatePart(): void {
    file_put_contents(
      $this->directory . '/parts/header.html',
      '<!-- wp:paragraph --><p>Header</p><!-- /wp:paragraph -->',
    );
    $snapshot = $this->patternSnapshot([
      'fixture--outer' => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->',
    ]);

    $nodes = $this->trees()->pattern($snapshot, 'fixture/outer')->nodes;

    self::assertSame('core/group', $nodes[0]->name);
    self::assertSame('header', $nodes[0]->attributes['tagName']);
    self::assertTrue($nodes[0]->attributes['templatePart']);
    self::assertSame('core/paragraph', $nodes[0]->children[0]->name);
  }

  /**
   * Tests rendered HTML follows each block through expanded references.
   *
   * The inner pattern is referenced twice, so both copies carry the entry
   * of the inner pattern's own block. A block inside a template part
   * carries the part's entry for its nested path, and a block of a pattern
   * the part references carries that pattern's entry.
   */
  public function testRenderedHtmlFollowsReferences(): void {
    file_put_contents($this->directory . '/parts/sidebar.html', '<!-- wp:group --><div><!-- wp:avatar /--><!-- wp:pattern {"slug":"fixture/inner"} /--></div><!-- /wp:group -->');
    $snapshot = $this->patternSnapshot([
      'fixture--outer' => '<!-- wp:pattern {"slug":"fixture/inner"} /--><!-- wp:template-part {"slug":"sidebar"} /--><!-- wp:pattern {"slug":"fixture/inner"} /-->',
      'fixture--inner' => '<!-- wp:audio /-->',
    ], [
      'patterns/fixture--inner.html' => ['0' => '<figure class="wp-block-audio"></figure>'],
      'parts/sidebar.html' => ['0' => '<div><img class="avatar"></div>', '0.0' => '<img class="avatar">'],
    ]);

    $nodes = $this->trees()->pattern($snapshot, 'fixture/outer')->nodes;

    self::assertSame(['core/audio', 'core/group', 'core/audio'], array_column($nodes, 'name'));
    self::assertSame('<figure class="wp-block-audio"></figure>', $nodes[0]->rendered);
    self::assertSame('<figure class="wp-block-audio"></figure>', $nodes[2]->rendered);
    self::assertNull($nodes[1]->rendered, 'The template part wrapper is no block of the index.');
    self::assertSame('<div><img class="avatar"></div>', $nodes[1]->children[0]->rendered);
    self::assertSame('<img class="avatar">', $nodes[1]->children[0]->children[0]->rendered);
    self::assertSame('core/audio', $nodes[1]->children[0]->children[1]->name);
    self::assertSame('<figure class="wp-block-audio"></figure>', $nodes[1]->children[0]->children[1]->rendered);
  }

  /**
   * Tests that a Pattern's Query blocks page from pager element 1.
   */
  public function testPatternLoopsStartAtPagerElementOne(): void {
    $snapshot = $this->patternSnapshot([
      'fixture--outer' => '<!-- wp:query {"query":{"perPage":3}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->',
    ]);

    $loops = $this->trees()->pattern($snapshot, 'fixture/outer')->loops;

    self::assertCount(1, $loops);
    self::assertSame(1, $loops[0]->pagerElement);
  }

  /**
   * Tests that top-level parts stay out and nested references expand.
   */
  public function testReturnsExpandedMainOnly(): void {
    file_put_contents($this->directory . '/templates/single.html', '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main><!-- wp:template-part {"slug":"meta"} /--><!-- wp:pattern {"slug":"fixture/more"} /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->');
    file_put_contents($this->directory . '/parts/meta.html', '<!-- wp:paragraph --><p>by</p><!-- /wp:paragraph --><!-- wp:post-date /-->');
    file_put_contents($this->directory . '/patterns/fixture--more.html', '<!-- wp:post-title /-->');
    file_put_contents($this->directory . '/patterns.json', json_encode([
      'fixture--more' => ['slug' => 'fixture/more', 'title' => 'Fixture', 'categories' => [], 'inserter' => TRUE],
    ], JSON_THROW_ON_ERROR));
    $snapshot = $this->snapshot();

    $nodes = $this->trees()->template($snapshot, 'single')->nodes;

    self::assertCount(1, $nodes);
    self::assertSame('main', $nodes[0]->attributes['tagName']);
    self::assertSame(['core/group', 'core/post-title'], array_column($nodes[0]->children, 'name'));
    self::assertTrue($nodes[0]->children[0]->attributes['templatePart']);
    self::assertSame(['core/paragraph', 'core/post-date'], array_column($nodes[0]->children[0]->children, 'name'));
  }

  /**
   * Tests blocks between the parts form the main area without a main group.
   */
  public function testMainAreaWithoutMainGroup(): void {
    file_put_contents($this->directory . '/templates/front-page.html', '<!-- wp:template-part {"slug":"header"} /--><!-- wp:pattern {"slug":"fixture/hero"} /--><!-- wp:group --><div class="wp-block-group"><!-- wp:post-content /--></div><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->');
    file_put_contents($this->directory . '/patterns/fixture--hero.html', '<!-- wp:heading --><h2 class="wp-block-heading">Hero</h2><!-- /wp:heading -->');
    file_put_contents($this->directory . '/patterns.json', json_encode([
      'fixture--hero' => ['slug' => 'fixture/hero', 'title' => 'Fixture', 'categories' => [], 'inserter' => TRUE],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($this->directory . '/parts/header.html', '');
    file_put_contents($this->directory . '/parts/footer.html', '');
    $snapshot = $this->snapshot();

    $resolved = $this->trees()->template($snapshot, 'front-page');

    self::assertSame(['core/heading', 'core/group'], array_column($resolved->nodes, 'name'));
    self::assertSame(['part', 'messages', 'marker', 'part'], array_column($resolved->partSet->placements, 'type'));
  }

  /**
   * Tests page content on both sides of a part, or none, fails.
   */
  public function testRejectsSplitOrMissingMainArea(): void {
    file_put_contents($this->directory . '/templates/single.html', '<!-- wp:post-title /--><!-- wp:template-part {"slug":"meta"} /--><!-- wp:post-content /-->');
    file_put_contents($this->directory . '/templates/page.html', '<!-- wp:template-part {"slug":"header"} /--><!-- wp:template-part {"slug":"footer"} /-->');
    file_put_contents($this->directory . '/parts/meta.html', '');
    file_put_contents($this->directory . '/parts/header.html', '');
    file_put_contents($this->directory . '/parts/footer.html', '');
    $this->partTags = ['header' => 'header', 'footer' => 'footer'];
    $snapshot = $this->snapshot();

    try {
      $this->trees()->template($snapshot, 'single');
      self::fail('Content on both sides of a part must fail.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertSame('Template single holds page content on both sides of a template part.', $exception->getMessage());
    }
    $this->expectExceptionMessage('Template page holds no page content between its template parts.');
    $this->trees()->template($snapshot, 'page');
  }

  /**
   * Tests a template of parts alone takes its uncategorized part as content.
   */
  public function testUncategorizedPartHoldsTheMainArea(): void {
    file_put_contents($this->directory . '/templates/single.html', '<!-- wp:template-part {"slug":"header","tagName":"header"} /--><!-- wp:template-part {"slug":"single"} /--><!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->');
    file_put_contents($this->directory . '/parts/header.html', '');
    file_put_contents($this->directory . '/parts/single.html', '<!-- wp:post-content /-->');
    file_put_contents($this->directory . '/parts/footer.html', '');
    $this->partTags = ['header' => 'header', 'footer' => 'footer'];
    $snapshot = $this->snapshot();

    $resolved = $this->trees()->template($snapshot, 'single');

    self::assertSame(['core/group'], array_column($resolved->nodes, 'name'), 'The part expands to the group that prints its wrapper.');
    self::assertSame(['part', 'messages', 'marker', 'part'], array_column($resolved->partSet->placements, 'type'));
  }

  /**
   * Tests that a part reference with no tagName prints its area's tag.
   */
  public function testTemplatePartTakesAreaTag(): void {
    file_put_contents($this->directory . '/templates/single.html', '<!-- wp:group {"tagName":"main"} --><main><!-- wp:template-part {"slug":"meta"} /--><!-- wp:template-part {"slug":"notes","tagName":"section"} /--><!-- wp:template-part {"slug":"meta","tagName":""} /--></main><!-- /wp:group -->');
    file_put_contents($this->directory . '/parts/meta.html', '<!-- wp:post-date /-->');
    file_put_contents($this->directory . '/parts/notes.html', '<!-- wp:post-date /-->');
    $this->partTags = ['meta' => 'aside', 'notes' => 'aside'];

    $nodes = $this->trees()->template($this->snapshot(), 'single')->nodes;

    // An empty tagName takes the area tag too (template-part.php:156).
    self::assertSame(['aside', 'section', 'aside'], array_map(static fn ($part): string => $part->attributes['tagName'], $nodes[0]->children));
  }

  /**
   * Tests canonical page frames and template-part wrapper attributes.
   */
  public function testReturnsSharedPartSet(): void {
    $frame = '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /--><!-- wp:group {"tagName":"main"} --><main></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->';
    file_put_contents($this->directory . '/templates/single.html', $frame);
    file_put_contents($this->directory . '/templates/home.html', '<!-- wp:template-part {"tagName":"header","area":"header","slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main></main><!-- /wp:group --><!-- wp:template-part {"tagName":"footer","area":"footer","slug":"footer"} /-->');
    file_put_contents($this->directory . '/parts/header.html', '<!-- wp:paragraph --><p>Header</p><!-- /wp:paragraph -->');
    file_put_contents($this->directory . '/parts/footer.html', '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->');
    $snapshot = $this->snapshot();

    $single = $this->trees()->template($snapshot, 'single')->partSet;
    $home = $this->trees()->template($snapshot, 'home')->partSet;

    self::assertSame($single->id('fixture'), $home->id('fixture'));
    self::assertSame(['part', 'messages', 'marker', 'part'], array_column($single->placements, 'type'));
    self::assertSame('header', $single->placements[0]['nodes'][0]->attributes['tagName']);
    self::assertTrue($single->placements[0]['nodes'][0]->attributes['templatePart']);
    self::assertSame('footer', $single->placements[3]['nodes'][0]->attributes['tagName']);
  }

  /**
   * Tests a template whose file holds only a pattern reference.
   */
  public function testReadsPageFrameFromTopLevelPattern(): void {
    file_put_contents($this->directory . '/templates/home.html', '<!-- wp:pattern {"slug":"fixture/outer"} /-->');
    file_put_contents($this->directory . '/patterns/fixture--outer.html', '<!-- wp:pattern {"slug":"fixture/blog"} /-->');
    file_put_contents($this->directory . '/patterns/fixture--blog.html', '<!-- wp:template-part {"slug":"header","tagName":"header"} /--><!-- wp:group {"tagName":"main"} --><main><!-- wp:pattern {"slug":"fixture/title"} /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->');
    file_put_contents($this->directory . '/patterns/fixture--title.html', '<!-- wp:post-title /-->');
    file_put_contents($this->directory . '/patterns.json', json_encode([
      'fixture--outer' => ['slug' => 'fixture/outer', 'title' => 'Fixture', 'categories' => [], 'inserter' => TRUE],
      'fixture--blog' => ['slug' => 'fixture/blog', 'title' => 'Fixture', 'categories' => [], 'inserter' => TRUE],
      'fixture--title' => ['slug' => 'fixture/title', 'title' => 'Fixture', 'categories' => [], 'inserter' => TRUE],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($this->directory . '/parts/header.html', '<!-- wp:paragraph --><p>Header</p><!-- /wp:paragraph -->');
    file_put_contents($this->directory . '/parts/footer.html', '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->');
    $snapshot = $this->snapshot();

    $resolved = $this->trees()->template($snapshot, 'home');

    self::assertSame('main', $resolved->nodes[0]->attributes['tagName']);
    self::assertSame(['core/post-title'], array_column($resolved->nodes[0]->children, 'name'));
    self::assertSame(['part', 'messages', 'marker', 'part'], array_column($resolved->partSet->placements, 'type'));
    self::assertSame(['header', 'footer'], array_column(array_filter($resolved->partSet->placements, static fn (array $placement): bool => $placement['type'] === 'part'), 'slug'));
  }

  /**
   * Tests that a template's top-level pattern cannot include itself.
   */
  public function testRejectsCyclicTopLevelPattern(): void {
    file_put_contents($this->directory . '/templates/home.html', '<!-- wp:pattern {"slug":"fixture/loop"} /-->');
    file_put_contents($this->directory . '/patterns/fixture--loop.html', '<!-- wp:pattern {"slug":"fixture/loop"} /-->');
    file_put_contents($this->directory . '/patterns.json', json_encode([
      'fixture--loop' => ['slug' => 'fixture/loop', 'title' => 'Fixture', 'categories' => [], 'inserter' => TRUE],
    ], JSON_THROW_ON_ERROR));

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('fixture/loop -> fixture/loop');
    $this->trees()->template($this->snapshot(), 'home');
  }

  /**
   * Tests that a pattern inside the main area cannot include its frame.
   */
  public function testRejectsFrameIncludedFromMain(): void {
    file_put_contents($this->directory . '/templates/home.html', '<!-- wp:pattern {"slug":"fixture/blog"} /-->');
    file_put_contents($this->directory . '/patterns/fixture--blog.html', '<!-- wp:group {"tagName":"main"} --><main><!-- wp:pattern {"slug":"fixture/blog"} /--></main><!-- /wp:group -->');
    file_put_contents($this->directory . '/patterns.json', json_encode([
      'fixture--blog' => ['slug' => 'fixture/blog', 'title' => 'Fixture', 'categories' => [], 'inserter' => TRUE],
    ], JSON_THROW_ON_ERROR));

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Cyclic pattern reference: fixture/blog -> fixture/blog.');
    $this->trees()->template($this->snapshot(), 'home');
  }

  /**
   * Tests that a missing top-level pattern renders nothing.
   */
  public function testSkipsMissingTopLevelPattern(): void {
    file_put_contents($this->directory . '/templates/home.html', '<!-- wp:pattern {"slug":"fixture/missing"} /--><!-- wp:group {"tagName":"main"} --><main></main><!-- /wp:group -->');
    file_put_contents($this->directory . '/patterns.json', '{}');

    $resolved = $this->trees()->template($this->snapshot(), 'home');

    self::assertSame('main', $resolved->nodes[0]->attributes['tagName']);
    self::assertSame(['messages', 'marker'], array_column($resolved->partSet->placements, 'type'));
  }

  /**
   * Tests that a template part missing from the theme renders nothing.
   */
  public function testSkipsMissingTemplatePart(): void {
    file_put_contents($this->directory . '/templates/single.html', '<!-- wp:template-part {"slug":"missing"} /--><!-- wp:group {"tagName":"main"} --><main></main><!-- /wp:group -->');
    $snapshot = $this->snapshot();

    $resolved = $this->trees()->template($snapshot, 'single');

    self::assertSame(['part', 'messages', 'marker'], array_column($resolved->partSet->placements, 'type'));
    self::assertSame([], $resolved->partSet->placements[0]['nodes']);
    self::assertSame('main', $resolved->nodes[0]->attributes['tagName']);
  }

  /**
   * Tests that nested template parts cannot recurse forever.
   */
  public function testRejectsCyclicTemplateParts(): void {
    file_put_contents($this->directory . '/templates/single.html', '<!-- wp:group {"tagName":"main"} --><main><!-- wp:template-part {"slug":"meta"} /--></main><!-- /wp:group -->');
    file_put_contents($this->directory . '/parts/meta.html', '<!-- wp:template-part {"slug":"details"} /-->');
    file_put_contents($this->directory . '/parts/details.html', '<!-- wp:template-part {"slug":"meta"} /-->');
    $snapshot = $this->snapshot();

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('meta -> details -> meta');
    $this->trees()->template($snapshot, 'single');
  }

  /**
   * Tests that a Template's main-area Query blocks page from pager element 1.
   */
  public function testTemplateLoopsStartAtPagerElementOne(): void {
    file_put_contents($this->directory . '/templates/single.html', '<!-- wp:group {"tagName":"main"} --><main><!-- wp:query {"query":{"perPage":3}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query --></main><!-- /wp:group -->');
    $snapshot = $this->snapshot();

    $loops = $this->trees()->template($snapshot, 'single')->loops;

    self::assertCount(1, $loops);
    self::assertSame(1, $loops[0]->pagerElement);
  }

  /**
   * Tests that a Template's Part set id is stable across two resolutions.
   */
  public function testPartSetIdIsStableAcrossResolutions(): void {
    file_put_contents($this->directory . '/templates/single.html', '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /--><!-- wp:group {"tagName":"main"} --><main></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->');
    file_put_contents($this->directory . '/parts/header.html', '<!-- wp:paragraph --><p>Header</p><!-- /wp:paragraph -->');
    file_put_contents($this->directory . '/parts/footer.html', '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->');
    $snapshot = $this->snapshot();

    $first = $this->trees()->template($snapshot, 'single');
    $second = $this->trees()->template($snapshot, 'single');

    self::assertSame($first->partSet->id('fixture'), $second->partSet->id('fixture'));
  }

  /**
   * Tests that a Part set id differs when a part's attributes differ.
   */
  public function testPartSetIdDiffersWhenPartAttributesDiffer(): void {
    file_put_contents($this->directory . '/templates/single.html', '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /--><!-- wp:group {"tagName":"main"} --><main></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->');
    file_put_contents($this->directory . '/templates/variant.html', '<!-- wp:template-part {"slug":"header","area":"header","tagName":"div"} /--><!-- wp:group {"tagName":"main"} --><main></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->');
    file_put_contents($this->directory . '/parts/header.html', '<!-- wp:paragraph --><p>Header</p><!-- /wp:paragraph -->');
    file_put_contents($this->directory . '/parts/footer.html', '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->');
    $snapshot = $this->snapshot();

    $single = $this->trees()->template($snapshot, 'single');
    $variant = $this->trees()->template($snapshot, 'variant');

    self::assertNotSame($single->partSet->id('fixture'), $variant->partSet->id('fixture'));
  }

  /**
   * Tests that a Button's width preset resolves from the theme's sizes.
   */
  public function testResolvesButtonWidthPreset(): void {
    $snapshot = $this->snapshot();
    file_put_contents($this->directory . '/settings.json', json_encode([
      'layout' => ['wideSize' => '1200px'],
      'position' => ['sticky' => FALSE, 'fixed' => FALSE],
      'duotone_filters' => [],
      'use_root_padding_aware_alignments' => FALSE,
      'block_gap_support' => FALSE,
      'block_gaps' => [],
      'root_block_gap' => NULL,
      'button_widths' => ['33' => '33%', 'wide' => '20rem'],
      'typography' => ['fluid' => FALSE],
      'styled_variations' => [],
      'registered_variations' => [],
    ], JSON_THROW_ON_ERROR));
    $expander = new ReferenceExpander(new BlockParser($this->createStub(AttributeSourceInterface::class)));
    $width = static fn (string $value): mixed => $expander->expand([new BlockNode('core/button', ['style' => ['dimensions' => ['width' => $value]]], '', [])], $snapshot)[0]->attributes['style']['dimensions']['width'];

    self::assertSame('33%', $width('var:preset|dimension|33'));
    self::assertSame('var:preset|dimension|wide', $width('var:preset|dimension|wide'));
    self::assertSame('var:preset|dimension|missing', $width('var:preset|dimension|missing'));
    self::assertSame('40%', $width('40%'));
  }

  /**
   * Builds the service under test.
   *
   * A pattern and frame resolution do not depend on WordPress's own parsed
   * attributes, so the block parser gets no real WordPress markup to run.
   */
  private function trees(): ThemeTrees {
    $parser = new BlockParser(new class implements AttributeSourceInterface {

      /**
       * No real markup to parse.
       */
      public function forest(string $html): array {
        return [];
      }

    });
    return new ThemeTrees($parser, new ReferenceExpander($parser));
  }

  /**
   * Creates a snapshot from pattern markup keyed by snapshot file name.
   *
   * @param array<string, string> $patterns
   *   Pattern markup keyed by snapshot file name.
   * @param array<string, array<string, string>> $renders
   *   Block render index entries keyed by snapshot path. Every other file
   *   gets an empty entry.
   */
  private function patternSnapshot(array $patterns, array $renders = []): Snapshot {
    $metadata = [];
    foreach ($patterns as $name => $markup) {
      $slug = str_replace('--', '/', $name);
      $metadata[$name] = ['slug' => $slug, 'title' => $slug, 'categories' => [], 'inserter' => TRUE];
      file_put_contents($this->directory . "/patterns/$name.html", $markup);
    }
    file_put_contents($this->directory . '/patterns.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    $index = [];
    foreach (['patterns', 'parts'] as $directory) {
      foreach (glob("$this->directory/$directory/*.html") ?: [] as $file) {
        $index["$directory/" . basename($file)] = $renders["$directory/" . basename($file)] ?? [];
      }
    }
    file_put_contents($this->directory . '/render-index.json', json_encode($index, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));
    $this->writeMetadata();
    return new Snapshot('fixture', 'Fixture', '1.0', '7.1.1', $this->directory);
  }

  /**
   * Returns the test snapshot, with an empty render index for each file.
   */
  private function snapshot(): Snapshot {
    $index = [];
    foreach (['templates', 'parts', 'patterns'] as $directory) {
      foreach (glob("$this->directory/$directory/*.html") ?: [] as $file) {
        $index["$directory/" . basename($file)] = [];
      }
    }
    file_put_contents($this->directory . '/render-index.json', json_encode($index, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));
    $this->writeMetadata();
    return new Snapshot('fixture', 'Fixture', '1', '7.1.1', $this->directory);
  }

  /**
   * Writes metadata.json and an empty blocks.json every Snapshot needs.
   */
  private function writeMetadata(): void {
    $metadata = ['part_tags' => $this->partTagsFromDisk(), 'posts_per_page' => self::POSTS_PER_PAGE];
    file_put_contents($this->directory . '/metadata.json', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));
    file_put_contents($this->directory . '/blocks.json', '{}');
  }

  /**
   * Returns the area tag of each template part file on disk.
   *
   * @return array<string, string>
   *   Area tags keyed by part slug.
   */
  private function partTagsFromDisk(): array {
    $partTags = [];
    foreach (glob("$this->directory/parts/*.html") ?: [] as $file) {
      $partTags[basename($file, '.html')] = $this->partTags[basename($file, '.html')] ?? 'div';
    }
    return $partTags;
  }

}
