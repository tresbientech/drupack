<?php

/**
 * @file
 * Exports a theme from WordPress. Runs inside WordPress Playground.
 *
 * The host mounts its snapshot directory at /wordpal. Input sits in
 * /wordpal/in, output goes to /wordpal/out.
 */

$request = json_decode(file_get_contents('/wordpal/in/request.json'), TRUE);
$out = '/wordpal/out';

// Defined as constants, not read from $request, so they reach every
// function in this file without threading $request through each one.
define('WORDPAL_HANDLE_PATTERN', $request['handle_pattern']);
define('WORDPAL_ASSET_EXTENSIONS', $request['asset_extensions']);
define('WORDPAL_SAFE_PATH', $request['safe_path']);
define('WORDPAL_PLUGIN_ASSETS', $request['plugin_assets']);
define('WORDPAL_PLUGIN_STYLE_PREFIX', $request['plugin_style_prefix']);
define('WORDPAL_LEGAL_FILES', $request['legal_files']);
define('WORDPAL_LEGAL_PREFIX', $request['legal_prefix']);
define('WORDPAL_PLUGIN_LEGAL_FILES', $request['plugin_legal_files']);

require_once __DIR__ . '/theme-files.php';

foreach ([
  '', '/templates', '/parts', '/patterns', '/styles', '/scripts', '/assets',
] as $directory) {
  if (!is_dir($out . $directory)) {
    mkdir($out . $directory, 0777, TRUE);
  }
}

switch_theme($request['theme']);
$theme = wp_get_theme($request['theme']);
$theme_directory = $theme->get_stylesheet_directory();
$theme_url = untrailingslashit($theme->get_stylesheet_directory_uri());
$drupal_theme_url = '/themes/custom/' . str_replace('-', '_', $request['theme']);
// Theme asset files the rendered HTML references, copied as they are found.
$css_assets = [];
// Replaces the theme URL in rendered HTML, copying the files it names.
$rewrite_theme_urls = static function ($html) use ($theme_url, $theme_directory, $out, $drupal_theme_url, &$css_assets) {
  return wordpal_theme_file_references($html, $theme_url, $theme_directory, $out, $drupal_theme_url, $css_assets);
};

/**
 * Creates or updates one fixture post.
 */
function wordpal_fixture_post($fixture, $author_id, $category_id = NULL, $tag_ids = [], $thumbnail_id = NULL) {
  $existing = get_page_by_path($fixture['slug'], OBJECT, 'post');
  $values = [
    'ID' => $existing?->ID ?? 0,
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_author' => $author_id,
    'post_title' => $fixture['title'],
    'post_name' => $fixture['slug'],
    'post_date_gmt' => gmdate('Y-m-d H:i:s', strtotime($fixture['date'])),
    'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($fixture['date']))),
    'post_content' => $fixture['content'] ?? '',
    'post_excerpt' => $fixture['excerpt'] ?? '',
  ];
  $post_id = wp_insert_post($values, TRUE);
  if (is_wp_error($post_id)) {
    throw new RuntimeException($post_id->get_error_message());
  }
  if ($category_id !== NULL) {
    wp_set_post_categories($post_id, [$category_id]);
  }
  if ($tag_ids !== []) {
    wp_set_post_terms($post_id, $tag_ids, 'post_tag');
  }
  if ($thumbnail_id !== NULL) {
    set_post_thumbnail($post_id, $thumbnail_id);
  }
  return $post_id;
}

/**
 * Creates or updates the fixture navigation entity.
 *
 * An item with a "page" target links the fixture page as a page link.
 */
function wordpal_fixture_navigation($fixture, $page_id) {
  $blocks = [];
  foreach ($fixture['items'] as $item) {
    $blocks[] = [
      'blockName' => 'core/navigation-link',
      'attrs' => isset($item['target']) ? [
        'label' => $item['title'],
        'url' => get_permalink($page_id),
        'id' => $page_id,
        'kind' => 'post-type',
        'type' => 'page',
      ] : [
        'label' => $item['title'],
        'url' => $item['path'],
        'kind' => 'custom',
        'type' => 'custom',
      ],
      'innerBlocks' => [],
      'innerHTML' => '',
      'innerContent' => [],
    ];
  }
  $slug = 'wordpal-fixture-navigation';
  $existing = get_page_by_path($slug, OBJECT, 'wp_navigation');
  $id = wp_insert_post([
    'ID' => $existing?->ID ?? 0,
    'post_type' => 'wp_navigation',
    'post_status' => 'publish',
    'post_title' => $fixture['name'],
    'post_name' => $slug,
    'post_content' => serialize_blocks($blocks),
  ], TRUE);
  if (is_wp_error($id)) {
    throw new RuntimeException($id->get_error_message());
  }
  return $id;
}

/**
 * Seeds the shared fixture and returns its WordPress entity IDs.
 */
function wordpal_seed_fixture($fixture) {
  $user = get_user_by('login', $fixture['author']['username']);
  $author_id = wp_insert_user([
    'ID' => $user?->ID ?? 0,
    'user_login' => $fixture['author']['username'],
    'user_email' => $fixture['author']['username'] . '@example.com',
    'display_name' => $fixture['author']['display_name'],
    'description' => $fixture['author']['biography'],
    'user_pass' => wp_generate_password(24),
  ]);
  if (is_wp_error($author_id)) {
    throw new RuntimeException($author_id->get_error_message());
  }

  $category = term_exists($fixture['post']['category'], 'category') ?: wp_insert_term($fixture['post']['category'], 'category');
  $category_id = is_array($category) ? $category['term_id'] : $category;
  $tag_ids = [];
  foreach ($fixture['post']['tags'] as $name) {
    $tag = term_exists($name, 'post_tag') ?: wp_insert_term($name, 'post_tag');
    $tag_ids[] = is_array($tag) ? $tag['term_id'] : $tag;
  }

  $image = $fixture['post']['featured_image'];
  $upload = wp_upload_bits($image['filename'], NULL, base64_decode($image['base64'], TRUE));
  if ($upload['error']) {
    throw new RuntimeException($upload['error']);
  }
  $attachment_id = wp_insert_attachment([
    'post_mime_type' => $image['mime_type'],
    'post_title' => pathinfo($image['filename'], PATHINFO_FILENAME),
    'post_status' => 'inherit',
  ], $upload['file']);
  require_once ABSPATH . 'wp-admin/includes/image.php';
  wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
  update_post_meta($attachment_id, '_wp_attachment_image_alt', $image['alt']);

  wordpal_fixture_post($fixture['neighbors']['previous'], $author_id, $category_id);
  wordpal_fixture_post($fixture['neighbors']['next'], $author_id, $category_id);
  $post_id = wordpal_fixture_post($fixture['post'], $author_id, $category_id, $tag_ids, $attachment_id);
  foreach ($fixture['older_posts'] as $older_post) {
    wordpal_fixture_post($older_post, $author_id, $category_id);
  }
  $comments = [];
  foreach ($fixture['comments'] as $comment) {
    $comments[$comment['id']] = wp_insert_comment([
      'comment_post_ID' => $post_id,
      'comment_author' => $comment['author'],
      'comment_author_email' => $comment['email'],
      'comment_content' => $comment['body'],
      'comment_date_gmt' => gmdate('Y-m-d H:i:s', strtotime($comment['date'])),
      'comment_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($comment['date']))),
      'comment_approved' => 1,
      'comment_parent' => $comment['parent'] === NULL ? 0 : $comments[$comment['parent']],
    ]);
  }
  $page_id = wp_insert_post([
    'ID' => get_page_by_path($fixture['page']['slug'])?->ID ?? 0,
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_author' => $author_id,
    'post_title' => $fixture['page']['title'],
    'post_name' => $fixture['page']['slug'],
    'post_content' => $fixture['page']['content'],
    'post_excerpt' => $fixture['page']['excerpt'],
  ], TRUE);
  if (is_wp_error($page_id)) {
    throw new RuntimeException($page_id->get_error_message());
  }
  return [
    'post' => $post_id,
    'page' => $page_id,
    'author' => $author_id,
    'navigation' => wordpal_fixture_navigation($fixture['navigation'], $page_id),
  ];
}

