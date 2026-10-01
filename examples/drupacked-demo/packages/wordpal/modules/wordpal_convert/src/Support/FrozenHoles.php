<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Support;

use Dom\HTMLDocument;
use Dom\XPath;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal_convert\Theme\BlockNode;

/**
 * Returns the HTML a Frozen block's theme file holds.
 *
 * The file holds the HTML WordPress rendered for the block, with each
 * left-out Query cut out and each kept Query replaced by a numbered hole.
 * The writer places a kept Query's View in the hole's slot. A Query inside
 * another Query is part of the outer Query's View.
 */
final class FrozenHoles {

  /**
   * Returns a Frozen block's file HTML and hole Queries, or why it has none.
   *
   * @return array{html: string, queries: \Drupal\wordpal_convert\Theme\BlockNode[]}|string
   *   html() for the block, or the reason the theme cannot store its HTML.
   */
  public static function file(BlockNode $node): array|string {
    if ($node->rendered === NULL) {
      return 'the block render index holds no HTML for it';
    }
    try {
      $file = self::html($node);
    }
    catch (\UnexpectedValueException $exception) {
      return $exception->getMessage();
    }
    $problem = FrozenHtmlCheck::problem($file['html']);
    if ($problem !== NULL) {
      return "its rendered HTML $problem";
    }
    // A marker inside a comment, raw text or an attribute parses as no
    // comment of its own, and a cut can join the parts of one.
    if (self::markerComments($file['html']) !== count($file['queries'])) {
      return 'its hole markers do not each sit between elements';
    }
    return $file;
  }

  /**
   * Returns the key, name and file HTML of each Frozen block with a file.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $frozen
   *   The blocks a writer freezes.
   *
   * @return array<int, array{key: string, name: string, html: string}>
   *   One entry per key. Blocks of one tree that rendered the same HTML
   *   hold the same Queries, so they cut the same HTML and share one file.
   */
  public static function stored(array $frozen): array {
    $stored = [];
    foreach ($frozen as $node) {
      $file = self::file($node);
      if (is_string($file)) {
        continue;
      }
      $key = FrozenBlock::key($node->rendered);
      $stored[$key] = ['key' => $key, 'name' => $node->name, 'html' => $file['html']];
    }
    return array_values($stored);
  }

  /**
   * Returns the holed HTML and the Queries filling its holes, in order.
   *
   * @return array{html: string, queries: \Drupal\wordpal_convert\Theme\BlockNode[]}
   *   The HTML, and the Query of hole N at index N - 1.
   *
   * @throws \UnexpectedValueException
   *   When the rendered HTML holds a Query's or a cut HTML other than once,
   *   holds a hole marker of its own, or holds more Queries than
   *   FrozenBlock::HOLES.
   */
  public static function html(BlockNode $node): array {
    $html = (string) $node->rendered;
    if (str_contains($html, FrozenBlock::HOLE_PREFIX)) {
      throw new \UnexpectedValueException('its rendered HTML holds a WordPal hole marker');
    }
    foreach ($node->cut as $cut) {
      $html = self::replaceOnce($html, $cut, '', 'a left-out Query');
    }
    $queries = self::queries($node->children);
    if (count($queries) > FrozenBlock::HOLES) {
      throw new \UnexpectedValueException(sprintf('it holds %d Queries, and a Frozen block places %d', count($queries), FrozenBlock::HOLES));
    }
    $offset = -1;
    foreach ($queries as $index => $query) {
      $html = self::replaceOnce($html, (string) $query->rendered, FrozenBlock::hole($index + 1), 'an inner core/query');
      $at = strpos($html, FrozenBlock::hole($index + 1));
      // The template fills the holes in file order.
      if ($at < $offset) {
        throw new \UnexpectedValueException('its rendered HTML holds its Queries in another order than its inner blocks');
      }
      $offset = $at;
    }
    return ['html' => $html, 'queries' => $queries];
  }

  /**
   * Replaces the one occurrence of a block's HTML.
   */
  private static function replaceOnce(string $html, string $search, string $replace, string $what): string {
    $count = $search === '' ? 0 : substr_count($html, $search);
    if ($count !== 1) {
      throw new \UnexpectedValueException("its rendered HTML holds the HTML of $what $count times");
    }
    return str_replace($search, $replace, $html);
  }

  /**
   * Returns the number of comments that are a whole hole marker.
   */
  private static function markerComments(string $html): int {
    $document = HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html, LIBXML_NOERROR);
    $count = 0;
    foreach ((new XPath($document))->query('//comment()') as $comment) {
      $count += (int) FrozenBlock::isHole('<!--' . $comment->data . '-->');
    }
    return $count;
  }

  /**
   * Returns the outermost Query blocks of a tree, in tree order.
   *
   * A Frozen block's holes hold these Queries' Views.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at one level.
   */
  public static function queries(array $nodes): array {
    $queries = [];
    foreach ($nodes as $node) {
      $queries = $node->name === 'core/query' ? [...$queries, $node] : [...$queries, ...self::queries($node->children)];
    }
    return $queries;
  }

}
