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
 * @version   1.0.7
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
 * Serializes concurrent read-modify-write cycles on one option, so two requests changing different keys of the same
 * array option can't overwrite each other's change. Backed by wp_options' unique option_name (INSERT IGNORE), like
 * SingleUseToken::claim(), so it needs no extension or special database privilege.
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
     * If the lock can't be taken within $wait_ms, $fn runs anyway and the wait is logged: an unlocked save is what every
     * save did before, and refusing it would lose the admin's change instead of risking someone else's. That trade holds
     * only while the worst case of racing is an edit to redo; pass $fail_closed where a lost write can't be recovered.
     *
     * @param string   $option      Option name the callback reads and writes.
     * @param callable $fn          Zero-argument callback doing the read-modify-write.
     * @param int      $wait_ms     How long to wait for a concurrent holder.
     * @param bool     $fail_closed Refuse instead of writing unlocked, throwing a RuntimeException that names the option.
     *                              For writes whose loss is permanent — a retired seal key dropped from the history
     *                              leaves every PDF it signed unverifiable, with no way back.
     * @return mixed Whatever $fn returns.
     * @throws \RuntimeException When $fail_closed is set and the lock could not be taken.
     */
    public static function run(string $option, callable $fn, int $wait_ms = 3000, bool $fail_closed = false): mixed
    {
        global $wpdb;
        $lock     = self::PREFIX . $option;
        $deadline = microtime(true) + ($wait_ms / 1000);
        $acquired = false;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock row; see class docblock.
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $lock,
                    (string) (time() + self::STALE_AFTER)
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
        // Every cache the value can sit in, not just one: an autoloaded option lives in the 'alloptions' bundle, and a
        // transient under its own name in the 'transient' group and as _transient_<name> among the options. Clearing
        // only 'options' left VerifierCleanup reading a stale pending list inside the lock it had just taken.
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('_transient_' . $option, 'options');
        wp_cache_delete('_transient_timeout_' . $option, 'options');
        wp_cache_delete($option, 'transient');
        try {
            return $fn();
        } finally {
            if ($acquired) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the lock row taken above.
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $lock));
            }
        }
    }
}
