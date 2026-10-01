<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal\Component\PropName;
use Drupal\wordpal\Support\BlockSupports;
use Drupal\wordpal\Support\LayoutCss;
use Drupal\wordpal_convert\Theme\AttributeSourceInterface;
use Drupal\wordpal_convert\Theme\BlockParser;
use Drupal\wordpal\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests WordPress block support output.
 */
#[CoversClass(AttributeFlattener::class)]
#[CoversClass(BlockSupports::class)]
#[CoversClass(LayoutCss::class)]
#[Group('wordpal')]
class BlockSupportsTest extends UnitTestCase {

  /**
   * Tests support output against a WordPress-rendered theme pattern.
   */
  public function testMatchesWordPressFixture(): void {
    $directory = dirname(__DIR__, 2) . '/fixtures/components';
    $raw = (string) file_get_contents($directory . '/cta-pricing.html');
    $rendered = (string) file_get_contents($directory . '/cta-pricing.rendered.html');
    $node = (new BlockParser(new class implements AttributeSourceInterface {

      /**
       * No real markup to parse.
       */
      public function forest(string $html): array {
        return [];
      }

    }))->parse($raw)[0];
    $props = (new AttributeFlattener())->flatten($node->attributes, $node->name);
    $output = $this->supports()->render($node->name, $props);

    $actual = sprintf(
      '<div class="wp-block-group %s" style="%s"></div>',
      implode(' ', $output->classes),
      $output->style(),
    );

    $this->assertSame($this->wrapperAttributes($this->rootElement($rendered)), $this->wrapperAttributes($actual));
    $this->assertLayoutRulesMatch($output->cssRules, $rendered, (string) file_get_contents($directory . '/block-supports.css'));
  }

  /**
   * Tests that a one-sided block gap falls back for the missing side.
   */
  public function testOneSidedBlockGapFallsBackForMissingSide(): void {
    $props = (new AttributeFlattener())->flatten([
      'style' => ['spacing' => ['blockGap' => ['top' => '2rem']]],
    ], 'core/group');

    self::assertSame('2rem var(--wp--style--block-gap, 0.5em)', $props['block_gap']);
  }

  /**
   * Tests that the half-set gap reaches the CSS with the theme's gap.
   */
  public function testHalfSetGapPrintsTheThemeGapForTheMissingSide(): void {
    $props = (new AttributeFlattener())->flatten([
      'layout' => ['type' => 'flex'],
      'style' => ['spacing' => ['blockGap' => ['left' => 'var:preset|spacing|50']]],
    ], 'core/group');
    $output = $this->supports()->render('core/group', $props);

    self::assertStringContainsString('gap:var(--wp--style--block-gap, 0.5em) var(--wp--preset--spacing--50);', implode('', $output->cssRules));
  }

  /**
   * Tests the first styled variation alone decides which gap a block reads.
   */
  public function testOnlyTheFirstStyledVariationSetsTheGap(): void {
    $layout = new LayoutCss($this->themeSettings(TRUE, [
      'core/group' => '3rem',
      'core/group/b' => '5rem',
    ]));
    $props = (new AttributeFlattener())->flatten([
      'layout' => ['type' => 'flex'],
      'style' => ['spacing' => ['blockGap' => ['top' => '1rem']]],
    ], 'core/group');

    self::assertStringContainsString('gap:1rem 3rem;', implode('', $layout->build('core/group', $props + ['css_class' => 'is-style-a is-style-b'])->cssRules));
  }

  /**
   * Tests a registered variation without a styled gap shadows a later one.
   */
  public function testRegisteredVariationWithoutGapResolvesFirst(): void {
    $layout = new LayoutCss($this->themeSettings(TRUE, [
      'core/group' => '3rem',
      'core/group/b' => '5rem',
    ]));
    $props = (new AttributeFlattener())->flatten([
      'layout' => ['type' => 'flex'],
      'style' => ['spacing' => ['blockGap' => ['top' => '1rem']]],
    ], 'core/group');

    self::assertStringContainsString('gap:1rem 3rem;', implode('', $layout->build('core/group', $props + ['css_class' => 'is-style-c is-style-b'])->cssRules));
  }

  /**
   * Tests a variation the style registry lacks sets no gap.
   *
   * WordPress reads block.json variations apart from the registry, and
   * layout.php sees only the registry.
   */
  public function testVariationOutsideTheRegistrySetsNoGap(): void {
    $layout = new LayoutCss($this->themeSettings(TRUE, [
      'core/group' => '3rem',
      'core/group/pill' => '9rem',
    ]));
    $props = (new AttributeFlattener())->flatten([
      'layout' => ['type' => 'flex'],
      'style' => ['spacing' => ['blockGap' => ['top' => '1rem']]],
    ], 'core/group');

    self::assertStringContainsString('gap:1rem 3rem;', implode('', $layout->build('core/group', $props + ['css_class' => 'is-style-pill'])->cssRules));
  }

