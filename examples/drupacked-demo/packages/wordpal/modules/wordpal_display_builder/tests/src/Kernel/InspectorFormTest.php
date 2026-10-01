<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Kernel;

use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\KernelTests\KernelTestBase;
use Drupal\wordpal_display_builder\Hook\InspectorFormHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the WordPress inspector tabs and panels on a ui_patterns prop form.
 *
 * Real WordPal components are discovered from the enabled `wordpal` module,
 * the same `wordpal:heading` component wordpal_canvas's own InspectorFormTest
 * groups into panels, so both targets are proven against one fixture.
 */
#[CoversClass(InspectorFormHooks::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class InspectorFormTest extends KernelTestBase {

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
   * Tests that a heading's props sit in WordPress's tabs and panels.
   */
  public function testGroupsHeadingPropsIntoInspectorPanels(): void {
    $props = $this->headingPropsForm();

    self::assertSame(['wordpal_content', 'wordpal_settings', 'wordpal_styles'], $this->children($props));
    self::assertSame('details', $props['wordpal_content']['#type']);
    self::assertTrue($props['wordpal_content']['#open']);
    // ui_patterns adds its own "attributes" prop with no x-wordpal-panel; it
    // falls into Content, the same bucket an unpaneled runtime prop uses on
    // Canvas.
    self::assertSame(['attributes', 'content'], $this->children($props['wordpal_content']));
    self::assertSame(['level', 'align', 'wordpal_advanced'], $this->children($props['wordpal_settings']));
    self::assertFalse($props['wordpal_settings']['#open']);
    self::assertSame(['anchor', 'css_class'], $this->children($props['wordpal_settings']['wordpal_advanced']));
    self::assertSame(
      ['wordpal_typography', 'wordpal_color', 'wordpal_dimensions', 'wordpal_border'],
      $this->children($props['wordpal_styles']),
    );
    self::assertSame('Border & Shadow', (string) $props['wordpal_styles']['wordpal_border']['#title']);
    self::assertContains('font_size', $this->children($props['wordpal_styles']['wordpal_typography']));

    // Moving a prop keeps the parents its value submits under.
    self::assertSame(
      ['component', 'props', 'content'],
      $props['wordpal_content']['content']['#parents'],
    );
  }

  /**
   * Tests a color prop offers a select of the default theme's presets.
   *
   * `wordpal_canvas`'s own InspectorFormTest proves the same fixture theme's
   * tokens on this same component; this proves the shared builder offers
   * them here too, through this module's own hook.
   */
  public function testOffersThemePresetsOnColorProp(): void {
    $this->container->get(ThemeInstallerInterface::class)->install(['wordpal_tokens_test']);
    $this->config('system.theme')->set('default', 'wordpal_tokens_test')->save();

    $form = $this->headingForm();
    $textColor = $form['props']['wordpal_styles']['wordpal_color']['text_color'];

    self::assertSame('text_color', $textColor['wordpal_presets']['#attributes']['data-wordpal-preset-for']);
    self::assertSame(
      [
        '' => 'Default',
        'var:preset|color|base' => 'Base',
        'var:preset|color|contrast' => 'Contrast',
        '__custom' => 'Custom…',
      ],
      $this->options($textColor),
    );
  }

  /**
   * Tests a preset select is paired to the prop's own value field.
   *
   * Matches Canvas's own pairing: the select carries no name, so the value
   * field stays the only input the source plugin reads.
   */
  public function testPairsPresetSelectToTheValueField(): void {
    $this->container->get(ThemeInstallerInterface::class)->install(['wordpal_tokens_test']);
    $this->config('system.theme')->set('default', 'wordpal_tokens_test')->save();

    $form = $this->headingForm();
    $textColor = $form['props']['wordpal_styles']['wordpal_color']['text_color'];

    self::assertSame('text_color', $textColor['source']['value']['#attributes']['data-wordpal-preset']);
    self::assertContains('wordpal/inspector_presets', $form['#attached']['library']);
  }

  /**
   * Tests a preset select stays unpaired when its prop has no value field.
   *
   * An editor can switch a preset prop's source away from a plain widget (to
   * a token or an entity field source), which drops the "value" key the
   * pairing writes to; the theme still lists the preset, but nothing is
   * wired to store an editor's choice.
   */
  public function testSkipsPairingWithoutValueField(): void {
    $this->container->get(ThemeInstallerInterface::class)->install(['wordpal_tokens_test']);
    $this->config('system.theme')->set('default', 'wordpal_tokens_test')->save();

    $element = [
      '#component_id' => 'wordpal:heading',
      'props' => [
        'text_color' => [
          '#title' => 'Text color',
          'source' => ['#type' => 'container'],
        ],
      ],
    ];

    $result = InspectorFormHooks::groupProps($element, new FormState());
    $textColor = $result['props']['wordpal_styles']['wordpal_color']['text_color'];

    self::assertArrayHasKey('wordpal_presets', $textColor);
    self::assertArrayNotHasKey('value', $textColor['source']);
    self::assertArrayNotHasKey('library', $result['#attached'] ?? []);
  }

  /**
   * Tests a preset chosen as the form stores it renders WordPress's class.
   *
   * The source is the shape the preset select's script writes into the
   * prop's textfield source (the stored value is the preset reference).
   */
  public function testChosenPresetRendersWordPressClass(): void {
    $props = [
      'content' => ['source_id' => 'textfield', 'source' => ['value' => 'Hello']],
      'text_color' => ['source_id' => 'textfield', 'source' => ['value' => 'var:preset|color|contrast']],
    ];
    $source = [
      'source_id' => 'component',
      'source' => [
        'component' => ['component_id' => 'wordpal:heading', 'variant_id' => NULL, 'props' => $props],
      ],
    ];
    $data = [];
    $build = $this->container->get('ui_patterns.component_element_builder')->buildSource($data, 'content', [], $source, []);

    $html = (string) $this->container->get('renderer')->renderInIsolation($build['#slots']['content'][0]);

    self::assertMatchesRegularExpression('/<h2[^>]* class="[^"]*\bhas-text-color\b/', $html);
    self::assertMatchesRegularExpression('/<h2[^>]* class="[^"]*\bhas-contrast-color\b/', $html);
  }

  /**
   * Tests a component outside the `wordpal` provider is left alone.
   */
  public function testLeavesAnotherProvidersComponentAlone(): void {
    $element = ['#type' => 'component_form', '#default_value' => ['component_id' => 'ui_patterns:some_component']];
    $before = $element;

    $this->container->get(InspectorFormHooks::class)->formAlter($element, new FormState());

    self::assertSame($before, $element);
  }

  /**
   * Tests a non-component_form element is left alone.
   */
  public function testLeavesAnUnknownFormShapeAlone(): void {
    $element = ['#type' => 'textfield'];
    $before = $element;

    $this->container->get(InspectorFormHooks::class)->formAlter($element, new FormState());

    self::assertSame($before, $element);
  }

  /**
   * Builds the wordpal:heading component_form and returns its props element.
   */
  private function headingPropsForm(): array {
    return $this->headingForm()['props'];
  }

  /**
   * Builds and returns the wordpal:heading component_form element.
   */
  private function headingForm(): array {
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm($this->formObject(), $form_state);
    return $form['component'];
  }

  /**
   * A form holding one component_form element for wordpal:heading.
   *
   * Runs the element through the real hook dispatch, as ui_patterns' own
   * ComponentSource::settingsForm() does, rather than calling the hook
   * class directly.
   */
  private function formObject(): FormInterface {
    return new class extends FormBase {

      /**
       * {@inheritdoc}
       */
      public function getFormId(): string {
        return 'wordpal_display_builder_test_component_form';
      }

      /**
       * {@inheritdoc}
       */
      public function buildForm(array $form, FormStateInterface $form_state): array {
        $element = [
          '#type' => 'component_form',
          '#default_value' => ['component_id' => 'wordpal:heading'],
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

  /**
   * Returns the keys of an element's children, in render order.
   */
  private function children(array $element): array {
    return Element::children($element, TRUE);
  }

  /**
   * Returns the options of a preset field's select, as value => label.
   */
  private function options(array $field): array {
    $options = [];
    foreach (Element::children($field['wordpal_presets']) as $key) {
      $option = $field['wordpal_presets'][$key];
      $options[$option['#attributes']['value']] = (string) $option['#value'];
    }
    return $options;
  }

}
