<?php

/**
 * @file
 * Copies the theme files that rendered HTML references.
 *
 * These functions call no WordPress function, so a unit test loads them alone.
 */

/**
 * Returns the extensions of the theme files the snapshot copies as assets.
 *
 * \Drupal\wordpal_convert\WordPress\Snapshot::assets() checks each SVG.
 */
function wordpal_asset_extensions() {
  return WORDPAL_ASSET_EXTENSIONS;
}

/**
 * Returns the snapshot path of a theme file a stylesheet references.
 *
 * The theme's assets directory keeps its path. Other theme files go under
 * assets/_theme/.
 */
function wordpal_theme_asset_path($relative) {
  return str_starts_with($relative, 'assets/') ? $relative : 'assets/_theme/' . $relative;
}

/**
 * Replaces the theme URL in $html, copying the asset files it names.
 *
 * A URL under $theme_url that names a file of the theme directory with an
 * asset extension is copied to its snapshot path under $out, listed in
 * $assets, and points at that path under $drupal_theme_url. Any other theme
 * URL only changes its prefix, and a missing file or another extension is
 * reported in the PHP error log.
 */
function wordpal_theme_file_references($html, $theme_url, $theme_directory, $out, $drupal_theme_url, &$assets) {
  $root = realpath($theme_directory);
  $html = preg_replace_callback('#' . preg_quote($theme_url, '#') . '/([^"\'\s)?\#,<>&]+)#', static function ($match) use ($root, $out, $drupal_theme_url, &$assets) {
    $relative = rawurldecode($match[1]);
    // The URL comes from theme content: a path that resolves outside the
    // theme directory or through a link is not copied.
    $source = realpath($root . '/' . $relative);
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if ($source === FALSE || !str_starts_with($source, $root . '/') || !is_file($source) || is_link($root . '/' . $relative) || !in_array($extension, wordpal_asset_extensions(), TRUE)) {
      error_log('wordpal: theme file left uncopied: ' . $match[0]);
      return $match[0];
    }
    // The path comes from the resolved file, never from the URL text.
    $path = wordpal_theme_asset_path(str_replace(DIRECTORY_SEPARATOR, '/', substr($source, strlen($root) + 1)));
    // The same shape Snapshot::checkPaths() requires of an assets.json path.
    if (str_contains($path, '..') || !preg_match(WORDPAL_SAFE_PATH, $path)) {
      error_log('wordpal: theme file path is not a snapshot path: ' . $match[0]);
      return $match[0];
    }
    if (!is_file($out . '/' . $path)) {
      if (!is_dir(dirname($out . '/' . $path))) {
        mkdir(dirname($out . '/' . $path), 0777, TRUE);
      }
      copy($source, $out . '/' . $path);
    }
    if (!in_array($path, $assets, TRUE)) {
      $assets[] = $path;
    }
    return $drupal_theme_url . '/' . $path;
  }, $html);
  return str_replace($theme_url, $drupal_theme_url, $html);
}
