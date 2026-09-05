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
 * @version   1.0.5
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
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            \wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        \check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');

        $form_id = absint(\wp_unslash($_POST['form_id'] ?? 0));
        $post    = $form_id ? \get_post($form_id) : null;
        if (!$post || $post->post_type !== 'fabricator_form') {
            \wp_send_json_error(['message' => 'Invalid form_id'], 400);
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
                'read',
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

        $perf_mode   = defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options');
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
        $data_form    = \wp_json_encode($form_data, JSON_HEX_APOS | JSON_HEX_QUOT);
        $data_palette = \wp_json_encode($palette, JSON_HEX_APOS | JSON_HEX_QUOT);
        $ajax_url     = \esc_attr(\admin_url('admin-ajax.php'));

        /* assets/js/admin-builder.js (the drag-and-drop builder) is enqueued by
           Assets::enqueueAdmin() under the 'fabricator-forms-builder' handle — that
           enqueue always runs before this render() callback (admin_enqueue_scripts
           fires before the admin_menu page-render callback), so localizing the
           already-registered handle here is safe; wp_localize_script() only stores the
           data, WordPress prints it as part of the enqueued script output later. */
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
                false  /* in <head> so it registers its DOMContentLoaded listener BEFORE admin-builder.js */
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
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            \wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        \check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');

        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;

        // Catches so a field-handler exception (e.g. malformed HTML block) returns a JSON error
        // instead of breaking WP's response entirely.
        try {
            // base64-wrapped like ajaxSave()'s 'form_data', so sanitize_*_field() doesn't strip tags out of embedded HTML.
            $settings_override = [];
            if (!empty($_POST['settings'])) {
                $decoded_s = base64_decode(\FabricatorForms\Utils\Sanitize::str(sanitize_text_field(\wp_unslash($_POST['settings']))), true);
                $raw_s = ($decoded_s !== false) ? json_decode($decoded_s, true) : null;
                if (is_array($raw_s)) {
                    $settings_override = self::sanitizeSettings($raw_s);
                }
            }

            /* Use live editor fields when posted; fall back to saved DB state. */
            $fields_override = null;
            if (!empty($_POST['fields'])) {
                $decoded_f = base64_decode(\FabricatorForms\Utils\Sanitize::str(sanitize_text_field(\wp_unslash($_POST['fields']))), true);
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

        /* Collect all field-specific CSS (mirrors Assets::enqueueFront). */
        $field_css = [];
        foreach (\FabricatorForms\Fields\FieldRegistry::all() as $class) {
            $css = trim((new $class())->getStyles());
            if ($css !== '') {
                $field_css[] = $css;
            }
        }

        /* Collect FabricatorFieldInits. */
        $inits = [];
        foreach (\FabricatorForms\Fields\FieldRegistry::all() as $type => $class) {
            $fn = (new $class())->getClientInit();
            if ($fn !== '') {
                $inits[] = \wp_json_encode($type) . ':' . trim($fn);
            }
        }

        /* Collect FabricatorValidators. */
        $pairs = [];
        $seen  = [];
        foreach (\FabricatorForms\Fields\FieldRegistry::all() as $class) {
            foreach ((new $class())->getClientValidation() as $entry) {
                $rule = $entry['rule'] ?? '';
                $fn   = $entry['fn']   ?? '';
                if ($rule === '' || $fn === '' || isset($seen[$rule])) {
                    continue;
                }
                $seen[$rule] = true;
                $pairs[]     = \wp_json_encode($rule) . ':' . trim($fn);
            }
        }

        /* Collect FabricatorEmptyChecks. */
        $empty_checks = [];
        foreach (\FabricatorForms\Fields\FieldRegistry::all() as $type => $class) {
            $entry = (new $class())->getClientEmptyCheck();
            if (!empty($entry['fn'])) {
                $empty_checks[] = \wp_json_encode($type) . ':' . trim($entry['fn']);
            }
        }

        /* Collect FabricatorSkipValidation. */
        $skip = [];
        foreach (\FabricatorForms\Fields\FieldRegistry::all() as $type => $class) {
            if ((new $class())->skipValidation()) {
                $skip[] = \wp_json_encode($type);
            }
        }

        $css_url = \FABRICATOR_FORMS_URL . 'assets/css/front.css';

        $globals = 'window.FabricatorForms={'
            . 'ajaxUrl:"",ibanBicUrl:"",'
            . 'i18n:{submitting:' . \wp_json_encode(__('Sending…', 'formfabricator')) . ',error_server:' . \wp_json_encode(__('Server error.', 'formfabricator')) . '}'
            . '};';
        if (!empty($inits)) {
            $globals .= 'window.FabricatorFieldInits={' . implode(',', $inits) . '};';
        }
        if (!empty($pairs)) {
            $globals .= 'window.FabricatorValidators={' . implode(',', $pairs) . '};';
        }
        if (!empty($empty_checks)) {
            $globals .= 'window.FabricatorEmptyChecks={' . implode(',', $empty_checks) . '};';
        }
        if (!empty($skip)) {
            $globals .= 'window.FabricatorSkipValidation=[' . implode(',', $skip) . '];';
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

        // Fresh, isolated WP_Styles/WP_Scripts instances scoped to only this preview's assets, since the global registries can't be reused without dragging in the whole builder page.
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
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            \wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        \check_ajax_referer('fabricator_forms_admin_nonce', 'nonce');

        // base64-wrapped so admin-builder.js can send the raw JSON as a plain string field
        // without WP's magic-quotes slashing corrupting embedded quotes before we get here
        $encoded = \FabricatorForms\Utils\Sanitize::str(sanitize_text_field(\wp_unslash($_POST['form_data'] ?? '')));
        $json    = base64_decode($encoded, true);
        $raw     = ($json !== false) ? json_decode($json, true) : null;
        if (!is_array($raw)) {
            \wp_send_json_error(['message' => 'Invalid form data'], 400);
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
            $result = FormModel::save(
                [
                'title'         => \sanitize_text_field(is_string($raw_title) ? $raw_title : ''),
                'fields'        => self::sanitizeFields(is_array($raw['fields'] ?? null) ? $raw['fields'] : []),
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

            /* Save PDF attachment settings from notifications */
            $pdf_settings = \get_option('fabricator_forms_pdf_settings', []);
            foreach ($sanitized_notifications as $notif) {
                $slug = $notif['slug'] ?? '';
                if ($slug) {
                    $pdf_settings[$result . '|' . $slug] = !empty($notif['attach_pdf']) ? 1 : 0;
                }
            }
            \update_option('fabricator_forms_pdf_settings', $pdf_settings);

            \wp_send_json_success(
                [
                'message'  => __('Form saved.', 'formfabricator'),
                'form_id'  => $result,
                'snapshot' => FormModel::snapshot((int) $result),
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
     * Sanitizes the fields array from the builder.
     *
     * @param array $fields Raw fields array from the builder.
     * @return array Sanitized fields array.
     */
    public static function sanitizeFields(array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['id']) || empty($field['type'])) {
                continue;
            }
            // These structural/label keys bypass each field type's sanitizeConfigValue()
            // below — they're plain identifiers/labels, not rich config values, and every
            // field type needs them handled the same way regardless of its own rules
            $plaintext_keys = ['id', 'type', 'label', 'placeholder', 'description', 'hint', 'name'];
            $handler = \FabricatorForms\Fields\FieldRegistry::get((string)($field['type'] ?? ''));
            $f = [];
            foreach ($field as $k => $v) {
                $sk = \sanitize_key($k);
                if (is_string($v)) {
                    $f[$sk] = in_array($k, $plaintext_keys, true)
                        ? \sanitize_text_field($v)
                        : ($handler ? $handler->sanitizeConfigValue($k, $v) : \wp_kses_post($v));
                } elseif (is_bool($v) || is_int($v) || is_float($v)) {
                    $f[$sk] = $v;
                } elseif (is_array($v)) {
                    $f[$sk] = self::sanitizeArrayValue($v);
                }
            }
            if (isset($f['options']) && is_array($f['options'])) {
                $clean_opts = [];
                foreach ($f['options'] as $opt) {
                    if (is_array($opt)) {
                        $clean_opts[] = [
                            'value'   => \sanitize_text_field($opt['value'] ?? ''),
                            'label'   => \sanitize_text_field($opt['label'] ?? ''),
                            'default' => !empty($opt['default']),
                        ];
                    } else {
                        $clean_opts[] = \sanitize_text_field((string)$opt);
                    }
                }
                $f['options'] = $clean_opts;
            }
            $clean[] = $f;
        }
        return $clean;
    }

    /**
     * Recursively sanitizes a nested array-valued field config entry (depth-capped against CWE-674).
     *
     * @param array $value Raw nested array value.
     * @param int   $depth Current recursion depth (internal use).
     * @return array Recursively sanitized array.
     */
    private static function sanitizeArrayValue(array $value, int $depth = 0): array
    {
        if ($depth > 10) {
            return [];
        }
        $clean = [];
        foreach ($value as $k => $v) {
            $sk = is_string($k) ? \sanitize_key($k) : $k;
            if (is_string($v)) {
                $clean[$sk] = \wp_kses_post($v);
            } elseif (is_bool($v) || is_int($v) || is_float($v)) {
                $clean[$sk] = $v;
            } elseif (is_array($v)) {
                $clean[$sk] = self::sanitizeArrayValue($v, $depth + 1);
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
                    'field_id' => \sanitize_key(\FabricatorForms\Utils\Sanitize::str($rule['field_id'] ?? '')),
                    'operator' => \sanitize_key(\FabricatorForms\Utils\Sanitize::str($rule['operator'] ?? 'equals', 'equals')),
                    'value'    => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($rule['value'] ?? '')),
                    /* May be a literal address or a {field_id}/{admin_email}
                       placeholder resolved at send time — sanitize_email()
                       would strip the braces, so keep it as plain text. */
                    'email'    => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($rule['email'] ?? '')),
                ];
            }
            $clean[] = [
                'slug'             => \sanitize_key(\FabricatorForms\Utils\Sanitize::str($n['slug'] ?? '', 'notification-' . \wp_generate_uuid4())),
                'name'             => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($n['name']       ?? '')),
                'recipient_mode'   => in_array($n['recipient_mode'] ?? '', ['single', 'routing'], true)
                    ? $n['recipient_mode'] : 'single',
                'to'               => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($n['to']         ?? '')),
                'routing_rules'    => $routing_rules,
                'routing_fallback' =>
                    \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($n['routing_fallback'] ?? '')),
                'reply_to'         => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($n['reply_to']   ?? '')),
                'subject'          => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($n['subject']    ?? '')),
                /* Body is always HTML, authored via the Visual or Code view. */
                'body'             => self::sanitizeEmailBody(\FabricatorForms\Utils\Sanitize::str($n['body'] ?? '')),
                'from_name'        => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($n['from_name']  ?? '')),
                'from_email'       => \sanitize_email(\FabricatorForms\Utils\Sanitize::str($n['from_email']      ?? '')),
                'cc'               => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($n['cc']         ?? '')),
                'bcc'              => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($n['bcc']        ?? '')),
                'attach_pdf'       => !empty($n['attach_pdf']),
                'attach_uploads'   => !empty($n['attach_uploads']),
                'enabled'          => !isset($n['enabled']) || !empty($n['enabled']),
            ];
        }
        return $clean;
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
                'field_id'   => \sanitize_key(\FabricatorForms\Utils\Sanitize::str($rule['field_id']   ?? '')),
                'operator'   => \sanitize_key(\FabricatorForms\Utils\Sanitize::str($rule['operator']   ?? 'equals', 'equals')),
                'value'      => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($rule['value'] ?? '')),
                'use_option' => !empty($rule['use_option']),
            ];
        }

        return [
            'submit_label'      => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($settings['submit_label']    ?? '', __('Submit', 'formfabricator'))),
            'submit_working'    => \sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($settings['submit_working']   ?? '', __('Sending…', 'formfabricator'))),
            'success_message'   => \wp_kses_post(\FabricatorForms\Utils\Sanitize::str($settings['success_message']         ?? '', __('Thank you!', 'formfabricator'))),
            'submit_conditions' => [
                'enabled' => !empty($settings['submit_conditions']['enabled']),
                'match'   => in_array($settings['submit_conditions']['match'] ?? '', ['all', 'any'], true)
                    ? $settings['submit_conditions']['match'] : 'all',
                'rules'   => $rules,
            ],
        ];
    }

    /**
     * Sanitizes an HTML email body. Uses targeted regex instead of wp_kses since wp_kses strips valid email CSS.
     *
     * @param string $html Raw HTML body from the notification editor.
     * @return string Sanitized HTML.
     */
    private static function sanitizeEmailBody(string $html): string
    {
        $before = $html;

        $passes = [
            'script-open'   => '/<script\b[^>]*>/i',
            'script-close'  => '/<\/script\s*>/i',
            'script-tags'   => '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/is',
            // Active/embeddable content tags have no legitimate use in an email
            // body and some webmail/desktop clients do render them, so strip
            // the whole element rather than relying on attribute-level passes.
            'iframe-tags'   => '/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>|<iframe\b[^>]*\/?>/is',
            'object-tags'   => '/<object\b[^<]*(?:(?!<\/object>)<[^<]*)*<\/object>/is',
            'embed-tags'    => '/<embed\b[^>]*\/?>/i',
            // Meta refresh is a classic open-redirect/auto-navigate vector in
            // rendered HTML mail and has no legitimate use in a notification body.
            'meta-refresh'  => '/<meta\b[^>]*http-equiv\s*=\s*["\']?\s*refresh[^>]*>/i',
            // HTML5 allows "/" as an attribute boundary (e.g. <img/onerror=...>),
            // not just whitespace — match both so this can't be sidestepped.
            'event-handlers' =>
                '/[\s\/]+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            'js-uris'
                => '/\b(href|src|action)\s*=\s*(["\'])\s*'
                . '(?:javascript|vbscript)\s*:[^"\']*\2/i',
            // Unquoted attribute values are valid HTML (<a href=javascript:...>)
            // and aren't matched by the quoted pattern above at all.
            'js-uris-unquoted'
                => '/\b(href|src|action)\s*=\s*(?!["\'])'
                . '(?:javascript|vbscript)\s*:[^\s>]*/i',
            // style="...url('javascript:...')..." is a CSS-vector XSS the
            // href/src/action pass above doesn't cover.
            'css-js-uris'
                => '/(\bstyle\s*=\s*)(["\'])((?:(?!\2).)*(?:javascript|vbscript)\s*:(?:(?!\2).)*)\2/i',
        ];

        $replacements = [
            'script-open'       => '',
            'script-close'      => '',
            'script-tags'       => '',
            'iframe-tags'       => '',
            'object-tags'       => '',
            'embed-tags'        => '',
            'meta-refresh'      => '',
            'event-handlers'    => '',
            'js-uris-unquoted'  => '$1=""',
            'css-js-uris'       => '$1$2$2',
        ];
        $replacements['js-uris'] = '$1=$2#$2';

        foreach ($passes as $label => $pattern) {
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceDyn -- $pattern/$replacements are drawn from the hardcoded $passes/$replacements arrays above, not attacker input; no /e modifier is used anywhere in this codebase.
            $after = preg_replace($pattern, $replacements[$label], $html) ?? $html;
            if ($after !== $html) {
                // Count-only, no stripped content — safe to log unconditionally
                // (not gated behind WP_DEBUG) so production sites keep an audit
                // trail when a notification body actually triggers a strip.
                preg_match_all($pattern, $html, $m);
                \FabricatorForms\fabricator_log(
                    'FabricatorForms sanitizeEmailBody [' . $label . '] removed '
                    . count($m[0] ?? []) . ' match(es)'
                );
            }
            $html = $after;
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
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceWeird -- hardcoded literal pattern/replacement stripping CSS comments from the decoded attribute value, not attacker-influenced pattern selection; no /e modifier.
                $decoded = preg_replace('#/\*.*?\*/#s', '', $decoded);
                // Browsers strip ASCII tab/CR/LF before parsing a URL scheme, so "jav\tascript:" still executes — strip these before the keyword check.
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

        // Layer 2: wp_kses() allow-list, defense-in-depth on top of the regex passes above; DOCTYPE is preserved explicitly since wp_kses doesn't recognize it as a tag.
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
            'meta'  => ['charset' => true, 'http-equiv' => true, 'content' => true, 'name' => true],
            'style' => ['type' => true, 'media' => true],
            'link'  => ['rel' => true, 'href' => true, 'type' => true, 'media' => true],

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
            'form'     => ['style' => true, 'class' => true, 'id' => true, 'action' => true, 'method' => true],
            'label'    => ['style' => true, 'class' => true, 'for' => true],
            'input'    => ['style' => true, 'class' => true, 'id' => true, 'type' => true, 'name' => true,
                           'value' => true, 'placeholder' => true, 'checked' => true, 'disabled' => true],
            'select'   => ['style' => true, 'class' => true, 'id' => true, 'name' => true, 'multiple' => true],
            'option'   => ['value' => true, 'selected' => true],
            'textarea' => ['style' => true, 'class' => true, 'id' => true, 'name' => true, 'rows' => true, 'cols' => true],
            'button'   => ['style' => true, 'class' => true, 'id' => true, 'type' => true],
            'noscript' => [],
        ];

        // Add email-safe CSS properties safecss_filter_attr() would otherwise silently drop.
        $allow_email_css = static function (array $attrs): array {
            return array_unique(
                array_merge(
                    $attrs,
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
        \add_filter('safecss_filter_attr_allow_css', $allow_email_css);
        $html = \wp_kses($html, $allowed_email_tags);
        \remove_filter('safecss_filter_attr_allow_css', $allow_email_css);

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
                'addFirstFieldHtml'   => __('Add your first field via', 'formfabricator'),
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
                'pagePrefix'         => __('Page ', 'formfabricator'),
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

                /* SEPA / phone advanced blocks */
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

                /* Sub-fields accordion (Name, Address, SEPA, …) */
                'subfieldsSection' => __('Subfields', 'formfabricator'),
                'enableSubfield'   => __('Enable subfield', 'formfabricator'),
                'placeholderLabel' => __('Placeholder', 'formfabricator'),

                /* Notifications list + modal */
                'noNotificationsHtml' => __('No notifications yet. Click', 'formfabricator'),
                'addNotification'     => __('Add notification', 'formfabricator'),
                'notificationPrefix'  => __('Notification ', 'formfabricator'),
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
                'defaultAdminEmailHint' => __('{admin_email} for default value', 'formfabricator'),
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

            /* SEPA "Länderfilter" country-tag picker (IBAN-capable countries). Reuses the
               exact same __() msgids as SepaField::ibanCountryOptions() — same list, same
               English source text — except 'SC' (Seychelles), which that list doesn't
               contain and is new here. Reusing msgids means these already have translations. */
            'countryNames' => [
                'AD' => __('Andorra', 'formfabricator'),
                'AE' => __('United Arab Emirates', 'formfabricator'),
                'AL' => __('Albania', 'formfabricator'),
                'AT' => __('Austria', 'formfabricator'),
                'AZ' => __('Azerbaijan', 'formfabricator'),
                'BA' => __('Bosnia and Herzegovina', 'formfabricator'),
                'BE' => __('Belgium', 'formfabricator'),
                'BG' => __('Bulgaria', 'formfabricator'),
                'BH' => __('Bahrain', 'formfabricator'),
                'BR' => __('Brazil', 'formfabricator'),
                'CH' => __('Switzerland', 'formfabricator'),
                'CR' => __('Costa Rica', 'formfabricator'),
                'CY' => __('Cyprus', 'formfabricator'),
                'CZ' => __('Czechia', 'formfabricator'),
                'DE' => __('Germany', 'formfabricator'),
                'DJ' => __('Djibouti', 'formfabricator'),
                'DK' => __('Denmark', 'formfabricator'),
                'DO' => __('Dominican Republic', 'formfabricator'),
                'EE' => __('Estonia', 'formfabricator'),
                'EG' => __('Egypt', 'formfabricator'),
                'ES' => __('Spain', 'formfabricator'),
                'FI' => __('Finland', 'formfabricator'),
                'FR' => __('France', 'formfabricator'),
                'GB' => __('United Kingdom', 'formfabricator'),
                'GE' => __('Georgia', 'formfabricator'),
                'GI' => __('Gibraltar', 'formfabricator'),
                'GL' => __('Greenland', 'formfabricator'),
                'GR' => __('Greece', 'formfabricator'),
                'GT' => __('Guatemala', 'formfabricator'),
                'HR' => __('Croatia', 'formfabricator'),
                'HU' => __('Hungary', 'formfabricator'),
                'IE' => __('Ireland', 'formfabricator'),
                'IL' => __('Israel', 'formfabricator'),
                'IQ' => __('Iraq', 'formfabricator'),
                'IS' => __('Iceland', 'formfabricator'),
                'IT' => __('Italy', 'formfabricator'),
                'JO' => __('Jordan', 'formfabricator'),
                'KW' => __('Kuwait', 'formfabricator'),
                'KZ' => __('Kazakhstan', 'formfabricator'),
                'LB' => __('Lebanon', 'formfabricator'),
                'LC' => __('St. Lucia', 'formfabricator'),
                'LI' => __('Liechtenstein', 'formfabricator'),
                'LT' => __('Lithuania', 'formfabricator'),
                'LU' => __('Luxembourg', 'formfabricator'),
                'LV' => __('Latvia', 'formfabricator'),
                'LY' => __('Libya', 'formfabricator'),
                'MA' => __('Morocco', 'formfabricator'),
                'MC' => __('Monaco', 'formfabricator'),
                'MD' => __('Moldova', 'formfabricator'),
                'ME' => __('Montenegro', 'formfabricator'),
                'MK' => __('North Macedonia', 'formfabricator'),
                'MR' => __('Mauritania', 'formfabricator'),
                'MT' => __('Malta', 'formfabricator'),
                'MU' => __('Mauritius', 'formfabricator'),
                'NI' => __('Nicaragua', 'formfabricator'),
                'NL' => __('Netherlands', 'formfabricator'),
                'NO' => __('Norway', 'formfabricator'),
                'PK' => __('Pakistan', 'formfabricator'),
                'PL' => __('Poland', 'formfabricator'),
                'PT' => __('Portugal', 'formfabricator'),
                'QA' => __('Qatar', 'formfabricator'),
                'RO' => __('Romania', 'formfabricator'),
                'RS' => __('Serbia', 'formfabricator'),
                'SA' => __('Saudi Arabia', 'formfabricator'),
                'SC' => __('Seychelles', 'formfabricator'),
                'SE' => __('Sweden', 'formfabricator'),
                'SI' => __('Slovenia', 'formfabricator'),
                'SK' => __('Slovakia', 'formfabricator'),
                'SM' => __('San Marino', 'formfabricator'),
                'SV' => __('El Salvador', 'formfabricator'),
                'TN' => __('Tunisia', 'formfabricator'),
                'TR' => __('Turkey', 'formfabricator'),
                'UA' => __('Ukraine', 'formfabricator'),
                'VA' => __('Vatican City', 'formfabricator'),
                'VG' => __('British Virgin Islands', 'formfabricator'),
                'XK' => __('Kosovo', 'formfabricator'),
            ],

            /* Phone-field "Vorwahlen" (calling code) tag picker. These are dial-code
               region labels, not ISO country names, so they're a distinct set of new
               msgids from countryNames above (some cover multiple countries, e.g. +1). */
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
