<?php

declare(strict_types=1);

namespace Drupal\wordpal_canvas\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\wordpal\Component\InspectorPanel;
use Drupal\wordpal\Component\InspectorPanelBuilder;
use Drupal\wordpal\Support\PropSchema;
use Drupal\wordpal_canvas\CanvasWriter;

/**
 * Lays out Canvas's component form as the WordPress block inspector.
 *
 * Canvas builds a flat list of fields. This groups a WordPress component's
 * fields into Content, Settings and Styles, with one collapsible panel per
 * inspector panel, and offers the default theme's presets on preset props.
 */
final class InspectorFormHooks {

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly InspectorPanelBuilder $panelBuilder,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for Canvas's component instance form.
   */
  #[Hook('form_component_instance_form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $selection = self::selection($form);
    if ($selection === NULL) {
      return;
    }
    [$componentId, $instanceUuid] = $selection;
    $prefix = 'sdc.' . CanvasWriter::COMPONENT_PROVIDER . '.';
    if (!str_starts_with($componentId, $prefix)) {
      return;
    }
    $sdcId = CanvasWriter::COMPONENT_PROVIDER . ':' . substr($componentId, strlen($prefix));
    $properties = $this->componentPluginManager->getDefinition($sdcId)['props']['properties'];
    $instance = &$form['canvas_component_props'][$instanceUuid];
    $instance['#attached']['library'][] = 'wordpal/inspector_presets';

    $tokens = NULL;
    $panels = [];
    foreach ($properties as $name => $schema) {
      // Canvas builds no field for a prop it cannot store, and none for any
      // prop of a component whose source is broken.
      if (!isset($instance[$name])) {
        continue;
      }
      $field = $instance[$name];
      $group = PropSchema::presetGroup($name);
      if ($group !== NULL) {
        // Read once per form build, and only once a prop needs it.
        $tokens ??= $this->panelBuilder->defaultThemeTokens();
        $field = $this->withPresets($name, $group, $field, $tokens);
      }
      if (isset($field['widget']['#options'])) {
        $field['widget']['#options'] = $this->panelBuilder->limitOptions($field['widget']['#options'], $schema, ((array) ($field['widget']['#default_value'] ?? []))[0] ?? NULL);
      }
      // Runtime props carry no panel, and hold the post data a block shows.
      $panels[$schema[InspectorPanel::KEY] ?? 'content'][$name] = $field;
      unset($instance[$name]);
    }
    $instance += $this->panelBuilder->tabs($panels);
  }

  /**
   * Returns the selected component id and instance uuid, or NULL.
   *
   * Canvas's ComponentInstanceForm carries them in three hidden fields that
   * canvas.api.php does not document; a Canvas release may rename them.
   *
   * @return array{0: string, 1: string}|null
   *   The component id and the instance uuid.
   */
  private static function selection(array $form): ?array {
    $tree = $form['form_canvas_tree']['#value'] ?? NULL;
    $selected = $form['form_canvas_selected']['#value'] ?? NULL;
    if (!is_string($tree) || !is_string($selected) || !isset($form['canvas_component_props'][$selected])) {
      return NULL;
    }
    $type = json_decode($tree, TRUE)['type'] ?? NULL;
    if (!is_string($type)) {
      return NULL;
    }
    return [explode('@', $type)[0], $selected];
  }

  /**
   * Adds a select of the theme's presets to a preset prop's text field.
   *
   * The text field stays the only input Canvas reads: the select the shared
   * builder returns has no name, so Canvas stores what it stored before.
   */
  private function withPresets(string $name, string $group, array $field, array $tokens): array {
    $select = $this->panelBuilder->presetSelect($name, $group, $tokens, $field['widget'][0]['value']['#title']);
    if ($select === NULL) {
      return $field;
    }
    $field['widget'][0]['value']['#attributes']['data-wordpal-preset'] = $name;
    $field['wordpal_presets'] = $select;
    return $field;
  }

}
