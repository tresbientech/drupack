<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\wordpal\Support\PropSchema;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Support\PlatformCredit;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\DrupalRouteFrame;

/**
 * Maps a WordPress block's own attributes onto a wordpal SDC's own props.
 *
 * The mapping is target-independent: the SDC's own prop schema decides
 * which WordPress attribute lands on which prop, examples fill what the
 * block leaves out, and casting and enum checks are the component's own.
 * CanvasWriter uses this for every block it converts to a generated
 * component; QuerySettingsWriter uses it for the fixed runtime components a
 * Query's own View renders through on every target.
 */
final class ComponentInputs {

  /**
   * The SDC provider prefix every wordpal component shares.
   */
  private const COMPONENT_PROVIDER = 'wordpal';

  /**
   * Prop formats whose JSON Schema validation rejects a bare "#".
   */
  private const URL_FORMATS = ['uri', 'uri-reference'];

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly AttributeFlattener $flattener,
  ) {}

  /**
   * Turns block attributes into component inputs.
   *
   * A prop the block leaves out falls back to the block's own default, which
   * the definition carries as its first example.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The block.
   * @param string[] $skipped
   *   Collects values no prop accepts.
   */
  public function forNode(BlockNode $node, array &$skipped): array {
    $props = $this->componentPluginManager
      ->find(self::COMPONENT_PROVIDER . ':' . $node->slug())
      ->metadata->schema['properties'] ?? [];
    $values = $this->flattener->flatten($node->attributes, $node->name);
    // Post Content holding Drupal's main content shows no post, so its post
    // data props take no sample value.
    $noSample = in_array(DrupalRouteFrame::MAIN_CONTENT, array_column($node->children, 'name'), TRUE)
      ? ComponentDefinitionAlter::RUNTIME_PROPS[$node->slug()]
      : [];

    $inputs = [];
    foreach ($props as $prop => $schema) {
      if (!array_key_exists($prop, $values)) {
        if (isset($schema['examples'][0]) && !isset($inputs[$prop]) && !in_array($prop, $noSample, TRUE)) {
          $inputs[$prop] = $schema['examples'][0];
        }
        continue;
      }
      // The style engine prints no declaration for a value that is not a
      // string (WP_Style_Engine_CSS_Declarations::add_declaration()), such
      // as a number lineHeight on a Post Title.
      if (!is_string($values[$prop]) && PropSchema::isStyleEngineValue($node->name, $prop)) {
        continue;
      }
      $value = $this->cast($values[$prop], $schema);
      // WordPress samples link to "#", which these URL formats reject.
      if ($value === '#' && in_array($schema['format'] ?? NULL, self::URL_FORMATS, TRUE)) {
        continue;
      }
      // WordPress saves no class for an empty enum attribute, such as a Media
      // & Text block with "align": "".
      if ($value === '' && isset($schema['enum']) && !in_array('', $schema['enum'], TRUE)) {
        continue;
      }
      if (isset($schema['enum']) && !in_array($value, $schema['enum'], TRUE)) {
        // Markup from older WordPress releases aligns text through `align`.
        if ($prop === 'align' && isset($props['text_align']) && in_array($value, $props['text_align']['enum'], TRUE)) {
          $inputs['text_align'] = $value;
          continue;
        }
        $skipped[] = sprintf('%s: %s="%s"', $node->name, $prop, $value);
        continue;
      }
      if (($schema['contentMediaType'] ?? NULL) === 'text/html') {
        $value = PlatformCredit::rewriteHtml($value);
      }
      $inputs[$prop] = $value;
    }
    return $inputs;
  }

  /**
   * Casts a WordPress value to the type the prop declares.
   */
  private function cast(mixed $value, array $schema): mixed {
    if (!array_key_exists('type', $schema)) {
      throw new \UnexpectedValueException('Component prop schema has no type.');
    }
    // ComponentMetadata::parseSchemaInfo() appends "object" to every prop's
    // type, to allow deferring rendering in Twig to the render pipeline.
    $type = $schema['type'];
    if (is_array($type)) {
      $type = $type[0];
    }
    return match ($type) {
      'integer' => (int) $value,
      'number' => $value + 0,
      'boolean' => (bool) $value,
      'string' => (string) $value,
      default => throw new \UnexpectedValueException("Unsupported component prop type: $type"),
    };
  }

  /**
   * Returns the props a component takes from the content mapping.
   */
  public static function mapped(BlockNode $node, ContentMapping $mapping): array {
    if ($node->name !== 'core/query-title') {
      return [];
    }
    $inputs = [];
    foreach (['tag', 'category'] as $concept) {
      if ($mapping->has($concept) && !$mapping->drops($concept)) {
        $inputs[$concept . '_vocabulary'] = $mapping->target($concept)['vocabulary'];
      }
    }
    if ($mapping->has('search') && !$mapping->drops('search')) {
      $inputs['search_path'] = $mapping->target('search')['path'];
      $inputs['search_parameter'] = $mapping->target('search')['parameter'];
    }
    return $inputs;
  }

  /**
   * Returns whether a node's children sit in a Template part and a Gallery.
   *
   * A Template part referenced inside a tree is a Group marked
   * templatePart.
   *
   * @return array{0: bool, 1: bool}
   *   Whether the children are in a Template part, then in a Gallery.
   */
  public static function childContext(BlockNode $node, bool $inTemplatePart, bool $inGallery): array {
    return [
      $inTemplatePart || ($node->name === 'core/group' && ($node->attributes['templatePart'] ?? FALSE)),
      $inGallery || $node->name === 'core/gallery',
    ];
  }

  /**
   * Returns the props an image block takes from its ancestors.
   *
   * WordPress decodes a Template part's images async, and prints a
   * Gallery image's data-id.
   */
  public static function contextInputs(BlockNode $node, bool $inTemplatePart, bool $inGallery): array {
    $inputs = [];
    if ($inTemplatePart && in_array($node->name, DefinitionGenerator::TEMPLATE_PART_IMAGE_BLOCKS, TRUE)) {
      $inputs['in_template_part'] = TRUE;
    }
    if ($inGallery && $node->name === 'core/image') {
      $inputs['in_gallery'] = TRUE;
    }
    return $inputs;
  }

}
