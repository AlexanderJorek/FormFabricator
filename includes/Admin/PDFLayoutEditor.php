<?php

/**
 * Admin settings page for configuring PDF layout and branding.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.6
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Admin;

use FabricatorForms\Fields\FieldRegistry;

defined('ABSPATH') || exit;

/**
 * Admin editor for customizing the PDF layout (header, footer, fonts, colors).
 */
class PDFLayoutEditor
{
    private static array $section_labels = [];

    /**
     * Returns localised section labels, initialised lazily so __() is available.
     *
     * @return array<string,string>
     */
    private static function sectionLabels(): array
    {
        if (self::$section_labels === []) {
            self::$section_labels = [
                'header'     => __('Header (Logo & Title)', 'formfabricator'),
                'fields'     => __('Form fields', 'formfabricator'),
                'signatures' => __('Signatures & Uploads', 'formfabricator'),
                'metadata'   => __('Metadata & Timestamp', 'formfabricator'),
                'legal'      => __('Legal notice', 'formfabricator'),
                'footer'     => __('Footer', 'formfabricator'),
            ];
        }
        return self::$section_labels;
    }

    /**
     * Returns the default PDF layout options array.
     *
     * @return array Default PDF layout options.
     */
    public static function defaults(): array
    {
        return [
            'logo_url'        => '',
            'logo_width'      => 180,
            'accent_color'    => '#f59e0b',
            'separator_color' => '#c9cdd4',
            'font_family'     => 'dejavusans',
            'font_size_body'  => 11,
            'title_size'      => 14,
            'footer_text'     => '',
            'margin_top'      => 15,
            'margin_bottom'   => 15,
            'margin_left'     => 15,
            'margin_right'    => 15,
            'section_hidden'  => [],
            'header_layout'   => ['rows' => 8, 'elements' => []],
        ];
    }

    /**
     * Registers admin hooks for the PDF layout editor.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('admin_body_class', [self::class, 'bodyClass']);
        add_action('wp_ajax_fabricator_forms_pdf_preview', [self::class, 'ajaxPreview']);
        add_action('wp_ajax_fabricator_save_pdf_layout', [self::class, 'handleSave']);
        add_action('wp_ajax_fabricator_forms_unlock_pdf_layout', [self::class, 'ajaxUnlock']);
        add_filter('heartbeat_received', [self::class, 'heartbeatReceived'], 10, 2);
    }

    /**
     * Refreshes or reports a conflict on the PDF Layout page's advisory edit lock — see Utils\AdminLock.
     *
     * @param array $response Heartbeat response payload being built.
     * @param array $data     Data sent by the client in this heartbeat tick.
     * @return array Modified heartbeat response.
     */
    public static function heartbeatReceived(array $response, array $data): array
    {
        if (empty($data['fabricator_pdf_layout_lock']) || !\FabricatorForms\Plugin::userCan('edit_pdf_layout')) {
            return $response;
        }
        $lock_owner = \FabricatorForms\Utils\AdminLock::check('pdf_layout');
        if ($lock_owner) {
            $user = get_userdata($lock_owner);
            $response['fabricator_pdf_layout_lock_conflict'] = $user ? $user->display_name : __('another user', 'formfabricator');
        } else {
            \FabricatorForms\Utils\AdminLock::acquire('pdf_layout');
        }
        return $response;
    }

    /**
     * Releases the current user's PDF Layout edit lock, fired via sendBeacon() on unload.
     *
     * @return void
     */
    public static function ajaxUnlock(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require('edit_pdf_layout', 'fabricator_forms_admin_nonce', 'nonce');
        \FabricatorForms\Utils\AdminLock::release('pdf_layout', get_current_user_id());
        wp_send_json_success();
    }