  /**
   * Tests a half-set gap reads the missing side from an array gap.
   */
  public function testHalfSetGapReadsTheSideOfAnArrayGap(): void {
    $layout = new LayoutCss($this->themeSettings(TRUE, ['core/group' => ['top' => '2rem', 'left' => '4rem']]));
    $props = (new AttributeFlattener())->flatten([
      'layout' => ['type' => 'flex'],
      'style' => ['spacing' => ['blockGap' => ['top' => '1rem']]],
    ], 'core/group');

    self::assertStringContainsString('gap:1rem 4rem;', implode('', $layout->build('core/group', $props)->cssRules));
  }

  /**
   * Tests that a half-set gap takes the block type's or variation's own gap.
   */
  public function testHalfSetGapTakesTheBlockTypesOwnGap(): void {
    $layout = new LayoutCss($this->themeSettings(TRUE, [
      'core/group' => '3rem',
      'core/group/section' => 'var:preset|spacing|60',
    ]));
    $props = (new AttributeFlattener())->flatten([
      'layout' => ['type' => 'flex'],
      'style' => ['spacing' => ['blockGap' => ['top' => '1rem']]],
    ], 'core/group');

    self::assertStringContainsString('gap:1rem 3rem;', implode('', $layout->build('core/group', $props)->cssRules));
    self::assertStringContainsString('gap:1rem var(--wp--preset--spacing--60);', implode('', $layout->build('core/group', $props + ['css_class' => 'is-style-section'])->cssRules));
    self::assertStringContainsString('gap:1rem var(--wp--style--block-gap, 0.5em);', implode('', $layout->build('core/columns', $props)->cssRules));
  }

  /**
   * Tests a grid without block gap support falls back to the root gap value.
   */
  public function testGridWithoutGapSupportFallsBackToTheRootGap(): void {
    $grid = ['layout_type' => 'grid', 'column_count' => 3, 'minimum_column_width' => '12rem'];

    $layout = new LayoutCss($this->themeSettings(FALSE, [], '2rem'));
    self::assertStringContainsString('(100% - (2rem * (3 - 1))) /3)', implode('', $layout->build('core/post-template', $grid)->cssRules));

    $layout = new LayoutCss($this->themeSettings(FALSE, [], ['top' => '1rem', 'left' => '4rem']));
    self::assertStringContainsString('(100% - (4rem * (3 - 1))) /3)', implode('', $layout->build('core/post-template', $grid)->cssRules));
  }

  /**
   * Tests that a grid with a column count and a minimum width shrinks.
   */
  public function testGridWithCountAndMinimumWidthPrintsWordPressFormula(): void {
    $layout = $this->layout();
    $grid = ['layout_type' => 'grid', 'column_count' => 3, 'minimum_column_width' => '12rem'];

    $css = implode('', $layout->build('core/post-template', $grid + ['block_gap' => '1rem'])->cssRules);
    self::assertStringContainsString('grid-template-columns:repeat(auto-fill, minmax(max(min(12rem, 100%), (100% - (1rem * (3 - 1))) /3), 1fr));', $css);
    self::assertStringContainsString('container-type:inline-size;', $css);

    $css = implode('', $layout->build('core/post-template', $grid)->cssRules);
    self::assertStringContainsString('(100% - (var(--wp--style--block-gap, 0.5em) * (3 - 1))) /3)', $css);

    $css = implode('', $layout->build('core/post-template', $grid + ['block_gap' => '0'])->cssRules);
    self::assertStringContainsString('(100% - (0px * (3 - 1))) /3)', $css);

    $css = implode('', $layout->build('core/post-template', ['layout_type' => 'grid', 'column_count' => 3])->cssRules);
    self::assertStringContainsString('grid-template-columns:repeat(3, minmax(0, 1fr));', $css);
  }

  /**
   * Tests that a theme without block gap support gets no gap CSS.
   */
  public function testNoGapCssWithoutBlockGapSupport(): void {
    $supports = $this->supports(FALSE);
    foreach (['flex', 'grid', 'constrained', 'default'] as $type) {
      $output = $supports->render('core/group', ['layout_type' => $type, 'block_gap' => '3rem']);
      self::assertStringNotContainsString('gap', implode('', $output->cssRules), $type);
      self::assertStringNotContainsString('margin-block-start:3rem', implode('', $output->cssRules), $type);
    }
  }

