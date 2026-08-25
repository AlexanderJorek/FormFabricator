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
 * @version   1.0.3
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Admin;

use ForgeForms\Fields\FieldRegistry;

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
        add_action('wp_ajax_forge_forms_pdf_preview', [self::class, 'ajaxPreview']);
        add_action('wp_ajax_forge_save_pdf_layout', [self::class, 'handleSave']);
        add_action('wp_ajax_forge_forms_unlock_pdf_layout', [self::class, 'ajaxUnlock']);
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
        if (empty($data['forge_pdf_layout_lock']) || !\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            return $response;
        }
        $lock_owner = \ForgeForms\Utils\AdminLock::check('pdf_layout');
        if ($lock_owner) {
            $user = get_userdata($lock_owner);
            $response['forge_pdf_layout_lock_conflict'] = $user ? $user->display_name : __('another user', 'formfabricator');
        } else {
            \ForgeForms\Utils\AdminLock::acquire('pdf_layout');
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
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_forms_admin_nonce', 'nonce');
        \ForgeForms\Utils\AdminLock::release('pdf_layout', get_current_user_id());
        wp_send_json_success();
    }

    /**
     * AJAX handler that generates and returns a PDF preview as base64.
     *
     * @return void
     */
    public static function ajaxPreview(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_forms_admin_nonce', 'nonce');

        /* ---- Rate limit: PDF generation is expensive; throttle per-user preview requests. ---- */
        $rl_key = 'pdf_layout_preview_' . get_current_user_id();
        if (\ForgeForms\Utils\RateLimiter::increment($rl_key, 5) > 5) {
            wp_send_json_error(['message' => 'Please wait before requesting another preview.'], 429);
        }

        /* Use live settings from the request so the user doesn't have to save first */
        if (!empty($_POST['settings'])) {
            /* wp_unslash is required — WordPress's wp_magic_quotes() slashes all $_POST values */
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via the outer sanitize_textarea_field() call; WPCS loses track through the intermediate Sanitize::str() static call.
            $raw = json_decode(sanitize_textarea_field(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['settings'] ?? ''))), true);
            if (!is_array($raw)) {
                \ForgeForms\forge_log('ajaxPreview: settings JSON decode failed — ' . json_last_error_msg());
            }
            if (is_array($raw)) {
                $defs = self::defaults();
                $sanitized_hl = self::sanitizeHeaderLayout((array) ($raw['header_layout'] ?? []), false);
                $preview_opts = [
                    'logo_url'        => esc_url_raw(\ForgeForms\Utils\Sanitize::str($raw['logo_url'] ?? '')),
                    'logo_width'      => min(400, max(40, (int) ($raw['logo_width']     ?? 180))),
                    'accent_color'    => sanitize_hex_color(\ForgeForms\Utils\Sanitize::str($raw['accent_color']    ?? '')) ?: $defs['accent_color'],
                    'separator_color' => sanitize_hex_color(\ForgeForms\Utils\Sanitize::str($raw['separator_color'] ?? '')) ?: $defs['separator_color'],
                    'font_family'     => sanitize_key(\ForgeForms\Utils\Sanitize::str($raw['font_family'] ?? '', 'dejavusans')),
                    'font_size_body'  => min(20, max(6, (int) ($raw['font_size_body'] ?? 11))),
                    'title_size'      => min(36, max(10, (int) ($raw['title_size']     ?? 14))),
                    'footer_text'     => sanitize_textarea_field(\ForgeForms\Utils\Sanitize::str($raw['footer_text'] ?? '')),
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
                    'pre_option_forge_forms_pdf_layout',
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
        $path = \ForgeForms\PDF\Generator::generate($dummy, 0, __('Layout Preview', 'formfabricator'));

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
        if ($current_page === 'forge-forms-pdf-layout') {
            $classes .= ' forge-list-page';
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
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            return;
        }
        // Registered with the generic 'read' capability because ForgeForms uses its own
        // userCan('edit_pdf_layout') capability model rather than a real WP capability;
        // real enforcement happens above (addPage bails already) and again at the top
        // of render(). Any new callback reachable from this menu item MUST re-check
        // userCan('edit_pdf_layout') itself — do not rely on this menu registration alone.
        $hook = add_submenu_page(
            'forge-forms',
            __('FormFabricator PDF Layout', 'formfabricator'),
            __('PDF Layout', 'formfabricator'),
            'read',
            'forge-forms-pdf-layout',
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
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            wp_die(esc_html__('Permission denied.', 'formfabricator'));
        }

        wp_enqueue_media();

        $saved      = false;
        $save_error = '';
        if (isset($_POST['forge_pdf_layout_nonce'])
            && wp_verify_nonce(sanitize_key($_POST['forge_pdf_layout_nonce']), 'forge_pdf_layout')
        ) {
            $save_error = self::save();
            $saved      = $save_error === '';
        }

        $defs = self::defaults();
        $opts = array_merge($defs, (array) get_option('forge_forms_pdf_layout', []));

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
        $field_layout_mode = get_option('forge_forms_field_layout', 'block');

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
        $lock_owner_id   = \ForgeForms\Utils\AdminLock::check('pdf_layout');
        if ($lock_owner_id) {
            $lock_owner_user = get_userdata($lock_owner_id);
            $lock_owner_name = $lock_owner_user ? $lock_owner_user->display_name : __('another user', 'formfabricator');
        } else {
            \ForgeForms\Utils\AdminLock::acquire('pdf_layout');
        }
        wp_enqueue_script('heartbeat');
        $lock_admin_nonce = wp_create_nonce('forge_forms_admin_nonce');
        wp_localize_script(
            'forge-forms-pdflayout-lock',
            'ForgePdfLayoutLock',
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
            'forge-forms-admin-pdflayout',
            'ForgePdfLayoutPage',
            [
            'i18n' => self::pdfLayoutI18n(),
            'data' => [
                'siteName'        => $site_name,
                'siteUrl'         => $site_url,
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'nonce'           => wp_create_nonce('forge_forms_admin_nonce'),
                'dummySignature'  => $dummy_signature,
                'dummyText'       => $dummy_text,
                'dummyUpload'     => $dummy_upload,
                'fieldLayoutMode' => $field_layout_mode,
            ],
            ]
        );
        ?>
<canvas id="forge-particle-canvas"></canvas>
<div class="wrap forge-list-wrap">
    <div class="forge-title-pill"><i class="fa-solid fa-file-pdf"></i> <?php echo esc_html__('PDF Layout', 'formfabricator'); ?></div>
    <hr class="wp-header-end" style="display:none">

        <?php if ($saved) : ?>
        <div class="forge-settings-notice forge-settings-notice--success">
            <i class="fa-solid fa-circle-check"></i> <?php echo esc_html__('Layout saved.', 'formfabricator'); ?>
        </div>
        <?php elseif ($save_error !== '') : ?>
        <div class="forge-settings-notice forge-settings-notice--error">
            <i class="fa-solid fa-triangle-exclamation"></i> <?php echo esc_html($save_error); ?>
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

    <form method="post" id="forge-pdf-layout-form">
        <?php wp_nonce_field('forge_pdf_layout', 'forge_pdf_layout_nonce'); ?>
        <input type="hidden" name="forge_pdf_layout_snapshot" value="<?php echo esc_attr(self::snapshot()); ?>">
        <input type="hidden" name="section_hidden" id="forge-section-hidden-input"
            value="<?php echo esc_attr(implode(',', $opts['section_hidden'])); ?>">
        <input type="hidden" name="header_layout_json" id="forge-header-layout-input"
            value="<?php echo esc_attr(wp_json_encode($opts['header_layout'] ?? ['rows' => 8, 'elements' => []])); ?>">

        <div class="forge-pdf-editor-wrap">

            <!-- ── Settings Panel ── -->
            <div class="forge-pdf-settings-panel">

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title"><i class="fa-solid fa-table-columns"></i> <?php echo esc_html__('Header', 'formfabricator'); ?></h2>
                    <div class="forge-settings-field">
                        <p class="forge-card-hint">
                            <?php echo esc_html__('Arrange titles, logos and other content via drag & drop.', 'formfabricator'); ?>
                        </p>
                        <button type="button" class="button button-primary forge-hb-open-btn"
                            id="forge-open-header-builder-card">
                            <i class="fa-solid fa-pen-to-square"></i> <?php echo esc_html__('Edit header', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title"><i class="fa-solid fa-palette"></i> <?php echo esc_html__('Colors', 'formfabricator'); ?></h2>

                    <?php
                    $color_fields = [
                        ['accent_color',    __('Accent color (thick dividers)', 'formfabricator'), '#f59e0b'],
                        ['separator_color', __('Divider color (thin lines)', 'formfabricator'), '#c9cdd4'],
                    ];
                    foreach ($color_fields as [$id, $lbl, $default]) :
                        $eid = esc_attr($id);
                        ?>
                    <div class="forge-settings-field">
                        <label for="<?php echo esc_attr($eid); ?>"><?php echo esc_html($lbl); ?></label>
                        <input type="text" id="<?php echo esc_attr($eid); ?>"
                               name="<?php echo esc_attr($eid); ?>"
                               value="<?php echo esc_attr($opts[$id]); ?>"
                               class="forge-iris-input"
                               data-default-color="<?php echo esc_attr($default); ?>"
                               autocomplete="off" data-lpignore="true"
                               data-1p-ignore data-bwignore spellcheck="false">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title"><i class="fa-solid fa-font"></i> <?php echo esc_html__('Typography', 'formfabricator'); ?></h2>

                    <div class="forge-settings-field">
                        <label for="font_family"><?php echo esc_html__('Font', 'formfabricator'); ?></label>
                        <select id="font_family" name="font_family">
                            <?php foreach ($fonts as $val => $lbl) : ?>
                                <option value="<?php echo esc_attr($val); ?>"
                                    <?php selected($opts['font_family'], $val); ?>
                                ><?php echo esc_html($lbl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="forge-settings-field">
                        <label for="font_size_body"><?php echo esc_html__('Base font size:', 'formfabricator'); ?>
                            <span id="font-size-body-val"><?php echo (int) $opts['font_size_body']; ?></span> <?php echo esc_html__('pt', 'formfabricator'); ?>
                        </label>
                        <input type="range" id="font_size_body" name="font_size_body"
                            min="8" max="14" step="1" value="<?php echo (int) $opts['font_size_body']; ?>">
                    </div>

                    <div class="forge-settings-field">
                        <label for="title_size"><?php echo esc_html__('Title size:', 'formfabricator'); ?>
                            <span id="title-size-val"><?php echo (int) $opts['title_size']; ?></span> <?php echo esc_html__('pt', 'formfabricator'); ?>
                        </label>
                        <input type="range" id="title_size" name="title_size"
                            min="12" max="28" step="1" value="<?php echo (int) $opts['title_size']; ?>">
                    </div>
                </div>

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title">
                        <i class="fa-solid fa-arrows-left-right-to-line"></i> <?php echo esc_html__('Page margins (mm)', 'formfabricator'); ?>
                    </h2>
                    <div class="forge-margins-grid">
                        <?php
                        $margin_sides = [
                            'top'    => __('Top', 'formfabricator'),
                            'right'  => __('Right', 'formfabricator'),
                            'bottom' => __('Bottom', 'formfabricator'),
                            'left'   => __('Left', 'formfabricator'),
                        ];
                        foreach ($margin_sides as $side => $lbl) :
                            ?>
                        <div class="forge-settings-field">
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

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title">
                        <i class="fa-solid fa-table-list"></i> <?php echo esc_html__('Sections', 'formfabricator'); ?>
                    </h2>
                    <p class="forge-settings-hint" style="margin-top:0">
                        <?php echo esc_html__('Eye icon to show/hide.', 'formfabricator'); ?>
                    </p>
                    <ul id="forge-sections-sortable" class="forge-sections-list">
                        <?php foreach (array_keys(self::sectionLabels()) as $slug) :
                            $is_hidden = in_array($slug, $opts['section_hidden'], true);
                            ?>
                        <li class="forge-section-item<?php echo $is_hidden ? ' forge-section-hidden' : ''; ?>"
                            data-slug="<?php echo esc_attr($slug); ?>">
                            <span><?php echo esc_html(self::sectionLabels()[$slug]); ?></span>
                            <?php if ($slug === 'header') : ?>
                            <button type="button" class="forge-section-edit-btn"
                                id="forge-open-header-builder" title="<?php echo esc_attr__('Edit header', 'formfabricator'); ?>">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="forge-section-toggle" title="<?php echo esc_attr__('Show/Hide', 'formfabricator'); ?>">
                                <i class="fa-solid <?php echo $is_hidden ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title"><i class="fa-solid fa-shoe-prints"></i> <?php echo esc_html__('Footer', 'formfabricator'); ?></h2>
                    <div class="forge-settings-field">
                        <label for="footer_text"><?php echo esc_html__('Footer text', 'formfabricator'); ?></label>
                        <textarea id="footer_text" name="footer_text" rows="3"
                            placeholder="<?php echo esc_attr__('e.g. Company name · Address · Phone', 'formfabricator'); ?>"
                        ><?php echo esc_textarea($opts['footer_text']); ?></textarea>
                        <div class="forge-placeholder-chips">
                            <?php foreach (['{site_name}','{site_url}','{date}'] as $token) : ?>
                            <button type="button" class="forge-placeholder-chip"
                                data-insert="<?php echo esc_attr($token); ?>"><?php echo esc_html($token); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>


            </div><!-- /.forge-pdf-settings-panel -->

            <!-- ── Preview Panel ── -->
            <div class="forge-pdf-preview-panel">
                <div class="forge-preview-toolbar">
                    <span><i class="fa-solid fa-eye"></i> <?php echo esc_html__('Preview (A4)', 'formfabricator'); ?></span>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="button button-primary" form="forge-pdf-layout-form">
                            <i class="fa-solid fa-floppy-disk"></i> <?php echo esc_html__('Save', 'formfabricator'); ?>
                        </button>
                        <button type="button" class="button" id="forge-pdf-preview-btn">
                            <i class="fa-solid fa-file-pdf"></i> <?php echo esc_html__('Open PDF', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>
                <div class="forge-preview-stage">
                    <div class="forge-preview-stage-inner" id="forge-preview-stage-inner">
                        <div class="forge-a4-paper" id="forge-a4-paper"></div>
                    </div>
                </div>
            </div>

        </div><!-- /.forge-pdf-editor-wrap -->
    </form>
</div>

<!-- ── Header Builder Modal ── -->
<div id="forge-hb-modal" class="forge-hb-modal" hidden>
    <div class="forge-hb-overlay" id="forge-hb-overlay"></div>
    <div class="forge-hb-dialog">

        <div class="forge-hb-dialog-head">
            <span><i class="fa-solid fa-table-cells-large"></i> <?php echo esc_html__('Edit header', 'formfabricator'); ?></span>
            <button type="button" class="forge-hb-dialog-head-close"
                id="forge-hb-close" title="<?php echo esc_attr__('Close', 'formfabricator'); ?>">&#x2715;</button>
        </div>

        <div class="forge-hb-toolbar">
            <button type="button" class="button" id="forge-hb-add-title">
                <i class="fa-solid fa-heading"></i> <?php echo esc_html__('Title', 'formfabricator'); ?>
            </button>
            <button type="button" class="button" id="forge-hb-add-image"><i class="fa-solid fa-image"></i> <?php echo esc_html__('Image', 'formfabricator'); ?></button>
            <div style="width:1px;height:24px;background:#c3c4c7;margin:0 4px;"></div>
            <label><?php echo esc_html__('Height (rows of 5 mm):', 'formfabricator'); ?>
                <input type="number" id="forge-hb-rows" min="2" max="30" value="8" style="width:52px">
            </label>
            <span style="font-size:11px;color:#888;margin-left:4px;">
                <?php echo esc_html__('← Drag to position · Corners to resize · Del to delete', 'formfabricator'); ?>
            </span>
        </div>

        <div class="forge-hb-body">
            <div class="forge-hb-canvas-wrap">
                <div id="forge-hb-canvas" class="forge-hb-canvas"></div>
            </div>
            <div class="forge-hb-props" id="forge-hb-props">
                <p class="forge-hb-empty"><?php echo esc_html__('Select element', 'formfabricator'); ?><br><?php echo esc_html__('to edit', 'formfabricator'); ?></p>
            </div>
        </div>

        <div class="forge-hb-dialog-footer">
            <button type="button" class="button" id="forge-hb-cancel"><?php echo esc_html__('Cancel', 'formfabricator'); ?></button>
            <button type="button" class="button button-primary" id="forge-hb-apply">
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
                // Canvas element-type badges (hbMakeNode()) — reuse the same msgids as
                // the toolbar's "Title"/"Image" buttons above; 'elHtml' is a new, plain
                // "HTML" label distinct from the 'htmlCode' textarea field label.
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
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_pdf_layout', 'forge_pdf_layout_nonce');
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
     * Optimistic-concurrency snapshot hash of the current forge_forms_pdf_layout option.
     *
     * @return string Snapshot hash.
     */
    private static function snapshot(): string
    {
        return md5(wp_json_encode(get_option('forge_forms_pdf_layout', [])));
    }

    /**
     * Saves the PDF layout option.
     *
     * @return string Error message, or '' on success.
     */
    private static function save(): string
    {
        // Defense-in-depth: both current call sites (handleSave() and render())
        // already gate on Plugin::userCan('edit_pdf_layout') before reaching here,
        // but this method should not rely solely on callers remembering to check —
        // a single missed gate anywhere in the admin layer would otherwise be a
        // full privilege-escalation/CSRF path with no second line of defense.
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            return __('Insufficient permissions.', 'formfabricator');
        }
        if (!isset($_POST['forge_pdf_layout_nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['forge_pdf_layout_nonce'])), 'forge_pdf_layout')
        ) {
            return __('Security check failed. Please reload and try again.', 'formfabricator');
        }

        // Optimistic-concurrency guard: reject a save if the option changed since this snapshot.
        $expected_snapshot = isset($_POST['forge_pdf_layout_snapshot'])
            ? sanitize_text_field(wp_unslash($_POST['forge_pdf_layout_snapshot']))
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
            'forge_forms_pdf_layout',
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

        delete_transient('forge_pdf_template_fingerprints');
        return '';
    }

    /**
     * Resolves an image element's src to a local media-library attachment URL. Local attachment URLs resolve
     * immediately with no network access. External URLs are only ever fetched when $persist is true (i.e. on
     * final save, not on every live-preview keystroke), and are fetched exactly once via
     * media_sideload_image() — which downloads through wp_safe_remote_get() (WordPress's own SSRF guard,
     * rejecting loopback/private/link-local targets) and validates the result is actually an image before
     * storing it as a normal attachment. From then on the field behaves like any other local image and mPDF
     * never makes an outbound request for it.
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

        $attachment_id = media_sideload_image($src, 0, null, 'id');
        if (is_wp_error($attachment_id)) {
            \ForgeForms\forge_log(
                'ForgeForms PDFLayoutEditor: failed to sideload header image '
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
        $rows = min(30, max(2, (int) ($raw['rows'] ?? 8)));
        $elements = [];
        foreach ((array) ($raw['elements'] ?? []) as $el) {
            // $el is a per-element array decoded from client JSON — 'type'/'id'
            // are normally strings, but nothing guarantees that; sanitize_key()
            // has a strict string type hint and throws an uncaught TypeError
            // on an array/object value.
            $el_type = $el['type'] ?? '';
            $type = sanitize_key(is_string($el_type) ? $el_type : '');
            if (!in_array($type, ['title', 'image', 'html'], true)) {
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
                // The header-builder editor is a contenteditable box that writes
                // formatted HTML into el.content (bold/italic/color/superscript
                // spans) — el.text is only ever a plain-text fallback. Persist
                // content through the same inline-formatting allow-list used at
                // PDF-render time (includes/PDF/templates/layout.php), or the admin's
                // formatting is silently discarded on every save.
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
                $item['color'] = sanitize_hex_color(\ForgeForms\Utils\Sanitize::str($el['color'] ?? '')) ?: '#1d2327';
            } elseif ($type === 'image') {
                $src = self::resolveImageSrc(esc_url_raw(\ForgeForms\Utils\Sanitize::str($el['src'] ?? '')), $persist);
                if ($src === '') {
                    continue;
                }
                $item['src'] = $src;
                $item['fit'] = in_array($el['fit'] ?? '', ['contain', 'cover', 'fill'], true) ? $el['fit'] : 'contain';
            } elseif ($type === 'html') {
                $item['html'] = wp_kses_post(\ForgeForms\Utils\Sanitize::str($el['html'] ?? ''));
            }
            $elements[] = $item;
        }
        return ['rows' => $rows, 'elements' => $elements];
    }

    /**
     * Sample submission data shared by the server-rendered PDF preview and the browser-side HTML preview, so
     * both show identical content. Sized to run roughly 1.5–2 A4 pages at the default layout settings.
     *
     * @return array Dummy mapped-field entries.
     */
    public static function dummyFields(): array
    {
        $nachricht = 'Beispieltext für die PDF-Vorschau. Dieser Absatz '
            . 'demonstriert, wie längere Freitext-Eingaben im fertigen '
            . 'Dokument umbrechen und wie viel Platz sie einnehmen. Er '
            . 'enthält mehrere Sätze, damit sich die Vorschau über eine '
            . 'realistische Textmenge erstreckt, wie sie auch bei echten '
            . 'Formular-Einsendungen vorkommt.';
        $anmerkungen = 'Hier folgt ein weiterer Beispielabsatz mit '
            . 'zusätzlichen Anmerkungen, damit die Vorschau insgesamt '
            . 'mehrere Seiten umfasst und Layout-Einstellungen wie Ränder, '
            . 'Schriftgröße und Abschnittsreihenfolge realistisch '
            . 'beurteilt werden können.';

        return [
            ['type' => 'text', 'label' => 'Vorname', 'value' => 'Max'],
            ['type' => 'text', 'label' => 'Nachname', 'value' => 'Mustermann'],
            [
                'type'  => 'email',
                'label' => 'E-Mail',
                'value' => 'max.mustermann@example.de',
            ],
            ['type' => 'text', 'label' => 'Telefon', 'value' => '+49 151 23456789'],
            [
                'type'  => 'text',
                'label' => 'Anschrift',
                'value' => 'Musterstraße 12, 10115 Berlin',
            ],
            ['type' => 'text', 'label' => 'Geburtsdatum', 'value' => '14.03.1990'],
            ['type' => 'text', 'label' => 'Anliegen', 'value' => 'Mitgliedsantrag'],
            [
                'type'  => 'text',
                'label' => 'Mitgliedschaftsart',
                'value' => 'Fördermitglied',
            ],
            ['type' => 'textarea', 'label' => 'Nachricht', 'value' => $nachricht],
            [
                'type'  => 'textarea',
                'label' => 'Zusätzliche Anmerkungen',
                'value' => $anmerkungen,
            ],
            [
                'type'  => 'signature',
                'label' => 'Unterschrift',
                'value' => '[Beispielunterschrift]',
                'materialized_files' => [[
                    'name'   => 'unterschrift.png',
                    'mime'   => 'image/png',
                    'base64' => self::dummySignaturePng(),
                ]],
            ],
            [
                'type'  => 'upload',
                'label' => 'Anhang',
                'value' => 'beispiel-dokument.png',
                'materialized_files' => [[
                    'name'   => 'beispiel-dokument.png',
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
