<?php

/**
 * Atomic, exactly-once claim primitive.
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

namespace FabricatorForms\Utils;

defined('ABSPATH') || exit;

// Atomic, exactly-once claim backed by wp_options' UNIQUE KEY (INSERT IGNORE) — closes the TOCTOU
// window a get_transient()/set_transient() read-then-write would leave open.
class SingleUseToken
{
    /**
     * Atomically attempts to claim $key. Returns true only for the first caller to claim it.
     *
     * @param string $key            Unique claim identifier, already hashed/sanitized by the caller.
     * @param int    $ttl_seconds    How long the claim is held before the sweep considers it expired.
     * @return bool True if this call created the claim, false if it was already held.
     */
    public static function claim(string $key, int $ttl_seconds): bool
    {
        global $wpdb;

        $opt    = 'fabricator_su_' . $key;
        $expiry = time() + $ttl_seconds;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic claim requires a direct query; option is never autoloaded/cached via get_option().
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $opt,
                (string) $expiry
            )
        );
        // Direct query bypasses WP's cache invalidation.
        wp_cache_delete($opt, 'options');

        return (int) $wpdb->rows_affected === 1;
    }

    /**
     * Releases a previously-claimed key so a legitimate retry isn't permanently blocked.
     *
     * @param string $key Same identifier passed to claim().
     * @return void
     */
    public static function release(string $key): void
    {
        global $wpdb;

        $opt = 'fabricator_su_' . $key;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see claim() above; this option is never autoloaded/cached via get_option().
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $opt));
        wp_cache_delete($opt, 'options');
    }

    // WP-Cron callback (hourly): deletes any fabricator_su_* option row whose TTL has expired.
    public static function cronSweepExpired(): void
    {
        global $wpdb;

        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk sweep of this class's own private fabricator_su_* option rows (never read via get_option()/cached); WP-Cron cleanup, not request-path caching concern.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d",
                $wpdb->esc_like('fabricator_su_') . '%',
                $now
            )
        );
        wp_cache_delete('alloptions', 'options');
    }
}
