<?php

/**
 * Admin list table displaying all saved forms.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.9
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

/**
 * Admin list table displaying all saved forms.
 */
class FormList
{
    /**
     * Registers admin hooks for the form list page.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('wp_ajax_fabricator_forms_delete', [self::class, 'ajaxDelete']);
        add_action('wp_ajax_fabricator_forms_duplicate', [self::class, 'ajaxDuplicate']);
        add_action('wp_ajax_fabricator_forms_bulk_delete', [self::class, 'ajaxBulkDelete']);
        add_action('wp_ajax_fabricator_forms_bulk_duplicate', [self::class, 'ajaxBulkDuplicate']);
        add_action('wp_ajax_fabricator_forms_export', [self::class, 'ajaxExport']);
        add_action('wp_ajax_fabricator_forms_import', [self::class, 'ajaxImport']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    /**
     * Appends a CSS class on the list page.
     *
     * @param string $classes Existing admin body classes.
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin body-class check, no data written.
        if (isset($_GET['page']) && $_GET['page'] === 'fabricator-forms') {
            $classes .= ' fabricator-list-page';
        }
        return $classes;
    }

    /**
     * Registers the main menu page and list submenu.
     *
     * @return void
     */
    public static function menu(): void
    {
        // Registered for everyone (view_forms guards the list page), so users of any one sub-page get the menu too.
        $menuIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
            . '<path fill="#fff" transform="rotate(-45 10 10)" d="'
            . 'M11.9.39l1.4 1.4c1.61.19 3.5-.74 4.61.37s.18 3 .37 4.61l1.4 1.4c'
            . '.39.39.39 1.02 0 1.41l-9.19 9.2c-.4.39-1.03.39-1.42 0L1.29 11c'
            . '-.39-.39-.39-1.02 0-1.42l9.2-9.19c.39-.39 1.02-.39 1.41 0z'
            . 'm.58 2.25l-.58.58 4.95 4.95.58-.58c-.19-.6-.2-1.22-.15-1.82'
            . '.02-.31.05-.62.09-.92.12-1 .18-1.63-.17-1.98s-.98-.29-1.98-.17'
            . 'c-.3.04-.61.07-.92.09-.6.05-1.22.04-1.82-.15z'
            . 'm4.02.93c.39.39.39 1.03 0 1.42s-1.03.39-1.42 0-.39-1.03 0-1.42 1.03-.39 1.42 0z'
            . 'm-6.72.36l-.71.7L15.44 11l.7-.71z'
            . 'M8.36 5.34l-.7.71 6.36 6.36.71-.7z'
            . 'M6.95 6.76l-.71.7 6.37 6.37.7-.71z'
            . 'M5.54 8.17l-.71.71 6.36 6.36.71-.71z'
            . 'M4.12 9.58l-.71.71 6.37 6.37.71-.71z'
            . '"/></svg>';
        add_menu_page(
            __('FormFabricator Form List', 'formfabricator'),
            __('FormFabricator', 'formfabricator'),
            \FabricatorForms\Plugin::ACCESS_CAP_PREFIX . 'view_forms',
            'fabricator-forms',
            [self::class, 'render'],
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- builds a data: URI for inline display; the alternative is writing an HTTP-reachable file. Not obfuscation.
            'data:image/svg+xml;base64,' . base64_encode($menuIconSvg),
            30
        );

        // Rename the auto-generated first submenu entry from "FormFabricator" to "Form List".
        add_submenu_page(
            'fabricator-forms',
            __('FormFabricator Form List', 'formfabricator'),
            __('Form List', 'formfabricator'),
            \FabricatorForms\Plugin::ACCESS_CAP_PREFIX . 'view_forms',
            'fabricator-forms',
            [self::class, 'render']
        );
    }

