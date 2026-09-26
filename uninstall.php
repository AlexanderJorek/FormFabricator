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
 * @version   1.0.7
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

/**
 * Removes every trace of the plugin from the CURRENT blog; must be called inside switch_to_blog() per site on multisite.
 *
 * @return void
 */
function fabricator_uninstall_current_site()
{
    // Belt-and-braces: uninstall can run without a preceding deactivate (e.g. WP-CLI force delete).
    /* Must stay in sync with Plugin::CRON_HOOKS + Plugin::ONE_OFF_CRON_HOOKS. Hand-listed rather than
       referenced: uninstall.php runs standalone, so the plugin's classes are never loaded here. */
    $fabricator_cron_hooks = [
        'fabricator_generator_sweep_tmp_dirs',
        'fabricator_rl_sweep_expired',
        'fabricator_su_sweep_expired',
        'fabricator_cs_sweep_expired',
        'fabricator_verifier_sweep_tmp_dirs',
        'fabricator_verifier_cleanup_files',
        'fabricator_verifier_sweep_expired',
        'fabricator_uploads_probe_run',
    ];
    foreach ($fabricator_cron_hooks as $fabricator_cron_hook) {
        wp_clear_scheduled_hook($fabricator_cron_hook);
    }

        /* Remove all stored forms (CPT posts + meta) */
    // A page at a time, not posts_per_page => -1: an unbounded query loads every form at once, and each round here
    // deletes what it read, so the next one returns the rest. The counter only stops a runaway loop if a delete fails.
    // The plugin's own classes aren't loaded during uninstall, so the batch size is spelled out rather than shared.
    for ($fabricator_round = 0; $fabricator_round < 1000; $fabricator_round++) {
        $fabricator_forms = get_posts(
            [
            'post_type'      => 'fabricator_form',
            'posts_per_page' => 100,
            // Explicit list, not 'any': 'any' silently omits trash/auto-draft, which would survive uninstall.
            'post_status'    => ['publish', 'pending', 'draft', 'future', 'private', 'trash', 'auto-draft', 'inherit'],
            'fields'         => 'ids',
            ]
        );
        if (empty($fabricator_forms)) {
            break;
        }
        foreach ($fabricator_forms as $fabricator_form_id) {
            wp_delete_post($fabricator_form_id, true);
        }
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
        'fabricator_form_selects',
        // Seal key data — deleted on uninstall, NOT on reset.
        'fabricator_forms_seal_key',
        'fabricator_forms_seal_key_history',
        'fabricator_forms_seal_key_damaged',
        'fabricator_forms_seal_encryption',
        'fabricator_forms_seal_setup_done',
        'fabricator_forms_access',
        'fabricator_forms_trusted_proxies',
        'fabricator_verifier_sweep_due',
    ];
    foreach ($fabricator_options as $fabricator_option) {
        delete_option($fabricator_option);
    }

    // Transients, not options: fabricator_forms_seal_key_pending_download holds a PLAINTEXT seal key that delete_option() never touched.
    $fabricator_transients = [
        'fabricator_forms_seal_key_pending_download',
        'fabricator_host_memory_bytes',
        'fabricator_pdf_dirs_ready',
        'fabricator_pdf_template_fingerprints',
        'fabricator_unknown_server_logged',
        'fabricator_gdpr_no_policy_logged',
        'fabricator_uploads_probe',
    ];
    foreach ($fabricator_transients as $fabricator_transient) {
        delete_transient($fabricator_transient);
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

    /* Remove the advisory admin edit-locks (fabricator_lock_<screen>, written by Utils/AdminLock.php).
       Plain options with no expiry, so nothing else would ever clear them. */
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see fabricator_rl_ cleanup comment above
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('fabricator_lock_') . '%'
        )
    );
    wp_cache_delete('alloptions', 'options');

    // No delete_transient() equivalent for a prefix — DELETE both value and _transient_timeout_ rows directly.
    // fabricator_pdf_ also covers the fabricator_pdf_layout_result_<user> save outcomes; fabricator_settings_result_ is its
    // settings-page counterpart (both written by the Post/Redirect/Get save handlers).
    foreach (['fabricator_pdf_', 'fabricator_vp_', 'fabricator_setup_master_key_', 'fabricator_settings_result_', 'fabricator_vbatch_', 'fabricator_vpending_'] as $fabricator_prefix) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see fabricator_rl_ cleanup comment above
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . $wpdb->esc_like($fabricator_prefix) . '%',
                '_transient_timeout_' . $wpdb->esc_like($fabricator_prefix) . '%'
            )
        );
    }

    // Under a persistent object cache these transients never reach wp_options, so the DELETE above finds nothing. The
    // per-admin setup hash has a derivable key and is removed here; the fabricator_pdf_ and fabricator_vp_ keys are random
    // tokens that cannot be enumerated; like the per-user fabricator_vpending_ lists, they expire within 10 minutes.
    if (wp_using_ext_object_cache()) {
        foreach (get_users(['capability' => 'manage_options', 'fields' => 'ID']) as $fabricator_admin_id) {
            delete_transient('fabricator_setup_master_key_' . (int) $fabricator_admin_id);
        }
    }

    // Targeted invalidation, not wp_cache_flush(): flushing the whole object cache would evict every other plugin's entries too.
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');

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
                // getPathname(), not getRealPath(): getRealPath() resolves symlinks, so a link planted in this tree made
                // uninstall delete its target outside the plugin's directory. A link is removed as the link itself
                // (the iterator does not descend into linked directories).
                $fabricator_path = $fabricator_file->getPathname();
                if ($fabricator_file->isLink() || !$fabricator_file->isDir()) {
                    wp_delete_file($fabricator_path);
                    $fabricator_ok = !file_exists($fabricator_path) && !is_link($fabricator_path);
                } else {
                    $fabricator_ok = $wp_filesystem->rmdir($fabricator_path);
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
}

// Run it once per site on a network; get_sites() is batched so a large network doesn't load every blog object at once.
if (is_multisite()) {
    $fabricator_offset = 0;
    do {
        $fabricator_site_ids = get_sites(
            [
            'fields' => 'ids',
            'number' => 200,
            'offset' => $fabricator_offset,
            ]
        );
        foreach ($fabricator_site_ids as $fabricator_site_id) {
            switch_to_blog((int) $fabricator_site_id);
            fabricator_uninstall_current_site();
            restore_current_blog();
        }
        $fabricator_offset += 200;
    } while (count($fabricator_site_ids) === 200);
} else {
    fabricator_uninstall_current_site();
}

// Once, outside the per-site loop: user meta lives in the network-wide usermeta table, so running this per site
// repeated the same delete for every site. The unprotected-uploads notice's 30-day dismissal, stored per user.
delete_metadata('user', 0, 'fabricator_uploads_notice_dismissed', '', true);
