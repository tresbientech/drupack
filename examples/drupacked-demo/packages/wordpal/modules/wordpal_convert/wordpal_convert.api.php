<?php

/**
 * @file
 * Hooks provided by the WordPal Convert module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Adds or changes files WordPal writes into a generated theme.
 *
 * A page builder module implements this to add its own template files to
 * the theme ThemeGenerator writes, without wordpal_convert knowing that
 * builder's name.
 *
 * @param array $files
 *   File contents keyed by their app-root-relative path, such as
 *   "themes/custom/$themeId/templates/example.html.twig". Setting an
 *   existing key replaces that file's contents; a new key adds a file.
 * @param string $themeId
 *   The generated theme's Drupal machine name.
 */
function hook_wordpal_theme_files_alter(array &$files, string $themeId): void {
  $files["themes/custom/$themeId/templates/example.html.twig"] = "{{ content }}\n";
}

/**
 * Alters a generated component definition before it is written.
 *
 * ComponentCommands calls this once per component, after building the
 * definition from a WordPress block.json and before writing it to
 * components/$blockName/$blockName.component.yml. A page builder module
 * implements this to overlay props it binds at render time, such as an
 * entity field, onto the generated definition.
 *
 * @param array $definition
 *   The generated component definition, in Drupal Single Directory
 *   Component form.
 * @param string $blockName
 *   The component's slug, matching its directory under components/.
 */
function hook_wordpal_component_definition_alter(array &$definition, string $blockName): void {
  if ($blockName === 'example') {
    $definition['props']['properties']['example_prop']['title'] = 'Example prop';
  }
}

/**
 * @} End of "addtogroup hooks".
 */
