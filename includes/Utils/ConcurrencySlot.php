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
 * @version   1.0.8
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Utils;

defined('ABSPATH') || exit;

/**
 * Byte-weighted admission control for expensive work, shared via wp_options (rows "<expiry>|<bytes>").
 */
class ConcurrencySlot
{
    /**
     * Reserves $bytes in $bucket: insert, then check against rows with a lower option_id, which orders simultaneous
     * callers strictly.
     *
     * @param string $bucket        Bucket name (hardcoded literal per caller, never request input).
     * @param int    $bytes         Estimated peak memory for this job (see MemoryBudget).
     * @param int    $budget_bytes  Total bytes this bucket may have reserved at once.
     * @param int    $ttl_seconds   How long a reservation is honored before it's abandoned.
     * @param int    $max_holders   Hard cap on simultaneous holders, however small their jobs.
     * @return string|false Token to pass to release(), or false when the budget is exhausted.
     */
    public static function reserve(
        string $bucket,
        int $bytes,
        int $budget_bytes,
        int $ttl_seconds,
        int $max_holders = 32
    ): string|false {
        global $wpdb;

        $prefix = 'fabricator_cs_' . $bucket . '_';
        $now    = time();
        $bytes  = max(1, $bytes);

        // A job that cannot fit the empty budget will never be admitted; say so without a DB write.
        if ($bytes > $budget_bytes) {
            return false;
        }

        $token  = bin2hex(random_bytes(12));
        $opt    = $prefix . $token;
        $expiry = $now + $ttl_seconds;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see SingleUseToken::claim() for the same pattern; never cached.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $opt,
                $expiry . '|' . $bytes
            )
        );
        wp_cache_delete($opt, 'options');
        // $token is freshly random per call, so IGNORE only ever fires on a genuine collision
        // (astronomically unlikely) — rows_affected === 1 confirms the row is really ours.
        if ((int) $wpdb->rows_affected !== 1) {
            return false;
        }
        $my_row_id = (int) $wpdb->insert_id;

        /* Sum only unexpired rows inserted BEFORE ours. */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own private rows, never cached; a fresh read every call is the whole point.
        $prior = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED)), 0) AS reserved_bytes,
                    COUNT(*) AS holders
                 FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND option_id < %d
                   AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) > %d",
                $wpdb->esc_like($prefix) . '%',
                $my_row_id,
                $now
            ),
            ARRAY_A
        );

        $prior_bytes   = (int) ($prior['reserved_bytes'] ?? 0);
        $prior_holders = (int) ($prior['holders'] ?? 0);

        if (($prior_bytes + $bytes) > $budget_bytes || $prior_holders >= $max_holders) {
            self::release($bucket, $token);
            return false;
        }

        return $token;
    }

    /**
     * Bytes currently reserved in $bucket, for diagnostics and admin-facing messages.
     *
     * @param string $bucket Same bucket name passed to reserve().
     * @return int Sum of unexpired reservations, in bytes.
     */
    public static function reservedBytes(string $bucket): int
    {
        global $wpdb;

        $prefix = 'fabricator_cs_' . $bucket . '_';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own private rows, never cached; see reserve().
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED)), 0)
                 FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) > %d",
                $wpdb->esc_like($prefix) . '%',
                time()
            )
        );
    }

    /**
     * Releases a previously-claimed slot.
     *
     * @param string $bucket Same bucket name passed to reserve().
     * @param string $token  Token returned by reserve().
     * @return void
     */
    public static function release(string $bucket, string $token): void
    {
        global $wpdb;

        $opt = 'fabricator_cs_' . $bucket . '_' . $token;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see reserve() above; never cached.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $opt));
        wp_cache_delete($opt, 'options');
    }

    // WP-Cron callback (hourly): deletes expired rows for holders that died before reaching release().
    public static function cronSweepExpired(): void
    {
        global $wpdb;

        $now = time();
        /* SUBSTRING_INDEX(value, '|', 1) is the expiry of an "<expiry>|<bytes>" row. */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk sweep of own private rows; WP-Cron cleanup, not request-path.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d",
                $wpdb->esc_like('fabricator_cs_') . '%',
                $now
            )
        );
        wp_cache_delete('alloptions', 'options');
    }
}
