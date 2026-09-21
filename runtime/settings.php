<?php

$GLOBALS['conf']['container_service_providers']['drupack'] = 'Drupack\Support\WindowsPathServiceProvider';

$data = getenv('DRUPACK_RUNTIME_DATA_DIR');
$databases['default']['default'] = __DRUPACK_DATABASE_CONFIGURATION__;
$settings['hash_salt'] = file_get_contents($data . '/hash_salt');
$settings['config_sync_directory'] = $data . '/config';
// The address, not the directory: SiteDataPublicStream resolves it into Site
// data, so a page carries a root-relative address for a file the application
// never holds.
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = $data . '/private';
$settings['file_temp_path'] = $data . '/tmp';
// Drupal compiles the application path into its container and keys that cache
// without it, so a site whose application moved would boot a container naming
// the directory it left. Each application directory gets its own key. The
// launcher that ships with this settings file always exports
// DRUPACK_RUNTIME_APP_DIR in its canonical, forward-slash form, so hashing the
// exported value directly is enough to key it.
$settings['deployment_identifier'] = substr(hash('sha256', (string) getenv('DRUPACK_RUNTIME_APP_DIR')), 0, 16);
$settings['update_free_access'] = FALSE;
// The site directory lives inside the application, which every site of this
// release shares and no site writes to. Hardening it to read-only stops a later
// start from replacing or clearing that copy.
$settings['skip_permissions_hardening'] = TRUE;
$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^127\\.0\\.0\\.1$',
  '^\\[::1\\]$',
  '^' . preg_quote(getenv('DRUPACK_RUNTIME_HOST'), '/') . '$',
];
$config['locale.settings']['translation']['use_source'] = 'local';
$config['project_browser.admin_settings']['allow_ui_install'] = FALSE;
$config['project_browser.admin_settings']['enabled_sources'] = [];
