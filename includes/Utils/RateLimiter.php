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

// Atomic, window-based rate-limit counter backed directly by wp_options. Count and window-expiry are stored
// together as one "count|expiry" value, and the reset-or-increment decision happens inside one atomic INSERT
// ... ON DUPLICATE KEY UPDATE under InnoDB's per-row lock — avoiding the TOCTOU race a separate
// read-then-write (or two separate atomic statements) would have.
class RateLimiter
{
    /**
     * Atomically increments the counter for $key and returns the new count. The window resets automatically
     * once $window_seconds have elapsed since the counter was first created for this key.
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

        // Single atomic upsert: inserts the first-ever row for this key, or — on the
        // existing row — atomically resets (if its stored expiry has passed) or
        // increments (otherwise), all within one statement under one row lock, so no
        // concurrent request can observe or overwrite an intermediate state.
        //
        // The count is read back below via a plain SELECT rather than
        // SELECT LAST_INSERT_ID(), which an earlier version used — that relies on
        // both statements landing on the same MySQL session, which isn't guaranteed
        // behind a connection pooler and caused sporadically wrong counts.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic upsert cannot be expressed via the Options/Transients API without losing the single-statement atomicity this rate limiter depends on (see class docblock); this option is never autoloaded/cached via get_option().
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
        // The direct query above bypasses WP's own cache invalidation, so the object
        // cache (if any, e.g. Redis/Memcached) must be explicitly told to drop its
        // stale copy or every subsequent get_option() on this key would return it.
        wp_cache_delete($opt, 'options');

        // Same direct-query rationale as the upsert above: this option is never
        // autoloaded/cached via get_option(), it's a private counter row this class owns exclusively.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt));
        if ($raw === null || !str_contains((string) $raw, '|')) {
            // Shouldn't happen immediately after the upsert above commits, but fail
            // toward "treat as first submission" rather than crash on a malformed read.
            return 1;
        }
        return (int) substr((string) $raw, 0, strpos((string) $raw, '|'));
    }

    /**
     * Read-only peek at how many seconds remain until $key's window resets. Does not increment or otherwise
     * mutate the counter — callers use this purely to explain a rate-limit rejection (e.g. "try again in N
     * seconds") after increment() has already returned an over-the-cap count for the same request.
     *
     * @param string $key Same bucket identifier passed to increment().
     * @return int Seconds until reset, or 0 if the bucket doesn't exist or has already
     *             expired (i.e. the next increment() call would reset it).
     */
    public static function secondsUntilReset(string $key): int
    {
        global $wpdb;

        $opt = 'fabricator_rl_' . $key;
        // Same direct-query rationale as increment() above: this option is never
        // autoloaded/cached via get_option(), it's a private counter row this class owns exclusively.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt));
        if ($raw === null || !str_contains((string) $raw, '|')) {
            return 0;
        }

        $expiry = (int) substr((string) $raw, (int) strrpos((string) $raw, '|') + 1);
        return max(0, $expiry - time());
    }

    // WP-Cron callback (hourly): deletes any fabricator_rl_* option row whose window has expired. Each distinct
    // rate-limit bucket (IP+form combination) leaves a permanent wp_options row once written, since
    // increment() only ever resets/increments a row in place and never deletes it — without this sweep,
    // buckets accumulate forever.
    public static function cronSweepExpired(): void
    {
        global $wpdb;

        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk sweep of this class's own private fabricator_rl_* option rows (never read via get_option()/cached); WP-Cron cleanup, not request-path caching concern.
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