/**
 * Renders block markup the way do_blocks() does, recording each block.
 *
 * Each top-level block renders once, with its inner blocks inside it, and a
 * render_block filter records every block's own output as it goes. The
 * recorded HTML is the HTML the returned render holds, so a block rendered
 * with wp_unique_id() keeps the ID it has in the whole. A block a parent
 * renders outside its own inner-block list, such as a Post Template's
 * per-post copies, renders once more on its own afterwards.
 *
 * A path joins the block's position in each parse_blocks() list with dots,
 * top level first, such as "2.0.1". Top-level positions count the
 * whitespace between blocks, as parse_blocks() returns it. Each parsed
 * block carries its path under one key. A render_block_data filter
 * can make WordPress rebuild a parent's inner block instances, and the
 * rebuilt ones keep only their parsed block. WordPress's per-post and
 * per-comment copies are core/null blocks, and their inner blocks get no
 * path.
 *
 * The filter also takes the styles queued since the last recorded block, so
 * a block owns the styles its render enqueued. A block rendered as nothing
 * owns none, as WordPress dequeues them. The style queue is emptied while
 * recording and holds every recorded style afterwards.
 * wordpal_record_block_styles() adds each block's plugin styles to
 * $rendered_styles.
 *
 * @return array
 *   The rendered markup and the index of rendered HTML keyed by path, theme
 *   URLs replaced by the Drupal theme URL in both.
 */
function wordpal_render_recorded($content, $rewrite_theme_urls, &$rendered_styles) {
  // The parsed block key holding a block's path.
  $key = 'wordpalPath';
  $post = get_post();
  $context = $post instanceof WP_Post ? ['postId' => $post->ID, 'postType' => $post->post_type] : [];
  $blocks = [];
  $index = [];
  $own_styles = [];
  $type_styles = [];
  $positions = [];
  $queued_before = wp_styles()->queue;
  wp_styles()->dequeue($queued_before);
  $recorded_styles = [];
  $count = static function ($pre_render, $parsed, $parent = NULL) use (&$positions) {
    if ($parent instanceof WP_Block) {
      $positions[spl_object_id($parent)] = ($positions[spl_object_id($parent)] ?? -1) + 1;
    }
    return $pre_render;
  };
  $mark = static function ($parsed, $source = NULL, $parent = NULL) use (&$positions, $key) {
    if ($parent instanceof WP_Block && $parent->name !== 'core/null' && isset($parent->parsed_block[$key])) {
      $parsed[$key] = $parent->parsed_block[$key] . '.' . $positions[spl_object_id($parent)];
    }
    return $parsed;
  };
  $record = static function ($html, $parsed, $instance = NULL) use (&$index, &$own_styles, &$type_styles, &$recorded_styles, &$positions, $key) {
    if ($instance instanceof WP_Block) {
      unset($positions[spl_object_id($instance)]);
    }
    $path = $parsed[$key] ?? NULL;
    // A block rendered outside the tree leaves its styles queued for its
    // parent.
    if ($path === NULL || $parsed['blockName'] === 'core/null') {
      return $html;
    }
    if (!isset($index[$path])) {
      // WordPress processes Interactivity directives after this filter, on
      // the root interactive block's output.
      $index[$path] = str_contains($html, 'data-wp-') ? wp_interactivity_process_directives($html) : $html;
      $own_styles[$path] = trim($html) === '' ? [] : wp_styles()->queue;
      // A plugin may enqueue the stylesheets of its block types for the whole
      // page, after the blocks render. The block type still names them.
      $type_styles[$path] = [];
      // The block type is NULL for an unregistered block, and a plugin that
      // registers its own type can leave a handle property NULL.
      if (trim($html) !== '' && $instance instanceof WP_Block && $instance->block_type !== NULL) {
        $type = $instance->block_type;
        $type_styles[$path] = [...$type->style_handles ?? [], ...$type->view_style_handles ?? []];
      }
      $namespaces = &wordpal_style_namespaces();
      foreach ($own_styles[$path] as $handle) {
        $namespaces[$handle][strtok((string) $parsed['blockName'], '/')] = TRUE;
      }
    }
    $recorded_styles = [...$recorded_styles, ...$own_styles[$path]];
    wp_styles()->dequeue(wp_styles()->queue);
    return $html;
  };
  add_filter('pre_render_block', $count, PHP_INT_MAX, 3);
  add_filter('render_block_data', $mark, PHP_INT_MAX, 3);
  add_filter('render_block', $record, PHP_INT_MAX, 3);
  $output = '';
  foreach (parse_blocks($content) as $position => $parsed) {
    $pre_render = apply_filters('pre_render_block', NULL, $parsed, NULL);
    if ($pre_render !== NULL) {
      $output .= $pre_render;
      $index[(string) $position] = $pre_render;
      continue;
    }
    $parsed = apply_filters('render_block_data', $parsed, $parsed, NULL);
    $parsed[$key] = (string) $position;
    $block = new WP_Block($parsed, apply_filters('render_block_context', $context, $parsed, NULL));
    if ($parsed['blockName'] !== NULL || trim($parsed['innerHTML']) !== '') {
      wordpal_register_paths($block, (string) $position, $blocks);
    }
    $output .= $block->render();
  }
  remove_filter('pre_render_block', $count, PHP_INT_MAX);
  remove_filter('render_block_data', $mark, PHP_INT_MAX);
  remove_filter('render_block', $record, PHP_INT_MAX);
  wp_styles()->enqueue(array_unique([...$queued_before, ...$recorded_styles, ...wp_styles()->queue]));
  foreach ($blocks as $path => $block) {
    if (!isset($index[$path])) {
      $index[$path] = $block->render();
    }
  }
  ksort($index, SORT_NATURAL);
  $replace = static function ($html) use ($rewrite_theme_urls) {
    return wordpal_inline_uploads($rewrite_theme_urls($html));
  };
  $index = array_map($replace, $index);
  wordpal_record_block_styles($index, $own_styles, $type_styles, $rendered_styles);
  return [$replace($output), $index];
}

