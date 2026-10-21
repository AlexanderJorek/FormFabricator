<?php

/**
 * Admin drag-and-drop form builder page.
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

use FabricatorForms\Form\FormModel;
use FabricatorForms\Form\FormSanitizer;
use FabricatorForms\Fields\FieldRegistry;

/**
 * Admin drag-and-drop form builder page controller.
 */
class FormEditor
{
    /**
     * Registers admin hooks for the form editor page.
     *
     * @return void
     */
    public static function init(): void
    {
        \add_action('admin_menu', [self::class, 'menu']);
        \add_action('wp_ajax_fabricator_forms_save_form', [self::class, 'ajaxSave']);
        \add_action('wp_ajax_fabricator_forms_preview', [self::class, 'ajaxPreview']);
        \add_action('wp_ajax_fabricator_forms_unlock_form', [self::class, 'ajaxUnlock']);
        \add_action('wp_ajax_fabricator_forms_send_test_mail', [self::class, 'ajaxSendTestMail']);
        \add_filter('admin_body_class', [self::class, 'bodyClass']);
        \add_filter('heartbeat_received', [self::class, 'heartbeatReceived'], 10, 2);
    }

    /**
     * Wires the editor page into WP core's native post-locking mechanism via Heartbeat.
     *
     * @param array $response Heartbeat response payload being built.
     * @param array $data     Data sent by the client in this heartbeat tick.
     * @return array Modified heartbeat response.
     */
    public static function heartbeatReceived(array $response, array $data): array
    {
        if (empty($data['fabricator_forms_lock'])) {
            return $response;
        }
        $form_id = absint($data['fabricator_forms_lock']);
        $post    = $form_id ? get_post($form_id) : null;
        if (!$post || $post->post_type !== 'fabricator_form' || !\FabricatorForms\Capabilities::userCan('edit_forms')) {
            return $response;
        }
        $lock_owner = wp_check_post_lock($form_id);
        if ($lock_owner && (int) $lock_owner !== get_current_user_id()) {
            $user = get_userdata($lock_owner);
            $response['fabricator_forms_lock_conflict'] = $user ? $user->display_name : __('another user', 'formfabricator');
        } else {
            wp_set_post_lock($form_id);
        }
        return $response;
    }

    /**
     * Releases the post-lock this user holds, fired via sendBeacon() on tab close/navigation.
     *
     * @return void
     */
    public static function ajaxUnlock(): void
    {
        \FabricatorForms\Utils\Security\AjaxGuard::capability('edit_forms');
        check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');

        $form_id = isset($_POST['form_id']) ? absint(\wp_unslash($_POST['form_id'])) : 0;
        $post    = $form_id ? \get_post($form_id) : null;
        if (!$post || $post->post_type !== 'fabricator_form') {
            \wp_send_json_error(['message' => __('Invalid form_id', 'formfabricator')], 400);
        }

        // Only release a lock this same user currently holds — never a forged/stale beacon.
        $lock = \get_post_meta($form_id, '_edit_lock', true);
        if ($lock !== '') {
            $lock_parts = explode(':', (string) $lock);
            $lock_owner = isset($lock_parts[1]) ? (int) $lock_parts[1] : 0;
            if ($lock_owner === \get_current_user_id()) {
                \delete_post_meta($form_id, '_edit_lock');
            }
        }

        \wp_send_json_success();
    }

    /**
     * Appends a CSS class on the editor page.
     *
     * @param string $classes Existing admin body classes.
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        // The admin page being shown, as WordPress resolved it from the request.
        global $plugin_page;
        if ($plugin_page === 'fabricator-forms-editor') {
            $classes .= ' fabricator-editor-page';
        }
        return $classes;
    }

    /**
     * Registers the editor submenu page.
     *
     * @return void
     */
    public static function menu(): void
    {
        if (\FabricatorForms\Capabilities::userCan('edit_forms')) {
            \add_submenu_page(
                'fabricator-forms',
                __('FormFabricator Editor', 'formfabricator'),
                __('New Form', 'formfabricator'),
                \FabricatorForms\Capabilities::ACCESS_CAP_PREFIX . 'edit_forms',
                'fabricator-forms-editor',
                [self::class, 'render']
            );
        }
    }