    /**
     * Renders the admin form list page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!\FabricatorForms\Plugin::userCan('view_forms')) {
            wp_die(esc_html__('Permission denied.', 'formfabricator'));
        }

        $forms   = FormModel::getAll();
        $new_url = admin_url('admin.php?page=fabricator-forms-editor');

        /* Strings for admin-formlist.js. */
        $list_i18n = [
            'deleteConfirm'      => __('Really delete form?', 'formfabricator'),
            // translators: %d is replaced client-side with the number of selected forms.
            'deleteConfirmMulti' => __('Really delete the selected forms (%d)?', 'formfabricator'),
            'chooseAction'       => __('Choose action', 'formfabricator'),
            'chooseActionAlert'  => __('Please choose an action.', 'formfabricator'),
            // translators: %d is replaced client-side with the number of selected forms.
            'selectedCount'      => __('%d selected', 'formfabricator'),
            'error'              => __('Error', 'formfabricator'),
            'importError'        => __('Import error', 'formfabricator'),
            // translators: %s is replaced client-side with the imported form's title.
            'importConfirm'      => __('Import the form "%s"? Its notifications send submissions to:', 'formfabricator'),
            'importNoRecipients' => __('(no notification recipients)', 'formfabricator'),
            'importUnnamed'      => __('Unnamed notification', 'formfabricator'),
            'importNoAddress'    => __('no address', 'formfabricator'),
            'copied'             => __('Copied!', 'formfabricator'),
            'copyShortcode'      => __('Copy shortcode', 'formfabricator'),
            'copy'               => __('Copy', 'formfabricator'),
        ];
        wp_localize_script(
            'fabricator-forms-admin-formlist',
            'FabricatorFormListPage',
            [
            'i18n'        => $list_i18n,
            'importNonce' => wp_create_nonce('fabricator_forms_import'),
            ]
        );
        ?>
        <canvas id="fabricator-particle-canvas"></canvas>

        <div class="wrap fabricator-list-wrap">

            <div class="fabricator-title-pill"><?php esc_html_e('Forms', 'formfabricator'); ?></div>
            <?php \FabricatorForms\Utils\Assets::renderNoticeDock(); ?>

            <div class="fabricator-list-toolbar" id="fabricator-list-toolbar">
                    <!-- Left: select-all + bulk actions -->
                    <?php $noForms = empty($forms) ? ' hidden' : ''; ?>
                    <div class="fabricator-toolbar-left" id="fabricator-toolbar-left"<?php echo esc_attr($noForms); ?>>
                        <label class="fabricator-select-all-wrap">
                            <input type="checkbox" id="fabricator-select-all" title="<?php echo esc_attr__('Select all', 'formfabricator'); ?>">
                        </label>
                        <div class="fabricator-bulk-bar" id="fabricator-bulk-bar" hidden>
                            <span class="fabricator-bulk-count" id="fabricator-bulk-count"></span>
                            <div class="fabricator-bulk-action-wrap">
                                <button class="button fabricator-list-btn" id="fabricator-bulk-action-btn">
                                    <span id="fabricator-bulk-action-label"><?php esc_html_e('Choose action', 'formfabricator'); ?></span> &#9660;
                                </button>
                                <div class="fabricator-row-dropdown" id="fabricator-bulk-action-dd" hidden>
                                    <button class="fabricator-dd-item" data-action="duplicate">
                                        <i class="fa-solid fa-copy"></i> <?php esc_html_e('Duplicate', 'formfabricator'); ?>
                                    </button>
                                    <div class="fabricator-dd-sep"></div>
                                    <button class="fabricator-dd-item fabricator-dd-item--danger" data-action="delete">
                                        <i class="fa-solid fa-trash"></i> <?php esc_html_e('Delete', 'formfabricator'); ?>
                                    </button>
                                </div>
                            </div>
                            <button class="button fabricator-list-btn button-primary" id="fabricator-bulk-apply"><?php esc_html_e('Apply', 'formfabricator'); ?></button>
                        </div>
                    </div>
                    <!-- Center: search -->
                    <div class="fabricator-toolbar-center" id="fabricator-toolbar-center"<?php echo esc_attr($noForms); ?>>
                        <input type="search" id="fabricator-form-search"
                               placeholder="<?php echo esc_attr__('Search forms…', 'formfabricator'); ?>" autocomplete="off">
                    </div>
                    <!-- Right: import input + new form -->
                    <div class="fabricator-toolbar-right">
                        <div class="fabricator-import-wrap">
                            <input type="text" id="fabricator-import-input"
                                   placeholder="<?php echo esc_attr__('Paste export string…', 'formfabricator'); ?>" autocomplete="off">
                            <button class="button fabricator-list-btn" id="fabricator-import-submit">
                                <i class="fa-solid fa-file-import"></i>
                            </button>
                        </div>
                        <a href="<?php echo esc_url($new_url); ?>"
                           class="button button-primary fabricator-list-btn">
                            <?php esc_html_e('+ New Form', 'formfabricator'); ?>
                        </a>
                    </div>
                </div>

