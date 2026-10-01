<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

use Drupal\wordpal\Component\InspectorPanel;
use Drupal\wordpal\Component\PropName as RuntimePropName;

/**
 * Builds a component definition from a WordPress block definition.
 */
final class DefinitionGenerator {

  /**
   * Blocks whose saved image WordPress decodes async in a Template part.
   */
  public const TEMPLATE_PART_IMAGE_BLOCKS = ['core/image', 'core/cover', 'core/media-text'];

  /**
   * Supports that say nothing about markup or styling.
   */
  public const IGNORED_SUPPORTS = [
    'html',
    'splitting',
    'interactivity',
    'allowedBlocks',
    'multiple',
    'reusable',
    'inserter',
    'renaming',
    'lock',
    '__experimentalOnEnter',
    '__experimentalOnMerge',
    '__experimentalSettings',
    '__experimentalSelector',
    '__unstablePasteTextInline',
    'contentRole',
    'listView',
    '__experimentalExposeControlsToChildren',
    '__experimentalSlashInserter',
  ];

  /**
   * Typography supports, as WordPress key => prop name.
   */
  private const TYPOGRAPHY_PROPS = [
    'fontSize' => 'font_size',
    'lineHeight' => 'line_height',
    '__experimentalFontFamily' => 'font_family',
    '__experimentalFontWeight' => 'font_weight',
    '__experimentalFontStyle' => 'font_style',
    '__experimentalTextTransform' => 'text_transform',
    '__experimentalTextDecoration' => 'text_decoration',
    '__experimentalLetterSpacing' => 'letter_spacing',
    '__experimentalWritingMode' => 'writing_mode',
    'textColumns' => 'text_columns',
    'textIndent' => 'text_indent',
  ];

  /**
   * The inspector panel of each support's props.
   *
   * WordPress renders the controls of align and ariaLabel outside the Styles
   * tab, and those of a flex child's size in the Dimensions panel.
   */
  private const SUPPORT_PANELS = [
    'align' => 'settings',
    'anchor' => 'advanced',
    'ariaLabel' => 'settings',
    'background' => 'color',
    'position' => 'layout',
    'className' => 'advanced',
    'color' => 'color',
    'typography' => 'typography',
    'spacing' => 'dimensions',
    'dimensions' => 'dimensions',
    'filter' => 'filter',
    'layout' => 'layout',
    '__experimentalBorder' => 'border',
    'shadow' => 'border',
  ];

  /**
   * The CSS border-style keywords.
   */
  private const BORDER_STYLES = ['none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'];

  /**
   * The border styles of WordPress's border control (components.js).
   */
  private const BORDER_STYLE_OPTIONS = ['solid', 'dashed', 'dotted'];

  /**
   * The editor labels of enum values, keyed by prop name.
   *
   * From WordPress 7.1.2, wp-includes/js/dist/. A value without an entry gets
   * its title-cased name.
   */
  private const ENUM_LABELS = [
    // block-editor.js: alignmentControl options.
    'align' => [
      'none' => 'None',
      'left' => 'Align left',
      'center' => 'Align center',
      'right' => 'Align right',
      'wide' => 'Wide width',
      'full' => 'Full width',
    ],
    // block-editor.js: text alignment control.
    'text_align' => [
      'left' => 'Align text left',
      'center' => 'Align text center',
      'right' => 'Align text right',
    ],
    // block-editor.js: flex justification control.
    'justify_content' => [
      'left' => 'Justify items left',
      'center' => 'Justify items center',
      'right' => 'Justify items right',
      'space-between' => 'Space between items',
    ],
    // block-editor.js: verticalAlignmentOptions.
    'vertical_alignment' => [
      'top' => 'Align top',
      'center' => 'Align middle',
      'bottom' => 'Align bottom',
      'stretch' => 'Stretch to fill',
      'space-between' => 'Space between',
    ],
    // block-editor.js: layout type labels.
    'layout_type' => ['default' => 'Flow'],
    // block-editor.js: child layout control. Fill is WordPress's
    // "grow", fixed is its "max", and fixedNoShrink its "fixed".
    'self_stretch' => ['fit' => 'Fit', 'fill' => 'Grow', 'fixed' => 'Max', 'fixedNoShrink' => 'Fixed'],
    // block-editor.js: HTMLElementControl options.
    'tag_name' => [
      'div' => 'Default (<div>)',
      'header' => '<header>',
      'main' => '<main>',
      'section' => '<section>',
      'article' => '<article>',
      'aside' => '<aside>',
      'footer' => '<footer>',
    ],
  ];