    /**
     * Renders the drag-and-drop builder page HTML.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!\FabricatorForms\Capabilities::userCan('edit_forms')) {
            \wp_die(esc_html__('Permission denied.', 'formfabricator'));
        }

        // The perf-overlay script is dev tooling the release package leaves out, so the button needs the file to exist.
        $perf_mode   = defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- the plugin's own file.
            && file_exists(FABRICATOR_FORMS_PATH . 'tools/dev-assets/fabricator-perf-debug.js');
        $perf_start  = $perf_mode ? microtime(true) : 0.0;

        // Which form to open, from the link (as WordPress's own post.php?post=): a page load that only displays, gated
        // by edit_forms above; every save carries its own nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page load, see above.
        $form_id = isset($_GET['form_id']) ? absint(wp_unslash($_GET['form_id'])) : 0;
        $form    = $form_id ? FormModel::get($form_id) : null;
        $palette = FieldRegistry::paletteGroups();

        $lock_owner_name = '';
        if ($form) {
            $form_data = [
                'id'            => $form->id,
                'title'         => $form->title,
                'fields'        => $form->fields,
                'notifications' => $form->notifications,
                'settings'      => $form->settings,
                'snapshot'      => FormModel::snapshot($form->id),
            ];

            $lock_owner = wp_check_post_lock($form->id);
            if ($lock_owner) {
                $lock_owner_user = get_userdata($lock_owner);
                $lock_owner_name = $lock_owner_user ? $lock_owner_user->display_name : __('another user', 'formfabricator');
            } else {
                wp_set_post_lock($form->id);
            }
        } else {
            $form_data = [
                'id'            => 0,
                'title'         => __('New Form', 'formfabricator'),
                'fields'        => [],
                'notifications' => [self::defaultNotification()],
                'settings'      => [
                    'submit_label'    => __('Submit', 'formfabricator'),
                    'success_message' => __('Thank you for your submission!', 'formfabricator'),
                ],
                'snapshot'      => '',
            ];
        }

        \wp_enqueue_script('heartbeat');

        $nonce    = \wp_create_nonce('fabricator_forms_admin_nonce');
        // Cast::jsonForAttribute(): without JSON_HEX_AMP an escaped "&lt;b&gt;" in a text came back as real markup.
        $data_form    = \FabricatorForms\Utils\Cast::jsonForAttribute($form_data);
        $data_palette = \FabricatorForms\Utils\Cast::jsonForAttribute($palette);
        $ajax_url     = \esc_attr(\admin_url('admin-ajax.php'));

        // admin_enqueue_scripts, where the handle is registered, always fires before render().
        \wp_localize_script('fabricator-forms-builder', 'FabricatorBuilderI18n', self::builderI18n());
        \wp_localize_script(
            'fabricator-forms-editor-lock',
            'FabricatorEditorLock',
            [
            'formId'  => $form ? $form->id : 0,
            'nonce'   => $nonce,
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'i18n'    => [
                // translators: %s: display name of the user currently editing this page.
                'lockConflict' => __('Currently being edited by %s. Saving may conflict.', 'formfabricator'),
            ],
            ]
        );

        if ($perf_mode) {
            $php_ms = round((microtime(true) - $perf_start) * 1000, 2);
            \wp_enqueue_script(
                'fabricator-perf-debug',
                FABRICATOR_FORMS_URL . 'tools/dev-assets/fabricator-perf-debug.js',
                [],
                FABRICATOR_FORMS_VERSION,
                // $in_footer is moot: this always runs after wp_head, so it's footer either way.
                false
            );
            \wp_localize_script('fabricator-perf-debug', 'FabricatorPerfData', [
                'phpRenderMs' => $php_ms,
                'formId'      => $form_id,
                'fieldCount'  => count($form_data['fields']),
                'i18n'        => [
                    'toggleShow' => __('Show performance overlay', 'formfabricator'),
                    'toggleHide' => __('Hide performance overlay', 'formfabricator'),
                ],
            ]);
        }
        ?>
        <canvas id="fabricator-particle-canvas"></canvas>
        <div class="wrap fabricator-editor-wrap" style="padding:0;margin:0;">
        <?php \FabricatorForms\Utils\Assets::renderNoticeDock(); ?>
        <!-- admin-builder.js reads these on load and keeps them updated as the source of
             truth for the form/palette state; ajaxSave() below receives that state back -->
        <div id="fabricator-editor"
             data-form='<?php echo esc_attr($data_form); ?>'
             data-palette='<?php echo esc_attr($data_palette); ?>'
             data-nonce="<?php echo \esc_attr($nonce); ?>"
             data-ajax-url="<?php echo esc_attr($ajax_url); ?>">

            <div id="fabricator-canvas">
                <div id="fabricator-canvas-header">
                    <div id="fabricator-header-brand">
                        <i class="fa-solid fa-table-list"></i>
                        <span>FormFabricator</span>
                    </div>
                    <div id="fabricator-header-divider"></div>
                    <input id="fabricator-form-name" type="text" value="" />
                    <span id="fabricator-save-status"></span>
                    <?php if ($lock_owner_name !== '') : ?>
                    <span id="fabricator-lock-notice" class="fabricator-ss--err">
                        <?php
                        // translators: %s: display name of the user currently editing this form.
                        echo esc_html(sprintf(__('Currently being edited by %s. Saving may conflict.', 'formfabricator'), $lock_owner_name));
                        ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($perf_mode) : ?>
                    <button id="fabricator-perf-btn" type="button" title="<?php echo esc_attr__('Performance Overlay', 'formfabricator'); ?>">
                        <i class="fa-solid fa-gauge-high"></i>
                    </button>
                    <?php endif; ?>
                    <button id="fabricator-preview-btn" type="button" title="<?php echo esc_attr__('Preview', 'formfabricator'); ?>">
                        <i class="fa-solid fa-eye"></i> <?php echo esc_html__('Preview', 'formfabricator'); ?>
                    </button>
                    <button id="fabricator-save-btn" type="button"><?php esc_html_e('Save', 'formfabricator'); ?></button>
                </div>

                <div id="fabricator-canvas-tabs">
                    <button class="fabricator-tab-btn fabricator-tab-active" data-tab="fabricator-fields-panel"><?php esc_html_e('Fields', 'formfabricator'); ?></button>
                    <button class="fabricator-tab-btn" data-tab="fabricator-notifications-panel"><?php esc_html_e('Notifications', 'formfabricator'); ?></button>
                </div>

                <div id="fabricator-fields-panel" class="fabricator-tab-panel fabricator-panel-active">
                    <div id="fabricator-field-list"></div>
                    <div id="fabricator-submit-preview-bar"></div>
                    <div id="fabricator-add-field-bar">
                        <button id="fabricator-add-field-btn" type="button">
                            <i class="fa-solid fa-plus"></i> <?php esc_html_e('Add Field', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>

                <div id="fabricator-notifications-panel" class="fabricator-tab-panel"></div>
            </div>

        </div><!-- #fabricator-editor -->
        </div>
        <?php // Particle background + heartbeat lock notice: assets/js/admin-editor-canvas.js and assets/js/admin-editor-lock.js. ?>
        <?php
    }

    /**
     * AJAX handler that returns a complete HTML preview of the form.
     *
     * @return void
     */
    public static function ajaxPreview(): void
    {
        \FabricatorForms\Utils\Security\AjaxGuard::capability('edit_forms');
        check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');

        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;

        // Catches so a field-handler exception (e.g. malformed HTML block) returns a JSON error
        // instead of breaking WP's response entirely.
        try {
            // base64-wrapped like ajaxSave()'s 'form_data', so sanitize_*_field() doesn't strip tags out of embedded HTML.
            $settings_override = [];
            if (!empty($_POST['settings'])) {
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the builder's own base64-wrapped JSON (strict mode); not obfuscation.
                $decoded_s = base64_decode((is_string($_POST['settings'] ?? null) ? sanitize_text_field(wp_unslash($_POST['settings'])) : ''), true);
                $raw_s = ($decoded_s !== false) ? json_decode($decoded_s, true) : null;
                if (is_array($raw_s)) {
                    $settings_override = FormSanitizer::sanitizeSettings($raw_s);
                }
            }

            /* Use live editor fields when posted; fall back to saved DB state. */
            $fields_override = null;
            if (!empty($_POST['fields'])) {
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the builder's own base64-wrapped JSON (strict mode); not obfuscation.
                $decoded_f = base64_decode((is_string($_POST['fields'] ?? null) ? sanitize_text_field(wp_unslash($_POST['fields'])) : ''), true);
                $raw_f = ($decoded_f !== false) ? json_decode($decoded_f, true) : null;
                if (is_array($raw_f)) {
                    $fields_override = FormSanitizer::sanitizeFields($raw_f);
                }
            }

            $html = \FabricatorForms\Form\FormRenderer::render($form_id, $settings_override, $fields_override);
        } catch (\Throwable $e) {
            \FabricatorForms\fabricator_log('FabricatorForms FormEditor::ajaxPreview: ' . get_class($e) . ' — ' . $e->getMessage());
            \wp_send_json_error(
                [
                'message' => __('Could not build the preview — one of the fields may contain content the editor could not process (check any HTML blocks for malformed markup).', 'formfabricator'),
                ],
                500
            );
        }
        if (!$html) {
            \wp_send_json_error(['message' => __('Form not found.', 'formfabricator')], 404);
        }

        /* Exactly what a page with a form gets (Assets::frontFieldAssets() and the front-end localization), except
           ajaxUrl: the preview never submits. */
        $front_assets = \FabricatorForms\Utils\Assets::frontFieldAssets();
        $field_css    = [$front_assets['css']];

        $css_url = \FABRICATOR_FORMS_URL . 'assets/css/front.css';

        $localization            = \FabricatorForms\Utils\Assets::frontLocalization();
        $localization['ajaxUrl'] = '';
        $globals                 = 'window.FabricatorForms=' . \wp_json_encode($localization) . ';';
        foreach ($front_assets['globals'] as $global_name => $literal) {
            $globals .= 'window.' . $global_name . '=' . $literal . ';';
        }

        $toolbar_html = '<div id="fabricator-preview-toolbar">'
            . '<div class="fpt-row">'
            . '<label class="fpt-toggle">'
            . '<input type="checkbox" id="fpt-skip-required">'
            . '<span class="fpt-slider"></span>'
            . '</label>'
            . '<label class="fpt-label" for="fpt-skip-required">'
            . '<strong>' . esc_html__('Ignore required fields', 'formfabricator') . '</strong>'
            . '</label>'
            . '</div>'
            . '</div>';

        $base_css = 'body{font-family:system-ui,sans-serif;background:#f6f7f7;'
            . 'margin:0;padding:40px 24px;display:flex;gap:20px;'
            . 'justify-content:center;align-items:flex-start;}'
            . '#fabricator-preview-content{flex:1;max-width:760px;min-width:0;}'
            . '.fabricator-form-wrap{background:#fff;border-radius:8px;padding:32px;'
            . 'box-shadow:0 2px 12px rgba(0,0,0,.08);box-sizing:border-box;}'
            . '@media(prefers-color-scheme:dark){'
            . 'body{background:#1a1a1a;}'
            . '.fabricator-form-wrap{box-shadow:0 2px 16px rgba(0,0,0,.5);}'
            . '}';

        // Own WP_Styles/WP_Scripts, so the builder page's global registries stay out of the preview.
        $preview_styles = new \WP_Styles();
        $preview_styles->add(
            'fabricator-preview-fontawesome',
            FABRICATOR_FORMS_URL . 'assets/vendor/fontawesome/css/all.min.css',
            [],
            \FabricatorForms\Utils\Assets::FONT_AWESOME_VERSION
        );
        $preview_styles->add('fabricator-preview-front', $css_url, [], FABRICATOR_FORMS_VERSION);
        $preview_styles->add_inline_style('fabricator-preview-front', implode("\n", $field_css) . "\n" . $base_css);
        $preview_styles->add(
            'fabricator-preview-toolbar',
            FABRICATOR_FORMS_URL . 'assets/css/admin-preview-toolbar.css',
            ['fabricator-preview-front'],
            FABRICATOR_FORMS_VERSION
        );
        ob_start();
        $preview_styles->do_items(['fabricator-preview-fontawesome', 'fabricator-preview-front', 'fabricator-preview-toolbar']);
        $styles_html = (string) ob_get_clean();

        $preview_scripts = new \WP_Scripts();
        $preview_scripts->add(
            'fabricator-preview-front',
            FABRICATOR_FORMS_URL . 'assets/js/front.js',
            [],
            FABRICATOR_FORMS_VERSION
        );
        $preview_scripts->add(
            'fabricator-preview-toolbar',
            FABRICATOR_FORMS_URL . 'assets/js/admin-preview-toolbar.js',
            ['fabricator-preview-front'],
            FABRICATOR_FORMS_VERSION
        );
        $preview_scripts->add_inline_script('fabricator-preview-front', $globals, 'before');
        ob_start();
        $preview_scripts->do_items(['fabricator-preview-front', 'fabricator-preview-toolbar']);
        $scripts_html = (string) ob_get_clean();

        $page = '<!DOCTYPE html><html lang="' . esc_attr(str_replace('_', '-', get_locale())) . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html__('Preview', 'formfabricator') . '</title>'
            . $styles_html
            . '</head><body>'
            . '<div id="fabricator-preview-content">' . $html . '</div>'
            . $toolbar_html
            . $scripts_html
            . '</body></html>';

        \wp_send_json_success(['html' => $page]);
    }

