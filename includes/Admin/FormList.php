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
        if (\FabricatorForms\Plugin::userCan('view_forms')) {
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
                'read',
                'fabricator-forms',
                [self::class, 'render'],
                'data:image/svg+xml;base64,' . base64_encode($menuIconSvg),
                30
            );

            // Rename the auto-generated first submenu entry from "FormFabricator" to "Formular Liste"
            add_submenu_page(
                'fabricator-forms',
                __('FormFabricator Form List', 'formfabricator'),
                __('Form List', 'formfabricator'),
                'read',
                'fabricator-forms',
                [self::class, 'render']
            );
        }
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

        /* Localized strings consumed by assets/js/admin-formlist.js for
           dynamically-generated UI text (alerts, toasts, modal messages set
           from JS) via the wp_localize_script call below. */
        $list_i18n = [
            'deleteConfirm'      => __('Really delete form?', 'formfabricator'),
            // translators: %d is replaced client-side with the number of selected forms.
            'deleteConfirmMulti' => __('Really delete %d form(s)?', 'formfabricator'),
            'chooseAction'       => __('Choose action', 'formfabricator'),
            'chooseActionAlert'  => __('Please choose an action.', 'formfabricator'),
            // translators: %d is replaced client-side with the number of selected forms.
            'selectedCount'      => __('%d selected', 'formfabricator'),
            'error'              => __('Error', 'formfabricator'),
            'importError'        => __('Import error', 'formfabricator'),
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
            <hr class="wp-header-end" style="display:none">

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

            <?php if (!empty($forms)) : ?>
                <div class="fabricator-form-list" id="fabricator-form-list">
                    <?php foreach ($forms as $form) : ?>
                        <?php
                        $edit_url  = admin_url('admin.php?page=fabricator-forms-editor&form_id=' . $form->id);
                        $shortcode = '[fabricator_form id="' . $form->id . '"]';
                        $count     = count($form->fields);
                        $del_nonce = wp_create_nonce('fabricator_forms_delete_' . $form->id);
                        $dup_nonce = wp_create_nonce('fabricator_forms_duplicate_' . $form->id);
                        $exp_nonce = wp_create_nonce('fabricator_forms_export_' . $form->id);
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
                                <a href="<?php echo esc_url($edit_url); ?>"
                                   class="fabricator-form-row-title">
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
                                <a href="<?php echo esc_url($edit_url); ?>"
                                   class="button fabricator-btn-edit">
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
                    <?php endforeach; ?>
                    <div class="fabricator-no-results" id="fabricator-no-results" hidden>
                        <?php esc_html_e('No forms found.', 'formfabricator'); ?>
                    </div>
                </div>
            <?php endif; ?>
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
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        if (!$form_id || !check_ajax_referer('fabricator_forms_delete_' . $form_id, 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
        }
        FormModel::delete($form_id, true);
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
        $del_nonce = wp_create_nonce('fabricator_forms_delete_' . $form->id);
        $dup_nonce = wp_create_nonce('fabricator_forms_duplicate_' . $form->id);
        $exp_nonce = wp_create_nonce('fabricator_forms_export_' . $form->id);
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
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        if (!$form_id || !check_ajax_referer('fabricator_forms_duplicate_' . $form_id, 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
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
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $ids    = json_decode(\FabricatorForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['ids'] ?? '[]')), '[]'), true);
        $nonces = json_decode(\FabricatorForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['nonces'] ?? '[]')), '[]'), true);
        if (!is_array($ids) || !is_array($nonces)) {
            wp_send_json_error(['message' => 'Invalid data.'], 400);
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
            FormModel::delete($form_id, true);
            $deleted[] = $form_id;
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
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $ids    = json_decode(\FabricatorForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['ids'] ?? '[]')), '[]'), true);
        $nonces = json_decode(\FabricatorForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['nonces'] ?? '[]')), '[]'), true);
        if (!is_array($ids) || !is_array($nonces)) {
            wp_send_json_error(['message' => 'Invalid data.'], 400);
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
        if (!\FabricatorForms\Plugin::userCan('view_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        $nonce   = sanitize_key($_POST['nonce'] ?? '');
        if (!$form_id || !wp_verify_nonce($nonce, 'fabricator_forms_export_' . $form_id)) {
            wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
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
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $nonce = sanitize_key($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'fabricator_forms_import')) {
            wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
        }
        $raw = sanitize_text_field(wp_unslash($_POST['string'] ?? ''));
        if ($raw === '') {
            wp_send_json_error(['message' => __('No import string provided.', 'formfabricator')], 400);
        }
        $padded  = $raw . str_repeat('=', (4 - strlen($raw) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            wp_send_json_error(['message' => __('Invalid import string.', 'formfabricator')], 400);
        }
        // Bound the inflated size to prevent a decompression bomb (CERT MEM10-C).
        $max_inflated_size = 5 * 1024 * 1024; // 5 MB is generous for a form export
        $json = @gzinflate($decoded, $max_inflated_size);
        if ($json === false) {
            $json = @gzdecode($decoded, $max_inflated_size);
        }
        if ($json === false) {
            wp_send_json_error(['message' => __('Decompression failed.', 'formfabricator')], 400);
        }
        // Import always creates a NEW form rather than overwriting one with a matching id.
        $payload = json_decode($json, true);
        if (!is_array($payload)
            || !isset($payload['v'], $payload['t'], $payload['f'])
            || !in_array((int)$payload['v'], [1, 2], true)
        ) {
            wp_send_json_error(['message' => __('Unknown format.', 'formfabricator')], 400);
        }
        $fields = is_array($payload['f']) ? $payload['f'] : [];
        if ((int)$payload['v'] === 2) {
            $fields = self::restoreFieldDefaults($fields);
        }
        // Route imported content through the same sanitizers as ajaxSave() — it's untrusted input.
        $payload_title = $payload['t'];
        $result = FormModel::save(
            [
            'title'         => sanitize_text_field(is_string($payload_title) ? $payload_title : ''),
            'fields'        => \FabricatorForms\Admin\FormEditor::sanitizeFields($fields),
            'notifications' => \FabricatorForms\Admin\FormEditor::sanitizeNotifications(
                is_array($payload['n'] ?? null) ? $payload['n'] : []
            ),
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
     * Remove field config keys that match the field type's defaults.
     *
     * @param array $fields Fields array from the form model.
     * @return array Compacted fields array without redundant keys.
     */
    private static function stripFieldDefaults(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $type     = $field['type'] ?? '';
            $instance = \FabricatorForms\Fields\FieldRegistry::get($type);
            if (!$instance) {
                $out[] = $field;
                continue;
            }
            $defaults = $instance->getDefaultConfig();
            $compact  = [];
            foreach ($field as $k => $v) {
                // Always keep structural keys; drop others matching the type's default to shrink the export.
                if ($k === 'type' || $k === 'id' || $k === 'col') {
                    $compact[$k] = $v;
                } elseif (!array_key_exists($k, $defaults) || $defaults[$k] !== $v) {
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
            $type     = $field['type'] ?? '';
            $instance = \FabricatorForms\Fields\FieldRegistry::get($type);
            if ($instance) {
                $out[] = array_merge($instance->getDefaultConfig(), $field);
            } else {
                $out[] = $field;
            }
        }
        return $out;
    }
}
