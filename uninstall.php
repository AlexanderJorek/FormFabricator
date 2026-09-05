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
 * @version   1.0.5
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

// Blocks direct execution; only WP core defines this before requiring the file.
defined('WP_UNINSTALL_PLUGIN') || exit;

// WARNING: permanently removes all plugin data, including PDF seal keys. No recovery.

// Belt-and-braces: uninstall can run without a preceding deactivate (e.g. WP-CLI force delete).
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

/* Remove rate-limiter bucket rows — dynamically named (fabricator_rl_<hash>), so not in $fabricator_options above. */
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk delete of dynamically-named rows, no delete_option() equivalent
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('fabricator_rl_') . '%'
    )
);
wp_cache_delete('alloptions', 'options');

/* Remove single-use submission-claim rows (fabricator_su_*), same reason as rate-limiter rows above. */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see fabricator_rl_ cleanup comment above
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('fabricator_su_') . '%'
    )
);
wp_cache_delete('alloptions', 'options');

/* Remove concurrency-slot rows (fabricator_cs_*), same reason as rate-limiter rows above. */
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
    // Runs from an admin-triggered deletion (or privileged WP-CLI), so WP_Filesystem() is safe here.
    global $wp_filesystem;
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $fabricator_fs_ready = WP_Filesystem() && $wp_filesystem instanceof \WP_Filesystem_Base;

    if (!$fabricator_fs_ready) {
        // WP_Filesystem() can still fail on servers needing FTP/SSH creds; log and skip rather than fatal on ->rmdir().
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
                // Standalone script, no plugin logging available — use debug logging.
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