  private const LAYOUT_PROPS = [
    'layout_type' => ['default', 'constrained', 'flex', 'grid'],
    'justify_content' => ['left', 'center', 'right', 'space-between', 'stretch'],
    'orientation' => ['horizontal', 'vertical'],
    'flex_wrap' => ['wrap', 'nowrap'],
    'vertical_alignment' => ['top', 'center', 'bottom', 'stretch', 'space-between'],
  ];

  /**
   * The blocks each container block holds, as block name => component.
   */
  public const EXPECTED_IN_SLOT = [
    'core/columns' => ['wordpal:column'],
    'core/list' => ['wordpal:list-item'],
    'core/list-item' => ['wordpal:list'],
    'core/buttons' => ['wordpal:button'],
    'core/social-links' => ['wordpal:social-link'],
    // WordPress prints a Post Terms block's links itself. Display Builder
    // fills the slot with one link per term of the post.
    'core/post-terms' => ['wordpal:' . ComponentSet::POST_TERM_SLUG],
    'core/accordion' => ['wordpal:accordion-item'],
    'core/accordion-item' => ['wordpal:accordion-heading', 'wordpal:accordion-panel'],
  ];

  /**
   * Builds the definition of one component.
   *
   * @param array $block
   *   A decoded WordPress block.json.
   * @param array[] $parents
   *   The decoded block.json of each block whose slot expects this one. The
   *   block context a parent provides becomes a prop of the child: a parent
   *   passes it at render time in WordPress, and each child holds its own
   *   copy here.
   *
   * @return array
   *   Two keys: "definition" holds the component definition, and "skipped"
   *   lists the attributes and supports left out of it.
   */
  public function generate(array $block, array $parents = []): array {
    $skipped = [];
    $props = [];

    foreach ($parents as $parent) {
      foreach (array_intersect_key($parent['providesContext'] ?? [], array_flip($block['usesContext'] ?? [])) as $attributeName) {
        $prop = $this->attributeProp($attributeName, $parent['attributes'][$attributeName]);
        if ($prop === NULL) {
          $skipped[] = 'context ' . $attributeName;
          continue;
        }
        $prop[InspectorPanel::KEY] = 'settings';
        $props[PropName::fromAttribute($attributeName)] = $prop;
      }
    }

    foreach ($block['attributes'] ?? [] as $name => $attribute) {
      if (in_array($name, PropName::EDITOR_ONLY, TRUE)) {
        continue;
      }
      $prop = $this->attributeProp($name, $attribute);
      if ($prop === NULL) {
        $skipped[] = 'attribute ' . $name;
        continue;
      }
      $prop[InspectorPanel::KEY] = $this->attributePanel($name, $attribute);
      $props[PropName::fromAttribute($name)] = $prop;
    }

    foreach ($this->supportProps($block['supports'] ?? [], $skipped) as $name => $prop) {
      $props[$name] = $prop;
    }
    if (isset($props['duotone'])) {
      // WP_Duotone::get_selector() falls back to the block's root selector.
      $props['duotone'][RuntimePropName::DUOTONE_SELECTOR] = $block['selectors']['filter']['duotone'] ?? '.wp-block-' . substr($block['name'], strlen('core/'));
    }
    $props = $this->constrainProps($block['name'], $props);

    $slug = substr($block['name'], (int) strpos($block['name'], '/') + 1);
    $definition = [
      'name' => $block['title'] ?? $slug,
      'description' => $block['description'] ?? '',
      'status' => 'stable',
      'group' => 'WordPress: ' . ($block['category'] ?? 'other'),
      'links' => ["https://github.com/WordPress/wordpress-develop/tree/trunk/src/wp-includes/blocks/$slug"],
    ];
    if ($this->hasInnerBlocks($block)) {
      // A block that holds both text and blocks keeps "content" for its text.
      $slot = isset($props['content']) ? 'children' : 'content';
      $definition['slots'] = [$slot => ['title' => ucfirst($slot)]];
      if (isset(self::EXPECTED_IN_SLOT[$block['name']])) {
        $definition['slots'][$slot]['expected'] = self::EXPECTED_IN_SLOT[$block['name']];
      }
    }
    if ($props !== []) {
      $definition['props'] = ['type' => 'object', 'properties' => InspectorPanel::sort($props)];
    }

    return ['definition' => $definition, 'skipped' => $skipped];
  }