            <div class="fabricator-list-empty" id="fabricator-list-empty"<?php echo !empty($forms) ? ' hidden' : ''; ?>>
                <h2><?php esc_html_e('No forms yet', 'formfabricator'); ?></h2>
                <p><?php esc_html_e('Create your first form and embed it via shortcode on any page.', 'formfabricator'); ?></p>
                <a href="<?php echo esc_url($new_url); ?>" class="button button-primary">
                    <?php esc_html_e('+ Create First Form', 'formfabricator'); ?>
                </a>
            </div>

            <?php // Always rendered, only hidden while empty: an imported or duplicated form is inserted here without a reload. ?>
            <div class="fabricator-form-list" id="fabricator-form-list"<?php echo empty($forms) ? ' hidden' : ''; ?>>
                <?php foreach ($forms as $form) : ?>
                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderRow() escapes every dynamic value itself; one template for the page and the AJAX-inserted rows. ?>
                    <?php echo self::renderRow($form); ?>
                <?php endforeach; ?>
                <div class="fabricator-no-results" id="fabricator-no-results" hidden>
                    <?php esc_html_e('No forms found.', 'formfabricator'); ?>
                </div>
            </div>
        </div>

        <!-- Export modal -->
        <div id="fabricator-export-modal" class="fabricator-modal-backdrop" hidden>
            <div class="fabricator-modal fabricator-modal--wide">
                <h3 class="fabricator-modal-title"><?php esc_html_e('Export form', 'formfabricator'); ?></h3>

                <!-- Loading state -->
                <div id="fabricator-export-loading">
                    <p class="fabricator-export-loading-label"><?php esc_html_e('Exporting…', 'formfabricator'); ?></p>
                    <div class="fabricator-export-bar-track">
                        <div class="fabricator-export-bar-fill" id="fabricator-export-bar"></div>
                    </div>
                </div>

                <!-- Result state -->
                <div id="fabricator-export-result" hidden>
                    <p class="fabricator-modal-hint">
                        <?php esc_html_e('Copy this string. It contains all fields, notifications and settings.', 'formfabricator'); ?>
                    </p>
                    <textarea id="fabricator-export-string" class="fabricator-modal-textarea" readonly rows="6"></textarea>
                    <div class="fabricator-modal-actions">
                        <button class="button fabricator-list-btn" id="fabricator-export-copy">
                            <i class="fa-solid fa-copy"></i> <?php esc_html_e('Copy', 'formfabricator'); ?>
                        </button>
                        <button class="button fabricator-list-btn" id="fabricator-export-close"><?php esc_html_e('Close', 'formfabricator'); ?></button>
                    </div>
                </div>
            </div>
        </div>

<!-- Delete confirmation modal -->
        <div id="fabricator-delete-modal" class="fabricator-modal-backdrop" hidden>
            <div class="fabricator-modal">
                <p class="fabricator-modal-msg" id="fabricator-modal-msg"><?php esc_html_e('Really delete form?', 'formfabricator'); ?></p>
                <div class="fabricator-modal-actions">
                    <button class="button fabricator-list-btn" id="fabricator-modal-cancel"><?php esc_html_e('Cancel', 'formfabricator'); ?></button>
                    <button class="button fabricator-list-btn fabricator-btn-danger" id="fabricator-modal-confirm"><?php esc_html_e('Delete', 'formfabricator'); ?></button>
                </div>
            </div>
        </div>

