<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Kernel;

use Drupal\canvas\ComponentIncompatibilityReasonRepository;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks Canvas requirements and editable schemas for the WordPress catalog.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ComponentCatalogTest extends CanvasKernelTestBase {

  /**
   * Maps an SDC prop's x-formatting-context to its CKEditor field shape.
   *
   * Mirrors \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType's own
   * "inline" and "block" arms: [field_type, field_widget, text format].
   */
  private const FORMATTING_CONTEXT_FIELDS = [
    'inline' => ['text', 'text_textfield', 'canvas_html_inline'],
    'block' => ['text_long', 'text_textarea', 'canvas_html_block'],
  ];

  /**
   * Modules needed by WordPal's components and Canvas source discovery.
   *
   * @var string[]
   */
  protected static $modules = [
    'comment',
    'wordpal',
    'wordpal_convert',
    'wordpal_canvas', 'wordpal_canvas_runtime',
  ];

  /**
   * Runs Canvas's source requirements checks for every mapped component.
   */
  public function testCanvasRequirementsForEveryComponent(): void {
    $blocks = $this->providerDefinitions('plugin.manager.block');
    $sdcs = $this->providerDefinitions('plugin.manager.sdc');
    self::assertNotSame([], $blocks, 'wordpal must provide at least one block plugin.');
    self::assertNotSame([], $sdcs, 'wordpal must provide at least one SDC component.');

    $manager = $this->container->get(ComponentSourceManager::class);
    $manager->generateComponents('block', array_keys($blocks));
    $manager->generateComponents('sdc', array_keys($sdcs));

    $incompatibilities = $this->container->get(ComponentIncompatibilityReasonRepository::class)->getReasons();
    $failures = [];
    foreach (array_keys($blocks) as $id) {
      $failures = [...$failures, ...$this->componentFailures('block.' . $id, $id, 'block', $incompatibilities)];
    }
    $sdcManager = $this->container->get(ComponentPluginManager::class);
    foreach (array_keys($sdcs) as $id) {
      $componentId = 'sdc.' . str_replace(':', '.', $id);
      $failures = [...$failures, ...$this->componentFailures($componentId, $id, 'sdc', $incompatibilities)];
      $component = Component::load($componentId);
      if ($component === NULL) {
        continue;
      }
      $propFieldDefinitions = $component->getSettings()['prop_field_definitions'];
      $properties = $sdcManager->find($id)->metadata->schema['properties'] ?? [];
      foreach ($properties as $prop => $schema) {
        if (!isset($schema['x-formatting-context'])) {
          continue;
        }
        $failures = [
          ...$failures,
          ...$this->richTextPropFailures($id, $prop, $schema['x-formatting-context'], $propFieldDefinitions[$prop] ?? NULL),
        ];
      }
    }
    self::assertSame([], $failures, implode("\n", $failures));
  }

  /**
   * Returns wordpal's own plugin definitions from one discovery manager.
   */
  private function providerDefinitions(string $service): array {
    return array_filter(
      $this->container->get($service)->getDefinitions(),
      static fn (array $definition): bool => ($definition['provider'] ?? NULL) === 'wordpal',
    );
  }

  /**
   * Returns why a component failed Canvas's requirements, or an empty list.
   */
  private function componentFailures(string $componentId, string $block, string $source, array $incompatibilities): array {
    $component = Component::load($componentId);
    if ($component === NULL) {
      return ["$block did not meet Canvas requirements: " . json_encode($incompatibilities[$source][$componentId] ?? [])];
    }
    if (!$component->status()) {
      return ["$block Canvas component is disabled"];
    }
    if ($source !== 'sdc') {
      return [];
    }
    $failures = [];
    foreach ($component->getSettings()['prop_field_definitions'] as $name => $definition) {
      if (!array_key_exists('field_type', $definition)) {
        $failures[] = "$block.$name has no editable field type";
      }
      if (!array_key_exists('field_widget', $definition)) {
        $failures[] = "$block.$name has no editable field widget";
      }
    }
    return $failures;
  }

  /**
   * Returns why a rich-text prop does not use its expected CKEditor field.
   */
  private function richTextPropFailures(string $block, string $prop, string $formattingContext, ?array $definition): array {
    if ($definition === NULL) {
      return ["$block.$prop has no editable prop definition"];
    }
    [$fieldType, $fieldWidget, $format] = self::FORMATTING_CONTEXT_FIELDS[$formattingContext];
    $failures = [];
    if (($definition['field_type'] ?? NULL) !== $fieldType) {
      $failures[] = "$block.$prop field_type must be $fieldType";
    }
    if (($definition['field_widget'] ?? NULL) !== $fieldWidget) {
      $failures[] = "$block.$prop field_widget must be $fieldWidget";
    }
    if (($definition['field_instance_settings']['allowed_formats'] ?? NULL) !== [$format]) {
      $failures[] = "$block.$prop must allow only the $format format";
    }
    $editor = $this->container->get('config.factory')->get("editor.editor.$format")->get('editor');
    if ($editor !== 'ckeditor5') {
      $failures[] = "$format must use the ckeditor5 editor, got " . ($editor ?? 'none');
    }
    return $failures;
  }

}
