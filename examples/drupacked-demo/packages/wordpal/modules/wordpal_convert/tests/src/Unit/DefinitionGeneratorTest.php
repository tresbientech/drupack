<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\wordpal\Component\InspectorPanel;
use Drupal\wordpal_convert\Component\DefinitionGenerator;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests building component definitions from WordPress blocks.
 */
#[CoversClass(DefinitionGenerator::class)]
#[Group('wordpal')]
class DefinitionGeneratorTest extends UnitTestCase {

  /**
   * Tests that block supports become flat scalar props.
   */
  public function testTurnsSupportsIntoFlatProps(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/group',
      'title' => 'Group',
      'category' => 'design',
      'description' => 'Gather blocks in a layout container.',
      'attributes' => [
        'tagName' => ['type' => 'string', 'default' => 'div'],
        'templateLock' => ['type' => ['string', 'boolean']],
      ],
      'supports' => [
        'align' => ['wide', 'full'],
        'anchor' => TRUE,
        'color' => ['gradients' => TRUE],
        'spacing' => ['margin' => ['top', 'bottom'], 'padding' => TRUE, 'blockGap' => TRUE],
        'typography' => ['fontSize' => TRUE, '__experimentalFontFamily' => TRUE],
        'layout' => ['allowSizingOnChildren' => TRUE],
        'shadow' => TRUE,
        'interactivity' => ['clientNavigation' => TRUE],
      ],
    ]);
    $properties = $result['definition']['props']['properties'];

    $this->assertSame('Group', $result['definition']['name']);
    $this->assertSame('WordPress: design', $result['definition']['group']);
    $this->assertSame(['content' => ['title' => 'Content']], $result['definition']['slots']);

    // A block default becomes the example Canvas starts an instance with.
    $this->assertSame(['div'], $properties['tag_name']['examples']);
    $this->assertSame([
      'div',
      'header',
      'main',
      'section',
      'article',
      'aside',
      'footer',
      'nav',
    ], $properties['tag_name']['enum']);
    $this->assertSame(['wide', 'full'], $properties['align']['enum']);
    $this->assertSame(['wide' => 'Wide width', 'full' => 'Full width'], $properties['align']['meta:enum']);
    $this->assertSame('Default (<div>)', $properties['tag_name']['meta:enum']['div']);
    $this->assertSame('<header>', $properties['tag_name']['meta:enum']['header']);
    $this->assertSame('Flow', $properties['layout_type']['meta:enum']['default']);
    $this->assertSame('Grow', $properties['self_stretch']['meta:enum']['fill']);
    $this->assertSame('Fixed', $properties['self_stretch']['meta:enum']['fixedNoShrink']);
    $this->assertSame(['default', 'constrained', 'flex', 'grid'], $properties['layout_type']['enum']);
    $this->assertArrayHasKey('background_color', $properties);
    $this->assertArrayHasKey('gradient', $properties);
    $this->assertArrayHasKey('font_family', $properties);
    $this->assertArrayHasKey('block_gap', $properties);
    $this->assertArrayHasKey('css_class', $properties);
    $this->assertSame('CSS class', $properties['css_class']['title']);

    // Margin lists the sides the block allows, padding takes all four.
    $this->assertArrayHasKey('margin_top', $properties);
    $this->assertArrayNotHasKey('margin_left', $properties);
    $this->assertArrayHasKey('padding_left', $properties);

    // Editor-only attributes stay out, and unhandled supports get reported.
    $this->assertArrayNotHasKey('template_lock', $properties);
    $this->assertSame('border', $properties['shadow']['x-wordpal-panel']);
    $this->assertSame([], $result['skipped']);
  }

  /**
   * Tests that Query's saved tagName gets the same enum as Group's.
   *
   * WordPress prints core/query's own tagName as its loop wrapper, the same
   * attribute core/group carries.
   */
  public function testQueryTagNameGetsTheSameEnumAsGroup(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/query',
      'title' => 'Query Loop',
      'category' => 'theme',
      'attributes' => [
        'tagName' => ['type' => 'string', 'default' => 'div'],
      ],
      'supports' => [],
    ]);
    $properties = $result['definition']['props']['properties'];

    $this->assertSame([
      'div',
      'header',
      'main',
      'section',
      'article',
      'aside',
      'footer',
      'nav',
    ], $properties['tag_name']['enum']);
  }

  /**
   * Tests that a rich text attribute becomes an HTML prop.
   */
  public function testTurnsRichTextIntoAnHtmlProp(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/paragraph',
      'title' => 'Paragraph',
      'category' => 'text',
      'attributes' => [
        'content' => ['type' => 'rich-text', 'source' => 'rich-text', 'selector' => 'p'],
        'dropCap' => ['type' => 'boolean', 'default' => FALSE],
        'placeholder' => ['type' => 'string'],
        'direction' => ['type' => 'string', 'enum' => ['ltr', 'rtl']],
      ],
      'supports' => ['className' => FALSE],
    ]);
    $properties = $result['definition']['props']['properties'];

    $this->assertSame([
      'title' => 'Content',
      'type' => 'string',
      'contentMediaType' => 'text/html',
      'x-formatting-context' => 'inline',
      'x-wordpal-panel' => 'settings',
    ], $properties['content']);
    $this->assertSame('boolean', $properties['drop_cap']['type']);
    $this->assertSame(['ltr', 'rtl'], $properties['direction']['enum']);
    $this->assertArrayNotHasKey('placeholder', $properties);
    $this->assertSame('advanced', $properties['css_class']['x-wordpal-panel'], 'A saved Paragraph class reads back as className.');
    $this->assertArrayNotHasKey('slots', $result['definition']);

    $listItem = (new DefinitionGenerator())->generate([
      'name' => 'core/list-item',
      'title' => 'List item',
      'category' => 'text',
      'attributes' => [],
      'supports' => ['className' => FALSE],
    ]);
    $this->assertArrayNotHasKey('css_class', $listItem['definition']['props']['properties']);
  }

  /**
   * Tests that a Button always gets a prop for its link's own saved class.
   */
  public function testButtonGetsLinkClassProp(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/button',
      'title' => 'Button',
      'category' => 'design',
      'attributes' => [],
      'supports' => [],
    ]);
    $properties = $result['definition']['props']['properties'];

    $this->assertSame('Link CSS class', $properties['link_css_class']['title']);
    $this->assertSame('advanced', $properties['link_css_class']['x-wordpal-panel'], 'A saved link class reads back as linkClassName.');
  }

  /**
   * Tests that attributes Canvas cannot store stay out.
   */
  public function testLeavesOutUnstorableAttributes(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/gallery',
      'title' => 'Gallery',
      'category' => 'media',
      'attributes' => [
        'images' => ['type' => 'array', 'source' => 'query'],
        'columns' => ['type' => 'number'],
      ],
      'supports' => [],
    ]);

    $this->assertArrayNotHasKey('images', $result['definition']['props']['properties']);
    $this->assertSame('number', $result['definition']['props']['properties']['columns']['type']);
    $this->assertSame(['attribute images'], $result['skipped']);
  }

  /**
   * Tests that a list or object blockGap support still adds the prop.
   *
   * WordPress declares `blockGap: true` on some blocks, but `buttons`
   * declares it as a list of the axes it exposes, and `columns` as an
   * object holding a default and that same list.
   */
  public function testTreatsAnyBlockGapShapeAsSupport(): void {
    $buttons = (new DefinitionGenerator())->generate([
      'name' => 'core/buttons',
      'title' => 'Buttons',
      'category' => 'design',
      'supports' => [
        'spacing' => ['blockGap' => ['horizontal', 'vertical']],
      ],
    ]);
    $columns = (new DefinitionGenerator())->generate([
      'name' => 'core/columns',
      'title' => 'Columns',
      'category' => 'design',
      'supports' => [
        'spacing' => ['blockGap' => ['sides' => ['horizontal', 'vertical'], '__experimentalDefault' => '2em']],
      ],
    ]);

    $this->assertArrayHasKey('block_gap', $buttons['definition']['props']['properties']);
    $this->assertArrayHasKey('block_gap', $columns['definition']['props']['properties']);
  }

  /**
   * Tests that horizontal and vertical padding sides expand to physical ones.
   *
   * The button block declares `spacing.padding: ["horizontal", "vertical"]`.
   * Nothing reads a "padding_horizontal" or "padding_vertical" prop, so the
   * generator must expand them to the four sides BlockSupports renders.
   */
  public function testExpandsHorizontalAndVerticalPaddingSides(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/button',
      'title' => 'Button',
      'category' => 'design',
      'supports' => [
        'spacing' => ['padding' => ['horizontal', 'vertical']],
      ],
    ]);
    $properties = $result['definition']['props']['properties'];

    $this->assertArrayHasKey('padding_left', $properties);
    $this->assertArrayHasKey('padding_right', $properties);
    $this->assertArrayHasKey('padding_top', $properties);
    $this->assertArrayHasKey('padding_bottom', $properties);
    $this->assertArrayNotHasKey('padding_horizontal', $properties);
    $this->assertArrayNotHasKey('padding_vertical', $properties);
  }

  /**
   * Tests that color.text and color.background FALSE drop their props.
   *
   * The buttons block sets `color.text: false`, since Canvas colors the
   * text of each button, not the group. `color.background` is unset there,
   * which WordPress and this generator both treat as supported.
   */
  public function testSkipsColorPropsDisabledByTheBlock(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/buttons',
      'title' => 'Buttons',
      'category' => 'design',
      'supports' => [
        'color' => ['gradients' => TRUE, 'text' => FALSE],
      ],
    ]);
    $properties = $result['definition']['props']['properties'];

    $this->assertArrayNotHasKey('text_color', $properties);
    $this->assertArrayHasKey('background_color', $properties);
    $this->assertArrayHasKey('gradient', $properties);

    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/separator',
      'title' => 'Separator',
      'category' => 'design',
      'supports' => [
        'color' => ['background' => FALSE, 'text' => TRUE],
      ],
    ]);
    $properties = $result['definition']['props']['properties'];

    $this->assertArrayNotHasKey('background_color', $properties);
    $this->assertArrayHasKey('text_color', $properties);
  }

  /**
   * Tests that only background.backgroundImage adds the background props.
   */
  public function testBackgroundImageSupportAddsBackgroundProps(): void {
    $properties = (new DefinitionGenerator())->generate([
      'name' => 'core/quote',
      'title' => 'Quote',
      'category' => 'text',
      'supports' => ['background' => ['backgroundImage' => TRUE, 'backgroundSize' => TRUE]],
    ])['definition']['props']['properties'];
    self::assertSame('uri-reference', $properties['background_image']['format']);
    self::assertSame('color', $properties['background_size']['x-wordpal-panel']);

    $properties = (new DefinitionGenerator())->generate([
      'name' => 'core/cover',
      'title' => 'Cover',
      'category' => 'media',
    ])['definition']['props']['properties'];
    self::assertArrayNotHasKey('background_image', $properties);
  }

  /**
   * Tests that each prop names its inspector panel, in inspector order.
   */
  public function testPlacesPropsInInspectorPanels(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/heading',
      'title' => 'Heading',
      'category' => 'text',
      'attributes' => [
        'textAlign' => ['type' => 'string'],
        'content' => ['type' => 'rich-text', 'source' => 'rich-text', 'role' => 'content'],
        'level' => ['type' => 'number', 'default' => 2],
      ],
      'supports' => [
        '__experimentalBorder' => ['radius' => TRUE],
        'align' => ['wide', 'full'],
        'anchor' => TRUE,
        'className' => TRUE,
        'color' => ['gradients' => TRUE, 'link' => TRUE],
        'filter' => ['duotone' => TRUE],
        'spacing' => ['margin' => TRUE],
        'dimensions' => ['minHeight' => TRUE],
        'layout' => TRUE,
        'typography' => ['fontSize' => TRUE],
        'ariaLabel' => TRUE,
      ],
    ]);
    $panels = array_map(
      static fn (array $prop): string => $prop[InspectorPanel::KEY],
      $result['definition']['props']['properties'],
    );

    $this->assertSame('content', $panels['content']);
    $this->assertSame('settings', $panels['level']);
    $this->assertSame('settings', $panels['text_align']);
    $this->assertSame('settings', $panels['align']);
    $this->assertSame('settings', $panels['aria_label']);
    $this->assertSame('advanced', $panels['anchor']);
    $this->assertSame('advanced', $panels['css_class']);
    $this->assertSame('typography', $panels['font_size']);
    $this->assertSame('color', $panels['link_color']);
    $this->assertSame('filter', $panels['duotone']);
    $this->assertSame('layout', $panels['layout_type']);
    $this->assertSame('dimensions', $panels['margin_top']);
    $this->assertSame('dimensions', $panels['min_height']);
    $this->assertSame('dimensions', $panels['self_stretch']);
    $this->assertSame('border', $panels['border_radius']);

    // Content first, then Settings and Advanced, then the Styles panels.
    $this->assertSame('content', array_key_first($panels));
    $order = array_values(array_unique($panels));
    $this->assertSame(
      ['content', 'settings', 'advanced', 'typography', 'color', 'filter', 'layout', 'dimensions', 'border'],
      $order,
    );
    // Within a panel, props keep the order block.json gives them.
    $settings = array_keys(array_filter($panels, static fn (string $panel): bool => $panel === 'settings'));
    $this->assertSame(['text_align', 'level', 'align', 'aria_label'], $settings);
  }

  /**
   * Tests border support adds uniform props and an enum border style.
   */
  public function testBorderSupportAddsUniformProps(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/group',
      'title' => 'Group',
      'category' => 'design',
      'attributes' => [],
      'supports' => [
        '__experimentalBorder' => ['color' => TRUE, 'radius' => TRUE, 'style' => TRUE, 'width' => TRUE],
      ],
    ]);
    $props = $result['definition']['props']['properties'];

    $this->assertSame(
      ['border_radius', 'border_color', 'border_width', 'border_style', 'border_top_width'],
      array_slice(array_keys(array_filter($props, static fn (array $prop): bool => str_starts_with($prop['title'], 'Border'))), 0, 5),
    );
    $this->assertSame(['none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'], $props['border_style']['enum']);
    $this->assertSame('Solid', $props['border_style']['meta:enum']['solid']);
    $this->assertSame('border', $props['border_style'][InspectorPanel::KEY]);
    $this->assertSame(['solid', 'dashed', 'dotted'], $props['border_style'][InspectorPanel::OPTIONS_KEY], 'The editor offers the three styles of the WordPress border control.');
    $this->assertSame(['solid', 'dashed', 'dotted'], $props['border_top_style'][InspectorPanel::OPTIONS_KEY]);
    $this->assertSame(['none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'], $props['border_top_style']['enum'], 'Each side gets its own style enum, like its own width and color.');
    $this->assertSame('Border top left radius', $props['border_top_left_radius']['title'], 'A border radius can hold one corner value per key too.');
  }

  /**
   * Tests that a layout block carries a minimum column width.
   */
  public function testLayoutBlockHasMinimumColumnWidth(): void {
    $properties = (new DefinitionGenerator())->generate([
      'name' => 'core/post-template',
      'title' => 'Post Template',
      'category' => 'theme',
      'attributes' => [],
      'supports' => ['layout' => TRUE],
    ])['definition']['props']['properties'];
    $this->assertSame('string', $properties['minimum_column_width']['type']);
    $this->assertSame('layout', $properties['minimum_column_width'][InspectorPanel::KEY]);
  }

  /**
   * Tests every layout component carries the minimum column width.
   *
   * A grid layout's own width reaches the render only through this prop.
   */
  public function testEveryLayoutComponentCarriesMinimumColumnWidth(): void {
    $files = glob(dirname(__DIR__, 5) . '/components/*/*.component.yml');
    $this->assertNotEmpty($files);
    foreach ($files as $file) {
      $properties = Yaml::parseFile($file)['props']['properties'] ?? [];
      if (isset($properties['column_count'])) {
        $this->assertArrayHasKey('minimum_column_width', $properties, $file);
      }
    }
  }

  /**
   * Tests that props the generator constrains keep their panel.
   */
  public function testConstrainedPropsKeepTheirPanel(): void {
    $result = (new DefinitionGenerator())->generate([
      'name' => 'core/group',
      'title' => 'Group',
      'category' => 'design',
      'attributes' => ['tagName' => ['type' => 'string', 'default' => 'div']],
      'supports' => [],
    ]);
    $properties = $result['definition']['props']['properties'];

    $this->assertSame('settings', $properties['tag_name'][InspectorPanel::KEY]);
    $this->assertSame('settings', $properties['template_part'][InspectorPanel::KEY]);

    $postTemplate = (new DefinitionGenerator())->generate([
      'name' => 'core/post-template',
      'title' => 'Post Template',
      'category' => 'theme',
      'attributes' => [],
      'supports' => [],
    ])['definition']['props']['properties'];
    $this->assertSame('integer', $postTemplate['display_layout_columns']['type']);

    $image = (new DefinitionGenerator())->generate([
      'name' => 'core/image',
      'title' => 'Image',
      'category' => 'media',
      'attributes' => [],
      'supports' => [],
    ])['definition']['props']['properties'];
    $this->assertSame('boolean', $image['in_template_part']['type']);
  }

  /**
   * Tests a block in its parent's slot takes the parent's context as props.
   */
  public function testTakesSlotParentContextAsProps(): void {
    $generator = new DefinitionGenerator();
    $parent = [
      'name' => 'core/social-links',
      'attributes' => ['openInNewTab' => ['type' => 'boolean', 'default' => FALSE], 'size' => ['type' => 'string']],
      'providesContext' => ['openInNewTab' => 'openInNewTab'],
    ];
    $child = [
      'name' => 'core/social-link',
      'attributes' => ['service' => ['type' => 'string']],
      'usesContext' => ['openInNewTab', 'postId'],
    ];

    $props = $generator->generate($child, [$parent])['definition']['props']['properties'];

    self::assertSame(['core/social-links'], $generator->slotParents('social-link'));
    self::assertSame([
      'title' => 'Open in new tab',
      'type' => 'boolean',
      'examples' => [FALSE],
      'x-wordpal-panel' => 'settings',
    ], $props['open_in_new_tab']);
    self::assertArrayNotHasKey('size', $props);
  }

}
