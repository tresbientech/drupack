<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Support;

/**
 * Checks that a theme SVG holds no construct that can run script.
 *
 * The generated theme serves its SVG files from the site's origin, and a
 * WordPress theme chose their content. A file passes only when it parses as
 * XML and every element, attribute and style it holds is on the safe side of
 * each rule below.
 */
final class SvgCheck {

  /**
   * The SVG namespace.
   */
  private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

  /**
   * The XML namespace, which holds xml:base.
   */
  private const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';

  /**
   * The system identifier of each W3C SVG public identifier.
   */
  private const SVG_DOCTYPES = [
    '-//W3C//DTD SVG 1.0//EN' => 'http://www.w3.org/TR/2001/REC-SVG-20010904/DTD/svg10.dtd',
    '-//W3C//DTD SVG 1.1//EN' => 'http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd',
  ];

  /**
   * Returns why an SVG is unsafe, or NULL when it is safe.
   */
  public static function problem(string $svg): ?string {
    // Browsers decode an SVG with fewer encodings than libxml, so the check
    // reads only the one encoding both agree on.
    if (!mb_check_encoding($svg, 'UTF-8')) {
      return 'is not valid UTF-8';
    }
    // The libxml parser reads bytes 3C 00 3F 00 as UTF-16 without a BOM.
    if (str_contains($svg, "\0")) {
      return 'holds a NUL byte';
    }
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    // No LIBXML_NOENT or LIBXML_DTDLOAD: no entity is substituted and no
    // DTD is fetched.
    $parsed = $svg !== '' && $document->loadXML($svg, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$parsed || $document->documentElement === NULL) {
      return 'does not parse as XML';
    }
    if ($document->xmlEncoding !== NULL && strtoupper($document->xmlEncoding) !== 'UTF-8') {
      return "declares the encoding $document->xmlEncoding";
    }
    $doctype = $document->doctype;
    // DOM reports no internal subset for an empty one, so the bytes decide.
    if ($doctype !== NULL && ($doctype->internalSubset !== NULL || $doctype->entities->length > 0 || preg_match('/<!DOCTYPE[^>[]*\[/i', $svg))) {
      return 'declares a DOCTYPE internal subset';
    }
    if ($doctype !== NULL && ($doctype->name !== 'svg' || (self::SVG_DOCTYPES[$doctype->publicId] ?? NULL) !== $doctype->systemId)) {
      return 'declares a DOCTYPE other than the W3C SVG 1.0 or 1.1 one';
    }
    $xpath = new \DOMXPath($document);
    if ($xpath->query('//processing-instruction()')->length > 0) {
      return 'holds a processing instruction';
    }
    foreach ($xpath->query('//*') as $element) {
      $name = strtolower($element->localName);
      if ($element->namespaceURI !== self::SVG_NAMESPACE) {
        return "holds the element $element->nodeName outside the SVG namespace";
      }
      if ($name === 'script' || $name === 'foreignobject') {
        return "holds a $element->localName element";
      }
      if ($name === 'style' && ($problem = self::cssProblem($element->textContent)) !== NULL) {
        return "holds a style element that $problem";
      }
      foreach ($element->attributes as $attribute) {
        if (($problem = self::attributeProblem($element, $attribute)) !== NULL) {
          return $problem;
        }
      }
    }
    return NULL;
  }

  /**
   * Returns why one attribute is unsafe, or NULL when it is safe.
   */
  private static function attributeProblem(\DOMElement $element, \DOMAttr $attribute): ?string {
    $name = strtolower($attribute->localName);
    // Browsers ignore whitespace and control characters inside a scheme.
    $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $attribute->value));
    if ($attribute->namespaceURI === self::XML_NAMESPACE && $name === 'base') {
      return 'holds xml:base';
    }
    if (str_starts_with($name, 'on')) {
      return "holds the event handler attribute $attribute->nodeName";
    }
    if ($name === 'href') {
      return str_starts_with($value, '#') ? NULL : "holds the $attribute->nodeName \"$attribute->value\", which is not a fragment";
    }
    // An animation can set an href or an event handler to its own value.
    if ($name === 'attributename' && (str_ends_with($value, 'href') || str_starts_with($value, 'on'))) {
      return "animates the attribute $attribute->value on $element->localName";
    }
    if (str_contains($value, 'javascript:') || str_contains($value, 'data:')) {
      return "holds a javascript: or data: URL in $attribute->nodeName";
    }
    // Presentation attributes, animation values and style all load url().
    if (($problem = self::cssProblem($attribute->value)) !== NULL) {
      return "holds a $attribute->nodeName attribute that $problem";
    }
    return NULL;
  }

  /**
   * Returns why CSS is unsafe, or NULL when it only references fragments.
   */
  private static function cssProblem(string $css): ?string {
    if (str_contains($css, '\\')) {
      return 'holds a CSS escape';
    }
    $css = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $css));
    if (str_contains($css, '@import')) {
      return 'imports a stylesheet';
    }
    if (str_contains($css, 'javascript:') || str_contains($css, 'data:')) {
      return 'holds a javascript: or data: URL';
    }
    if (preg_match('/(image-set|image|src)\(/', $css, $match)) {
      return "loads a resource with {$match[1]}()";
    }
    preg_match_all('/url\(["\']?(.)/', $css, $matches);
    foreach ($matches[1] as $first) {
      if ($first !== '#') {
        return 'references a URL other than a fragment';
      }
    }
    return NULL;
  }

}
