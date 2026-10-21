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
        add_action('wp_ajax_fabricator_save_general_settings', [self::class, 'handleSaveGeneralSettings']);
        // The page's seal-key and user-access parts register their own handlers.
        SealKeySettings::init();
        AccessSettings::init();
    }

    /**
     * Appends a CSS class on the settings page.
     *
     * @param string $classes Existing admin body classes.
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        // The admin page being shown, as WordPress resolved it from the request.
        global $plugin_page;
        if ($plugin_page === 'fabricator-forms-settings') {
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
        if (\FabricatorForms\Capabilities::userCan('settings')) {
            $hook = add_submenu_page(
                'fabricator-forms',
                __('FormFabricator Settings', 'formfabricator'),
                __('Settings', 'formfabricator'),
                \FabricatorForms\Capabilities::ACCESS_CAP_PREFIX . 'settings',
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
        if (!isset($_POST['fabricator_settings_nonce']) || !\FabricatorForms\Capabilities::userCan('settings')) {
            return;
        }
        if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST['fabricator_settings_nonce'])), 'fabricator_forms_settings')) {
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
        if (!\FabricatorForms\Capabilities::userCan('settings')) {
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
        $particles         = get_option('fabricator_forms_particles', 'animated') === 'static' ? 'static' : 'animated';
        $wp_admin_email    = get_option('admin_email');
        // Plugin-access-only users (not real WP admins) don't see PDF-seal/recaptcha/user-access tiles.
        $is_full_admin = current_user_can('manage_options');

        // Advisory notice only — saveGeneralSettings()'s snapshot-hash check is the real guard.
        $lock_owner_name = \FabricatorForms\Utils\Storage\AdminLock::claim('settings');
        \FabricatorForms\Utils\Storage\AdminLock::localize('settings');
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
        <?php // Heartbeat lock notice JS lives in assets/js/admin-lock.js, enqueued in Utils/Assets.php. ?>

        <?php SealKeySettings::renderSetupBlocker(); ?>

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
                                     browser extensions and form caches could all read it. Submitting it empty keeps the saved one. A
                                     text field, not a password one, so what is pasted can be checked before saving. */ ?>
                            <input type="text" id="recaptcha_secret" name="recaptcha_secret"
                                   value=""
                                   placeholder="<?php echo (string) $recaptcha_secret !== '' ? esc_attr__('Saved (leave empty to keep)', 'formfabricator') : '6Le…'; ?>"
                                   autocomplete="off" data-lpignore="true"
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
                            <?php $wide_proxies = \FabricatorForms\Utils\Security\ClientIp::wideTrustedEntries(); ?>
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
                                'Your privacy policy needs to say what happens to form submissions, and, if a CAPTCHA field uses Google reCAPTCHA, what Google receives.',
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
                                    <input type="radio" name="particles" value="animated" <?php checked($particles, 'animated'); ?>>
                                    <span class="fabricator-card-radio-head">
                                        <i class="fa-solid fa-circle-nodes"></i>
                                        <strong><?php echo esc_html__('Animated', 'formfabricator'); ?></strong>
                                    </span>
                                </label>
                                <label class="fabricator-card-radio">
                                    <input type="radio" name="particles" value="static" <?php checked($particles, 'static'); ?>>
                                    <span class="fabricator-card-radio-head">
                                        <i class="fa-solid fa-image"></i>
                                        <strong><?php echo esc_html__('Static', 'formfabricator'); ?></strong>
                                    </span>
                                </label>
                            </div>
                            <p class="fabricator-settings-hint">
                                <?php echo esc_html__('When your system asks for reduced motion, it is always static.', 'formfabricator'); ?>
                            </p>
                        </div>
                    </div>

                    <?php SealKeySettings::renderSecurityTile($is_full_admin); ?>

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

        <?php SealKeySettings::renderRotationModal($is_full_admin); ?>

        <?php if ($is_full_admin) : ?>
            <?php SealKeySettings::renderKeyViewModal(); ?>

        <!-- Privacy policy text modal -->
        <div id="fabricator-privacy-text-overlay" class="fabricator-reset-overlay" hidden>
            <div class="fabricator-modal-box fabricator-privacy-text-modal"
                 role="dialog" aria-modal="true" aria-labelledby="fabricator-privacy-text-title">
                <h2 id="fabricator-privacy-text-title" class="fabricator-key-view-title">
                    <i class="fa-solid fa-user-shield"></i> <?php echo esc_html__('Privacy Policy Text', 'formfabricator'); ?>
                </h2>
                <?php
                // What the text below covers: the reCAPTCHA section only while a site key is set.
                $privacy_disclaimer_sentences = [
                    \FabricatorForms\Admin\PrivacyPolicy::coversRecaptcha()
                        ? __(
                            'Here is example text for your privacy policy: what happens to a form submission, and what Google reCAPTCHA receives.',
                            'formfabricator'
                        )
                        : __(
                            'Here is example text for your privacy policy: what happens to a form submission. A section on Google reCAPTCHA is added once a reCAPTCHA site key is set.',
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
                $privacy_langs = [];
                $privacy_texts = [];
                foreach (\FabricatorForms\Admin\PrivacyPolicy::languages() as $lang_code => $lang) {
                    $privacy_langs[$lang_code] = $lang['name'];
                    $privacy_texts[$lang_code] = $lang['text'];
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

        <?php SealKeySettings::renderBackupModals(); ?>

        <?php AccessSettings::renderModal(); ?>

        <?php
        wp_localize_script(
            'fabricator-forms-admin-settings',
            'FabricatorSettingsPage',
            [
            'i18n'   => self::settingsI18n(),
            'nonces' => [
                'factoryReset' => wp_create_nonce('fabricator_factory_reset'),
            ],
            'data'   => [
                'isFullAdmin' => $is_full_admin,
            ],
            ]
        );
        SealKeySettings::localize($is_full_admin);
        AccessSettings::localize($is_full_admin);
        ?>

        <?php // Settings page JS: assets/js/admin-settings.js, admin-settings-keys.js and admin-settings-access.js. ?>
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
            'networkError'        => __('Network error', 'formfabricator'),
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability('settings');
        check_ajax_referer('fabricator_forms_settings', 'fabricator_settings_nonce');
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
            get_option('fabricator_forms_particles', 'animated'),
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
        if (!\FabricatorForms\Capabilities::userCan('settings')) {
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
            [$proxy_list, $proxy_invalid] = \FabricatorForms\Utils\Security\ClientIp::normalizeProxyList((is_string($_POST['trusted_proxies'] ?? null) ? sanitize_textarea_field(wp_unslash($_POST['trusted_proxies'])) : ''));
            if ($proxy_invalid !== []) {
                // translators: %s: comma-separated list of the rejected entries.
                self::$last_save_error = sprintf(__('Not saved: these trusted proxy entries are not valid IP addresses or CIDR ranges: %s', 'formfabricator'), implode(', ', $proxy_invalid));
                return;
            }
        }

        // An emptied field clears the option, as its hint promises. Only fields in the POST are touched, and an invalid
        // address is refused rather than clearing the saved one.
        if (isset($_POST['fabricator_cfg_a'])) {
            $from_email_raw   = is_string($_POST['fabricator_cfg_a']) ? sanitize_text_field(wp_unslash($_POST['fabricator_cfg_a'])) : '';
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
            $from_name_input = (is_string($_POST['fabricator_cfg_b'] ?? null) ? sanitize_text_field(wp_unslash($_POST['fabricator_cfg_b'])) : '');
            if ($from_name_input !== '') {
                update_option('fabricator_forms_from_name', $from_name_input, false);
            } else {
                delete_option('fabricator_forms_from_name');
            }
        }
        // reCAPTCHA keys are hidden from non-full-admins in the UI; enforce that boundary server-side too against a raw POST.
        if (current_user_can('manage_options')) {
            // Only when the request carries the field, like the sender fields above: a save without it would clear the key.
            if (isset($_POST['recaptcha_site'])) {
                $site_key = (is_string($_POST['recaptcha_site'] ?? null) ? sanitize_text_field(wp_unslash($_POST['recaptcha_site'])) : '');
                update_option('fabricator_forms_recaptcha_site_key', $site_key, false);
            }
            // Write-only field: empty means "keep the saved secret"; removing it takes the explicit checkbox.
            $secret_key = (is_string($_POST['recaptcha_secret'] ?? null) ? sanitize_text_field(wp_unslash($_POST['recaptcha_secret'])) : '');
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
            $colour = (is_string($_POST[$field] ?? null) ? sanitize_hex_color(wp_unslash($_POST[$field])) : '') ?: $default;
            update_option($option, $colour, false);
        }

        // Only when the request carries it, as for the colours above.
        if (isset($_POST['particles'])) {
            update_option('fabricator_forms_particles', sanitize_key(wp_unslash($_POST['particles'])) === 'static' ? 'static' : 'animated', false);
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
        \FabricatorForms\Utils\Security\AjaxGuard::capability(static fn() => \FabricatorForms\Capabilities::userCan('settings') && current_user_can('manage_options'));
        check_ajax_referer('fabricator_factory_reset', 'nonce');

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

        if (isset($_POST['del_forms']) && sanitize_text_field(wp_unslash($_POST['del_forms'])) === '1') {
            // A page at a time, not posts_per_page => -1: each round deletes what it read, so the next query returns
            // the ones still left. The counter only stops a runaway loop if a delete is ever refused.
            for ($round = 0; $round < 1000; $round++) {
                $ids = get_posts(
                    [
                    'post_type'      => 'fabricator_form',
                    'posts_per_page' => \FabricatorForms\Form\FormModel::QUERY_BATCH,
                    // Explicit list, as in uninstall.php: get_posts() defaults to 'publish' and 'any' still omits trash and
                    // auto-draft, and the reset promises to delete every form, drafts and trashed ones included.
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
}
