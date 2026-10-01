<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Support;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Checks that a Frozen block's HTML holds no construct that can run script.
 *
 * The generated theme prints a Frozen block's HTML unfiltered on every page
 * that places it, and WordPress or a plugin rendered that HTML. The HTML
 * passes only when it parses as a closed HTML5 fragment and every element,
 * attribute and style it holds is on the safe side of each rule below.
 */
final class FrozenHtmlCheck {

  /**
   * The image types a CSS url() may hold as a data: URL, as a regex group.
   */
  private const CSS_DATA_IMAGES = 'png|jpeg|gif|webp|avif|svg\\+xml';

  /**
   * The HTML namespace.
   */
  private const HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

  /**
   * The element appended after the HTML to find where the fragment ends.
   *
   * No HTML or SVG element has this name, so foreign content keeps it in
   * its own namespace instead of breaking out.
   */
  private const END = 'wordpal-frozen-end';

  /**
   * Elements that can run script or embed a document.
   *
   * The list also holds the elements a browser that runs script parses
   * differently from this check, such as noscript.
   */
  private const ELEMENTS = [
    'applet', 'base', 'embed', 'frame', 'frameset', 'iframe', 'meta',
    'noembed', 'noframes', 'noscript', 'object', 'plaintext', 'script',
    'template', 'xmp',
  ];

  /**
   * Returns why a Frozen block's HTML is unsafe, or NULL when it is safe.
   */
  public static function problem(string $html): ?string {
    if (!mb_check_encoding($html, 'UTF-8')) {
      return 'is not valid UTF-8';
    }
    if (str_contains($html, "\0")) {
      return 'holds a NUL byte';
    }
    if (stripos($html, self::END) !== FALSE) {
      return 'holds the name ' . self::END;
    }
    $document = HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html . '<' . self::END . '></' . self::END . '>', LIBXML_NOERROR);
    $end = $document->getElementsByTagName(self::END);
    // An open comment, attribute, raw text element or foreign element
    // swallows or re-parents the marker, and the page markup after it.
    if ($end->length !== 1 || $end->item(0)->namespaceURI !== self::HTML_NAMESPACE) {
      return 'leaves a comment, tag, raw text or SVG element open';
    }
    // A body or html start tag in the fragment adds its attributes to the
    // page's own element.
    if ($document->body->attributes->length > 0 || $document->documentElement->attributes->length > 0) {
      return 'sets attributes on the body or html element';
    }
    foreach ($document->body->getElementsByTagName('*') as $element) {
      if (($problem = self::elementProblem($element)) !== NULL) {
        return $problem;
      }
    }
    return NULL;
  }

  /**
   * Returns why one element or its attributes are unsafe, or NULL.
   */
  private static function elementProblem(Element $element): ?string {
    $name = strtolower($element->localName);
    if (in_array($name, self::ELEMENTS, TRUE) && !self::isUtf8Charset($element)) {
      return "holds the element $element->localName";
    }
    if ($name === 'style' && ($problem = self::cssProblem($element->textContent)) !== NULL) {
      return "holds a style element that $problem";
    }
    foreach ($element->attributes as $attribute) {
      $attributeName = strtolower($attribute->localName);
      if (str_starts_with($attributeName, 'on')) {
        return "holds the event handler attribute $attribute->name";
      }
      // An SVG animation can set an href or an event handler.
      if ($attributeName === 'attributename') {
        return "holds an SVG animation of $attribute->value";
      }
      // cssProblem() checks the data: URLs of a style attribute.
      $image = ($name === 'img' && in_array($attributeName, ['src', 'srcset'], TRUE)) || $attributeName === 'style';
      if (($scheme = self::scheme($attribute->value, $image)) !== NULL) {
        return "holds a $scheme URL in $attribute->name";
      }
      if ($attributeName === 'style' && ($problem = self::cssProblem($attribute->value)) !== NULL) {
        return "holds a style attribute that $problem";
      }
    }
    return NULL;
  }

  /**
   * Returns TRUE for a <meta charset="utf-8"> and nothing else.
   *
   * Drupal sends UTF-8 in the Content-Type header, so a browser ignores a
   * charset declared in the body. A plugin's DOMDocument round trip leaves
   * this element in rendered blocks.
   */
  private static function isUtf8Charset(Element $element): bool {
    return strtolower($element->localName) === 'meta'
      && $element->attributes->length === 1
      && strtolower((string) $element->getAttribute('charset')) === 'utf-8';
  }

  /**
   * Returns the unsafe URL scheme a value holds, or NULL when it holds none.
   *
   * @param string $value
   *   An attribute value or CSS text, entities already decoded.
   * @param bool $image
   *   Whether the value is an image source, where a data: URL is an image.
   */
  private static function scheme(string $value, bool $image): ?string {
    // The URL parser drops tabs and newlines anywhere in a URL.
    $value = strtolower((string) preg_replace('/[\t\n\r]+/', '', $value));
    $schemes = $image ? 'javascript|vbscript' : 'javascript|vbscript|data';
    // A scheme starts a value, a srcset candidate or a CSS url().
    if (preg_match('/(?:^|[^a-z0-9+.-])(' . $schemes . '):/', $value, $match)) {
      return $match[1] . ':';
    }
    return NULL;
  }

  /**
   * Returns why CSS is unsafe, or NULL when it is safe.
   */
  private static function cssProblem(string $css): ?string {
    if (str_contains($css, '\\')) {
      return 'holds a CSS escape';
    }
    $css = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $css));
    if (str_contains($css, 'expression(')) {
      return 'holds a CSS expression()';
    }
    if (str_contains($css, 'behavior:') || str_contains($css, '-moz-binding')) {
      return 'binds a script behavior';
    }
    if (($scheme = self::scheme($css, TRUE)) !== NULL) {
      return "holds a $scheme URL";
    }
    // A data: URL in CSS is allowed only as a url() image, which a browser
    // decodes as an image and never runs as a document.
    $dataUrls = preg_match_all('/(?:^|[^a-z0-9+.-])data:/', $css);
    $images = preg_match_all('/url\(["\']?data:image\/(?:' . self::CSS_DATA_IMAGES . ')[;,]/', $css);
    if ($dataUrls !== $images) {
      return 'holds a data: URL other than an image in url()';
    }
    return NULL;
  }

}
