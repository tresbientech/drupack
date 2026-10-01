<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

use Drupal\wordpal_convert\Listing\ListingBlockSettings;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\ReferenceExpander;
use Drupal\wordpal_convert\Theme\TreeConcepts;

/**
 * Lists the Drupal target of every WordPress core block.
 */
final class BlockCoverage {

  /**
   * Returns one row per core block, keyed and sorted by block name.
   *
   * @param array<string, array> $library
   *   Each core block's block.json, keyed by block name.
   * @param string[] $components
   *   The slugs of the generated components.
   * @param array<string, string> $parents
   *   The parent block name of each block its parent's Drupal target
   *   consumes, keyed by block name.
   * @param array<string, string[]> $skipped
   *   What DefinitionGenerator left out of each component, keyed by block
   *   name.
   *
   * @return array<string, array{target: array{kind: string, id: ?string}, concept: ?string, skipped: string[], dropped: string[]}>
   *   The rows. The concept is the one a block with default attributes
   *   binds to.
   */
  public static function rows(array $library, array $components, array $parents, array $skipped): array {
    $rows = [];
    foreach (array_keys($library) as $name) {
      $rows[$name] = [
        'target' => self::target($name, $components, $parents),
        'concept' => TreeConcepts::concept(new BlockNode($name, [], '', [])),
        // Only a generated component has skipped items.
        'skipped' => $skipped[$name] ?? [],
        'dropped' => isset(TreeConcepts::DROPPED_OPTIONS[$name]) ? [TreeConcepts::DROPPED_OPTIONS[$name]] : [],
      ];
    }
    ksort($rows);
    return $rows;
  }

  /**
   * Returns one block's target, first match wins.
   */
  private static function target(string $name, array $components, array $parents): array {
    $pluginId = BlockPluginInputs::pluginId($name);
    $slug = substr($name, strlen('core/'));
    $reference = in_array($name, [ReferenceExpander::PATTERN, ReferenceExpander::TEMPLATE_PART], TRUE);
    return match (TRUE) {
      $reference => ['kind' => 'expanded', 'id' => NULL],
      $pluginId !== NULL => ['kind' => 'plugin', 'id' => $pluginId],
      isset(ListingBlockSettings::KINDS[$name]) => ['kind' => 'view', 'id' => ListingBlockSettings::KINDS[$name]],
      in_array($slug, $components, TRUE) => ['kind' => 'component', 'id' => $slug],
      isset($parents[$name]) => ['kind' => 'parent', 'id' => $parents[$name]],
      default => ['kind' => 'frozen', 'id' => NULL],
    };
  }

}