        <?php // Form list page JS lives in assets/js/admin-formlist.js, enqueued in Utils/Assets.php. ?>
        <?php
    }

    /**
     * AJAX handler to delete a single form.
     *
     * @return void
     */
    public static function ajaxDelete(): void
    {
        // wp_send_json_error() terminates the request via wp_die(), so execution
        // never falls through past a failed check below (no explicit return needed).
        \FabricatorForms\Utils\AjaxGuard::capability('edit_forms');
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        if (!$form_id || !check_ajax_referer('fabricator_forms_delete_' . $form_id, 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce verification failed.', 'formfabricator')], 403);
        }
        // Reported as it happened: a form that is not ours, or a failed delete, is not "deleted".
        if (!FormModel::delete($form_id, true)) {
            wp_send_json_error(['message' => __('The form could not be deleted.', 'formfabricator')], 400);
        }
        wp_send_json_success(['message' => __('Form deleted.', 'formfabricator')]);
    }

    /**
     * Renders the HTML for a single form-list row.
     *
     * @param \FabricatorForms\Form\FormModel $form The form model instance.
     * @return string Row HTML.
     */
    private static function renderRow(\FabricatorForms\Form\FormModel $form): string
    {
        $edit_url  = admin_url('admin.php?page=fabricator-forms-editor&form_id=' . $form->id);
        $shortcode = '[fabricator_form id="' . $form->id . '"]';
        $count     = count($form->fields);
        // Nonces only for users who may act; each handler re-checks the capability too.
        $can_edit  = \FabricatorForms\Plugin::userCan('edit_forms');
        $del_nonce = $can_edit ? wp_create_nonce('fabricator_forms_delete_' . $form->id) : '';
        $dup_nonce = $can_edit ? wp_create_nonce('fabricator_forms_duplicate_' . $form->id) : '';
        $exp_nonce = $can_edit ? wp_create_nonce('fabricator_forms_export_' . $form->id) : '';
        ob_start();
        ?>
        <div class="fabricator-form-row" data-title="<?php echo esc_attr(strtolower($form->title)); ?>">
            <label class="fabricator-row-check-wrap">
                <input type="checkbox" class="fabricator-row-check"
                       value="<?php echo esc_attr($form->id); ?>"
                       data-del-nonce="<?php echo esc_attr($del_nonce); ?>"
                       data-dup-nonce="<?php echo esc_attr($dup_nonce); ?>">
            </label>
            <div class="fabricator-form-row-icon">
                <i class="fa-solid fa-table-list"></i>
            </div>
            <div class="fabricator-form-row-main">
                <a href="<?php echo esc_url($edit_url); ?>" class="fabricator-form-row-title">
                    <?php echo esc_html($form->title); ?>
                </a>
                <div class="fabricator-form-row-meta">
                    <?php // translators: %d: number of fields in the form. ?>
                    <span><?php echo esc_html(sprintf(_n('%d Field', '%d Fields', $count, 'formfabricator'), $count)); ?></span>
                    <span class="fabricator-meta-sep">&middot;</span>
                    <code class="fabricator-form-row-code"><?php echo esc_html($shortcode); ?></code>
                </div>
            </div>
            <div class="fabricator-form-row-actions">
                <a href="<?php echo esc_url($edit_url); ?>" class="button fabricator-btn-edit">
                    <?php esc_html_e('Edit', 'formfabricator'); ?>
                </a>
                <div class="fabricator-row-menu-wrap">
                    <button class="button fabricator-row-menu-btn" title="<?php echo esc_attr__('More actions', 'formfabricator'); ?>">&#8942;</button>
                    <div class="fabricator-row-dropdown" hidden>
                        <button class="fabricator-dd-item fabricator-copy-shortcode"
                                data-code="<?php echo esc_attr($shortcode); ?>">
                            <i class="fa-solid fa-clipboard"></i> <?php esc_html_e('Copy shortcode', 'formfabricator'); ?>
                        </button>
                        <button class="fabricator-dd-item fabricator-duplicate-form"
                                data-id="<?php echo esc_attr($form->id); ?>"
                                data-nonce="<?php echo esc_attr($dup_nonce); ?>">
                            <i class="fa-solid fa-copy"></i> <?php esc_html_e('Duplicate', 'formfabricator'); ?>
                        </button>
                        <div class="fabricator-dd-sep"></div>
                        <button class="fabricator-dd-item fabricator-export-form"
                                data-id="<?php echo esc_attr($form->id); ?>"
                                data-nonce="<?php echo esc_attr($exp_nonce); ?>">
                            <i class="fa-solid fa-file-export"></i> <?php esc_html_e('Export', 'formfabricator'); ?>
                        </button>
                        <div class="fabricator-dd-sep"></div>
                        <button class="fabricator-dd-item fabricator-dd-item--danger fabricator-delete-form"
                                data-id="<?php echo esc_attr($form->id); ?>"
                                data-nonce="<?php echo esc_attr($del_nonce); ?>">
                            <i class="fa-solid fa-trash"></i> <?php esc_html_e('Delete', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * AJAX handler to duplicate a single form.
     *
     * @return void
     */
    public static function ajaxDuplicate(): void
    {
        \FabricatorForms\Utils\AjaxGuard::capability('edit_forms');
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        if (!$form_id || !check_ajax_referer('fabricator_forms_duplicate_' . $form_id, 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce verification failed.', 'formfabricator')], 403);
        }
        $result = FormModel::duplicate($form_id, true);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }
        $new_form = FormModel::get((int) $result);
        $row_html = $new_form ? self::renderRow($new_form) : '';
        wp_send_json_success(
            ['message' => __('Form duplicated.', 'formfabricator'), 'new_id' => $result, 'html' => $row_html]
        );
    }

    /**
     * AJAX handler to delete multiple forms at once.
     *
     * @return void
     */
    public static function ajaxBulkDelete(): void
    {
        \FabricatorForms\Utils\AjaxGuard::capability('edit_forms');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast::stringOrDefault() sits before the sanitizer; sniff can't see past it without customSanitizingFunctions.
        $ids    = json_decode(sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['ids'] ?? '[]'), '[]')), true);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast::stringOrDefault() sits before the sanitizer; sniff can't see past it without customSanitizingFunctions.
        $nonces = json_decode(sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['nonces'] ?? '[]'), '[]')), true);
        if (!is_array($ids) || !is_array($nonces)) {
            wp_send_json_error(['message' => __('Invalid data.', 'formfabricator')], 400);
        }
        $ids     = array_slice((array)$ids, 0, 200, true);
        $nonces  = array_slice((array)$nonces, 0, 200, true);
        $deleted = [];
        // $ids and $nonces are positionally paired (same index = same form) rather
        // than keyed by id, since each row has its own per-id delete nonce.
        foreach ($ids as $i => $raw_id) {
            if (!is_scalar($raw_id)) {
                continue;
            }
            $form_id = (int)$raw_id;
            $nonce   = sanitize_key($nonces[$i] ?? '');
            if (!$form_id || !wp_verify_nonce($nonce, 'fabricator_forms_delete_' . $form_id)) {
                continue;
            }
            // Only what was deleted: the page removes exactly these rows.
            if (FormModel::delete($form_id, true)) {
                $deleted[] = $form_id;
            }
        }
        wp_send_json_success(['deleted' => $deleted]);
    }

    /**
     * AJAX handler to duplicate multiple forms at once.
     *
     * @return void
     */
    public static function ajaxBulkDuplicate(): void
    {
        \FabricatorForms\Utils\AjaxGuard::capability('edit_forms');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast::stringOrDefault() sits before the sanitizer; sniff can't see past it without customSanitizingFunctions.
        $ids    = json_decode(sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['ids'] ?? '[]'), '[]')), true);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast::stringOrDefault() sits before the sanitizer; sniff can't see past it without customSanitizingFunctions.
        $nonces = json_decode(sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['nonces'] ?? '[]'), '[]')), true);
        if (!is_array($ids) || !is_array($nonces)) {
            wp_send_json_error(['message' => __('Invalid data.', 'formfabricator')], 400);
        }
        $ids     = array_slice((array)$ids, 0, 200, true);
        $nonces  = array_slice((array)$nonces, 0, 200, true);
        $created = [];
        foreach ($ids as $i => $raw_id) {
            if (!is_scalar($raw_id)) {
                continue;
            }
            $form_id = (int)$raw_id;
            $nonce   = sanitize_key($nonces[$i] ?? '');
            if (!$form_id || !wp_verify_nonce($nonce, 'fabricator_forms_duplicate_' . $form_id)) {
                continue;
            }
            $result = FormModel::duplicate($form_id, true);
            if (!is_wp_error($result)) {
                $created[] = $result;
            }
        }
        wp_send_json_success(['created' => $created]);
    }

    /**
     * AJAX handler that exports a form as a compressed base64 string.
     *
     * @return void
     */
    public static function ajaxExport(): void
    {
        // edit_forms: the export carries notification addresses view-only users never see.
        \FabricatorForms\Utils\AjaxGuard::capability('edit_forms');
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        $nonce   = sanitize_key($_POST['nonce'] ?? '');
        if (!$form_id || !wp_verify_nonce($nonce, 'fabricator_forms_export_' . $form_id)) {
            wp_send_json_error(['message' => __('Nonce verification failed.', 'formfabricator')], 403);
        }
        $form = FormModel::get($form_id);
        if (!$form) {
            wp_send_json_error(['message' => __('Form not found.', 'formfabricator')], 404);
        }
        $payload = ['v' => 2, 't' => $form->title];
        $payload['f'] = self::stripFieldDefaults($form->fields);
        if (!empty($form->notifications)) {
            $payload['n'] = $form->notifications;
        }
        if (!empty($form->settings)) {
            $payload['s'] = $form->settings;
        }
        $json       = (string)wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        $compressed = gzdeflate($json, 9);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- deflate+base64url so the form definition survives copy/paste; not obfuscation.
        $string     = rtrim(strtr(base64_encode($compressed), '+/', '-_'), '=');
        wp_send_json_success(['string' => $string]);
    }

    /**
     * AJAX handler that imports a form from a compressed base64 string.
     *
     * @return void
     */
    public static function ajaxImport(): void
    {
        \FabricatorForms\Utils\AjaxGuard::capability('edit_forms');
        $nonce = sanitize_key($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'fabricator_forms_import')) {
            wp_send_json_error(['message' => __('Nonce verification failed.', 'formfabricator')], 403);
        }
        $raw = sanitize_text_field(wp_unslash($_POST['string'] ?? ''));
        if ($raw === '') {
            wp_send_json_error(['message' => __('No import string provided.', 'formfabricator')], 400);
        }
        $padded  = $raw . str_repeat('=', (4 - strlen($raw) % 4) % 4);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the export string produced above (strict mode); re-sanitized before use, not obfuscation.
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            wp_send_json_error(['message' => __('Invalid import string.', 'formfabricator')], 400);
        }
        // Bound the inflated size to prevent a decompression bomb (CERT MEM10-C).
        $max_inflated_size = 5 * 1024 * 1024; // 5 MB is generous for a form export
        // Not-deflate input is the expected failure here (answered with an error below), so its warning is captured, not shown.
        $json = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => gzinflate($decoded, $max_inflated_size));
        if ($json === false) {
            $json = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => gzdecode($decoded, $max_inflated_size));
        }
        if ($json === false) {
            wp_send_json_error(['message' => __('Decompression failed.', 'formfabricator')], 400);
        }
        // Import always creates a NEW form rather than overwriting one with a matching id.
        $payload = json_decode($json, true);
        if (!is_array($payload)
            || !isset($payload['v'], $payload['t'], $payload['f'])
            || (int)$payload['v'] !== 2
        ) {
            wp_send_json_error(['message' => __('Unknown format.', 'formfabricator')], 400);
        }
        $fields = self::restoreFieldDefaults(is_array($payload['f']) ? $payload['f'] : []);
        // Route imported content through the same sanitizers as ajaxSave() — it's untrusted input.
        $payload_title = $payload['t'];
        $title         = sanitize_text_field(is_string($payload_title) ? $payload_title : '');
        $clean_fields  = \FabricatorForms\Admin\FormEditor::sanitizeFields($fields);
        $notifications = \FabricatorForms\Admin\FormEditor::sanitizeNotifications(
            is_array($payload['n'] ?? null) ? $payload['n'] : []
        );
        $id_error = \FabricatorForms\Admin\FormEditor::fieldIdError($clean_fields);
        if ($id_error !== '') {
            wp_send_json_error(['message' => $id_error], 422);
        }
        // Step one of two: report where the imported notifications would send submissions, so a pasted string can't
        // quietly route every future submission to someone else. admin-formlist.js asks, then repeats without preview.
        if (!empty($_POST['preview'])) {
            wp_send_json_success(
                [
                'preview'    => true,
                'title'      => $title,
                'recipients' => self::notificationRecipients($notifications),
                ]
            );
        }
        $result = FormModel::save(
            [
            'title'         => $title,
            'fields'        => $clean_fields,
            'notifications' => $notifications,
            'settings'      => \FabricatorForms\Admin\FormEditor::sanitizeSettings(
                is_array($payload['s'] ?? null) ? $payload['s'] : []
            ),
            ],
            0,
            true
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }
        $new_form = FormModel::get((int) $result);
        $row_html = $new_form ? self::renderRow($new_form) : '';
        wp_send_json_success(['new_id' => $result, 'html' => $row_html]);
    }

    /**
     * Every address-bearing value per notification (To/Cc/Bcc/Reply-To, routing rules and fallback), for the import confirmation.
     *
     * @param array $notifications Sanitized notifications.
     * @return array<int, array{name: string, enabled: bool, recipients: string[]}>
     */
    private static function notificationRecipients(array $notifications): array
    {
        $out = [];
        foreach ($notifications as $notif) {
            $values = [
                $notif['to'] ?? '', $notif['cc'] ?? '', $notif['bcc'] ?? '', $notif['reply_to'] ?? '',
                $notif['routing_fallback'] ?? '', $notif['routing_fallback_cc'] ?? '', $notif['routing_fallback_bcc'] ?? '',
            ];
            foreach ((array) ($notif['routing_rules'] ?? []) as $rule) {
                $values[] = $rule['email'] ?? '';
                $values[] = $rule['cc'] ?? '';
                $values[] = $rule['bcc'] ?? '';
            }
            $addresses = [];
            foreach ($values as $value) {
                foreach (preg_split('/[;,]+/', (string) $value) ?: [] as $part) {
                    $part = trim($part);
                    if ($part !== '' && !in_array($part, $addresses, true)) {
                        $addresses[] = $part;
                    }
                }
            }
            $out[] = [
                'name'       => (string) ($notif['name'] ?? ''),
                'enabled'    => !empty($notif['enabled']),
                'recipients' => $addresses,
            ];
        }
        return $out;
    }

    /**
     * Remove field config keys that match the field type's defaults.
     *
     * @param array $fields Fields array from the form model.
     * @return array Compacted fields array without redundant keys.
     */
    private static function stripFieldDefaults(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            // An imported "type" that is no string (a list, a number) is no field type: kept as it came, for
            // sanitizeFields() to drop. Passed on, it would end the import in a TypeError at FieldRegistry::get(string).
            $type     = is_array($field) && is_string($field['type'] ?? null) ? $field['type'] : '';
            $instance = $type !== '' ? \FabricatorForms\Fields\FieldRegistry::get($type) : null;
            if (!$instance) {
                $out[] = $field;
                continue;
            }
            $defaults = $instance->getDefaultConfig();
            $compact  = [];
            foreach ($field as $k => $v) {
                // Always kept: 'cols', 'children', 'date_format' (its default follows the site's date setting), and text
                // equal to a default, since defaults are translated and the importing site's would change the wording.
                $is_wording = is_string($v) && $v !== '';
                if ($k === 'type' || $k === 'id' || $k === 'cols' || $k === 'children' || $k === 'date_format') {
                    $compact[$k] = $v;
                } elseif ($is_wording || !array_key_exists($k, $defaults) || $defaults[$k] !== $v) {
                    $compact[$k] = $v;
                }
            }
            $out[] = $compact;
        }
        return $out;
    }

    /**
     * Re-merge field defaults stripped during export.
     *
     * @param array $fields Compacted fields array from import payload.
     * @return array Fields array with defaults restored.
     */
    private static function restoreFieldDefaults(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            // An imported "type" that is no string (a list, a number) is no field type: kept as it came, for
            // sanitizeFields() to drop. Passed on, it would end the import in a TypeError at FieldRegistry::get(string).
            $type     = is_array($field) && is_string($field['type'] ?? null) ? $field['type'] : '';
            $instance = $type !== '' ? \FabricatorForms\Fields\FieldRegistry::get($type) : null;
            if ($instance) {
                $out[] = array_merge($instance->getDefaultConfig(), $field);
            } else {
                $out[] = $field;
            }
        }
        return $out;
    }
}
