<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\ui_patterns\Element\ComponentForm;
use Drupal\wordpal\Component\InspectorPanel;
use Drupal\wordpal\Component\InspectorPanelBuilder;
use Drupal\wordpal\Support\PropSchema;
use Drupal\wordpal_display_builder\SourceTreeBuilder;

/**
 * Lays out a WordPal component's ui_patterns prop form as the WP inspector.
 *
 * The hook fires on the bare `component_form` element, before its own
 * #process callback expands it into per-prop fields, so grouping those
 * fields happens in an #after_build appended here instead: #after_build
 * callbacks run only once every child, including each prop's own widget,
 * has finished building. A preset prop also gets a select of the default
 * theme's presets here, the same select Canvas's own form alter offers.
 */
final class InspectorFormHooks {

  /**
   * Implements hook_ui_patterns_form_alter().
   */
  #[Hook('ui_patterns_form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $componentId = $form['#component_id'] ?? $form['#default_value']['component_id'] ?? NULL;
    if (($form['#type'] ?? NULL) !== 'component_form' || !is_string($componentId) || !str_starts_with($componentId, SourceTreeBuilder::PROVIDER . ':')) {
      return;
    }
    // #process is left untouched, so the element type's own default keeps
    // building component_id/variant_id/slots/props as usual.
    $form['#after_build'] = [[ComponentForm::class, 'afterBuild'], [self::class, 'groupProps']];
  }

  /**
   * Groups a fully built props form into Content, Settings and Styles.
   *
   * An #after_build callback on component_form, so must stay callable
   * without an instance: form elements carry #after_build across requests,
   * and only a static callback is safe to store there.
   */
  public static function groupProps(array $element, FormStateInterface $form_state): array {
    $componentId = $element['#component_id'] ?? $element['#default_value']['component_id'] ?? NULL;
    if (!is_string($componentId) || !isset($element['props'])) {
      return $element;
    }
    $properties = \Drupal::service(ComponentPluginManager::class)->getDefinition($componentId)['props']['properties'] ?? [];
    $panelBuilder = \Drupal::service(InspectorPanelBuilder::class);
    $tokens = NULL;
    $panels = [];
    $pairsAPreset = FALSE;
    foreach ($properties as $name => $schema) {
      if (!isset($element['props'][$name])) {
        continue;
      }
      $field = $element['props'][$name];
      $group = PropSchema::presetGroup($name);
      if ($group !== NULL) {
        // Read once per form build, and only once a prop needs it.
        $tokens ??= $panelBuilder->defaultThemeTokens();
        $select = $panelBuilder->presetSelect($name, $group, $tokens, $field['#title'] ?? $name);
        if ($select !== NULL) {
          $field['wordpal_presets'] = $select;
          // An editor can switch a prop's source to a token or an entity
          // field. The select then has no value field to write into.
          if (isset($field['source']['value']['#type'])) {
            $field['source']['value']['#attributes']['data-wordpal-preset'] = $name;
            $pairsAPreset = TRUE;
          }
        }
      }
      if (isset($field['source']['value']['#options'])) {
        $field['source']['value']['#options'] = $panelBuilder->limitOptions($field['source']['value']['#options'], $schema, $field['source']['value']['#default_value'] ?? NULL);
      }
      $panels[$schema[InspectorPanel::KEY] ?? 'content'][$name] = $field;
      unset($element['props'][$name]);
    }
    if ($pairsAPreset) {
      $element['#attached']['library'][] = 'wordpal/inspector_presets';
    }
    // ui_patterns' own "Props" heading precedes every prop; the tabs replace
    // it, so nothing but #-prefixed properties should remain here.
    foreach (Element::children($element['props']) as $key) {
      unset($element['props'][$key]);
    }
    $element['props'] += $panelBuilder->tabs($panels);
    return $element;
  }

}
