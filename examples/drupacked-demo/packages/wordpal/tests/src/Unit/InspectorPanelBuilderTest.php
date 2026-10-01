<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Render\Element;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Component\InspectorPanel;
use Drupal\wordpal\Component\InspectorPanelBuilder;
use Drupal\wordpal\Support\PresetShape;
use Drupal\wordpal\Support\PresetSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests grouping a component's fields into the WordPress inspector's tabs.
 */
#[CoversClass(InspectorPanelBuilder::class)]
#[Group('wordpal')]
final class InspectorPanelBuilderTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The panel/tab titles, and the preset select's own labels, go through
    // $this->t().
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Tests a field of each panel lands under its tab, in inspector order.
   */
  public function testGroupsPanelsUnderTheirTabsInInspectorOrder(): void {
    $tabs = $this->builder()->tabs([
      'content' => ['content' => ['#type' => 'textfield']],
      'settings' => ['level' => ['#type' => 'select']],
      'advanced' => ['anchor' => ['#type' => 'textfield']],
      'typography' => ['font_size' => ['#type' => 'textfield']],
    ]);

    self::assertSame(['wordpal_content', 'wordpal_settings', 'wordpal_styles'], array_keys($tabs));
    // Content's own panel sits directly in the tab, not a nested details.
    self::assertArrayHasKey('content', $tabs['wordpal_content']);
    self::assertSame('details', $tabs['wordpal_content']['#type']);
    self::assertTrue($tabs['wordpal_content']['#open']);
    self::assertFalse($tabs['wordpal_settings']['#open']);
    self::assertSame(['level', 'wordpal_advanced'], Element::children($tabs['wordpal_settings'], TRUE));
    self::assertSame(['font_size'], Element::children($tabs['wordpal_styles']['wordpal_typography']));
  }

  /**
   * Tests a select keeps the editor options and the current value.
   */
  public function testLimitOptionsKeepsTheCurrentValue(): void {
    $options = ['' => '- Select -', 'none' => 'None', 'solid' => 'Solid', 'dashed' => 'Dashed', 'double' => 'Double'];
    $schema = [InspectorPanel::OPTIONS_KEY => ['solid', 'dashed']];

    self::assertSame(['' => '- Select -', 'solid' => 'Solid', 'dashed' => 'Dashed'], $this->builder()->limitOptions($options, $schema, 'solid'));
    self::assertSame(['' => '- Select -', 'solid' => 'Solid', 'dashed' => 'Dashed', 'double' => 'Double'], $this->builder()->limitOptions($options, $schema, 'double'));
    self::assertSame($options, $this->builder()->limitOptions($options, [], 'double'), 'A prop with no editor options lists every value.');
  }

  /**
   * Tests a component with no fields in any known panel builds no tab.
   */
  public function testEmptyPanelsBuildNoTabs(): void {
    self::assertSame([], $this->builder()->tabs([]));
  }

  /**
   * Tests the select offers the theme's presets plus a custom entry.
   */
  public function testPresetSelectOffersThemeTokensPlusCustomEntry(): void {
    $tokens = [
      'color' => [
        'base' => ['$description' => 'Base'],
        'contrast' => ['$description' => 'Contrast'],
      ],
    ];

    $select = $this->builder()->presetSelect('text_color', new PresetSupport('color', PresetShape::Slug), $tokens, 'Text color');

    self::assertSame('text_color', $select['#attributes']['data-wordpal-preset-for']);
    self::assertSame(
      ['' => 'Default', 'base' => 'Base', 'contrast' => 'Contrast', '__custom' => 'Custom…'],
      $this->options($select),
    );
    // A reference-shaped preset stores the theme.json reference, not the
    // bare slug the slug shape stores.
    $reference = $this->builder()->presetSelect('link_color', new PresetSupport('color', PresetShape::Reference), $tokens, 'Link color');
    self::assertSame(
      [
        '' => 'Default',
        'var:preset|color|base' => 'Base',
        'var:preset|color|contrast' => 'Contrast',
        '__custom' => 'Custom…',
      ],
      $this->options($reference),
    );
  }

  /**
   * Tests a prop whose group the theme defines nothing for gets no select.
   *
   * The paired field a caller holds keeps whatever value it already stores:
   * this method never touches it, only ever returning NULL or a fresh select.
   */
  public function testPresetSelectIsNullWhenTheThemeHasNoSuchGroup(): void {
    self::assertNull($this->builder()->presetSelect('gradient', new PresetSupport('gradient', PresetShape::Slug), ['color' => []], 'Gradient'));
  }

  /**
   * Builds the panel builder, with services none of these tests exercise.
   */
  private function builder(): InspectorPanelBuilder {
    return new InspectorPanelBuilder(
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(ThemeExtensionList::class),
      '/app',
    );
  }

  /**
   * Returns a select's option values keyed by their labels.
   */
  private function options(array $select): array {
    $options = [];
    foreach (Element::children($select) as $key) {
      $options[$select[$key]['#attributes']['value']] = (string) $select[$key]['#value'];
    }
    return $options;
  }

}