    /**
     * AJAX handler that generates and returns a PDF preview as base64.
     *
     * @return void
     */
    public static function ajaxPreview(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require('edit_pdf_layout', 'fabricator_forms_admin_nonce', 'nonce');

        /* ---- Rate limit: PDF generation is expensive; throttle per-user preview requests. ---- */
        $rl_key = 'pdf_layout_preview_' . get_current_user_id();
        if (\FabricatorForms\Utils\RateLimiter::increment($rl_key, 5) > 5) {
            wp_send_json_error(['message' => __('Please wait before requesting another preview.', 'formfabricator')], 429);
        }

        /* Use live settings from the request so the user doesn't have to save first */
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
        if (!empty($_POST['settings'])) {
            /* wp_unslash is required — WordPress's wp_magic_quotes() slashes all $_POST values */
            // json_decode() itself doesn't sanitize — every key read from $raw below is sanitized individually before use.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
            $raw = json_decode(sanitize_textarea_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['settings'] ?? ''))), true);
            if (!is_array($raw)) {
                \FabricatorForms\fabricator_log('ajaxPreview: settings JSON decode failed — ' . json_last_error_msg());
            }
            if (is_array($raw)) {
                $defs = self::defaults();
                $sanitized_hl = self::sanitizeHeaderLayout((array) ($raw['header_layout'] ?? []), false);
                $preview_opts = [
                    'logo_url'        => esc_url_raw(\FabricatorForms\Utils\Cast::stringOrDefault($raw['logo_url'] ?? '')),
                    'logo_width'      => min(400, max(40, (int) ($raw['logo_width']     ?? 180))),
                    'accent_color'    => sanitize_hex_color(\FabricatorForms\Utils\Cast::stringOrDefault($raw['accent_color']    ?? '')) ?: $defs['accent_color'],
                    'separator_color' => sanitize_hex_color(\FabricatorForms\Utils\Cast::stringOrDefault($raw['separator_color'] ?? '')) ?: $defs['separator_color'],
                    'font_family'     => sanitize_key(\FabricatorForms\Utils\Cast::stringOrDefault($raw['font_family'] ?? '', 'dejavusans')),
                    'font_size_body'  => min(20, max(6, (int) ($raw['font_size_body'] ?? 11))),
                    'title_size'      => min(36, max(10, (int) ($raw['title_size']     ?? 14))),
                    'footer_text'     => sanitize_textarea_field(\FabricatorForms\Utils\Cast::stringOrDefault($raw['footer_text'] ?? '')),
                    'margin_top'      => min(50, max(0, (int) ($raw['margin_top']    ?? 15))),
                    'margin_bottom'   => min(50, max(0, (int) ($raw['margin_bottom'] ?? 15))),
                    'margin_left'     => min(50, max(0, (int) ($raw['margin_left']   ?? 15))),
                    'margin_right'    => min(50, max(0, (int) ($raw['margin_right']  ?? 15))),
                    'section_hidden'  => array_values(
                        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is an inline closure, not attacker-controlled dispatch.
                        array_filter(
                            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is the hardcoded 'sanitize_key' string, not attacker-controlled dispatch.
                            array_map('sanitize_key', (array) ($raw['section_hidden'] ?? [])),
                            fn($s) => isset(self::sectionLabels()[$s])
                        )
                    ),
                    'header_layout'   => $sanitized_hl,
                ];
                add_filter(
                    'pre_option_fabricator_forms_pdf_layout',
                    static function () use ($preview_opts): array {
                        return $preview_opts;
                    },
                    PHP_INT_MAX
                );
            }
        }

        $dummy = self::dummyFields();

        // form_id=0 signals to Generator/HashSeal that this is a throwaway layout preview,
        // not a real submission — it must not be persisted or count toward seal history
        $path = \FabricatorForms\PDF\Generator::generate($dummy, 0, __('Layout Preview', 'formfabricator'));

        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $path is the return value of PDF\Generator::generate(), an internally-computed temp-file path, not attacker input.
        if (!$path || !file_exists($path)) {
            wp_send_json_error(['message' => __('PDF generation failed.', 'formfabricator')], 500);
        }

        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $path is the return value of PDF\Generator::generate(), an internally-computed temp-file path, not attacker input.
        $data = file_get_contents($path);
        // Delete immediately after reading — no submission data is ever kept on disk (see CLAUDE.md)
        wp_delete_file($path);

        if ($data === false) {
            wp_send_json_error(['message' => __('PDF could not be read.', 'formfabricator')], 500);
        }

        wp_send_json_success(['pdf_b64' => base64_encode($data)]);
    }

    /**
     * Appends a CSS class on the PDF layout editor page.
     *
     * @param string $classes Existing admin body classes.
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin body-class check, no data written.
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($current_page === 'fabricator-forms-pdf-layout') {
            $classes .= ' fabricator-list-page';
        }
        return $classes;
    }

    /**
     * Registers the PDF-Layout submenu page and removes admin notices on it.
     *
     * @return void
     */
    public static function addPage(): void
    {
        if (!\FabricatorForms\Plugin::userCan('edit_pdf_layout')) {
            return;
        }
        // Registered with generic 'read' since real enforcement is userCan('edit_pdf_layout') above and in render() — any new callback here MUST re-check it itself.
        $hook = add_submenu_page(
            'fabricator-forms',
            __('FormFabricator PDF Layout', 'formfabricator'),
            __('PDF Layout', 'formfabricator'),
            'read',
            'fabricator-forms-pdf-layout',
            [self::class, 'render']
        );
        add_action(
            'load-' . $hook,
            static function (): void {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
                remove_all_actions('user_admin_notices');
                remove_all_actions('network_admin_notices');
            }
        );
    }

    /**
     * Renders the PDF layout editor page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!\FabricatorForms\Plugin::userCan('edit_pdf_layout')) {
            wp_die(esc_html__('Permission denied.', 'formfabricator'));
        }

        wp_enqueue_media();

        $saved      = false;
        $save_error = '';
        if (isset($_POST['fabricator_pdf_layout_nonce'])
            && wp_verify_nonce(sanitize_key($_POST['fabricator_pdf_layout_nonce']), 'fabricator_pdf_layout')
        ) {
            $save_error = self::save();
            $saved      = $save_error === '';
        }

        $defs = self::defaults();
        $opts = array_merge($defs, (array) get_option('fabricator_forms_pdf_layout', []));

        if (!is_array($opts['section_hidden'])) {
            $opts['section_hidden'] = $defs['section_hidden'];
        }

        $fonts = [
            'dejavusans'     => __('DejaVu Sans (Default, sans-serif)', 'formfabricator'),
            'dejavuserif'    => __('DejaVu Serif (with serifs)', 'formfabricator'),
            'dejavusansmono' => __('DejaVu Sans Mono (Fixed-width)', 'formfabricator'),
            'freemono'       => __('FreeMono (Typewriter)', 'formfabricator'),
        ];


        $site_name         = get_bloginfo('name');
        $site_url          = get_bloginfo('url');
        $field_layout_mode = get_option('fabricator_forms_field_layout', 'block');

        /* Same sample data as the server-rendered PDF preview, so both
           previews show identical text and images. */
        $dummy_fields    = self::dummyFields();
        $dummy_text      = array_values(
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is an inline closure over hardcoded dummy data, not attacker-controlled dispatch.
            array_filter(
                $dummy_fields,
                fn($f) => (bool)(FieldRegistry::get($f['type'] ?? '')?->hasTextPreview())
            )
        );
        $dummy_signature = self::dummySignaturePng();
        $dummy_upload    = self::dummyUploadPng();

        // Advisory notice only — save()'s snapshot-hash check is the real guard.
        $lock_owner_name = '';
        $lock_owner_id   = \FabricatorForms\Utils\AdminLock::check('pdf_layout');
        if ($lock_owner_id) {
            $lock_owner_user = get_userdata($lock_owner_id);
            $lock_owner_name = $lock_owner_user ? $lock_owner_user->display_name : __('another user', 'formfabricator');
        } else {
            \FabricatorForms\Utils\AdminLock::acquire('pdf_layout');
        }
        wp_enqueue_script('heartbeat');
        $lock_admin_nonce = wp_create_nonce('fabricator_forms_admin_nonce');
        wp_localize_script(
            'fabricator-forms-pdflayout-lock',
            'FabricatorPdfLayoutLock',
            [
            'nonce'   => $lock_admin_nonce,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'i18n'    => [
                // translators: %s: display name of the user currently editing this page.
                'lockConflict' => __('Currently being edited by %s. Saving may conflict.', 'formfabricator'),
            ],
            ]
        );
        wp_localize_script(
            'fabricator-forms-admin-pdflayout',
            'FabricatorPdfLayoutPage',
            [
            'i18n' => self::pdfLayoutI18n(),
            'data' => [
                'siteName'        => $site_name,
                'siteUrl'         => $site_url,
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'nonce'           => wp_create_nonce('fabricator_forms_admin_nonce'),
                'dummySignature'  => $dummy_signature,
                'dummyText'       => $dummy_text,
                'dummyUpload'     => $dummy_upload,
                'fieldLayoutMode' => $field_layout_mode,
            ],
            ]
        );
        ?>
