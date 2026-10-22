<?php

/**
 * The Settings page's PDF seal-key part: first-run setup, the Security tile, its dialogs and their handlers.
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
 * PDF seal-key management on the Settings page: the first-run choice of key storage, the Security tile, key rotation,
 * the key list, the key backup download, the switch to encrypted storage and legacy-key import.
 *
 * The keys themselves are HashSeal's; this is their admin side. Every handler is for administrators (manage_options)
 * who also hold the plugin's settings permission.
 */
class SealKeySettings
{
    /**
     * Registers the seal-key AJAX handlers.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('wp_ajax_fabricator_forms_rotate_key', [self::class, 'handleRotateKey']);
        add_action('wp_ajax_fabricator_forms_setup_keep_default', [self::class, 'handleSetupKeepDefault']);
        add_action('wp_ajax_fabricator_forms_setup_get_master_key', [self::class, 'handleSetupGetMasterKey']);
        add_action('wp_ajax_fabricator_forms_upgrade_get_master_key', [self::class, 'handleUpgradeGetMasterKey']);
        add_action('wp_ajax_fabricator_forms_setup_confirm_secure', [self::class, 'handleSetupConfirmSecure']);
        add_action('wp_ajax_fabricator_forms_setup_reset_choice', [self::class, 'handleSetupResetChoice']);
        add_action('wp_ajax_fabricator_forms_add_legacy_key', [self::class, 'handleAddLegacyKey']);
        add_action('wp_ajax_fabricator_forms_peek_key_download', [self::class, 'handlePeekKeyDownload']);
        add_action('wp_ajax_fabricator_forms_confirm_key_download', [self::class, 'handleConfirmKeyDownload']);
    }

    /**
     * Redirects admins to settings page until PDF seal setup is complete.
     *
     * @return void
     */
    public static function maybeSealSetupRedirect(): void
    {
        // The admin page being opened, as WordPress resolved it from the request before admin_init.
        global $plugin_page;

        if (!current_user_can('manage_options')) {
            return;
        }
        if (get_option('fabricator_forms_seal_setup_done', false)) {
            return;
        }
        if (wp_doing_ajax() || !isset($_SERVER['REQUEST_METHOD']) || sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) !== 'GET') {
            return;
        }
        $current_page = is_string($plugin_page) ? $plugin_page : '';
        // Only redirect within FormFabricator pages, not the whole WP admin. The verifier among them: before setup it has
        // no key to check a PDF against, so every PDF would come back unknown.
        if (strncmp($current_page, 'fabricator-forms', 16) !== 0) {
            return;
        }
        if ($current_page === 'fabricator-forms-settings') {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=fabricator-forms-settings'));
        exit;
    }

    /**
     * Prints the first-run overlay that asks how seal keys are stored, until the setup is done.
     *
     * @return void
     */
    public static function renderSetupBlocker(): void
    {
        $setup_done_early = (bool) get_option('fabricator_forms_seal_setup_done', false);
        ?>
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
        <?php
    }

    /**
     * Prints the Security tile: how the keys are stored, and the key actions. Users without manage_options get it
     * hidden rather than left out, so the settings scripts find its buttons; the handlers refuse them anyway.
     *
     * @param bool $is_full_admin Whether the current user holds manage_options.
     * @return void
     */
    public static function renderSecurityTile(bool $is_full_admin): void
    {
        $setup_done   = (bool) get_option('fabricator_forms_seal_setup_done', false);
        $enc_enabled  = \FabricatorForms\PDF\HashSeal::isEncryptionEnabled();
        // Encrypted storage chosen, but the master key is gone from wp-config.php: nothing can be decrypted. This
        // state gets its own notice; the upgrade button would only answer "already enabled".
        $enc_orphaned = !$enc_enabled && get_option('fabricator_forms_seal_encryption') === 'enabled';
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
        <?php
    }

    /**
     * Prints the key-rotation dialog.
     *
     * @param bool $is_full_admin Whether the current user holds manage_options.
     * @return void
     */
    public static function renderRotationModal(bool $is_full_admin): void
    {
        ?>
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
        <?php
    }

