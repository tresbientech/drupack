<?php

// Simple OAuth signs and checks tokens with a key pair each site keeps in its
// own Site data, which no route serves.
$drupack_oauth_keys = getenv('DRUPACK_RUNTIME_DATA_DIR') . '/oauth-keys';
$config['simple_oauth.settings']['public_key'] = "$drupack_oauth_keys/public.key";
$config['simple_oauth.settings']['private_key'] = "$drupack_oauth_keys/private.key";
// The seed applies Agent Access from Drush, which stores http://localhost here.
// Empty, the endpoint follows each request's host.
$config['simple_oauth_server_metadata.settings']['registration_endpoint'] = '';

// The seed build runs Drush alone, so the application ships no key, and a
// site's first served request generates its pair. A request that loses the
// rename to another discards its own pair, so the two keys always match.
if (PHP_SAPI !== 'cli' && !is_dir($drupack_oauth_keys)) {
  $drupack_staging = "$drupack_oauth_keys." . bin2hex(random_bytes(8));
  $drupack_key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
  if ($drupack_key === FALSE || !openssl_pkey_export($drupack_key, $drupack_private) || !mkdir($drupack_staging, 0700)) {
    throw new RuntimeException('Cannot generate the OAuth key pair: ' . openssl_error_string());
  }
  $drupack_pems = ['private.key' => $drupack_private, 'public.key' => openssl_pkey_get_details($drupack_key)['key']];
  foreach ($drupack_pems as $drupack_name => $drupack_pem) {
    if (file_put_contents("$drupack_staging/$drupack_name", $drupack_pem) === FALSE || !chmod("$drupack_staging/$drupack_name", 0600)) {
      throw new RuntimeException("Cannot write the OAuth key $drupack_staging/$drupack_name");
    }
  }
  if (!@rename($drupack_staging, $drupack_oauth_keys)) {
    foreach (array_keys($drupack_pems) as $drupack_name) {
      unlink("$drupack_staging/$drupack_name");
    }
    rmdir($drupack_staging);
    if (!is_dir($drupack_oauth_keys)) {
      throw new RuntimeException("Cannot move the OAuth key pair into $drupack_oauth_keys");
    }
  }
}
