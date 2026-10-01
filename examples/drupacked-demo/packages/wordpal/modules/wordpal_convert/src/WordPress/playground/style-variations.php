<?php

/**
 * @file
 * Tells a full style variation from a color or typography preset.
 *
 * The Site Editor lists as style variations only those that are not
 * isVariationWithProperties( variation, [ 'color' ] ) nor
 * isVariationWithProperties( variation, [ 'typography', 'spacing' ] )
 * (fullStyleVariations in @wordpress/edit-site). These functions port that
 * test. They call no WordPress function, so a unit test loads them alone.
 */

/**
 * Keeps the keys named in $properties at any depth, and the path to them.
 *
 * Ports filterObjectByProperties() from @wordpress/global-styles-engine.
 */
function wordpal_filter_by_properties(array $value, array $properties): array {
  $filtered = [];
  foreach ($value as $key => $item) {
    if (in_array($key, $properties, TRUE)) {
      $filtered[$key] = $item;
    }
    elseif (is_array($item)) {
      $inner = wordpal_filter_by_properties($item, $properties);
      if ($inner !== []) {
        $filtered[$key] = $inner;
      }
    }
  }
  return $filtered;
}

/**
 * Returns TRUE when a variation's settings and styles hold only $properties.
 *
 * Ports isVariationWithProperties(), which compares settings and styles.
 */
function wordpal_is_variation_with_properties(array $variation, array $properties): bool {
  $filtered = wordpal_filter_by_properties($variation, $properties);
  return ($filtered['settings'] ?? NULL) === ($variation['settings'] ?? NULL)
    && ($filtered['styles'] ?? NULL) === ($variation['styles'] ?? NULL);
}

/**
 * Returns TRUE for a variation the Site Editor lists as a style variation.
 */
function wordpal_is_full_variation(array $variation): bool {
  return !wordpal_is_variation_with_properties($variation, ['color'])
    && !wordpal_is_variation_with_properties($variation, ['typography', 'spacing']);
}