  /**
   * Tests that a zero root padding keeps the full-width pull-out valid.
   */
  public function testZeroPaddingPullsFullWidthChildrenOutByZeroPixels(): void {
    $output = $this->supports()->render('core/group', [
      'layout_type' => 'constrained',
      'padding_left' => '0',
      'padding_right' => '2rem',
    ]);

    $rules = implode('', $output->cssRules);
    self::assertStringContainsString('margin-left:calc(0px * -1)', $rules);
    self::assertStringContainsString('margin-right:calc(2rem * -1)', $rules);
  }

  /**
   * Tests that a preset attribute wins over the custom value beside it.
   */
  public function testPresetAttributeWinsOverCustomStyleValue(): void {
    $props = (new AttributeFlattener())->flatten([
      'backgroundColor' => 'base',
      'textColor' => 'contrast',
      'gradient' => 'sunset',
      'fontSize' => 'large',
      'fontFamily' => 'serif-stack',
      'borderColor' => 'accent',
      'style' => [
        'color' => ['background' => '#ff0000', 'text' => '#00ff00', 'gradient' => 'linear-gradient(#000,#fff)'],
        'typography' => ['fontSize' => '20px', 'fontFamily' => 'Georgia'],
        'border' => ['color' => '#0000ff'],
      ],
    ], 'core/group');

    self::assertSame('var:preset|color|base', $props['background_color']);
    self::assertSame('var:preset|color|contrast', $props['text_color']);
    self::assertSame('var:preset|gradient|sunset', $props['gradient']);
    self::assertSame('var:preset|font-size|large', $props['font_size']);
    self::assertSame('var:preset|font-family|serif-stack', $props['font_family']);
    self::assertSame('var:preset|color|accent', $props['border_color']);

    $custom = (new AttributeFlattener())->flatten(['style' => ['color' => ['background' => '#ff0000']]], 'core/group');
    self::assertSame('#ff0000', $custom['background_color']);
  }

  /**
   * Tests that a CSS keyword in a style value stays a CSS value.
   */
  public function testCssKeywordsAreNotPresetSlugs(): void {
    $flattener = new AttributeFlattener();
    $custom = $flattener->flatten([
      'style' => [
        'color' => ['background' => 'transparent', 'text' => 'white'],
        'border' => ['color' => 'currentColor'],
        'typography' => ['fontFamily' => 'monospace'],
      ],
    ], 'core/button');
    $output = $this->supports()->render('core/button', $custom, FALSE);

    self::assertSame('transparent', $output->styles['background-color']);
    self::assertSame('white', $output->styles['color']);
    self::assertSame('currentColor', $output->styles['border-color']);
    self::assertSame('monospace', $output->styles['font-family']);
    self::assertNotContains('has-white-color', $output->classes);
    self::assertNotContains('has-monospace-font-family', $output->classes);

    // A theme preset spelled like a keyword is still a preset.
    $preset = $flattener->flatten([
      'backgroundColor' => 'transparent',
      'textColor' => 'white',
      'fontFamily' => 'monospace',
    ], 'core/button');
    $output = $this->supports()->render('core/button', $preset, FALSE);

    self::assertContains('has-transparent-background-color', $output->classes);
    self::assertContains('has-white-color', $output->classes);
    self::assertContains('has-monospace-font-family', $output->classes);
    self::assertArrayNotHasKey('font-family', $output->styles);
  }

  /**
   * Tests that a unitless border width prints in pixels on engine blocks.
   */
  public function testAddsPixelsToUnitlessBorderWidth(): void {
    $flattener = new AttributeFlattener();
    $attributes = ['style' => ['border' => ['width' => 2, 'style' => 'solid']]];

    self::assertSame('2px', $flattener->flatten($attributes, 'core/post-title')['border_width']);
    self::assertSame(2, $flattener->flatten($attributes, 'core/heading')['border_width']);
  }

  /**
   * Tests that a gap WordPress sanitizes away writes no value.
   */
  public function testDropsGapWordPressRejects(): void {
    $flattener = new AttributeFlattener();

    $gap = static fn (mixed $value): array => $flattener->flatten(['style' => ['spacing' => ['blockGap' => $value]]], 'core/group');

    self::assertArrayNotHasKey('block_gap', $gap('var(--wp--preset--spacing--40)'));
    self::assertSame('var:preset|spacing|40', $gap('var:preset|spacing|40')['block_gap']);
    self::assertSame('var(--wp--style--block-gap, 0.5em) 2rem', $gap(['top' => 'calc(1rem)', 'left' => '2rem'])['block_gap']);
  }