/**
 * Replaces a media library URL with the bytes it names.
 *
 * A Reference render is a standalone file the WordPress instance that wrote
 * it no longer serves: an <img> naming that instance's own uploads URL,
 * such as a post's featured image, would otherwise point nowhere once
 * compared as a static page.
 */
function wordpal_inline_uploads($html) {
  static $uploads_url;
  static $uploads_basedir;
  if (!isset($uploads_url)) {
    $uploads = wp_upload_dir();
    $uploads_url = $uploads['baseurl'];
    $uploads_basedir = realpath($uploads['basedir']);
  }
  if ($uploads_basedir === FALSE) {
    return $html;
  }
  return (string) preg_replace_callback(
    '#' . preg_quote($uploads_url, '#') . '/([^"\'\s)]+)#',
    static function ($matches) use ($uploads_basedir) {
      // The URL comes from theme content: a path that resolves outside the
      // uploads directory stays a URL.
      $path = realpath($uploads_basedir . '/' . rawurldecode($matches[1]));
      if ($path === FALSE || !str_starts_with($path, $uploads_basedir . '/') || !is_file($path)) {
        return $matches[0];
      }
      $mime = mime_content_type($path);
      return $mime === FALSE ? $matches[0] : 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    },
    $html,
  );
}

/**
 * Lists one block and its inner blocks by their paths.
 */
function wordpal_register_paths(WP_Block $block, $path, &$blocks) {
  $blocks[$path] = $block;
  foreach ($block->inner_blocks as $position => $inner) {
    wordpal_register_paths($inner, "$path.$position", $blocks);
  }
}

/**
 * Adds the style handles of each recorded block to $rendered_styles.
 *
 * A block needs the styles it and its inner blocks enqueued, and those its
 * block type names. Plugins register some of them only on wp_enqueue_scripts,
 * so wordpal_plugin_style_handles() filters the plugin ones later. The
 * entries are keyed by the SHA-256 hash of the block's HTML, the key the
 * generated theme stores a Frozen block's HTML under.
 */
function wordpal_record_block_styles($index, $own_styles, $type_styles, &$rendered_styles) {
  foreach ($index as $path => $html) {
    $handles = [];
    foreach ($own_styles as $own_path => $own) {
      if ($own_path === $path || str_starts_with($own_path, "$path.")) {
        $handles = [...$handles, ...$own, ...$type_styles[$own_path]];
      }
    }
    if ($handles === []) {
      continue;
    }
    $key = hash('sha256', $html);
    $rendered_styles[$key] = array_values(array_unique([...($rendered_styles[$key] ?? []), ...$handles]));
  }
}

/**
 * Returns the handles among $handles whose CSS a plugin ships.
 *
 * That is a stylesheet file in a plugin, or inline CSS added to a handle a
 * plugin owns. A registered handle with no stylesheet of its own stands for
 * its dependencies.
 */
function wordpal_plugin_style_handles($handles) {
  $plugin = [];
  foreach ($handles as $handle) {
    $style = wp_styles()->registered[$handle] ?? NULL;
    if ($style === NULL) {
      continue;
    }
    if ($style->src === FALSE) {
      $plugin = [...$plugin, ...wordpal_plugin_style_handles($style->deps)];
      if (wordpal_plugin_inline_css($handle) !== '' && wordpal_style_plugin($handle) !== NULL && preg_match(WORDPAL_HANDLE_PATTERN, $handle)) {
        $plugin[] = $handle;
      }
    }
    elseif (wordpal_plugin_style_file($handle) !== NULL) {
      $plugin[] = $handle;
    }
  }
  return $plugin;
}

/**
 * Returns the CSS of a handle in print order: before, file, after.
 *
 * The file counts only when a plugin ships it.
 */
function wordpal_plugin_inline_css($handle) {
  $file = wordpal_plugin_style_file($handle);
  return trim(implode("\n", [
    ...wp_styles()->get_data($handle, 'before') ?: [],
    $file === NULL ? '' : (string) file_get_contents($file),
    ...wp_styles()->get_data($handle, 'after') ?: [],
  ]));
}

/**
 * Returns the directory name of the plugin that owns a path, or NULL.
 */
function wordpal_plugin_of_path($path) {
  $root = realpath(WP_PLUGIN_DIR) . DIRECTORY_SEPARATOR;
  return str_starts_with($path, $root) ? explode(DIRECTORY_SEPARATOR, substr($path, strlen($root)))[0] : NULL;
}

/**
 * Returns the block namespaces that enqueued each handle, by reference.
 *
 * @return array<string, array<string, true>>
 *   Namespaces keyed by handle.
 */
function &wordpal_style_namespaces() {
  static $namespaces = [];
  return $namespaces;
}

/**
 * Returns whether a registered stylesheet is a file of the theme.
 */
function wordpal_theme_style($handle) {
  $style = wp_styles()->registered[$handle] ?? NULL;
  if ($style === NULL || !is_string($style->src)) {
    return FALSE;
  }
  foreach ([get_stylesheet_directory_uri(), get_template_directory_uri()] as $theme_url) {
    if (str_starts_with($style->src, untrailingslashit($theme_url) . '/')) {
      return TRUE;
    }
  }
  return FALSE;
}

/**
 * Returns whether the theme registers the blocks of a namespace.
 *
 * That is so when a block type of the namespace names a stylesheet of the
 * theme and none names a plugin file.
 */
