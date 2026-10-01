<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Kernel;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\display_builder\SourceTree;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests WordPal components inside the shared Display Builder editing UI.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ComponentEditingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'filter',
    'comment',
    'node',
    'views',
    'menu_link_content',
    'link',
    'text',
    'wordpal',
    'wordpal_convert',
    'wordpal_display_builder',
    'ui_patterns',
    'ui_patterns_field',
    'display_builder',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
  }

  /**
   * Tests the component library lists every WordPal component.
   *
   * The configuration mirrors the default profile's component_library island.
   */
  public function testLibraryListsEveryWordpalComponent(): void {
    $library = $this->container->get('display_builder.component_library_definitions')->getDefinitions([
      'exclude' => ['navigation' => 'navigation'],
      'exclude_id' => '',
      'component_status' => ['experimental' => '0', 'deprecated' => '0', 'obsolete' => '0'],
      'include_no_ui' => FALSE,
    ]);

    $listed = [];
    foreach ($library['grouped'] as $definitions) {
      $listed = array_merge($listed, array_keys($definitions));
    }
    $listed = array_filter($listed, static fn (string $id): bool => str_starts_with($id, 'wordpal:'));
    $defined = array_filter(
      array_keys($this->container->get('plugin.manager.sdc')->getDefinitions()),
      static fn (string $id): bool => str_starts_with($id, 'wordpal:'),
    );

    self::assertSame([], array_values(array_diff($defined, $listed)));
  }

  /**
   * Tests every WordPal component renders as an empty drop places it.
   *
   * The source is the library's own drop data (props with defaults only),
   * rendered through the builder's element builder.
   *
   * @param string $componentId
   *   The component plugin id.
   * @param string|null $rootClass
   *   The WordPress class the root element carries, NULL when the component
   *   prints nothing on an empty drop: like WordPress, it prints no element
   *   until its content or post binding is set.
   */
  #[DataProvider('componentProvider')]
  public function testComponentRendersWhenDropped(string $componentId, ?string $rootClass): void {
    $library = $this->container->get('display_builder.component_library_definitions')->getDefinitions([
      'exclude' => ['navigation' => 'navigation'],
      'exclude_id' => '',
      'component_status' => ['experimental' => '0', 'deprecated' => '0', 'obsolete' => '0'],
      'include_no_ui' => FALSE,
    ]);
    $source = ['source_id' => 'component', 'source' => $library['sources'][$componentId]];

    $this->container->get('entity_type.manager')->getStorage('user')->create(['uid' => 1, 'name' => 'author'])->save();

    // Only deprecations naming WordPal count: ui_patterns' own Twig extension
    // raises signature deprecations the first time a component renders.
    $deprecations = [];
    set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
      if (stripos($message, 'wordpal') !== FALSE) {
        $deprecations[] = $message;
      }
      return TRUE;
    }, E_DEPRECATED | E_USER_DEPRECATED);
    try {
      $data = [];
      $build = $this->container->get('ui_patterns.component_element_builder')->buildSource($data, 'content', [], $source, []);
      $html = (string) $this->container->get('renderer')->renderInIsolation($build['#slots']['content'][0]);
    }
    finally {
      restore_error_handler();
    }

    self::assertSame([], $deprecations);
    if ($rootClass !== NULL) {
      self::assertNotSame('', trim(preg_replace('/<!--.*?-->/s', '', $html)), $componentId . ' rendered nothing');
      self::assertMatchesRegularExpression('/class="[^"]*\b' . preg_quote($rootClass, '/') . '\b/', $html);
    }
  }

  /**
   * Every WordPal component id with its root WordPress class, if any.
   *
   * @return array<string, array{string, string|null}>
   *   Keyed by component id.
   */
  public static function componentProvider(): array {
    $noClass = [
      'fragment', 'frozen-html', 'html', 'list-item', 'post-term', 'image',
      'post-content', 'post-date', 'post-featured-image', 'post-terms', 'query-pagination',
      'query-title', 'read-more', 'social-link', 'term-description',
    ];
    $cases = [];
    foreach (glob(dirname(__DIR__, 5) . '/components/*', GLOB_ONLYDIR) as $dir) {
      $name = basename($dir);
      $cases['wordpal:' . $name] = ['wordpal:' . $name, in_array($name, $noClass, TRUE) ? NULL : 'wp-block-' . $name];
    }
    return $cases;
  }

  /**
   * Tests the Columns slot declares Column as its only child, unenforced.
   *
   * The slot's `expected` list is the only place the rule lives. Display
   * Builder's SourceTree checks slot cardinality only, so a paragraph attaches
   * to the Columns slot without error.
   */
  public function testColumnsSlotExpectsColumnButDisplayBuilderAcceptsAnything(): void {
    $definition = $this->container->get('plugin.manager.sdc')->getDefinitions()['wordpal:columns'];
    self::assertSame(['wordpal:column'], $definition['slots']['content']['expected']);

    $tree = new SourceTree([
      [
        'source_id' => 'component',
        'source' => ['component' => ['component_id' => 'wordpal:columns']],
      ],
    ], $this->container->get('plugin.manager.ui_patterns_source'));
    $root = $tree->getTree()[0]['node_id'];

    self::assertFalse($tree->isSlotFull($tree->getNode($root), 'content'));
    self::assertNotNull(
      $tree->attachToSlot($root, 'content', 0, 'component', ['component' => ['component_id' => 'wordpal:paragraph']]),
      'Display Builder enforces the slot `expected` list: assert the paragraph is rejected.',
    );
  }

  /**
   * Tests a paragraph's content prop is an inline HTML rich text field.
   */
  public function testParagraphContentIsInlineHtml(): void {
    $content = $this->container->get('plugin.manager.sdc')->getDefinitions()['wordpal:paragraph']['props']['properties']['content'];

    self::assertSame('text/html', $content['contentMediaType']);
    self::assertSame('inline', $content['x-formatting-context']);
  }

  /**
   * Tests the builder form gives a paragraph's content a plain text widget.
   */
  public function testParagraphContentWidget(): void {
    $form = $this->container->get('form_builder')->buildForm($this->formObject('wordpal:paragraph'), new FormState());
    $content = $form['component']['props']['wordpal_content']['content'];

    self::assertSame('textfield', $content['source']['value']['#type']);
    self::assertArrayNotHasKey('#format', $content['source']['value']);
  }

  /**
   * Tests a paragraph renders its inline formatting.
   */
  public function testParagraphRendersBoldAndLink(): void {
    $build = [
      '#type' => 'component',
      '#component' => 'wordpal:paragraph',
      '#props' => ['content' => 'Walk <strong>the</strong> <a href="/city">city</a>'],
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertStringContainsString('<strong>the</strong>', $html);
    self::assertStringContainsString('<a href="/city">city</a>', $html);
  }

  /**
   * Tests a border style select offers WordPress's three styles.
   *
   * WordPress 7.1.2 (components.js BORDER_STYLES) offers Solid, Dashed and
   * Dotted only, while the schema accepts every CSS keyword.
   */
  public function testBorderStyleSelectListsWordPressStyles(): void {
    $form = $this->container->get('form_builder')->buildForm($this->formObject('wordpal:columns'), new FormState());
    $select = $form['component']['props']['wordpal_styles']['wordpal_border']['border_style']['source']['value'];

    self::assertSame(
      ['' => '- Select -', 'solid' => 'Solid', 'dashed' => 'Dashed', 'dotted' => 'Dotted'],
      array_map('strval', $select['#options']),
    );
  }

  /**
   * Tests a saved style outside WordPress's three stays selectable.
   */
  public function testBorderStyleSelectKeepsSavedStyle(): void {
    $form = $this->container->get('form_builder')->buildForm($this->formObject('wordpal:columns', ['border_style' => 'double']), new FormState());
    $select = $form['component']['props']['wordpal_styles']['wordpal_border']['border_style']['source']['value'];

    self::assertSame(['', 'solid', 'dashed', 'dotted', 'double'], array_keys($select['#options']));
  }

  /**
   * Tests enum labels are WordPress's editor labels.
   *
   * WordPress 7.1.2: block-editor.js verticalAlignmentOptions labels the
   * vertical value "Space between"; its justify control says "Space between
   * items".
   */
  public function testEnumLabelsMatchWordPress(): void {
    $props = $this->container->get('plugin.manager.sdc')->getDefinitions()['wordpal:columns']['props']['properties'];

    self::assertSame('Space between items', $props['justify_content']['meta:enum']['space-between']);
    self::assertSame('Space between', $props['vertical_alignment']['meta:enum']['space-between']);
    self::assertSame('Align middle', $props['vertical_alignment']['meta:enum']['center']);
    self::assertSame('Stretch to fill', $props['vertical_alignment']['meta:enum']['stretch']);
    self::assertSame('Justify items left', $props['justify_content']['meta:enum']['left']);
  }

  /**
   * A form holding one component_form element for the given component.
   *
   * @param string $componentId
   *   The component id.
   * @param array<string, string> $values
   *   Select values the component already holds, keyed by prop.
   */
  private function formObject(string $componentId, array $values = []): FormInterface {
    return new class ($componentId, $values) extends FormBase {

      public function __construct(protected readonly string $componentId, protected readonly array $values) {}

      /**
       * {@inheritdoc}
       */
      public function getFormId(): string {
        return 'wordpal_display_builder_test_enum_form';
      }

      /**
       * {@inheritdoc}
       */
      public function buildForm(array $form, FormStateInterface $form_state): array {
        $element = [
          '#type' => 'component_form',
          '#default_value' => [
            'component_id' => $this->componentId,
            'props' => array_map(
              static fn (string $value): array => ['source_id' => 'select', 'source' => ['value' => $value]],
              $this->values,
            ),
          ],
        ];
        // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
        \Drupal::moduleHandler()->alter('ui_patterns_form', $element, $form_state);
        $form['component'] = $element;
        return $form;
      }

      /**
       * {@inheritdoc}
       */
      public function submitForm(array &$form, FormStateInterface $form_state): void {}

    };
  }

}