  /**
   * Returns the prop names a block's supports turn into.
   *
   * @param array $block
   *   A decoded WordPress block.json.
   *
   * @return string[]
   *   Prop names, such as "font_size".
   */
  public function supportPropNames(array $block): array {
    $skipped = [];
    return array_keys($this->supportProps($block['supports'] ?? [], $skipped));
  }

  /**
   * Returns the blocks whose slot expects a component.
   *
   * @return string[]
   *   WordPress block names, such as "core/social-links".
   */
  public function slotParents(string $slug): array {
    return array_keys(array_filter(self::EXPECTED_IN_SLOT, static fn (array $expected): bool => in_array("wordpal:$slug", $expected, TRUE)));
  }

  /**
   * Turns one block attribute into a prop, or NULL when unsupported.
   */
  private function attributeProp(string $name, array $attribute): ?array {
    $title = $this->title(PropName::fromAttribute($name));
    $source = $attribute['source'] ?? NULL;
    if ($source === 'rich-text' || $source === 'html' || ($attribute['type'] ?? NULL) === 'rich-text') {
      return [
        'title' => $title,
        'type' => 'string',
        'contentMediaType' => 'text/html',
        'x-formatting-context' => 'inline',
      ];
    }
    if (isset($attribute['enum'])) {
      $values = array_values(array_filter($attribute['enum'], static fn ($value) => is_string($value) && $value !== ''));
      return $values === [] ? NULL : $this->withDefault($this->enumProp($title, $values), $attribute);
    }
    $prop = match ($attribute['type'] ?? NULL) {
      'string' => ['title' => $title, 'type' => 'string'],
      'boolean' => ['title' => $title, 'type' => 'boolean'],
      'number' => ['title' => $title, 'type' => 'number'],
      'integer' => ['title' => $title, 'type' => 'integer'],
      default => NULL,
    };
    if ($prop !== NULL && str_ends_with($name, 'url')) {
      $prop['format'] = 'uri-reference';
    }
    return $prop === NULL ? NULL : $this->withDefault($prop, $attribute);
  }

  /**
   * Returns the inspector panel of a block attribute.
   */
  private function attributePanel(string $name, array $attribute): string {
    return match (TRUE) {
      ($attribute['role'] ?? NULL) === 'content' => 'content',
      $name === 'anchor', $name === 'className' => 'advanced',
      default => 'settings',
    };
  }

  /**
   * Builds an enum prop, with a label for each value.
   */
  private function enumProp(string $title, array $values, ?string $prop = NULL): array {
    // A block.json enum names WordPress's own values, so it gets no shared
    // label: "div" reads "Default (<div>)" only in the Group element select.
    $labels = $prop === NULL ? [] : self::ENUM_LABELS[$prop] ?? [];
    return [
      'title' => $title,
      'type' => 'string',
      'enum' => $values,
      'meta:enum' => array_combine($values, array_map(fn (string $value): string => $labels[$value] ?? $this->title($value), $values)),
    ];
  }

  /**
   * Adds the block's default as an example.
   *
   * Nothing applies a schema default: Twig falls back on its own, and the
   * first example becomes the value a new component instance starts with.
   */
  private function withDefault(array $prop, array $attribute): array {
    // An empty default says nothing, and a blank example is an invalid
    // field default.
    if (array_key_exists('default', $attribute) && is_scalar($attribute['default']) && $attribute['default'] !== '') {
      $prop['examples'] = [$attribute['default']];
    }
    return $prop;
  }