function wordpal_theme_namespace($namespace) {
  $theme = FALSE;
  foreach (WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type) {
    foreach (str_starts_with($name, "$namespace/") ? [...$type->style_handles, ...$type->view_style_handles] : [] as $block_handle) {
      if (isset(wp_styles()->registered[$block_handle]) && wordpal_plugin_style_file($block_handle) !== NULL) {
        return FALSE;
      }
      $theme = $theme || wordpal_theme_style($block_handle);
    }
  }
  return $theme;
}

/**
 * Returns the directory name of the plugin that owns a registered handle.
 *
 * A handle with a plugin file belongs to that plugin. A handle of the theme
 * gives NULL: it is theme CSS, whatever it depends on. A handle with no
 * source belongs to the plugin of its first dependency, else to the plugin
 * that registers a style for the namespace of the blocks that enqueued it.
 * A namespace whose blocks the theme registers gives NULL too. Only core
 * blocks enqueued it: a WordPress handle, which has no plugin, gives NULL.
 * A handle of another block that nothing resolves stops the snapshot.
 */
function wordpal_style_plugin($handle) {
  $style = wp_styles()->registered[$handle] ?? NULL;
  if ($style === NULL) {
    return NULL;
  }
  $file = wordpal_plugin_style_file($handle);
  if ($file !== NULL) {
    return wordpal_plugin_of_path($file);
  }
  $namespaces = array_diff(array_keys(wordpal_style_namespaces()[$handle] ?? []), ['core']);
  if (wordpal_theme_style($handle) || array_filter($namespaces, 'wordpal_theme_namespace') !== []) {
    return NULL;
  }
  foreach ($style->deps as $dependency) {
    $plugin = wordpal_style_plugin($dependency);
    if ($plugin !== NULL) {
      return $plugin;
    }
  }
  foreach ($namespaces as $namespace) {
    foreach (WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type) {
      foreach (str_starts_with($name, "$namespace/") ? [...$type->style_handles, ...$type->view_style_handles] : [] as $block_handle) {
        $block_file = wp_styles()->registered[$block_handle] ?? NULL;
        $plugin = $block_file === NULL ? NULL : wordpal_plugin_of_path((string) wordpal_plugin_style_file($block_handle));
        if ($plugin !== NULL) {
          return $plugin;
        }
      }
    }
  }
  if ($namespaces !== []) {
    throw new RuntimeException("No plugin owns the inline CSS of the style handle $handle, enqueued by " . implode(', ', $namespaces) . ' blocks.');
  }
  return NULL;
}

/**
 * Returns the plugin file of a registered stylesheet, or NULL for another.
 */
function wordpal_plugin_style_file($handle) {
  $style = wp_styles()->registered[$handle];
  $plugin_root = realpath(WP_PLUGIN_DIR);
  $path = $style->extra['path'] ?? NULL;
  $plugins_url = untrailingslashit(plugins_url());
  if (!is_string($path) && is_string($style->src) && str_starts_with($style->src, $plugins_url . '/')) {
    $path = WP_PLUGIN_DIR . '/' . strtok(substr($style->src, strlen($plugins_url) + 1), '?#');
  }
  $resolved = is_string($path) ? realpath($path) : FALSE;
  if ($resolved === FALSE || !str_starts_with($resolved, $plugin_root . DIRECTORY_SEPARATOR) || !is_file($resolved) || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'css') {
    return NULL;
  }
  // The handle names the generated theme library and file.
  return preg_match(WORDPAL_HANDLE_PATTERN, $handle) ? $resolved : NULL;
}

$render_index = [];
$rendered_styles = [];
// The block-rendered markup of each pattern, keyed as patterns.json keys it.
// references.php writes these under references/patterns/ when it runs.
// phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
$pattern_references = [];

$fixture = json_decode(file_get_contents('/wordpal/in/fixture.json'), TRUE, 512, JSON_THROW_ON_ERROR);
update_option('show_comments_cookies_opt_in', FALSE);
// A Reference render compares its Site Title against the converted site's
// own name; without this, WordPress prints Playground's own default title
// instead of the name the request names.
if (isset($request['site_name'])) {
  update_option('blogname', $request['site_name']);
}
$fixture_ids = wordpal_seed_fixture($fixture);
// WordPress's sample post, its sample page and the pages plugin activation
// created leave before any Reference render, as Drupal holds only the fixture.
wp_delete_post(1, TRUE);
foreach (get_posts([
  'post_type' => 'page',
  'post_status' => 'any',
  'numberposts' => -1,
  'exclude' => [$fixture_ids['page']],
  'fields' => 'ids',
]) as $page_id) {
  wp_delete_post($page_id, TRUE);
}
// A template part reference with no tagName prints its area's tag.
$area_tags = array_column(get_allowed_block_template_part_areas(), 'area_tag', 'area');
$part_tags = [];
foreach (glob("$theme_directory/parts/*.html") ?: [] as $file) {
  $part_slug = basename($file, '.html');
  $part = get_block_template(get_stylesheet() . '//' . $part_slug, 'wp_template_part');
  $part_tags[$part_slug] = $area_tags[$part->area] ?? 'div';
}
ksort($part_tags);

$active_plugins = array_map(static fn ($plugin) => explode('/', $plugin)[0], get_option('active_plugins', []));
sort($active_plugins);
// The attribution headers of each installed plugin directory: a plugin
// stylesheet can sit in any of them. A single-file plugin has no directory.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin_headers = [];
foreach (get_plugins() as $plugin_file => $plugin) {
  if (!str_contains($plugin_file, '/')) {
    continue;
  }
  $license_fields = ['license' => 'License', 'license_uri' => 'License URI'];
  $plugin_license = get_file_data(WP_PLUGIN_DIR . '/' . $plugin_file, $license_fields);
  // A plugin such as WooCommerce states its license in readme.txt alone.
  $readme = WP_PLUGIN_DIR . '/' . dirname($plugin_file) . '/readme.txt';
  if ($plugin_license === ['license' => '', 'license_uri' => ''] && is_file($readme)) {
    $plugin_license = get_file_data($readme, $license_fields);
  }
  $plugin_headers[explode('/', $plugin_file)[0]] ??= [
    'name' => $plugin['Name'],
    'version' => $plugin['Version'],
    'plugin_uri' => $plugin['PluginURI'],
    'author' => $plugin['Author'],
    'author_uri' => $plugin['AuthorURI'],
    'license' => $plugin_license['license'],
    'license_uri' => $plugin_license['license_uri'],
  ];
}
ksort($plugin_headers);
// WP_Theme::get() does not parse the two license headers.
$license_headers = get_file_data($theme_directory . '/style.css', [
  'license' => 'License',
  'license_uri' => 'License URI',
]);
$screenshot = $theme->get_screenshot('relative');
$screenshot_path = $screenshot === FALSE || is_link($theme_directory . '/' . $screenshot) ? NULL : 'screenshot.' . strtolower(pathinfo($screenshot, PATHINFO_EXTENSION));
if ($screenshot_path !== NULL) {
  copy($theme_directory . '/' . $screenshot, $out . '/' . $screenshot_path);
}
file_put_contents($out . '/metadata.json', json_encode([
  'slug' => $request['theme'],
  'name' => $theme->get('Name'),
  'version' => $theme->get('Version'),
  'wordpress_version' => get_bloginfo('version'),
  'plugins' => $active_plugins,
  'plugin_headers' => (object) $plugin_headers,
  'sources' => $request['sources'],
  'posts_per_page' => (int) get_option('posts_per_page'),
  'part_tags' => $part_tags,
  'license' => $license_headers['license'],
  'license_uri' => $license_headers['license_uri'],
  'theme_uri' => $theme->get('ThemeURI'),
  'author' => $theme->get('Author'),
  'author_uri' => $theme->get('AuthorURI'),
  'screenshot' => $screenshot_path,
  'snapshot_date' => gmdate('Y-m-d'),
], JSON_PRETTY_PRINT));

