<?php

$GLOBALS['conf']['container_service_providers']['drupack'] = 'Drupack\Support\WindowsPathServiceProvider';

$data = getenv('DRUPACK_RUNTIME_DATA_DIR');
$databases['default']['default'] = __DRUPACK_DATABASE_CONFIGURATION__;
$settings['hash_salt'] = file_get_contents($data . '/hash_salt');
$settings['config_sync_directory'] = $data . '/config';
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = $data . '/private';
$settings['file_temp_path'] = $data . '/tmp';
$settings['update_free_access'] = FALSE;
$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^127\\.0\\.0\\.1$',
  '^\\[::1\\]$',
  '^' . preg_quote(getenv('DRUPACK_RUNTIME_HOST'), '/') . '$',
];
$config['locale.settings']['translation']['use_source'] = 'local';
$config['project_browser.admin_settings']['allow_ui_install'] = FALSE;
$config['project_browser.admin_settings']['enabled_sources'] = [];
