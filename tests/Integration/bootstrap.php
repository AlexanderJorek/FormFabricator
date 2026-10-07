<?php

/**
 * Bootstrap for the integration suite: real WordPress (vendor/roots/wordpress-no-content), a real database, the WP
 * core test library (vendor/wp-phpunit/wp-phpunit), and the plugin loaded the way WordPress loads it.
 *
 * Kept apart from tests/bootstrap.php, which stubs WordPress for the unit and perf suites; see tests/phpunit-integration.xml.dist.
 * Set WP_MULTISITE=1 to run the same suite on a multisite network.
 */

$fabricator_root = dirname(__DIR__, 2);

require $fabricator_root . '/vendor/autoload.php'; // PHPUnit Polyfills, and WP_PHPUNIT__DIR via wp-phpunit's __loaded.php

putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');

// The test site runs with WP_DEBUG, so fabricator_log() writes on every submission; keep that out of the test output.
// A test that asserts on log content points error_log at its own file for the duration.
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formfabricator-integration.log');

$fabricator_tests_dir = getenv('WP_PHPUNIT__DIR');
require_once $fabricator_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function () use ($fabricator_root): void {
    require $fabricator_root . '/formfabricator.php';
});

// Builtins the plugin calls unqualified that no real request can satisfy in a test (is_uploaded_file()); see the file.
require dirname(__DIR__) . '/Support/namespace-overrides.php';

require $fabricator_tests_dir . '/includes/bootstrap.php';

// A test that runs in its own process (one defining FABRICATOR_SEAL_MASTER_KEY, which no later test may see) boots
// this file again; the parent installed the test site already, and installing again would drop its tables mid-run.
putenv('WP_TESTS_SKIP_INSTALL=1');
