<?php

// The site's composer project builds the autoloader, so the engine registers its own namespace.
$class_loader->addPsr4('Drupack\\Support\\', $app_root . '/../support');
$GLOBALS['conf']['container_service_providers']['drupack'] = 'Drupack\Support\WindowsPathServiceProvider';

$data = getenv('DRUPACK_RUNTIME_DATA_DIR');
$databases['default']['default'] = __DRUPACK_DATABASE_CONFIGURATION__;
$settings['hash_salt'] = file_get_contents($data . '/hash_salt');
$settings['config_sync_directory'] = $data . '/config';
// The address, not the directory: SiteDataPublicStream resolves it into Site data.
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = $data . '/private';
$settings['file_temp_path'] = $data . '/tmp';
// Drupal compiles the application path into its container and keys that cache without it, so
// each application directory gets its own key. DRUPACK_RUNTIME_APP_DIR is already canonical,
// so hashing it directly is enough. A site's own application in Site data keeps its path
// across releases, so the release marker joins the key.
$drupack_application = (string) getenv('DRUPACK_RUNTIME_APP_DIR');
$drupack_release = is_file("$drupack_application/.release") ? file_get_contents("$drupack_application/.release") : '';
$settings['deployment_identifier'] = substr(hash('sha256', $drupack_application . $drupack_release), 0, 16);
$settings['update_free_access'] = FALSE;
// On an offline or filtered host drupal.org calls never answer, so each gets 10 seconds
// rather than Guzzle's 30.
$settings['http_client_config']['timeout'] = 10;
// dblog writes each PHP deprecation in its own transaction, and the packaged code raises about
// a hundred on an uncached page. DrupalKernel::bootEnvironment() sets E_ALL before this file
// loads, so the narrowing belongs here.
error_reporting(E_ALL & ~E_DEPRECATED);
// The site directory lives in the application, which every site of this release shares
// read-only. Hardening it would stop a later start from replacing that copy.
$settings['skip_permissions_hardening'] = TRUE;
$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^127\\.0\\.0\\.1$',
  '^\\[::1\\]$',
  '^' . preg_quote(getenv('DRUPACK_RUNTIME_HOST'), '/') . '$',
  '^' . preg_quote(getenv('DRUPACK_RUNTIME_ALIAS'), '/') . '$',
];
// automated_cron would run a queue or a drupal.org fetch inside a reader's page. The module
// stays installed, as Drupal CMS recipes install it; the server runs cron in a child process.
$config['automated_cron.settings']['interval'] = 0;
$config['locale.settings']['translation']['use_source'] = 'local';
$config['project_browser.admin_settings']['allow_ui_install'] = FALSE;
$config['project_browser.admin_settings']['enabled_sources'] = [];
// Loads last, so the site's own settings override the ones above.
$drupack_site_settings = __DRUPACK_SITE_SETTINGS__;
if ($drupack_site_settings !== '') {
  require getenv('DRUPACK_RUNTIME_APP_DIR') . '/' . $drupack_site_settings;
}