    /**
     * Sends a test email with the open notification's sender to the current user, and answers with the outcome.
     *
     * @return void
     */
    public static function ajaxSendTestMail(): void
    {
        \FabricatorForms\Utils\Security\AjaxGuard::capability('edit_forms');
        check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');

        // Each test is a real email: unthrottled, a loop could use up the site's sending quota, and the notifications
        // that then fail would lose submissions.
        if (\FabricatorForms\Utils\Security\RateLimiter::increment('test_mail_' . get_current_user_id(), 5 * MINUTE_IN_SECONDS) > 5) {
            \wp_send_json_error(['message' => __('Please wait a few minutes before sending another test email.', 'formfabricator')], 429);
        }

        // Only the user's own address: anyone who may edit forms could otherwise mail any address from this site.
        $to = (string) wp_get_current_user()->user_email;
        if (!is_email($to)) {
            \wp_send_json_error(['message' => __('Your user account has no valid email address to send the test to.', 'formfabricator')], 400);
        }
        $notif = [
            'from_name'  => (is_string($_POST['from_name'] ?? null) ? \sanitize_text_field(wp_unslash($_POST['from_name'])) : ''),
            'from_email' => FormSanitizer::sanitizeFromEmail((is_string($_POST['from_email'] ?? null) ? \sanitize_text_field(wp_unslash($_POST['from_email'])) : '')),
        ];
        $title = (is_string($_POST['form_title'] ?? null) ? \sanitize_text_field(wp_unslash($_POST['form_title'])) : '');

        $error = \FabricatorForms\Form\MailSender::sendTest($notif, $to, $title);
        if ($error !== '') {
            // The mail system's own words can name its server or login response: only for those who manage the site's
            // settings. Form editors may be granted to people who manage nothing else.
            if (!current_user_can('manage_options')) {
                \wp_send_json_error(['message' => __('The test email could not be sent. Ask the site\'s administrator to check its mail setup.', 'formfabricator')], 502);
            }
            \wp_send_json_error(['message' => sprintf(
                /* translators: %s: the reason the mail function gave. */
                __('The test email could not be sent: %s', 'formfabricator'),
                $error
            )], 502);
        }
        \wp_send_json_success(['message' => sprintf(
            /* translators: %s: the current user's email address. */
            __('Test email sent to %s. If it does not arrive, check the spam folder, then the site\'s mail setup.', 'formfabricator'),
            $to
        )]);
    }

