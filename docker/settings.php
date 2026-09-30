<?php

/**
 * @file
 * Environment-driven settings for containerized / Northflank deployment.
 *
 * All secrets and connection details come from environment variables so nothing
 * sensitive is committed. Set these in the Northflank service:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD   (or a single DATABASE_URL)
 *   HASH_SALT                (required; a long random string)
 *   TRUSTED_HOST_PATTERNS    (comma-separated regexes, e.g. ^.*\.code\.run$)
 *   DRUPAL_PRIVATE_FILES     (optional, e.g. /var/www/private)
 *   REVERSE_PROXY_ADDRESSES  (optional, comma-separated)
 */

// Database: individual vars, or a single DATABASE_URL which takes precedence.
$databases['default']['default'] = [
  'driver' => getenv('DB_DRIVER') ?: 'mysql',
  'host' => getenv('DB_HOST') ?: 'localhost',
  'port' => getenv('DB_PORT') ?: '3306',
  'database' => getenv('DB_NAME') ?: '',
  'username' => getenv('DB_USER') ?: '',
  'password' => getenv('DB_PASSWORD') ?: '',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

if ($database_url = getenv('DATABASE_URL')) {
  $u = parse_url($database_url);
  $scheme = $u['scheme'] ?? 'mysql';
  $databases['default']['default'] = [
    'driver' => in_array($scheme, ['postgres', 'postgresql', 'pgsql'], TRUE) ? 'pgsql' : 'mysql',
    'host' => $u['host'] ?? 'localhost',
    'port' => $u['port'] ?? 3306,
    'database' => isset($u['path']) ? ltrim($u['path'], '/') : '',
    'username' => isset($u['user']) ? rawurldecode($u['user']) : '',
    'password' => isset($u['pass']) ? rawurldecode($u['pass']) : '',
    'prefix' => '',
    'collation' => 'utf8mb4_general_ci',
  ];
}

// TLS for the database connection. Northflank's MySQL addon (when deployed with
// TLS) sets require_secure_transport=ON, so the client must connect encrypted.
// Set DB_SSL=true to enable it. Provide DB_SSL_CA (a path to the CA cert) to
// verify the server certificate; without it the connection is still encrypted
// but the certificate is not verified, which is enough to satisfy the server.
if (in_array(strtolower((string) getenv('DB_SSL')), ['1', 'true', 'yes'], TRUE)) {
  $ca = getenv('DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';
  $databases['default']['default']['pdo'][PDO::MYSQL_ATTR_SSL_CA] = $ca;
  if (!getenv('DB_SSL_CA')) {
    $databases['default']['default']['pdo'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = FALSE;
  }
}

// Required security salt.
$settings['hash_salt'] = getenv('HASH_SALT') ?: '';

// Config is version-controlled at <project>/config/sync (one level above web/).
$settings['config_sync_directory'] = '../config/sync';

// File paths.
$settings['file_temp_path'] = getenv('DRUPAL_TEMP_PATH') ?: '/tmp';
if ($private = getenv('DRUPAL_PRIVATE_FILES')) {
  $settings['file_private_path'] = $private;
}

// Trusted host patterns (set TRUSTED_HOST_PATTERNS in the environment!).
if ($hosts = getenv('TRUSTED_HOST_PATTERNS')) {
  $settings['trusted_host_patterns'] = array_values(array_filter(array_map('trim', explode(',', $hosts))));
}
else {
  // Fallback allows any host. Set TRUSTED_HOST_PATTERNS in production.
  $settings['trusted_host_patterns'] = ['^.+$'];
}

// Behind Northflank's edge proxy (TLS terminated upstream).
$settings['reverse_proxy'] = TRUE;
if ($proxies = getenv('REVERSE_PROXY_ADDRESSES')) {
  $settings['reverse_proxy_addresses'] = array_values(array_filter(array_map('trim', explode(',', $proxies))));
}

// Standard hardening / defaults.
$settings['update_free_access'] = FALSE;
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components'];
$settings['skip_permissions_hardening'] = FALSE;

// BigBlueButton credentials from the environment. Runtime $config overrides win
// over the database value and are NOT touched by `drush config:import`, so the
// secret is never committed and never wiped by a deploy. Set BBB_HOST and
// BBB_SECRET in the Northflank service (or leave them and configure via the UI).
if ($bbb_host = getenv('BBB_HOST')) {
  $config['bigbluebutton.settings']['hostname'] = $bbb_host;
}
if ($bbb_secret = getenv('BBB_SECRET')) {
  $config['bigbluebutton.settings']['secret'] = $bbb_secret;
}

// Load the site-specific services file placed by drupal-scaffold.
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';

// Optional local override (never committed; useful for debugging a container).
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
