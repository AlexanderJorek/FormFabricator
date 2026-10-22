<?php

/**
 * The Settings page's user-access part: the access matrix dialog and its handlers.
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

namespace FabricatorForms\Admin;

defined('ABSPATH') || exit;

/**
 * Which roles and users may use which part of the plugin: the access matrix dialog on the Settings page, and the
 * handlers that search users and save the matrix, which Capabilities::userCan() reads. Reserved for administrators
 * (manage_options), never the plugin's own settings permission: it grants access to other users.
 */
class AccessSettings
{
    /**
     * Registers the access-matrix AJAX handlers.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('wp_ajax_fabricator_forms_save_access_settings', [self::class, 'handleSaveAccessSettings']);
        add_action('wp_ajax_fabricator_forms_access_user_search', [self::class, 'handleAccessUserSearch']);
    }

    /**
     * Prints the access-matrix dialog; assets/js/admin-settings-access.js fills it from the localized matrix.
     *
     * @return void
     */
    public static function renderModal(): void
    {
        ?>
        <!-- User-access modal -->
        <div id="fabricator-access-overlay" class="fabricator-reset-overlay" hidden>
            <div class="fabricator-modal-box fabricator-access-modal"
                 role="dialog" aria-modal="true" aria-labelledby="fabricator-access-modal-title">
                <h2 id="fabricator-access-modal-title">
                    <i class="fa-solid fa-users-gear"></i> <?php echo esc_html__('User access', 'formfabricator'); ?>
                </h2>
                <p><?php echo esc_html__('Administrators always have full access. User exceptions grant access in addition to the role; they cannot revoke access the role already allows.', 'formfabricator'); ?></p>

                <div id="fabricator-access-loading" style="text-align:center;padding:24px 0;">
                    <i class="fa-solid fa-spinner fa-spin"></i> <?php echo esc_html__('Loading…', 'formfabricator'); ?>
                </div>
                <div id="fabricator-access-content" style="display:none;">
                    <h3 class="fabricator-access-section-title"><?php echo esc_html__('Roles', 'formfabricator'); ?></h3>
                    <div class="fabricator-access-scroll">
                        <table class="fabricator-access-table">
                            <thead><tr id="fabricator-access-roles-head"></tr></thead>
                            <tbody id="fabricator-access-roles-body"></tbody>
                        </table>
                    </div>

                    <h3 class="fabricator-access-section-title"><?php echo esc_html__('User exceptions', 'formfabricator'); ?></h3>
                    <div class="fabricator-access-scroll" id="fabricator-access-users-scroll">
                        <table class="fabricator-access-table">
                            <thead><tr id="fabricator-access-users-head"></tr></thead>
                            <tbody id="fabricator-access-users-body">
                                <tr id="fabricator-access-no-users">
                                    <td colspan="6" class="fabricator-access-empty">
                                        <?php echo esc_html__('No user exceptions configured.', 'formfabricator'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="fabricator-access-search-section">
                        <input type="text" id="fabricator-access-user-search"
                               class="fabricator-access-search-input"
                               placeholder="<?php echo esc_attr__('Add user…', 'formfabricator'); ?>"
                               autocomplete="off" spellcheck="false">
                        <div id="fabricator-access-user-dropdown"
                             class="fabricator-access-dropdown" hidden></div>
                    </div>
                    <p id="fabricator-access-error"
                       style="color:#b32d2e;display:none;margin-top:8px;font-size:12px;"></p>
                </div>

                <div class="fabricator-reset-actions" style="margin-top:16px;">
                    <button type="button" id="fabricator-access-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="fabricator-access-save" class="button button-primary">
                        <i class="fa-solid fa-floppy-disk"></i> <?php echo esc_html__('Save', 'formfabricator'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Hands assets/js/admin-settings-access.js the stored matrix, its snapshot, the nonce and its strings. Called while
     * the page renders.
     *
     * @param bool $is_full_admin Whether the current user holds manage_options.
     * @return void
     */
    public static function localize(bool $is_full_admin): void
    {
        // Inlined for instant modal open; restricted to full admins so it can't leak permission grants via page source.
        $access_inline_data = null;
        if ($is_full_admin) {
            global $wp_roles;
            $access_option = get_option('fabricator_forms_access', ['roles' => [], 'users' => []]);
            $access_role_names = [];
            foreach ($wp_roles->roles as $_slug => $_data) {
                $access_role_names[$_slug] = translate_user_role($_data['name']);
            }
            // No full user list here: get_users() without a limit would load every account on each Settings render.
            // The "add user" picker queries handleAccessUserSearch() as the admin types.
            $access_user_overrides = [];
            foreach (($access_option['users'] ?? []) as $_uid => $_perms) {
                $_ud = get_userdata((int) $_uid);
                // No longer a member of this site: shown as unknown, as listing the network's users is not this admin's.
                if ($_ud && is_multisite() && !is_user_member_of_blog((int) $_uid)) {
                    $_ud = false;
                }
                $access_user_overrides[] = [
                    'id'    => (int) $_uid,
                    'name'  => $_ud
                        ? ($_ud->display_name ?: $_ud->user_login)
                        // translators: %d: the ID of a user account that no longer exists or no longer belongs to this site.
                        : sprintf(__('Unknown user (#%d)', 'formfabricator'), (int) $_uid),
                    'perms' => is_array($_perms) ? $_perms : [],
                ];
            }
            $access_inline_data = [
                'roles'          => (object) ($access_option['roles'] ?? []),
                'role_names'     => $access_role_names,
                'user_overrides' => $access_user_overrides,
            ];
        }

        wp_localize_script(
            'fabricator-forms-admin-settings-access',
            'FabricatorAccessSettings',
            [
            'i18n' => self::i18n(),
            'data' => [
                'accessNonce'    => $is_full_admin ? wp_create_nonce('fabricator_access_settings') : '',
                'accessData'     => $is_full_admin ? $access_inline_data : null,
                'accessSnapshot' => $is_full_admin ? self::accessSnapshot() : '',
            ],
            ]
        );
    }

    /**
     * Translated strings used by assets/js/admin-settings-access.js.
     *
     * @return array
     */
    private static function i18n(): array
    {
        return [
            'permList'            => __('List', 'formfabricator'),
            'permForms'           => __('Forms', 'formfabricator'),
            'permPdfLayout'       => __('PDF Layout', 'formfabricator'),
            'permVerifier'        => __('Verifier', 'formfabricator'),
            'permSettings'        => __('Settings', 'formfabricator'),
            /* Hover text for access-matrix headers: "Forms" alone doesn't suggest it confers outbound mail. */
            'permListDesc'        => __("View only:\n- See the form list\n- Open a form read-only\nNo creating, editing, deleting, importing or exporting.", 'formfabricator'),
            // phpcs:ignore Generic.Files.LineLength -- WordPress.WP.I18n.NonSingularStringLiteralText requires __() to receive a single unbroken string literal, so this cannot be wrapped via concatenation.
            'permFormsDesc'       => __("Full control of forms:\n- Create, edit, duplicate and delete forms\n- Set notification recipients (To, CC, BCC and routing rules) - this site then sends mail to any address entered\n- Write rich text (HTML block, consent and mandate texts) with links, formatting, images and SVG; form elements such as input fields are always removed\n- Export a form, including its recipient addresses", 'formfabricator'),
            'permPdfLayoutDesc'   => __("PDF appearance:\n- Header, logo and title\n- Fonts, colours and margins\n- Which sections are shown or hidden\n- Footer text", 'formfabricator'),
            'permVerifierDesc'    => __("Document verification:\n- Upload a PDF to the verification page\n- See the result, including the original field values recorded in the document's seal", 'formfabricator'),
            // phpcs:ignore Generic.Files.LineLength -- WordPress.WP.I18n.NonSingularStringLiteralText requires one unbroken string literal, so this cannot be wrapped via concatenation.
            'permSettingsDesc'    => __("Plugin settings:\n- General, mail and PDF settings\nThe access matrix, trusted proxies, seal-key rotation and the CAPTCHA keys also require \"manage_options\".", 'formfabricator'),
            'userLabel'           => __('User', 'formfabricator'),
            'roleLabel'           => __('Role', 'formfabricator'),
            'clickToRemove'       => __('Click to remove', 'formfabricator'),
            'addLabel'            => __('Add', 'formfabricator'),
            'errorSaving'         => __('Error saving.', 'formfabricator'),
        ];
    }

    /**
     * AJAX: up to 20 users matching the typed term, for the access modal's "add user" picker.
     *
     * @return void
     */
    public static function handleAccessUserSearch(): void
    {
        // Same gate as handleSaveAccessSettings(): only real WP admins see or edit per-user grants.
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => current_user_can('manage_options'));
        check_ajax_referer('fabricator_access_settings', 'nonce');
        $term = (is_string($_POST['term'] ?? null) ? sanitize_text_field(wp_unslash($_POST['term'])) : '');
        $args = [
            'fields'  => ['ID', 'display_name', 'user_login'],
            'number'  => 20,
            'orderby' => 'display_name',
        ];
        if ($term !== '') {
            $args['search']         = '*' . $term . '*';
            $args['search_columns'] = ['user_login', 'user_nicename', 'display_name', 'user_email'];
        }
        $users = [];
        foreach (get_users($args) as $user) {
            $users[] = [
                'id'   => (int) $user->ID,
                'name' => $user->display_name ?: $user->user_login,
            ];
        }
        wp_send_json_success($users);
    }

    /**
     * Returns the list of FormFabricator capability slugs.
     *
     * @return array List of capability slug strings.
     */
    private static function accessCaps(): array
    {
        return [
            'view_forms',
            'edit_forms',
            'edit_pdf_layout',
            'use_verifier',
            'settings',
        ];
    }

    /**
     * Sanitizes a permissions array for a role or user.
     *
     * @param array $raw Raw permissions array from POST.
     * @return array Sanitized permissions array.
     */
    private static function sanitizePerms(array $raw): array
    {
        $perms = [];
        foreach (self::accessCaps() as $cap) {
            $perms[$cap] = isset($raw[$cap]) && $raw[$cap] === '1';
        }
        return $perms;
    }

    /**
     * AJAX handler to save role and user access permissions.
     *
     * @return void
     */
    public static function handleSaveAccessSettings(): void
    {
        /* Grants/revokes capabilities for other users — must stay reserved
           for real WP admins, never just the broader "settings" cap. */
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => current_user_can('manage_options'));
        check_ajax_referer('fabricator_access_settings', 'nonce');

        // wp_roles() guarantees the global is initialized; a bare `global $wp_roles` doesn't.
        $wp_roles = wp_roles();
        // Role => [capability => '1'], every value a key; the roles and capabilities are checked against their lists below.
        $raw_roles = isset($_POST['roles']) && is_array($_POST['roles']) ? map_deep(wp_unslash($_POST['roles']), 'sanitize_key') : [];
        $roles = [];
        if (is_array($raw_roles)) {
            foreach ($raw_roles as $slug => $raw_perms) {
                $slug = sanitize_key($slug);
                if ($slug === 'administrator' || !array_key_exists($slug, $wp_roles->roles)) {
                    continue;
                }
                $roles[$slug] = self::sanitizePerms(
                    is_array($raw_perms) ? $raw_perms : []
                );
            }
        }

        // User id => [capability => '1'], every value a key; the users and capabilities are checked below.
        $raw_users = isset($_POST['users']) && is_array($_POST['users']) ? map_deep(wp_unslash($_POST['users']), 'sanitize_key') : [];
        $users = [];
        if (is_array($raw_users)) {
            foreach ($raw_users as $uid => $raw_perms) {
                $uid = (int) $uid;
                // This site's members only: on a network, an ID of anyone else would grant nothing (Capabilities::userCan()),
                // and the page would show their name to an admin who may not list the network's users.
                if ($uid > 0 && get_userdata($uid) && (!is_multisite() || is_user_member_of_blog($uid))) {
                    $users[$uid] = self::sanitizePerms(
                        is_array($raw_perms) ? $raw_perms : []
                    );
                }
            }
        }

        // The whole matrix is replaced, so the page's snapshot is compared with what is stored, under one lock with the
        // write, or concurrent saves would silently lose grants.
        $expected = isset($_POST['snapshot']) ? sanitize_text_field(wp_unslash($_POST['snapshot'])) : '';
        $saved    = \FabricatorForms\Utils\Storage\OptionMutex::run(
            'fabricator_forms_access',
            static function () use ($expected, $roles, $users): ?string {
                if ($expected !== '' && !hash_equals(self::accessSnapshot(), $expected)) {
                    return null;
                }
                // autoload=false: read only by Capabilities::userCan(), which never runs for logged-out visitors.
                update_option('fabricator_forms_access', ['roles' => $roles, 'users' => $users], false);
                return self::accessSnapshot();
            }
        );
        if ($saved === null) {
            wp_send_json_error(
                ['message' => __('Settings were changed elsewhere since this page loaded. Please reload and try again.', 'formfabricator')],
                409
            );
        }
        wp_send_json_success(['snapshot' => $saved]);
    }

    /**
     * Fingerprint of the stored access matrix, for handleSaveAccessSettings()'s changed-elsewhere check.
     *
     * @return string
     */
    private static function accessSnapshot(): string
    {
        return hash('sha256', (string) wp_json_encode(get_option('fabricator_forms_access', ['roles' => [], 'users' => []])));
    }
}
