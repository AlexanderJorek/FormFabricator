<?php

/**
 * Editor-only stub for WordPress core time constants; never loaded at runtime.
 */

define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 60 * 60);
define('DAY_IN_SECONDS', 60 * 60 * 24);
define('WEEK_IN_SECONDS', 60 * 60 * 24 * 7);
define('MONTH_IN_SECONDS', 60 * 60 * 24 * 30);
define('YEAR_IN_SECONDS', 60 * 60 * 24 * 365);

/**
 * wp-config.php debug switches — set by the site, not by any plugin.
 * Always accessed via defined() guards, so absence at runtime is expected.
 */
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);

/**
 * FormFabricator PDF seal master key — a site-specific secret meant to be defined
 * in wp-config.php by the site admin (see FormSettings.php / HashSeal.php).
 * It is intentionally absent from the codebase itself; every real use is
 * guarded with defined(). Stubbed here only so Intelephense can resolve it.
 */
define('FABRICATOR_SEAL_MASTER_KEY', '');
