<?php

/**
 * Admin settings page for individual form configuration.
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

namespace FabricatorForms\Admin;

defined('ABSPATH') || exit;

/**
 * Admin settings page for FormFabricator global configuration.
 */
class FormSettings
{
    /**
     * Error from the most recent saveGeneralSettings() call, for the non-AJAX POST fallback to surface.
     *
     * @var string
     */
    private static string $last_save_error = '';

    /**
     * Registers all admin hooks for the settings page.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addSettingsPage']);
        add_action('admin_body_class', [self::class, 'bodyClass']);
        add_action('wp_ajax_fabricator_forms_factory_reset', [self::class, 'handleFactoryReset']);
        add_action('wp_ajax_fabricator_forms_rotate_key', [self::class, 'handleRotateKey']);
        add_action('wp_ajax_fabricator_setup_keep_default', [self::class, 'handleSetupKeepDefault']);
        add_action('wp_ajax_fabricator_setup_get_master_key', [self::class, 'handleSetupGetMasterKey']);
        add_action('wp_ajax_fabricator_upgrade_get_master_key', [self::class, 'handleUpgradeGetMasterKey']);
        add_action('wp_ajax_fabricator_setup_confirm_secure', [self::class, 'handleSetupConfirmSecure']);
        add_action('wp_ajax_fabricator_setup_reset_choice', [self::class, 'handleSetupResetChoice']);
        add_action('wp_ajax_fabricator_add_legacy_key', [self::class, 'handleAddLegacyKey']);
        add_action('wp_ajax_fabricator_peek_key_download', [self::class, 'handlePeekKeyDownload']);
        add_action('wp_ajax_fabricator_confirm_key_download', [self::class, 'handleConfirmKeyDownload']);
        add_action('wp_ajax_fabricator_save_access_settings', [self::class, 'handleSaveAccessSettings']);
        add_action('wp_ajax_fabricator_access_user_search', [self::class, 'handleAccessUserSearch']);
        add_action('wp_ajax_fabricator_save_general_settings', [self::class, 'handleSaveGeneralSettings']);
        add_action('wp_ajax_fabricator_forms_unlock_settings', [self::class, 'ajaxUnlock']);
        add_filter('heartbeat_received', [self::class, 'heartbeatReceived'], 10, 2);
    }

    /**
     * Refreshes or reports a conflict on the Settings page's advisory edit lock — see Utils\AdminLock.
     *
     * @param array $response Heartbeat response payload being built.
     * @param array $data     Data sent by the client in this heartbeat tick.
     * @return array Modified heartbeat response.
     */
    public static function heartbeatReceived(array $response, array $data): array
    {
        if (empty($data['fabricator_settings_lock']) || !\FabricatorForms\Plugin::userCan('settings')) {
            return $response;
        }
        $lock_owner = \FabricatorForms\Utils\AdminLock::check('settings');
        if ($lock_owner) {
            $user = get_userdata($lock_owner);
            $response['fabricator_settings_lock_conflict'] = $user ? $user->display_name : __('another user', 'formfabricator');
        } else {
            \FabricatorForms\Utils\AdminLock::acquire('settings');
        }
        return $response;
    }

    /**
     * Releases the current user's Settings-page edit lock, fired via sendBeacon() on unload.
     *
     * @return void
     */
    public static function ajaxUnlock(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require('settings', 'fabricator_forms_admin_nonce', 'nonce');
        \FabricatorForms\Utils\AdminLock::release('settings', get_current_user_id());
        wp_send_json_success();
    }

    /**
     * Appends a CSS class on the settings page.
     *
     * @param string $classes Existing admin body classes.
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin body-class check, no data written.
        if (isset($_GET['page']) && $_GET['page'] === 'fabricator-forms-settings') {
            $classes .= ' fabricator-list-page';
        }
        return $classes;
    }

    /**
     * Registers the settings submenu page.
     *
     * @return void
     */
    public static function addSettingsPage(): void
    {
        if (\FabricatorForms\Plugin::userCan('settings')) {
            $hook = add_submenu_page(
                'fabricator-forms',
                __('FormFabricator Settings', 'formfabricator'),
                __('Settings', 'formfabricator'),
                \FabricatorForms\Plugin::ACCESS_CAP_PREFIX . 'settings',
                'fabricator-forms-settings',
                [self::class, 'renderSettingsPage']
            );
            if ($hook) {
                add_action('load-' . $hook, [self::class, 'handleSettingsPost']);
            }
        }
    }