$block_types = WP_Block_Type_Registry::get_instance()->get_all_registered();
$block_names = array_keys($block_types);
sort($block_names);
$blocks = [];
foreach ($block_names as $block_name) {
  $blocks[$block_name] = $block_types[$block_name]->supports ?? [];
}
file_put_contents($out . '/blocks.json', json_encode($blocks, JSON_PRETTY_PRINT));

// Each theme template and part holds the content WordPress resolves for it:
// a plugin can serve its own content under the theme's slug, as Gutenverse
// serves a theme's gutenverse-files/templates.
foreach (['templates' => 'wp_template', 'parts' => 'wp_template_part'] as $directory => $type) {
  foreach (glob("$theme_directory/$directory/*.html") ?: [] as $file) {
    $template = get_block_template(get_stylesheet() . '//' . basename($file, '.html'), $type);
    file_put_contents($out . '/' . $directory . '/' . basename($file), $template->content);
  }
}

// Patterns come from the registry, so their PHP has run and remote
// theme.json patterns WordPress fetched are included.
$requested_patterns = wp_get_theme_directory_pattern_slugs();
foreach ($requested_patterns as $requested_pattern) {
  if (!is_string($requested_pattern) || $requested_pattern === '') {
    throw new UnexpectedValueException('Every requested theme.json pattern must be a non-empty slug.');
  }
}
if ($requested_patterns !== []) {
  $current_user_id = get_current_user_id();
  wp_set_current_user(1);
  try {
    _register_remote_theme_patterns();
  }
  finally {
    wp_set_current_user($current_user_id);
  }
}
$patterns = [];
$resolved_patterns = [];
$registered_patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
foreach ($registered_patterns as $pattern) {
  $source_slug = $pattern['name'];
  $local = str_starts_with($source_slug, $request['theme'] . '/');
  $remote = ($pattern['source'] ?? NULL) === 'pattern-directory/theme';
  if (!$local && !$remote) {
    continue;
  }
  if ($remote && in_array($source_slug, $requested_patterns, TRUE)) {
    $resolved_patterns[$source_slug] = TRUE;
  }
  $name = str_replace('/', '--', $source_slug);
  $patterns[$name] = [
    'slug' => $source_slug,
    'title' => $pattern['title'],
    'categories' => $pattern['categories'] ?? [],
    'inserter' => $pattern['inserter'] ?? TRUE,
  ];
  file_put_contents($out . "/patterns/$name.html", $rewrite_theme_urls($pattern['content']));
  // phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
  [$pattern_references[$name], $render_index["patterns/$name.html"]] = wordpal_render_recorded($pattern['content'], $rewrite_theme_urls, $rendered_styles);
}
// Templates and parts render under the fixture post, as the single
// Reference render does.
query_posts(['p' => $fixture_ids['post'], 'post_type' => 'post']);
the_post();
foreach (['templates', 'parts'] as $directory) {
  foreach (glob("$out/$directory/*.html") ?: [] as $file) {
    $render_index["$directory/" . basename($file)] = wordpal_render_recorded((string) file_get_contents($file), $rewrite_theme_urls, $rendered_styles)[1];
  }
}
wp_reset_query();
// Conversion's own entries. references.php, if it runs, adds the block
// library fixtures' entries and rewrites this file with both.
file_put_contents($out . '/render-index.json', json_encode($render_index, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

$unresolved_patterns = array_values(array_diff($requested_patterns, array_keys($resolved_patterns)));
if ($unresolved_patterns !== []) {
  throw new UnexpectedValueException('WordPress did not register requested patterns: ' . implode(', ', $unresolved_patterns));
}
file_put_contents($out . '/patterns.json', json_encode($patterns, JSON_PRETTY_PRINT));

// What the theme states in theme.json, resolved the way WordPress reads it.
$theme_json = WP_Theme_JSON_Resolver::get_merged_data();
$settings = $theme_json->get_settings();
$styles = $theme_json->get_raw_data()['styles'] ?? [];

$variations = [];
foreach ($styles['blocks'] ?? [] as $block_name => $block_styles) {
  foreach (array_keys($block_styles['variations'] ?? []) as $variation) {
    $variations["$block_name/$variation"] = wordpal_variation_class($block_name, $variation);
  }
}

// WP_Duotone prints each duotone preset a page uses as this SVG filter.
$duotone_svg = new ReflectionMethod(WP_Duotone::class, 'get_filter_svg');
$duotone_filters = [];
foreach ($settings['color']['duotone'] ?? [] as $origin_presets) {
  foreach ($origin_presets as $preset) {
    $duotone_filters[$preset['slug']] = $duotone_svg->invoke(NULL, 'wp-duotone-' . $preset['slug'], $preset['colors']);
  }
}

file_put_contents($out . '/settings.json', json_encode([
  'layout' => ['wideSize' => $settings['layout']['wideSize'] ?? NULL],
  'position' => [
    'sticky' => ($settings['position']['sticky'] ?? FALSE) === TRUE,
    'fixed' => ($settings['position']['fixed'] ?? FALSE) === TRUE,
  ],
  'duotone_filters' => (object) $duotone_filters,
  'use_root_padding_aware_alignments' => $settings['useRootPaddingAwareAlignments'] ?? FALSE,
  'typography' => [
    'fluid' => $settings['typography']['fluid'] ?? FALSE,
  ],
  'styled_variations' => $variations,
], JSON_PRETTY_PRINT));

// The presets the theme defines, one list per group of a preset reference.
file_put_contents($out . '/presets.json', json_encode([
  'color' => $settings['color']['palette']['theme'] ?? [],
  'gradient' => $settings['color']['gradients']['theme'] ?? [],
  'font-size' => $settings['typography']['fontSizes']['theme'] ?? [],
  'font-family' => $settings['typography']['fontFamilies']['theme'] ?? [],
  'spacing' => $settings['spacing']['spacingSizes']['theme'] ?? [],
], JSON_PRETTY_PRINT));

/**
 * Rewrites theme URLs for CSS stored beside the generated asset directory.
 */
function wordpal_css_urls($css, $theme_url) {
  return str_replace($theme_url . '/assets/', '../assets/', $css);
}

/**
 * Points the relative url() references of a stylesheet at copies.
 *
 * A reference resolves against the stylesheet's directory, and its target
 * must be an asset file inside $root. $target maps the target's path in
 * $root to its snapshot path under assets/, where it is copied and added to
 * $assets. Any other reference stays as it is.
 */
function wordpal_css_asset_urls($css, $source_path, $root, $target, $out, &$assets) {
  $root = realpath($root);
  return preg_replace_callback('/url\((["\']?)([^"\')]+)\1\)/', static function ($match) use ($source_path, $root, $target, $out, &$assets) {
    $url = trim($match[2]);
    if ($url === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|/|\#)#i', $url)) {
      return $match[0];
    }
    $parts = preg_split('/([?#])/', $url, 2, PREG_SPLIT_DELIM_CAPTURE);
    $resolved = realpath(dirname($source_path) . '/' . $parts[0]);
    $extension = strtolower(pathinfo((string) $resolved, PATHINFO_EXTENSION));
    if ($resolved === FALSE || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR) || !is_file($resolved) || !in_array($extension, wordpal_asset_extensions(), TRUE)) {
      return $match[0];
    }
    $relative = $target(str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($root) + 1)));
    if (!is_file($out . '/' . $relative)) {
      if (!is_dir(dirname($out . '/' . $relative))) {
        mkdir(dirname($out . '/' . $relative), 0777, TRUE);
      }
      copy($resolved, $out . '/' . $relative);
    }
    $assets[] = $relative;
    $suffix = count($parts) > 1 ? implode('', array_slice($parts, 1)) : '';
    return 'url("../' . $relative . $suffix . '")';
  }, $css);
}