  /**
   * Tests that a legacy inherit flag or a content width means constrained.
   */
  public function testLegacyLayoutIsConstrained(): void {
    $flattener = new AttributeFlattener();

    self::assertSame('constrained', $flattener->flatten(['layout' => ['inherit' => TRUE]], 'core/group')['layout_type']);
    self::assertSame('constrained', $flattener->flatten(['layout' => ['type' => 'default', 'contentSize' => '600px']], 'core/group')['layout_type']);
    self::assertArrayNotHasKey('layout_type', $flattener->flatten(['layout' => ['inherit' => FALSE]], 'core/group'));
    self::assertSame('flex', $flattener->flatten(['layout' => ['type' => 'flex']], 'core/group')['layout_type']);
  }

  /**
   * Tests a duotone preset classes the block and filters its image.
   */
  public function testDuotonePresetFiltersTheBlockImage(): void {
    $output = $this->supports()->render('core/image', ['duotone' => 'var:preset|duotone|dark-grayscale']);
    self::assertContains('wp-duotone-dark-grayscale', $output->classes);
    self::assertSame(
      ['.wp-duotone-dark-grayscale.wp-block-image img, .wp-duotone-dark-grayscale.wp-block-image .components-placeholder' => 'filter:url(#wp-duotone-dark-grayscale);'],
      $output->cssRules,
    );
    self::assertSame([], $this->supports()->render('core/image', ['duotone' => 'var:preset|duotone|unknown'])->cssRules);
  }

  /**
   * Tests a sticky position the theme allows, and a fixed one it does not.
   */
  public function testPositionFollowsTheThemeSettings(): void {
    $output = $this->supports()->render('core/group', ['position_type' => 'sticky', 'position_top' => '0']);
    self::assertContains('is-position-sticky', $output->classes);
    self::assertSame(['top:calc(0px + var(--wp-admin--admin-bar--position-offset, 0px));position:sticky;z-index:10;'], array_values($output->cssRules));
    self::assertNotContains('is-position-fixed', $this->supports()->render('core/group', ['position_type' => 'fixed'])->classes);
  }

  /**
   * Tests a saved layout replaces the block type's default layout.
   */
  public function testSavedLayoutReplacesTheDefault(): void {
    $defaults = ['layout_type' => 'flex', 'flex_wrap' => 'nowrap'];

    self::assertStringContainsString('flex-wrap:nowrap', $this->layout()->build('core/columns', [], $defaults)->stylesheet());
    $saved = $this->layout()->build('core/columns', ['layout_type' => 'flex'], $defaults);
    self::assertSame(['is-layout-flex', 'wp-block-columns-is-layout-flex'], $saved->classes);
    $aligned = $this->layout()->build('core/columns', ['vertical_alignment' => 'top'], $defaults);
    self::assertContains('is-layout-flex', $aligned->classes);
  }

  /**
   * Tests nested CSS functions and separate row and column gaps.
   */
  public function testKeepsNestedCssFunctionsInGap(): void {
    $output = $this->layout()->build('core/group', [
      'layout_type' => 'flex',
      'block_gap' => 'calc(1rem + var(--wp--preset--spacing--20)) 2rem',
    ]);

    $this->assertStringContainsString('gap:calc(1rem + var(--wp--preset--spacing--20)) 2rem;', $output->stylesheet());
  }

  /**
   * Tests the axes of a vertical flex layout and child fill sizing.
   */
  public function testVerticalFlexAxesAndChildFill(): void {
    $layout = $this->layout();
    $output = $layout->build('core/group', [
      'layout_type' => 'flex',
      'orientation' => 'vertical',
      'justify_content' => 'left',
      'vertical_alignment' => 'space-between',
    ]);

    $this->assertStringContainsString('align-items:flex-start;', $output->stylesheet());
    $this->assertStringContainsString('justify-content:space-between;', $output->stylesheet());
    $this->assertStringContainsString('flex-grow:1;', $layout->childLayout(['self_stretch' => 'fill'])->stylesheet());
  }

  /**
   * Tests each flex child size prints what WordPress 7.1.2 prints.
   *
   * WordPress's layout.php serializes Max as `fixed` and Fixed as
   * `fixedNoShrink`; only the latter sets flex-shrink.
   */
  public function testFlexChildSizesMatchWordPress(): void {
    $pattern = '/content-[0-9a-f]+/';
    $fixed = $this->layout()->childLayout(['self_stretch' => 'fixed', 'flex_size' => '30%']);
    $noShrink = $this->layout()->childLayout(['self_stretch' => 'fixedNoShrink', 'flex_size' => '30%']);

    self::assertSame(
      'html .wp-container-content-%s{flex-basis:30%;box-sizing:border-box;}',
      preg_replace($pattern, 'content-%s', $fixed->stylesheet()),
    );
    self::assertSame(
      'html .wp-container-content-%s{flex-basis:30%;flex-shrink:0;box-sizing:border-box;}',
      preg_replace($pattern, 'content-%s', $noShrink->stylesheet()),
    );
  }

