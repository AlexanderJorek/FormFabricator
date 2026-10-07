<?php

/**
 * WordPress test configuration for the integration suite.
 *
 * WordPress core comes from roots/wordpress-no-content in vendor/. The database is read from the environment so CI
 * and a local run need no edits here; the defaults match the MySQL service in .github/workflows/tests.yml. Everything
 * in this database is dropped and recreated on every run — never point it at a database you care about.
 */

$fabricator_env = static fn(string $name, string $default): string => getenv($name) !== false ? (string) getenv($name) : $default;

define('ABSPATH', dirname(__DIR__, 2) . '/vendor/roots/wordpress-no-content/');

define('DB_NAME', $fabricator_env('WP_TESTS_DB_NAME', 'wordpress_test'));
define('DB_USER', $fabricator_env('WP_TESTS_DB_USER', 'root'));
define('DB_PASSWORD', $fabricator_env('WP_TESTS_DB_PASSWORD', 'root'));
define('DB_HOST', $fabricator_env('WP_TESTS_DB_HOST', '127.0.0.1'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'FormFabricator Tests');
define('WP_PHP_BINARY', PHP_BINARY);
define('WPLANG', '');

// On, except in a test process started with FABRICATOR_TESTS_WP_DEBUG=0 (Form\DebugOffTest: what a live site logs).
define('WP_DEBUG', getenv('FABRICATOR_TESTS_WP_DEBUG') !== '0');

// Runs side by side (the release build starts the suites at once) each get their own tables, by prefix, and their own
// uploads, in a content folder of their own. Unset, a run uses wptests_ and WordPress's own wp-content.
if (getenv('FABRICATOR_TESTS_CONTENT_DIR') !== false) {
    define('WP_CONTENT_DIR', (string) getenv('FABRICATOR_TESTS_CONTENT_DIR'));
}

$table_prefix = $fabricator_env('FABRICATOR_TESTS_TABLE_PREFIX', 'wptests_'); // phpcs:ignore -- read by the WordPress test bootstrap as a local variable
