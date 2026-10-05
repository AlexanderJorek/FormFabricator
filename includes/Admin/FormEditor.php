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

use FabricatorForms\Form\FormModel;
use FabricatorForms\Fields\FieldRegistry;

/**
 * Admin drag-and-drop form builder page controller.
 */
class FormEditor
{
    /* Field types that may never be a group child. Mirrors NO_GROUP_TYPES in admin-builder.js; enforced in
       sanitizeFields() for crafted saves and imports. */
    private const NO_GROUP_TYPES = ['group', 'pagebreak', 'page-header'];

    /**
     * Keys that may hold a list on any field, whatever its type declares: each has a purpose-built sanitizer in
     * sanitizeFields(). Any other key holds a list only where the field's own defaults say so.
     *
     * @var string[]
     */
    private const STRUCTURAL_ARRAY_KEYS = ['conditions', 'children', 'options'];

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
        if (!$post || $post->post_type !== 'fabricator_form' || !\FabricatorForms\Plugin::userCan('edit_forms')) {
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
        \FabricatorForms\Utils\AjaxGuard::require('edit_forms', 'fabricator_forms_admin_nonce', 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
        $form_id = absint(\wp_unslash($_POST['form_id'] ?? 0));
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin body-class check, no data written.
        if (isset($_GET['page']) && $_GET['page'] === 'fabricator-forms-editor') {
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
        if (\FabricatorForms\Plugin::userCan('edit_forms')) {
            \add_submenu_page(
                'fabricator-forms',
                __('FormFabricator Editor', 'formfabricator'),
                __('New Form', 'formfabricator'),
                \FabricatorForms\Plugin::ACCESS_CAP_PREFIX . 'edit_forms',
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
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            \wp_die(esc_html__('Permission denied.', 'formfabricator'));
        }

        // Release packages leave the perf-overlay script out, so the button needs the file to exist.
        $perf_mode   = defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')
            && file_exists(FABRICATOR_FORMS_PATH . 'assets/js/fabricator-perf-debug.js');
        $perf_start  = $perf_mode ? microtime(true) : 0.0;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page load (which form to display), gated by edit_forms capability above, no data written.
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
                FABRICATOR_FORMS_URL . 'assets/js/fabricator-perf-debug.js',
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
        \FabricatorForms\Utils\AjaxGuard::require('edit_forms', 'fabricator_forms_admin_nonce', 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;

        // Catches so a field-handler exception (e.g. malformed HTML block) returns a JSON error
        // instead of breaking WP's response entirely.
        try {
            // base64-wrapped like ajaxSave()'s 'form_data', so sanitize_*_field() doesn't strip tags out of embedded HTML.
            $settings_override = [];
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
            if (!empty($_POST['settings'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
                $decoded_s = base64_decode(sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(\wp_unslash($_POST['settings']))), true);
                $raw_s = ($decoded_s !== false) ? json_decode($decoded_s, true) : null;
                if (is_array($raw_s)) {
                    $settings_override = self::sanitizeSettings($raw_s);
                }
            }

            /* Use live editor fields when posted; fall back to saved DB state. */
            $fields_override = null;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
            if (!empty($_POST['fields'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- verified via AjaxGuard::require(); sniff can't see through the static call.
                $decoded_f = base64_decode(sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(\wp_unslash($_POST['fields']))), true);
                $raw_f = ($decoded_f !== false) ? json_decode($decoded_f, true) : null;
                if (is_array($raw_f)) {
                    $fields_override = self::sanitizeFields($raw_f);
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

        $toolbar_css = '
#fabricator-preview-toolbar{
    position:sticky;top:16px;flex-shrink:0;
    background:#fff;border:1px solid #dcdcde;border-radius:10px;
    box-shadow:0 4px 16px rgba(0,0,0,.12);
    padding:12px 14px;display:flex;flex-direction:column;gap:10px;
    font-family:system-ui,sans-serif;font-size:12px;
    width:190px;align-self:flex-start;
}
.fpt-row{display:flex;align-items:flex-start;gap:10px;}
.fpt-toggle{position:relative;flex-shrink:0;width:34px;height:20px;margin-top:1px;}
.fpt-toggle input{opacity:0;width:0;height:0;position:absolute;}
.fpt-slider{
    position:absolute;inset:0;border-radius:20px;
    background:#c3c4c7;cursor:pointer;transition:background .2s;
}
.fpt-slider::before{
    content:"";position:absolute;left:3px;top:3px;
    width:14px;height:14px;border-radius:50%;background:#fff;
    transition:transform .2s;
}
.fpt-toggle input:checked+.fpt-slider{background:#2271b1;}
.fpt-toggle input:checked+.fpt-slider::before{transform:translateX(14px);}
.fpt-label{display:flex;flex-direction:column;gap:2px;cursor:pointer;}
.fpt-label strong{font-size:12px;font-weight:600;color:#1d2327;}
.fpt-label span{font-size:11px;color:#787c82;line-height:1.4;}
.fpt-badge{
    display:inline-block;padding:2px 7px;border-radius:20px;font-size:10px;
    font-weight:700;text-transform:uppercase;letter-spacing:.4px;
    background:#f0f6fc;color:#2271b1;border:1px solid #c2d9f0;
}
@media(prefers-color-scheme:dark){
    #fabricator-preview-toolbar{background:#2c2c2c;border-color:#3c3c3c;box-shadow:0 4px 16px rgba(0,0,0,.4);}
    .fpt-label strong{color:#e0e0e0;}
}';

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
            . '}'
            . $toolbar_css;

        // Own WP_Styles/WP_Scripts, so the builder page's global registries stay out of the preview.
        $preview_styles = new \WP_Styles();
        $preview_styles->add(
            'fabricator-preview-fontawesome',
            FABRICATOR_FORMS_URL . 'assets/vendor/fontawesome/css/all.min.css',
            [],
            FABRICATOR_FORMS_VERSION
        );
        $preview_styles->add('fabricator-preview-front', $css_url, [], FABRICATOR_FORMS_VERSION);
        $preview_styles->add_inline_style('fabricator-preview-front', implode("\n", $field_css) . "\n" . $base_css);
        ob_start();
        $preview_styles->do_items(['fabricator-preview-fontawesome', 'fabricator-preview-front']);
        $styles_markup = ob_get_clean();

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
        $scripts_markup = ob_get_clean();

        $page = '<!DOCTYPE html><html lang="' . esc_attr(str_replace('_', '-', get_locale())) . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html__('Preview', 'formfabricator') . '</title>'
            . $styles_markup
            . '</head><body>'
            . '<div id="fabricator-preview-content">' . $html . '</div>'
            . $toolbar_html
            . $scripts_markup
            . '</body></html>';

        \wp_send_json_success(['html' => $page]);
    }

    /**
     * AJAX handler that saves form data.
     *
     * @return void
     */
    public static function ajaxSave(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require('edit_forms', 'fabricator_forms_admin_nonce', 'nonce');

        // base64-wrapped so WP's magic-quotes slashing can't corrupt embedded quotes in the raw JSON.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified via AjaxGuard::require(); sniff can't see through the static call.
        $encoded = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(\wp_unslash($_POST['form_data'] ?? '')));
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
            $sanitized_notifications = self::sanitizeNotifications(is_array($raw['notifications'] ?? null) ? $raw['notifications'] : []);
            $sanitized_fields        = self::sanitizeFields(is_array($raw['fields'] ?? null) ? $raw['fields'] : []);
            $id_error                = self::fieldIdError($sanitized_fields);
            if ($id_error !== '') {
                \wp_send_json_error(['message' => $id_error], 422);
            }
            $result = FormModel::save(
                [
                'title'         => \sanitize_text_field(is_string($raw_title) ? $raw_title : ''),
                'fields'        => $sanitized_fields,
                'notifications' => $sanitized_notifications,
                'settings'      => self::sanitizeSettings(is_array($raw['settings'] ?? null) ? $raw['settings'] : []),
                ],
                $form_id,
                true,
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
            \FabricatorForms\Utils\OptionMutex::run(
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
                    // autoload=false: nothing reads this on the front end (shouldAttachPdf() runs only
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
                    . self::consentPlaceholderWarning($sanitized_fields)
                    . ' '
                    . self::signatureDeliveryWarning((int) $result, $sanitized_fields, $sanitized_notifications)
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
     * Names the form itself posts next to its fields (FormRenderer); a field with one of these IDs would overwrite it.
     *
     * @var string[]
     */
    private const RESERVED_FIELD_IDS = ['action', 'form_id', 'fabricator_nonce', 'fabricator_submission_token', 'fabricator_hp_field'];

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
     * @param array $fields Sanitized fields, as sanitizeFields() returns them.
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
     * @param array $fields Sanitized fields, as sanitizeFields() returns them.
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
     * The save warning for a Consent field still showing its placeholder text, or ''. That text names no purpose and
     * no way to withdraw, so the consent would not be valid (GDPR Art. 7(3), 4(11)).
     *
     * @param array $fields Sanitized fields, as sanitizeFields() returns them.
     * @return string
     */
    private static function consentPlaceholderWarning(array $fields): string
    {
        $found = false;
        $walk  = static function (array $fields) use (&$walk, &$found): void {
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (($field['type'] ?? '') === 'consent' && \FabricatorForms\Fields\ConsentField::usesPlaceholderText($field)) {
                    $found = true;
                }
                if (is_array($field['children'] ?? null)) {
                    $walk($field['children']);
                }
            }
        };
        $walk($fields);
        $message = __('A Consent field still shows its placeholder text, which names no purpose and no way to withdraw, so it is not valid consent. Write what the visitor agrees to and how they can withdraw it.', 'formfabricator');
        return $found ? $message : '';
    }

    /**
     * Returns an error listing the field IDs a form can't use, or '' when every ID is usable.
     *
     * The builder's ID rule is client-side only, so an import could carry a duplicate ID, a name the form posts itself
     * ("action"), or characters PHP rewrites in POST keys, letting one field's input satisfy another's checks. Group
     * children share the POST namespace with top-level fields.
     *
     * @param array $fields Sanitized fields, as sanitizeFields() returns them.
     * @return string Translated error message, or ''.
     */
    public static function fieldIdError(array $fields): string
    {
        $ids      = [];
        $reserved = array_flip(self::RESERVED_FIELD_IDS);
        $collect  = static function (array $list) use (&$collect, &$ids, &$reserved): void {
            foreach ($list as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $id = (string) ($field['id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                    // A direct debit mandate posts its signature under "<id>-sig"; a field with that ID would replace it.
                    if (($field['type'] ?? '') === 'directdebit') {
                        $reserved[$id . '-sig'] = true;
                    }
                }
                if (!empty($field['children']) && is_array($field['children'])) {
                    $collect($field['children']);
                }
            }
        };
        $collect($fields);

        $counts = array_count_values($ids);
        $bad    = [];
        foreach ($ids as $id) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', $id) || isset($reserved[$id]) || $counts[$id] > 1) {
                $bad[$id] = true;
            }
        }
        if ($bad === []) {
            return '';
        }
        return sprintf(
            /* translators: %s: comma-separated list of field IDs. */
            __('These field IDs cannot be used: %s. A field ID may contain only letters, digits and hyphens, must be unique within the form, and must not be a reserved name such as "action".', 'formfabricator'),
            implode(', ', array_keys($bad))
        );
    }

    /**
     * Sanitizes the fields array from the builder.
     *
     * @param array $fields Raw fields array from the builder.
     * @return array Sanitized fields array.
     */
    public static function sanitizeFields(array $fields, int $depth = 0): array
    {
        /* Depth cap mirrors sanitizeArrayValue()'s (CWE-674): this recurses through group
           children, and an imported form is untrusted input that could nest them arbitrarily. */
        if ($depth > 10) {
            return [];
        }
        $clean = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['id']) || empty($field['type'])) {
                continue;
            }
            // Server-side half of admin-builder.js's NO_GROUP_TYPES: prevents nesting that would skip validation on grandchildren.
            if ($depth > 0 && in_array((string) $field['type'], self::NO_GROUP_TYPES, true)) {
                continue;
            }
            // These structural/label keys bypass sanitizeConfigValue() — every field type handles them the same way regardless of its own rules.
            $plaintext_keys = ['id', 'type', 'label', 'placeholder', 'description', 'hint', 'name'];
            $handler  = \FabricatorForms\Fields\FieldRegistry::get((string)($field['type'] ?? ''));
            $defaults = $handler ? $handler->getDefaultConfig() : [];
            $f = [];
            foreach ($field as $k => $v) {
                $sk = \sanitize_key($k);
                // A structural or label key is always text. An array here (a crafted save or import) would reach the PDF
                // template's typed label and throw a TypeError on every submission of the form.
                if (in_array($sk, $plaintext_keys, true) && !is_string($v)) {
                    $f[$sk] = is_scalar($v) ? \sanitize_text_field((string) $v) : '';
                    continue;
                }
                // Every other key keeps the kind of value (list or single) its default has; an array under a
                // single-value key would throw a TypeError on render. The wrong kind gets the default.
                if (array_key_exists($sk, $defaults)) {
                    if (is_array($defaults[$sk]) !== is_array($v)) {
                        $v = $defaults[$sk];
                    }
                } elseif (is_array($v) && !in_array($sk, self::STRUCTURAL_ARRAY_KEYS, true)) {
                    continue; // a list under a key no field declares has no reader that expects one
                }
                if (is_string($v)) {
                    $f[$sk] = in_array($sk, $plaintext_keys, true)
                        ? \sanitize_text_field($v)
                        // $sk, not $k: plainTextConfigKeys() are lowercase.
                        : ($handler ? $handler->sanitizeConfigValue($sk, $v) : \FabricatorForms\Utils\HtmlSanitizer::sanitize($v));
                } elseif (is_bool($v) || is_int($v) || is_float($v)) {
                    $f[$sk] = $v;
                } elseif (is_array($v)) {
                    // 'conditions'/'children' need their own sanitizers; HTML-sanitized rules would stop matching options.
                    if ($sk === 'conditions') {
                        $f[$sk] = self::sanitizeConditions($v);
                    } elseif ($sk === 'children') {
                        $f[$sk] = self::sanitizeFields($v, $depth + 1);
                    } else {
                        // The same plain-text/HTML split as sanitizeConfigValue().
                        $f[$sk] = self::sanitizeArrayValue(
                            $v,
                            0,
                            $handler ? $handler->isPlainTextConfigKey($sk) : false
                        );
                    }
                }
            }
            if (isset($f['options']) && is_array($f['options'])) {
                $clean_opts = [];
                $seen       = [];
                foreach ($f['options'] as $opt) {
                    if (!is_array($opt)) {
                        continue;
                    }
                    $label = \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($opt['label'] ?? ''));
                    $value = \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($opt['value'] ?? ''));
                    // A value is what the form posts and what rules, routing and the email compare: never empty (an
                    // option valued "" could not satisfy "required"), never shared (two options became one).
                    if ($value === '') {
                        $value = self::optionValueFromLabel($label, count($clean_opts) + 1);
                    }
                    $base = $value;
                    for ($n = 2; isset($seen[$value]); $n++) {
                        $value = $base . '-' . $n;
                    }
                    $seen[$value] = true;
                    $clean_opts[] = [
                        'value'   => $value,
                        'label'   => $label,
                        'default' => !empty($opt['default']),
                    ];
                }
                $f['options'] = $clean_opts;
            }
            $clean[] = $f;
        }
        return $clean;
    }

    /**
     * An option value for an option saved without one: the builder's slugify() of its label, or "option-N" when
     * nothing ASCII is left. Not sanitize_title(), whose percent-encoding the submitted value would lose.
     *
     * @param string $label    Sanitized option label.
     * @param int    $position 1-based position of the option.
     * @return string
     */
    private static function optionValueFromLabel(string $label, int $position): string
    {
        $ascii = function_exists('remove_accents') ? \remove_accents($label) : $label;
        $slug  = trim((string) preg_replace('/-{2,}/', '-', (string) preg_replace('/[^a-z0-9-]/', '', (string) preg_replace('/\s+/', '-', strtolower($ascii)))), '-');
        return $slug !== '' ? $slug : 'option-' . $position;
    }

    /**
     * Sanitizes a field-id reference; must match sanitizeFields()'s id sanitization exactly — sanitize_key() lowercases, which breaks mixed-case ids.
     *
     * @param mixed $raw Raw field-id reference from the builder payload.
     * @return string Sanitized field-id reference.
     */
    private static function sanitizeFieldRef(mixed $raw): string
    {
        return \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($raw ?? ''));
    }

    /**
     * Sanitizes a field's conditional-logic block; rule values must match sanitize_text_field()'d option values.
     *
     * @param array $raw Raw conditions block: action, match, rules[].
     * @return array Sanitized conditions block.
     */
    private static function sanitizeConditions(array $raw): array
    {
        $rules = [];
        foreach ((array) ($raw['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $rules[] = [
                'field_id' => self::sanitizeFieldRef($rule['field_id'] ?? null),
                'operator' => \sanitize_key(\FabricatorForms\Utils\Cast::stringOrDefault($rule['operator'] ?? 'equals', 'equals')),
                'value'    => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['value'] ?? '')),
            ];
        }
        return [
            'action' => in_array($raw['action'] ?? '', ['show', 'hide'], true) ? $raw['action'] : 'show',
            'match'  => in_array($raw['match'] ?? '', ['all', 'any'], true) ? $raw['match'] : 'all',
            'rules'  => $rules,
        ];
    }

    /**
     * Recursively sanitizes a nested array-valued field config entry (depth-capped against CWE-674).
     *
     * @param array $value      Raw nested array value.
     * @param int   $depth      Current recursion depth (internal use).
     * @param bool  $plain_text Sanitize string leaves with sanitize_text_field() instead of
     *                          Utils\HtmlSanitizer::sanitize(); set for config keys the field declares plain text,
     *                          whose values are rendered through text sinks and would otherwise be
     *                          entity-encoded at save and shown encoded.
     * @return array Recursively sanitized array.
     */
    private static function sanitizeArrayValue(array $value, int $depth = 0, bool $plain_text = false): array
    {
        if ($depth > 10) {
            return [];
        }
        $clean = [];
        foreach ($value as $k => $v) {
            $sk = is_string($k) ? \sanitize_key($k) : $k;
            if (is_string($v)) {
                $clean[$sk] = $plain_text ? \sanitize_text_field($v) : \FabricatorForms\Utils\HtmlSanitizer::sanitize($v);
            } elseif (is_bool($v) || is_int($v) || is_float($v)) {
                $clean[$sk] = $v;
            } elseif (is_array($v)) {
                $clean[$sk] = self::sanitizeArrayValue($v, $depth + 1, $plain_text);
            }
        }
        return $clean;
    }

    /**
     * Sanitizes the notifications array.
     *
     * @param array $notifications Raw notifications array.
     * @return array Sanitized notifications array.
     */
    public static function sanitizeNotifications(array $notifications): array
    {
        $clean = [];
        foreach ($notifications as $n) {
            if (!is_array($n)) {
                continue;
            }
            $routing_rules = [];
            foreach ((array)($n['routing_rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $routing_rules[] = [
                    'field_id' => self::sanitizeFieldRef($rule['field_id'] ?? null),
                    'operator' => \sanitize_key(\FabricatorForms\Utils\Cast::stringOrDefault($rule['operator'] ?? 'equals', 'equals')),
                    'value'    => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['value'] ?? '')),
                    // An address or a placeholder, whose braces sanitize_email() would strip.
                    'email'    => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['email'] ?? '')),
                    'cc'       => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['cc'] ?? '')),
                    'bcc'      => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['bcc'] ?? '')),
                ];
            }
            $clean[] = [
                'slug'             => \sanitize_key(\FabricatorForms\Utils\Cast::stringOrDefault($n['slug'] ?? '', 'notification-' . \wp_generate_uuid4())),
                'name'             => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['name']       ?? '')),
                'recipient_mode'   => in_array($n['recipient_mode'] ?? '', ['single', 'routing'], true)
                    ? $n['recipient_mode'] : 'single',
                'to'               => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['to']         ?? '')),
                'routing_rules'    => $routing_rules,
                'routing_fallback' =>
                    \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['routing_fallback'] ?? '')),
                'routing_fallback_cc' =>
                    \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['routing_fallback_cc'] ?? '')),
                'routing_fallback_bcc' =>
                    \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['routing_fallback_bcc'] ?? '')),
                'reply_to'         => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['reply_to']   ?? '')),
                'subject'          => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['subject']    ?? '')),
                /* Body is always HTML, authored via the Visual or Code view. */
                'body'             => self::sanitizeEmailBody(\FabricatorForms\Utils\Cast::stringOrDefault($n['body'] ?? '')),
                'from_name'        => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['from_name']  ?? '')),
                'from_email'       => self::sanitizeFromEmail(\FabricatorForms\Utils\Cast::stringOrDefault($n['from_email'] ?? '')),
                'cc'               => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['cc']         ?? '')),
                'bcc'              => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['bcc']        ?? '')),
                'attach_pdf'       => !empty($n['attach_pdf']),
                'attach_uploads'   => !empty($n['attach_uploads']),
                'enabled'          => !isset($n['enabled']) || !empty($n['enabled']),
            ];
        }
        return $clean;
    }

    /**
     * A fixed address, or {admin_email}. Never a form field: mail from a visitor's domain can be silently discarded
     * under DMARC, losing the submission. Anything else is dropped, and the site's sender applies.
     *
     * @param string $raw Raw sender value from the builder.
     * @return string Sanitized address, '{admin_email}', or '' for the site's sender.
     */
    private static function sanitizeFromEmail(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '{admin_email}') {
            return $raw;
        }
        return \sanitize_email($raw);
    }

    /**
     * Removes each <tag>…</tag> element, or a lone opening tag that never closes, in one forward pass.
     *
     * @param string   $html HTML to clean.
     * @param string[] $tags Lowercase tag names.
     * @return string
     */
    private static function stripElements(string $html, array $tags): string
    {
        foreach ($tags as $tag) {
            $out     = '';
            $offset  = 0;
            $removed = 0;
            // Latched: with no closing tag after one opener there is none after any later one, so the search to the end
            // runs once. Searched again for every unclosed opener, a body of them took seconds (400 KB: 6.5 s).
            $no_close = false;
            while (preg_match('/<' . $tag . '\b/i', $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $start = $m[0][1];
                $out  .= substr($html, $offset, $start - $offset);
                $close = $no_close ? false : stripos($html, '</' . $tag, $start);
                $no_close = $close === false;
                $gt    = strpos($html, '>', $close !== false ? $close : $start);
                $offset = $gt === false ? strlen($html) : $gt + 1;
                $removed++;
            }
            if ($removed > 0) {
                $html = $out . substr($html, $offset);
                \FabricatorForms\fabricator_log('FabricatorForms sanitizeEmailBody [' . $tag . '-tags] removed ' . $removed . ' match(es)');
            }
        }
        return $html;
    }

    /**
     * Sanitizes the form settings array.
     *
     * @param array $settings Raw settings array.
     * @return array Sanitized settings array.
     */
    public static function sanitizeSettings(array $settings): array
    {
        $rules = [];
        foreach ((array)($settings['submit_conditions']['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $rules[] = [
                'field_id'   => self::sanitizeFieldRef($rule['field_id']   ?? null),
                'operator'   => \sanitize_key(\FabricatorForms\Utils\Cast::stringOrDefault($rule['operator']   ?? 'equals', 'equals')),
                'value'      => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['value'] ?? '')),
                'use_option' => !empty($rule['use_option']),
            ];
        }

        return [
            'submit_label'      => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($settings['submit_label']    ?? '', __('Submit', 'formfabricator'))),
            'submit_working'    => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($settings['submit_working']   ?? '', __('Sending…', 'formfabricator'))),
            // Plain text: it is shown via .textContent.
            'success_message'   => \sanitize_textarea_field(\FabricatorForms\Utils\Cast::stringOrDefault($settings['success_message'] ?? '', __('Thank you!', 'formfabricator'))),
            // No 'enabled' flag: having rules switches this on.
            'submit_conditions' => [
                'match'   => in_array($settings['submit_conditions']['match'] ?? '', ['all', 'any'], true)
                    ? $settings['submit_conditions']['match'] : 'all',
                'rules'   => $rules,
            ],
        ];
    }

    /**
     * Sanitizes an HTML email body in two layers: regex passes reach <style> contents wp_kses skips, then wp_kses() as defense-in-depth.
     *
     * @param string $html Raw HTML body from the notification editor.
     * @return string Sanitized HTML.
     */
    private static function sanitizeEmailBody(string $html): string
    {
        $before = $html;

        // Embeddable content has no place in an email body, so whole elements go, by a linear scan.
        $html = self::stripElements($html, ['iframe', 'object']);

        // No regex below scans unboundedly from a point that may never match: each anchors on a fixed token.
        $passes = [
            // Removing every opening and closing tag neutralizes a script; a whole-element pass after these had nothing left to match.
            'script-open'   => '/<script\b[^>]*>/i',
            'script-close'  => '/<\/script\s*>/i',
            'embed-tags'    => '/<embed\b[^>]*\/?>/i',
            // Meta refresh is a classic open-redirect/auto-navigate vector in
            // rendered HTML mail and has no legitimate use in a notification body.
            'meta-refresh'  => '/<meta\b[^>]*http-equiv\s*=\s*["\']?\s*refresh[^>]*>/i',
            // "/" is an attribute boundary too (<img/onerror=...>). A lookbehind, so long whitespace runs don't backtrack.
            'event-handlers' =>
                '/(?<=[\s\/])on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            'js-uris'
                => '/\b(href|src|action)\s*=\s*(["\'])\s*'
                . '(?:javascript|vbscript)\s*:[^"\']*\2/i',
            // Unquoted attribute values are valid HTML (<a href=javascript:...>)
            // and aren't matched by the quoted pattern above at all.
            'js-uris-unquoted'
                => '/\b(href|src|action)\s*=\s*(?!["\'])'
                . '(?:javascript|vbscript)\s*:[^\s>]*/i',
            // style="...url('javascript:...')..." is handled by the decoded-attribute pass below, which also covers
            // entity-encoded and comment-split forms; a separate regex here backtracked quadratically on long styles.
        ];

        $replacements = [
            'script-open'       => '',
            'script-close'      => '',
            'embed-tags'        => '',
            'meta-refresh'      => '',
            'event-handlers'    => '',
            'js-uris-unquoted'  => '$1=""',
        ];
        $replacements['js-uris'] = '$1=$2#$2';

        foreach ($passes as $label => $pattern) {
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceDyn -- $pattern/$replacements come from the hardcoded arrays above, not attacker input.
            $after = preg_replace($pattern, $replacements[$label], $html) ?? $html;
            if ($after !== $html) {
                // Count-only, no stripped content logged; fabricator_log() is WP_DEBUG-gated, a debug aid not an audit trail.
                preg_match_all($pattern, $html, $m);
                \FabricatorForms\fabricator_log(
                    'FabricatorForms sanitizeEmailBody [' . $label . '] removed '
                    . count($m[0] ?? []) . ' match(es)'
                );
            }
            $html = $after;
        }

        /* wp_kses() only filters tags/attributes, never <style> element contents — scrutinize its CSS here. */
        $style_before = $html;
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $html = preg_replace_callback(
            '#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
            static function (array $m): string {
                return $m[1] . self::sanitizeStyleBlockCss($m[2]) . $m[3];
            },
            $html
        ) ?? $html;
        if ($html !== $style_before) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms sanitizeEmailBody [style-block-css] neutralized active CSS constructs'
            );
        }

        // Guards against entity-encoded or CSS-comment-split "javascript:" evading the passes above.
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $html = preg_replace_callback(
            '/\b(href|src|action|style)\s*=\s*(?:(["\'])((?:(?!\2).)*)\2|([^\s>]+))/is',
            static function (array $m): string {
                $attr    = $m[1];
                $quoted  = $m[2] !== '';
                $q       = $quoted ? $m[2] : '';
                $val     = $quoted ? $m[3] : ($m[4] ?? '');
                $decoded = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceWeird -- hardcoded literal pattern stripping CSS comments from the decoded value; no /e modifier.
                $decoded = preg_replace('#/\*.*?\*/#s', '', $decoded);
                // Browsers ignore tab/CR/LF in a URL scheme ("jav\tascript:").
                $decoded = str_replace(["\t", "\n", "\r"], '', (string) $decoded);
                if (preg_match('/javascript\s*:|vbscript\s*:/i', $decoded)) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms sanitizeEmailBody [encoded-js-uri] stripped '
                        . $attr . ' value: ' . substr($val, 0, 200)
                    );
                    return $quoted ? ($attr . '=' . $q . $q) : ($attr . '=""');
                }
                return $m[0];
            },
            $html
        ) ?? $html;

        // Content appended after </html> (e.g. {all_fields} tacked on) breaks email clients; move it to just before </body> instead.
        $close_pos = strripos($html, '</html>');
        if ($close_pos !== false) {
            $orphan = trim(substr($html, $close_pos + 7));
            if ($orphan !== '') {
                $html = substr($html, 0, $close_pos + 7);
                $body_close = strripos($html, '</body>');
                if ($body_close !== false) {
                    $html = substr($html, 0, $body_close)
                        . "\n" . $orphan . "\n"
                        . substr($html, $body_close);
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms sanitizeEmailBody: moved orphaned content'
                        . ' from after </html> to before </body>: '
                        . substr($orphan, 0, 100)
                    );
                }
            }
        }

        // Layer 2: wp_kses() allow-list. DOCTYPE is kept by hand, as wp_kses doesn't know it.
        $doctype = '';
        if (preg_match('/^\s*<!DOCTYPE[^>]*>/i', $html, $dm)) {
            $doctype = $dm[0];
            $html    = substr($html, strlen($dm[0]));
        }

        $allowed_email_tags = [
            // Document wrapper — see comment above for why this must stay.
            'html'  => ['lang' => true],
            'head'  => [],
            'body'  => ['style' => true, 'bgcolor' => true],
            'title' => [],
            // No http-equiv (a refresh redirect) and no <link> (a remote stylesheet is a read-tracking beacon).
            'meta'  => ['charset' => true, 'content' => true, 'name' => true],
            'style' => ['type' => true, 'media' => true],

            // Layout & content — HTML-email-safe subset.
            'table'    => ['style' => true, 'width' => true, 'height' => true, 'border' => true,
                           'cellpadding' => true, 'cellspacing' => true, 'align' => true,
                           'bgcolor' => true, 'role' => true, 'class' => true, 'id' => true],
            'caption'  => ['style' => true, 'class' => true],
            'colgroup' => ['style' => true, 'class' => true],
            'col'      => ['style' => true, 'span' => true, 'width' => true],
            'thead'    => ['style' => true, 'class' => true],
            'tbody'    => ['style' => true, 'class' => true],
            'tfoot'    => ['style' => true, 'class' => true],
            'tr'       => ['style' => true, 'align' => true, 'valign' => true, 'bgcolor' => true, 'class' => true, 'id' => true],
            'td'       => ['style' => true, 'align' => true, 'valign' => true, 'width' => true,
                           'height' => true, 'colspan' => true, 'rowspan' => true, 'bgcolor' => true,
                           'class' => true, 'id' => true, 'scope' => true],
            'th'       => ['style' => true, 'align' => true, 'valign' => true, 'width' => true,
                           'height' => true, 'colspan' => true, 'rowspan' => true, 'bgcolor' => true,
                           'class' => true, 'id' => true, 'scope' => true],
            'div'      => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'span'     => ['style' => true, 'class' => true, 'id' => true],
            'p'        => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'a'        => ['style' => true, 'href' => true, 'title' => true, 'target' => true,
                           'rel' => true, 'class' => true, 'id' => true],
            'img'      => ['style' => true, 'src' => true, 'alt' => true, 'title' => true,
                           'width' => true, 'height' => true, 'align' => true, 'border' => true,
                           'class' => true, 'id' => true],
            'br'       => [],
            'hr'       => ['style' => true, 'class' => true],
            'strong'   => ['style' => true],
            'em'       => ['style' => true],
            'b'        => ['style' => true],
            'i'        => ['style' => true],
            'u'        => ['style' => true],
            'mark'     => ['style' => true, 'class' => true],
            'small'    => ['style' => true],
            'del'      => ['style' => true, 'cite' => true],
            'ins'      => ['style' => true, 'cite' => true],
            'sup'      => ['style' => true],
            'sub'      => ['style' => true],
            'ul'       => ['style' => true, 'class' => true, 'id' => true],
            'ol'       => ['style' => true, 'class' => true, 'id' => true],
            'li'       => ['style' => true, 'class' => true, 'id' => true],
            'dl'       => ['style' => true, 'class' => true],
            'dt'       => ['style' => true],
            'dd'       => ['style' => true],
            'h1'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h2'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h3'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h4'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h5'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h6'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'blockquote' => ['style' => true, 'class' => true, 'cite' => true],
            'pre'      => ['style' => true, 'class' => true],
            'code'     => ['style' => true, 'class' => true],
            'kbd'      => ['style' => true],
            'samp'     => ['style' => true],
            'var'      => ['style' => true],
            'cite'     => ['style' => true],
            'abbr'     => ['style' => true, 'title' => true],
            'time'     => ['style' => true, 'datetime' => true],
            'q'        => ['style' => true, 'cite' => true],
            'details'  => ['style' => true, 'class' => true, 'open' => true],
            'summary'  => ['style' => true],
            'progress' => ['style' => true, 'value' => true, 'max' => true],
            'meter'    => ['style' => true, 'value' => true, 'min' => true, 'max' => true,
                           'low' => true, 'high' => true, 'optimum' => true],
            'section'  => ['style' => true, 'class' => true, 'id' => true],
            'header'   => ['style' => true, 'class' => true, 'id' => true],
            'footer'   => ['style' => true, 'class' => true, 'id' => true],
            'article'  => ['style' => true, 'class' => true, 'id' => true],
            'aside'    => ['style' => true, 'class' => true, 'id' => true],
            'nav'      => ['style' => true, 'class' => true, 'id' => true],
            'address'  => ['style' => true, 'class' => true],
            // No action/method: a <form> that submits somewhere is a phishing form inside a notification email.
            'form'     => ['style' => true, 'class' => true, 'id' => true],
            'label'    => ['style' => true, 'class' => true, 'for' => true],
            'input'    => ['style' => true, 'class' => true, 'id' => true, 'type' => true, 'name' => true,
                           'value' => true, 'placeholder' => true, 'checked' => true, 'disabled' => true],
            'select'   => ['style' => true, 'class' => true, 'id' => true, 'name' => true, 'multiple' => true],
            'option'   => ['value' => true, 'selected' => true],
            'textarea' => ['style' => true, 'class' => true, 'id' => true, 'name' => true, 'rows' => true, 'cols' => true],
            'button'   => ['style' => true, 'class' => true, 'id' => true, 'type' => true],
            'noscript' => [],
        ];

        // 'safe_style_css' takes the property list; 'safecss_filter_attr_allow_css' would pass a bool.
        $allow_email_css = static function (array $allowed_properties): array {
            return array_unique(
                array_merge(
                    $allowed_properties,
                    [
                        'overflow', 'border-radius', 'display', 'background-color',
                        'padding', 'margin', 'font-family', 'font-size', 'color',
                        'text-align', 'width', 'max-width', 'border', 'border-collapse',
                        'vertical-align', 'line-height', 'background', 'box-sizing',
                        'white-space', 'text-decoration', 'font-weight', 'letter-spacing',
                        'border-top', 'border-right', 'border-bottom', 'border-left',
                        'border-spacing', 'padding-top', 'padding-right', 'padding-bottom',
                        'padding-left', 'margin-top', 'margin-right', 'margin-bottom',
                        'margin-left',
                    ]
                )
            );
        };
        // Removed in finally, so a throw inside wp_kses() can't leave it hooked.
        \add_filter('safe_style_css', $allow_email_css);
        try {
            $html = \wp_kses($html, $allowed_email_tags);
        } finally {
            \remove_filter('safe_style_css', $allow_email_css);
        }

        $html = $doctype . $html;

        // Force rel="noopener noreferrer" on target="_blank" links (reverse-tabnabbing defense-in-depth).
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $html = preg_replace_callback(
            '/<a\b([^>]*\btarget=["\']_blank["\'][^>]*)>/i',
            static function (array $m): string {
                if (preg_match('/\brel=["\']([^"\']*)["\']/i', $m[1], $rel_match)) {
                    $rel     = $rel_match[1];
                    $needed  = array_diff(['noopener', 'noreferrer'], explode(' ', $rel));
                    if (empty($needed)) {
                        return $m[0];
                    }
                    $new_rel = trim($rel . ' ' . implode(' ', $needed));
                    return '<a' . preg_replace('/\brel=["\'][^"\']*["\']/i', 'rel="' . esc_attr($new_rel) . '"', $m[1]) . '>';
                }
                return '<a' . $m[1] . ' rel="noopener noreferrer">';
            },
            $html
        ) ?? $html;

        // Log lengths only (no content) when something was stripped, as a production audit trail.
        if ($html !== $before) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms sanitizeEmailBody: input length '
                . strlen($before) . ' → output length ' . strlen($html)
            );
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms sanitizeEmailBody: nothing stripped '
                . '(input length ' . strlen($html) . ')'
            );
        }

        return $html;
    }

    /**
     * Resolves CSS identifier escapes (e.g. \65 → 'e') so keyword filters can't be evaded by escaping.
     *
     * @param string $css Raw CSS text.
     * @return string CSS with identifier escapes resolved.
     */
    private static function decodeCssEscapes(string $css): string
    {
        if (!str_contains($css, '\\')) {
            return $css;
        }
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        return (string) preg_replace_callback(
            '/\\\\(?:([0-9A-Fa-f]{1,6})[ \t\r\n\f]?|([^\r\n\f0-9A-Fa-f]))/',
            static function (array $m): string {
                if (($m[1] ?? '') !== '') {
                    $code = (int) hexdec($m[1]);
                    // Printable ASCII only; NUL and anything non-ASCII is dropped, not emitted.
                    return ($code >= 0x20 && $code <= 0x7E) ? chr($code) : '';
                }
                return $m[2] ?? '';
            },
            $css
        );
    }

    /**
     * Neutralizes script-executing and remote-fetching constructs inside a <style> block's CSS.
     *
     * @param string $css Raw CSS text from between <style> and </style>.
     * @return string CSS with active constructs neutralized.
     */
    private static function sanitizeStyleBlockCss(string $css): string
    {
        // Comments first: "expr/**/ession(" would otherwise slip past every keyword match below.
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceWeird -- hardcoded literal pattern stripping CSS comments; no /e modifier.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        // CSS escapes first: a browser reads "@\69 mport" as "@import".
        $css = self::decodeCssEscapes($css);

        // @import pulls a remote stylesheet — a tracking/exfiltration vector in mail, and the
        // fetched CSS would never pass through this function at all. Drop the whole at-rule.
        $css = (string) preg_replace('/@\s*import\b[^;{}]*(?:;|(?=[{}])|$)/i', '', $css);

        // expression() is legacy-IE script execution. Renaming the function makes the whole
        // declaration invalid CSS, so browsers drop it — visible in source, inert in effect.
        $css = (string) preg_replace('/\bexpression\s*\(/i', 'expression-blocked(', $css);

        // behavior: (IE HTC) and -moz-binding: (XBL) both attach executable code to an element.
        $css = (string) preg_replace('/(?:-moz-)?\bbinding\s*:[^;}]*/i', '', $css);
        $css = (string) preg_replace('/\bbehavior\s*:[^;}]*/i', '', $css);

        // url() with a script/document scheme; normalized first since browsers strip tab/CR/LF before parsing it. data:image/* is kept.
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $css = (string) preg_replace_callback(
            '/url\s*\(\s*(["\']?)(.*?)\1\s*\)/is',
            static function (array $m): string {
                $url = (string) html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url = str_replace(["\t", "\n", "\r", "\0"], '', $url);
                if (preg_match('#^\s*(?:javascript|vbscript)\s*:#i', $url)) {
                    return 'url()';
                }
                if (preg_match('#^\s*data\s*:#i', $url) && !preg_match('#^\s*data\s*:\s*image/#i', $url)) {
                    return 'url()';
                }
                return $m[0];
            },
            $css
        ) ?? $css;

        return $css;
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
                'urlPlaceholder'     => __('https://…', 'formfabricator'),
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