    /**
     * AJAX handler that saves form data.
     *
     * @return void
     */
    public static function ajaxSave(): void
    {
        \FabricatorForms\Utils\Security\AjaxGuard::capability('edit_forms');
        check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');

        // base64-wrapped so WP's magic-quotes slashing can't corrupt embedded quotes in the raw JSON.
        $encoded = (is_string($_POST['form_data'] ?? null) ? sanitize_text_field(wp_unslash($_POST['form_data'])) : '');
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the export string produced above (strict mode); re-sanitized before use, not obfuscation.
        $json    = base64_decode($encoded, true);
        $raw     = ($json !== false) ? json_decode($json, true) : null;
        if (!is_array($raw)) {
            \wp_send_json_error(['message' => __('Invalid form data', 'formfabricator')], 400);
        }

        $form_id       = (int)($raw['id'] ?? 0);
        $raw_title     = $raw['title'] ?? '';
        $expected_snapshot = \sanitize_text_field(is_string($raw['snapshot'] ?? null) ? $raw['snapshot'] : '');

        // Post-locking as a second line of defense alongside the snapshot-hash check below.
        if ($form_id > 0) {
            $lock_owner = wp_check_post_lock($form_id);
            if ($lock_owner && (int) $lock_owner !== get_current_user_id()) {
                $user = get_userdata($lock_owner);
                \wp_send_json_error(
                    [
                    'message' => sprintf(
                        /* translators: %s: display name of the user currently editing this form. */
                        __('This form is currently locked for editing by %s.', 'formfabricator'),
                        $user ? $user->display_name : __('another user', 'formfabricator')
                    ),
                    ],
                    409
                );
            }
        }

        // Catches so a field-handler exception (e.g. malformed HTML block) returns a JSON error
        // instead of breaking WP's response entirely.
        try {
            $trimmed_bodies          = [];
            $sanitized_notifications = FormSanitizer::sanitizeNotifications(is_array($raw['notifications'] ?? null) ? $raw['notifications'] : [], $trimmed_bodies);
            $sanitized_fields        = FormSanitizer::sanitizeFields(is_array($raw['fields'] ?? null) ? $raw['fields'] : []);
            $id_error                = FormSanitizer::fieldIdError($sanitized_fields);
            if ($id_error !== '') {
                \wp_send_json_error(['message' => $id_error], 422);
            }
            $result = FormModel::save(
                [
                'title'         => \sanitize_text_field(is_string($raw_title) ? $raw_title : ''),
                'fields'        => $sanitized_fields,
                'notifications' => $sanitized_notifications,
                'settings'      => FormSanitizer::sanitizeSettings(is_array($raw['settings'] ?? null) ? $raw['settings'] : []),
                ],
                $form_id,
                $expected_snapshot
            );

            if (\is_wp_error($result)) {
                $status = $result->get_error_code() === 'conflict' ? 409 : 500;
                \wp_send_json_error(['message' => $result->get_error_message()], $status);
            }

            wp_set_post_lock($result);

            /* Keys are scoped to this form's own notifications, so the narrower 'edit_forms' gate is safe here. */
            // Under OptionMutex: every form's flags live in this one option, and an unlocked read-modify-write would let a form
            // saved at the same moment undo this save's attach-PDF flags (or the other way round) without any error.
            \FabricatorForms\Utils\Storage\OptionMutex::run(
                'fabricator_forms_pdf_settings',
                static function () use ($result, $sanitized_notifications): void {
                    $pdf_settings = \get_option('fabricator_forms_pdf_settings', []);
                    if (!is_array($pdf_settings)) {
                        $pdf_settings = [];
                    }
                    // Rebuilt, not added to, so a removed notification's "<form_id>|<slug>" entry goes too.
                    $prefix = $result . '|';
                    foreach (array_keys($pdf_settings) as $existing_key) {
                        if (strncmp((string) $existing_key, $prefix, strlen($prefix)) === 0) {
                            unset($pdf_settings[$existing_key]);
                        }
                    }
                    foreach ($sanitized_notifications as $notif) {
                        $slug = $notif['slug'] ?? '';
                        if ($slug) {
                            $pdf_settings[$prefix . $slug] = !empty($notif['attach_pdf']) ? 1 : 0;
                        }
                    }
                    // autoload=false: nothing reads this on the front end (MailSender reads it only
                    // during submission handling), and the option grows one key per form x notification.
                    \update_option('fabricator_forms_pdf_settings', $pdf_settings, false);
                }
            );

            \wp_send_json_success(
                [
                'message'  => __('Form saved.', 'formfabricator'),
                'form_id'  => $result,
                'snapshot' => FormModel::snapshot((int) $result),
                // Saved either way; but a form with nowhere to send it is refused by FormProcessor, and a mandate without
                // wording takes no details, so say so now, not when a visitor finds out.
                'warning'  => trim(
                    (\FabricatorForms\Form\MailSender::hasEnabledNotification($sanitized_notifications)
                        || apply_filters('fabricator_forms_accept_without_notifications', false, (int) $result)
                        ? ''
                        : __('This form has no active notification, so visitors cannot send it. Add or enable a notification in the notification settings of this form.', 'formfabricator'))
                    . ' '
                    . (self::hasMandateWithoutWording($sanitized_fields)
                        ? __('A Direct Debit Mandate has no mandate text, so visitors cannot give it. Enter the text your bank or payment provider requires in the field\'s settings.', 'formfabricator')
                        : '')
                    . ' '
                    . self::mandateElementsWarning($sanitized_fields)
                    . ' '
                    . self::consentTextWarning($sanitized_fields)
                    . ' '
                    . self::signatureDeliveryWarning((int) $result, $sanitized_fields, $sanitized_notifications)
                    . ' '
                    . self::trimmedBodyWarning($trimmed_bodies)
                ),
                ]
            );
        } catch (\Throwable $e) {
            \FabricatorForms\fabricator_log('FabricatorForms FormEditor::ajaxSave: ' . get_class($e) . ' — ' . $e->getMessage());
            \wp_send_json_error(
                [
                'message' => __('Could not save this form — one of the fields may contain content the editor could not process (check any HTML blocks for malformed markup). Please review the fields and try again.', 'formfabricator'),
                ],
                500
            );
        }
    }

    /**
     * The save warning for notifications whose message lost content when saved, or ''. The form is saved without it,
     * so the admin learns now, not from a recipient, that the email differs from what they wrote.
     *
     * @param string[] $names The notifications' names (FormSanitizer::sanitizeNotifications()).
     * @return string
     */
    private static function trimmedBodyWarning(array $names): string
    {
        if ($names === []) {
            return '';
        }
        return sprintf(
            /* translators: %s: notification names, comma-separated. */
            __(
                'Content was removed from the message of these notifications because email may not carry it (scripts, embedded content, event handlers, external stylesheets, or tags and styles outside the email-safe set). Check the message: %s.',
                'formfabricator'
            ),
            implode(', ', $names)
        );
    }

