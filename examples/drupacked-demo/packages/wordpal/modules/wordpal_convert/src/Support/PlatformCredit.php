<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Support;

use Dom\Document;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Dom\XPath;

/**
 * Credits Drupal where a theme credits WordPress as its platform.
 *
 * "Powered by WordPress" becomes "Powered by <s>WordPress</s>Drupal", with
 * the link on Drupal. A credit to a WordPress theme, such as "WordPress Theme
 * by SuperbThemes", stays as it is.
 */
final class PlatformCredit {

  /**
   * The link the credit gets.
   */
  private const DRUPAL_URL = 'https://www.drupal.org';

  /**
   * The name the credit gives.
   */
  private const DRUPAL_NAME = 'Drupal';

  /**
   * The words that end a credit's text before WordPress.
   *
   * An optional "<name> and" credits the theme's author first, as "Proudly
   * powered by Ovation Themes and WordPress".
   */
  private const LEAD = '/(?:^|[^\p{L}])(?:(?:proudly\s+)?powered\s+(?:by|with)|designed\s+with|built\s+(?:with|on)|(?:affectionately\s+)?created\s+with|made\s+with|running\s+on|gestaltet\s+mit)(?:\s*\S.{0,60}?\s*\.?\s*(?:and|&))?\s*$/iu';

  /**
   * The word, outside a URL or a longer name such as WordPress.org.
   */
  private const WORD = '/(?<![\p{L}\d_\/.-])wordpress(?![\p{L}\d_-])(?!\.\w)/iu';

  /**
   * Text after the word that makes the credit a theme's own.
   */
  private const THEME_CREDIT = '/^\s*(?:block\s+)?(?:themes?|blocks?|block\s+patterns?)\b/iu';

  /**
   * Elements that stay inside one sentence.
   */
  private const INLINE = [
    'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'em', 'i', 'kbd', 'mark',
    'q', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var',
  ];

  /**
   * Rewrites the credits in an HTML fragment.
   *
   * Returns the fragment unchanged when it holds no credit, so markup
   * without one keeps its exact bytes.
   */
  public static function rewriteHtml(string $html): string {
    if (stripos($html, 'wordpress') === FALSE) {
      return $html;
    }
    $document = HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html, LIBXML_NOERROR);
    return self::rewrite($document->body) === 0 ? $html : $document->body->innerHTML;
  }

  /**
   * Rewrites the credits under an element in place.
   *
   * @return int
   *   The number of credits rewritten.
   */
  private static function rewrite(Element $root): int {
    $texts = (new XPath($root->ownerDocument))->query('.//text()[contains(translate(., "WORDPRES", "wordpres"), "wordpress")][not(ancestor::script or ancestor::style or ancestor::template or ancestor::title)]', $root);
    $count = 0;
    foreach (iterator_to_array($texts) as $text) {
      $count += self::rewriteText($text);
    }
    return $count;
  }

  /**
   * Rewrites the credit a text node ends, if it ends one.
   */
  private static function rewriteText(Text $text): int {
    if (!preg_match(self::WORD, $text->data, $match, PREG_OFFSET_CAPTURE)) {
      return 0;
    }
    [$word, $offset] = $match[0];
    $container = $text->parentNode;
    while (self::isInline($container)) {
      $container = $container->parentNode;
    }
    [$before, $after] = self::around($container, $text);
    if (!preg_match(self::LEAD, self::collapse($before . substr($text->data, 0, $offset)))
      || preg_match(self::THEME_CREDIT, self::collapse(substr($text->data, $offset + strlen($word)) . $after))) {
      return 0;
    }

    // DOM offsets count characters, preg offsets count bytes.
    $wordNode = $text->splitText(mb_strlen(substr($text->data, 0, $offset)));
    $wordNode->splitText(mb_strlen($word));
    $struck = $text->ownerDocument->createElement('s');
    $wordNode->before($struck);
    $struck->append($wordNode);

    $link = $struck->parentNode;
    while (self::isInline($link) && $link->localName !== 'a') {
      $link = $link->parentNode;
    }
    if ($link->localName !== 'a') {
      $struck->after(self::drupalLink($text->ownerDocument));
    }
    elseif (preg_match('/^\s*wordpress\s*\.?\s*$/iu', $link->textContent)) {
      $link->replaceWith(...$link->childNodes);
      $struck->after(self::drupalLink($text->ownerDocument));
    }
    else {
      // A link around the whole credit, a Button block's among them, keeps
      // its element and classes and points at Drupal, so no link nests in
      // another.
      $link->setAttribute('href', self::DRUPAL_URL);
      $struck->after(self::DRUPAL_NAME);
    }
    return 1;
  }

  /**
   * Returns a container's text before and after one of its text nodes.
   *
   * A nested block element ends the sentence on either side.
   *
   * @return string[]
   *   The text before, then the text after.
   */
  private static function around(Node $container, Text $text): array {
    $before = $after = '';
    $state = 'before';
    $walk = static function (Node $node) use (&$walk, &$before, &$after, &$state, $text): void {
      foreach ($node->childNodes as $child) {
        if ($child === $text) {
          $state = 'after';
        }
        elseif (self::isInline($child)) {
          $walk($child);
        }
        elseif ($child instanceof Element) {
          if ($state === 'before') {
            $before = '';
          }
          else {
            $state = 'done';
          }
        }
        elseif ($child instanceof Text) {
          match ($state) {
            'before' => $before .= $child->data,
            'after' => $after .= $child->data,
            'done' => NULL,
          };
        }
      }
    };
    $walk($container);
    return [$before, $after];
  }

  /**
   * Returns whether a node is an element that stays inside a sentence.
   */
  private static function isInline(?Node $node): bool {
    return $node instanceof Element && in_array($node->localName, self::INLINE, TRUE);
  }

  /**
   * Returns the link to Drupal.
   */
  private static function drupalLink(Document $document): Element {
    $link = $document->createElement('a');
    $link->setAttribute('href', self::DRUPAL_URL);
    $link->append(self::DRUPAL_NAME);
    return $link;
  }

  /**
   * Collapses whitespace, non-breaking spaces included, to one space.
   */
  private static function collapse(string $text): string {
    return preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
  }

}