  /**
   * Tests that a vertical flex layout with no justification aligns to start.
   */
  public function testVerticalFlexDefaultsToStartAlignment(): void {
    $output = $this->layout()->build('core/group', [
      'layout_type' => 'flex',
      'orientation' => 'vertical',
    ]);

    $this->assertSame(['flex-direction:column;align-items:flex-start;'], array_values($output->cssRules));
  }

  /**
   * Tests that zero remains a valid border value.
   */
  public function testKeepsZeroBorderValues(): void {
    $output = $this->supports()->render('core/paragraph', [
      'border_top_width' => '0',
      'border_top_color' => '0',
    ], FALSE);

    $this->assertSame(['border-top-width' => '0', 'border-top-color' => '0'], $output->styles);
  }

  /**
   * Tests the style engine drops a zero side of a runtime-rendered block.
   */
  public function testDropsZeroSideBorderOfStyleEngineBlock(): void {
    $output = $this->supports()->render('core/post-title', [
      'border_top_width' => '0',
      'border_left_width' => '2px',
      'border_left_color' => 'var:preset|color|accent',
    ], FALSE);

    $this->assertSame([
      'border-left-width' => '2px',
      'border-left-color' => 'var(--wp--preset--color--accent)',
    ], $output->styles);
  }

  /**
   * Tests text columns and text indent print as inline styles.
   */
  public function testTextColumnsAndIndent(): void {
    $output = $this->supports()->render('core/paragraph', ['text_columns' => '2', 'text_indent' => '2em'], FALSE);
    $this->assertSame(['column-count' => '2', 'text-indent' => '2em'], $output->styles);
  }

  /**
   * Tests fit text adds WordPress's class and Interactivity directives.
   */
  public function testFitTextAddsDirectives(): void {
    $output = $this->supports()->render('core/paragraph', ['fit_text' => TRUE], FALSE);
    $this->assertSame(['has-fit-text'], $output->classes);
    $this->assertSame('core/fit-text::callbacks.init', $output->attributes['data-wp-init---core-fit-text']);
    $this->assertSame('', $output->attributes['data-wp-interactive']);
  }

  /**
   * Tests a uniform border color: a preset becomes classes, else a style.
   */
  public function testUniformBorderColor(): void {
    $preset = $this->supports()->render('core/button', [
      'border_color' => 'var:preset|color|accent',
      'border_width' => '2px',
    ], FALSE);
    $this->assertSame(['has-border-color', 'has-accent-border-color'], $preset->classes);
    $this->assertSame(['border-width' => '2px'], $preset->styles);

    $custom = $this->supports()->render('core/button', ['border_color' => '#000000'], FALSE);
    $this->assertSame(['has-border-color'], $custom->classes);
    $this->assertSame(['border-color' => '#000000'], $custom->styles);
  }

  /**
   * Tests a uniform border style prints as a literal CSS keyword.
   *
   * WordPress's search.php distributes it verbatim (no preset ever applies to
   * a border style), unlike border color and width.
   */
  public function testUniformBorderStyle(): void {
    $output = $this->supports()->render('core/search', ['border_style' => 'none'], FALSE);
    $this->assertSame(['border-style' => 'none'], $output->styles);
  }

  /**
   * Tests a per-side border style prints beside its width and color.
   */
  public function testPerSideBorderStyle(): void {
    $output = $this->supports()->render('core/group', [
      'border_top_width' => '0px',
      'border_top_style' => 'none',
    ], FALSE);

    $this->assertSame(['border-top-width' => '0px', 'border-top-style' => 'none'], $output->styles);
  }

  /**
   * Tests a per-corner border radius prints beside a per-side width.
   */
  public function testPerCornerBorderRadius(): void {
    $output = $this->supports()->render('core/image', [
      'border_top_left_radius' => '5px',
      'border_bottom_left_radius' => '5px',
    ], FALSE);

    $this->assertSame(['border-top-left-radius' => '5px', 'border-bottom-left-radius' => '5px'], $output->styles);
  }

