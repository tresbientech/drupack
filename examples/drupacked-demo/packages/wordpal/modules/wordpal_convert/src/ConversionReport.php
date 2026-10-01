<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

/**
 * What one wordpal:convert run produced.
 */
final readonly class ConversionReport {

  /**
   * The report line stating Frozen blocks run no plugin script.
   */
  public const PLUGIN_SCRIPTS = 'Plugin scripts are not exported: a Frozen block prints its HTML and plugin CSS without the plugin\'s behavior.';

  /**
   * Constructs a conversion report.
   *
   * @param string[] $created
   *   Lines of the form "entity_type:id" for every entity the run created.
   * @param string[] $skipped
   *   Structural items left out: a Template that failed to parse, a single
   *   or page Template whose write the writer rejected, a tree whose Query
   *   blocks require a dropped post, or a style variation whose title makes
   *   no usable slug.
   * @param string[] $skippedPatterns
   *   Patterns left out because a preflight or write error rejected them.
   * @param array<string, int> $dropped
   *   Counts of mapped blocks dropped, keyed by content concept.
   * @param array<string, string> $providers
   *   The Drupal feature providing each concept the mapping sets to null.
   * @param array<string, string[]> $unregistered
   *   Labels of the trees using each block namespace that no active plugin
   *   registers, keyed by namespace.
   * @param array<string, string[]> $invalid
   *   Names of the blocks WordPress's own parser found invalid, keyed by
   *   tree label.
   * @param array<string, string[]> $frozen
   *   The name of each Frozen block a tree wrote, once per block, keyed by
   *   tree label.
   * @param array{uuids: array<string, string[]>, skipped: string[]}|null $demoContent
   *   UUIDs of the seeded Demo content keyed by entity type and the menu
   *   items it left out, or NULL when the run seeded none.
   * @param array<string, string[]> $droppedOptions
   *   The block options each tree writes without their WordPress behavior,
   *   keyed by tree label.
   * @param string[] $disabledViews
   *   Ids of the site's Views whose pages the converted listing Templates
   *   replaced.
   * @param string[] $takenOverTemplates
   *   Ids of the site's disabled content templates the converted Templates
   *   replaced.
   * @param string $targetLabel
   *   The Builder target's name, printed in the listing Templates line.
   * @param string[] $templatePaths
   *   The path each listing Template with an editor of its own opens at. On
   *   display_builder the term Templates get no path.
   * @param array<string, string> $variations
   *   The title of each style variation the generated theme offers, keyed
   *   by the slug its theme setting stores.
   * @param array<string, array> $menus
   *   The menus created for Navigation blocks' own links, keyed by menu id,
   *   each with its label and the labels of the trees using it.
   * @param array<string, string[]> $frozenStyles
   *   Lines such as "woocommerce/mini-cart: wc-blocks-style", one per
   *   Frozen block carrying plugin stylesheets, keyed by tree label.
   * @param array<string, string[]> $unreadStyles
   *   Lines such as "core/image: style.color.duotone", one per WordPress
   *   control a converted block sets and no prop reads, keyed by tree label.
   * @param string[] $pluginLicenses
   *   Lines such as "woocommerce 9.3.1: GPLv3 (WordPress.org)", one per
   *   plugin whose files the generated theme holds.
   */
  public function __construct(
    public array $created,
    public array $skipped,
    public array $skippedPatterns,
    public array $dropped,
    public array $providers,
    public array $unregistered,
    public array $invalid,
    public array $frozen,
    public ?array $demoContent,
    public array $droppedOptions,
    public array $disabledViews,
    public array $takenOverTemplates,
    public string $targetLabel,
    public array $templatePaths,
    public array $variations,
    public array $menus,
    public array $frozenStyles,
    public array $unreadStyles,
    public array $pluginLicenses,
  ) {}

  /**
   * Returns one line per created menu, with the trees whose blocks bind it.
   *
   * @return string[]
   *   Lines such as "wordpal-0123456789ab (Theme: About): theme/footer".
   */
  public function menuLines(): array {
    $lines = [];
    foreach ($this->menus as $id => $menu) {
      $lines[] = "$id ({$menu['label']}): " . implode(', ', $menu['trees']);
    }
    return $lines;
  }

  /**
   * Returns one line per style variation.
   *
   * @return string[]
   *   Lines such as "ember: Ember".
   */
  public function variationLines(): array {
    $lines = [];
    foreach ($this->variations as $slug => $title) {
      $lines[] = "$slug: $title";
    }
    return $lines;
  }

  /**
   * Returns one line per unregistered namespace, with the trees using it.
   *
   * @return string[]
   *   Lines such as "woocommerce: Template part header, theme/hero".
   */
  public function unregisteredLines(): array {
    $lines = [];
    foreach ($this->unregistered as $namespace => $trees) {
      $lines[] = "$namespace: " . implode(', ', $trees);
    }
    return $lines;
  }

  /**
   * Returns one line per tree holding Frozen blocks.
   *
   * @return string[]
   *   Lines such as "Template part header: woocommerce/mini-cart", with a
   *   count after a block that freezes more than once.
   */
  public function frozenLines(): array {
    $lines = [];
    foreach ($this->frozen as $tree => $blocks) {
      $names = [];
      foreach (array_count_values($blocks) as $name => $count) {
        $names[] = $count > 1 ? "$name ($count)" : $name;
      }
      $lines[] = "$tree: " . implode(', ', $names);
    }
    return $lines;
  }

  /**
   * Returns one line per tree holding a block WordPress's parser rejects.
   *
   * @return string[]
   *   Lines such as "Template part header: core/image", with a count after
   *   a block invalid more than once.
   */
  public function invalidLines(): array {
    $lines = [];
    foreach ($this->invalid as $tree => $blocks) {
      $names = [];
      foreach (array_count_values($blocks) as $name => $count) {
        $names[] = $count > 1 ? "$name ($count)" : $name;
      }
      $lines[] = "$tree: " . implode(', ', $names);
    }
    return $lines;
  }

  /**
   * Returns one line per Frozen block carrying plugin stylesheets.
   *
   * @return string[]
   *   Lines such as "Template part header: woocommerce/mini-cart:
   *   wc-blocks-style, wc-blocks-style-mini-cart".
   */
  public function frozenStyleLines(): array {
    $lines = [];
    foreach ($this->frozenStyles as $tree => $blocks) {
      foreach (array_unique($blocks) as $block) {
        $lines[] = "$tree: $block";
      }
    }
    return $lines;
  }

  /**
   * Returns one line per WordPress control a converted block keeps unedited.
   *
   * @return string[]
   *   Lines such as "Template single: core/image: style.color.duotone".
   */
  public function unreadStyleLines(): array {
    $lines = [];
    foreach ($this->unreadStyles as $tree => $controls) {
      foreach (array_unique($controls) as $control) {
        $lines[] = "$tree: $control";
      }
    }
    return $lines;
  }

  /**
   * Returns one line per block option written without its behavior.
   *
   * @return string[]
   *   Lines such as "Template part footer: core/latest-posts: full post
   *   content, rendered as the excerpt".
   */
  public function droppedOptionLines(): array {
    $lines = [];
    foreach ($this->droppedOptions as $tree => $options) {
      foreach (array_unique($options) as $option) {
        $lines[] = "$tree: $option";
      }
    }
    return $lines;
  }

  /**
   * Returns one line per Demo content entity type the conversion seeded.
   *
   * @return string[]
   *   The entity types with at least one seeded entity, with their counts.
   */
  public function demoContentLines(): array {
    $lines = [];
    foreach ($this->demoContent['uuids'] as $type => $uuids) {
      if ($uuids !== []) {
        $lines[] = "$type: " . count($uuids);
      }
    }
    return $lines;
  }

  /**
   * Returns one line per concept with dropped blocks, naming its provider.
   *
   * @return string[]
   *   The concepts with at least one dropped block.
   */
  public function droppedLines(): array {
    $lines = [];
    foreach (array_filter($this->dropped) as $concept => $count) {
      $line = "$concept: $count dropped " . ($count === 1 ? 'block' : 'blocks');
      if (isset($this->providers[$concept])) {
        $line .= '; add ' . $this->providers[$concept] . ' and map it';
      }
      $lines[] = $line;
    }
    return $lines;
  }

}