<canvas id="fabricator-particle-canvas"></canvas>
<div class="wrap fabricator-list-wrap">
    <div class="fabricator-title-pill"><i class="fa-solid fa-file-pdf"></i> <?php echo esc_html__('PDF Layout', 'formfabricator'); ?></div>
    <hr class="wp-header-end" style="display:none">

        <?php if ($saved) : ?>
        <div class="fabricator-settings-notice fabricator-settings-notice--success">
            <i class="fa-solid fa-circle-check"></i> <?php echo esc_html__('Layout saved.', 'formfabricator'); ?>
        </div>
        <?php elseif ($save_error !== '') : ?>
        <div class="fabricator-settings-notice fabricator-settings-notice--error">
            <i class="fa-solid fa-triangle-exclamation"></i> <?php echo esc_html($save_error); ?>
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
                            /* translators: %s: display name of the user currently editing the PDF layout. */
                            __('Currently being edited by %s. Saving may conflict.', 'formfabricator'),
                            $lock_owner_name
                        )
                        : ''
                );
                ?>
            </span>
        </div>
        <?php // Heartbeat lock notice: assets/js/admin-pdflayout-lock.js (enqueued in Utils/Assets.php) -- previously an inline <script> block here. ?>

    <form method="post" id="fabricator-pdf-layout-form">
        <?php wp_nonce_field('fabricator_pdf_layout', 'fabricator_pdf_layout_nonce'); ?>
        <input type="hidden" name="fabricator_pdf_layout_snapshot" value="<?php echo esc_attr(self::snapshot()); ?>">
        <input type="hidden" name="section_hidden" id="fabricator-section-hidden-input"
            value="<?php echo esc_attr(implode(',', $opts['section_hidden'])); ?>">
        <input type="hidden" name="header_layout_json" id="fabricator-header-layout-input"
            value="<?php echo esc_attr(wp_json_encode($opts['header_layout'] ?? ['rows' => 8, 'elements' => []])); ?>">

        <div class="fabricator-pdf-editor-wrap">

            <!-- ── Settings Panel ── -->
            <div class="fabricator-pdf-settings-panel">

                <div class="fabricator-settings-card">
                    <h2 class="fabricator-settings-card-title"><i class="fa-solid fa-table-columns"></i> <?php echo esc_html__('Header', 'formfabricator'); ?></h2>
                    <div class="fabricator-settings-field">
                        <p class="fabricator-card-hint">
                            <?php echo esc_html__('Arrange titles, logos and other content via drag & drop.', 'formfabricator'); ?>
                        </p>
                        <button type="button" class="button button-primary fabricator-hb-open-btn"
                            id="fabricator-open-header-builder-card">
                            <i class="fa-solid fa-pen-to-square"></i> <?php echo esc_html__('Edit header', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>

                <div class="fabricator-settings-card">
                    <h2 class="fabricator-settings-card-title"><i class="fa-solid fa-palette"></i> <?php echo esc_html__('Colors', 'formfabricator'); ?></h2>

                    <?php
                    $color_fields = [
                        ['accent_color',    __('Accent color (thick dividers)', 'formfabricator'), '#f59e0b'],
                        ['separator_color', __('Divider color (thin lines)', 'formfabricator'), '#c9cdd4'],
                    ];
                    foreach ($color_fields as [$id, $lbl, $default]) :
                        $eid = esc_attr($id);
                        ?>
                    <div class="fabricator-settings-field">
                        <label for="<?php echo esc_attr($eid); ?>"><?php echo esc_html($lbl); ?></label>
                        <input type="text" id="<?php echo esc_attr($eid); ?>"
                               name="<?php echo esc_attr($eid); ?>"
                               value="<?php echo esc_attr($opts[$id]); ?>"
                               class="fabricator-iris-input"
                               data-default-color="<?php echo esc_attr($default); ?>"
                               autocomplete="off" data-lpignore="true"
                               data-1p-ignore data-bwignore spellcheck="false">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="fabricator-settings-card">
                    <h2 class="fabricator-settings-card-title"><i class="fa-solid fa-font"></i> <?php echo esc_html__('Typography', 'formfabricator'); ?></h2>

                    <div class="fabricator-settings-field">
                        <label for="font_family"><?php echo esc_html__('Font', 'formfabricator'); ?></label>
                        <select id="font_family" name="font_family">
                            <?php foreach ($fonts as $val => $lbl) : ?>
                                <option value="<?php echo esc_attr($val); ?>"
                                    <?php selected($opts['font_family'], $val); ?>
                                ><?php echo esc_html($lbl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fabricator-settings-field">
                        <label for="font_size_body"><?php echo esc_html__('Base font size:', 'formfabricator'); ?>
                            <span id="font-size-body-val"><?php echo (int) $opts['font_size_body']; ?></span> <?php echo esc_html__('pt', 'formfabricator'); ?>
                        </label>
                        <input type="range" id="font_size_body" name="font_size_body"
                            min="8" max="14" step="1" value="<?php echo (int) $opts['font_size_body']; ?>">
                    </div>

                    <div class="fabricator-settings-field">
                        <label for="title_size"><?php echo esc_html__('Title size:', 'formfabricator'); ?>
                            <span id="title-size-val"><?php echo (int) $opts['title_size']; ?></span> <?php echo esc_html__('pt', 'formfabricator'); ?>
                        </label>
                        <input type="range" id="title_size" name="title_size"
                            min="12" max="28" step="1" value="<?php echo (int) $opts['title_size']; ?>">
                    </div>
                </div>

                <div class="fabricator-settings-card">
                    <h2 class="fabricator-settings-card-title">
                        <i class="fa-solid fa-arrows-left-right-to-line"></i> <?php echo esc_html__('Page margins (mm)', 'formfabricator'); ?>
                    </h2>
                    <div class="fabricator-margins-grid">
                        <?php
                        $margin_sides = [
                            'top'    => __('Top', 'formfabricator'),
                            'right'  => __('Right', 'formfabricator'),
                            'bottom' => __('Bottom', 'formfabricator'),
                            'left'   => __('Left', 'formfabricator'),
                        ];
                        foreach ($margin_sides as $side => $lbl) :
                            ?>
                        <div class="fabricator-settings-field">
                            <label for="margin_<?php echo esc_attr($side); ?>"><?php echo esc_html($lbl); ?>:
                                <span id="margin-<?php echo esc_attr($side); ?>-val">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $side only ever takes the literal values from $margin_sides above; output is (int)-cast regardless. ?>
                                    <?php echo (int) $opts['margin_' . $side]; ?>
                                </span> mm
                            </label>
                            <input type="range" id="margin_<?php echo esc_attr($side); ?>"
                                name="margin_<?php echo esc_attr($side); ?>" min="5" max="40" step="1"
                                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $side only ever takes the literal values from $margin_sides above; output is (int)-cast regardless. ?>
                                value="<?php echo (int) $opts['margin_' . $side]; ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="fabricator-settings-card">
                    <h2 class="fabricator-settings-card-title">
                        <i class="fa-solid fa-table-list"></i> <?php echo esc_html__('Sections', 'formfabricator'); ?>
                    </h2>
                    <p class="fabricator-settings-hint" style="margin-top:0">
                        <?php echo esc_html__('Eye icon to show/hide.', 'formfabricator'); ?>
                    </p>
                    <ul id="fabricator-sections-sortable" class="fabricator-sections-list">
                        <?php foreach (array_keys(self::sectionLabels()) as $slug) :
                            $is_hidden = in_array($slug, $opts['section_hidden'], true);
                            ?>
                        <li class="fabricator-section-item<?php echo $is_hidden ? ' fabricator-section-hidden' : ''; ?>"
                            data-slug="<?php echo esc_attr($slug); ?>">
                            <span><?php echo esc_html(self::sectionLabels()[$slug]); ?></span>
                            <?php if ($slug === 'header') : ?>
                            <button type="button" class="fabricator-section-edit-btn"
                                id="fabricator-open-header-builder" title="<?php echo esc_attr__('Edit header', 'formfabricator'); ?>">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="fabricator-section-toggle" title="<?php echo esc_attr__('Show/Hide', 'formfabricator'); ?>">
                                <i class="fa-solid <?php echo $is_hidden ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="fabricator-settings-card">
                    <h2 class="fabricator-settings-card-title"><i class="fa-solid fa-shoe-prints"></i> <?php echo esc_html__('Footer', 'formfabricator'); ?></h2>
                    <div class="fabricator-settings-field">
                        <label for="footer_text"><?php echo esc_html__('Footer text', 'formfabricator'); ?></label>
                        <textarea id="footer_text" name="footer_text" rows="3"
                            placeholder="<?php echo esc_attr__('e.g. Company name · Address · Phone', 'formfabricator'); ?>"
                        ><?php echo esc_textarea($opts['footer_text']); ?></textarea>
                        <div class="fabricator-placeholder-chips">
                            <?php foreach (['{site_name}','{site_url}','{date}'] as $token) : ?>
                            <button type="button" class="fabricator-placeholder-chip"
                                data-insert="<?php echo esc_attr($token); ?>"><?php echo esc_html($token); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>


            </div><!-- /.fabricator-pdf-settings-panel -->

            <!-- ── Preview Panel ── -->
            <div class="fabricator-pdf-preview-panel">
                <div class="fabricator-preview-toolbar">
                    <span><i class="fa-solid fa-eye"></i> <?php echo esc_html__('Preview (A4)', 'formfabricator'); ?></span>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="button button-primary" form="fabricator-pdf-layout-form">
                            <i class="fa-solid fa-floppy-disk"></i> <?php echo esc_html__('Save', 'formfabricator'); ?>
                        </button>
                        <button type="button" class="button" id="fabricator-pdf-preview-btn">
                            <i class="fa-solid fa-file-pdf"></i> <?php echo esc_html__('Open PDF', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>
                <div class="fabricator-preview-stage">
                    <div class="fabricator-preview-stage-inner" id="fabricator-preview-stage-inner">
                        <div class="fabricator-a4-paper" id="fabricator-a4-paper"></div>
                    </div>
                </div>
            </div>

        </div><!-- /.fabricator-pdf-editor-wrap -->
    </form>
</div>

<!-- ── Header Builder Modal ── -->
<div id="fabricator-hb-modal" class="fabricator-hb-modal" hidden>
    <div class="fabricator-hb-overlay" id="fabricator-hb-overlay"></div>
    <div class="fabricator-hb-dialog">

        <div class="fabricator-hb-dialog-head">
            <span><i class="fa-solid fa-table-cells-large"></i> <?php echo esc_html__('Edit header', 'formfabricator'); ?></span>
            <button type="button" class="fabricator-hb-dialog-head-close"
                id="fabricator-hb-close" title="<?php echo esc_attr__('Close', 'formfabricator'); ?>">&#x2715;</button>
        </div>

        <div class="fabricator-hb-toolbar">
            <button type="button" class="button" id="fabricator-hb-add-title">
                <i class="fa-solid fa-heading"></i> <?php echo esc_html__('Title', 'formfabricator'); ?>
            </button>
            <button type="button" class="button" id="fabricator-hb-add-image"><i class="fa-solid fa-image"></i> <?php echo esc_html__('Image', 'formfabricator'); ?></button>
            <div style="width:1px;height:24px;background:#c3c4c7;margin:0 4px;"></div>
            <label><?php echo esc_html__('Height (rows of 5 mm):', 'formfabricator'); ?>
                <input type="number" id="fabricator-hb-rows" min="2" max="30" value="8" style="width:52px">
            </label>
            <span style="font-size:11px;color:#888;margin-left:4px;">
                <?php echo esc_html__('← Drag to position · Corners to resize · Del to delete', 'formfabricator'); ?>
            </span>
        </div>

        <div class="fabricator-hb-body">
            <div class="fabricator-hb-canvas-wrap">
                <div id="fabricator-hb-canvas" class="fabricator-hb-canvas"></div>
            </div>
            <div class="fabricator-hb-props" id="fabricator-hb-props">
                <p class="fabricator-hb-empty"><?php echo esc_html__('Select element', 'formfabricator'); ?><br><?php echo esc_html__('to edit', 'formfabricator'); ?></p>
            </div>
        </div>

        <div class="fabricator-hb-dialog-footer">
            <button type="button" class="button" id="fabricator-hb-cancel"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
            <button type="button" class="button button-primary" id="fabricator-hb-apply">
                <i class="fa-solid fa-check"></i> <?php echo esc_html__('Apply', 'formfabricator'); ?>
            </button>
        </div>

    </div>
</div>

        <?php // PDF Layout editor JS: assets/js/admin-pdflayout.js. ?>
        <?php
    }

    /**
     * Translated strings used by assets/js/admin-pdflayout.js, passed via wp_localize_script.
     *
     * @return array
     */
    private static function pdfLayoutI18n(): array
    {
        return [
            'addImage'           => __('Add image', 'formfabricator'),
            'cancel'             => __('Cancel', 'formfabricator'),
            'chooseFromLibrary'  => __('Choose from media library', 'formfabricator'),
            'created'            => __('Created:', 'formfabricator'),
            'errorSaving'        => __('Error saving.', 'formfabricator'),
            'externalUrl'        => __('External URL', 'formfabricator'),
            'externalUrlHint'    => __(
                'Better to add the image to this site\'s media library first — an external URL is downloaded into the library on save anyway, and a slow or unreachable host will delay that save.',
                'formfabricator'
            ),
            'formLabel'          => __('Form:', 'formfabricator'),
            'generating'         => __('Generating…', 'formfabricator'),
            'imageUrl'           => __('Image URL:', 'formfabricator'),
            'insert'             => __('Insert', 'formfabricator'),
            'legalNotice'        => __('Legal Notice:', 'formfabricator'),
            'legalNoticeBody'    => __(
                'This document represents the original. Any change, manipulation, or modification invalidates this document. This document was issued in electronic form and must be kept exclusively in electronic form. Any printout is merely a copy and has no legal validity.', // phpcs:ignore Generic.Files.LineLength
                'formfabricator'
            ),
            'metadata'           => __('Metadata', 'formfabricator'),
            // Sample-submission labels for the live preview; mirror dummyFields()'s equivalents.
            'sampleSignature'    => __('Signature', 'formfabricator'),
            'sampleAttachment'   => __('Attachment', 'formfabricator'),
            'networkError'       => __('Network error', 'formfabricator'),
            'saving'             => __('Saving…', 'formfabricator'),
            'selectImage'        => __('Select image', 'formfabricator'),
            'pdfNotGenerated'    => __('The PDF could not be generated.', 'formfabricator'),
            'requestUnreachable' => __('The request could not reach the server. Please check your connection and try again.', 'formfabricator'), // phpcs:ignore Generic.Files.LineLength
            // translators: %d is the HTTP status code returned by the server.
            'unexpectedResponse' => __('Unexpected server response (HTTP %d). See browser console for details.', 'formfabricator'),
            'orLabel'            => __('or', 'formfabricator'),
            // translators: %1$s is the current page number, %2$s is the total page count.
            'pageOfPage'         => sprintf(__('Page %1$s of %2$s', 'formfabricator'), '%1$s', '%2$s'),
            'hb'                 => [
                'width'             => __('Width', 'formfabricator'),
                'height'            => __('Height', 'formfabricator'),
                'noImageSelected'   => __('No image selected', 'formfabricator'),
                'changeImage'       => __('Change image', 'formfabricator'),
                'chooseFromLibrary' => __('Choose from media library', 'formfabricator'),
                'fitContain'        => __('Fit', 'formfabricator'),
                'fitCover'          => __('Fill', 'formfabricator'),
                'fitFill'           => __('Stretch', 'formfabricator'),
                'bold'              => __('Bold', 'formfabricator'),
                'italic'            => __('Italic', 'formfabricator'),
                'underline'         => __('Underline', 'formfabricator'),
                'underlineStyle'    => __('Underline style', 'formfabricator'),
                'solid'             => __('Solid', 'formfabricator'),
                'double'            => __('Double', 'formfabricator'),
                'dotted'            => __('Dotted', 'formfabricator'),
                'dashed'            => __('Dashed', 'formfabricator'),
                'strikethrough'     => __('Strikethrough', 'formfabricator'),
                'superscript'       => __('Superscript', 'formfabricator'),
                'subscript'         => __('Subscript', 'formfabricator'),
                'left'              => __('Left', 'formfabricator'),
                'center'            => __('Center', 'formfabricator'),
                'right'             => __('Right', 'formfabricator'),
                'fontSize'          => __('Font size', 'formfabricator'),
                'color'             => __('Color', 'formfabricator'),
                'text'              => __('Text', 'formfabricator'),
                'htmlCode'          => __('HTML code', 'formfabricator'),
                'htmlNote'          => __('HTML is rendered directly. No script tag.', 'formfabricator'),
                'selectElement'     => __('Select element', 'formfabricator'),
                'toEdit'            => __('to edit', 'formfabricator'),
                'deleteElement'     => __('Delete element', 'formfabricator'),
                'orEnterUrl'        => __('or enter a URL …', 'formfabricator'),
                // Canvas element-type badges (hbMakeNode()) — 'elHtml' is distinct from the 'htmlCode' textarea field label.
                'elTitle'           => __('Title', 'formfabricator'),
                'elImage'           => __('Image', 'formfabricator'),
                'elHtml'            => __('HTML', 'formfabricator'),
            ],
        ];
    }

    /**
     * AJAX handler that saves PDF layout settings.
     *
     * @return void
     */
    public static function handleSave(): void
    {
        \FabricatorForms\Utils\AjaxGuard::require('edit_pdf_layout', 'fabricator_pdf_layout', 'fabricator_pdf_layout_nonce');
        $error = self::save();
        if ($error !== '') {
            wp_send_json_error(['message' => $error], 409);
        }
        wp_send_json_success(['message' => __('Layout saved.', 'formfabricator'), 'snapshot' => self::snapshot()]);
    }

    /**
     * Saves PDF layout settings from POST data.
     *
     * @return void
     */
    /**
     * Optimistic-concurrency snapshot hash of the current fabricator_forms_pdf_layout option.
     *
     * @return string Snapshot hash.
     */
    private static function snapshot(): string
    {
        return md5(wp_json_encode(get_option('fabricator_forms_pdf_layout', [])));
    }

    /**
     * Saves the PDF layout option.
     *
     * @return string Error message, or '' on success.
     */
    private static function save(): string
    {
        // Defense-in-depth: callers already gate on this, but don't rely solely on them remembering to check.
        if (!\FabricatorForms\Plugin::userCan('edit_pdf_layout')) {
            return __('Insufficient permissions.', 'formfabricator');
        }
        if (!isset($_POST['fabricator_pdf_layout_nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['fabricator_pdf_layout_nonce'])), 'fabricator_pdf_layout')
        ) {
            return __('Security check failed. Please reload and try again.', 'formfabricator');
        }

        // Optimistic-concurrency guard: reject a save if the option changed since this snapshot.
        $expected_snapshot = isset($_POST['fabricator_pdf_layout_snapshot'])
            ? sanitize_text_field(wp_unslash($_POST['fabricator_pdf_layout_snapshot']))
            : '';
        if ($expected_snapshot !== '' && $expected_snapshot !== self::snapshot()) {
            return __('The PDF layout was changed elsewhere since this page loaded. Please reload and try again.', 'formfabricator');
        }

        $defs = self::defaults();
        $labels = self::sectionLabels();

        $hidden = array_values(
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is an inline closure, not attacker-controlled dispatch.
            array_filter(
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- each value is passed through sanitize_key() via array_map() (WPCS doesn't recognize the string-callback form); callback is the hardcoded 'sanitize_key' string, not attacker-controlled dispatch.
                array_map('sanitize_key', explode(',', (string) wp_unslash($_POST['section_hidden'] ?? ''))),
                fn($s) => isset($labels[$s])
            )
        );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded JSON is fully validated/sanitized below by self::sanitizeHeaderLayout() before use.
        $header_layout_decoded = json_decode((string) wp_unslash($_POST['header_layout_json'] ?? '{}'), true);

        update_option(
            'fabricator_forms_pdf_layout',
            [
            'logo_url'        => esc_url_raw((string) wp_unslash($_POST['logo_url'] ?? '')),
            'logo_width'      => min(400, max(40, absint(wp_unslash($_POST['logo_width'] ?? 180)))),
            'accent_color'    => sanitize_hex_color((string) wp_unslash($_POST['accent_color']    ?? '')) ?: $defs['accent_color'],
            'separator_color' => sanitize_hex_color((string) wp_unslash($_POST['separator_color'] ?? '')) ?: $defs['separator_color'],
            'font_family'     => sanitize_key(wp_unslash($_POST['font_family'] ?? 'dejavusans')),
            'font_size_body'  => min(20, max(6, absint(wp_unslash($_POST['font_size_body'] ?? 11)))),
            'title_size'      => min(36, max(10, absint(wp_unslash($_POST['title_size']     ?? 14)))),
            'footer_text'     => sanitize_textarea_field(wp_unslash($_POST['footer_text'] ?? '')),
            'margin_top'      => min(50, max(0, absint(wp_unslash($_POST['margin_top']    ?? 15)))),
            'margin_bottom'   => min(50, max(0, absint(wp_unslash($_POST['margin_bottom'] ?? 15)))),
            'margin_left'     => min(50, max(0, absint(wp_unslash($_POST['margin_left']   ?? 15)))),
            'margin_right'    => min(50, max(0, absint(wp_unslash($_POST['margin_right']  ?? 15)))),
            'section_hidden'  => $hidden,
            'header_layout'   => self::sanitizeHeaderLayout(
                is_array($header_layout_decoded) ? $header_layout_decoded : [],
                true
            ),
            ]
        );

        delete_transient('fabricator_pdf_template_fingerprints');
        return '';
    }

    /**
     * Resolves an image element's src to a local media-library attachment URL; external URLs are fetched only when $persist is true, via media_sideload_image()'s SSRF-guarded wp_safe_remote_get().
     *
     * @param string $src     Raw src URL from the layout editor.
     * @param bool   $persist True when called from the final save handler.
     * @return string Local attachment URL, or '' if it can't be resolved/fetched.
     */
    private static function resolveImageSrc(string $src, bool $persist): string
    {
        if ($src === '') {
            return '';
        }
        if (attachment_url_to_postid($src)) {
            return $src;
        }
        if (!$persist || !preg_match('#^https?://#i', $src)) {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        /* Lightest viable sideload: the PDF renderer only ever reads the ORIGINAL file
           (layout.php resolves attachment_url_to_postid() -> get_attached_file()), so the
           thumbnail/medium/large/scaled derivatives WordPress would normally generate are pure
           cost here — several image decodes and re-encodes plus extra files on disk, per image,
           during a synchronous admin save. Suppressing them for the duration of this one call
           keeps the attachment (and its media-library entry) while skipping all of that. */
        $suppress_sizes = static function (): array {
            return [];
        };
        add_filter('intermediate_image_sizes_advanced', $suppress_sizes, PHP_INT_MAX);
        add_filter('big_image_size_threshold', '__return_false', PHP_INT_MAX);
        try {
            $attachment_id = media_sideload_image($src, 0, null, 'id');
        } finally {
            // finally: a sideload throwing must not leave these filters attached for the rest
            // of the request, where they'd silently break unrelated media uploads.
            remove_filter('intermediate_image_sizes_advanced', $suppress_sizes, PHP_INT_MAX);
            remove_filter('big_image_size_threshold', '__return_false', PHP_INT_MAX);
        }

        if (is_wp_error($attachment_id)) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms PDFLayoutEditor: failed to sideload header image '
                . $src . ' — ' . $attachment_id->get_error_message()
            );
            return '';
        }

        return wp_get_attachment_url($attachment_id) ?: '';
    }

    /**
     * Sanitizes the header layout grid configuration.
     *
     * @param array $raw     Raw header layout data from POST.
     * @param bool  $persist True when sanitizing for the final save (allows a one-time
     *                       external-image fetch); false for live preview, where external
     *                       URLs are resolved only if they were already sideloaded on a
     *                       prior save.
     * @return array Sanitized header layout array.
     */
    private static function sanitizeHeaderLayout(array $raw, bool $persist = false): array
    {
        /* ACCEPTED RISK (reviewed, deliberate — do not "fix" without asking):
           the element list is intentionally uncapped, so a save posting N image elements with N
           distinct external URLs issues N media_sideload_image() fetches. Reaching that state
           requires the edit_pdf_layout capability and a hand-crafted request; the realistic
           misuse is someone pasting a lot of URLs, which the picker's hint steers away from, and
           resolveImageSrc() keeps each fetch as cheap as possible. Judged not worth a cap that
           would reject or silently truncate a legitimate (if unusual) header layout. */
        $rows = min(30, max(2, (int) ($raw['rows'] ?? 8)));
        $elements = [];
        foreach ((array) ($raw['elements'] ?? []) as $el) {
            // 'type'/'id' are normally strings but nothing guarantees that; sanitize_key()'s strict type hint throws on an array/object value.
            $el_type = $el['type'] ?? '';
            $type = sanitize_key(is_string($el_type) ? $el_type : '');
            /* 'html' is deliberately absent: the header builder offers only Title and Image, and
               layout.php's header renderer handles only those two — an 'html' element was
               accepted and stored here but then silently never drawn in the PDF. */
            if (!in_array($type, ['title', 'image'], true)) {
                continue;
            }
            $el_id = $el['id'] ?? 'e1';
            $item = [
                'id'   => sanitize_key(is_string($el_id) ? $el_id : 'e1'),
                'type' => $type,
                'x'    => max(0, min(41, (int) ($el['x'] ?? 0))),
                'y'    => max(0, min(500, (int) ($el['y'] ?? 0))),
                'w'    => max(1, min(42, (int) ($el['w'] ?? 10))),
                'h'    => max(1, min(500, (int) ($el['h'] ?? 4))),
            ];
            if ($type === 'title') {
                // el.content carries the contenteditable's formatted HTML (el.text is a plain-text fallback); use the same allow-list as PDF-render time or formatting is silently discarded on save.
                $raw_content = $el['content'] ?? $el['text'] ?? '{form_title}';
                $raw_html = is_string($raw_content) ? $raw_content : '{form_title}';
                $item['content'] = wp_kses($raw_html, [
                    'b'      => [],
                    'strong' => [],
                    'i'      => [],
                    'em'     => [],
                    'u'      => ['style' => []],
                    's'      => [],
                    'del'    => [],
                    'sup'    => [],
                    'sub'    => [],
                    'span'   => ['style' => []],
                    'br'     => [],
                ]);
                $item['size']  = min(72, max(6, (int) ($el['size'] ?? 18)));
                $item['bold']  = !empty($el['bold']);
                $item['align'] = in_array($el['align'] ?? '', ['left', 'center', 'right'], true) ? $el['align'] : 'left';
                $item['color'] = sanitize_hex_color(\FabricatorForms\Utils\Cast::stringOrDefault($el['color'] ?? '')) ?: '#1d2327';
            } elseif ($type === 'image') {
                $src = self::resolveImageSrc(esc_url_raw(\FabricatorForms\Utils\Cast::stringOrDefault($el['src'] ?? '')), $persist);
                if ($src === '') {
                    continue;
                }
                $item['src'] = $src;
                $item['fit'] = in_array($el['fit'] ?? '', ['contain', 'cover', 'fill'], true) ? $el['fit'] : 'contain';
            }
            $elements[] = $item;
        }
        return ['rows' => $rows, 'elements' => $elements];
    }

    /**
     * Sample submission data shared by the server-rendered and browser-side PDF previews so both match.
     *
     * @return array Dummy mapped-field entries.
     */
    public static function dummyFields(): array
    {
        $message = __(
            // phpcs:ignore Generic.Files.LineLength -- single string literal so WordPress i18n tooling extracts it correctly.
            'Sample text for the PDF preview. This paragraph demonstrates how longer free-text entries wrap in the finished document and how much room they take up. It contains several sentences so the preview covers a realistic amount of text, of the kind real form submissions produce.',
            'formfabricator'
        );
        $notes = __(
            // phpcs:ignore Generic.Files.LineLength -- single string literal so WordPress i18n tooling extracts it correctly.
            'Here follows another sample paragraph with additional notes, so that the preview spans several pages and layout settings such as margins, font size and section order can be judged realistically.',
            'formfabricator'
        );

        return [
            ['type' => 'text', 'label' => __('First name', 'formfabricator'), 'value' => __('Jane', 'formfabricator')],
            ['type' => 'text', 'label' => __('Last name', 'formfabricator'), 'value' => __('Doe', 'formfabricator')],
            [
                'type'  => 'email',
                'label' => __('Email', 'formfabricator'),
                'value' => 'jane.doe@example.com',
            ],
            ['type' => 'text', 'label' => __('Phone', 'formfabricator'), 'value' => '+1 555 0123456'],
            [
                'type'  => 'text',
                'label' => __('Address', 'formfabricator'),
                'value' => __('123 Example Street, 10115 Springfield', 'formfabricator'),
            ],
            ['type' => 'text', 'label' => __('Date of birth', 'formfabricator'), 'value' => '14.03.1990'],
            [
                'type'  => 'text',
                'label' => __('Subject', 'formfabricator'),
                'value' => __('Membership application', 'formfabricator'),
            ],
            [
                'type'  => 'text',
                'label' => __('Membership type', 'formfabricator'),
                'value' => __('Supporting member', 'formfabricator'),
            ],
            ['type' => 'textarea', 'label' => __('Message', 'formfabricator'), 'value' => $message],
            [
                'type'  => 'textarea',
                'label' => __('Additional notes', 'formfabricator'),
                'value' => $notes,
            ],
            [
                'type'  => 'signature',
                'label' => __('Signature', 'formfabricator'),
                'value' => __('[Sample signature]', 'formfabricator'),
                'materialized_files' => [[
                    'name'   => 'signature.png',
                    'mime'   => 'image/png',
                    'base64' => self::dummySignaturePng(),
                ]],
            ],
            [
                'type'  => 'upload',
                'label' => __('Attachment', 'formfabricator'),
                'value' => 'sample-document.png',
                'materialized_files' => [[
                    'name'   => 'sample-document.png',
                    'mime'   => 'image/png',
                    'base64' => self::dummyUploadPng(),
                ]],
            ],
        ];
    }

    /**
     * Generate a 300×80 PNG showing a handwriting-style squiggle (signature placeholder). Falls back to a
     * solid-colour rectangle if GD is unavailable.
     *
     * @return string Base64-encoded dummy signature PNG.
     */
    private static function dummySignaturePng(): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return self::dummyFallbackPng();
        }
        $img   = imagecreatetruecolor(300, 80);
        $white = imagecolorallocate($img, 255, 255, 255);
        $gray  = imagecolorallocate($img, 210, 210, 210);
        $ink   = imagecolorallocate($img, 30, 30, 30);
        imagefill($img, 0, 0, $white);
        imagerectangle($img, 0, 0, 299, 79, $gray);
        /* Simple squiggle path */
        $pts = [20,55, 45,25, 70,50, 95,30, 120,55, 150,20, 180,50, 210,35, 240,55, 270,30, 290,45];
        for ($i = 0; $i < count($pts) - 2; $i += 2) {
            imageline($img, $pts[$i], $pts[$i + 1], $pts[$i + 2], $pts[$i + 3], $ink);
        }
        ob_start();
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $img is a GD image resource, not a filesystem path; imagepng() here writes to the output buffer (2-arg form), no file is touched.
        imagepng($img);
        $raw = ob_get_clean();
        unset($img);
        return base64_encode((string) $raw);
    }

    /**
     * Generate a 300×80 PNG showing a document icon (upload placeholder).
     *
     * @return string Base64-encoded dummy upload PNG.
     */
    private static function dummyUploadPng(): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return self::dummyFallbackPng();
        }
        $img   = imagecreatetruecolor(300, 80);
        $white = imagecolorallocate($img, 255, 255, 255);
        $gray  = imagecolorallocate($img, 210, 210, 210);
        $blue  = imagecolorallocate($img, 80, 120, 200);
        $dark  = imagecolorallocate($img, 60, 60, 60);
        imagefill($img, 0, 0, $white);
        imagerectangle($img, 0, 0, 299, 79, $gray);
        /* Document shape */
        imagerectangle($img, 110, 10, 160, 70, $blue);
        /* Folded corner */
        imageline($img, 148, 10, 160, 22, $blue);
        imageline($img, 148, 10, 148, 22, $blue);
        imageline($img, 148, 22, 160, 22, $blue);
        /* Lines representing text */
        imagefilledrectangle($img, 117, 30, 153, 32, $dark);
        imagefilledrectangle($img, 117, 38, 153, 40, $dark);
        imagefilledrectangle($img, 117, 46, 140, 48, $dark);
        /* Label */
        imagestring($img, 2, 170, 32, 'Attachment', $dark);
        ob_start();
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $img is a GD image resource, not a filesystem path; imagepng() here writes to the output buffer (2-arg form), no file is touched.
        imagepng($img);
        $raw = ob_get_clean();
        unset($img);
        return base64_encode((string) $raw);
    }

    /**
     * Minimal valid 1×1 white PNG for environments without GD.
     *
     * @return string Base64-encoded 1×1 white PNG fallback.
     */
    private static function dummyFallbackPng(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
    }
}
