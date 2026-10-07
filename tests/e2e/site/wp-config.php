<?php

/**
 * wp-config for the E2E site, on the integration suite's database with tables of its own (prefix e2e_, or
 * FABRICATOR_E2E_TABLE_PREFIX for runs side by side). install.php writes the one-line wp-config.php that requires it;
 * everything that differs per run comes from the environment.
 */

$fabricator_e2e_env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
};

define('DB_HOST', $fabricator_e2e_env('WP_TESTS_DB_HOST', '127.0.0.1'));
define('DB_NAME', $fabricator_e2e_env('WP_TESTS_DB_NAME', 'wordpress_test'));
define('DB_USER', $fabricator_e2e_env('WP_TESTS_DB_USER', 'root'));
define('DB_PASSWORD', $fabricator_e2e_env('WP_TESTS_DB_PASSWORD', 'root'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
// Apart from the integration suite's tables in the same database, and from other E2E runs started beside this one.
$table_prefix = $fabricator_e2e_env('FABRICATOR_E2E_TABLE_PREFIX', 'e2e_');

// Salts for a throwaway site: fixed, so a login made by global setup stays valid for the whole run.
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $fabricator_e2e_key) {
    define($fabricator_e2e_key, 'formfabricator-e2e-' . $fabricator_e2e_key);
}

$fabricator_e2e_url = rtrim($fabricator_e2e_env('FABRICATOR_E2E_URL', 'http://127.0.0.1:8899'), '/');
define('WP_HOME', $fabricator_e2e_url);
define('WP_SITEURL', $fabricator_e2e_url);
// Themes, plugins, mu-plugins and uploads live in a folder of the run's own, served by router.php.
define('WP_CONTENT_DIR', $fabricator_e2e_env('FABRICATOR_E2E_CONTENT'));
define('WP_CONTENT_URL', $fabricator_e2e_url . '/wp-content');

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', WP_CONTENT_DIR . '/debug.log');
define('WP_DEBUG_DISPLAY', false);
// No request to itself: on Windows the PHP server answers one request at a time, so a loopback would wait on itself.
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', '');

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 3) . '/vendor/roots/wordpress-no-content/');
}
require_once ABSPATH . 'wp-settings.php';
