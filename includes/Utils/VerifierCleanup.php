<?php

/**
 * Deletes the PDF copies uploaded for verification, and the images extracted from them, once they are unused.
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
 * Removes stored verification copies as soon as nothing needs them.
 *
 * A finished check deletes its own copy at once (Verificationpage::discardCheckedCopy()). This class removes the rest
 * UNUSED_TTL after their last use: copies whose check never ran or never finished. WP-Cron alone runs late on quiet
 * sites and not at all under DISABLE_WP_CRON without a server cron, so the same sweep also runs on the first request of
 * any kind once a copy is due. That trigger is an autoloaded timestamp, so a request with nothing due costs no query.
 * Lives in Utils, not in the admin-only Verificationpage, because front-end requests must be able to run it.
 */
class VerifierCleanup
{
    /**
     * Seconds a stored copy may stay unused before it is deleted.
     *
     * @var int
     */
    public const UNUSED_TTL = 600;

    /**
     * One-off WP-Cron hook that runs sweep() when the next copy is due.
     *
     * @var string
     */
    public const HOOK = 'fabricator_verifier_sweep_expired';

    /**
     * Per-user transient listing the serving tokens of that user's copies that still wait for their check.
     *
     * @var string
     */
    public const PENDING_PREFIX = 'fabricator_vpending_';

    /**
     * Autoloaded option: when the next stored copy is due (Unix time), or 0 when none is stored.
     *
     * @var string
     */
    private const DUE_OPTION = 'fabricator_verifier_sweep_due';

    /**
     * Minimum seconds between two refreshes of one user's waiting copies; the page polls progress every few seconds.
     *
     * @var int
     */
    private const REFRESH_INTERVAL = 60;

    /**
     * Folders below the protected PDF folder that hold verification copies and extracted images.
     *
     * @var string[]
     */
    private const DIRS = ['/verfiles', '/verimages'];

    /**
     * Guard files SecureDir::harden() writes into those folders (.htaccess is skipped by glob() already).
     *
     * @var string[]
     */
    private const GUARD_FILES = ['index.php', 'web.config'];

    /**
     * Hooked on init for every request: sweeps once a stored copy is due.
     *
     * @return void
     */
    public static function maybeSweep(): void
    {
        $due = get_option(self::DUE_OPTION, null);
        if ($due === null) {
            // Created once and autoloaded, so later requests find it among the options already in memory.
            add_option(self::DUE_OPTION, 0, '', true);
            return;
        }
        if ((int) $due > 0 && (int) $due <= time()) {
            self::sweep();
        }
    }

    /**
     * Deletes every copy and extracted image unused for UNUSED_TTL, then arranges the sweep for the oldest one left.
     * Also the hourly WP-Cron backstop.
     *
     * @return void
     */
    public static function sweep(): void
    {
        $now      = time();
        $next_due = 0;
        $base     = wp_upload_dir()['basedir'] . '/fabricator-secure-pdf';
        foreach (self::DIRS as $sub) {
            foreach ((glob($base . $sub . '/*') ?: []) as $file) {
                if (!is_file($file) || in_array(basename($file), self::GUARD_FILES, true)) {
                    continue;
                }
                // A concurrent request may delete the file between is_file() and here; false is then the right answer.
                $mtime = Cast::withoutWarnings(static fn() => filemtime($file));
                if ($mtime === false) {
                    continue;
                }
                if ($now - $mtime < self::UNUSED_TTL) {
                    $due      = $mtime + self::UNUSED_TTL;
                    $next_due = $next_due === 0 ? $due : min($next_due, $due);
                    continue;
                }
                wp_delete_file($file);
                if (file_exists($file)) {
                    \FabricatorForms\fabricator_log("FabricatorForms VerifierCleanup: could not remove {$file}");
                }
            }
        }
        self::setDue($next_due);
    }

