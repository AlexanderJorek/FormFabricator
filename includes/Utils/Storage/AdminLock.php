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
 * @version   1.1.1
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Utils\Storage;

defined('ABSPATH') || exit;

use FabricatorForms\Capabilities;

/**
 * Reimplements WP core's post-lock pattern against wp_options for singleton screens with no post ID.
 *
 * A screen claims its lock when it renders and on every heartbeat (assets/js/admin-lock.js), and releases it by a
 * beacon when the tab closes. The lock only tells the second administrator who else is editing; each screen's save
 * refuses a stale page by its own snapshot check.
 */
class AdminLock
{
    /**
     * The screens that hold such a lock: lock key => the capability (Capabilities::userCan()) that may edit the screen.
     *
     * @var array<string, string>
     */
    private const SCREENS = [
        'settings'   => 'settings',
        'pdf_layout' => 'edit_pdf_layout',
    ];

    /**
     * Registers the heartbeat and unlock handlers of every locked screen.
     *
     * @return void
     */
    public static function init(): void
    {
        add_filter('heartbeat_received', [self::class, 'heartbeatReceived'], 10, 2);
        add_action('wp_ajax_fabricator_forms_unlock_settings', [self::class, 'ajaxUnlock']);
        add_action('wp_ajax_fabricator_forms_unlock_pdf_layout', [self::class, 'ajaxUnlock']);
    }

    /**
     * Takes or refreshes the lock on $key for the current user, unless someone else holds it.
     *
     * @param string $key Lock identifier, one of SCREENS.
     * @return string The other editor's display name, or '' when the current user now holds the lock.
     */
    public static function claim(string $key): string
    {
        $owner = self::check($key);
        if (!$owner) {
            self::acquire($key);
            return '';
        }
        $user = get_userdata($owner);
        return $user ? $user->display_name : __('another user', 'formfabricator');
    }

    /**
     * Hands admin-lock.js its screen, the unlock nonce and the conflict notice. Called while the screen renders.
     *
     * @param string $key Lock identifier, one of SCREENS.
     * @return void
     */
    public static function localize(string $key): void
    {
        wp_enqueue_script('heartbeat');
        wp_localize_script(
            'fabricator-forms-admin-lock',
            'FabricatorAdminLock',
            [
                'screen'  => $key,
                'nonce'   => wp_create_nonce('fabricator_forms_admin_nonce'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'i18n'    => [
                    // translators: %s: display name of the user currently editing this page.
                    'lockConflict' => __('Currently being edited by %s. Saving may conflict.', 'formfabricator'),
                ],
            ]
        );
    }

    /**
     * Refreshes the lock of each screen the heartbeat names (fabricator_<key>_lock), or reports who holds it
     * (fabricator_<key>_lock_conflict).
     *
     * @param array $response Heartbeat response payload being built.
     * @param array $data     Data sent by the client in this heartbeat tick.
     * @return array Modified heartbeat response.
     */
    public static function heartbeatReceived(array $response, array $data): array
    {
        foreach (self::SCREENS as $key => $capability) {
            if (empty($data['fabricator_' . $key . '_lock']) || !Capabilities::userCan($capability)) {
                continue;
            }
            $other = self::claim($key);
            if ($other !== '') {
                $response['fabricator_' . $key . '_lock_conflict'] = $other;
            }
        }
        return $response;
    }

    /**
     * Releases the current user's lock on a screen, fired via sendBeacon() on unload. The action's name says which
     * screen (fabricator_forms_unlock_<key>); the request carries only the nonce.
     *
     * @return void
     */
    public static function ajaxUnlock(): void
    {
        $key = substr((string) current_action(), strlen('wp_ajax_fabricator_forms_unlock_'));
        if (!isset(self::SCREENS[$key])) {
            wp_send_json_error(null, 400);
        }
        \FabricatorForms\Utils\Security\AjaxGuard::capability(self::SCREENS[$key]);
        check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');
        self::release($key, get_current_user_id());
        wp_send_json_success();
    }

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
