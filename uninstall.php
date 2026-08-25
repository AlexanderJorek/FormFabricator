<?php

/**
 * Removes all plugin data from the database on plugin deletion.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.3
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

// WP_UNINSTALL_PLUGIN is only ever defined by WordPress core immediately before it
// requires this file during a plugin deletion — this guard blocks direct execution
defined('WP_UNINSTALL_PLUGIN') || exit;

/*
 * WARNING: This file runs when the plugin is deleted from the WordPress admin.
 * ALL plugin data — including PDF seal keys and key history — will be permanently
 * removed. There is no recovery. Back up your seal keys before uninstalling.
 */

/* Belt-and-braces: deactivation already clears these, but uninstall can be
   triggered without a preceding deactivate (e.g. WP-CLI --skip-plugins force
   delete), so clear the recurring temp-file sweeps here too. */
wp_clear_scheduled_hook('forge_verifier_sweep_tmp_dirs');
wp_clear_scheduled_hook('forge_generator_sweep_tmp_dirs');

/* Remove all stored forms (CPT posts + meta) */
$forge_forms = get_posts(
    [
    'post_type'      => 'forge_form',
    'posts_per_page' => -1,
    'post_status'    => 'any',
    'fields'         => 'ids',
    ]
);
foreach ($forge_forms as $forge_form_id) {
    wp_delete_post($forge_form_id, true);
}

/* Remove all plugin options — including seal keys and encryption state */
$forge_options = [
    'forge_forms_from_email',
    'forge_forms_from_name',
    'forge_forms_recaptcha_site_key',
    'forge_forms_recaptcha_secret_key',
    'forge_forms_hover_color',
    'forge_forms_accent_color',
    'forge_forms_admin_accent',
    'forge_forms_border_color',
    'forge_forms_pdf_settings',
    'forge_forms_pdf_layout',
    'forge_forms_field_layout',
    'forge_forms_version',
    'forge_form_selects',
    // Seal key data — deleted on uninstall, NOT on reset.
    'forge_forms_seal_key',
    'forge_forms_seal_key_history',
    'forge_forms_seal_key_pending_download',
    'forge_forms_seal_encryption',
    'forge_forms_seal_setup_done',
    'forge_forms_access',
];
foreach ($forge_options as $forge_option) {
    delete_option($forge_option);
}

/* Remove rate-limiter bucket rows. These are not in the fixed $forge_options list above
   because their names are dynamic (forge_rl_<hash>) — one row per rate-limited
   key/IP combination. The hourly sweep (Utils/RateLimiter.php) normally expires
   these, but if it never ran (e.g. site deleted immediately after install, or
   WP-Cron disabled) rows could otherwise survive plugin deletion indefinitely. */
global $wpdb;
// Bulk cleanup of this plugin's own dynamically-named forge_rl_* rows during uninstall; the
// plugin is being removed, so there is no caching concern, and this pattern-based bulk delete
// cannot be expressed via delete_option() (which only takes a single known option name).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('forge_rl_') . '%'
    )
);
wp_cache_delete('alloptions', 'options');

/* Remove single-use submission-claim rows (forge_su_*) for the same reason as the rate-limiter
   rows above — see Utils/SingleUseToken.php. */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see forge_rl_ cleanup comment above
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('forge_su_') . '%'
    )
);
wp_cache_delete('alloptions', 'options');

/* Remove concurrency-slot rows (forge_cs_*) for the same reason as the rate-limiter
   rows above — see Utils/ConcurrencySlot.php. */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see forge_rl_ cleanup comment above
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('forge_cs_') . '%'
    )
);
wp_cache_delete('alloptions', 'options');

/* Remove upload directory */
$forge_upload_dir = wp_upload_dir();
$forge_plugin_dir = $forge_upload_dir['basedir'] . '/forge-secure-pdf';
if (is_dir($forge_plugin_dir)) {
    // uninstall.php only ever runs from a WP-admin-triggered plugin deletion (or WP-CLI running as the
    // same privileged user), so WP core's own filesystem credentials are available here — unlike
    // Generator.php/MailSender.php's front-end/shutdown-function cleanup paths, WP_Filesystem() can be
    // relied on safely in this context.
    global $wp_filesystem;
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $forge_fs_ready = WP_Filesystem() && $wp_filesystem instanceof \WP_Filesystem_Base;

    if (!$forge_fs_ready) {
        // WP_Filesystem() can still return false / leave $wp_filesystem unset on a server
        // that requires FTP/SSH credentials WordPress has none stored for (unusual, but not
        // impossible, for an admin-triggered uninstall or a WP-CLI run without FS_METHOD
        // forced to 'direct'). Calling ->rmdir() on a null/non-object here would fatal
        // instead of leaving the (already-emptied-of-DB-data) directory behind — log and
        // bail out of just this cleanup step rather than crashing the whole uninstall.
        // uninstall.php runs standalone, outside the plugin's normal bootstrap/logging
        // (forge_log()), so wp-content debug logging is the only reasonable way to surface
        // a real cleanup failure here; this is uninstall diagnostics for a plugin that
        // handles PII, not leftover debug code.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see comment above
        error_log('FormFabricator uninstall: WP_Filesystem unavailable, skipping removal of ' . $forge_plugin_dir);
    } else {
        $forge_it    = new RecursiveDirectoryIterator($forge_plugin_dir, FilesystemIterator::SKIP_DOTS);
        $forge_files = new RecursiveIteratorIterator($forge_it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($forge_files as $forge_file) {
            $forge_path = $forge_file->getRealPath();
            if ($forge_file->isDir()) {
                $forge_ok = $wp_filesystem->rmdir($forge_path);
            } else {
                wp_delete_file($forge_path);
                $forge_ok = !file_exists($forge_path);
            }
            if (!$forge_ok) {
                // Same rationale as above: uninstall.php runs standalone, outside the plugin's
                // normal bootstrap/logging, so wp-content debug logging is the only reasonable
                // way to surface a real cleanup failure here.
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see comment above
                error_log('FormFabricator uninstall: failed to remove ' . $forge_path);
            }
        }
        if (!$wp_filesystem->rmdir($forge_plugin_dir)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see justification above.
            error_log('FormFabricator uninstall: failed to remove directory ' . $forge_plugin_dir);
        }
    }
}
