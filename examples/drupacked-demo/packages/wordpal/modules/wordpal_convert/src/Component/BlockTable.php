<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

/**
 * Renders the block support table as Markdown.
 */
final class BlockTable {

  /**
   * Returns the Markdown document.
   *
   * @param string $version
   *   The WordPress version the rows were generated from.
   * @param array<string, array> $rows
   *   BlockCoverage::rows() rows, keyed by WordPress block name.
   * @param array{blocks: array<string, array[]>, site: array[]} $notes
   *   The hand-kept notes. Each note holds "note" and an optional "builder"
   *   and "ref", a path relative to docs/.
   * @param array{crawl_date: string, themes: int, share: array<string, float>} $usage
   *   The theme census share of each core block.
   */
  public static function render(string $version, array $rows, array $notes, array $usage): string {
    $unknown = array_diff(array_keys($notes['blocks']), array_keys($rows));
    if ($unknown !== []) {
      throw new \InvalidArgumentException('The block notes name blocks WordPress ' . $version . ' lacks: ' . implode(', ', $unknown));
    }
    $share = $usage['share'];
    $missing = array_diff(array_keys($rows), array_keys($share));
    if ($missing !== []) {
      throw new \InvalidArgumentException('The block usage has no share for: ' . implode(', ', $missing));
    }
    uksort($rows, static fn (string $a, string $b): int => [$share[$b], $a] <=> [$share[$a], $b]);
    $lines = [
      '# Block support',
      '',
      "This table lists every core block of WordPress $version and its Drupal target. `drush wordpal:block-table` writes it from `components/block-coverage.json`, `components/block-notes.yml` and `components/block-usage.json`. Edit those, not this page.",
      '',
      'A block gets one of six targets:',
      '',
      '- Expanded: the conversion replaces the reference with the blocks it points to.',
      '- Component: an SDC component in `components/`.',
      '- Block plugin: a WordPal Drupal block plugin.',
      "- View: a Drupal View built from the block's listing settings.",
      "- Part of: the parent block's Drupal target renders it.",
      '- Frozen: the HTML WordPress rendered, fixed. Editors cannot change it. A Query inside it renders as its View, in a hole slot.',
      '',
      sprintf('Usage is the share of the %s active block themes whose markup uses the block, from the theme census of %s. Rows run from the most used block.', number_format($usage['themes']), $usage['crawl_date']),
      '',
      'The concept column names the content a block binds to. A block drops from the conversion when the content mapping sets its concept to null.',
      '',
      '| Block | Usage | Target | Concept | Notes |',
      '|---|---|---|---|---|',
    ];
    foreach ($rows as $name => $row) {
      $lines[] = sprintf(
        '| `%s` | %.1f%% | %s | %s | %s |',
        $name,
        $share[$name] * 100,
        self::target($row['target']),
        $row['concept'] === NULL ? '' : "`{$row['concept']}`",
        implode('<br>', [...self::notes($row), ...array_map(self::note(...), $notes['blocks'][$name] ?? [])]),
      );
    }
    $lines[] = '';
    $lines[] = '## Differences on every page';
    $lines[] = '';
    foreach ($notes['site'] as $note) {
      $lines[] = '- ' . self::note($note);
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * Returns the notes of one row, one line each.
   */
  private static function notes(array $row): array {
    $notes = [];
    if ($row['skipped'] !== []) {
      // DefinitionGenerator names each item as "<kind> <name>".
      $items = array_map(static function (string $item): string {
        [$kind, $name] = explode(' ', $item, 2);
        return "$kind `$name`";
      }, $row['skipped']);
      $notes[] = 'Not in the component: ' . implode(', ', $items) . '.';
    }
    foreach ($row['dropped'] as $option) {
      $notes[] = 'On conversion: ' . $option . '.';
    }
    return $notes;
  }

  /**
   * Returns one hand-kept note as Markdown.
   */
  private static function note(array $note): string {
    $text = $note['note'];
    if (isset($note['builder'])) {
      $builder = match ($note['builder']) {
        'canvas' => 'Canvas',
        'display_builder' => 'Display Builder',
      };
      $text = "($builder) $text";
    }
    if (isset($note['ref'])) {
      $label = preg_match('#^adr/(\d{4})-#', $note['ref'], $match) ? 'ADR ' . $match[1] : basename($note['ref'], '.md');
      $text .= " ([$label]({$note['ref']}))";
    }
    return $text;
  }

  /**
   * Returns the target cell of one row.
   */
  private static function target(array $target): string {
    return match ($target['kind']) {
      'component' => "Component `{$target['id']}`",
      'plugin' => "Block plugin `{$target['id']}`",
      'view' => "View, `{$target['id']}` listing",
      'parent' => "Part of `{$target['id']}`",
      'expanded' => 'Expanded',
      'frozen' => 'Frozen',
    };
  }

}