  /**
   * Tests a border style carrying another declaration fails.
   */
  public function testBorderStyleRejectsExtraDeclarations(): void {
    $this->expectException(\UnexpectedValueException::class);
    $this->supports()->render('core/search', ['border_style' => 'solid;background:url(https://example.com/x)'], FALSE);
  }

  /**
   * Tests a heading element color adds the elements class and a rule.
   *
   * WordPress's elements.php adds one `wp-elements-N` class per block that
   * carries any element color, and a heading's own selector styles h1-h6
   * nested inside it (a group's saved markup carries no `has-heading-color`
   * of its own: that class only marks the block WordPress renders as the
   * heading itself, such as a comment author name link).
   */
  public function testHeadingElementColorAddsElementsClassAndRule(): void {
    $output = $this->supports()->render('core/group', ['heading_color' => 'var:preset|color|contrast'], FALSE);

    $this->assertCount(1, $output->classes);
    $this->assertMatchesRegularExpression('/^wp-elements-[0-9a-f]+$/', $output->classes[0]);
    $this->assertSame(
      ['color:var(--wp--preset--color--contrast);'],
      array_values(array_filter(
        $output->cssRules,
        static fn (string $selector): bool => str_starts_with($selector, '.' . $output->classes[0] . ' h1'),
        ARRAY_FILTER_USE_KEY,
      )),
    );
  }

  /**
   * Tests a link and a heading element color share one elements class.
   *
   * `wp_render_elements_support_styles()` calls `wp_get_elements_class_name()`
   * once per block, so a block with both styles gets one class carrying two
   * rules, not two classes.
   */
  public function testLinkAndHeadingElementColorsShareOneClass(): void {
    $output = $this->supports()->render('core/group', [
      'link_color' => 'var:preset|color|primary',
      'heading_color' => 'var:preset|color|contrast',
    ], FALSE);

    $elementsClasses = array_values(array_filter(
      $output->classes,
      static fn (string $class): bool => str_starts_with($class, 'wp-elements-'),
    ));
    $this->assertCount(1, $elementsClasses);
    $this->assertCount(2, $output->cssRules);
  }

  /**
   * Tests a button element color styles the buttons inside the block.
   */
  public function testButtonElementColor(): void {
    $output = $this->supports()->render('core/group', [
      'button_color' => '#1a2b3c',
      'button_background_color' => 'var:preset|color|contrast',
    ], FALSE);

    $container = $output->classes[0];
    $this->assertMatchesRegularExpression('/^wp-elements-[0-9a-f]+$/', $container);
    $this->assertSame(
      "color:#1a2b3c;background-color:var(--wp--preset--color--contrast);",
      $output->cssRules[".$container .wp-element-button, .$container .wp-block-button__link"],
    );
  }

  /**
   * Tests a shadow prints as box-shadow, a preset as its custom property.
   */
  public function testShadow(): void {
    $this->assertSame(['box-shadow' => 'var(--wp--preset--shadow--natural)'], $this->supports()->render('core/group', ['shadow' => 'var:preset|shadow|natural'], FALSE)->styles);
    $this->assertSame(['box-shadow' => '2px 2px 0 #1a2b3c'], $this->supports()->render('core/group', ['shadow' => '2px 2px 0 #1a2b3c'], FALSE)->styles);
  }

  /**
   * Tests a focal point rounds to whole percentages, as cover.php does.
   */
  public function testFocalPointRoundsToPercentages(): void {
    $props = (new AttributeFlattener())->flatten(['focalPoint' => ['x' => 0.5, 'y' => 0.125]], 'core/cover');
    self::assertSame('50% 13%', $props['focal_point']);
  }

  /**
   * Tests that style.background.* flattens to one prop per sub-property.
   */
  public function testBackgroundImageFlattensToItsOwnProps(): void {
    $props = (new AttributeFlattener())->flatten([
      'style' => [
        'background' => [
          'backgroundImage' => ['url' => '/themes/custom/spike_business/assets/images/banner-img4.png'],
          'backgroundSize' => 'contain',
          'backgroundRepeat' => 'no-repeat',
          'backgroundPosition' => '50% 99%',
        ],
      ],
    ], 'core/group');

    self::assertSame('/themes/custom/spike_business/assets/images/banner-img4.png', $props['background_image'] ?? NULL);
    self::assertSame('contain', $props['background_size'] ?? NULL);
    self::assertSame('no-repeat', $props['background_repeat'] ?? NULL);
    self::assertSame('50% 99%', $props['background_position'] ?? NULL);
  }

