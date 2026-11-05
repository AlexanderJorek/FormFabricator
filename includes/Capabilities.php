<?php

/**
 * Who may use which part of the plugin: its permissions, and the capabilities WordPress checks for them.
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

namespace FabricatorForms;

defined('ABSPATH') || exit;

/**
 * The plugin's permissions (view_forms, edit_forms, edit_pdf_layout, use_verifier, settings): administrators hold every
 * one, and the access matrix (Admin\AccessSettings) grants them to roles and users. WordPress enforces them on the admin
 * screens through the "fabricator_access_<permission>" capabilities grantAccessCaps() derives.
 */
class Capabilities
{
    /**
     * Prefix of the capabilities grantAccessCaps() derives from this plugin's access settings.
     *
     * @var string
     */
    public const ACCESS_CAP_PREFIX = 'fabricator_access_';

    /**
     * Checks if the given user has a specific FormFabricator capability.
     *
     * @param string $cap     The capability slug to check.
     * @param int    $user_id User ID, or 0 for the current user.
     */
    public static function userCan(string $cap, int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        $user = get_userdata($user_id);
        // On a network, grants belong to this site's members only: removing someone from the site ends them, whatever
        // the stored list still says.
        if (!$user || (is_multisite() && !is_user_member_of_blog($user_id))) {
            return false;
        }
        // Read on every call, never memoized: a save later in the same request must count.
        $access = get_option('fabricator_forms_access', []);
        $user_overrides = $access['users'] ?? [];
        // A per-user entry grants on top of the role, it doesn't replace it.
        if (isset($user_overrides[$user_id])
            && is_array($user_overrides[$user_id])
            && !empty($user_overrides[$user_id][$cap])
        ) {
            return true;
        }
        $role_perms = $access['roles'] ?? [];
        foreach ($user->roles as $role) {
            if (!empty($role_perms[$role][$cap])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Grants "fabricator_access_<cap>" exactly when userCan(<cap>) allows it, so WordPress itself enforces the admin
     * screens' capability.
     *
     * @param array $allcaps Capabilities the user has.
     * @param array $caps    Primitive capabilities being checked.
     * @param array $args    Original has_cap() arguments.
     * @param mixed $user    The WP_User being checked.
     * @return array
     */
    public static function grantAccessCaps(array $allcaps, array $caps, array $args, $user): array
    {
        foreach ($caps as $cap) {
            if (!is_string($cap) || !str_starts_with($cap, self::ACCESS_CAP_PREFIX)) {
                continue;
            }
            // userCan() treats user 0 as "the current user", so a logged-out check must not reach it.
            $allcaps[$cap] = $user instanceof \WP_User && $user->ID > 0
                && self::userCan(substr($cap, strlen(self::ACCESS_CAP_PREFIX)), (int) $user->ID);
        }
        return $allcaps;
    }

    /**
     * Grants the create_fabricator_forms capability to users with the plugin's own edit_forms permission
     * (userCan() already lets admins through).
     *
     * @param string[] $caps    Required primitive capabilities.
     * @param string   $cap     Requested meta capability.
     * @param int      $user_id The user being asked about: not always the current one (user_can($other, …)).
     * @return string[]
     */
    public static function mapCreateFormCap(array $caps, string $cap, int $user_id = 0): array
    {
        if ($cap === 'create_fabricator_forms') {
            return self::userCan('edit_forms', $user_id) ? ['exist'] : ['do_not_allow'];
        }
        return $caps;
    }
}