  /**
   * Constrains attributes that WordPress validates outside block.json.
   */
  private function constrainProps(string $blockName, array $props): array {
    if (in_array($blockName, ['core/group', 'core/cover', 'core/query'], TRUE) && isset($props['tag_name'])) {
      $tags = ['div', 'header', 'main', 'section', 'article', 'aside', 'footer', 'nav'];
      $props['tag_name'] = $this->withDefault(
        $this->enumProp('Tag name', $tags, 'tag_name'),
        ['default' => 'div'],
      ) + [InspectorPanel::KEY => 'settings'];
    }
    if ($blockName === 'core/group') {
      $props['template_part'] = $this->withDefault(
        ['title' => 'Template part wrapper', 'type' => 'boolean'],
        ['default' => FALSE],
      ) + [InspectorPanel::KEY => 'settings'];
    }
    if (in_array($blockName, self::TEMPLATE_PART_IMAGE_BLOCKS, TRUE)) {
      // WordPress adds decoding="async" to a saved image in a Template part,
      // whose content passes through wp_filter_content_tags()
      // (blocks/template-part.php:153, blocks.php:2668).
      $props['in_template_part'] = [
        'title' => 'In a Template part',
        'type' => 'boolean',
        InspectorPanel::KEY => 'settings',
      ];
    }
    if ($blockName === 'core/cover') {
      // The flattener prints the `focalPoint` object as whole percentages.
      $props['focal_point'] = $this->stringProp('focal_point') + [InspectorPanel::KEY => 'settings'];
    }
    if ($blockName === 'core/paragraph') {
      // Paragraph opts out of the className support, yet saved markup can
      // carry a class that the conversion reads back as className.
      $props['css_class'] = $this->stringProp('css_class') + [InspectorPanel::KEY => 'advanced'];
    }
    if ($blockName === 'core/button') {
      // The className support classes the wrapper, not the link, yet saved
      // markup can carry a class on the link that the conversion reads back
      // as linkClassName.
      $props['link_css_class'] = $this->stringProp('link_css_class') + [InspectorPanel::KEY => 'advanced'];
    }
    if ($blockName === 'core/post-template') {
      // The generator leaves out the Query's `displayLayout` context, an
      // object. A legacy flex one lays the posts out in columns
      // (post-template.php:84-88).
      $props['display_layout_columns'] = [
        'title' => 'Legacy flex columns',
        'type' => 'integer',
        InspectorPanel::KEY => 'settings',
      ];
    }
    if ($blockName === 'core/heading' && isset($props['level'])) {
      $levels = [1, 2, 3, 4, 5, 6];
      $props['level'] = [
        'title' => 'Level',
        'type' => 'integer',
        'enum' => $levels,
        'meta:enum' => array_combine($levels, array_map(static fn (int $level): string => "H$level", $levels)),
        'examples' => [2],
        InspectorPanel::KEY => 'settings',
      ];
    }
    if ($blockName === 'core/image' && isset($props['href'])) {
      $props['href']['format'] = 'uri-reference';
    }
    if ($blockName === 'core/image') {
      // core/gallery's own compat filter copies each image's saved id onto a
      // data-id attribute before it renders (gallery.php).
      $props['in_gallery'] = ['title' => 'In a Gallery', 'type' => 'boolean'] + [InspectorPanel::KEY => 'settings'];
    }
    if ($blockName === 'core/html') {
      // Canvas stores a string of this pattern as multi-line text.
      $props['content'] = [
        'title' => 'HTML',
        'type' => 'string',
        'pattern' => '(.|\r?\n)*',
        InspectorPanel::KEY => 'content',
      ];
    }
    if ($blockName === 'core/avatar') {
      // The avatar shows the post's author, bound as author_id. WordPress
      // user ids do not match Drupal's, so a block naming one user shows
      // the post's author too.
      unset($props['user_id']);
    }
    if ($blockName === 'core/quote') {
      // A quote's text lives in its inner blocks. The deprecated `value`
      // attribute holds the text of quotes saved before WordPress 6.2, which
      // save() no longer prints.
      unset($props['value']);
    }
    if ($blockName === 'core/social-link') {
      // WordPress takes an email address, a bare domain or "#" here, and
      // turns it into a link when it renders.
      unset($props['url']['format']);
    }
    return $props;
  }

  /**
   * Turns the block supports into props, each in its inspector panel.
   *
   * @param array $supports
   *   The supports of one block.
   * @param array $skipped
   *   Collects the supports this leaves out.
   *
   * @return array
   *   Props, keyed by prop name.
   */
  private function supportProps(array $supports, array &$skipped): array {
    $props = [];
    foreach ($supports as $support => $value) {
      if (in_array($support, self::IGNORED_SUPPORTS, TRUE) || $value === FALSE) {
        continue;
      }
      if (!isset(self::SUPPORT_PANELS[$support])) {
        $skipped[] = 'support ' . $support;
        continue;
      }
      foreach ($this->propsOfSupport($support, $value) as $name => $prop) {
        $props[$name] = $prop + [InspectorPanel::KEY => self::SUPPORT_PANELS[$support]];
      }
    }
    // WordPress adds a class name attribute unless a block opts out.
    if (!array_key_exists('className', $supports)) {
      $props['css_class'] = $this->stringProp('css_class') + [InspectorPanel::KEY => 'advanced'];
    }
    // Any block can state the room it takes inside a flex layout.
    $props['self_stretch'] = $this->enumProp('Self stretch', ['fit', 'fill', 'fixed', 'fixedNoShrink'], 'self_stretch') + [InspectorPanel::KEY => 'dimensions'];
    $props['flex_size'] = $this->stringProp('flex_size') + [InspectorPanel::KEY => 'dimensions'];
    return $props;
  }

