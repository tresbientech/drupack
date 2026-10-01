<?php

/**
 * @file
 * Stand-ins for the seven WordPress functions the copied style engine calls.
 *
 * `wordpal:generate-components` copies wp-includes/style-engine verbatim
 * into this namespace, so its unqualified calls to these functions resolve
 * here instead of to a global WordPress that never loads. Each one ports the
 * one behavior the style engine depends on; none is a general-purpose port
 * of the WordPress function of the same name.
 */

declare(strict_types=1);

namespace Drupal\wordpal\WordPress\StyleEngine\Vendor;

use Drupal\wordpal\Support\PresetValue;

/**
 * Filters one CSS declaration, the way kses's safecss_filter_attr() does.
 *
 * WP_Style_Engine_CSS_Declarations::filter_declaration() calls this once per
 * "$property:$value" pair before it reaches an inline style attribute or a
 * page-level stylesheet. Security code fails closed: a pair this function
 * cannot verify as a property name plus a value PresetValue::css() accepts
 * is dropped rather than passed through.
 */
function safecss_filter_attr(string $css): string {
  if (!preg_match('/^([a-z0-9_-]+):\s*(.+)$/', trim($css), $matches)) {
    return '';
  }
  [, $property, $value] = $matches;
  try {
    return $property . ':' . PresetValue::css(trim($value));
  }
  catch (\UnexpectedValueException) {
    return '';
  }
}

/**
 * Reads a nested array value by path, as WordPress's `_wp_array_get()` does.
 */
function _wp_array_get(array $array, array $path, mixed $default = NULL): mixed {
  $cursor = $array;
  foreach ($path as $segment) {
    if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
      return $default;
    }
    $cursor = $cursor[$segment];
  }
  return $cursor;
}

/**
 * Kebab-cases a preset slug, as WordPress's `_wp_to_kebab_case()` does.
 *
 * PresetValue::slug() already ports this exact algorithm for WordPal's own
 * preset handling, so the style engine reuses it rather than a second copy.
 */
function _wp_to_kebab_case(string $value): string {
  return PresetValue::slug($value);
}

/**
 * Merges declaration options with their defaults, as `wp_parse_args()` does.
 */
function wp_parse_args(array $args, array $defaults): array {
  return array_merge($defaults, $args);
}

/**
 * Strips tags from a declaration value, as `wp_strip_all_tags()` does.
 */
function wp_strip_all_tags(string $string, bool $removeBreaks = FALSE): string {
  $string = (string) preg_replace('#<(script|style)[^>]*?>.*?</\\1>#si', '', $string);
  $string = strip_tags($string);
  if ($removeBreaks) {
    $string = (string) preg_replace('/[\r\n\t ]+/', ' ', $string);
  }
  return trim($string);
}

/**
 * Restricts a property name to safe characters, as `sanitize_key()` does.
 */
function sanitize_key(string $key): string {
  return (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($key));
}

/**
 * Encodes a value as JSON, as `wp_json_encode()` does for the style engine.
 */
function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|FALSE {
  return json_encode($data, $options, $depth);
}
