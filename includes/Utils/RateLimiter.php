<?php

/**
 * Atomic, window-based rate-limit counter.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.6
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Utils;

defined('ABSPATH') || exit;

// Atomic window-based rate-limit counter backed by wp_options, avoiding the TOCTOU race a read-then-write would have.
class RateLimiter
{
    /**
     * Atomically increments the counter for $key, returning the new count; the window auto-resets.
     *
     * @param string $key            Unique rate-limit bucket identifier (already hashed/sanitized by
     *                                the caller — used verbatim as part of an option name).
     * @param int    $window_seconds Window duration in seconds.
     * @return int New count after incrementing.
     */
    public static function increment(string $key, int $window_seconds): int
    {
        global $wpdb;

        $opt        = 'fabricator_rl_' . $key;
        $now        = time();
        $new_expiry = $now + $window_seconds;

        // Read back via SELECT rather than LAST_INSERT_ID(), which broke behind a connection pooler.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic upsert can't be expressed via Options/Transients API without losing atomicity; never cached.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, CONCAT(1, '|', %d), 'no')
                 ON DUPLICATE KEY UPDATE option_value = IF(
                     CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) <= %d,
                     CONCAT(1, '|', %d),
                     CONCAT(
                         CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1,
                         '|',
                         SUBSTRING_INDEX(option_value, '|', -1)
                     )
                 )",
                $opt,
                $new_expiry,
                $now,
                $new_expiry
            )
        );
        // The direct query bypasses WP's cache invalidation, so the object cache must be told to drop its stale copy.
        wp_cache_delete($opt, 'options');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- same direct-query rationale as the upsert above: a private counter row this class owns.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt));
        if ($raw === null || !str_contains((string) $raw, '|')) {
            // Fail toward "treat as first submission" rather than crash on a malformed read.
            return 1;
        }
        return (int) substr((string) $raw, 0, strpos((string) $raw, '|'));
    }

    /**
     * Read-only peek at seconds remaining until $key's window resets; does not mutate the counter.
     *
     * @param string $key Same bucket identifier passed to increment().
     * @return int Seconds until reset, or 0 if the bucket doesn't exist or has already
     *             expired (i.e. the next increment() call would reset it).
     */
    public static function secondsUntilReset(string $key): int
    {
        global $wpdb;

        $opt = 'fabricator_rl_' . $key;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- same rationale as increment() above: a private counter row this class owns.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt));
        if ($raw === null || !str_contains((string) $raw, '|')) {
            return 0;
        }

        $expiry = (int) substr((string) $raw, (int) strrpos((string) $raw, '|') + 1);
        return max(0, $expiry - time());
    }

    // WP-Cron callback (hourly): deletes expired fabricator_rl_* rows, since increment() never deletes them itself.
    public static function cronSweepExpired(): void
    {
        global $wpdb;

        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk sweep of own private rows; WP-Cron cleanup, not request-path.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) <= %d",
                $wpdb->esc_like('fabricator_rl_') . '%',
                $now
            )
        );
        wp_cache_delete('alloptions', 'options');
    }
}
