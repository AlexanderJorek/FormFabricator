<?php

/**
 * Atomic, self-healing concurrency cap for expensive server-side work.
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

namespace ForgeForms\Utils;

defined('ABSPATH') || exit;

/**
 * Caps how many requests in a bucket run at once, across all users — unlike RateLimiter's
 * per-key window counter. Each claim is its own row under a random token, via INSERT IGNORE
 * (same proven primitive as SingleUseToken). The room-check (SELECT COUNT) isn't part of the
 * same atomic statement as the insert, so a narrow race can slightly overshoot the cap under a
 * true burst — acceptable for a soft self-DoS mitigation. TTL self-heals a claim whose holder
 * died without calling release(); callers should also release on shutdown for the common path.
 */
class ConcurrencySlot
{
    /**
     * Attempts to claim a slot in $bucket, while fewer than $max_concurrent are currently held.
     * Returns the claim's identifying token (pass to release()) on success, or false if the bucket
     * is currently full.
     *
     * @param string $bucket         Bucket name (hardcoded literal per caller, never request input).
     * @param int    $max_concurrent Maximum claims honored at once in this bucket.
     * @param int    $ttl_seconds    How long a claim is honored before it's considered abandoned.
     * @return string|false
     */
    public static function acquire(string $bucket, int $max_concurrent, int $ttl_seconds): string|false
    {
        global $wpdb;

        $prefix = 'forge_cs_' . $bucket . '_';
        $now    = time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- counts this class's own private forge_cs_* rows; never autoloaded/cached via get_option(), and a fresh read is required every call (this is the whole point of the check).
        $active = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) > %d",
                $wpdb->esc_like($prefix) . '%',
                $now
            )
        );
        if ($active >= $max_concurrent) {
            return false;
        }

        $token  = bin2hex(random_bytes(12));
        $opt    = $prefix . $token;
        $expiry = $now + $ttl_seconds;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see SingleUseToken::claim() for the same pattern; this option is never autoloaded/cached via get_option().
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $opt,
                (string) $expiry
            )
        );
        wp_cache_delete($opt, 'options');
        // $token is freshly random per call, so IGNORE only ever fires on a genuine collision
        // (astronomically unlikely) — rows_affected === 1 confirms the row is really ours.
        if ((int) $wpdb->rows_affected !== 1) {
            return false;
        }
        return $token;
    }

    /**
     * Releases a previously-claimed slot.
     *
     * @param string $bucket Same bucket name passed to acquire().
     * @param string $token  Token returned by acquire().
     * @return void
     */
    public static function release(string $bucket, string $token): void
    {
        global $wpdb;

        $opt = 'forge_cs_' . $bucket . '_' . $token;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see acquire() above; this option is never autoloaded/cached via get_option().
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $opt));
        wp_cache_delete($opt, 'options');
    }

    // WP-Cron callback (hourly): deletes any forge_cs_* option row whose TTL has expired, for the
    // rare case a holder died without ever reaching its release() (crash, OOM-killed worker,
    // request that bypassed the shutdown hook entirely).
    public static function cronSweepExpired(): void
    {
        global $wpdb;

        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk sweep of this class's own private forge_cs_* option rows (never read via get_option()/cached); WP-Cron cleanup, not request-path caching concern.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d",
                $wpdb->esc_like('forge_cs_') . '%',
                $now
            )
        );
        wp_cache_delete('alloptions', 'options');
    }
}
