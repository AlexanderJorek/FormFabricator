<?php

/**
 * A short-lived lock around a read-modify-write of one option.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.9
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
 * Serializes read-modify-write cycles on one option, backed by wp_options' unique option_name (INSERT IGNORE), so it
 * needs no extension or special database privilege.
 */
class OptionMutex
{
    /**
     * Lock rows are "fabricator_lock_opt_<name>"; uninstall.php removes every fabricator_lock_ row.
     *
     * @var string
     */
    private const PREFIX = 'fabricator_lock_opt_';

    /**
     * Seconds after which a lock whose holder died without releasing it may be broken.
     *
     * @var int
     */
    private const STALE_AFTER = 30;

    /**
     * Runs $fn while holding the lock for $option, with that option's cache cleared first so $fn reads the stored value.
     *
     * If the lock can't be taken within $wait_ms, $fn runs anyway and the wait is logged: refusing would lose the
     * admin's change for sure. Pass $fail_closed where a lost write can't be recovered.
     *
     * @param string   $option      Option name the callback reads and writes.
     * @param callable $fn          Zero-argument callback doing the read-modify-write.
     * @param int      $wait_ms     How long to wait for a concurrent holder.
     * @param bool     $fail_closed Throw a RuntimeException instead of writing unlocked (such as for seal keys).
     * @return mixed Whatever $fn returns.
     * @throws \RuntimeException When $fail_closed is set and the lock could not be taken.
     */
    public static function run(string $option, callable $fn, int $wait_ms = 3000, bool $fail_closed = false): mixed
    {
        global $wpdb;
        $lock     = self::PREFIX . $option;
        $deadline = microtime(true) + ($wait_ms / 1000);
        $acquired = false;
        // "<expiry>:<owner>": the stale check reads the leading number, and the owner token lets a release remove only
        // its own row, even after a slow holder's lock was broken.
        $value = '';
        do {
            $value = (time() + self::STALE_AFTER) . ':' . bin2hex(random_bytes(8));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock row; see class docblock.
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $lock,
                    $value
                )
            );
            if ((int) $wpdb->rows_affected === 1) {
                $acquired = true;
                break;
            }
            // A holder that died without releasing: its row carries an expiry, so a stale lock is broken, not waited on forever.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
                    $lock,
                    time()
                )
            );
            usleep(50000);
        } while (microtime(true) < $deadline);

        if (!$acquired) {
            if ($fail_closed) {
                \FabricatorForms\fabricator_log('FabricatorForms OptionMutex: could not lock ' . $option . ' within ' . $wait_ms . ' ms; refused.');
                throw new \RuntimeException('Lock busy: another request is changing this option.');
            }
            \FabricatorForms\fabricator_log('FabricatorForms OptionMutex: could not lock ' . $option . ' within ' . $wait_ms . ' ms; writing without the lock.');
        }
        // Every cache the value can sit in: 'options', 'alloptions', and for a transient its own group and option.
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('_transient_' . $option, 'options');
        wp_cache_delete('_transient_timeout_' . $option, 'options');
        wp_cache_delete($option, 'transient');
        try {
            return $fn();
        } finally {
            if ($acquired) {
                // Only while the row is still this holder's: after a stale break it belongs to the next holder.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the lock row taken above.
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, $value));
            }
        }
    }
}