    /**
     * Saves a posted settings form on the page's load- hook, before any output, then redirects (Post/Redirect/Get).
     *
     * The redirect keeps a reload of the page from submitting the form again.
     *
     * @return void
     */
    public static function handleSettingsPost(): void
    {
        if (!isset($_POST['fabricator_settings_nonce']) || !\FabricatorForms\Plugin::userCan('settings')) {
            return;
        }
        if (!wp_verify_nonce(sanitize_key($_POST['fabricator_settings_nonce']), 'fabricator_forms_settings')) {
            return;
        }
        self::$last_save_error = '';
        self::saveGeneralSettings();
        set_transient(
            'fabricator_settings_result_' . get_current_user_id(),
            self::$last_save_error === '' ? 'saved' : self::$last_save_error,
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=fabricator-forms-settings'));
        exit;
    }

    /**
     * Renders the full settings page HTML.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!\FabricatorForms\Plugin::userCan('settings')) {
            wp_die(esc_html__('Permission denied.', 'formfabricator'));
        }

        // Saved by handleSettingsPost() before any output; the outcome survives its redirect in a short per-user transient.
        $result_key = 'fabricator_settings_result_' . get_current_user_id();
        $result     = get_transient($result_key);
        delete_transient($result_key);
        $saved                 = $result === 'saved';
        self::$last_save_error = is_string($result) && $result !== 'saved' ? $result : '';

        $from_email        = get_option('fabricator_forms_from_email', '');
        $from_name         = get_option('fabricator_forms_from_name', '');
        $recaptcha_site    = get_option('fabricator_forms_recaptcha_site_key', '');
        $recaptcha_secret  = get_option('fabricator_forms_recaptcha_secret_key', '');
        // Stored comma-separated; shown one entry per line.
        $trusted_proxies   = str_replace(', ', "\n", (string) get_option('fabricator_forms_trusted_proxies', ''));
        $hover_color       = get_option('fabricator_forms_hover_color', '#1d2327');
        $accent_color      = get_option('fabricator_forms_accent_color', '#f59e0b');
        $border_color      = get_option('fabricator_forms_border_color', '#c9cdd4');
        $admin_accent      = get_option('fabricator_forms_admin_accent', '#2271b1');
        $field_layout_mode = get_option('fabricator_forms_field_layout', 'block');
        $particles         = get_option('fabricator_forms_particles', 'on') === 'off' ? 'off' : 'on';
        $wp_admin_email    = get_option('admin_email');
        $setup_done_early = (bool) get_option('fabricator_forms_seal_setup_done', false);
        // Plugin-access-only users (not real WP admins) don't see PDF-seal/recaptcha/user-access tiles.
        $is_full_admin = current_user_can('manage_options');

        // Advisory notice only — saveGeneralSettings()'s snapshot-hash check is the real guard.
        $lock_owner_name = '';
        $lock_owner_id   = \FabricatorForms\Utils\AdminLock::check('settings');
        if ($lock_owner_id) {
            $lock_owner_user  = get_userdata($lock_owner_id);
            $lock_owner_name  = $lock_owner_user ? $lock_owner_user->display_name : __('another user', 'formfabricator');
        } else {
            \FabricatorForms\Utils\AdminLock::acquire('settings');
        }
        wp_enqueue_script('heartbeat');
        $lock_admin_nonce = wp_create_nonce('fabricator_forms_admin_nonce');
        wp_localize_script(
            'fabricator-forms-settings-lock',
            'FabricatorSettingsLock',
            [
            'nonce'   => $lock_admin_nonce,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'i18n'    => [
                // translators: %s: display name of the user currently editing this page.
                'lockConflict' => __('Currently being edited by %s. Saving may conflict.', 'formfabricator'),
            ],
            ]
        );
        ?>
        <?php if ($saved) : ?>
        <div class="fabricator-settings-notice fabricator-settings-notice--success">
            <i class="fa-solid fa-circle-check"></i> <?php echo esc_html__('Settings saved.', 'formfabricator'); ?>
        </div>
        <?php elseif (self::$last_save_error !== '') : ?>
        <div class="fabricator-settings-notice fabricator-settings-notice--error">
            <i class="fa-solid fa-triangle-exclamation"></i> <?php echo esc_html(self::$last_save_error); ?>
        </div>
        <?php endif; ?>
        <div id="fabricator-lock-notice" class="fabricator-settings-notice fabricator-settings-notice--error"
             style="<?php echo $lock_owner_name === '' ? 'display:none;' : ''; ?>">
            <i class="fa-solid fa-lock"></i>
            <span id="fabricator-lock-notice-text">
                <?php
                echo esc_html(
                    $lock_owner_name !== ''
                        ? sprintf(
                            /* translators: %s: display name of the user currently editing Settings. */
                            __('Currently being edited by %s. Saving may conflict.', 'formfabricator'),
                            $lock_owner_name
                        )
                        : ''
                );
                ?>
            </span>
        </div>
        <?php // Heartbeat lock notice JS lives in assets/js/admin-settings-lock.js, enqueued in Utils/Assets.php. ?>

        <?php if (!$setup_done_early) : ?>
        <div id="fabricator-setup-blocker"
             style="position:fixed;z-index:100001;background:rgba(0,0,0,.55);
                    display:flex;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:28px 28px 24px;max-width:560px;
                        width:calc(100% - 32px);box-shadow:0 4px 24px rgba(0,0,0,.22);
                        max-height:calc(100vh - 80px);overflow-y:auto;">
                <!-- Step 1: choose storage mode -->
                <div id="fabricator-blocker-step1">
                <h2 style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1d2327;">
                    <i class="fa-solid fa-shield-halved" style="color:#f59e0b;margin-right:7px;"></i>
                    <?php echo esc_html__('FormFabricator — Initial Setup', 'formfabricator'); ?>
                </h2>
                <p style="margin:0 0 6px;font-size:13px;color:#1d2327;font-weight:600;">
                    <?php echo esc_html__('What are PDF seal keys?', 'formfabricator'); ?>
                </p>
                <p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    echo esc_html__('FormFabricator can generate PDFs from form submissions. Each of these PDFs receives an invisible cryptographic signature when created — similar to a seal on a letter. This signature is calculated from the document content and a secret key.', 'formfabricator'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    echo esc_html__('On the verification page you can upload a PDF: the system recalculates the signature and compares it. If it matches, the document is authentic and unaltered. If even a single character was changed, the check fails.', 'formfabricator'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <p style="margin:0 0 14px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    echo esc_html__('You receive the key as a downloadable file. Keep it safely off the server (e.g. in a password manager or an encrypted USB drive) — so you can restore it as a legacy key after a server failure and continue verifying older PDFs.', 'formfabricator'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <p style="margin:0 0 6px;font-size:13px;color:#1d2327;font-weight:600;">
                    <i class="fa-solid fa-circle-question" style="color:#787c82;margin-right:5px;"></i>
                    <?php echo esc_html__('Why choose encrypted?', 'formfabricator'); ?>
                </p>
                <p style="margin:0 0 8px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    echo esc_html__('In standard mode, the key is stored in plaintext in the database. Anyone who gains access to the database — for example through a compromised plugin or a data backup — could read the key and sign forged PDFs that pass as authentic.', 'formfabricator'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <p style="margin:0 0 16px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %s: wp-config.php as inline code element */
                        __('In encrypted mode, the key in the database is worthless without the master key, which is stored separately on the server in %s. A database-only leak is therefore not sufficient.', 'formfabricator'),
                        '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">wp-config.php</code>'
                    ));
                    ?>
                </p>
                <p style="margin:0 0 10px;font-size:13px;color:#1d2327;font-weight:600;">
                    <?php echo esc_html__('How should the keys be stored?', 'formfabricator'); ?>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;">
                    <button type="button" id="fabricator-blocker-default"
                            style="text-align:left;padding:16px;border:2px solid #dcdcde;
                                   border-radius:6px;background:#fff;cursor:pointer;
                                   font-family:inherit;transition:border-color .15s;">
                        <span style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <i class="fa-solid fa-database" style="font-size:15px;color:#787c82;"></i>
                            <strong style="font-size:13px;color:#1d2327;"><?php echo esc_html__('Standard', 'formfabricator'); ?></strong>
                        </span>
                        <span style="font-size:12px;color:#50575e;line-height:1.4;">
                            <?php echo esc_html__('Stored unencrypted in the database. Anyone with database access, for example through a backup, can forge seals. Compatible with all installations.', 'formfabricator'); ?>
                        </span>
                    </button>
                    <button type="button" id="fabricator-blocker-secure"
                            style="text-align:left;padding:16px;border:2px solid #dcdcde;
                                   border-radius:6px;background:#fff;cursor:pointer;
                                   font-family:inherit;transition:border-color .15s;">
                        <span style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <i class="fa-solid fa-lock" style="font-size:15px;color:#2271b1;"></i>
                            <strong style="font-size:13px;color:#1d2327;"><?php echo esc_html__('Encrypted', 'formfabricator'); ?></strong>
                        </span>
                        <span style="font-size:12px;color:#50575e;line-height:1.4;">
                            <?php
                            echo wp_kses_post(sprintf(
                                /* translators: %s: wp-config.php as inline code element */
                                __('AES-256-GCM — requires a master key in %s.', 'formfabricator'),
                                '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:11px;color:#1d2327;">wp-config.php</code>'
                            ));
                            ?>
                        </span>
                    </button>
                </div>
                <p id="fabricator-blocker-error"
                   style="color:#b32d2e;display:none;margin:0 0 10px;font-size:13px;"></p>
                </div><!-- /#fabricator-blocker-step1 -->

                <!-- Step 2: master-key setup (shown after choosing Encrypted) -->
                <div id="fabricator-blocker-mk-step" style="display:none;">
                    <h2 style="margin:0 0 12px;font-size:17px;font-weight:700;color:#1d2327;">
                        <i class="fa-solid fa-lock" style="color:#2271b1;margin-right:7px;"></i>
                        <?php echo esc_html__('Set up master key', 'formfabricator'); ?>
                    </h2>
                    <p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.55;">
                        <?php
                        echo wp_kses_post(sprintf(
                            /* translators: %s: wp-config.php as inline code element */
                            __('Copy the following line and add it to the %s on your server. Here\'s how:', 'formfabricator'),
                            '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">wp-config.php</code>'
                        ));
                        ?>
                    </p>
                    <ol style="margin:0 0 12px 18px;font-size:13px;color:#50575e;line-height:1.7;">
                        <li><?php
                            echo esc_html__('Open the file manager of your server — either via the hosting control panel, an FTP client (e.g. FileZilla), or SSH.', 'formfabricator'); // phpcs:ignore Generic.Files.LineLength
                        ?></li>
                        <li><?php
                            $code_wplogin = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 4px;font-size:11px;color:#1d2327;">wp-login.php</code>';
                            $code_wpconfig = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 4px;font-size:11px;color:#1d2327;">wp-config.php</code>';
                            echo wp_kses_post(sprintf(
                                /* translators: 1: wp-login.php as code element, 2: wp-config.php as code element */
                                __('Navigate to the root directory of your WordPress installation (where %1$s and %2$s are also located).', 'formfabricator'),
                                $code_wplogin,
                                $code_wpconfig
                            ));
                            ?></li>
                        <li><?php
                            echo wp_kses_post(sprintf(
                                /* translators: %s: wp-config.php as inline code element */
                                __('Open %s for editing.', 'formfabricator'),
                                '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 4px;font-size:11px;color:#1d2327;">wp-config.php</code>'
                            ));
                            ?></li>
                        <li><?php
                            echo wp_kses_post(sprintf(
                                /* translators: 1: the "stop editing" comment line of wp-config.php as a code element, 2: the separately translated "directly before it" as a strong element. */
                                __('Find the line %1$s and insert the line below %2$s.', 'formfabricator'),
                                '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 4px;font-size:11px;color:#1d2327;">/* That\'s all, stop editing! */</code>',
                                '<strong>' . esc_html__('directly before it', 'formfabricator') . '</strong>'
                            ));
                            ?></li>
                        <li><?php echo esc_html__('Save the file and return here.', 'formfabricator'); ?></li>
                    </ol>
                    <div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;
                                padding:12px 14px;margin-bottom:12px;">
                        <div style="font-size:11px;font-family:monospace;word-break:break-all;color:#1d2327;"
                             id="fabricator-blocker-mk-line">—</div>
                    </div>
                    <p id="fabricator-blocker-mk-error"
                       style="color:#b32d2e;display:none;margin:0 0 10px;font-size:13px;"></p>
                    <div style="display:flex;gap:12px;">
                        <button type="button" id="fabricator-blocker-mk-back" class="button"
                                style="margin-right:auto;">
                            <i class="fa-solid fa-arrow-left"></i> <?php echo esc_html__('Back', 'formfabricator'); ?>
                        </button>
                        <button type="button" id="fabricator-blocker-mk-confirm" class="button button-primary">
                            <i class="fa-solid fa-check"></i> <?php echo esc_html__('Entered — Continue', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>

                <!-- Step 2b: master key already present, just finalise -->
                <div id="fabricator-blocker-ready-step" style="display:none;">
                    <h2 style="margin:0 0 12px;font-size:17px;font-weight:700;color:#1d2327;">
                        <i class="fa-solid fa-circle-check" style="color:#00a32a;margin-right:7px;"></i>
                        <?php echo esc_html__('Master key detected', 'formfabricator'); ?>
                    </h2>
                    <p style="margin:0 0 16px;font-size:13px;color:#50575e;line-height:1.55;">
                        <?php
                        $code_master = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">FABRICATOR_SEAL_MASTER_KEY</code>';
                        $code_wpcfg  = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">wp-config.php</code>';
                        echo wp_kses_post(sprintf(
                            /* translators: 1: FABRICATOR_SEAL_MASTER_KEY constant as code element, 2: wp-config.php as code element */
                            __('A valid %1$s is entered in %2$s. Click "Complete setup" to generate the first seal key and finish the setup.', 'formfabricator'),
                            $code_master,
                            $code_wpcfg
                        ));
                        ?>
                    </p>
                    <p id="fabricator-blocker-ready-error"
                       style="color:#b32d2e;display:none;margin:0 0 10px;font-size:13px;"></p>
                    <div style="display:flex;gap:12px;justify-content:flex-end;">
                        <button type="button" id="fabricator-blocker-ready-confirm" class="button button-primary">
                            <i class="fa-solid fa-bolt"></i> <?php echo esc_html__('Complete setup', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <canvas id="fabricator-particle-canvas"></canvas>
        <div class="wrap fabricator-list-wrap">

            <form method="post" id="fabricator-settings-form">
                <?php wp_nonce_field('fabricator_forms_settings', 'fabricator_settings_nonce'); ?>
                <input type="hidden" name="fabricator_settings_snapshot" value="<?php echo esc_attr(self::settingsSnapshot()); ?>">

                <div class="fabricator-settings-topbar">
                    <div class="fabricator-title-pill"><?php echo esc_html__('Settings', 'formfabricator'); ?></div>
                    <div class="fabricator-settings-topbar-actions">
                        <button type="submit" class="button button-primary">
                            <i class="fa-solid fa-floppy-disk"></i> <?php echo esc_html__('Save', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>

                <?php \FabricatorForms\Utils\Assets::renderNoticeDock(); ?>

                <div class="fabricator-settings-tiles">

                    <div class="fabricator-settings-section-header">
                        <i class="fa-solid fa-display"></i> <?php echo esc_html__('Frontend', 'formfabricator'); ?>
                    </div>

                    <div class="fabricator-settings-card">
                        <h2 class="fabricator-settings-card-title">
                            <i class="fa-solid fa-paintbrush"></i> <?php echo esc_html__('Form colors', 'formfabricator'); ?>
                        </h2>

                        <div class="fabricator-settings-field">
                            <label for="accent_color"><?php echo esc_html__('Accent color', 'formfabricator'); ?></label>
                            <input type="text" id="accent_color" name="accent_color"
                                   value="<?php echo esc_attr($accent_color); ?>"
                                   class="fabricator-iris-input" data-default-color="#f59e0b"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>

                        <div class="fabricator-settings-field">
                            <label for="border_color"><?php echo esc_html__('Input border', 'formfabricator'); ?></label>
                            <input type="text" id="border_color" name="border_color"
                                   value="<?php echo esc_attr($border_color); ?>"
                                   class="fabricator-iris-input" data-default-color="#c9cdd4"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>
                    </div>

                    <div class="fabricator-settings-card">
                        <h2 class="fabricator-settings-card-title">
                            <i class="fa-solid fa-table-list"></i> <?php echo esc_html__('Field output', 'formfabricator'); ?>
                        </h2>
                        <div class="fabricator-settings-field">
                            <label><?php echo esc_html__('Layout in email & PDF', 'formfabricator'); ?></label>
                            <div class="fabricator-card-radio-group">
                                <label class="fabricator-card-radio">
                                    <input type="radio" name="field_layout_mode" value="block"
                                        <?php checked($field_layout_mode, 'block'); ?>>
                                    <span class="fabricator-card-radio-head">
                                        <i class="fa-solid fa-list"></i>
                                        <strong><?php echo esc_html__('Block', 'formfabricator'); ?></strong>
                                    </span>
                                    <span class="fabricator-card-radio-desc">
                                        <?php echo esc_html__('Label above the value.', 'formfabricator'); ?>
                                    </span>
                                </label>
                                <label class="fabricator-card-radio">
                                    <input type="radio" name="field_layout_mode" value="inline"
                                        <?php checked($field_layout_mode, 'inline'); ?>>
                                    <span class="fabricator-card-radio-head">
                                        <i class="fa-solid fa-grip-lines"></i>
                                        <strong><?php echo esc_html__('Inline', 'formfabricator'); ?></strong>
                                    </span>
                                    <span class="fabricator-card-radio-desc">
                                        <?php echo esc_html__('Label: value on one line.', 'formfabricator'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <?php if ($is_full_admin) : ?>
                    <div class="fabricator-settings-card">
                        <h2 class="fabricator-settings-card-title">
                            <i class="fa-solid fa-shield-halved"></i> <?php echo esc_html__('reCAPTCHA v2', 'formfabricator'); ?>
                        </h2>
                        <p class="fabricator-settings-hint">
                            <?php echo esc_html__('Only for CAPTCHA fields set to reCAPTCHA. ALTCHA, the default, runs on this site and needs no keys.', 'formfabricator'); ?>
                        </p>

                        <div class="fabricator-settings-field">
                            <label for="recaptcha_site"><?php echo esc_html__('Site Key', 'formfabricator'); ?></label>
                            <input type="text" id="recaptcha_site" name="recaptcha_site"
                                   value="<?php echo esc_attr($recaptcha_site); ?>"
                                   placeholder="6Le…"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>

                        <div class="fabricator-settings-field">
                            <label for="recaptcha_secret"><?php echo esc_html__('Secret Key', 'formfabricator'); ?></label>
                            <?php /* Write-only: the saved secret is never echoed back into the page, where screenshots, page source,
                                     browser extensions and form caches could all read it. Submitting it empty keeps the saved one. */ ?>
                            <input type="password" id="recaptcha_secret" name="recaptcha_secret"
                                   value=""
                                   placeholder="<?php echo (string) $recaptcha_secret !== '' ? esc_attr__('Saved (leave empty to keep)', 'formfabricator') : '6Le…'; ?>"
                                   autocomplete="new-password" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                            <?php if ((string) $recaptcha_secret !== '') : ?>
                            <label class="fabricator-settings-field--inline">
                                <input type="checkbox" name="recaptcha_secret_clear" value="1">
                                <?php echo esc_html__('Remove the saved secret key', 'formfabricator'); ?>
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="fabricator-settings-card">
                        <h2 class="fabricator-settings-card-title">
                            <i class="fa-solid fa-network-wired"></i> <?php echo esc_html__('Trusted proxies', 'formfabricator'); ?>
                        </h2>
                        <div class="fabricator-settings-field">
                            <label for="trusted_proxies"><?php echo esc_html__('Proxy IP addresses or ranges', 'formfabricator'); ?></label>
                            <textarea id="trusted_proxies" name="trusted_proxies" rows="3" spellcheck="false" autocomplete="off"
                                      placeholder="203.0.113.10&#10;198.51.100.0/24"><?php echo esc_textarea($trusted_proxies); ?></textarea>
                            <p class="fabricator-settings-hint">
                                <?php
                                echo esc_html__('Only needed if this site runs behind a proxy, load balancer or CDN (for example Cloudflare). Enter its addresses, one per line.', 'formfabricator')
                                    . ' ' . esc_html__("For requests from these addresses, the visitor's real address is then read from the X-Forwarded-For header. Leave empty otherwise.", 'formfabricator');
                                ?>
                            </p>
                            <?php if (defined('FABRICATOR_TRUSTED_PROXIES')) : ?>
                            <p class="fabricator-settings-hint">
                                <?php echo esc_html__('The FABRICATOR_TRUSTED_PROXIES constant in wp-config.php is set as well; its entries apply in addition to these.', 'formfabricator'); ?>
                            </p>
                            <?php endif; ?>
                            <?php $wide_proxies = \FabricatorForms\Utils\ClientIp::wideTrustedEntries(); ?>
                            <?php if ($wide_proxies !== []) : ?>
                            <p class="fabricator-settings-hint fabricator-settings-hint--warn">
                                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: %s: comma-separated list of the trusted proxy ranges concerned, e.g. "0.0.0.0/0". */
                                        __('%s trusts a large part of the internet as a proxy: any visitor from there can claim another address and so get past the sending limit. Enter only the addresses of your own proxy or CDN.', 'formfabricator'),
                                        implode(', ', $wide_proxies)
                                    )
                                );
                                ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="fabricator-settings-card">
                        <h2 class="fabricator-settings-card-title">
                            <i class="fa-solid fa-user-shield"></i> <?php echo esc_html__('Privacy Policy Text', 'formfabricator'); ?>
                        </h2>
                        <p class="fabricator-settings-hint">
                            <?php
                            echo esc_html__(
                                'If you use the CAPTCHA field (Google reCAPTCHA), your privacy policy needs to disclose that.',
                                'formfabricator'
                            );
                            ?>
                        </p>
                        <button type="button" class="button" id="fabricator-privacy-text-trigger" style="margin-top:10px;">
                            <i class="fa-solid fa-file-lines"></i> <?php echo esc_html__('Show example text', 'formfabricator'); ?>
                        </button>
                    </div>
                    <?php endif; ?>

                    <div class="fabricator-settings-section-header">
                        <i class="fa-solid fa-server"></i> <?php echo esc_html__('Backend', 'formfabricator'); ?>
                    </div>

                    <div class="fabricator-settings-card">
                        <h2 class="fabricator-settings-card-title">
                            <i class="fa-solid fa-envelope"></i> <?php echo esc_html__('Email delivery', 'formfabricator'); ?>
                        </h2>

                        <div class="fabricator-settings-field">
                            <label for="fabricator_cfg_a"><?php echo esc_html__('Sender email', 'formfabricator'); ?></label>
                            <input type="text" inputmode="email" id="fabricator_cfg_a" name="fabricator_cfg_a"
                                   value="<?php echo esc_attr($from_email); ?>"
                                   placeholder="<?php echo esc_attr($wp_admin_email); ?>"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore data-form-type="other"
                                   spellcheck="false">
                            <p class="fabricator-settings-hint">
                                <?php
                                printf(
                                    /* translators: %s: admin e-mail address */
                                    esc_html__('Leave blank to use the WordPress admin email (%s).', 'formfabricator'),
                                    esc_html($wp_admin_email)
                                );
                                ?>
                            </p>
                        </div>

                        <div class="fabricator-settings-field">
                            <label for="fabricator_cfg_b"><?php echo esc_html__('Sender name', 'formfabricator'); ?></label>
                            <input type="text" id="fabricator_cfg_b" name="fabricator_cfg_b"
                                   value="<?php echo esc_attr($from_name); ?>"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore data-form-type="other"
                                   spellcheck="false">
                        </div>
                    </div>

                    <div class="fabricator-settings-card">
                        <h2 class="fabricator-settings-card-title">
                            <i class="fa-solid fa-pen-ruler"></i> <?php echo esc_html__('Editor', 'formfabricator'); ?>
                        </h2>

                        <div class="fabricator-settings-field">
                            <label for="admin_accent"><?php echo esc_html__('Admin accent color', 'formfabricator'); ?></label>
                            <input type="text" id="admin_accent" name="admin_accent"
                                   value="<?php echo esc_attr($admin_accent); ?>"
                                   class="fabricator-iris-input" data-default-color="#2271b1"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                            <p class="fabricator-settings-hint">
                                <?php echo esc_html__('Color for buttons, sliders, and toggles in the admin area.', 'formfabricator'); ?>
                            </p>
                        </div>

                        <div class="fabricator-settings-field">
                            <label for="hover_color"><?php echo esc_html__('Hover color', 'formfabricator'); ?></label>
                            <input type="text" id="hover_color" name="hover_color"
                                   value="<?php echo esc_attr($hover_color); ?>"
                                   class="fabricator-iris-input" data-default-color="#1d2327"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>

                        <div class="fabricator-settings-field">
                            <label><?php echo esc_html__('Particle background', 'formfabricator'); ?></label>
                            <div class="fabricator-card-radio-group">
                                <label class="fabricator-card-radio">
                                    <input type="radio" name="particles" value="on" <?php checked($particles, 'on'); ?>>
                                    <span class="fabricator-card-radio-head">
                                        <i class="fa-solid fa-circle-nodes"></i>
                                        <strong><?php echo esc_html__('On', 'formfabricator'); ?></strong>
                                    </span>
                                    <span class="fabricator-card-radio-desc">
                                        <?php echo esc_html__('Moving, or a still picture when the system asks for reduced motion.', 'formfabricator'); ?>
                                    </span>
                                </label>
                                <label class="fabricator-card-radio">
                                    <input type="radio" name="particles" value="off" <?php checked($particles, 'off'); ?>>
                                    <span class="fabricator-card-radio-head">
                                        <i class="fa-solid fa-ban"></i>
                                        <strong><?php echo esc_html__('Off', 'formfabricator'); ?></strong>
                                    </span>
                                    <span class="fabricator-card-radio-desc">
                                        <?php echo esc_html__('A plain background on every FormFabricator page.', 'formfabricator'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <?php
                    $setup_done       = $setup_done_early;
                    $key_history      = $is_full_admin ? \FabricatorForms\PDF\HashSeal::getHistoryFingerprints() : [];
                    // Active key isn't in history (retired keys only) — fetched separately so a rotated-away compromised key doesn't look current.
                    $active_key       = $is_full_admin ? \FabricatorForms\PDF\HashSeal::getActiveKeyInfo() : [];
                    $rotate_nonce     = wp_create_nonce('fabricator_rotate_key');
                    $setup_nonce      = wp_create_nonce('fabricator_seal_setup');
                    // Boolean only: the key itself is fetched when the modal opens, so it never reaches page source or bfcache.
                    $has_pending_download = ($setup_done && $is_full_admin)
                        && \FabricatorForms\PDF\HashSeal::peekPendingDownload() !== null;
                    $enc_enabled      = \FabricatorForms\PDF\HashSeal::isEncryptionEnabled();
                    // Encrypted storage chosen, but the master key is gone from wp-config.php: nothing can be decrypted. This
                    // state gets its own notice; the upgrade button would only answer "already enabled".
                    $enc_orphaned     = !$enc_enabled && get_option('fabricator_forms_seal_encryption') === 'enabled';
                    ?>
                    <div class="fabricator-settings-card fabricator-settings-card--security"
                         <?php echo $is_full_admin ? '' : 'hidden'; ?>>
                        <h2 class="fabricator-settings-card-title">
                            <i class="fa-solid fa-shield-halved"></i> <?php echo esc_html__('Security', 'formfabricator'); ?>
                        </h2>

                        <?php if ($setup_done) : ?>
                            <?php if ($enc_enabled) : ?>
                        <div style="border:1px solid #b8e6c1;border-radius:4px;padding:10px 14px;
                                    margin-bottom:14px;background:#f0faf2;display:flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-lock" style="color:#00a32a;"></i>
                            <strong style="color:#007017;"><?php echo esc_html__('Encrypted', 'formfabricator'); ?></strong>
                            <span style="color:#50575e;font-size:13px;">
                                <?php echo esc_html__('Keys are secured with AES-256-GCM.', 'formfabricator'); ?></span>
                        </div>
                            <?php elseif ($enc_orphaned) : ?>
                        <div role="alert" style="border:1px solid #f0b9b9;border-radius:4px;padding:10px 14px;
                                    margin-bottom:14px;background:#fcf0f1;display:flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-triangle-exclamation" style="color:#b32d2e;"></i>
                            <strong style="color:#8a2424;"><?php echo esc_html__('Encrypted — master key missing', 'formfabricator'); ?></strong>
                            <span style="color:#50575e;font-size:13px;">
                                <?php
                                echo wp_kses_post(sprintf(
                                    /* translators: 1: FABRICATOR_SEAL_MASTER_KEY as a code element, 2: wp-config.php as a code element. */
                                    __('The keys are stored encrypted, but %1$s is no longer in %2$s, so none of them can be read. Put the line back from your backup.', 'formfabricator'),
                                    '<code>FABRICATOR_SEAL_MASTER_KEY</code>',
                                    '<code>wp-config.php</code>'
                                ));
                                ?>
                            </span>
                        </div>
                            <?php else : ?>
                        <button type="button" id="fabricator-upgrade-enc-btn"
                                class="fabricator-security-action-btn" style="margin-bottom:14px;">
                            <span class="fabricator-security-action-icon" style="color:#787c82;">
                                <i class="fa-solid fa-database"></i>
                            </span>
                            <span class="fabricator-security-action-body">
                                <strong><?php echo esc_html__('Standard — unencrypted', 'formfabricator'); ?></strong>
                                <span><?php echo esc_html__('Click to switch to AES-256-GCM', 'formfabricator'); ?></span>
                            </span>
                            <i class="fa-solid fa-chevron-right fabricator-security-action-arrow"></i>
                        </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="fabricator-security-actions" <?php echo !$setup_done ? 'hidden' : ''; ?>>
                            <button type="button" id="fabricator-key-view-trigger" class="fabricator-security-action-btn">
                                <span class="fabricator-security-action-icon" style="color:#2271b1;">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </span>
                                <span class="fabricator-security-action-body">
                                    <strong><?php echo esc_html__('View PDF keys', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right fabricator-security-action-arrow"></i>
                            </button>

                            <button type="button" id="fabricator-legacy-key-trigger" class="fabricator-security-action-btn">
                                <span class="fabricator-security-action-icon" style="color:#a9a9a9;">
                                    <i class="fa-solid fa-file-import"></i>
                                </span>
                                <span class="fabricator-security-action-body">
                                    <strong><?php echo esc_html__('Add legacy key', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right fabricator-security-action-arrow"></i>
                            </button>

                            <button type="button" id="fabricator-rotate-key-trigger" class="fabricator-security-action-btn">
                                <span class="fabricator-security-action-icon" style="color:#c07a00;">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                </span>
                                <span class="fabricator-security-action-body">
                                    <strong><?php echo esc_html__('Rotate PDF key', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right fabricator-security-action-arrow"></i>
                            </button>
                        </div>
                    </div>

                    <div class="fabricator-settings-card" <?php echo $is_full_admin ? '' : 'hidden'; ?>>
                        <h2 class="fabricator-settings-card-title">
                            <i class="fa-solid fa-screwdriver-wrench"></i> <?php echo esc_html__('Miscellaneous', 'formfabricator'); ?>
                        </h2>

                        <div class="fabricator-security-actions">
                            <button type="button" id="fabricator-access-tile-btn"
                                    class="fabricator-security-action-btn">
                                <span class="fabricator-security-action-icon">
                                    <i class="fa-solid fa-users-gear"></i>
                                </span>
                                <span class="fabricator-security-action-body">
                                    <strong><?php echo esc_html__('User access', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right fabricator-security-action-arrow"></i>
                            </button>
                            <button type="button" id="fabricator-reset-tile-btn"
                                    class="fabricator-security-action-btn fabricator-security-action-btn--danger">
                                <span class="fabricator-security-action-icon fabricator-security-action-icon--danger">
                                    <i class="fa-solid fa-arrow-rotate-left"></i>
                                </span>
                                <span class="fabricator-security-action-body">
                                    <strong><?php echo esc_html__('Reset to factory defaults', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right fabricator-security-action-arrow"></i>
                            </button>
                        </div>

                    </div>

                </div><!-- /.fabricator-settings-tiles -->
            </form>
        </div>

        <!-- Key-rotation modal -->
        <div id="fabricator-key-overlay" class="fabricator-reset-overlay" hidden>
            <div class="fabricator-modal-box fabricator-key-rotate-modal"
                 role="dialog" aria-modal="true" aria-labelledby="fabricator-key-modal-title">
                <h2 id="fabricator-key-modal-title" style="color:#c07a00;">
                    <i class="fa-solid fa-key"></i> <?php echo esc_html__('Rotate PDF key', 'formfabricator'); ?>
                </h2>
                <p>
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %s: the separately translated word "rotated" as an em element (the status name shown for such PDFs). */
                        __('Generates a new random key. PDFs sealed with the previous key remain verifiable and are marked as %s.', 'formfabricator'),
                        '<em>' . esc_html__('rotated', 'formfabricator') . '</em>'
                    ));
                    ?>
                </p>

                <div class="fabricator-key-rotate-fields">
                    <label class="fabricator-reset-check-wrap">
                        <input type="checkbox" id="fabricator_key_compromised" value="1">
                        <span><?php
                                $strong_cmp = '<strong>' . esc_html__('compromised', 'formfabricator') . '</strong>';
                                echo wp_kses_post(sprintf(
                                    /* translators: %s: the separately translated word "compromised" as a strong element. */
                                    __('Mark the previous key as %s', 'formfabricator'),
                                    $strong_cmp
                                ));
                                ?></span>
                    </label>
                    <p id="fabricator-key-compromised-hint" class="fabricator-settings-hint" hidden
                       style="color:#b32d2e;margin:0 0 12px;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?php echo esc_html__('PDFs sealed with the previous key will be shown as compromised during verification.', 'formfabricator'); ?>
                    </p>
                    <?php if ($is_full_admin && \FabricatorForms\PDF\HashSeal::activeKeyProblem() === 'wrong-master-key') : ?>
                        <?php // Rotation refuses to bury the current key under another master key unless the admin says it is lost. ?>
                        <label class="fabricator-reset-check-wrap">
                            <input type="checkbox" id="fabricator_key_master_lost" value="1">
                            <span><?php echo esc_html__('The old master key is lost', 'formfabricator'); ?></span>
                        </label>
                        <p class="fabricator-settings-hint" style="color:#b32d2e;margin:0 0 12px;">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <?php echo esc_html__('Tick only if the master key your seal keys were encrypted with is gone for good. Keys encrypted with it stay unreadable, and their PDFs can no longer be checked.', 'formfabricator'); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="fabricator-reset-actions">
                    <button type="button" id="fabricator-key-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="fabricator-key-confirm" class="button button-primary fabricator-reset-confirm">
                        <i class="fa-solid fa-rotate"></i> <?php echo esc_html__('Rotate key', 'formfabricator'); ?>
                    </button>
                </div>
                <p id="fabricator-key-modal-msg" class="fabricator-settings-hint" hidden
                   style="margin-top:10px;text-align:right;"></p>
            </div>
        </div>

        <!-- Key-view modal -->
        <?php if ($is_full_admin) : ?>
        <div id="fabricator-key-view-overlay" class="fabricator-reset-overlay" hidden>
            <div class="fabricator-modal-box fabricator-key-view-modal"
                 role="dialog" aria-modal="true" aria-labelledby="fabricator-key-view-title">
                <h2 id="fabricator-key-view-title" class="fabricator-key-view-title">
                    <i class="fa-solid fa-magnifying-glass"></i> <?php echo esc_html__('PDF keys', 'formfabricator'); ?>
                </h2>

                <?php if (!empty($key_history) || !empty($active_key)) : ?>
                <div class="fabricator-key-view-scroll">
                <table class="fabricator-key-master-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Fingerprint', 'formfabricator'); ?></th>
                            <th><?php echo esc_html__('Status', 'formfabricator'); ?></th>
                            <th><?php echo esc_html__('Date', 'formfabricator'); ?></th>
                            <th><?php echo esc_html__('By', 'formfabricator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($active_key)) : ?>
                        <tr class="fabricator-key-uuid-row">
                            <td colspan="4">
                                <span class="fabricator-key-uuid-lbl"><?php echo esc_html__('UUID:', 'formfabricator'); ?></span>
                                <code class="fabricator-key-uuid-code"><?php echo esc_html((string) $active_key['uuid']); ?></code>
                            </td>
                        </tr>
                        <tr class="fabricator-key-data-row">
                            <td><code class="fabricator-key-fp-cell"><?php echo esc_html((string) $active_key['fingerprint']); ?></code></td>
                            <td>
                                <span class="fabricator-key-card-badges">
                                    <span class="fabricator-key-badge fabricator-key-badge--active"><?php echo esc_html__('ACTIVE', 'formfabricator'); ?></span>
                                </span>
                            </td>
                            <td><?php echo esc_html__('in use', 'formfabricator'); ?></td>
                            <td>—</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach (array_reverse($key_history) as $entry) :
                        $fp_mid    = (string)($entry['fingerprint'] ?? '');
                        $uuid      = (string)($entry['uuid'] ?? '—');
                        $sta       = (string)($entry['status'] ?? 'rotated');
                        $cmp       = !empty($entry['compromised']);
                        $at        = (string)($entry['retired_at'] ?? '—');
                        $by        = (string)($entry['retired_by_login'] ?? '—');

                        $extra_badge = '';
                        if ($sta === 'rotated-legacy') {
                            $badge_cls   = 'fabricator-key-badge fabricator-key-badge--rotated';
                            $badge_lbl   = __('ROTATED', 'formfabricator');
                            $extra_badge = '<span class="fabricator-key-badge fabricator-key-badge--legacy">' . esc_html__('LEGACY', 'formfabricator') . '</span>';
                        } elseif ($sta === 'compromised-legacy') {
                            $badge_cls   = 'fabricator-key-badge fabricator-key-badge--compromised';
                            $badge_lbl   = __('COMPROMISED', 'formfabricator');
                            $extra_badge = '<span class="fabricator-key-badge fabricator-key-badge--legacy">' . esc_html__('LEGACY', 'formfabricator') . '</span>';
                        } elseif ($cmp) {
                            $badge_cls = 'fabricator-key-badge fabricator-key-badge--compromised';
                            $badge_lbl = __('COMPROMISED', 'formfabricator');
                        } else {
                            $badge_cls = 'fabricator-key-badge fabricator-key-badge--rotated';
                            // Two whole translatable labels, so translators can word and order "initial" freely.
                            $badge_lbl = $sta === 'initial' ? __('ROTATED (initial key)', 'formfabricator') : __('ROTATED', 'formfabricator');
                        }
                        ?>
                        <tr class="fabricator-key-uuid-row">
                            <td colspan="4">
                                <span class="fabricator-key-uuid-lbl"><?php echo esc_html__('UUID:', 'formfabricator'); ?></span>
                                <code class="fabricator-key-uuid-code"><?php echo esc_html($uuid); ?></code>
                            </td>
                        </tr>
                        <tr class="fabricator-key-data-row">
                            <td><code class="fabricator-key-fp-cell"><?php echo esc_html($fp_mid); ?></code></td>
                            <td>
                                <span class="fabricator-key-card-badges">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge_cls is one of the hardcoded literals assigned above, no user input. ?>
                                    <span class="<?php echo $badge_cls; ?>"><?php echo esc_html($badge_lbl); ?></span>
                                    <?php
                                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra_badge is hardcoded HTML built from esc_html__() literals above, no user input.
                                    echo $extra_badge; ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($at); ?></td>
                            <td><?php echo esc_html($by); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div><!-- /.fabricator-key-view-scroll -->
                <?php else : ?>
                <p class="fabricator-settings-hint" style="margin-top:12px;">
                    <?php echo esc_html__('No key created yet.', 'formfabricator'); ?>
                </p>
                <?php endif; ?>

                <div class="fabricator-reset-actions" style="margin-top:20px;">
                    <span></span>
                    <button type="button" id="fabricator-key-view-close" class="button"><?php echo esc_html__('Close', 'formfabricator'); ?></button>
                </div>
            </div>
        </div>

        <!-- Privacy policy text modal -->
        <div id="fabricator-privacy-text-overlay" class="fabricator-reset-overlay" hidden>
            <div class="fabricator-modal-box fabricator-privacy-text-modal"
                 role="dialog" aria-modal="true" aria-labelledby="fabricator-privacy-text-title">
                <h2 id="fabricator-privacy-text-title" class="fabricator-key-view-title">
                    <i class="fa-solid fa-user-shield"></i> <?php echo esc_html__('Privacy Policy Text', 'formfabricator'); ?>
                </h2>
                <?php
                $privacy_disclaimer_sentences = [
                    __(
                        'Here is an example disclaimer you should add to your privacy policy if you use the CAPTCHA field (Google reCAPTCHA).',
                        'formfabricator'
                    ),
                    __(
                        'This example is provided for convenience only — it is not legal advice, may be incomplete or out of date, and is not a substitute for your own review.',
                        'formfabricator'
                    ),
                    __(
                        'You are solely responsible for the accuracy and completeness of your privacy policy.',
                        'formfabricator'
                    ),
                    __(
                        'Copy it in yourself wherever it belongs — nothing here is added automatically.',
                        'formfabricator'
                    ),
                ];
                foreach ($privacy_disclaimer_sentences as $sentence) :
                    ?>
                    <p class="fabricator-settings-hint fabricator-privacy-text-disclaimer">
                        <?php echo esc_html($sentence); ?>
                    </p>
                    <?php
                endforeach;
                ?>
                <?php
                $privacy_langs = \FabricatorForms\Plugin::availablePrivacyLanguages();
                $privacy_texts = [];
                foreach ($privacy_langs as $lang_code => $lang_name) {
                    $privacy_texts[$lang_code] = \FabricatorForms\Plugin::privacyPolicyPlainText($lang_code);
                }
                $privacy_default_lang = isset($privacy_langs['en']) ? 'en' : array_key_first($privacy_langs);
                ?>
                <div class="fabricator-settings-field fabricator-settings-field--inline">
                    <label for="fabricator-privacy-text-lang-input"><?php echo esc_html__('Language:', 'formfabricator'); ?></label>
                    <div class="fabricator-combobox" id="fabricator-privacy-lang-combobox">
                        <input type="text" id="fabricator-privacy-text-lang-input" class="fabricator-combobox-input"
                               autocomplete="off" role="combobox" aria-expanded="false"
                               aria-controls="fabricator-privacy-lang-list"
                               value="<?php echo esc_attr($privacy_langs[$privacy_default_lang] ?? ''); ?>"
                               data-value="<?php echo esc_attr($privacy_default_lang); ?>">
                        <i class="fa-solid fa-chevron-down fabricator-combobox-arrow"></i>
                        <ul class="fabricator-combobox-list" id="fabricator-privacy-lang-list" hidden role="listbox">
                            <?php foreach ($privacy_langs as $lang_code => $lang_name) : ?>
                                <li class="fabricator-combobox-option<?php echo $lang_code === $privacy_default_lang ? ' is-selected' : ''; ?>"
                                    role="option" data-value="<?php echo esc_attr($lang_code); ?>">
                                    <?php echo esc_html($lang_name); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <textarea id="fabricator-privacy-text-box" class="fabricator-privacy-text-box" readonly rows="10"
                          data-privacy-texts="<?php echo esc_attr(\FabricatorForms\Utils\Cast::jsonForAttribute($privacy_texts)); ?>"
                ><?php echo esc_textarea($privacy_texts[$privacy_default_lang] ?? ''); ?></textarea>

                <div class="fabricator-reset-actions" style="margin-top:20px;">
                    <span>
                        <button type="button" class="button" id="fabricator-privacy-text-copy">
                            <i class="fa-solid fa-copy"></i> <?php echo esc_html__('Copy to clipboard', 'formfabricator'); ?>
                        </button>
                        <span id="fabricator-privacy-text-copied" style="display:none;color:#00a32a;margin-left:8px;">
                            <i class="fa-solid fa-check"></i> <?php echo esc_html__('Copied!', 'formfabricator'); ?>
                        </span>
                    </span>
                    <button type="button" id="fabricator-privacy-text-close" class="button"><?php echo esc_html__('Close', 'formfabricator'); ?></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Factory-reset modal -->
        <div id="fabricator-reset-overlay" class="fabricator-reset-overlay" hidden>
            <div class="fabricator-modal-box fabricator-factory-reset-modal"
                 role="dialog" aria-modal="true" aria-labelledby="fabricator-reset-title">
                <div class="fabricator-reset-header">
                    <span class="fabricator-reset-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <div>
                        <h2 id="fabricator-reset-title"><?php echo esc_html__('Confirm reset', 'formfabricator'); ?></h2>
                        <p class="fabricator-reset-subtitle"><?php echo esc_html__('This action cannot be undone.', 'formfabricator'); ?></p>
                    </div>
                </div>

                <p class="fabricator-reset-lead"><?php echo esc_html__('The following settings will be permanently deleted:', 'formfabricator'); ?></p>
                <ul class="fabricator-reset-list">
                    <li><i class="fa-solid fa-envelope"></i> <?php echo esc_html__('Email sender (address & name)', 'formfabricator'); ?></li>
                    <li><i class="fa-solid fa-shield-halved"></i> <?php echo esc_html__('reCAPTCHA Site- & Secret Key', 'formfabricator'); ?></li>
                    <li><i class="fa-solid fa-paintbrush"></i> <?php echo esc_html__('Display colors', 'formfabricator'); ?></li>
                    <li><i class="fa-solid fa-file-pdf"></i> <?php echo esc_html__('PDF layout settings', 'formfabricator'); ?></li>
                    <li><i class="fa-solid fa-users"></i>
                        <?php echo esc_html__('User management & access rights', 'formfabricator'); ?></li>
                </ul>

                <div class="fabricator-reset-key-notice">
                    <i class="fa-solid fa-key"></i>
                    <span><?php
                            $strong_nicht = '<strong>' . esc_html__('not', 'formfabricator') . '</strong>';
                            echo wp_kses_post(sprintf(
                                /* translators: %s: the separately translated word "not" as a strong element. */
                                __('PDF seal keys will %s be deleted.', 'formfabricator'),
                                $strong_nicht
                            ));
                            ?></span>
                </div>

                <label class="fabricator-reset-check-wrap" id="fabricator-reset-forms-label">
                    <input type="checkbox" id="fabricator-reset-delete-forms">
                    <div class="fabricator-reset-check-content">
                        <span class="fabricator-reset-check-title"><?php echo esc_html__('Delete forms & selection groups', 'formfabricator'); ?></span>
                        <span class="fabricator-reset-check-desc"><?php echo esc_html__('All forms and form selection groups will be permanently removed.', 'formfabricator'); ?></span>
                    </div>
                </label>

                <div class="fabricator-reset-actions">
                    <button type="button" id="fabricator-reset-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="fabricator-reset-confirm" class="button fabricator-reset-confirm">
                        <i class="fa-solid fa-rotate-left"></i> <?php echo esc_html__('Reset', 'formfabricator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Key-download modal -->
        <div id="fabricator-key-dl-overlay" class="fabricator-reset-overlay" hidden>
            <div class="fabricator-modal-box fabricator-key-download-modal" role="dialog" aria-modal="true"
                 aria-labelledby="fabricator-key-dl-title">
                <h2 id="fabricator-key-dl-title" style="color:#1a56db;">
                    <i class="fa-solid fa-key"></i> <?php echo esc_html__('Back up key', 'formfabricator'); ?>
                </h2>
                <p>
                    <?php
                    echo esc_html__('A new PDF seal key has been created. Download the key file and store it securely — e.g. in a password manager. You will need it to re-verify old PDFs after a total server loss.', 'formfabricator'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <div class="fabricator-key-dl-info">
                    <div><strong><?php echo esc_html__('UUID:', 'formfabricator'); ?></strong>
                        <code id="fabricator-key-dl-uuid" class="fabricator-key-fp-cell fabricator-key-uuid-cell"></code>
                    </div>
                    <div><strong><?php echo esc_html__('Created:', 'formfabricator'); ?></strong> <span id="fabricator-key-dl-date"></span></div>
                    <div><strong><?php echo esc_html__('Fingerprint:', 'formfabricator'); ?></strong>
                        <code id="fabricator-key-dl-fingerprint" class="fabricator-key-fp-cell"></code>
                    </div>
                </div>
                <div class="fabricator-reset-actions" style="margin-top:20px;flex-direction:column;gap:10px;">
                    <button type="button" id="fabricator-key-dl-btn"
                            class="button button-primary" style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-download"></i> <?php echo esc_html__('Download key file', 'formfabricator'); ?>
                    </button>
                    <button type="button" id="fabricator-key-dl-confirm" class="button" disabled
                            style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-check"></i> <?php echo esc_html__('Saved — Continue', 'formfabricator'); ?>
                    </button>
                </div>
                <p class="fabricator-settings-hint" style="margin-top:12px;text-align:center;">
                    <?php echo esc_html__('You must download the file before you can continue.', 'formfabricator'); ?>
                </p>
                <p class="fabricator-settings-hint" style="margin-top:6px;text-align:center;">
                    <?php echo esc_html__('Note: uninstalling the plugin wipes all seal keys from the server. This file is then the only copy.', 'formfabricator'); ?>
                </p>
            </div>
        </div>

        <!-- Master-key setup modal (Increase Security path) -->
        <div id="fabricator-master-key-overlay" class="fabricator-reset-overlay" hidden>
            <div class="fabricator-modal-box fabricator-master-key-modal" role="dialog" aria-modal="true"
                 aria-labelledby="fabricator-master-key-title">
                <h2 id="fabricator-master-key-title" style="color:#1a56db;">
                    <i class="fa-solid fa-lock"></i> <?php echo esc_html__('Set up master key', 'formfabricator'); ?>
                </h2>
                <p id="fabricator-master-key-intro">
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: 1: wp-config.php as a code element, 2: the separately translated word "before" as a strong element. */
                        __('Add this line to your %1$s %2$s clicking "Continue". The key never leaves the server — it lives only in your configuration file.', 'formfabricator'),
                        '<code>wp-config.php</code>',
                        '<strong>' . esc_html__('before', 'formfabricator') . '</strong>'
                    ));
                    ?>
                </p>
                <div class="fabricator-key-dl-info" style="margin-bottom:14px;">
                    <div style="font-size:11px;font-family:monospace;word-break:break-all;"
                         id="fabricator-master-key-line">—</div>
                </div>
                <p class="fabricator-settings-hint" id="fabricator-master-key-lose-hint">
                    <?php echo esc_html__('If you lose this line, all stored keys become unrecoverable. Keep it as safe as a password.', 'formfabricator'); // phpcs:ignore Generic.Files.LineLength ?>
                </p>
                <!-- Existing master key only: the unencrypted keys, ticked by the admin before any is encrypted. -->
                <div id="fabricator-master-key-keys" hidden></div>
                <p id="fabricator-master-key-error" class="fabricator-settings-hint"
                   style="color:#b32d2e;display:none;margin-top:8px;"></p>
                <div class="fabricator-reset-actions" style="margin-top:20px;">
                    <button type="button" id="fabricator-master-key-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="fabricator-master-key-confirm" class="button button-primary">
                        <i class="fa-solid fa-check"></i> <?php echo esc_html__('Entered — Continue', 'formfabricator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Legacy-key import modal -->
        <div id="fabricator-legacy-key-overlay" class="fabricator-reset-overlay" hidden>
            <div class="fabricator-modal-box fabricator-legacy-import-modal" role="dialog" aria-modal="true"
                 aria-labelledby="fabricator-legacy-key-title">
                <h2 id="fabricator-legacy-key-title" style="color:#a9a9a9;">
                    <i class="fa-solid fa-file-import"></i> <?php echo esc_html__('Add legacy key', 'formfabricator'); ?>
                </h2>
                <p style="margin-bottom:4px;">
                    <?php echo esc_html__('Paste the full contents of the saved key file.', 'formfabricator'); ?>
                </p>
                <textarea id="fabricator-legacy-key-json" rows="10"
                          style="width:100%;font-family:monospace;font-size:12px;resize:vertical;"
                          placeholder='{"plugin":"FormFabricator PDF Seal Key","uuid":"...","key":"...","created_at":"..."}'
                          autocomplete="off" spellcheck="false"></textarea>
                <p style="margin:12px 0 6px;font-weight:600;"><?php echo esc_html__('Status of this key:', 'formfabricator'); ?></p>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;">
                    <input type="radio" name="fabricator_legacy_status" id="fabricator-legacy-status-rotated"
                           value="rotated-legacy" checked>
                    <?php echo esc_html__('Rotated — key was regularly replaced', 'formfabricator'); ?>
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="fabricator_legacy_status" id="fabricator-legacy-status-compromised"
                           value="compromised-legacy">
                    <?php echo esc_html__('Compromised — key was classified as unsafe', 'formfabricator'); ?>
                </label>
                <p id="fabricator-legacy-key-error" class="fabricator-settings-hint"
                   style="color:#b32d2e;display:none;margin-top:10px;"></p>
                <p id="fabricator-legacy-key-mismatch-msg"
                   style="display:none;margin-top:10px;font-size:12px;color:#50575e;
                          background:#fff8e1;border:1px solid #f0c040;border-radius:4px;padding:8px 10px;">
                </p>
                <div class="fabricator-reset-actions" style="margin-top:16px;">
                    <button type="button" id="fabricator-legacy-key-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="fabricator-legacy-key-force" class="button button-primary"
                            style="display:none;">
                        <i class="fa-solid fa-plus"></i> <?php echo esc_html__('Import anyway', 'formfabricator'); ?>
                    </button>
                    <button type="button" id="fabricator-legacy-key-confirm" class="button button-primary">
                        <i class="fa-solid fa-plus"></i> <?php echo esc_html__('Add', 'formfabricator'); ?>
                    </button>
                </div>
            </div>
        </div>

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
                $access_user_overrides[] = [
                    'id'    => (int) $_uid,
                    'name'  => $_ud
                        ? ($_ud->display_name ?: $_ud->user_login)
                        // translators: %d: the ID of a user account that no longer exists.
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
        // The option alone, not isEncryptionEnabled(): the 'masterkey' state is the constant still missing.
        $enc_chosen = get_option('fabricator_forms_seal_encryption') === 'enabled';
        $mk_defined = defined('FABRICATOR_SEAL_MASTER_KEY') && (string) FABRICATOR_SEAL_MASTER_KEY !== '';
        if (!$setup_done_early && $enc_chosen) {
            $setup_state = $mk_defined ? 'ready' : 'masterkey';
        } else {
            $setup_state = 'choose';
        }

        wp_localize_script(
            'fabricator-forms-admin-settings',
            'FabricatorSettingsPage',
            [
            'i18n'   => self::settingsI18n(),
            'nonces' => [
                'factoryReset'    => wp_create_nonce('fabricator_factory_reset'),
                'rotate'          => $rotate_nonce,
                'confirmDownload' => wp_create_nonce('fabricator_forms_admin_nonce'),
            ],
            'data'   => [
                'isFullAdmin'     => $is_full_admin,
                'setupDone'       => $setup_done,
                'setupNonce'      => $setup_nonce,
                'legacyKeyNonce'  => wp_create_nonce('fabricator_add_legacy_key'),
                // Flag only, never the key — see the comment where $has_pending_download is set.
                'hasPendingDownload' => $is_full_admin && $has_pending_download,
                'accessNonce'     => $is_full_admin ? wp_create_nonce('fabricator_access_settings') : '',
                'accessData'      => $is_full_admin ? $access_inline_data : null,
                'accessSnapshot'  => $is_full_admin ? self::accessSnapshot() : '',
                'setupState'      => $setup_state,
            ],
            ]
        );
        ?>

        <?php // Settings page JS: assets/js/admin-settings.js. ?>
        <?php
    }

    /**
     * Translated strings used by assets/js/admin-settings.js, passed via wp_localize_script.
     *
     * @return array
     */
    private static function settingsI18n(): array
    {
        return [
            'resetting'           => __('Resetting…', 'formfabricator'),
            'errorTryAgain'       => __('Error — try again', 'formfabricator'),
            // translators: %d is the countdown, in seconds, before the reset confirmation button becomes clickable.
            'areYouSureCountdown' => __('Are you sure? (%d)', 'formfabricator'),
            'yesReset'            => __('Yes, reset', 'formfabricator'),
            'reset'               => __('Reset', 'formfabricator'),
            'success'             => __('Success', 'formfabricator'),
            'error'               => __('Error', 'formfabricator'),
            'networkError'        => __('Network error', 'formfabricator'),
            'masterKeyExisting'   => __('wp-config.php already holds FABRICATOR_SEAL_MASTER_KEY, so no new line is issued. Confirm to store keys encrypted with it.', 'formfabricator'),
            'masterKeyUnencrypted' => __(
                'These stored keys are not encrypted, so they are refused now. Tick only keys whose fingerprint is in one of your key backup files: they are encrypted and used again. Unticked keys stay refused.',
                'formfabricator'
            ),
            'masterKeyActiveUnticked' => __('If you leave the active key unticked, rotate the PDF key afterwards so forms can seal again.', 'formfabricator'),
            'keyStatusActive'     => __('active key', 'formfabricator'),
            'keyStatusRetired'    => __('retired key', 'formfabricator'),
            'keyStatusSetAside'   => __('set-aside damaged key', 'formfabricator'),
            'keyStatusPending'    => __('key waiting for download', 'formfabricator'),
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
            'saving'              => __('Saving…', 'formfabricator'),
        ];
    }

    /**
     * AJAX handler that saves general plugin settings.
     *
     * @return void
     */
    public static function handleSaveGeneralSettings(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require('settings', 'fabricator_forms_settings', 'fabricator_settings_nonce');
        self::$last_save_error = '';
        self::saveGeneralSettings();
        if (self::$last_save_error !== '') {
            wp_send_json_error(['message' => self::$last_save_error], 409);
        }
        wp_send_json_success(['message' => __('Settings saved.', 'formfabricator'), 'snapshot' => self::settingsSnapshot()]);
    }

    /**
     * Optimistic-concurrency snapshot hash of every option saveGeneralSettings() can write.
     *
     * @return string Snapshot hash.
     */
    private static function settingsSnapshot(): string
    {
        $values = [
            get_option('fabricator_forms_from_email', ''),
            get_option('fabricator_forms_from_name', ''),
            get_option('fabricator_forms_recaptcha_site_key', ''),
            get_option('fabricator_forms_recaptcha_secret_key', ''),
            get_option('fabricator_forms_hover_color', '#1d2327'),
            get_option('fabricator_forms_accent_color', '#f59e0b'),
            get_option('fabricator_forms_border_color', '#c9cdd4'),
            get_option('fabricator_forms_admin_accent', '#2271b1'),
            get_option('fabricator_forms_field_layout', 'block'),
            get_option('fabricator_forms_particles', 'on'),
            get_option('fabricator_forms_trusted_proxies', ''),
        ];
        return md5(wp_json_encode($values));
    }

    /**
     * Saves general settings (email, reCAPTCHA keys, colors) from POST.
     *
     * @return void
     */
    private static function saveGeneralSettings(): void
    {
        if (!\FabricatorForms\Plugin::userCan('settings')) {
            return;
        }

        if (!isset($_POST['fabricator_settings_nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['fabricator_settings_nonce'])), 'fabricator_forms_settings')
        ) {
            return;
        }

        // Optimistic-concurrency guard: reject a save if options changed since this snapshot.
        $expected_snapshot = isset($_POST['fabricator_settings_snapshot'])
            ? sanitize_text_field(wp_unslash($_POST['fabricator_settings_snapshot']))
            : '';
        if ($expected_snapshot !== '' && $expected_snapshot !== self::settingsSnapshot()) {
            self::$last_save_error = __('Settings were changed elsewhere since this page loaded. Please reload and try again.', 'formfabricator');
            return;
        }

        // Validated before anything is written, so an invalid entry refuses the whole save. Administrators only: the
        // list decides whose address a forwarded header may claim.
        $proxy_list = null;
        if (current_user_can('manage_options') && isset($_POST['trusted_proxies'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every entry is validated as an IP address or CIDR range in ClientIp::normalizeProxyList(); Cast::stringOrDefault() breaks the sniff's taint trace.
            [$proxy_list, $proxy_invalid] = \FabricatorForms\Utils\ClientIp::normalizeProxyList(sanitize_textarea_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['trusted_proxies']))));
            if ($proxy_invalid !== []) {
                // translators: %s: comma-separated list of the rejected entries.
                self::$last_save_error = sprintf(__('Not saved: these trusted proxy entries are not valid IP addresses or CIDR ranges: %s', 'formfabricator'), implode(', ', $proxy_invalid));
                return;
            }
        }

        // An emptied field clears the option, as its hint promises. Only fields in the POST are touched, and an invalid
        // address is refused rather than clearing the saved one.
        if (isset($_POST['fabricator_cfg_a'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_email() below; Cast::stringOrDefault() breaks the sniff's taint trace.
            $from_email_raw   = trim(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['fabricator_cfg_a'])));
            $from_email_input = sanitize_email($from_email_raw);
            if ($from_email_raw === '') {
                delete_option('fabricator_forms_from_email');
            } elseif ($from_email_input !== '' && is_email($from_email_input)) {
                update_option('fabricator_forms_from_email', $from_email_input, false);
            } else {
                self::$last_save_error = __('Please enter a valid sender email address, or leave the field blank.', 'formfabricator');
                return;
            }
        }
        if (isset($_POST['fabricator_cfg_b'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field() below; Cast::stringOrDefault() breaks the sniff's taint trace.
            $from_name_input = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['fabricator_cfg_b'])));
            if ($from_name_input !== '') {
                update_option('fabricator_forms_from_name', $from_name_input, false);
            } else {
                delete_option('fabricator_forms_from_name');
            }
        }
        // reCAPTCHA keys are hidden from non-full-admins in the UI; enforce that boundary server-side too against a raw POST.
        if (current_user_can('manage_options')) {
            // Only when the request carries the field, like the sender fields above: a save without it cleared the saved key.
            if (isset($_POST['recaptcha_site'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field() below; Cast::stringOrDefault() breaks the sniff's taint trace.
                $site_key = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['recaptcha_site'])));
                update_option('fabricator_forms_recaptcha_site_key', $site_key, false);
            }
            // Write-only field: empty means "keep the saved secret"; removing it takes the explicit checkbox.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field() below; Cast::stringOrDefault() breaks the sniff's taint trace.
            $secret_key = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['recaptcha_secret'] ?? '')));
            if (!empty($_POST['recaptcha_secret_clear'])) {
                update_option('fabricator_forms_recaptcha_secret_key', '', false);
            } elseif ($secret_key !== '') {
                update_option('fabricator_forms_recaptcha_secret_key', $secret_key, false);
            }
            if ($proxy_list !== null) {
                update_option('fabricator_forms_trusted_proxies', $proxy_list, false);
            }
        }

        // Each colour only when the request carries it. Not autoloaded: no front-end request reads them.
        $colours = [
            'hover_color'  => ['fabricator_forms_hover_color', '#1d2327'],
            'accent_color' => ['fabricator_forms_accent_color', '#f59e0b'],
            'border_color' => ['fabricator_forms_border_color', '#c9cdd4'],
            'admin_accent' => ['fabricator_forms_admin_accent', '#2271b1'],
        ];
        foreach ($colours as $field => [$option, $default]) {
            if (!isset($_POST[$field])) {
                continue;
            }
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_hex_color() on the same line; Cast::stringOrDefault() breaks the sniff's taint trace.
            $colour = sanitize_hex_color(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST[$field]))) ?: $default;
            update_option($option, $colour, false);
        }

        // Only when the request carries it, as for the colours above.
        if (isset($_POST['particles'])) {
            update_option('fabricator_forms_particles', sanitize_key(wp_unslash($_POST['particles'])) === 'off' ? 'off' : 'on', false);
        }

        // Same rule: a request without the field leaves the stored layout as it is.
        if (isset($_POST['field_layout_mode'])) {
            $layout_mode = sanitize_key(wp_unslash($_POST['field_layout_mode']));
            update_option(
                'fabricator_forms_field_layout',
                $layout_mode === 'inline' ? 'inline' : 'block',
                false
            );
        }
    }

    /**
     * AJAX handler that resets all plugin settings (and optionally forms).
     *
     * @return void
     */
    public static function handleFactoryReset(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_factory_reset',
            'nonce'
        );

        $options = [
            'fabricator_forms_from_email',
            'fabricator_forms_from_name',
            'fabricator_forms_recaptcha_site_key',
            'fabricator_forms_recaptcha_secret_key',
            'fabricator_forms_hover_color',
            'fabricator_forms_accent_color',
            'fabricator_forms_border_color',
            'fabricator_forms_admin_accent',
            'fabricator_forms_pdf_settings',
            'fabricator_forms_pdf_layout',
            'fabricator_forms_field_layout',
            'fabricator_forms_particles',
            'fabricator_forms_access',
            'fabricator_forms_trusted_proxies',
        ];
        foreach ($options as $opt) {
            delete_option($opt);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
        if (!empty($_POST['del_forms']) && $_POST['del_forms'] === '1') {
            // A page at a time, not posts_per_page => -1: each round deletes what it read, so the next query returns
            // the ones still left. The counter only stops a runaway loop if a delete is ever refused.
            for ($round = 0; $round < 1000; $round++) {
                $ids = get_posts(
                    [
                    'post_type'      => 'fabricator_form',
                    'posts_per_page' => \FabricatorForms\Form\FormModel::QUERY_BATCH,
                    // Explicit list, as in uninstall.php: get_posts() defaults to 'publish' and 'any' still omits trash and
                    // auto-draft, so drafts and trashed forms survived a reset that promised to delete all forms.
                    'post_status'    => ['publish', 'pending', 'draft', 'future', 'private', 'trash', 'auto-draft', 'inherit'],
                    'fields'         => 'ids',
                    ]
                );
                if (empty($ids)) {
                    break;
                }
                foreach ($ids as $id) {
                    wp_delete_post((int)$id, true);
                }
            }
            delete_option('fabricator_form_selects');
        }

        wp_send_json_success();
    }

    /**
     * What the admin is told when a key change is refused because FABRICATOR_SEAL_MASTER_KEY is not the master key
     * the stored keys were encrypted with (HashSeal::WRONG_MASTER_KEY). Not escaped: shown via .textContent.
     *
     * @return string
     */
    private static function wrongMasterKeyMessage(): string
    {
        return __('Nothing was changed: FABRICATOR_SEAL_MASTER_KEY in wp-config.php does not open your seal keys. Put the right master key back, or, if it is lost for good, tick "The old master key is lost" and rotate.', 'formfabricator');
    }

    /**
     * AJAX handler that rotates the PDF seal key: retires the current key and stores a new random one.
     *
     * @return void
     */
    public static function handleRotateKey(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_rotate_key',
            'nonce'
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
        $compromised = !empty($_POST['key_compromised']) && $_POST['key_compromised'] === '1';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require().
        $master_key_lost = !empty($_POST['master_key_lost']) && $_POST['master_key_lost'] === '1';

        try {
            $new_key = \FabricatorForms\PDF\HashSeal::rotateKey($compromised, true, $master_key_lost);
        } catch (\Exception $e) {
            // Uncaught, this surfaced as a bare 500 (with a stack trace under WP_DEBUG_DISPLAY).
            \FabricatorForms\fabricator_log('FabricatorForms FormSettings: seal key rotation failed — ' . $e->getMessage());
            // Another rotation holds the key history: nothing was changed, and waiting is all it takes.
            if (str_starts_with($e->getMessage(), 'Lock busy:')) {
                wp_send_json_error(['message' => __('Another change to the seal key is in progress. Nothing was changed; please try again in a moment.', 'formfabricator')], 409);
                return;
            }
            if ($e->getCode() === \FabricatorForms\PDF\HashSeal::WRONG_MASTER_KEY) {
                wp_send_json_error(['message' => self::wrongMasterKeyMessage()], 409);
                return;
            }
            wp_send_json_error(['message' => __('The seal key could not be rotated. Please check the server log.', 'formfabricator')], 500);
            return;
        }
        wp_send_json_success(
            [
            'message'    => __('Key rotated successfully.', 'formfabricator'),
            'key_uuid'        => $new_key['uuid'],
            'key_value'       => $new_key['key'],
            'key_fingerprint' => \FabricatorForms\PDF\HashSeal::keyFingerprint($new_key['key']),
            'created_at'      => $new_key['created_at'],
            ]
        );
    }

    /**
     * AJAX handler to finalize setup using default (unencrypted) key storage.
     *
     * @return void
     */
    public static function handleSetupKeepDefault(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_seal_setup',
            'nonce'
        );
        // Setup-only. The setup nonce is issued on every Settings load, so without this a stale or replayed request after
        // setup would silently switch key encryption off, and every later rotation would store its key in plaintext.
        if (get_option('fabricator_forms_seal_setup_done', false)) {
            wp_send_json_error(['message' => __('Seal key setup is already complete.', 'formfabricator')], 409);
            return;
        }
        // With a master key in wp-config.php, an unencrypted key is refused (HashSeal::decryptKey()): Standard storage
        // would leave the site unable to seal.
        if (\FabricatorForms\PDF\HashSeal::masterKeyConfigured()) {
            $msg = __('FABRICATOR_SEAL_MASTER_KEY is set in wp-config.php, so keys must be stored encrypted. Choose encrypted storage, or remove that line from wp-config.php to use Standard storage.', 'formfabricator');
            wp_send_json_error(['message' => $msg], 409);
            return;
        }
        update_option('fabricator_forms_seal_encryption', 'disabled', false);
        update_option('fabricator_forms_seal_setup_done', true, false);
        \FabricatorForms\PDF\HashSeal::createInitialKey(); // atomic; an existing record is left untouched
        // Peek, not claim — see handleSetupConfirmSecure() for why.
        $dl = \FabricatorForms\PDF\HashSeal::peekPendingDownload();
        if (!$dl) {
            wp_send_json_error(['message' => __('No pending key download found.', 'formfabricator')]);
            return;
        }
        wp_send_json_success($dl);
    }

    /**
     * Ends an encrypted-storage request when PHP's openssl extension is missing, which the encryption needs: calling
     * the missing function throws an Error that no handler catches, and every admin page would go down with it.
     *
     * @return void
     */
    private static function refuseWithoutOpenssl(): void
    {
        if (!function_exists('openssl_encrypt')) {
            wp_send_json_error(['message' => __('Encrypted key storage needs the PHP openssl extension, which this server does not have. Ask your host to enable it, or keep Standard storage.', 'formfabricator')], 409);
        }
    }

    public static function handleSetupGetMasterKey(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_seal_setup',
            'nonce'
        );
        self::refuseWithoutOpenssl();
        // Setup-only, like handleSetupKeepDefault(): the setup nonce is issued on every Settings load, so without this a
        // stale or replayed request after setup would flip encryption on and hand out a master key nothing uses.
        if (get_option('fabricator_forms_seal_setup_done', false)) {
            wp_send_json_error(['message' => __('Seal key setup is already complete.', 'formfabricator')], 409);
            return;
        }
        // Commit the encryption choice immediately — the user cannot go back to Standard.
        update_option('fabricator_forms_seal_encryption', 'enabled', false);
        wp_send_json_success(['define_line' => self::issueMasterKey()]);
    }

    /**
     * Per-user transient prefix: the unencrypted key UUIDs the existing-master-key upgrade offered for encryption.
     *
     * @var string
     */
    private const OFFERED_KEYS_TRANSIENT = 'fabricator_upgrade_offered_keys_';

    /**
     * AJAX: issues a master key for switching a site set up in Standard mode to encrypted key storage. Nothing is
     * committed: handleSetupConfirmSecure() switches encryption on once wp-config.php holds the key.
     *
     * @return void
     */
    public static function handleUpgradeGetMasterKey(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_seal_setup',
            'nonce'
        );
        self::refuseWithoutOpenssl();
        if (!get_option('fabricator_forms_seal_setup_done', false)) {
            wp_send_json_error(['message' => __('Please complete the seal key setup first.', 'formfabricator')], 409);
            return;
        }
        if (get_option('fabricator_forms_seal_encryption') === 'enabled') {
            wp_send_json_error(['message' => __('Key encryption is already enabled.', 'formfabricator')], 409);
            return;
        }
        // A master key is already configured, and keys may be encrypted with it: a new one would orphan them, so the
        // existing one is confirmed instead. Encrypting a key makes it trusted, so the admin ticks the unencrypted keys
        // their backups show to be theirs, and handleSetupConfirmSecure() encrypts only those.
        if (\FabricatorForms\PDF\HashSeal::masterKeyConfigured()) {
            set_transient(
                'fabricator_setup_master_key_' . get_current_user_id(),
                hash('sha256', strtolower((string) FABRICATOR_SEAL_MASTER_KEY)),
                600
            );
            $keys = \FabricatorForms\PDF\HashSeal::unencryptedKeys();
            set_transient(self::OFFERED_KEYS_TRANSIENT . get_current_user_id(), array_column($keys, 'uuid'), 600);
            wp_send_json_success(['existing' => true, 'keys' => $keys]);
            return;
        }
        delete_transient(self::OFFERED_KEYS_TRANSIENT . get_current_user_id());
        wp_send_json_success(['define_line' => self::issueMasterKey()]);
    }

    /**
     * Generates a master key, remembers only its hash for handleSetupConfirmSecure(), and returns the wp-config.php line.
     *
     * @return string The define() line to paste into wp-config.php.
     */
    private static function issueMasterKey(): string
    {
        $master_hex = bin2hex(random_bytes(32));
        // Store only a hash, never the plaintext — a DB-only compromise mustn't yield the key that decrypts every seal key.
        set_transient(
            'fabricator_setup_master_key_' . get_current_user_id(),
            hash('sha256', $master_hex),
            600
        );
        self::invalidateWpConfigOpcache();
        return "define('FABRICATOR_SEAL_MASTER_KEY', '" . $master_hex . "');";
    }

    /**
     * Drops wp-config.php from OPcache so the next request compiles it from disk.
     *
     * With opcache.validate_timestamps=0 the pasted line would stay invisible. Runs when the key is issued and again on
     * a failed confirm, since a request in between may have cached the old file.
     *
     * @return void
     */
    private static function invalidateWpConfigOpcache(): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        // wp-config.php can live one directory above ABSPATH — same resolution wp-load.php itself uses.
        $wp_config_path = file_exists(\ABSPATH . 'wp-config.php')
            ? \ABSPATH . 'wp-config.php'
            : dirname(\ABSPATH) . '/wp-config.php';
        if (file_exists($wp_config_path)) {
            opcache_invalidate($wp_config_path, true);
        }
    }

    /**
     * Returns the pending seal key via AJAX response, not serialized into page HTML, so it never sits in page source/bfcache.
     *
     * @return void
     */
    public static function handlePeekKeyDownload(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_forms_admin_nonce',
            'nonce'
        );
        $pending = \FabricatorForms\PDF\HashSeal::peekPendingDownload();
        if ($pending === null) {
            wp_send_json_error(['message' => __('No pending key download found.', 'formfabricator')], 404);
        }
        wp_send_json_success($pending);
    }

    /**
     * Confirms the admin saved the seal key elsewhere — the only call that deletes the one-shot pending-download transient.
     *
     * @return void
     */
    public static function handleConfirmKeyDownload(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_forms_admin_nonce',
            'nonce'
        );
        \FabricatorForms\PDF\HashSeal::confirmDownload();
        wp_send_json_success();
    }

    /**
     * AJAX handler that resets the encryption mode choice during setup.
     *
     * @return void
     */
    public static function handleSetupResetChoice(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_seal_setup',
            'nonce'
        );
        // Setup-only. The setup nonce is issued on every Settings load, so without this a stale or replayed request after
        // setup would silently switch key encryption off, and every later rotation would store its key in plaintext.
        if (get_option('fabricator_forms_seal_setup_done', false)) {
            wp_send_json_error(['message' => __('Seal key setup is already complete.', 'formfabricator')], 409);
            return;
        }
        delete_option('fabricator_forms_seal_encryption');
        delete_transient('fabricator_setup_master_key_' . get_current_user_id());
        wp_send_json_success();
    }

    /**
     * AJAX handler that checks the master key and completes encrypted setup.
     *
     * @return void
     */
    public static function handleSetupConfirmSecure(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_seal_setup',
            'nonce'
        );
        self::refuseWithoutOpenssl();

        if (!defined('FABRICATOR_SEAL_MASTER_KEY') || (string) FABRICATOR_SEAL_MASTER_KEY === '') {
            // The line may well be in the file while a cached copy of it is what this request read; drop that copy so
            // the admin's next attempt compiles wp-config.php from disk.
            self::invalidateWpConfigOpcache();
            wp_send_json_error(
                [
                'message' => __('FABRICATOR_SEAL_MASTER_KEY not found. Please check wp-config.php and try again.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        // Only the key issued during setup counts; a missing or expired transient is refused.
        $expected = get_transient('fabricator_setup_master_key_' . get_current_user_id());
        if (!$expected) {
            wp_send_json_error(
                [
                'message' => __('Setup session expired. Please start again.', 'formfabricator'),
                ]
            );
            return;
        }
        if (!hash_equals($expected, hash('sha256', strtolower((string) FABRICATOR_SEAL_MASTER_KEY)))) {
            wp_send_json_error(
                [
                'message' => __('The entered master key does not match the expected value. Please check wp-config.php.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        $was_setup_done = (bool) get_option('fabricator_forms_seal_setup_done');

        // Existing keys are encrypted first, before anything is switched, so a refusal (a rotation holds the lock)
        // leaves the setup as it was. After the existing-master-key dialog only the ticked keys; otherwise all.
        $offered = get_transient(self::OFFERED_KEYS_TRANSIENT . get_current_user_id());
        $only    = null;
        if (is_array($offered)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
            $posted = isset($_POST['keys']) && is_array($_POST['keys'])
                // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; map_deep() sanitizes, WPCS misses the callback form.
                ? map_deep(wp_unslash($_POST['keys']), 'sanitize_text_field')
                : [];
            $only = array_values(array_intersect($offered, array_filter($posted, 'is_string')));
        }
        try {
            \FabricatorForms\PDF\HashSeal::encryptExistingKeys($only);
        } catch (\RuntimeException $e) {
            \FabricatorForms\fabricator_log('FabricatorForms FormSettings: encrypting existing keys failed — ' . $e->getMessage());
            if (str_starts_with($e->getMessage(), 'Lock busy:')) {
                wp_send_json_error(['message' => __('Another change to the seal key is in progress. Nothing was changed; please try again in a moment.', 'formfabricator')], 409);
                return;
            }
            if ($e->getCode() === \FabricatorForms\PDF\HashSeal::WRONG_MASTER_KEY) {
                wp_send_json_error(['message' => self::wrongMasterKeyMessage()], 409);
                return;
            }
            throw $e;
        }

        delete_transient('fabricator_setup_master_key_' . get_current_user_id());
        delete_transient(self::OFFERED_KEYS_TRANSIENT . get_current_user_id());
        update_option('fabricator_forms_seal_encryption', 'enabled', false);
        update_option('fabricator_forms_seal_setup_done', true, false);

        // Creates the first key unless one already exists (the upgrade path, whose key was encrypted in place above).
        \FabricatorForms\PDF\HashSeal::createInitialKey();
        // Peek, not claim: only handleConfirmKeyDownload() deletes it, so a lost response loses no key.
        $dl = \FabricatorForms\PDF\HashSeal::peekPendingDownload();

        if (!$dl) {
            if ($was_setup_done) {
                // Upgrade path: existing key encrypted in-place, no new key download needed.
                wp_send_json_success(['upgrade' => true]);
                return;
            }
            wp_send_json_error(
                [
                'message' => __('Setup complete, but no download entry found. Please rotate the key to generate a download.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }
        wp_send_json_success($dl);
    }

    /**
     * AJAX handler to import a legacy PDF seal key from JSON.
     *
     * @return void
     */
    public static function handleAddLegacyKey(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => \FabricatorForms\Plugin::userCan('settings') && current_user_can('manage_options'),
            'fabricator_add_legacy_key',
            'nonce'
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
        $raw = sanitize_textarea_field(wp_unslash($_POST['key_json'] ?? ''));
        if ($raw === '') {
            wp_send_json_error(['message' => __('No content pasted.', 'formfabricator')]);
            return;
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            wp_send_json_error(['message' => __('Invalid JSON format.', 'formfabricator')]);
            return;
        }

        $uuid = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($parsed['uuid'] ?? null));
        $key  = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($parsed['key']  ?? null));

        if ($uuid === '' || $key === '') {
            wp_send_json_error(['message' => __('Missing required fields: uuid and key.', 'formfabricator')]);
            return;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            wp_send_json_error(['message' => __('UUID has invalid format.', 'formfabricator')]);
            return;
        }
        if (!preg_match('/^[0-9a-f]{64}$/i', $key)) {
            wp_send_json_error(['message' => __('Key value must be exactly 64 hex characters.', 'formfabricator')]); // phpcs:ignore Generic.Files.LineLength
            return;
        }
        // A backup file carries its key's fingerprint; one that doesn't match the key is not the file that was
        // downloaded. A file without a fingerprint is taken as it is.
        $file_fingerprint = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($parsed['fingerprint'] ?? null));
        $normalize        = static fn(string $fp): string => strtolower((string) preg_replace('/\s+/', '', $fp));
        if ($file_fingerprint !== ''
            && !hash_equals($normalize(\FabricatorForms\PDF\HashSeal::keyFingerprint(strtolower($key))), $normalize($file_fingerprint))
        ) {
            wp_send_json_error(['message' => __('The fingerprint in this key file does not match its key. The file is damaged or was changed.', 'formfabricator')]); // phpcs:ignore Generic.Files.LineLength
            return;
        }

        // Guard: hard-reject only when the exact same payload already exists.
        $incoming_created = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($parsed['created_at'] ?? null));

        // By uuid only, matching HashSeal::addLegacyKey(): the stored active key may be encrypted, so comparing it with the
        // plaintext import never matched, and a second key filed under an existing uuid made verification ambiguous.
        $active_raw = get_option('fabricator_forms_seal_key');
        if ($active_raw) {
            $active_rec = json_decode((string) $active_raw, true);
            $active_dup = is_array($active_rec)
                && strtolower((string) ($active_rec['uuid'] ?? '')) === strtolower($uuid);
            if ($active_dup) {
                wp_send_json_error(['message' => __('A seal key with this UUID is already stored.', 'formfabricator')]); // phpcs:ignore Generic.Files.LineLength
                return;
            }
        }

        // The raw option: the uuids are stored in the clear, so no retired key needs decrypting to compare them.
        foreach ((array) get_option('fabricator_forms_seal_key_history', []) as $entry) {
            $history_dup = is_array($entry) && strtolower((string) ($entry['uuid'] ?? '')) === strtolower($uuid);
            if ($history_dup) {
                wp_send_json_error(
                    [
                    'message' => __('A seal key with this UUID is already stored.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                    ]
                );
                return;
            }
        }

        // Warn if the JSON plugin field doesn't match this plugin.
        $plugin_field    = isset($parsed['plugin']) ? (string) $parsed['plugin'] : '';
        $expected_plugin = 'FormFabricator PDF Seal Key';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
        $confirm_mismatch = (bool) (sanitize_text_field(wp_unslash($_POST['confirm_mismatch'] ?? '')) === '1');
        if ($plugin_field !== $expected_plugin && !$confirm_mismatch) {
            wp_send_json_error(
                [
                'code'    => 'plugin_mismatch',
                'message' => __('Plugin name does not match.', 'formfabricator'),
                'text'    => sprintf(
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered client-side via textContent, not innerHTML; esc_html() here would double-encode instead of protecting.
                    /* translators: %s: the plugin field value from the uploaded JSON */
                    __('The "plugin" field reads "%s" — this does not appear to be a key from this plugin.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                    $plugin_field
                ),
                'confirm' => __('Are you sure you want to import this key?', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
        $raw_status = sanitize_text_field(wp_unslash($_POST['key_status'] ?? ''));
        $allowed    = ['rotated-legacy', 'compromised-legacy'];
        $key_status = in_array($raw_status, $allowed, true) ? $raw_status : 'rotated-legacy';

        try {
            \FabricatorForms\PDF\HashSeal::addLegacyKey(
                $uuid,
                $key,
                $incoming_created,
                $key_status,
                true
            );
        } catch (\RuntimeException $e) {
            \FabricatorForms\fabricator_log('FabricatorForms FormSettings: legacy key import failed — ' . $e->getMessage());
            // A rotation holds the key history: nothing was changed, and waiting is all it takes.
            if (str_starts_with($e->getMessage(), 'Lock busy:')) {
                wp_send_json_error(['message' => __('Another change to the seal key is in progress. Nothing was changed; please try again in a moment.', 'formfabricator')], 409);
                return;
            }
            if ($e->getCode() === \FabricatorForms\PDF\HashSeal::WRONG_MASTER_KEY) {
                wp_send_json_error(['message' => self::wrongMasterKeyMessage()], 409);
                return;
            }
            wp_send_json_error(['message' => __('The key could not be imported. Please check the server log.', 'formfabricator')], 500);
            return;
        }
        wp_send_json_success(['message' => __('Legacy key added successfully.', 'formfabricator')]);
    }

    /**
     * AJAX: up to 20 users matching the typed term, for the access modal's "add user" picker.
     *
     * @return void
     */
    public static function handleAccessUserSearch(): void
    {
        // Same gate as handleSaveAccessSettings(): only real WP admins see or edit per-user grants.
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => current_user_can('manage_options'),
            'fabricator_access_settings',
            'nonce'
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above via AjaxGuard::require(); sanitize_text_field() wraps Cast::stringOrDefault(), which hides it from the sniff.
        $term = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['term'] ?? '')));
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
        \FabricatorForms\Utils\AjaxGuard::require(
            static fn() => current_user_can('manage_options'),
            'fabricator_access_settings',
            'nonce'
        );

        // wp_roles() guarantees the global is initialized; a bare `global $wp_roles` doesn't.
        $wp_roles = wp_roles();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- sanitized below; nonce verified via AjaxGuard::require().
        $raw_roles = wp_unslash($_POST['roles'] ?? []);
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

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- validated/sanitized below; nonce via AjaxGuard::require().
        $raw_users = wp_unslash($_POST['users'] ?? []);
        $users = [];
        if (is_array($raw_users)) {
            foreach ($raw_users as $uid => $raw_perms) {
                $uid = (int) $uid;
                if ($uid > 0 && get_userdata($uid)) {
                    $users[$uid] = self::sanitizePerms(
                        is_array($raw_perms) ? $raw_perms : []
                    );
                }
            }
        }

        // The whole matrix is replaced, so the page's snapshot is compared with what is stored, under one lock with the
        // write, or concurrent saves would silently lose grants.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via AjaxGuard::require().
        $expected = isset($_POST['snapshot']) ? sanitize_text_field(wp_unslash($_POST['snapshot'])) : '';
        $saved    = \FabricatorForms\Utils\OptionMutex::run(
            'fabricator_forms_access',
            static function () use ($expected, $roles, $users): ?string {
                if ($expected !== '' && !hash_equals(self::accessSnapshot(), $expected)) {
                    return null;
                }
                // autoload=false: read only by Plugin::userCan(), which never runs for logged-out visitors.
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

    /**
     * Returns whether a PDF should be attached for the given form and notification.
     *
     * @param int    $form_id Form post ID.
     * @param string $slug    Notification slug.
     * @return bool True if PDF should be attached.
     */
    public static function shouldAttachPdf(int $form_id, string $slug): bool
    {
        $saved = get_option('fabricator_forms_pdf_settings', []);
        return !empty($saved[$form_id . '|' . $slug]);
    }
}
