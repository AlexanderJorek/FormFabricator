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
 * @version   1.0.4
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
wp_clear_scheduled_hook('fabricator_verifier_sweep_tmp_dirs');
wp_clear_scheduled_hook('fabricator_generator_sweep_tmp_dirs');

/* Remove all stored forms (CPT posts + meta) */
$fabricator_forms = get_posts(
    [
    'post_type'      => 'fabricator_form',
    'posts_per_page' => -1,
    'post_status'    => 'any',
    'fields'         => 'ids',
    ]
);
foreach ($fabricator_forms as $fabricator_form_id) {
    wp_delete_post($fabricator_form_id, true);
}

/* Remove all plugin options — including seal keys and encryption state */
$fabricator_options = [
    'fabricator_forms_from_email',
    'fabricator_forms_from_name',
    'fabricator_forms_recaptcha_site_key',
    'fabricator_forms_recaptcha_secret_key',
    'fabricator_forms_hover_color',
    'fabricator_forms_accent_color',
    'fabricator_forms_admin_accent',
    'fabricator_forms_border_color',
    'fabricator_forms_pdf_settings',
    'fabricator_forms_pdf_layout',
    'fabricator_forms_field_layout',
    'fabricator_forms_version',
    'fabricator_form_selects',
    // Seal key data — deleted on uninstall, NOT on reset.
    'fabricator_forms_seal_key',
    'fabricator_forms_seal_key_history',
    'fabricator_forms_seal_key_pending_download',
    'fabricator_forms_seal_encryption',
    'fabricator_forms_seal_setup_done',
    'fabricator_forms_access',
];
foreach ($fabricator_options as $fabricator_option) {
    delete_option($fabricator_option);
}

/* Remove rate-limiter bucket rows. These are not in the fixed $fabricator_options list above
   because their names are dynamic (fabricator_rl_<hash>) — one row per rate-limited
   key/IP combination. The hourly sweep (Utils/RateLimiter.php) normally expires
   these, but if it never ran (e.g. site deleted immediately after install, or
   WP-Cron disabled) rows could otherwise survive plugin deletion indefinitely. */
global $wpdb;
// Bulk cleanup of this plugin's own dynamically-named fabricator_rl_* rows during uninstall; the
// plugin is being removed, so there is no caching concern, and this pattern-based bulk delete
// cannot be expressed via delete_option() (which only takes a single known option name).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('fabricator_rl_') . '%'
    )
);
wp_cache_delete('alloptions', 'options');

/* Remove single-use submission-claim rows (fabricator_su_*) for the same reason as the rate-limiter
   rows above — see Utils/SingleUseToken.php. */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see fabricator_rl_ cleanup comment above
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('fabricator_su_') . '%'
    )
);
wp_cache_delete('alloptions', 'options');

/* Remove concurrency-slot rows (fabricator_cs_*) for the same reason as the rate-limiter
   rows above — see Utils/ConcurrencySlot.php. */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see fabricator_rl_ cleanup comment above
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('fabricator_cs_') . '%'
    )
);
wp_cache_delete('alloptions', 'options');

/* Remove upload directory */
$fabricator_upload_dir = wp_upload_dir();
$fabricator_plugin_dir = $fabricator_upload_dir['basedir'] . '/fabricator-secure-pdf';
if (is_dir($fabricator_plugin_dir)) {
    // uninstall.php only ever runs from a WP-admin-triggered plugin deletion (or WP-CLI running as the
    // same privileged user), so WP core's own filesystem credentials are available here — unlike
    // Generator.php/MailSender.php's front-end/shutdown-function cleanup paths, WP_Filesystem() can be
    // relied on safely in this context.
    global $wp_filesystem;
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $fabricator_fs_ready = WP_Filesystem() && $wp_filesystem instanceof \WP_Filesystem_Base;

    if (!$fabricator_fs_ready) {
        // WP_Filesystem() can still return false / leave $wp_filesystem unset on a server
        // that requires FTP/SSH credentials WordPress has none stored for (unusual, but not
        // impossible, for an admin-triggered uninstall or a WP-CLI run without FS_METHOD
        // forced to 'direct'). Calling ->rmdir() on a null/non-object here would fatal
        // instead of leaving the (already-emptied-of-DB-data) directory behind — log and
        // bail out of just this cleanup step rather than crashing the whole uninstall.
        // uninstall.php runs standalone, outside the plugin's normal bootstrap/logging
        // (fabricator_log()), so wp-content debug logging is the only reasonable way to surface
        // a real cleanup failure here; this is uninstall diagnostics for a plugin that
        // handles PII, not leftover debug code.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see comment above
        error_log('FormFabricator uninstall: WP_Filesystem unavailable, skipping removal of ' . $fabricator_plugin_dir);
    } else {
        $fabricator_it    = new RecursiveDirectoryIterator($fabricator_plugin_dir, FilesystemIterator::SKIP_DOTS);
        $fabricator_files = new RecursiveIteratorIterator($fabricator_it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($fabricator_files as $fabricator_file) {
            $fabricator_path = $fabricator_file->getRealPath();
            if ($fabricator_file->isDir()) {
                $fabricator_ok = $wp_filesystem->rmdir($fabricator_path);
            } else {
                wp_delete_file($fabricator_path);
                $fabricator_ok = !file_exists($fabricator_path);
            }
            if (!$fabricator_ok) {
                // Same rationale as above: uninstall.php runs standalone, outside the plugin's
                // normal bootstrap/logging, so wp-content debug logging is the only reasonable
                // way to surface a real cleanup failure here.
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see comment above
                error_log('FormFabricator uninstall: failed to remove ' . $fabricator_path);
            }
        }
        if (!$wp_filesystem->rmdir($fabricator_plugin_dir)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see justification above.
            error_log('FormFabricator uninstall: failed to remove directory ' . $fabricator_plugin_dir);
        }
    }
}
