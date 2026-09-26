<?php

/**
 * Option-backed "who's editing this" advisory lock for singleton admin screens.
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
 * Reimplements WP core's post-lock pattern against wp_options for singleton screens with no post ID.
 */
class AdminLock
{
    /**
     * Returns the user ID holding a fresh lock on $key, or false if unlocked/expired/self-held.
     *
     * @param string $key Lock identifier — a fixed, hardcoded string per admin screen, never request input.
     * @return int|false
     */
    public static function check(string $key): int|false
    {
        $raw = get_option('fabricator_lock_' . $key, '');
        if ($raw === '' || !str_contains($raw, ':')) {
            return false;
        }
        [$time, $user] = explode(':', $raw, 2);
        $time   = (int) $time;
        $user   = (int) $user;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core's own filter, reused so existing core-lock-window customization applies here too.
        $window = (int) apply_filters('wp_check_post_lock_window', 150);
        if ($time && $user && $time > time() - $window && get_current_user_id() !== $user) {
            return $user;
        }
        return false;
    }

    /**
     * Marks $key as being edited by the current user, refreshing the timestamp.
     *
     * @param string $key Same identifier passed to check().
     * @return void
     */
    public static function acquire(string $key): void
    {
        $uid = get_current_user_id();
        if (!$uid) {
            return;
        }
        update_option('fabricator_lock_' . $key, time() . ':' . $uid, false);
    }

    /**
     * Releases $key, but only if currently held by $user_id.
     *
     * @param string $key     Same identifier passed to check()/acquire().
     * @param int    $user_id User ID the caller has already authenticated as the requester.
     * @return void
     */
    public static function release(string $key, int $user_id): void
    {
        $raw = get_option('fabricator_lock_' . $key, '');
        if ($raw === '' || !str_contains($raw, ':')) {
            return;
        }
        [, $owner] = explode(':', $raw, 2);
        if ((int) $owner === $user_id) {
            delete_option('fabricator_lock_' . $key);
        }
    }
}
