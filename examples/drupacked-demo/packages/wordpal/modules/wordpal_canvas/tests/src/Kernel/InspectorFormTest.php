<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Form\ComponentInstanceForm;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\wordpal\Support\PropSchema;
use Drupal\wordpal_canvas\Hook\InspectorFormHooks;
use Drupal\wordpal_convert\Theme\DesignTokens;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the WordPress inspector tabs and panels on Canvas's instance form.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class InspectorFormTest extends CanvasKernelTestBase {

  /**
   * The component instance the form edits.
   */
  private const UUID = '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'comment',
    'wordpal',
    'wordpal_convert',
    'wordpal_canvas', 'wordpal_canvas_runtime',
  ];

  /**
   * Makes the fixture theme the default theme and adds the heading component.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ThemeInstallerInterface::class)->install(['canvas_stark', 'wordpal_tokens_test']);
    $this->config('system.theme')->set('default', 'wordpal_tokens_test')->save();
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['wordpal:heading']);
  }

  /**
   * Tests that the fixture theme holds the tokens conversion writes.
   */
  public function testFixtureTokensMatchGeneratedTokens(): void {
    $module = dirname(__DIR__, 3);
    // The conversion fixture lives with wordpal_convert, which owns the
    // conversion runner and writers; wordpal_canvas keeps no copy of its own.
    $convertModule = dirname($module) . '/wordpal_convert';
    $presets = json_decode((string) file_get_contents("$convertModule/tests/fixtures/snapshot/wordpal-fixture/out/presets.json"), TRUE);

    self::assertSame(
      DesignTokens::yaml($presets),
      file_get_contents("$module/tests/themes/wordpal_tokens_test/wordpal_tokens_test.tokens.yml"),
    );
  }

  /**
   * Tests that a heading's fields sit in WordPress's tabs and panels.
   */
  public function testGroupsHeadingPropsIntoInspectorPanels(): void {
    $instance = $this->instanceForm('sdc.wordpal.heading')['canvas_component_props'][self::UUID];

    self::assertSame(['wordpal_content', 'wordpal_settings', 'wordpal_styles'], $this->children($instance));
    self::assertSame('details', $instance['wordpal_content']['#type']);
    self::assertTrue($instance['wordpal_content']['#open']);
    self::assertSame(['content'], $this->children($instance['wordpal_content']));
    self::assertSame(['level', 'align', 'wordpal_advanced'], $this->children($instance['wordpal_settings']));
    self::assertSame(['anchor', 'css_class'], $this->children($instance['wordpal_settings']['wordpal_advanced']));
    self::assertSame(
      ['wordpal_typography', 'wordpal_color', 'wordpal_dimensions', 'wordpal_border'],
      $this->children($instance['wordpal_styles']),
    );
    self::assertSame('Border & Shadow', (string) $instance['wordpal_styles']['wordpal_border']['#title']);
    self::assertContains('font_size', $this->children($instance['wordpal_styles']['wordpal_typography']));

    // Moving a field keeps the name its value is submitted under.
    $content = $instance['wordpal_content']['content']['widget'];
    self::assertSame(['canvas_component_props', self::UUID, 'content'], $content['#parents']);
  }

  /**
   * Tests a border style select offers WordPress's three styles.
   *
   * WordPress 7.1.2 (components.js BORDER_STYLES) offers Solid, Dashed and
   * Dotted only, while the schema accepts every CSS keyword.
   */
  public function testBorderStyleSelectListsWordPressStyles(): void {
    $border = $this->instanceForm('sdc.wordpal.heading')['canvas_component_props'][self::UUID]['wordpal_styles']['wordpal_border'];

    self::assertSame(
      ['_none' => '- None -', 'solid' => 'Solid', 'dashed' => 'Dashed', 'dotted' => 'Dotted'],
      array_map('strval', $border['border_style']['widget']['#options']),
    );
  }

  /**
   * Tests that preset props offer the default theme's tokens in a select.
   */
  public function testSuggestsThemePresets(): void {
    $styles = $this->instanceForm('sdc.wordpal.heading')['canvas_component_props'][self::UUID]['wordpal_styles'];

    $fontSize = $styles['wordpal_typography']['font_size'];
    self::assertSame('font_size', $fontSize['wordpal_presets']['#attributes']['data-wordpal-preset-for']);
    self::assertSame('font_size', $fontSize['widget'][0]['value']['#attributes']['data-wordpal-preset']);
    self::assertSame(
      ['' => 'Default', 'small' => 'Small', 'large' => 'Large', '__custom' => 'Custom…'],
      $this->options($fontSize),
    );
    // The field stays a text field, so a custom value is still allowed.
    self::assertSame('textfield', $fontSize['widget'][0]['value']['#type']);

    self::assertSame(
      ['' => 'Default', 'base' => 'Base', 'contrast' => 'Contrast', '__custom' => 'Custom…'],
      $this->options($styles['wordpal_color']['text_color']),
    );
    // A prop that stores a preset reference gets the reference as its value.
    self::assertSame(
      [
        '' => 'Default',
        'var:preset|color|base' => 'Base',
        'var:preset|color|contrast' => 'Contrast',
        '__custom' => 'Custom…',
      ],
      $this->options($styles['wordpal_color']['link_color']),
    );
    self::assertSame(
      ['' => 'Default', 'var:preset|spacing|10' => '1', 'var:preset|spacing|20' => '2', '__custom' => 'Custom…'],
      $this->options($styles['wordpal_dimensions']['padding_top']),
    );
    self::assertSame(
      ['' => 'Default', 'body' => 'Body', '__custom' => 'Custom…'],
      $this->options($styles['wordpal_typography']['font_family']),
    );
    // The theme defines no gradients.
    self::assertArrayNotHasKey('wordpal_presets', $styles['wordpal_color']['gradient']);
    self::assertArrayNotHasKey('wordpal_presets', $styles['wordpal_typography']['line_height']);
  }

  /**
   * Tests a preset name from theme.json renders as text, never markup.
   */
  public function testEscapesPresetNames(): void {
    $field = ['widget' => [0 => ['value' => ['#title' => 'Text color']]]];
    $tokens = ['color' => ['base' => ['$description' => '<a href="/x"><b>Base</b></a> & co']]];
    $withPresets = new \ReflectionMethod(InspectorFormHooks::class, 'withPresets');
    $field = $withPresets->invoke($this->container->get(InspectorFormHooks::class), 'text_color', PropSchema::preset('text_color'), $field, $tokens);

    $html = (string) $this->container->get('renderer')->renderInIsolation($field['wordpal_presets']);

    self::assertStringContainsString('<option value="base">&lt;a href=&quot;/x&quot;&gt;&lt;b&gt;Base&lt;/b&gt;&lt;/a&gt; &amp; co</option>', $html);
  }

  /**
   * Tests the markup Canvas's sidebar theme renders from the altered form.
   */
  public function testRendersPanelsAndSuggestionsInCanvasTheme(): void {
    $form = $this->instanceForm('sdc.wordpal.heading');
    $theme = $this->container->get('theme.initialization')->initTheme('canvas_stark');
    $this->container->get('theme.manager')->setActiveTheme($theme);

    $html = (string) $this->container->get('renderer')->renderInIsolation($form);

    $content = strpos($html, '>Content<');
    $settings = strpos($html, '>Settings<');
    $styles = strpos($html, '>Styles<');
    self::assertNotFalse($content);
    self::assertGreaterThan($content, $settings);
    self::assertGreaterThan($settings, $styles);
    self::assertStringContainsString('<drupal-canvas-details', $html);
    // Canvas's theme passes a field's attributes to its React input as JSON.
    self::assertStringContainsString('&quot;data-wordpal-preset&quot;:&quot;font_size&quot;', $html);
    self::assertMatchesRegularExpression('/<select data-wordpal-preset-for="font_size"(?![^>]*\bname=)[^>]*>/', $html);
    self::assertMatchesRegularExpression('/<option value="var:preset\|spacing\|10"[^>]*>1<\/option>/', $html);
    self::assertStringContainsString('data-wordpal-custom="__custom"', $html);
    self::assertMatchesRegularExpression('/<option value="__custom"[^>]*>Custom…<\/option>/', $html);
    // Only the text field is named under the prop, the name Canvas reads.
    self::assertSame(1, substr_count($html, 'name="canvas_component_props[' . self::UUID . '][font_size]'));
  }

  /**
   * Tests an unrecognized or incomplete component form is left alone.
   */
  public function testLeavesAnUnknownFormShapeAlone(): void {
    $form = ['#type' => 'form', 'other' => ['#type' => 'textfield']];
    $before = $form;
    $this->container->get(InspectorFormHooks::class)->formAlter($form, new FormState());
    self::assertSame($before, $form);

    // The tree and selection fields are present, but the selected uuid has
    // no entry under canvas_component_props: the form is left alone too.
    $form = [
      '#type' => 'form',
      'form_canvas_tree' => ['#value' => '{}'],
      'form_canvas_selected' => ['#value' => self::UUID],
    ];
    $before = $form;
    $this->container->get(InspectorFormHooks::class)->formAlter($form, new FormState());
    self::assertSame($before, $form);
  }

  /**
   * Builds Canvas's instance form for one component, as its sidebar asks.
   */
  private function instanceForm(string $componentId): array {
    $component = Component::load($componentId);
    self::assertNotNull($component, "Canvas generated $componentId.");
    $request = Request::create('/', 'POST', [
      'form_canvas_tree' => json_encode([
        'nodeType' => 'component',
        'slots' => [],
        'type' => $componentId . '@' . $component->getActiveVersion(),
        'uuid' => self::UUID,
      ], JSON_THROW_ON_ERROR),
      'form_canvas_props' => json_encode(['source' => [], 'resolved' => []], JSON_THROW_ON_ERROR),
      'form_canvas_selected' => self::UUID,
    ]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $pattern = Pattern::create(['id' => 'test', 'label' => 'Test', 'component_tree' => []]);
    return $this->container->get('form_builder')->getForm(ComponentInstanceForm::class, $pattern);
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