  /**
   * Tests `var:custom` references on the paths WordPress 7.1.2 takes.
   *
   * Saved markup of a static block resolves them, the style engine of a
   * dynamic block prints no declaration, and a layout gap prints the value
   * as written.
   */
  public function testCustomReferencesFollowWordPressPaths(): void {
    $props = ['padding_top' => 'var:custom|xl-spacing', 'padding_left' => '1rem'];

    $static = $this->supports()->render('core/group', $props, FALSE);
    $dynamic = $this->supports()->render('core/post-title', $props, FALSE);
    $flow = $this->layout()->build('core/group', ['block_gap' => 'var:custom|xl-spacing']);
    $flex = $this->layout()->build('core/group', ['layout_type' => 'flex', 'block_gap' => 'var:custom|4-xl-spacing']);

    $this->assertSame(['padding-top' => 'var(--wp--custom--xl-spacing)', 'padding-left' => '1rem'], $static->styles);
    $this->assertSame(['padding-left' => '1rem'], $dynamic->styles);
    $this->assertStringContainsString('margin-block-start:var:custom|xl-spacing;', $flow->stylesheet());
    $this->assertStringContainsString('gap:var:custom|4-xl-spacing;', $flex->stylesheet());
  }

  /**
   * Tests that a dynamic block also strips a custom reference on these two.
   *
   * The style engine prints no declaration for a `var:custom|...` reference
   * on the font size or the border radius either. WordPress turns each of
   * their values into a length before the style engine reads it.
   */
  public function testCustomReferencesStripFontSizeAndBorderRadius(): void {
    $props = ['font_size' => 'var:custom|xl-font', 'border_radius' => 'var:custom|round'];

    $static = $this->supports()->render('core/group', $props, FALSE);
    $dynamic = $this->supports()->render('core/post-title', $props, FALSE);

    $this->assertSame(
      ['font-size' => 'var(--wp--custom--xl-font)', 'border-radius' => 'var(--wp--custom--round)'],
      $static->styles,
    );
    $this->assertSame([], $dynamic->styles);
  }

  /**
   * Tests a non-slug top-level fontSize still becomes a class, not a style.
   *
   * WordPress always treats the top-level `fontSize` attribute as a preset
   * reference (`wp_apply_typography_support()`'s
   * `"var:preset|font-size|{$block_attributes['fontSize']}"`), then
   * kebab-cases whatever it holds into a class
   * (`WP_Style_Engine::get_slug_from_preset_value()`), even when a theme
   * template hardcodes a literal CSS value there instead of a real preset
   * slug, as twentytwentytwo's `templates/home.html` does for its post
   * title (`"fontSize":"var(--wp--custom--typography--font-size--huge,
   * clamp(2.25rem, 4vw, 2.75rem))"`). The nested `style.typography.fontSize`
   * path stays a raw CSS value: only this top-level shorthand always
   * becomes a class.
   */
  public function testNonSlugTopLevelFontSizeBecomesClass(): void {
    $props = (new AttributeFlattener())->flatten([
      'fontSize' => 'var(--wp--custom--typography--font-size--huge, clamp(2.25rem, 4vw, 2.75rem))',
    ], 'core/post-title');
    $output = $this->supports()->render('core/post-title', $props, FALSE);

    $this->assertSame(
      ['has-var-wp-custom-typography-font-size-huge-clamp-2-25-rem-4-vw-2-75-rem-font-size'],
      $output->classes,
    );
    $this->assertSame([], $output->styles);
  }

  /**
   * Tests that flattening prints a unitless radius and Spacer size in pixels.
   *
   * WordPress prints a unitless border radius in pixels on a block the style
   * engine writes (block-supports/border.php:67-69), and a Spacer's save()
   * interpolates a bare height/width number into its style attribute the
   * same way (spacer/save.js). A block the style engine does not write keeps
   * the bare number.
   */
  public function testAddsPixelsToUnitlessRadiusAndSpacerSize(): void {
    $flattener = new AttributeFlattener();

    $title = $flattener->flatten(['style' => ['border' => ['radius' => 4]]], 'core/post-title');
    self::assertSame('4px', $title['border_radius']);

    $heading = $flattener->flatten(['style' => ['border' => ['radius' => 4]]], 'core/heading');
    self::assertSame(4, $heading['border_radius']);

    $spacer = $flattener->flatten(['height' => 66, 'width' => 40], 'core/spacer');
    self::assertSame('66px', $spacer['height']);
    self::assertSame('40px', $spacer['width']);
  }

