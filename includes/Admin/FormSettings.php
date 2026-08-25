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
 * @version   1.0.3
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Admin;

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
        add_action('wp_ajax_forge_forms_save_pdf_settings', [self::class, 'savePdfSettings']);
        add_action('wp_ajax_forge_forms_factory_reset', [self::class, 'handleFactoryReset']);
        add_action('wp_ajax_forge_forms_rotate_key', [self::class, 'handleRotateKey']);
        add_action('wp_ajax_forge_setup_keep_default', [self::class, 'handleSetupKeepDefault']);
        add_action('wp_ajax_forge_setup_get_master_key', [self::class, 'handleSetupGetMasterKey']);
        add_action('wp_ajax_forge_setup_confirm_secure', [self::class, 'handleSetupConfirmSecure']);
        add_action('wp_ajax_forge_setup_reset_choice', [self::class, 'handleSetupResetChoice']);
        add_action('wp_ajax_forge_add_legacy_key', [self::class, 'handleAddLegacyKey']);
        add_action('wp_ajax_forge_save_access_settings', [self::class, 'handleSaveAccessSettings']);
        add_action('wp_ajax_forge_save_general_settings', [self::class, 'handleSaveGeneralSettings']);
        add_action('wp_ajax_forge_forms_unlock_settings', [self::class, 'ajaxUnlock']);
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
        if (empty($data['forge_settings_lock']) || !\ForgeForms\Plugin::userCan('settings')) {
            return $response;
        }
        $lock_owner = \ForgeForms\Utils\AdminLock::check('settings');
        if ($lock_owner) {
            $user = get_userdata($lock_owner);
            $response['forge_settings_lock_conflict'] = $user ? $user->display_name : __('another user', 'formfabricator');
        } else {
            \ForgeForms\Utils\AdminLock::acquire('settings');
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
        if (!\ForgeForms\Plugin::userCan('settings')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_forms_admin_nonce', 'nonce');
        \ForgeForms\Utils\AdminLock::release('settings', get_current_user_id());
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
        if (isset($_GET['page']) && $_GET['page'] === 'forge-forms-settings') {
            $classes .= ' forge-list-page';
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
        if (\ForgeForms\Plugin::userCan('settings')) {
            add_submenu_page(
                'forge-forms',
                __('FormFabricator Settings', 'formfabricator'),
                __('Settings', 'formfabricator'),
                'read',
                'forge-forms-settings',
                [self::class, 'renderSettingsPage']
            );
        }
    }

    /**
     * Renders the full settings page HTML.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings')) {
            wp_die(esc_html__('Permission denied.', 'formfabricator'));
        }

        $saved = false;
        self::$last_save_error = '';

        if (isset($_POST['forge_settings_nonce'])
            && wp_verify_nonce(sanitize_key($_POST['forge_settings_nonce']), 'forge_forms_settings')
        ) {
            self::saveGeneralSettings();
            $saved = self::$last_save_error === '';
        }

        $from_email        = get_option('forge_forms_from_email', '');
        $from_name         = get_option('forge_forms_from_name', '');
        $recaptcha_site    = get_option('forge_forms_recaptcha_site_key', '');
        $recaptcha_secret  = get_option('forge_forms_recaptcha_secret_key', '');
        $hover_color       = get_option('forge_forms_hover_color', '#1d2327');
        $accent_color      = get_option('forge_forms_accent_color', '#f59e0b');
        $border_color      = get_option('forge_forms_border_color', '#c9cdd4');
        $admin_accent      = get_option('forge_forms_admin_accent', '#2271b1');
        $field_layout_mode = get_option('forge_forms_field_layout', 'block');
        $wp_admin_email    = get_option('admin_email');
        $setup_done_early = (bool) get_option('forge_forms_seal_setup_done', false);
        /* Users granted access only via the plugin's own user-control system
           (not real WP admins) don't see PDF-seal security, recaptcha keys,
           or the user-access/reset tile — those stay reserved for site admins. */
        $is_full_admin = current_user_can('manage_options');

        // Advisory notice only — saveGeneralSettings()'s snapshot-hash check is the real guard.
        $lock_owner_name = '';
        $lock_owner_id   = \ForgeForms\Utils\AdminLock::check('settings');
        if ($lock_owner_id) {
            $lock_owner_user  = get_userdata($lock_owner_id);
            $lock_owner_name  = $lock_owner_user ? $lock_owner_user->display_name : __('another user', 'formfabricator');
        } else {
            \ForgeForms\Utils\AdminLock::acquire('settings');
        }
        wp_enqueue_script('heartbeat');
        $lock_admin_nonce = wp_create_nonce('forge_forms_admin_nonce');
        wp_localize_script(
            'forge-forms-settings-lock',
            'ForgeSettingsLock',
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
        <div class="forge-settings-notice forge-settings-notice--success">
            <i class="fa-solid fa-circle-check"></i> <?php echo esc_html__('Settings saved.', 'formfabricator'); ?>
        </div>
        <?php elseif (self::$last_save_error !== '') : ?>
        <div class="forge-settings-notice forge-settings-notice--error">
            <i class="fa-solid fa-triangle-exclamation"></i> <?php echo esc_html(self::$last_save_error); ?>
        </div>
        <?php endif; ?>
        <div id="forge-lock-notice" class="forge-settings-notice forge-settings-notice--error"
             style="<?php echo $lock_owner_name === '' ? 'display:none;' : ''; ?>">
            <i class="fa-solid fa-lock"></i>
            <span id="forge-lock-notice-text">
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
        <?php // Heartbeat lock notice: assets/js/admin-settings-lock.js (enqueued in Utils/Assets.php) -- previously an inline <script> block here. ?>

        <?php if (!$setup_done_early) : ?>
        <div id="forge-setup-blocker"
             style="position:fixed;z-index:100001;background:rgba(0,0,0,.55);
                    display:flex;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:28px 28px 24px;max-width:560px;
                        width:calc(100% - 32px);box-shadow:0 4px 24px rgba(0,0,0,.22);
                        max-height:calc(100vh - 80px);overflow-y:auto;">
                <!-- Step 1: choose storage mode -->
                <div id="forge-blocker-step1">
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
                    <button type="button" id="forge-blocker-default"
                            style="text-align:left;padding:16px;border:2px solid #dcdcde;
                                   border-radius:6px;background:#fff;cursor:pointer;
                                   font-family:inherit;transition:border-color .15s;">
                        <span style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <i class="fa-solid fa-database" style="font-size:15px;color:#787c82;"></i>
                            <strong style="font-size:13px;color:#1d2327;"><?php echo esc_html__('Standard', 'formfabricator'); ?></strong>
                        </span>
                        <span style="font-size:12px;color:#50575e;line-height:1.4;">
                            <?php echo esc_html__('Stored unencrypted in the database. Compatible with all installations.', 'formfabricator'); ?>
                        </span>
                    </button>
                    <button type="button" id="forge-blocker-secure"
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
                <p id="forge-blocker-error"
                   style="color:#b32d2e;display:none;margin:0 0 10px;font-size:13px;"></p>
                </div><!-- /#forge-blocker-step1 -->

                <!-- Step 2: master-key setup (shown after choosing Encrypted) -->
                <div id="forge-blocker-mk-step" style="display:none;">
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
                                /* translators: 1: stop-editing comment as code element, 2: "direkt davor" as strong element */
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
                             id="forge-blocker-mk-line">—</div>
                    </div>
                    <p id="forge-blocker-mk-error"
                       style="color:#b32d2e;display:none;margin:0 0 10px;font-size:13px;"></p>
                    <div style="display:flex;gap:12px;">
                        <button type="button" id="forge-blocker-mk-back" class="button"
                                style="margin-right:auto;">
                            <i class="fa-solid fa-arrow-left"></i> <?php echo esc_html__('Back', 'formfabricator'); ?>
                        </button>
                        <button type="button" id="forge-blocker-mk-confirm" class="button button-primary">
                            <i class="fa-solid fa-check"></i> <?php echo esc_html__('Entered — Continue', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>

                <!-- Step 2b: master key already present, just finalise -->
                <div id="forge-blocker-ready-step" style="display:none;">
                    <h2 style="margin:0 0 12px;font-size:17px;font-weight:700;color:#1d2327;">
                        <i class="fa-solid fa-circle-check" style="color:#00a32a;margin-right:7px;"></i>
                        <?php echo esc_html__('Master key detected', 'formfabricator'); ?>
                    </h2>
                    <p style="margin:0 0 16px;font-size:13px;color:#50575e;line-height:1.55;">
                        <?php
                        $code_master = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">FORGE_SEAL_MASTER_KEY</code>';
                        $code_wpcfg  = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">wp-config.php</code>';
                        echo wp_kses_post(sprintf(
                            /* translators: 1: FORGE_SEAL_MASTER_KEY constant as code element, 2: wp-config.php as code element */
                            __('A valid %1$s is entered in %2$s. Click "Complete setup" to generate the first seal key and finish the setup.', 'formfabricator'),
                            $code_master,
                            $code_wpcfg
                        ));
                        ?>
                    </p>
                    <p id="forge-blocker-ready-error"
                       style="color:#b32d2e;display:none;margin:0 0 10px;font-size:13px;"></p>
                    <div style="display:flex;gap:12px;justify-content:flex-end;">
                        <button type="button" id="forge-blocker-ready-confirm" class="button button-primary">
                            <i class="fa-solid fa-bolt"></i> <?php echo esc_html__('Complete setup', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <canvas id="forge-particle-canvas"></canvas>
        <div class="wrap forge-list-wrap">
            <hr class="wp-header-end" style="display:none">

            <form method="post" id="forge-settings-form">
                <?php wp_nonce_field('forge_forms_settings', 'forge_settings_nonce'); ?>
                <input type="hidden" name="forge_settings_snapshot" value="<?php echo esc_attr(self::settingsSnapshot()); ?>">

                <div class="forge-settings-topbar">
                    <div class="forge-title-pill"><?php echo esc_html__('Settings', 'formfabricator'); ?></div>
                    <div class="forge-settings-topbar-actions">
                        <button type="submit" class="button button-primary">
                            <i class="fa-solid fa-floppy-disk"></i> <?php echo esc_html__('Save', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>

                <div class="forge-settings-tiles">

                    <div class="forge-settings-section-header">
                        <i class="fa-solid fa-display"></i> <?php echo esc_html__('Frontend', 'formfabricator'); ?>
                    </div>

                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-paintbrush"></i> <?php echo esc_html__('Form colors', 'formfabricator'); ?>
                        </h2>

                        <div class="forge-settings-field">
                            <label for="accent_color"><?php echo esc_html__('Accent color', 'formfabricator'); ?></label>
                            <input type="text" id="accent_color" name="accent_color"
                                   value="<?php echo esc_attr($accent_color); ?>"
                                   class="forge-iris-input" data-default-color="#f59e0b"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>

                        <div class="forge-settings-field">
                            <label for="border_color"><?php echo esc_html__('Input border', 'formfabricator'); ?></label>
                            <input type="text" id="border_color" name="border_color"
                                   value="<?php echo esc_attr($border_color); ?>"
                                   class="forge-iris-input" data-default-color="#c9cdd4"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>
                    </div>

                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-table-list"></i> <?php echo esc_html__('Field output', 'formfabricator'); ?>
                        </h2>
                        <div class="forge-settings-field">
                            <label><?php echo esc_html__('Layout in email & PDF', 'formfabricator'); ?></label>
                            <div class="forge-card-radio-group">
                                <label class="forge-card-radio">
                                    <input type="radio" name="field_layout_mode" value="block"
                                        <?php checked($field_layout_mode, 'block'); ?>>
                                    <span class="forge-card-radio-head">
                                        <i class="fa-solid fa-list"></i>
                                        <strong><?php echo esc_html__('Block', 'formfabricator'); ?></strong>
                                    </span>
                                    <span class="forge-card-radio-desc">
                                        <?php echo esc_html__('Label above the value.', 'formfabricator'); ?>
                                    </span>
                                </label>
                                <label class="forge-card-radio">
                                    <input type="radio" name="field_layout_mode" value="inline"
                                        <?php checked($field_layout_mode, 'inline'); ?>>
                                    <span class="forge-card-radio-head">
                                        <i class="fa-solid fa-grip-lines"></i>
                                        <strong><?php echo esc_html__('Inline', 'formfabricator'); ?></strong>
                                    </span>
                                    <span class="forge-card-radio-desc">
                                        <?php echo esc_html__('Label: value on one line.', 'formfabricator'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <?php if ($is_full_admin) : ?>
                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-shield-halved"></i> <?php echo esc_html__('reCAPTCHA v2', 'formfabricator'); ?>
                        </h2>

                        <div class="forge-settings-field">
                            <label for="recaptcha_site"><?php echo esc_html__('Site Key', 'formfabricator'); ?></label>
                            <input type="text" id="recaptcha_site" name="recaptcha_site"
                                   value="<?php echo esc_attr($recaptcha_site); ?>"
                                   placeholder="6Le…"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>

                        <div class="forge-settings-field">
                            <label for="recaptcha_secret"><?php echo esc_html__('Secret Key', 'formfabricator'); ?></label>
                            <input type="text" id="recaptcha_secret" name="recaptcha_secret"
                                   value="<?php echo esc_attr($recaptcha_secret); ?>"
                                   placeholder="6Le…"
                                   autocomplete="off" spellcheck="false">
                        </div>
                    </div>

                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-user-shield"></i> <?php echo esc_html__('Privacy Policy Text', 'formfabricator'); ?>
                        </h2>
                        <p class="forge-settings-hint">
                            <?php
                            echo esc_html__(
                                'If you use SEPA IBAN lookups (openiban.com) or the CAPTCHA field (Google reCAPTCHA), your privacy policy needs to disclose that.',
                                'formfabricator'
                            );
                            ?>
                        </p>
                        <button type="button" class="button" id="forge-privacy-text-trigger" style="margin-top:10px;">
                            <i class="fa-solid fa-file-lines"></i> <?php echo esc_html__('Show example text', 'formfabricator'); ?>
                        </button>
                    </div>
                    <?php endif; ?>

                    <div class="forge-settings-section-header">
                        <i class="fa-solid fa-server"></i> <?php echo esc_html__('Backend', 'formfabricator'); ?>
                    </div>

                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-envelope"></i> <?php echo esc_html__('Email delivery', 'formfabricator'); ?>
                        </h2>

                        <div class="forge-settings-field">
                            <label for="forge_cfg_a"><?php echo esc_html__('Sender email', 'formfabricator'); ?></label>
                            <input type="text" inputmode="email" id="forge_cfg_a" name="forge_cfg_a"
                                   value="<?php echo esc_attr($from_email); ?>"
                                   placeholder="<?php echo esc_attr($wp_admin_email); ?>"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore data-form-type="other"
                                   spellcheck="false">
                            <p class="forge-settings-hint">
                                <?php
                                printf(
                                    /* translators: %s: admin e-mail address */
                                    esc_html__('Leave blank to use the WordPress admin email (%s).', 'formfabricator'),
                                    esc_html($wp_admin_email)
                                );
                                ?>
                            </p>
                        </div>

                        <div class="forge-settings-field">
                            <label for="forge_cfg_b"><?php echo esc_html__('Sender name', 'formfabricator'); ?></label>
                            <input type="text" id="forge_cfg_b" name="forge_cfg_b"
                                   value="<?php echo esc_attr($from_name); ?>"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore data-form-type="other"
                                   spellcheck="false">
                        </div>
                    </div>

                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-pen-ruler"></i> <?php echo esc_html__('Editor', 'formfabricator'); ?>
                        </h2>

                        <div class="forge-settings-field">
                            <label for="admin_accent"><?php echo esc_html__('Admin accent color', 'formfabricator'); ?></label>
                            <input type="text" id="admin_accent" name="admin_accent"
                                   value="<?php echo esc_attr($admin_accent); ?>"
                                   class="forge-iris-input" data-default-color="#2271b1"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                            <p class="forge-settings-hint">
                                <?php echo esc_html__('Color for buttons, sliders, and toggles in the admin area.', 'formfabricator'); ?>
                            </p>
                        </div>

                        <div class="forge-settings-field">
                            <label for="hover_color"><?php echo esc_html__('Hover color', 'formfabricator'); ?></label>
                            <input type="text" id="hover_color" name="hover_color"
                                   value="<?php echo esc_attr($hover_color); ?>"
                                   class="forge-iris-input" data-default-color="#1d2327"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>
                    </div>

                    <?php
                    $setup_done       = $setup_done_early;
                    $key_history      = $is_full_admin ? \ForgeForms\PDF\HashSeal::getHistory() : [];
                    $rotate_nonce     = wp_create_nonce('forge_rotate_key');
                    $setup_nonce      = wp_create_nonce('forge_seal_setup');
                    $pending_download = ($setup_done && $is_full_admin)
                        ? \ForgeForms\PDF\HashSeal::claimPendingDownload()
                        : null;
                    $enc_enabled      = \ForgeForms\PDF\HashSeal::isEncryptionEnabled();
                    ?>
                    <div class="forge-settings-card forge-settings-card--security"
                         <?php echo $is_full_admin ? '' : 'hidden'; ?>>
                        <h2 class="forge-settings-card-title">
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
                            <?php else : ?>
                        <button type="button" id="forge-upgrade-enc-btn"
                                class="forge-security-action-btn" style="margin-bottom:14px;">
                            <span class="forge-security-action-icon" style="color:#787c82;">
                                <i class="fa-solid fa-database"></i>
                            </span>
                            <span class="forge-security-action-body">
                                <strong><?php echo esc_html__('Standard — unencrypted', 'formfabricator'); ?></strong>
                                <span><?php echo esc_html__('Click to switch to AES-256-GCM', 'formfabricator'); ?></span>
                            </span>
                            <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                        </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="forge-security-actions" <?php echo !$setup_done ? 'hidden' : ''; ?>>
                            <button type="button" id="forge-key-view-trigger" class="forge-security-action-btn">
                                <span class="forge-security-action-icon" style="color:#2271b1;">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('View PDF keys', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>

                            <button type="button" id="forge-legacy-key-trigger" class="forge-security-action-btn">
                                <span class="forge-security-action-icon" style="color:#a9a9a9;">
                                    <i class="fa-solid fa-file-import"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('Add legacy key', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>

                            <button type="button" id="forge-rotate-key-trigger" class="forge-security-action-btn">
                                <span class="forge-security-action-icon" style="color:#c07a00;">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('Rotate PDF key', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>
                        </div>
                    </div>

                    <div class="forge-settings-card" <?php echo $is_full_admin ? '' : 'hidden'; ?>>
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-screwdriver-wrench"></i> <?php echo esc_html__('Miscellaneous', 'formfabricator'); ?>
                        </h2>

                        <div class="forge-security-actions">
                            <button type="button" id="forge-access-tile-btn"
                                    class="forge-security-action-btn">
                                <span class="forge-security-action-icon">
                                    <i class="fa-solid fa-users-gear"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('User access', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>
                            <button type="button" id="forge-reset-tile-btn"
                                    class="forge-security-action-btn forge-security-action-btn--danger">
                                <span class="forge-security-action-icon forge-security-action-icon--danger">
                                    <i class="fa-solid fa-arrow-rotate-left"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('Reset to factory defaults', 'formfabricator'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>
                        </div>

                    </div>

                </div><!-- /.forge-settings-tiles -->
            </form>
        </div>

        <!-- Key-rotation modal -->
        <div id="forge-key-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-key-rotate-modal"
                 role="dialog" aria-modal="true" aria-labelledby="forge-key-modal-title">
                <h2 id="forge-key-modal-title" style="color:#c07a00;">
                    <i class="fa-solid fa-key"></i> <?php echo esc_html__('Rotate PDF key', 'formfabricator'); ?>
                </h2>
                <p>
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %s: "rotiert" as an em element */
                        __('Derives a new key via PBKDF2. PDFs sealed with the previous key remain verifiable and are marked as %s.', 'formfabricator'),
                        '<em>' . esc_html__('rotated', 'formfabricator') . '</em>'
                    ));
                    ?>
                </p>

                <div class="forge-key-rotate-fields">
                    <div class="forge-settings-field">
                        <label for="forge_key_pw"><?php echo esc_html__('New key password', 'formfabricator'); ?></label>
                        <input type="text" id="forge_key_pw"
                               class="forge-fake-password"
                               autocomplete="off" data-lpignore="true"
                               data-1p-ignore data-bwignore spellcheck="false"
                               readonly
                               placeholder="<?php echo esc_attr__('Min. 12 characters…', 'formfabricator'); ?>">
                        <div id="forge-key-strength" class="forge-key-strength-bar">
                            <span></span><span></span><span></span><span></span><span></span>
                        </div>
                        <ul id="forge-key-pw-errors" class="forge-key-pw-errors"></ul>
                    </div>

                    <div class="forge-settings-field">
                        <label for="forge_key_pw2"><?php echo esc_html__('Confirm password', 'formfabricator'); ?></label>
                        <input type="text" id="forge_key_pw2"
                               class="forge-fake-password"
                               autocomplete="off" data-lpignore="true"
                               data-1p-ignore data-bwignore spellcheck="false"
                               readonly>
                    </div>

                    <label class="forge-reset-check-wrap">
                        <input type="checkbox" id="forge_key_compromised" value="1">
                        <span><?php
                                $strong_cmp = '<strong>' . esc_html__('compromised', 'formfabricator') . '</strong>';
                                echo wp_kses_post(sprintf(
                                    /* translators: %s: "kompromittiert" as a strong element */
                                    __('Mark the previous key as %s', 'formfabricator'),
                                    $strong_cmp
                                ));
                                ?></span>
                    </label>
                    <p id="forge-key-compromised-hint" class="forge-settings-hint" hidden
                       style="color:#b32d2e;margin:0 0 12px;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?php echo esc_html__('PDFs sealed with the previous key will be shown as compromised during verification.', 'formfabricator'); ?>
                    </p>
                </div>

                <div class="forge-reset-actions">
                    <button type="button" id="forge-key-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="forge-key-confirm" class="button button-primary forge-reset-confirm"
                            disabled>
                        <i class="fa-solid fa-rotate"></i> <?php echo esc_html__('Rotate key', 'formfabricator'); ?>
                    </button>
                </div>
                <p id="forge-key-modal-msg" class="forge-settings-hint" hidden
                   style="margin-top:10px;text-align:right;"></p>
            </div>
        </div>

        <!-- Key-view modal -->
        <?php if ($is_full_admin) : ?>
        <div id="forge-key-view-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-key-view-modal"
                 role="dialog" aria-modal="true" aria-labelledby="forge-key-view-title">
                <h2 id="forge-key-view-title" class="forge-key-view-title">
                    <i class="fa-solid fa-magnifying-glass"></i> <?php echo esc_html__('PDF keys', 'formfabricator'); ?>
                </h2>

                <?php if (!empty($key_history)) : ?>
                <div class="forge-key-view-scroll">
                <table class="forge-key-master-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Fingerprint', 'formfabricator'); ?></th>
                            <th><?php echo esc_html__('Status', 'formfabricator'); ?></th>
                            <th><?php echo esc_html__('Date', 'formfabricator'); ?></th>
                            <th><?php echo esc_html__('By', 'formfabricator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_reverse($key_history) as $entry) :
                        $raw_entry_key = (string)($entry['key'] ?? '');
                        $fp_mid        = '';
                        if ($raw_entry_key !== '') {
                            $fp_mid = substr(hash('sha256', $raw_entry_key), 0, 6);
                        }
                        $uuid      = esc_html((string)($entry['uuid'] ?? '—'));
                        $sta       = (string)($entry['status'] ?? 'rotated');
                        $cmp       = !empty($entry['compromised']);
                        $at        = esc_html((string)($entry['retired_at'] ?? '—'));
                        $by        = esc_html((string)($entry['retired_by_login'] ?? '—'));

                        $extra_badge = '';
                        if ($sta === 'rotated-legacy') {
                            $badge_cls   = 'forge-key-badge forge-key-badge--rotated';
                            $badge_lbl   = __('ROTATED', 'formfabricator');
                            $extra_badge = '<span class="forge-key-badge forge-key-badge--legacy">' . esc_html__('LEGACY', 'formfabricator') . '</span>';
                        } elseif ($sta === 'compromised-legacy') {
                            $badge_cls   = 'forge-key-badge forge-key-badge--compromised';
                            $badge_lbl   = __('COMPROMISED', 'formfabricator');
                            $extra_badge = '<span class="forge-key-badge forge-key-badge--legacy">' . esc_html__('LEGACY', 'formfabricator') . '</span>';
                        } elseif ($cmp) {
                            $badge_cls = 'forge-key-badge forge-key-badge--compromised';
                            $badge_lbl = __('COMPROMISED', 'formfabricator');
                        } else {
                            $badge_cls = 'forge-key-badge forge-key-badge--rotated';
                            $badge_lbl = __('ROTATED', 'formfabricator') . ($sta === 'initial' ? ' (Initial)' : '');
                        }
                        ?>
                        <tr class="forge-key-uuid-row">
                            <td colspan="4">
                                <span class="forge-key-uuid-lbl"><?php echo esc_html__('UUID:', 'formfabricator'); ?></span>
                                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $uuid is already esc_html()'d at assignment above. ?>
                                <code class="forge-key-uuid-code"><?php echo $uuid; ?></code>
                            </td>
                        </tr>
                        <tr class="forge-key-data-row">
                            <td><code class="forge-key-fp-cell"><?php echo esc_html($fp_mid); ?></code></td>
                            <td>
                                <span class="forge-key-card-badges">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge_cls is one of the hardcoded literals assigned above, no user input. ?>
                                    <span class="<?php echo $badge_cls; ?>"><?php echo esc_html($badge_lbl); ?></span>
                                    <?php
                                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra_badge is hardcoded HTML built from esc_html__() literals above, no user input.
                                    echo $extra_badge; ?>
                                </span>
                            </td>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $at is already esc_html()'d at assignment above. ?>
                            <td><?php echo $at; ?></td>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $by is already esc_html()'d at assignment above. ?>
                            <td><?php echo $by; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div><!-- /.forge-key-view-scroll -->
                <?php else : ?>
                <p class="forge-settings-hint" style="margin-top:12px;">
                    <?php echo esc_html__('No rotations performed yet — no log available.', 'formfabricator'); ?>
                </p>
                <?php endif; ?>

                <div class="forge-reset-actions" style="margin-top:20px;">
                    <span></span>
                    <button type="button" id="forge-key-view-close" class="button"><?php echo esc_html__('Close', 'formfabricator'); ?></button>
                </div>
            </div>
        </div>

        <!-- Privacy policy text modal -->
        <div id="forge-privacy-text-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-privacy-text-modal"
                 role="dialog" aria-modal="true" aria-labelledby="forge-privacy-text-title">
                <h2 id="forge-privacy-text-title" class="forge-key-view-title">
                    <i class="fa-solid fa-user-shield"></i> <?php echo esc_html__('Privacy Policy Text', 'formfabricator'); ?>
                </h2>
                <?php
                $privacy_disclaimer_sentences = [
                    __(
                        'Here is an example disclaimer you should add to your privacy policy if you use SEPA IBAN lookups (openiban.com) or the CAPTCHA field (Google reCAPTCHA).',
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
                    <p class="forge-settings-hint forge-privacy-text-disclaimer">
                        <?php echo esc_html($sentence); ?>
                    </p>
                    <?php
                endforeach;
                ?>
                <?php
                $privacy_langs = \ForgeForms\Plugin::availablePrivacyLanguages();
                $privacy_texts = [];
                foreach ($privacy_langs as $lang_code => $lang_name) {
                    $privacy_texts[$lang_code] = \ForgeForms\Plugin::privacyPolicyPlainText($lang_code);
                }
                $privacy_default_lang = isset($privacy_langs['en']) ? 'en' : array_key_first($privacy_langs);
                ?>
                <div class="forge-settings-field forge-settings-field--inline">
                    <label for="forge-privacy-text-lang-input"><?php echo esc_html__('Language:', 'formfabricator'); ?></label>
                    <div class="forge-combobox" id="forge-privacy-lang-combobox">
                        <input type="text" id="forge-privacy-text-lang-input" class="forge-combobox-input"
                               autocomplete="off" role="combobox" aria-expanded="false"
                               aria-controls="forge-privacy-lang-list"
                               value="<?php echo esc_attr($privacy_langs[$privacy_default_lang] ?? ''); ?>"
                               data-value="<?php echo esc_attr($privacy_default_lang); ?>">
                        <i class="fa-solid fa-chevron-down forge-combobox-arrow"></i>
                        <ul class="forge-combobox-list" id="forge-privacy-lang-list" hidden role="listbox">
                            <?php foreach ($privacy_langs as $lang_code => $lang_name) : ?>
                                <li class="forge-combobox-option<?php echo $lang_code === $privacy_default_lang ? ' is-selected' : ''; ?>"
                                    role="option" data-value="<?php echo esc_attr($lang_code); ?>">
                                    <?php echo esc_html($lang_name); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <textarea id="forge-privacy-text-box" class="forge-privacy-text-box" readonly rows="10"
                          data-privacy-texts="<?php echo esc_attr(wp_json_encode($privacy_texts)); ?>"
                ><?php echo esc_textarea($privacy_texts[$privacy_default_lang] ?? ''); ?></textarea>

                <div class="forge-reset-actions" style="margin-top:20px;">
                    <span>
                        <button type="button" class="button" id="forge-privacy-text-copy">
                            <i class="fa-solid fa-copy"></i> <?php echo esc_html__('Copy to clipboard', 'formfabricator'); ?>
                        </button>
                        <span id="forge-privacy-text-copied" style="display:none;color:#00a32a;margin-left:8px;">
                            <i class="fa-solid fa-check"></i> <?php echo esc_html__('Copied!', 'formfabricator'); ?>
                        </span>
                    </span>
                    <button type="button" id="forge-privacy-text-close" class="button"><?php echo esc_html__('Close', 'formfabricator'); ?></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Factory-reset modal -->
        <div id="forge-reset-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-factory-reset-modal"
                 role="dialog" aria-modal="true" aria-labelledby="forge-reset-title">
                <div class="forge-reset-header">
                    <span class="forge-reset-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <div>
                        <h2 id="forge-reset-title"><?php echo esc_html__('Confirm reset', 'formfabricator'); ?></h2>
                        <p class="forge-reset-subtitle"><?php echo esc_html__('This action cannot be undone.', 'formfabricator'); ?></p>
                    </div>
                </div>

                <p class="forge-reset-lead"><?php echo esc_html__('The following settings will be permanently deleted:', 'formfabricator'); ?></p>
                <ul class="forge-reset-list">
                    <li><i class="fa-solid fa-envelope"></i> <?php echo esc_html__('Email sender (address & name)', 'formfabricator'); ?></li>
                    <li><i class="fa-solid fa-shield-halved"></i> <?php echo esc_html__('reCAPTCHA Site- & Secret Key', 'formfabricator'); ?></li>
                    <li><i class="fa-solid fa-paintbrush"></i> <?php echo esc_html__('Display colors', 'formfabricator'); ?></li>
                    <li><i class="fa-solid fa-file-pdf"></i> <?php echo esc_html__('PDF layout settings', 'formfabricator'); ?></li>
                    <li><i class="fa-solid fa-users"></i>
                        <?php echo esc_html__('User management & access rights', 'formfabricator'); ?></li>
                </ul>

                <div class="forge-reset-key-notice">
                    <i class="fa-solid fa-key"></i>
                    <span><?php
                            $strong_nicht = '<strong>' . esc_html__('not', 'formfabricator') . '</strong>';
                            echo wp_kses_post(sprintf(
                                /* translators: %s: "nicht" as a strong element */
                                __('PDF seal keys will %s be deleted.', 'formfabricator'),
                                $strong_nicht
                            ));
                            ?></span>
                </div>

                <label class="forge-reset-check-wrap" id="forge-reset-forms-label">
                    <input type="checkbox" id="forge-reset-delete-forms">
                    <div class="forge-reset-check-content">
                        <span class="forge-reset-check-title"><?php echo esc_html__('Delete forms & selection groups', 'formfabricator'); ?></span>
                        <span class="forge-reset-check-desc"><?php echo esc_html__('All forms and form selection groups will be permanently removed.', 'formfabricator'); ?></span>
                    </div>
                </label>

                <div class="forge-reset-actions">
                    <button type="button" id="forge-reset-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="forge-reset-confirm" class="button forge-reset-confirm">
                        <i class="fa-solid fa-rotate-left"></i> <?php echo esc_html__('Reset', 'formfabricator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Key-download modal -->
        <div id="forge-key-dl-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-key-download-modal" role="dialog" aria-modal="true"
                 aria-labelledby="forge-key-dl-title">
                <h2 id="forge-key-dl-title" style="color:#1a56db;">
                    <i class="fa-solid fa-key"></i> <?php echo esc_html__('Back up key', 'formfabricator'); ?>
                </h2>
                <p>
                    <?php
                    echo esc_html__('A new PDF seal key has been created. Download the key file and store it securely — e.g. in a password manager. You will need it to re-verify old PDFs after a total server loss.', 'formfabricator'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <div class="forge-key-dl-info">
                    <div><strong><?php echo esc_html__('UUID:', 'formfabricator'); ?></strong>
                        <code id="forge-key-dl-uuid" class="forge-key-fp-cell forge-key-uuid-cell"></code>
                    </div>
                    <div><strong><?php echo esc_html__('Created:', 'formfabricator'); ?></strong> <span id="forge-key-dl-date"></span></div>
                </div>
                <div class="forge-reset-actions" style="margin-top:20px;flex-direction:column;gap:10px;">
                    <button type="button" id="forge-key-dl-btn"
                            class="button button-primary" style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-download"></i> <?php echo esc_html__('Download key file', 'formfabricator'); ?>
                    </button>
                    <button type="button" id="forge-key-dl-confirm" class="button" disabled
                            style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-check"></i> <?php echo esc_html__('Saved — Continue', 'formfabricator'); ?>
                    </button>
                </div>
                <p class="forge-settings-hint" style="margin-top:12px;text-align:center;">
                    <?php echo esc_html__('You must download the file before you can continue.', 'formfabricator'); ?>
                </p>
            </div>
        </div>

        <!-- Master-key setup modal (Increase Security path) -->
        <div id="forge-master-key-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-master-key-modal" role="dialog" aria-modal="true"
                 aria-labelledby="forge-master-key-title">
                <h2 id="forge-master-key-title" style="color:#1a56db;">
                    <i class="fa-solid fa-lock"></i> <?php echo esc_html__('Set up master key', 'formfabricator'); ?>
                </h2>
                <p>
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: 1: wp-config.php as code element, 2: "bevor" as strong element */
                        __('Add this line to your %1$s %2$s clicking "Continue". The key never leaves the server — it lives only in your configuration file.', 'formfabricator'),
                        '<code>wp-config.php</code>',
                        '<strong>' . esc_html__('before', 'formfabricator') . '</strong>'
                    ));
                    ?>
                </p>
                <div class="forge-key-dl-info" style="margin-bottom:14px;">
                    <div style="font-size:11px;font-family:monospace;word-break:break-all;"
                         id="forge-master-key-line">—</div>
                </div>
                <p class="forge-settings-hint">
                    <?php echo esc_html__('If you lose this line, all stored keys become unrecoverable. Keep it as safe as a password.', 'formfabricator'); // phpcs:ignore Generic.Files.LineLength ?>
                </p>
                <p id="forge-master-key-error" class="forge-settings-hint"
                   style="color:#b32d2e;display:none;margin-top:8px;"></p>
                <div class="forge-reset-actions" style="margin-top:20px;">
                    <button type="button" id="forge-master-key-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="forge-master-key-confirm" class="button button-primary">
                        <i class="fa-solid fa-check"></i> <?php echo esc_html__('Entered — Continue', 'formfabricator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Legacy-key import modal -->
        <div id="forge-legacy-key-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-legacy-import-modal" role="dialog" aria-modal="true"
                 aria-labelledby="forge-legacy-key-title">
                <h2 id="forge-legacy-key-title" style="color:#a9a9a9;">
                    <i class="fa-solid fa-file-import"></i> <?php echo esc_html__('Add legacy key', 'formfabricator'); ?>
                </h2>
                <p style="margin-bottom:4px;">
                    <?php echo esc_html__('Paste the full contents of the saved key file.', 'formfabricator'); ?>
                </p>
                <textarea id="forge-legacy-key-json" rows="10"
                          style="width:100%;font-family:monospace;font-size:12px;resize:vertical;"
                          placeholder='{"plugin":"FormFabricator PDF Seal Key","uuid":"...","key":"...","created_at":"..."}'
                          autocomplete="off" spellcheck="false"></textarea>
                <p style="margin:12px 0 6px;font-weight:600;"><?php echo esc_html__('Status of this key:', 'formfabricator'); ?></p>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;">
                    <input type="radio" name="forge_legacy_status" id="forge-legacy-status-rotated"
                           value="rotated-legacy" checked>
                    <?php echo esc_html__('Rotated — key was regularly replaced', 'formfabricator'); ?>
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="forge_legacy_status" id="forge-legacy-status-compromised"
                           value="compromised-legacy">
                    <?php echo esc_html__('Compromised — key was classified as unsafe', 'formfabricator'); ?>
                </label>
                <p id="forge-legacy-key-error" class="forge-settings-hint"
                   style="color:#b32d2e;display:none;margin-top:10px;"></p>
                <p id="forge-legacy-key-mismatch-msg"
                   style="display:none;margin-top:10px;font-size:12px;color:#50575e;
                          background:#fff8e1;border:1px solid #f0c040;border-radius:4px;padding:8px 10px;">
                </p>
                <div class="forge-reset-actions" style="margin-top:16px;">
                    <button type="button" id="forge-legacy-key-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="forge-legacy-key-force" class="button button-primary"
                            style="display:none;">
                        <i class="fa-solid fa-plus"></i> <?php echo esc_html__('Import anyway', 'formfabricator'); ?>
                    </button>
                    <button type="button" id="forge-legacy-key-confirm" class="button button-primary">
                        <i class="fa-solid fa-plus"></i> <?php echo esc_html__('Add', 'formfabricator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- User-access modal -->
        <div id="forge-access-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-access-modal"
                 role="dialog" aria-modal="true" aria-labelledby="forge-access-modal-title">
                <h2 id="forge-access-modal-title">
                    <i class="fa-solid fa-users-gear"></i> <?php echo esc_html__('User access', 'formfabricator'); ?>
                </h2>
                <p><?php echo esc_html__('Administrators always have full access. User settings override the role setting.', 'formfabricator'); ?></p>

                <div id="forge-access-loading" style="text-align:center;padding:24px 0;">
                    <i class="fa-solid fa-spinner fa-spin"></i> <?php echo esc_html__('Loading…', 'formfabricator'); ?>
                </div>
                <div id="forge-access-content" style="display:none;">
                    <h3 class="forge-access-section-title"><?php echo esc_html__('Roles', 'formfabricator'); ?></h3>
                    <div class="forge-access-scroll">
                        <table class="forge-access-table">
                            <thead><tr id="forge-access-roles-head"></tr></thead>
                            <tbody id="forge-access-roles-body"></tbody>
                        </table>
                    </div>

                    <h3 class="forge-access-section-title"><?php echo esc_html__('User exceptions', 'formfabricator'); ?></h3>
                    <div class="forge-access-scroll" id="forge-access-users-scroll">
                        <table class="forge-access-table">
                            <thead><tr id="forge-access-users-head"></tr></thead>
                            <tbody id="forge-access-users-body">
                                <tr id="forge-access-no-users">
                                    <td colspan="6" class="forge-access-empty">
                                        <?php echo esc_html__('No user exceptions configured.', 'formfabricator'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="forge-access-search-section">
                        <input type="text" id="forge-access-user-search"
                               class="forge-access-search-input"
                               placeholder="<?php echo esc_attr__('Add user…', 'formfabricator'); ?>"
                               autocomplete="off" spellcheck="false">
                        <div id="forge-access-user-dropdown"
                             class="forge-access-dropdown" hidden></div>
                    </div>
                    <p id="forge-access-error"
                       style="color:#b32d2e;display:none;margin-top:8px;font-size:12px;"></p>
                </div>

                <div class="forge-reset-actions" style="margin-top:16px;">
                    <button type="button" id="forge-access-cancel" class="button"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
                    <button type="button" id="forge-access-save" class="button button-primary">
                        <i class="fa-solid fa-floppy-disk"></i> <?php echo esc_html__('Save', 'formfabricator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <?php
        /* Build access data inline so the modal opens instantly.
           This includes the full site user list and every user's/role's
           permission matrix — restricted to real WP admins, since the
           access-management UI itself is admin-only by design (a user with
           only the plugin's own 'settings' capability must not be able to
           read other users' permission grants from page source). */
        $access_inline_data = null;
        if ($is_full_admin) {
            global $wp_roles;
            $access_option = get_option('forge_forms_access', ['roles' => [], 'users' => []]);
            $access_role_names = [];
            foreach ($wp_roles->roles as $_slug => $_data) {
                $access_role_names[$_slug] = translate_user_role($_data['name']);
            }
            $access_all_users = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
            $access_user_list = [];
            foreach ($access_all_users as $_u) {
                $access_user_list[] = [
                    'id'   => (int) $_u->ID,
                    'name' => $_u->display_name ?: $_u->user_login,
                ];
            }
            $access_user_overrides = [];
            foreach (($access_option['users'] ?? []) as $_uid => $_perms) {
                $_ud = get_userdata((int) $_uid);
                $access_user_overrides[] = [
                    'id'    => (int) $_uid,
                    'name'  => $_ud
                        ? ($_ud->display_name ?: $_ud->user_login)
                        : 'Unknown (#' . (int) $_uid . ')',
                    'perms' => is_array($_perms) ? $_perms : [],
                ];
            }
            $access_inline_data = [
                'roles'          => (object) ($access_option['roles'] ?? []),
                'role_names'     => $access_role_names,
                'user_overrides' => $access_user_overrides,
                'user_list'      => $access_user_list,
            ];
        }
        // Determine which step the blocker should open on.
        // Use the raw DB option — isEncryptionEnabled() also requires the constant, which may not
        // be present yet (that is exactly the 'masterkey' state we need to detect).
        $enc_chosen = get_option('forge_forms_seal_encryption') === 'enabled';
        $mk_defined = defined('FORGE_SEAL_MASTER_KEY') && (string) FORGE_SEAL_MASTER_KEY !== '';
        if (!$setup_done_early && $enc_chosen) {
            $setup_state = $mk_defined ? 'ready' : 'masterkey';
        } else {
            $setup_state = 'choose';
        }

        wp_localize_script(
            'forge-forms-admin-settings',
            'ForgeSettingsPage',
            [
            'i18n'   => self::settingsI18n(),
            'nonces' => [
                'factoryReset' => wp_create_nonce('forge_factory_reset'),
                'rotate'       => $rotate_nonce,
            ],
            'data'   => [
                'isFullAdmin'     => $is_full_admin,
                'setupDone'       => $setup_done,
                'setupNonce'      => $setup_nonce,
                'legacyKeyNonce'  => wp_create_nonce('forge_add_legacy_key'),
                'pendingDownload' => $is_full_admin ? ($pending_download ?? null) : null,
                'accessNonce'     => $is_full_admin ? wp_create_nonce('forge_access_settings') : '',
                'accessData'      => $is_full_admin ? $access_inline_data : null,
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
            'minChars'            => __('Min. 12 characters', 'formfabricator'),
            'upperLetter'         => __('Uppercase letter', 'formfabricator'),
            'lowerLetter'         => __('Lowercase letter', 'formfabricator'),
            'digit'               => __('Digit', 'formfabricator'),
            'specialChar'         => __('Special character', 'formfabricator'),
            'success'             => __('Success', 'formfabricator'),
            'error'               => __('Error', 'formfabricator'),
            'networkError'        => __('Network error', 'formfabricator'),
            'permList'            => __('List', 'formfabricator'),
            'permForms'           => __('Forms', 'formfabricator'),
            'permPdfLayout'       => __('PDF Layout', 'formfabricator'),
            'permVerifier'        => __('Verifier', 'formfabricator'),
            'permSettings'        => __('Settings', 'formfabricator'),
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
        if (!\ForgeForms\Plugin::userCan('settings')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_forms_settings', 'forge_settings_nonce');
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
            get_option('forge_forms_from_email', ''),
            get_option('forge_forms_from_name', ''),
            get_option('forge_forms_recaptcha_site_key', ''),
            get_option('forge_forms_recaptcha_secret_key', ''),
            get_option('forge_forms_hover_color', '#1d2327'),
            get_option('forge_forms_accent_color', '#f59e0b'),
            get_option('forge_forms_border_color', '#c9cdd4'),
            get_option('forge_forms_admin_accent', '#2271b1'),
            get_option('forge_forms_field_layout', 'block'),
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
        if (!\ForgeForms\Plugin::userCan('settings')) {
            return;
        }

        if (!isset($_POST['forge_settings_nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['forge_settings_nonce'])), 'forge_forms_settings')
        ) {
            return;
        }

        // Optimistic-concurrency guard: reject a save if options changed since this snapshot.
        $expected_snapshot = isset($_POST['forge_settings_snapshot'])
            ? sanitize_text_field(wp_unslash($_POST['forge_settings_snapshot']))
            : '';
        if ($expected_snapshot !== '' && $expected_snapshot !== self::settingsSnapshot()) {
            self::$last_save_error = __('Settings were changed elsewhere since this page loaded. Please reload and try again.', 'formfabricator');
            return;
        }

        $from_email_input = \ForgeForms\Utils\Sanitize::str(sanitize_email(wp_unslash($_POST['forge_cfg_a'] ?? '')));
        if ($from_email_input !== '') {
            update_option('forge_forms_from_email', $from_email_input);
        }
        $from_name_input = \ForgeForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['forge_cfg_b'] ?? '')));
        if ($from_name_input !== '') {
            update_option('forge_forms_from_name', $from_name_input);
        }
        // reCAPTCHA keys are hidden from non-full-admins in the UI (the
        // Security card only renders for $is_full_admin) — enforce the same
        // boundary server-side so a user with only the plugin's 'settings'
        // capability can't set them via a raw POST to this handler.
        if (current_user_can('manage_options')) {
            $site_key = \ForgeForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['recaptcha_site'] ?? '')));
            update_option('forge_forms_recaptcha_site_key', $site_key);
            $secret_key = \ForgeForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['recaptcha_secret'] ?? '')));
            update_option('forge_forms_recaptcha_secret_key', $secret_key);
        }

        $hover        = \ForgeForms\Utils\Sanitize::str(sanitize_hex_color(wp_unslash($_POST['hover_color']    ?? ''))) ?: '#1d2327';
        $accent       = \ForgeForms\Utils\Sanitize::str(sanitize_hex_color(wp_unslash($_POST['accent_color']   ?? ''))) ?: '#f59e0b';
        $border       = \ForgeForms\Utils\Sanitize::str(sanitize_hex_color(wp_unslash($_POST['border_color']   ?? ''))) ?: '#c9cdd4';
        $admin_accent = \ForgeForms\Utils\Sanitize::str(sanitize_hex_color(wp_unslash($_POST['admin_accent']   ?? ''))) ?: '#2271b1';
        update_option('forge_forms_hover_color', $hover);
        update_option('forge_forms_accent_color', $accent);
        update_option('forge_forms_border_color', $border);
        update_option('forge_forms_admin_accent', $admin_accent);

        $layout_mode = sanitize_key(wp_unslash($_POST['field_layout_mode'] ?? 'block'));
        update_option(
            'forge_forms_field_layout',
            $layout_mode === 'inline' ? 'inline' : 'block'
        );
    }

    /**
     * AJAX handler that resets all plugin settings (and optionally forms).
     *
     * @return void
     */
    public static function handleFactoryReset(): void
    {
        $allowed = \ForgeForms\Plugin::userCan('settings')
            && current_user_can('manage_options');
        if (!$allowed) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_factory_reset', 'nonce');

        $options = [
            'forge_forms_from_email',
            'forge_forms_from_name',
            'forge_forms_recaptcha_site_key',
            'forge_forms_recaptcha_secret_key',
            'forge_forms_hover_color',
            'forge_forms_accent_color',
            'forge_forms_border_color',
            'forge_forms_admin_accent',
            'forge_forms_pdf_settings',
            'forge_forms_pdf_layout',
            'forge_forms_field_layout',
            'forge_forms_access',
        ];
        foreach ($options as $opt) {
            delete_option($opt);
        }

        if (!empty($_POST['del_forms']) && $_POST['del_forms'] === '1') {
            $ids = get_posts(
                [
                'post_type'      => 'forge_form',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                ]
            );
            foreach ($ids as $id) {
                wp_delete_post((int)$id, true);
            }
            delete_option('forge_form_selects');
        }

        wp_send_json_success();
    }

    /**
     * AJAX handler to save PDF attachment settings per notification.
     *
     * @return void
     */
    public static function savePdfSettings(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_forms_admin_nonce', 'nonce');

        $raw = json_decode(\ForgeForms\Utils\Sanitize::str(sanitize_textarea_field(wp_unslash($_POST['settings'] ?? ''))), true);
        if (!is_array($raw)) {
            wp_send_json_error(['message' => 'Invalid data'], 400);
        }

        $saved = get_option('forge_forms_pdf_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        // Key format "form_id|template_slug" mirrors the lookup key shouldAttachPdf() builds
        // when deciding whether to attach a PDF for a given submission
        foreach ($raw as $key => $value) {
            if (preg_match('/^\d+\|[\w-]+$/', $key)) {
                $saved[$key] = ((int)$value === 1) ? 1 : 0;
            }
        }

        update_option('forge_forms_pdf_settings', $saved);
        wp_send_json_success(['saved' => $raw]);
    }

    /**
     * AJAX handler to rotate the PDF seal key with a password.
     *
     * @return void
     */
    public static function handleRotateKey(): void
    {
        $allowed = \ForgeForms\Plugin::userCan('settings')
            && current_user_can('manage_options');
        if (!$allowed) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_rotate_key', 'nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password material: only unslashed, never output/queried, used solely for hash_equals()-style comparison and hashing below; generic sanitize_text_field() would silently alter the user's intended password.
        $password = (string) wp_unslash($_POST['key_password']         ?? '');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password material: only unslashed, never output/queried, used solely for comparison against $password above.
        $confirm  = (string) wp_unslash($_POST['key_password_confirm'] ?? '');
        $compromised = !empty($_POST['key_compromised']) && $_POST['key_compromised'] === '1';

        if ($password === '' || $password !== $confirm) {
            wp_send_json_error(['message' => __('Passwords do not match.', 'formfabricator')]);
            return;
        }

        $errors = \ForgeForms\PDF\HashSeal::validatePassword($password);
        if (!empty($errors)) {
            wp_send_json_error(['message' => implode(' ', $errors)]);
            return;
        }

        $new_key = \ForgeForms\PDF\HashSeal::rotateKey($password, $compromised, true);
        wp_send_json_success(
            [
            'message'    => __('Key rotated successfully.', 'formfabricator'),
            'key_uuid'   => $new_key['uuid'],
            'key_value'  => $new_key['key'],
            'created_at' => $new_key['created_at'],
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
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_seal_setup', 'nonce');
        update_option('forge_forms_seal_encryption', 'disabled', false);
        update_option('forge_forms_seal_setup_done', true, false);
        \ForgeForms\PDF\HashSeal::getCurrentKeyId(); // ensure initial key exists
        $dl = \ForgeForms\PDF\HashSeal::claimPendingDownload();
        if (!$dl) {
            wp_send_json_error(['message' => __('No pending key download found.', 'formfabricator')]);
            return;
        }
        wp_send_json_success($dl);
    }

    /**
     * AJAX handler that generates and returns the master key define line for wp-config.php.
     *
     * @return void
     */
    public static function handleSetupGetMasterKey(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_seal_setup', 'nonce');
        // Commit the encryption choice immediately — the user cannot go back to Standard.
        update_option('forge_forms_seal_encryption', 'enabled', false);
        $master_hex = bin2hex(random_bytes(32));
        set_transient('forge_setup_master_key_' . get_current_user_id(), $master_hex, 600);
        // Invalidate OPcache so the next request (confirm) re-reads wp-config.php from disk.
        // wp-config.php can live one directory above ABSPATH — same resolution wp-load.php itself uses.
        if (function_exists('opcache_invalidate')) {
            $wp_config_path = file_exists(\ABSPATH . 'wp-config.php')
                ? \ABSPATH . 'wp-config.php'
                : dirname(\ABSPATH) . '/wp-config.php';
            if (file_exists($wp_config_path)) {
                opcache_invalidate($wp_config_path, true);
            }
        }
        wp_send_json_success(
            [
            'define_line' => "define('FORGE_SEAL_MASTER_KEY', '" . $master_hex . "');",
            ]
        );
    }

    /**
     * AJAX handler that resets the encryption mode choice during setup.
     *
     * @return void
     */
    public static function handleSetupResetChoice(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_seal_setup', 'nonce');
        delete_option('forge_forms_seal_encryption');
        delete_transient('forge_setup_master_key_' . get_current_user_id());
        wp_send_json_success();
    }

    /**
     * AJAX handler that checks the master key and completes encrypted setup.
     *
     * @return void
     */
    public static function handleSetupConfirmSecure(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_seal_setup', 'nonce');

        if (!defined('FORGE_SEAL_MASTER_KEY') || (string) FORGE_SEAL_MASTER_KEY === '') {
            wp_send_json_error(
                [
                'message' => __('FORGE_SEAL_MASTER_KEY not found. Please check wp-config.php and try again.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        // A missing/expired transient must be rejected, not silently skipped — otherwise
        // this check would accept whatever FORGE_SEAL_MASTER_KEY currently is instead of
        // requiring it to match the value this plugin itself issued during setup.
        $expected = get_transient('forge_setup_master_key_' . get_current_user_id());
        if (!$expected) {
            wp_send_json_error(
                [
                'message' => __('Setup session expired. Please start again.', 'formfabricator'),
                ]
            );
            return;
        }
        if (!hash_equals($expected, strtolower((string) FORGE_SEAL_MASTER_KEY))) {
            wp_send_json_error(
                [
                'message' => __('The entered master key does not match the expected value. Please check wp-config.php.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        $was_setup_done = (bool) get_option('forge_forms_seal_setup_done');

        delete_transient('forge_setup_master_key_' . get_current_user_id());
        update_option('forge_forms_seal_encryption', 'enabled', false);
        update_option('forge_forms_seal_setup_done', true, false);

        // Encrypt any keys that were generated before setup completed.
        \ForgeForms\PDF\HashSeal::encryptExistingKeys();

        // Ensure active key exists (may have been generated during encryption pass).
        \ForgeForms\PDF\HashSeal::getCurrentKeyId();
        $dl = \ForgeForms\PDF\HashSeal::claimPendingDownload();

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
        $allowed = \ForgeForms\Plugin::userCan('settings')
            && current_user_can('manage_options');
        if (!$allowed) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_add_legacy_key', 'nonce');

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

        $uuid = sanitize_text_field(\ForgeForms\Utils\Sanitize::str($parsed['uuid'] ?? null));
        $key  = sanitize_text_field(\ForgeForms\Utils\Sanitize::str($parsed['key']  ?? null));

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

        // Guard: hard-reject only when the exact same payload already exists.
        $incoming_created = sanitize_text_field(\ForgeForms\Utils\Sanitize::str($parsed['created_at'] ?? null));

        $active_raw = get_option('forge_forms_seal_key');
        if ($active_raw) {
            $active_rec = json_decode((string) $active_raw, true);
            $active_dup = is_array($active_rec)
                && ($active_rec['uuid'] ?? '') === $uuid
                && ($active_rec['key']  ?? '') === $key;
            if ($active_dup) {
                wp_send_json_error(['message' => __('This key already exists as the active key.', 'formfabricator')]); // phpcs:ignore Generic.Files.LineLength
                return;
            }
        }

        foreach (\ForgeForms\PDF\HashSeal::getHistory() as $entry) {
            $history_dup = ($entry['uuid']       ?? '') === $uuid
                && ($entry['key']       ?? '') === $key
                && ($entry['retired_at'] ?? '') === $incoming_created;
            if ($history_dup) {
                wp_send_json_error(
                    [
                    'message' => __('This key already exists in the history (identical entry).', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                    ]
                );
                return;
            }
        }

        // Warn if the JSON plugin field doesn't match this plugin.
        $plugin_field    = isset($parsed['plugin']) ? (string) $parsed['plugin'] : '';
        $expected_plugin = 'FormFabricator PDF Seal Key';
        $confirm_mismatch = (bool) (sanitize_text_field(wp_unslash($_POST['confirm_mismatch'] ?? '')) === '1');
        if ($plugin_field !== $expected_plugin && !$confirm_mismatch) {
            wp_send_json_error(
                [
                'code'    => 'plugin_mismatch',
                'message' => __('Plugin name does not match.', 'formfabricator'),
                'text'    => sprintf(
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- not HTML: this JSON field is rendered client-side via createTextNode()/textContent (see FormSettings.php JS), never innerHTML, so esc_html() here would corrupt the text (double-encode) instead of protecting anything.
                    /* translators: %s: the plugin field value from the uploaded JSON */
                    __('The "plugin" field reads "%s" — this does not appear to be a key from this plugin.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                    $plugin_field
                ),
                'confirm' => __('Are you sure you want to import this key?', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        $raw_status = sanitize_text_field(wp_unslash($_POST['key_status'] ?? ''));
        $allowed    = ['rotated-legacy', 'compromised-legacy'];
        $key_status = in_array($raw_status, $allowed, true) ? $raw_status : 'rotated-legacy';

        \ForgeForms\PDF\HashSeal::addLegacyKey(
            $uuid,
            $key,
            $incoming_created,
            $key_status,
            true
        );
        wp_send_json_success(['message' => __('Legacy key added successfully.', 'formfabricator')]);
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
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_access_settings', 'nonce');

        global $wp_roles;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is sanitize_key()'d and each value passed through self::sanitizePerms() below before use.
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

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is (int)-cast and validated via get_userdata(), each value passed through self::sanitizePerms() below before use.
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

        update_option('forge_forms_access', ['roles' => $roles, 'users' => $users]);
        wp_send_json_success();
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
        $saved = get_option('forge_forms_pdf_settings', []);
        return !empty($saved[$form_id . '|' . $slug]);
    }
}