    /**
     * Prints the dialog that lists the active and retired keys. Only for users with manage_options.
     *
     * @return void
     */
    public static function renderKeyViewModal(): void
    {
        $key_history = \FabricatorForms\PDF\HashSeal::getHistoryFingerprints();
        // Active key isn't in history (retired keys only) — fetched separately so a rotated-away compromised key doesn't look current.
        $active_key  = \FabricatorForms\PDF\HashSeal::getActiveKeyInfo();
        ?>
        <!-- Key-view modal -->
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

                        $legacy = false;
                        if ($sta === 'rotated-legacy') {
                            $badge_cls = 'fabricator-key-badge fabricator-key-badge--rotated';
                            $badge_lbl = __('ROTATED', 'formfabricator');
                            $legacy    = true;
                        } elseif ($sta === 'compromised-legacy') {
                            $badge_cls = 'fabricator-key-badge fabricator-key-badge--compromised';
                            $badge_lbl = __('COMPROMISED', 'formfabricator');
                            $legacy    = true;
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
                                    <span class="<?php echo esc_attr($badge_cls); ?>"><?php echo esc_html($badge_lbl); ?></span>
                                    <?php if ($legacy) : ?>
                                        <span class="fabricator-key-badge fabricator-key-badge--legacy"><?php esc_html_e('LEGACY', 'formfabricator'); ?></span>
                                    <?php endif; ?>
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
        <?php
    }

    /**
     * Prints the dialogs for backing up a new key, setting up a master key and importing a legacy key.
     *
     * @return void
     */
    public static function renderBackupModals(): void
    {
        ?>
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
        <?php
    }

    /**
     * Hands assets/js/admin-settings-keys.js its nonces, strings and the setup state. Called while the page renders.
     *
     * @param bool $is_full_admin Whether the current user holds manage_options.
     * @return void
     */
    public static function localize(bool $is_full_admin): void
    {
        $setup_done = (bool) get_option('fabricator_forms_seal_setup_done', false);
        // Boolean only: the key itself is fetched when the modal opens, so it never reaches page source or bfcache.
        $has_pending_download = ($setup_done && $is_full_admin)
            && \FabricatorForms\PDF\HashSeal::peekPendingDownload() !== null;
        // The option alone, not isEncryptionEnabled(): the 'masterkey' state is the constant still missing.
        $enc_chosen = get_option('fabricator_forms_seal_encryption') === 'enabled';
        $mk_defined = defined('FABRICATOR_SEAL_MASTER_KEY') && (string) FABRICATOR_SEAL_MASTER_KEY !== '';
        if (!$setup_done && $enc_chosen) {
            $setup_state = $mk_defined ? 'ready' : 'masterkey';
        } else {
            $setup_state = 'choose';
        }

        wp_localize_script(
            'fabricator-forms-admin-settings-keys',
            'FabricatorSealKeys',
            [
            'i18n'   => self::i18n(),
            'nonces' => [
                'rotate'          => wp_create_nonce('fabricator_rotate_key'),
                'confirmDownload' => wp_create_nonce('fabricator_forms_admin_nonce'),
            ],
            'data'   => [
                'setupNonce'         => wp_create_nonce('fabricator_seal_setup'),
                'legacyKeyNonce'     => wp_create_nonce('fabricator_forms_add_legacy_key'),
                // Flag only, never the key — see the comment where $has_pending_download is set.
                'hasPendingDownload' => $has_pending_download,
                'setupState'         => $setup_state,
            ],
            ]
        );
    }

    /**
     * Translated strings used by assets/js/admin-settings-keys.js.
     *
     * @return array
     */
    private static function i18n(): array
    {
        return [
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
        ];
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_rotate_key', 'nonce');

        $compromised = isset($_POST['key_compromised']) && sanitize_text_field(wp_unslash($_POST['key_compromised'])) === '1';
        $master_key_lost = isset($_POST['master_key_lost']) && sanitize_text_field(wp_unslash($_POST['master_key_lost'])) === '1';

        try {
            $new_key = \FabricatorForms\PDF\HashSeal::rotateKey($compromised, $master_key_lost);
        } catch (\Exception $e) {
            // Caught, so a failed rotation answers as JSON, not a bare 500 (with a stack trace under WP_DEBUG_DISPLAY).
            \FabricatorForms\fabricator_log('FabricatorForms SealKeySettings: seal key rotation failed — ' . $e->getMessage());
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_seal_setup', 'nonce');
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_seal_setup', 'nonce');
        self::refuseWithoutOpenssl();
        // Setup-only, like handleSetupKeepDefault(): the setup nonce is issued on every Settings load, so without this a
        // stale or replayed request after setup would flip encryption on and hand out a master key nothing uses.
        if (get_option('fabricator_forms_seal_setup_done', false)) {
            wp_send_json_error(['message' => __('Seal key setup is already complete.', 'formfabricator')], 409);
            return;
        }
        // Commit the encryption choice immediately — the user cannot go back to Standard.
        update_option('fabricator_forms_seal_encryption', 'enabled', false);
        // A master key already in wp-config.php is confirmed, never replaced: on a network every site shares the file,
        // and other sites' keys may be encrypted with it.
        if (\FabricatorForms\PDF\HashSeal::masterKeyConfigured()) {
            $transient = 'fabricator_setup_master_key_' . get_current_user_id();
            // A key issued in this session stays the one to confirm; only without one is the configured key taken.
            if (!get_transient($transient)) {
                set_transient($transient, hash('sha256', strtolower((string) FABRICATOR_SEAL_MASTER_KEY)), 600);
                // Keys stored before setup can only have been planted while the constant was set, so none is offered
                // for encryption (decryptKey() keeps refusing them).
                set_transient(self::OFFERED_KEYS_TRANSIENT . get_current_user_id(), [], 600);
            }
            wp_send_json_success(['existing' => true]);
            return;
        }
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_seal_setup', 'nonce');
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
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- wp-config.php, looked up where wp-load.php looks.
        $wp_config_path = file_exists(\ABSPATH . 'wp-config.php')
            ? \ABSPATH . 'wp-config.php'
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- wp-config.php, looked up where wp-load.php looks.
            : dirname(\ABSPATH) . '/wp-config.php';
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- wp-config.php, looked up where wp-load.php looks.
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_seal_setup', 'nonce');
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_seal_setup', 'nonce');
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

        // Checked before anything uses it: a mistyped line answers with what to fix, not with a failure to encrypt.
        if (preg_match('/^[0-9a-fA-F]{64}$/D', (string) FABRICATOR_SEAL_MASTER_KEY) !== 1) {
            wp_send_json_error(
                [
                'message' => __('FABRICATOR_SEAL_MASTER_KEY in wp-config.php must be 64 hexadecimal characters. Please check the line.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
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
            $posted = isset($_POST['keys']) && is_array($_POST['keys'])
                ? map_deep(wp_unslash($_POST['keys']), 'sanitize_text_field')
                : [];
            $only = array_values(array_intersect($offered, array_filter($posted, 'is_string')));
        }
        try {
            \FabricatorForms\PDF\HashSeal::encryptExistingKeys($only);
        } catch (\RuntimeException $e) {
            \FabricatorForms\fabricator_log('FabricatorForms SealKeySettings: encrypting existing keys failed — ' . $e->getMessage());
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_forms_add_legacy_key', 'nonce');

        $raw = isset($_POST['key_json']) ? sanitize_textarea_field(wp_unslash($_POST['key_json'])) : '';
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

        // By uuid only, matching HashSeal::addLegacyKey(): the stored active key may be encrypted, so it can't be compared
        // with the plaintext import, and a second key filed under an existing uuid would make verification ambiguous.
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
        $confirm_mismatch = isset($_POST['confirm_mismatch']) && sanitize_text_field(wp_unslash($_POST['confirm_mismatch'])) === '1';
        if ($plugin_field !== $expected_plugin && !$confirm_mismatch) {
            wp_send_json_error(
                [
                'code'    => 'plugin_mismatch',
                'message' => __('Plugin name does not match.', 'formfabricator'),
                'text'    => sprintf(
                    /* translators: %s: the plugin field value from the uploaded JSON */
                    __('The "plugin" field reads "%s" — this does not appear to be a key from this plugin.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                    $plugin_field
                ),
                'confirm' => __('Are you sure you want to import this key?', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        $raw_status = isset($_POST['key_status']) ? sanitize_text_field(wp_unslash($_POST['key_status'])) : '';
        $allowed    = ['rotated-legacy', 'compromised-legacy'];
        $key_status = in_array($raw_status, $allowed, true) ? $raw_status : 'rotated-legacy';

        try {
            \FabricatorForms\PDF\HashSeal::addLegacyKey(
                $uuid,
                $key,
                $incoming_created,
                $key_status
            );
        } catch (\RuntimeException $e) {
            \FabricatorForms\fabricator_log('FabricatorForms SealKeySettings: legacy key import failed — ' . $e->getMessage());
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
}