/**
 * Returns the CSS printed by WordPress's font face generator.
 */
function wordpal_font_faces() {
  ob_start();
  wp_print_font_faces();
  wp_print_font_faces_from_style_variations();
  $output = (string) ob_get_clean();
  return trim((string) preg_replace('/<\/?style[^>]*>/', '', $output));
}

/**
 * Collects inline CSS registered for block style variants.
 */
function wordpal_block_style_css() {
  $css = [];
  foreach (WP_Block_Styles_Registry::get_instance()->get_all_registered() as $styles) {
    foreach ($styles as $style) {
      if (isset($style['inline_style'])) {
        $css[] = $style['inline_style'];
      }
    }
  }
  return implode("\n", $css);
}

/**
 * Copies the theme's Legal files and returns their snapshot paths.
 *
 * WORDPAL_LEGAL_FILES matches theme-relative paths at the theme root and
 * under assets/, so only those two places are listed. Each copy goes under
 * WORDPAL_LEGAL_PREFIX.
 */
function wordpal_copy_legal_files($theme_directory, $out) {
  $relatives = [];
  foreach (new FilesystemIterator($theme_directory) as $file) {
    $relatives[] = $file->getFilename();
  }
  if (is_dir($theme_directory . '/assets')) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme_directory . '/assets', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      $relatives[] = 'assets/' . substr($file->getPathname(), strlen($theme_directory . '/assets') + 1);
    }
  }
  $paths = [];
  foreach ($relatives as $relative) {
    $source = $theme_directory . '/' . $relative;
    if (is_link($source) || !is_file($source) || !preg_match(WORDPAL_LEGAL_FILES, $relative)) {
      continue;
    }
    $target = $out . '/' . WORDPAL_LEGAL_PREFIX . $relative;
    if (!is_dir(dirname($target))) {
      mkdir(dirname($target), 0777, TRUE);
    }
    copy($source, $target);
    $paths[] = WORDPAL_LEGAL_PREFIX . $relative;
  }
  sort($paths);
  return $paths;
}

/**
 * Copies each plugin directory's root Legal files and returns their paths.
 *
 * A plugin's files go under WORDPAL_PLUGIN_ASSETS, beside the plugin assets
 * its stylesheets reference.
 */
function wordpal_copy_plugin_legal_files($plugin_directories, $out) {
  $paths = [];
  foreach ($plugin_directories as $plugin_directory) {
    foreach (new FilesystemIterator(WP_PLUGIN_DIR . '/' . $plugin_directory) as $file) {
      if ($file->isLink() || !$file->isFile() || !preg_match(WORDPAL_PLUGIN_LEGAL_FILES, $file->getFilename())) {
        continue;
      }
      $path = WORDPAL_LEGAL_PREFIX . WORDPAL_PLUGIN_ASSETS . $plugin_directory . '/' . $file->getFilename();
      if (!is_dir(dirname($out . '/' . $path))) {
        mkdir(dirname($out . '/' . $path), 0777, TRUE);
      }
      copy($file->getPathname(), $out . '/' . $path);
      $paths[] = $path;
    }
  }
  return $paths;
}

/**
 * Copies the theme's static assets and returns their snapshot paths.
 */
function wordpal_copy_assets($theme_directory, $out) {
  $source = $theme_directory . '/assets';
  if (!is_dir($source)) {
    return [];
  }
  $allowed = wordpal_asset_extensions();
  $paths = [];
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
  foreach ($iterator as $file) {
    if (!$file->isFile() || $file->isLink() || !in_array(strtolower($file->getExtension()), $allowed, TRUE)) {
      continue;
    }
    $relative = 'assets/' . substr($file->getPathname(), strlen($source) + 1);
    $target = $out . '/' . $relative;
    if (!is_dir(dirname($target))) {
      mkdir(dirname($target), 0777, TRUE);
    }
    copy($file->getPathname(), $target);
    $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
  }
  sort($paths);
  return $paths;
}

/**
 * Writes one stylesheet and adds it to the ordered manifest.
 */
function wordpal_write_style($out, &$manifest, $path, $css, $theme_url) {
  file_put_contents($out . '/' . $path, wordpal_css_urls($css, $theme_url));
  $manifest[] = $path;
}