    /**
     * The save warning for notifications whose email would carry no signature image, or ''.
     *
     * @param int   $form_id       The saved form.
     * @param array $fields        Sanitized fields.
     * @param array $notifications Sanitized notifications.
     * @return string
     */
    private static function signatureDeliveryWarning(int $form_id, array $fields, array $notifications): string
    {
        $without = \FabricatorForms\Form\MailSender::notificationsWithoutSignatures($form_id, $fields, $notifications);
        if ($without === []) {
            return '';
        }
        return sprintf(
            /* translators: %s: notification names, comma-separated. */
            __('Signatures reach recipients only in the attached PDF, if the PDF Layout shows them, or with "Attach uploaded files". These notifications have neither, so their email only says a signature is present: %s.', 'formfabricator'),
            implode(', ', $without)
        );
    }

    /**
     * Whether any Direct Debit Mandate in the form, also inside a group, has no wording and so takes no details
     * (DirectDebitField::lacksWording()).
     *
     * @param array $fields Sanitized fields, as FormSanitizer::sanitizeFields() returns them.
     * @return bool
     */
    private static function hasMandateWithoutWording(array $fields): bool
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['type'] ?? '') === 'directdebit' && \FabricatorForms\Fields\DirectDebitField::lacksWording($field)) {
                return true;
            }
            if (is_array($field['children'] ?? null) && self::hasMandateWithoutWording($field['children'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The save warning for Direct Debit Mandates that lack what a bank needs to collect
     * (DirectDebitField::missingMandateElements()), or ''.
     *
     * @param array $fields Sanitized fields, as FormSanitizer::sanitizeFields() returns them.
     * @return string
     */
    private static function mandateElementsWarning(array $fields): string
    {
        $missing = [];
        $walk    = static function (array $fields) use (&$walk, &$missing): void {
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (($field['type'] ?? '') === 'directdebit' && !\FabricatorForms\Fields\DirectDebitField::lacksWording($field)) {
                    $missing = array_merge($missing, \FabricatorForms\Fields\DirectDebitField::missingMandateElements($field));
                }
                if (is_array($field['children'] ?? null)) {
                    $walk($field['children']);
                }
            }
        };
        $walk($fields);
        if ($missing === []) {
            return '';
        }
        return sprintf(
            /* translators: %s: what the mandate leaves out, comma-separated, e.g. "Creditor name, Mandate reference". */
            __('A Direct Debit Mandate does not name: %s. Visitors give bank details without knowing who collects, and banks cannot use it. Enter them in the field\'s settings, or ignore this if the mandate text names them.', 'formfabricator'),
            implode(', ', array_unique($missing))
        );
    }

    /**
     * The save warnings for Consent fields without valid wording, or ''. A placeholder text names no purpose and no way
     * to withdraw, and an empty one names nothing at all, so the consent would not be valid (GDPR Art. 7(3), 4(11)).
     *
     * @param array $fields Sanitized fields, as FormSanitizer::sanitizeFields() returns them.
     * @return string
     */
    private static function consentTextWarning(array $fields): string
    {
        $placeholder = false;
        $empty       = false;
        $walk        = static function (array $fields) use (&$walk, &$placeholder, &$empty): void {
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (($field['type'] ?? '') === 'consent') {
                    $placeholder = $placeholder || \FabricatorForms\Fields\ConsentField::usesPlaceholderText($field);
                    $empty       = $empty || \FabricatorForms\Fields\ConsentField::hasNoText($field);
                }
                if (is_array($field['children'] ?? null)) {
                    $walk($field['children']);
                }
            }
        };
        $walk($fields);
        return trim(
            ($placeholder
                ? __('A Consent field still shows its placeholder text, which names no purpose and no way to withdraw, so it is not valid consent. Write what the visitor agrees to and how they can withdraw it.', 'formfabricator')
                : '')
            . ' '
            . ($empty
                ? __('A Consent field has no text, so the visitor agrees to nothing. Write what the visitor agrees to and how they can withdraw it.', 'formfabricator')
                : '')
        );
    }

    /**
     * Returns the default notification configuration array.
     *
     * @return array Default notification configuration.
     */
    private static function defaultNotification(): array
    {
        return [
            'slug'             => 'notification-1',
            'name'             => __('Notification 1', 'formfabricator'),
            'recipient_mode'   => 'single',
            'to'               => \get_option('admin_email', ''),
            'routing_rules'    => [],
            'routing_fallback' => '',
            'subject'          => __('New Entry: {form_title}', 'formfabricator'),
            'body'             =>
                __('A new entry has been received.<br><br>{all_fields}', 'formfabricator'),
            'from_name'        => '{site_name}',
            'from_email'       => '{admin_email}',
            'reply_to'         => '{email}',
            'cc'               => '',
            'bcc'              => '',
            'attach_pdf'       => true,
            'enabled'          => true,
        ];
    }

    /**
     * Builds the localized string catalog consumed by assets/js/admin-builder.js.
     *
     * @return array{i18n: array<string, string>, countryNames: array<string, string>, phoneCodes: array<string, string>}
     */
    private static function builderI18n(): array
    {
        return [
            'i18n' => [
                /* Defaults seeded into a brand-new form (state.formName / state.settings) */
                'defaultFormName'  => __('New Form', 'formfabricator'),
                'submitLabel'      => __('Submit', 'formfabricator'),
                'submitWorking'    => __('Sending…', 'formfabricator'),
                'successMessage'   => __('Thank you for your submission!', 'formfabricator'),

                /* Labels admin-builder.js shows */
                // translators: %d: running number of the notification being added.
                'notificationName'    => __('Notification %d', 'formfabricator'),
                'notificationSubject' => __('New Entry: {form_title}', 'formfabricator'),
                'segOption'           => __('Option', 'formfabricator'),
                'segValue'            => __('Value', 'formfabricator'),
                'segVisual'           => __('Visual', 'formfabricator'),
                'segCode'             => __('Code', 'formfabricator'),
                'segNo'               => __('No', 'formfabricator'),
                'segYes'              => __('Yes', 'formfabricator'),

                /* Unsaved-changes guard modal */
                'unsavedTitle' => __('Unsaved changes', 'formfabricator'),
                'unsavedBody'  => __('This form has unsaved changes. Leave anyway?', 'formfabricator'),
                'stay'         => __('Stay', 'formfabricator'),
                'leave'        => __('Leave', 'formfabricator'),

                /* Number-field validation rule dropdown */
                'validationNone'             => __('None', 'formfabricator'),
                'validationIntegersOnly'     => __('Integers only', 'formfabricator'),
                'validationPositiveNumbers'  => __('Positive numbers', 'formfabricator'),
                'validationPositiveIntegers' => __('Positive integers', 'formfabricator'),

                /* Condition-rule operator dropdown (field/submit-button conditions) */
                'opEquals'      => __('is equal to', 'formfabricator'),
                'opNotEquals'   => __('is not equal to', 'formfabricator'),
                'opContains'    => __('contains', 'formfabricator'),
                'opNotContains' => __('does not contain', 'formfabricator'),
                'opEmpty'       => __('is empty', 'formfabricator'),
                'opNotEmpty'    => __('is not empty', 'formfabricator'),
                'opGreater'     => __('is greater than', 'formfabricator'),
                'opLess'        => __('is less than', 'formfabricator'),

                /* Field list / rows */
                'noFieldsFound'       => __('No fields found.', 'formfabricator'),
                'noFieldsYetTitle'    => __('No fields yet', 'formfabricator'),
                // translators: %s: the "Add field" button's name, shown in bold (substituted client-side).
                'addFirstFieldHtml'   => __('Add your first field via %s', 'formfabricator'),
                'noLabel'             => __('(no label)', 'formfabricator'),
                'edit'                => __('Edit', 'formfabricator'),
                'duplicate'           => __('Duplicate', 'formfabricator'),
                'remove'              => __('Remove', 'formfabricator'),
                'copied'              => __('copied', 'formfabricator'),
                'fieldGroupType'      => __('Field group', 'formfabricator'),
                'addFieldsToGroup'    => __('Add fields to group', 'formfabricator'),
                'settingsLabel'       => __('Settings', 'formfabricator'),
                'conditionsActive'    => __('Conditions active', 'formfabricator'),

                /* Field picker modal */
                'addFieldTitle'    => __('Add field', 'formfabricator'),
                'searchFieldType'  => __('Search field type…', 'formfabricator'),

                /* Field settings modal */
                'fieldSettingsTitle' => __('Field settings', 'formfabricator'),
                'copyFieldId'        => __('Copy field ID', 'formfabricator'),
                'tabGeneral'         => __('General', 'formfabricator'),
                'tabAdvanced'        => __('Advanced', 'formfabricator'),
                'tabConditions'      => __('Conditions', 'formfabricator'),
                'done'               => __('Done', 'formfabricator'),
                'editFieldSuffix'    => __('Edit', 'formfabricator'),
                'labelField'         => __('Label', 'formfabricator'),
                'hideLabel'          => __('Hide label', 'formfabricator'),
                'requiredField'      => __('Required field', 'formfabricator'),
                'noOtherFields'      => __('(no other fields)', 'formfabricator'),
                'valueWord'          => __('Value', 'formfabricator'),
                'mediaLibrary'       => __('Media library', 'formfabricator'),
                'useButtonLabel'     => __('Use', 'formfabricator'),
                'imageUrlPrompt'     => __('Image URL:', 'formfabricator'),
                'linkUrlPrompt'      => __('Enter link URL:', 'formfabricator'),

                /* Rich-text toolbar (spRichTextEditor) command tooltips */
                'rtBold'          => __('Bold', 'formfabricator'),
                'rtItalic'        => __('Italic', 'formfabricator'),
                'rtUnderline'     => __('Underline', 'formfabricator'),
                'rtBulletList'    => __('List', 'formfabricator'),
                'rtNumberedList'  => __('Numbered list', 'formfabricator'),
                'rtLink'          => __('Link', 'formfabricator'),

                /* Country/calling-code tag remove buttons, rating icon pill */
                'removeAriaLabel' => __('Remove', 'formfabricator'),
                'wholeValues'     => __('Whole values', 'formfabricator'),
                'halfValues'      => __('Half values', 'formfabricator'),
                // translators: %d: page number (substituted client-side).
                'pageNumber'         => __('Page %d', 'formfabricator'),
                'useEmailsHint'      => __('Use in emails:', 'formfabricator'),
                'fieldIdLabel'       => __('Field ID', 'formfabricator'),

                /* Advanced tab */
                'validationSection'      => __('Validation', 'formfabricator'),
                'validationRule'         => __('Validation rule', 'formfabricator'),
                'autocompleteSection'    => __('Browser autocomplete', 'formfabricator'),
                'enableBrowserFill'      => __('Enable browser autofill', 'formfabricator'),
                'autocompleteValue'      => __('Autocomplete value', 'formfabricator'),
                'autocompleteValueHint'  => __('E.g. "name", "email", "tel". Empty = browser default.', 'formfabricator'),
                'appearanceSection'      => __('Appearance', 'formfabricator'),
                'cssClasses'             => __('CSS class(es)', 'formfabricator'),
                'separateClassesHint'    => __('Separate multiple classes with spaces.', 'formfabricator'),

                /* Direct debit / phone advanced blocks */
                'countryFilter'      => __('Country filter', 'formfabricator'),
                'countryList'        => __('Country list', 'formfabricator'),
                'off'                => __('Off', 'formfabricator'),
                'allowed'            => __('Allowed', 'formfabricator'),
                'disallowed'         => __('Disallowed', 'formfabricator'),
                'formatValidation'   => __('Format validation', 'formfabricator'),
                'phoneAnyFormatHint' => __('Any valid format: min. 7 digits, optionally with + and country code.', 'formfabricator'),
                'any'                => __('Any', 'formfabricator'),
                'countriesMode'      => __('Countries', 'formfabricator'),
                'dialCodes'          => __('Dial codes', 'formfabricator'),

                /* Conditions tab (per-field and submit-button) */
                'condShow'          => __('Show', 'formfabricator'),
                'condHide'          => __('Hide', 'formfabricator'),
                'condSentenceField' => __('this field when', 'formfabricator'),
                'condAll'           => __('all', 'formfabricator'),
                'condAny'           => __('any', 'formfabricator'),
                'condSentenceTail'  => __('of the following conditions match:', 'formfabricator'),
                'addCondition'      => __('Add condition', 'formfabricator'),
                'removeCondition'   => __('Remove condition', 'formfabricator'),
                'removeRule'        => __('Remove rule', 'formfabricator'),
                'addRule'           => __('Add rule', 'formfabricator'),
                'chooseOption'      => __('Choose option', 'formfabricator'),
                'modeOption'        => __('Option', 'formfabricator'),
                'modeValue'         => __('Value', 'formfabricator'),
                'noFields'          => __('(no fields)', 'formfabricator'),

                /* Country / calling-code tag pickers */
                'searchCountry'  => __('Search and add country…', 'formfabricator'),
                'searchDialCode' => __('Search dial code (+49)…', 'formfabricator'),

                /* Select/radio/checkbox option editor */
                'preselected'            => __('Preselected', 'formfabricator'),
                'optionLabelPlaceholder' => __('Label', 'formfabricator'),
                'optionValuePlaceholder' => __('value', 'formfabricator'),
                'addOption'              => __('Add option', 'formfabricator'),

                /* Textarea/text character-limit unit toggle */
                'unitChars' => __('Characters', 'formfabricator'),
                'unitWords' => __('Words', 'formfabricator'),

                /* Rich text / HTML editors */
                'modeCode'    => __('Code', 'formfabricator'),
                'modePreview' => __('Preview', 'formfabricator'),
                'modeVisual'  => __('Visual', 'formfabricator'),

                /* Time field */
                'formatLabel' => __('Format', 'formfabricator'),
                'format24h'   => __('24h', 'formfabricator'),
                'format12h'   => __('12h (AM/PM)', 'formfabricator'),
                'prefillNow'  => __('Prefill now', 'formfabricator'),

                /* Sub-fields accordion (Name, Address, …) */
                'subfieldsSection' => __('Subfields', 'formfabricator'),
                'enableSubfield'   => __('Enable subfield', 'formfabricator'),
                'placeholderLabel' => __('Placeholder', 'formfabricator'),

                /* Notifications list + modal */
                // translators: %s: the "Add notification" button's name, shown in bold (substituted client-side).
                'noNotificationsHtml' => __('No notifications yet. Click %s.', 'formfabricator'),
                'addNotification'     => __('Add notification', 'formfabricator'),
                'routingActive'       => __('Routing active', 'formfabricator'),
                'noName'              => __('(no name)', 'formfabricator'),
                'notificationPlaceholder' => __('Notification', 'formfabricator'),
                'tabRecipient'        => __('Recipient', 'formfabricator'),
                'tabContent'          => __('Content', 'formfabricator'),
                'tabSender'           => __('Sender', 'formfabricator'),
                'recipientMode'       => __('Recipient mode', 'formfabricator'),
                'modeDirect'          => __('Direct', 'formfabricator'),
                'modeRouting'         => __('Routing', 'formfabricator'),
                'activeLabel'         => __('Active', 'formfabricator'),
                'singleModeHint'      => __('All entries are sent to a fixed address.', 'formfabricator'),
                'routingModeHint'     => __('The email address is chosen based on field conditions.', 'formfabricator'),
                'toEmail'             => __('To (email)', 'formfabricator'),
                'arrowTo'             => __('→ To:', 'formfabricator'),
                'emailPlaceholder'    => __('Email', 'formfabricator'),
                'fallbackEmail'       => __('Fallback email', 'formfabricator'),
                'fallbackEmailHint'   => __('Used when no rule matches', 'formfabricator'),
                'fallbackCc'          => __('Fallback CC', 'formfabricator'),
                'fallbackBcc'         => __('Fallback BCC', 'formfabricator'),
                // translators: prefix on a routing rule's CC row, matching the "→ To:" arrow above it.
                'arrowCc'             => __('→ Cc:', 'formfabricator'),
                // translators: prefix on a routing rule's BCC row, matching the "→ To:" arrow above it.
                'arrowBcc'            => __('→ Bcc:', 'formfabricator'),
                'subject'             => __('Subject', 'formfabricator'),
                'message'             => __('Message', 'formfabricator'),
                'attachments'         => __('Attachments', 'formfabricator'),
                'attachPdf'           => __('Attach generated PDF', 'formfabricator'),
                'attachPdfHint'       => __('The completed form is attached as a PDF document.', 'formfabricator'),
                'attachUploads'       => __('Attach uploaded files', 'formfabricator'),
                'attachUploadsHint'   => __('All file uploads from the form are forwarded as attachments.', 'formfabricator'),
                'automation'          => __('Automation', 'formfabricator'),
                'automationMarkers'   => __('Add automation markers', 'formfabricator'),
                'automationMarkersHint' =>
                    __('Each answer in the email is written between [FABRICATOR_FIELD_<field id>] and [FABRICATOR_FIELD_END], so a mail parser can find it. Answers containing "[FABRICATOR_FIELD_" are then refused.', 'formfabricator'),
                'sendTestMail'        => __('Send test email', 'formfabricator'),
                'sendTestMailHint'    => __('Sends a short email with this sender to your own address.', 'formfabricator'),
                'notifBodyHint'       => __(
                    'Removed on save: scripts, embedded content (iframe, object, embed), event handlers (onclick and the like), javascript: links, external stylesheets, form actions, and tags or styles outside the email-safe set.',
                    'formfabricator'
                ),
                'fromName'            => __('Sender name', 'formfabricator'),
                'defaultSiteNameHint' => __('{site_name} for default value', 'formfabricator'),
                'fromEmail'           => __('Sender email address', 'formfabricator'),
                'fromEmailHint'       => __('{admin_email}, or a fixed address of this site.', 'formfabricator'),
                'fromEmailFieldWarning' => __(
                    'A form field cannot be the sender: mail claiming to come from a visitor\'s address is often discarded by their provider after sending. It will not be saved. Put the field into "Reply-to email" instead.',
                    'formfabricator'
                ),
                'replyTo'             => __('Reply-to email', 'formfabricator'),
                'emptyMeansSenderEmail' => __('Empty = sender email', 'formfabricator'),
                'ccEmails'            => __('CC emails', 'formfabricator'),
                'bccEmails'           => __('BCC emails', 'formfabricator'),
                'separateWithSemicolon' => __('Separate multiple with semicolons', 'formfabricator'),

                /* Submit button preview + settings modal */
                'submitButtonTitle'       => __('Submit button', 'formfabricator'),
                'tabLabels'               => __('Labeling', 'formfabricator'),
                'buttonLabel'             => __('Label', 'formfabricator'),
                'buttonLabelHint'         => __('Visible text of the button', 'formfabricator'),
                'workingLabel'            => __('Label while sending', 'formfabricator'),
                'workingLabelHint'        => __('Shown while the submission is in progress', 'formfabricator'),
                'successMessageLabel'     => __('Success message', 'formfabricator'),
                'successMessageHint'      => __('Message shown after a successful submission', 'formfabricator'),
                'showButtonWhen'          => __('Show button when', 'formfabricator'),
                'conditionsMatchSuffix'   => __('of the conditions match:', 'formfabricator'),
                'configureButton'         => __('Configure button', 'formfabricator'),
                'visibilityConditionsActive' => __('Visibility: conditions active', 'formfabricator'),
                'conditionalBadge'        => __('conditional', 'formfabricator'),

                /* Save / preview status messages */
                'saving'         => __('Saving…', 'formfabricator'),
                'saved'          => __('Saved', 'formfabricator'),
                'dismiss'        => __('Dismiss', 'formfabricator'),
                'errorGeneric'   => __('Error', 'formfabricator'),
                'unknownError'   => __('Unknown error', 'formfabricator'),
                'serverError'    => __('Server error', 'formfabricator'),
                'previewLabel'   => __('Preview', 'formfabricator'),
                'previewFailed'  => __('Preview failed.', 'formfabricator'),
                'networkError'   => __('Network error.', 'formfabricator'),
            ],

            // SEPA country picker; same msgids as DirectDebitField::ibanCountryOptions().
            'countryNames' => [
                'AD' => __('Andorra', 'formfabricator'),
                'AL' => __('Albania', 'formfabricator'),
                'AT' => __('Austria', 'formfabricator'),
                'BE' => __('Belgium', 'formfabricator'),
                'BG' => __('Bulgaria', 'formfabricator'),
                'CH' => __('Switzerland', 'formfabricator'),
                'CY' => __('Cyprus', 'formfabricator'),
                'CZ' => __('Czechia', 'formfabricator'),
                'DE' => __('Germany', 'formfabricator'),
                'DK' => __('Denmark', 'formfabricator'),
                'EE' => __('Estonia', 'formfabricator'),
                'ES' => __('Spain', 'formfabricator'),
                'FI' => __('Finland', 'formfabricator'),
                'FR' => __('France', 'formfabricator'),
                'GB' => __('United Kingdom', 'formfabricator'),
                'GI' => __('Gibraltar', 'formfabricator'),
                'GR' => __('Greece', 'formfabricator'),
                'HR' => __('Croatia', 'formfabricator'),
                'HU' => __('Hungary', 'formfabricator'),
                'IE' => __('Ireland', 'formfabricator'),
                'IS' => __('Iceland', 'formfabricator'),
                'IT' => __('Italy', 'formfabricator'),
                'LI' => __('Liechtenstein', 'formfabricator'),
                'LT' => __('Lithuania', 'formfabricator'),
                'LU' => __('Luxembourg', 'formfabricator'),
                'LV' => __('Latvia', 'formfabricator'),
                'MC' => __('Monaco', 'formfabricator'),
                'MD' => __('Moldova', 'formfabricator'),
                'ME' => __('Montenegro', 'formfabricator'),
                'MK' => __('North Macedonia', 'formfabricator'),
                'MT' => __('Malta', 'formfabricator'),
                'NL' => __('Netherlands', 'formfabricator'),
                'NO' => __('Norway', 'formfabricator'),
                'PL' => __('Poland', 'formfabricator'),
                'PT' => __('Portugal', 'formfabricator'),
                'RO' => __('Romania', 'formfabricator'),
                'RS' => __('Serbia', 'formfabricator'),
                'SE' => __('Sweden', 'formfabricator'),
                'SI' => __('Slovenia', 'formfabricator'),
                'SK' => __('Slovakia', 'formfabricator'),
                'SM' => __('San Marino', 'formfabricator'),
                'VA' => __('Vatican City', 'formfabricator'),
            ],

            // Phone dial-code picker: region labels, not country names (+1 covers several countries).
            'phoneCodes' => [
                '+1'   => __('USA / Canada', 'formfabricator'),
                '+7'   => __('Russia', 'formfabricator'),
                '+20'  => __('Egypt', 'formfabricator'),
                '+27'  => __('South Africa', 'formfabricator'),
                '+30'  => __('Greece', 'formfabricator'),
                '+31'  => __('Netherlands', 'formfabricator'),
                '+32'  => __('Belgium', 'formfabricator'),
                '+33'  => __('France', 'formfabricator'),
                '+34'  => __('Spain', 'formfabricator'),
                '+36'  => __('Hungary', 'formfabricator'),
                '+39'  => __('Italy', 'formfabricator'),
                '+40'  => __('Romania', 'formfabricator'),
                '+41'  => __('Switzerland', 'formfabricator'),
                '+43'  => __('Austria', 'formfabricator'),
                '+44'  => __('United Kingdom', 'formfabricator'),
                '+45'  => __('Denmark', 'formfabricator'),
                '+46'  => __('Sweden', 'formfabricator'),
                '+47'  => __('Norway', 'formfabricator'),
                '+48'  => __('Poland', 'formfabricator'),
                '+49'  => __('Germany', 'formfabricator'),
                '+51'  => __('Peru', 'formfabricator'),
                '+52'  => __('Mexico', 'formfabricator'),
                '+54'  => __('Argentina', 'formfabricator'),
                '+55'  => __('Brazil', 'formfabricator'),
                '+56'  => __('Chile', 'formfabricator'),
                '+57'  => __('Colombia', 'formfabricator'),
                '+61'  => __('Australia', 'formfabricator'),
                '+62'  => __('Indonesia', 'formfabricator'),
                '+63'  => __('Philippines', 'formfabricator'),
                '+64'  => __('New Zealand', 'formfabricator'),
                '+65'  => __('Singapore', 'formfabricator'),
                '+66'  => __('Thailand', 'formfabricator'),
                '+81'  => __('Japan', 'formfabricator'),
                '+82'  => __('South Korea', 'formfabricator'),
                '+84'  => __('Vietnam', 'formfabricator'),
                '+86'  => __('China', 'formfabricator'),
                '+90'  => __('Turkey', 'formfabricator'),
                '+91'  => __('India', 'formfabricator'),
                '+92'  => __('Pakistan', 'formfabricator'),
                '+94'  => __('Sri Lanka', 'formfabricator'),
                '+98'  => __('Iran', 'formfabricator'),
                '+212' => __('Morocco', 'formfabricator'),
                '+213' => __('Algeria', 'formfabricator'),
                '+216' => __('Tunisia', 'formfabricator'),
                '+220' => __('Gambia', 'formfabricator'),
                '+234' => __('Nigeria', 'formfabricator'),
                '+254' => __('Kenya', 'formfabricator'),
                '+351' => __('Portugal', 'formfabricator'),
                '+352' => __('Luxembourg', 'formfabricator'),
                '+353' => __('Ireland', 'formfabricator'),
                '+354' => __('Iceland', 'formfabricator'),
                '+356' => __('Malta', 'formfabricator'),
                '+357' => __('Cyprus', 'formfabricator'),
                '+358' => __('Finland', 'formfabricator'),
                '+359' => __('Bulgaria', 'formfabricator'),
                '+370' => __('Lithuania', 'formfabricator'),
                '+371' => __('Latvia', 'formfabricator'),
                '+372' => __('Estonia', 'formfabricator'),
                '+380' => __('Ukraine', 'formfabricator'),
                '+385' => __('Croatia', 'formfabricator'),
                '+386' => __('Slovenia', 'formfabricator'),
                '+420' => __('Czechia', 'formfabricator'),
                '+421' => __('Slovakia', 'formfabricator'),
                '+423' => __('Liechtenstein', 'formfabricator'),
            ],
        ];
    }
}