  /**
   * Builds block support services with the fixture theme settings.
   */
  private function supports(bool $blockGapSupport = TRUE): BlockSupports {
    $components = $this->createMock(ComponentPluginManager::class);
    $duotone = [PropName::DUOTONE_SELECTOR => '.wp-block-image img, .wp-block-image .components-placeholder'];
    $components->method('getDefinition')->willReturnMap([
      ['wordpal:image', TRUE, ['props' => ['properties' => ['duotone' => $duotone]]]],
    ]);
    return new BlockSupports($this->layout($blockGapSupport), $this->themeSettings($blockGapSupport), $components);
  }

  /**
   * Builds the layout service with the fixture theme settings.
   */
  private function layout(bool $blockGapSupport = TRUE): LayoutCss {
    return new LayoutCss($this->themeSettings($blockGapSupport));
  }

  /**
   * Builds the theme settings used by the WordPress fixture.
   */
  private function themeSettings(bool $blockGapSupport = TRUE, array $blockGaps = [], string|array|null $rootBlockGap = NULL): ThemeSettings {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('isNew')->willReturn(FALSE);
    $variationClasses = [
      'core/button/outline' => 'is-style-outline--b3',
      'core/image/rounded' => 'is-style-rounded--17',
      'core/group/section' => 'is-style-section--1',
      'core/group/pill' => 'is-style-pill--4',
      'core/group/a' => 'is-style-a--2',
      'core/group/b' => 'is-style-b--3',
    ];
    $registered = [
      'core/button' => ['fill', 'outline'],
      'core/image' => ['rounded'],
      'core/group' => ['section', 'a', 'b', 'c'],
    ];
    $config->method('get')->willReturnMap([
      ['use_root_padding_aware_alignments', TRUE],
      ['block_gap_support', $blockGapSupport],
      ['block_gaps', $blockGaps],
      ['root_block_gap', $rootBlockGap],
      ['styled_variations', $variationClasses],
      ['registered_variations', $registered],
      ['position.sticky', TRUE],
      ['position.fixed', FALSE],
      ['duotone_filters', ['dark-grayscale' => '<svg></svg>']],
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with(ThemeSettings::configName('twentytwentyfour'))->willReturn($config);
    $activeTheme = $this->createMock(ActiveTheme::class);
    $activeTheme->method('getName')->willReturn('twentytwentyfour');
    $themeManager = $this->createMock(ThemeManagerInterface::class);
    $themeManager->method('getActiveTheme')->willReturn($activeTheme);
    return new ThemeSettings($factory, $themeManager);
  }

  /**
   * Returns a wrapper element's classes and style, layout hash normalized.
   *
   * WordPress and BlockSupports each generate the container's layout hash
   * their own way, so only its prefix compares.
   */
  private function wrapperAttributes(string $html): array {
    $document = new \DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    $element = $document->getElementsByTagName('body')->item(0)->firstElementChild;
    $classes = preg_replace(
      '/^(wp-container-.+-is-layout-)[0-9a-f]+$/',
      '$1HASH',
      preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY),
    );
    sort($classes);
    $style = array_values(array_filter(array_map('trim', explode(';', $element->getAttribute('style')))));
    sort($style);
    return ['classes' => $classes, 'style' => $style];
  }

  /**
   * Returns the first rendered element without its children.
   */
  private function rootElement(string $markup): string {
    $document = new \DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html><body>' . $markup . '</body></html>', LIBXML_NOERROR);
    $element = $document->getElementsByTagName('body')->item(0)?->firstElementChild;
    $this->assertInstanceOf(\DOMElement::class, $element);
    return (string) $document->saveHTML($element->cloneNode(FALSE));
  }

  /**
   * Compares the generated rules for the fixture's layout container.
   */
  private function assertLayoutRulesMatch(array $actual, string $rendered, string $stylesheet): void {
    preg_match('/wp-container-core-group-is-layout-[0-9a-f]+/', $rendered, $expectedMatch);
    preg_match('/\.(wp-container-[a-z0-9-]+-is-layout-[0-9a-f]{8})/', array_key_first($actual), $actualMatch);
    $actualClass = $actualMatch[1];
    $expectedClass = $expectedMatch[0];
    preg_match_all('/([^{}]+)\{([^{}]+)\}/', $stylesheet, $rules, PREG_SET_ORDER);
    $expected = [];
    foreach ($rules as $rule) {
      if (str_contains($rule[1], $expectedClass)) {
        $expected[preg_replace('/\s+/', ' ', trim($rule[1]))] = preg_replace('/\s+/', '', $rule[2]);
      }
    }
    $found = [];
    foreach ($actual as $selector => $declarations) {
      $found[str_replace($actualClass, $expectedClass, $selector)] = preg_replace('/\s+/', '', $declarations);
    }
    $this->assertSame($expected, $found);
  }

}
