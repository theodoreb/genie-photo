<?php

/**
 * @file
 * Settings of the genie site, required last by settings.php.
 */

// A packed site keeps keys and photos in Site data, development in ./private.
$genie_data = getenv('DRUPACK_RUNTIME_DATA_DIR') ?: __DIR__ . '/private';
$settings['file_private_path'] = "$genie_data/files";

$genie_keys = "$genie_data/oauth-keys";
$config['simple_oauth.settings']['public_key'] = "$genie_keys/public.key";
$config['simple_oauth.settings']['private_key'] = "$genie_keys/private.key";
// Empty, the endpoint follows each request's host.
$config['simple_oauth_server_metadata.settings']['registration_endpoint'] = '';

// A site's first served request generates its pair. A request that loses the
// rename to another discards its own pair, so the two keys always match.
if (PHP_SAPI !== 'cli' && !is_dir($genie_keys)) {
  $genie_staging = "$genie_keys." . bin2hex(random_bytes(8));
  $genie_openssl = ['config' => __DIR__ . '/openssl.cnf'];
  $genie_key = openssl_pkey_new($genie_openssl + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
  if ($genie_key === FALSE || !openssl_pkey_export($genie_key, $genie_private, NULL, $genie_openssl)) {
    throw new RuntimeException('Cannot generate the OAuth key pair: ' . openssl_error_string());
  }
  if (!mkdir($genie_staging, 0700, TRUE)) {
    throw new RuntimeException("Cannot create $genie_staging");
  }
  $genie_pems = ['private.key' => $genie_private, 'public.key' => openssl_pkey_get_details($genie_key)['key']];
  foreach ($genie_pems as $genie_name => $genie_pem) {
    if (file_put_contents("$genie_staging/$genie_name", $genie_pem) === FALSE || !chmod("$genie_staging/$genie_name", 0600)) {
      throw new RuntimeException("Cannot write the OAuth key $genie_staging/$genie_name");
    }
  }
  if (!@rename($genie_staging, $genie_keys)) {
    array_map(static fn (string $name) => unlink("$genie_staging/$name"), array_keys($genie_pems));
    rmdir($genie_staging);
    if (!is_dir($genie_keys)) {
      throw new RuntimeException("Cannot move the OAuth key pair into $genie_keys");
    }
  }
}

// The host code is the first line of this file, which bin/dev-drupal writes.
$config['key.key.genie_host_code']['key_provider_settings']['file_location'] = "$genie_data/host-code";