/**
 * Writes one stylesheet of the theme directory.
 *
 * A handle whose sanitized name repeats an earlier one is refused, the way
 * a theme script whose handle is unsafe for a file name is refused.
 */
function wordpal_write_theme_style($out, &$manifest, &$assets, $handle, $source_path, $theme_directory, $theme_url) {
  $path = 'styles/theme-' . sanitize_file_name((string) $handle) . '.css';
  if (in_array($path, $manifest, TRUE)) {
    return;
  }
  $css = wordpal_css_asset_urls((string) file_get_contents($source_path), $source_path, $theme_directory, 'wordpal_theme_asset_path', $out, $assets);
  wordpal_write_style($out, $manifest, $path, $css, $theme_url);
}

$style_manifest = [];
$core_blocks = (string) file_get_contents(ABSPATH . WPINC . '/css/dist/block-library/style' . wp_scripts_get_suffix() . '.css');
// wp_common_block_scripts_and_styles() prints wp-block-library-theme right
// after wp-block-library, for a theme that supports wp-block-styles.
if (current_theme_supports('wp-block-styles')) {
  $core_blocks .= "\n" . file_get_contents(ABSPATH . WPINC . '/css/dist/block-library/theme' . wp_scripts_get_suffix() . '.css');
}
wordpal_write_style($out, $style_manifest, $request['block_library_css'], $core_blocks, $theme_url);
wordpal_write_style($out, $style_manifest, 'styles/font-faces.css', wordpal_font_faces(), $theme_url);
wordpal_write_style($out, $style_manifest, 'styles/block-styles.css', wordpal_block_style_css(), $theme_url);

$theme_root = realpath($theme_directory);
$exported = [];
foreach (wp_styles()->registered as $handle => $style) {
  $path = $style->extra['path'] ?? NULL;
  if (!is_string($path) || !str_starts_with($path, $theme_directory . '/') || !is_file($path)) {
    continue;
  }
  wordpal_write_theme_style($out, $style_manifest, $css_assets, $handle, $path, $theme_directory, $theme_url);
  $exported[$handle] = TRUE;
}

