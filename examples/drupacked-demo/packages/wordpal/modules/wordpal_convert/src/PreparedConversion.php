<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\WordPress\Snapshot;

/**
 * The checked trees ConversionRunner::prepare() hands to write().
 */
final readonly class PreparedConversion {

  /**
   * Constructs a prepared conversion.
   *
   * @param string $target
   *   The Builder target, so write() asks WriterSelector for the same
   *   writer prepare() checked everything against.
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The converted snapshot.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The validated Content mapping.
   * @param \Drupal\wordpal_convert\Theme\ResolvedPattern[] $patterns
   *   Inserter patterns, keyed as the snapshot keys them.
   * @param array<string, \Drupal\wordpal_convert\Theme\PartSet> $partSets
   *   Part sets keyed by page variant id.
   * @param array<string, array> $partTrees
   *   Each Part set's parts without their unconvertible Queries, with their
   *   loops, keyed by page variant id.
   * @param array<string, \Drupal\wordpal_convert\Theme\ResolvedTemplate> $templates
   *   The converting routes' Templates, keyed by route.
   * @param array $intended
   *   The ownership manifest saved before the writes.
   * @param string[] $wired
   *   The site settings that pointed at the replaced conversion's outputs,
   *   as ConversionOwnership::delete() returns them.
   * @param string[] $takeover
   *   Ids of the disabled, unmanaged content templates activation replaces.
   * @param string[] $skipped
   *   Items preflight left out.
   * @param array<string, string[]> $unregistered
   *   Trees using each unregistered block namespace.
   * @param array<string, string[]> $invalid
   *   Names of the blocks WordPress's own parser found invalid, keyed by
   *   tree label.
   * @param array<string, string[]> $frozen
   *   The blocks each tree writes as Frozen blocks, keyed by tree label.
   * @param array<string, string[]> $droppedOptions
   *   The block options each tree writes without their WordPress behavior,
   *   keyed by tree label.
   * @param array<string, array> $menus
   *   The menus holding Navigation blocks' own links, keyed by menu id, as
   *   NavigationMenus::collect() returns them.
   * @param array<string, string[]> $frozenStyles
   *   The plugin stylesheets of each tree's Frozen blocks, keyed by tree
   *   label, as ConversionReport lists them.
   * @param array<string, string[]> $unreadStyles
   *   The style values each tree sets that no prop reads, keyed by tree
   *   label, as ConversionReport lists them.
   * @param string[] $concepts
   *   The content concepts the Templates, part sets and Patterns read, as
   *   TreeConcepts names them.
   * @param string[] $pluginLicenses
   *   One line per plugin whose files the generated theme holds, as
   *   ThemeLicense::pluginLine() builds it.
   */
  public function __construct(
    public string $target,
    public Snapshot $snapshot,
    public ContentMapping $mapping,
    public array $patterns,
    public array $partSets,
    public array $partTrees,
    public array $templates,
    public array $intended,
    public array $wired,
    public array $takeover,
    public array $skipped,
    public array $unregistered,
    public array $invalid,
    public array $frozen,
    public array $droppedOptions,
    public array $menus,
    public array $frozenStyles,
    public array $unreadStyles,
    public array $concepts,
    public array $pluginLicenses,
  ) {}

}
