<?php

/**
 * PHPUnit bootstrap for the unit and perf suites: no WordPress is loaded.
 *
 * Plugin classes are found through Composer's PSR-4 map, as in production. The plugin's files open with
 * `defined('ABSPATH') || exit;`, so ABSPATH is defined first; WordPress functions a test needs are stubbed per test with
 * Brain Monkey (see Support\TestCase).
 */

define('ABSPATH', __DIR__ . '/');

// WordPress's time and size constants (wp-includes/default-constants.php), with the values WordPress gives them.
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);
define('MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS);
define('YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS);
define('KB_IN_BYTES', 1024);
define('MB_IN_BYTES', 1024 * KB_IN_BYTES);
define('GB_IN_BYTES', 1024 * MB_IN_BYTES);
define('WP_MAX_MEMORY_LIMIT', '256M'); // WordPress's default for admin and wp_raise_memory_limit()

// What formfabricator.php defines; field classes read their CSS/JS assets from the plugin path.
define('FABRICATOR_FORMS_PATH', dirname(__DIR__) . '/');
define('FABRICATOR_FORMS_URL', 'https://example.test/wp-content/plugins/formfabricator/');
define('FABRICATOR_FORMS_VERSION', '0.0.0-test');

require dirname(__DIR__) . '/vendor/autoload.php';

// fabricator_log() lives in Plugin.php beside the Plugin class; loading the file defines both and runs no hooks.
require_once dirname(__DIR__) . '/includes/Plugin.php';

require_once __DIR__ . '/Support/namespace-overrides.php';