  /**
   * Turns one block support into props.
   *
   * @param string $support
   *   A key of self::SUPPORT_PANELS.
   * @param mixed $value
   *   The block's setting for that support.
   *
   * @return array
   *   Props, keyed by prop name.
   */
  private function propsOfSupport(string $support, mixed $value): array {
    $props = [];
    switch ($support) {
      case 'align':
        $values = is_array($value) ? $value : ['left', 'center', 'right', 'wide', 'full'];
        $props['align'] = $this->enumProp('Align', array_values($values), 'align');
        break;

      case 'anchor':
        $props['anchor'] = $this->stringProp('anchor');
        break;

      case 'ariaLabel':
        $props['aria_label'] = $this->stringProp('aria_label');
        break;

      case 'background':
        if (($value['backgroundImage'] ?? FALSE) === TRUE) {
          $props['background_image'] = $this->stringProp('background_image') + ['format' => 'uri-reference'];
          foreach (['background_size', 'background_position', 'background_repeat', 'background_attachment'] as $name) {
            $props[$name] = $this->stringProp($name);
          }
        }
        break;

      case 'color':
        if (($value['background'] ?? TRUE) !== FALSE) {
          $props['background_color'] = $this->stringProp('background_color');
        }
        if (($value['text'] ?? TRUE) !== FALSE) {
          $props['text_color'] = $this->stringProp('text_color');
        }
        if (($value['gradients'] ?? FALSE) === TRUE) {
          $props['gradient'] = $this->stringProp('gradient');
        }
        // WordPress's `color.link`/`color.heading` supports gate only the
        // editor's own color pickers: elements.php's server-side class and
        // style still apply to a saved `style.elements.link`/`heading` value
        // regardless of them (wp_should_add_elements_class_name() reads the
        // saved style, not the block's declared supports). A theme's own
        // pattern markup can carry the value on a block that never declares
        // the support, as core/read-more does. A block whose own supports
        // skip color serialization entirely, such as core/button and
        // core/separator, is the one case WordPress does honor here
        // (wp_should_skip_block_supports_serialization()).
        $skipsSerialization = $value['__experimentalSkipSerialization'] ?? FALSE;
        if ($skipsSerialization !== TRUE) {
          $props['link_color'] = $this->stringProp('link_color');
          $props['heading_color'] = $this->stringProp('heading_color');
          $props['button_color'] = $this->stringProp('button_color');
          $props['button_background_color'] = $this->stringProp('button_background_color');
        }
        break;

      case 'shadow':
        $props['shadow'] = $this->stringProp('shadow');
        break;

      case 'typography':
        foreach (self::TYPOGRAPHY_PROPS as $key => $prop) {
          if (($value[$key] ?? FALSE) === TRUE) {
            $props[$prop] = $this->stringProp($prop);
          }
        }
        if (($value['textAlign'] ?? FALSE) === TRUE) {
          $props['text_align'] = $this->enumProp('Text align', ['left', 'center', 'right'], 'text_align');
        }
        if (($value['fitText'] ?? FALSE) === TRUE) {
          $props['fit_text'] = ['title' => 'Fit text', 'type' => 'boolean'];
        }
        break;

      case 'spacing':
        foreach (['padding', 'margin'] as $box) {
          $sides = $value[$box] ?? FALSE;
          if ($sides === FALSE) {
            continue;
          }
          foreach ($this->expandSides(is_array($sides) ? $sides : RuntimePropName::SIDES) as $side) {
            $props[$box . '_' . $side] = $this->stringProp($box . '_' . $side);
          }
        }
        if (($value['blockGap'] ?? FALSE) !== FALSE) {
          $props['block_gap'] = $this->stringProp('block_gap');
        }
        break;

      case 'dimensions':
        if (($value['minHeight'] ?? FALSE) === TRUE) {
          $props['min_height'] = $this->stringProp('min_height');
        }
        if (($value['width'] ?? FALSE) === TRUE) {
          $props['width'] = $this->stringProp('width');
        }
        if (($value['aspectRatio'] ?? FALSE) === TRUE) {
          $props['aspect_ratio'] = $this->stringProp('aspect_ratio');
        }
        break;

      case 'filter':
        if (($value['duotone'] ?? FALSE) === TRUE) {
          $props['duotone'] = $this->stringProp('duotone');
        }
        break;

      case 'position':
        $types = array_keys(array_filter(is_array($value) ? $value : []));
        if ($types !== []) {
          $props['position_type'] = $this->enumProp('Position', $types);
          foreach (RuntimePropName::SIDES as $side) {
            $props["position_$side"] = $this->stringProp("position_$side");
          }
        }
        break;

      case 'layout':
        foreach (self::LAYOUT_PROPS as $prop => $enum) {
          $props[$prop] = $this->enumProp($this->title($prop), $enum, $prop);
        }
        $props['content_size'] = $this->stringProp('content_size');
        $props['wide_size'] = $this->stringProp('wide_size');
        $props['column_count'] = ['title' => 'Column count', 'type' => 'integer'];
        $props['minimum_column_width'] = $this->stringProp('minimum_column_width');
        break;

      case '__experimentalBorder':
        if (($value['radius'] ?? FALSE) === TRUE) {
          $props['border_radius'] = $this->stringProp('border_radius');
        }
        if (($value['color'] ?? FALSE) === TRUE) {
          $props['border_color'] = $this->stringProp('border_color');
        }
        if (($value['width'] ?? FALSE) === TRUE) {
          $props['border_width'] = $this->stringProp('border_width');
        }
        if (($value['style'] ?? FALSE) === TRUE) {
          $props['border_style'] = $this->enumProp('Border style', self::BORDER_STYLES) + [InspectorPanel::OPTIONS_KEY => self::BORDER_STYLE_OPTIONS];
        }
        foreach (RuntimePropName::SIDES as $side) {
          if (($value['width'] ?? FALSE) === TRUE) {
            $props["border_{$side}_width"] = $this->stringProp("border_{$side}_width");
          }
          if (($value['color'] ?? FALSE) === TRUE) {
            $props["border_{$side}_color"] = $this->stringProp("border_{$side}_color");
          }
          if (($value['style'] ?? FALSE) === TRUE) {
            $props["border_{$side}_style"] = $this->enumProp($this->title("border_{$side}_style"), self::BORDER_STYLES) + [InspectorPanel::OPTIONS_KEY => self::BORDER_STYLE_OPTIONS];
          }
        }
        if (($value['radius'] ?? FALSE) === TRUE) {
          foreach (array_keys(RuntimePropName::CORNERS) as $corner) {
            $props["border_{$corner}_radius"] = $this->stringProp("border_{$corner}_radius");
          }
        }
        break;

      case 'className':
        $props['css_class'] = $this->stringProp('css_class');
        break;
    }
    return $props;
  }