// The stylesheets the theme enqueues for the front end, in the order
// WordPress prints them. The hook also queues core and plugin styles, whose
// files lie outside the theme directory.
do_action('wp_enqueue_scripts');
$queued = wp_styles();
$queued->all_deps($queued->queue);
foreach ($queued->to_do as $handle) {
  $src = $queued->registered[$handle]->src;
  if (isset($exported[$handle]) || !is_string($src) || !str_starts_with($src, $theme_url . '/')) {
    continue;
  }
  $path = realpath($theme_directory . '/' . strtok(substr($src, strlen($theme_url) + 1), '?#'));
  if ($path === FALSE || !str_starts_with($path, $theme_root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    continue;
  }
  wordpal_write_theme_style($out, $style_manifest, $css_assets, $handle, $path, $theme_directory, $theme_url);
  $exported[$handle] = TRUE;
}
$queued->to_do = [];

file_put_contents($out . '/styles.json', json_encode($style_manifest, JSON_PRETTY_PRINT));

// The scripts the theme enqueues for the front end, in the order WordPress
// prints them, with the handles each depends on. Core and plugin scripts lie
// outside the theme directory.
$queued_scripts = wp_scripts();
$queued_scripts->all_deps($queued_scripts->queue);
$theme_scripts = [];
foreach ($queued_scripts->to_do as $handle) {
  $src = $queued_scripts->registered[$handle]->src;
  if (!is_string($src) || !str_starts_with($src, $theme_url . '/') || !preg_match(WORDPAL_HANDLE_PATTERN, $handle)) {
    continue;
  }
  $path = realpath($theme_directory . '/' . strtok(substr($src, strlen($theme_url) + 1), '?#'));
  if ($path === FALSE || !str_starts_with($path, $theme_root . DIRECTORY_SEPARATOR) || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'js') {
    continue;
  }
  $script_path = 'scripts/theme-' . $handle . '.js';
  copy($path, $out . '/' . $script_path);
  $theme_scripts[] = [
    'handle' => $handle,
    'path' => $script_path,
    'dependencies' => array_values($queued_scripts->registered[$handle]->deps),
    'footer' => $queued_scripts->get_data($handle, 'group') === 1,
    'strategy' => $queued_scripts->get_data($handle, 'strategy') ?: NULL,
  ];
}
$queued_scripts->to_do = [];
file_put_contents($out . '/scripts.json', json_encode($theme_scripts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

/**
 * Returns the plugin styles a stylesheet depends on, as plugin handles.
 */
function wordpal_plugin_style_dependencies($handle) {
  return array_values(array_unique(wordpal_plugin_style_handles(wp_styles()->registered[$handle]->deps)));
}

// A plugin can queue inline CSS for the whole page, as Gutenverse does with
// its generated dynamic CSS. WordPress prints it with every block of that
// plugin, so each Frozen block that ships the plugin's CSS takes it too.
$page_styles = [];
foreach (wp_styles()->queue as $handle) {
  $inline_only = wp_styles()->registered[$handle]->src === FALSE && wordpal_plugin_inline_css($handle) !== '';
  $plugin = $inline_only ? wordpal_style_plugin($handle) : NULL;
  if ($plugin !== NULL && preg_match(WORDPAL_HANDLE_PATTERN, $handle)) {
    $page_styles[$plugin][] = $handle;
  }
}
foreach ($rendered_styles as $key => $handles) {
  $handles = array_values(array_unique(wordpal_plugin_style_handles($handles)));
  foreach (array_unique(array_map('wordpal_style_plugin', $handles)) as $plugin) {
    $handles = array_values(array_unique([...$handles, ...$page_styles[$plugin] ?? []]));
  }
  if ($handles === []) {
    unset($rendered_styles[$key]);
  }
  else {
    $rendered_styles[$key] = $handles;
  }
}

// The plugin CSS the recorded blocks enqueued, with the plugin stylesheets
// it depends on. Each keeps its url() assets under the plugin assets prefix,
// by its path in the plugins directory.
$plugin_styles = [];
$pending = array_merge([], ...array_values($rendered_styles));
while ($pending !== []) {
  $handle = array_shift($pending);
  if (isset($plugin_styles[$handle])) {
    continue;
  }
  $plugin = wordpal_style_plugin($handle);
  // Inline CSS has no file: its relative url() resolves from the plugin root.
  $source_path = wordpal_plugin_style_file($handle) ?? WP_PLUGIN_DIR . '/' . $plugin . '/inline.css';
  $path = WORDPAL_PLUGIN_STYLE_PREFIX . $handle . '.css';
  $css = wordpal_css_asset_urls(wordpal_plugin_inline_css($handle), $source_path, WP_PLUGIN_DIR, static fn ($relative) => WORDPAL_PLUGIN_ASSETS . $relative, $out, $css_assets);
  file_put_contents($out . '/' . $path, wordpal_css_urls($css, $theme_url));
  $dependencies = wordpal_plugin_style_dependencies($handle);
  $plugin_styles[$handle] = ['path' => $path, 'plugin' => $plugin, 'dependencies' => $dependencies];
  $pending = [...$pending, ...$dependencies];
}
ksort($plugin_styles);
ksort($rendered_styles);
file_put_contents($out . '/plugin-styles.json', json_encode((object) $plugin_styles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($out . '/block-styles.json', json_encode((object) $rendered_styles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

/**
 * Returns the Global stylesheet with the block style variations' rules.
 *
 * This is wp_get_global_stylesheet() for a block theme, plus the rules of the
 * theme.json block style variations. The root rules select the plain
 * `is-style-<variation>` class. The inner element and block rules select the
 * class wordpal_variation_class() returns.
 */
function wordpal_global_stylesheet() {
  $tree = WP_Theme_JSON_Resolver::resolve_theme_file_uris(WP_Theme_JSON_Resolver::get_merged_data());
  $origins = ['default', 'theme', 'custom'];
  return $tree->get_stylesheet(['variables'], $origins)
    . $tree->get_stylesheet(['styles', 'presets'], $origins, ['include_block_style_variations' => TRUE])
    . wordpal_block_style_variation_css();
}

/**
 * Returns the class every converted instance of a styled variation carries.
 *
 * The snapshot's settings.json lists it under `styled_variations`, and
 * \Drupal\wordpal\Support\BlockSupports prints it.
 */
function wordpal_variation_class($block_name, $variation) {
  return "is-style-$variation--" . substr(md5($block_name . $variation), 0, 2);
}

/**
 * Returns the stylesheet WordPress prints with each block style variation.
 *
 * WordPress renders a variation's inner elements and blocks, such as its
 * link styles, per block instance under `is-style-<variation>--<n>`, a
 * number wp_unique_id() gives. The rules here select the one class
 * wordpal_variation_class() returns instead.
 */
function wordpal_block_style_variation_css() {
  $css = [];
  $blocks = WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data()['styles']['blocks'] ?? [];
  foreach ($blocks as $block_name => $block_styles) {
    foreach (array_keys($block_styles['variations'] ?? []) as $variation) {
      $parsed = wp_render_block_style_variation_support_styles([
        'blockName' => $block_name,
        'attrs' => ['className' => "is-style-$variation"],
      ]);
      // The support re-adds this filter, which the snapshot keeps off.
      remove_filter('wp_theme_json_get_style_nodes', 'wp_filter_out_block_nodes');
      if (!preg_match('/\bis-style-' . preg_quote($variation, '/') . '--\d+\b/', $parsed['attrs']['className'], $instance)) {
        continue;
      }
      $after = wp_styles()->get_data('block-style-variation-styles', 'after');
      $css[] = str_replace($instance[0], wordpal_variation_class($block_name, $variation), end($after));
    }
  }
  return implode('', $css);
}

/**
 * Returns the Global stylesheet with one style variation applied.
 *
 * The Site Editor stores a chosen variation as the user's global styles, so
 * the variation merges in at that origin.
 */
function wordpal_variation_stylesheet($variation) {
  $filter = static fn (WP_Theme_JSON_Data $data) => $data->update_with($variation);
  add_filter('wp_theme_json_data_user', $filter);
  wp_clean_theme_json_cache();
  $css = wordpal_global_stylesheet();
  remove_filter('wp_theme_json_data_user', $filter);
  wp_clean_theme_json_cache();
  return $css;
}

// One Global stylesheet per style variation, the theme's own styles first.
// WordPress lists the theme's and its parent's styles/**/*.json without
// section styles. The Site Editor then drops color and typography presets.
// The slug comes from the title, as WordPress names a variation by it.
// Rendering the home Template adds wp_filter_out_block_nodes, which drops the
// per-block styles from the Global stylesheet. WordPress then prints them
// with each block. The generated theme loads one stylesheet, so it keeps
// them, except those of a core block whose block.json names no front-end
// style. wp_add_global_styles_for_blocks() prints a core block's styles only
// when its wp-block-<name> handle is enqueued, and a block enqueues only the
// handles its block.json names.
remove_filter('wp_theme_json_get_style_nodes', 'wp_filter_out_block_nodes');
add_filter('wp_theme_json_get_style_nodes', static fn (array $nodes): array => array_filter($nodes, static function (array $node): bool {
  $block_name = (string) ($node['name'] ?? wp_get_block_name_from_theme_json_path($node['path']));
  $block_type = WP_Block_Type_Registry::get_instance()->get_registered($block_name);
  return !str_starts_with($block_name, 'core/') || in_array('wp-block-' . substr($block_name, 5), $block_type->style_handles ?? [], TRUE);
}));
wp_clean_theme_json_cache();
$variations = [['slug' => $request['default_variation'], 'title' => 'Default', 'path' => 'styles/global.css']];
file_put_contents($out . '/styles/global.css', wordpal_css_urls(wordpal_global_stylesheet(), $theme_url));
require __DIR__ . '/style-variations.php';
foreach (array_filter(WP_Theme_JSON_Resolver::get_style_variations('theme'), 'wordpal_is_full_variation') as $variation) {
  $slug = sanitize_title($variation['title']);
  $path = 'styles/variation-' . count($variations) . '.css';
  file_put_contents($out . '/' . $path, wordpal_css_urls(wordpal_variation_stylesheet($variation), $theme_url));
  $variations[] = ['slug' => $slug, 'title' => $variation['title'], 'path' => $path];
}
file_put_contents($out . '/variations.json', json_encode($variations, JSON_PRETTY_PRINT));
$assets = array_values(array_unique([...wordpal_copy_assets($theme_directory, $out), ...$css_assets]));
sort($assets);
file_put_contents($out . '/assets.json', json_encode($assets, JSON_PRETTY_PRINT));
$legal_files = [
  ...wordpal_copy_legal_files($theme_directory, $out),
  ...wordpal_copy_plugin_legal_files(array_keys($plugin_headers), $out),
];
sort($legal_files);
file_put_contents($out . '/legal.json', json_encode($legal_files, JSON_PRETTY_PRINT));