    /**
     * Records copies just stored for the current user and makes sure a sweep follows when they expire.
     *
     * @param string[] $tokens Serving tokens of the stored copies.
     * @return void
     */
    public static function trackPending(array $tokens): void
    {
        if ($tokens === []) {
            return;
        }
        $key = self::PENDING_PREFIX . get_current_user_id();
        // Locked, like refreshPending(): an upload in another tab rewrites the same list.
        OptionMutex::run(
            $key,
            static function () use ($key, $tokens): void {
                $pending = get_transient($key);
                $known   = is_array($pending) && is_array($pending['tokens'] ?? null) ? $pending['tokens'] : [];
                set_transient(
                    $key,
                    ['tokens' => array_values(array_unique(array_merge($known, $tokens))), 'refreshed' => time()],
                    self::UNUSED_TTL
                );
            }
        );
        self::sweepBy(time() + self::UNUSED_TTL);
    }

    /**
     * Restarts the waiting time of the current user's unchecked copies. The verification page's requests (download,
     * check, progress) call this, so a batch stays available while the page works through it and expires UNUSED_TTL
     * after the page stops asking.
     *
     * @return void
     */
    public static function refreshPending(): void
    {
        $key   = self::PENDING_PREFIX . get_current_user_id();
        $stale = static function () use ($key): ?array {
            $pending = get_transient($key);
            return is_array($pending) && (int) ($pending['refreshed'] ?? 0) <= time() - self::REFRESH_INTERVAL ? $pending : null;
        };
        // Checked before taking the lock too, since most calls come within the interval and have nothing to do.
        if ($stale() === null) {
            return;
        }
        // Locked, since trackPending() in another tab may add tokens meanwhile; read again inside.
        $kept = OptionMutex::run(
            $key,
            static function () use ($key, $stale): bool {
                $pending = $stale();
                if ($pending === null) {
                    return false;
                }
                $alive = [];
                foreach ((array) ($pending['tokens'] ?? []) as $token) {
                    $entry = is_string($token) ? get_transient('fabricator_pdf_' . $token) : false;
                    $path  = is_array($entry) ? ($entry['path'] ?? null) : null;
                    if (!is_string($path) || !is_file($path)) {
                        continue; // already checked, swept or expired
                    }
                    // The sweep goes by modification time, so this is what keeps the copy.
                    // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem,WordPress.WP.AlternativeFunctions.file_system_operations_touch -- $path comes from this plugin's own serving transient and points into verfiles/; WP_Filesystem::touch() may go through FTP, which this per-request refresh must not.
                    Cast::withoutWarnings(static fn() => touch($path));
                    set_transient('fabricator_pdf_' . $token, $entry, self::UNUSED_TTL);
                    $alive[] = $token;
                }
                if ($alive === []) {
                    delete_transient($key);
                    return false;
                }
                set_transient($key, ['tokens' => $alive, 'refreshed' => time()], self::UNUSED_TTL);
                return true;
            }
        );
        if ($kept) {
            self::sweepBy(time() + self::UNUSED_TTL);
        }
    }

    /**
     * Makes sure a sweep runs no later than $due: moves the due time and the one-off cron event earlier when needed.
     *
     * @param int $due Unix time.
     * @return void
     */
    private static function sweepBy(int $due): void
    {
        $current = (int) get_option(self::DUE_OPTION, 0);
        if ($current === 0 || $current > $due) {
            self::setDue($due);
        }
    }

    /**
     * Stores the next due time and schedules the matching one-off cron event.
     *
     * An early due time is harmless: that sweep deletes nothing and moves the time to the oldest copy left.
     *
     * @param int $due Unix time, or 0 when nothing is stored.
     * @return void
     */
    private static function setDue(int $due): void
    {
        if ((int) get_option(self::DUE_OPTION, 0) !== $due) {
            update_option(self::DUE_OPTION, $due, true);
        }
        if ($due === 0) {
            return;
        }
        // With a real server cron (DISABLE_WP_CRON plus a system job), copies go on time even when nobody visits.
        $next = wp_next_scheduled(self::HOOK);
        if ($next !== false && $next <= $due) {
            return;
        }
        if ($next !== false) {
            wp_unschedule_event($next, self::HOOK);
        }
        wp_schedule_single_event($due, self::HOOK);
    }
}