  /**
   * Expands the logical sides WordPress can declare into physical ones.
   *
   * A block that only tells horizontal from vertical spacing apart, such as
   * the button block's padding, declares "horizontal" and "vertical" rather
   * than listing the four sides BlockSupports renders.
   */
  public function expandSides(array $sides): array {
    $expanded = [];
    foreach ($sides as $side) {
      $physical = match ($side) {
        'horizontal' => ['left', 'right'],
        'vertical' => ['top', 'bottom'],
        default => [$side],
      };
      $expanded = [...$expanded, ...$physical];
    }
    return array_values(array_unique($expanded));
  }

  /**
   * Returns TRUE when the block holds other blocks.
   */
  public function hasInnerBlocks(array $block): bool {
    $supports = $block['supports'] ?? [];
    return isset(self::EXPECTED_IN_SLOT[$block['name']])
      || isset($supports['layout'])
      || isset($supports['allowedBlocks'])
      || isset($block['attributes']['allowedBlocks'])
      || isset($block['attributes']['templateLock']);
  }

  /**
   * Returns a string prop with a title.
   */
  private function stringProp(string $name): array {
    return ['title' => $this->title($name), 'type' => 'string'];
  }

  /**
   * Turns a prop name into a title, such as "font_size" into "Font size".
   */
  private function title(string $propName): string {
    return match ($propName) {
      'css_class' => 'CSS class',
      'link_css_class' => 'Link CSS class',
      'aria_label' => 'ARIA label',
      'url' => 'URL',
      default => ucfirst(str_replace('_', ' ', $propName)),
    };
  }

}
