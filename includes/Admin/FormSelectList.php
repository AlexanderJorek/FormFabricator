<?php

/**
 * Admin editor for reusable select-field option lists.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.4
 * @link      https://github.com/AlexanderJorek/FormFabricator
 */

namespace FabricatorForms\Admin;

defined('ABSPATH') || exit;

use FabricatorForms\Form\FormModel;
use FabricatorForms\Form\FormSelectModel;
use FabricatorForms\Form\FormRenderer;

/**
 * Admin editor for reusable select-field option lists.
 */
class FormSelectList
{
    /**
     * Registers admin hooks for the form-select list page.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('wp_ajax_fabricator_fsel_save', [self::class, 'ajaxSave']);
        add_action('wp_ajax_fabricator_fsel_delete', [self::class, 'ajaxDelete']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    /**
     * Appends a CSS class on the form-select page.
     *
     * @param string $classes Existing admin body classes.
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin body-class check, no data written.
        if (isset($_GET['page']) && $_GET['page'] === 'fabricator-forms-select') {
            $classes .= ' fabricator-list-page';
        }
        return $classes;
    }

    /**
     * Registers the Formular-Auswahl submenu page.
     *
     * @return void
     */
    public static function menu(): void
    {
        if (\FabricatorForms\Plugin::userCan('edit_forms')) {
            add_submenu_page(
                'fabricator-forms',
                __('Form Selection', 'formfabricator'),
                __('Form Selection', 'formfabricator'),
                'read',
                'fabricator-forms-select',
                [self::class, 'render']
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Admin page                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Renders the admin form-select list page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            wp_die(esc_html__('Permission denied.', 'formfabricator'));
        }

        $selects    = FormSelectModel::getAll();
        $all_forms  = FormModel::getAll();
        $save_nonce = wp_create_nonce('fabricator_fsel_save');
        ?>
        <canvas id="fabricator-particle-canvas"></canvas>

        <div class="wrap fabricator-list-wrap">
            <div class="fabricator-title-pill"><?php esc_html_e('Form Selection', 'formfabricator'); ?></div>
            <hr class="wp-header-end" style="display:none">

            <?php $noSelects = empty($selects) ? ' hidden' : ''; ?>
            <div class="fabricator-list-toolbar" id="fabricator-fsel-toolbar">
                <!-- Left: select-all + bulk actions -->
                <div class="fabricator-toolbar-left" id="fabricator-fsel-toolbar-left"<?php echo esc_attr($noSelects); ?>>
                    <label class="fabricator-select-all-wrap">
                        <input type="checkbox" id="fabricator-fsel-select-all" title="<?php echo esc_attr__('Select all', 'formfabricator'); ?>">
                    </label>
                    <div class="fabricator-bulk-bar" id="fabricator-fsel-bulk-bar" hidden>
                        <span class="fabricator-bulk-count" id="fabricator-fsel-bulk-count"></span>
                        <div class="fabricator-bulk-action-wrap">
                            <button class="button fabricator-list-btn" id="fabricator-fsel-bulk-action-btn">
                                <span id="fabricator-fsel-bulk-action-label"><?php esc_html_e('Choose action', 'formfabricator'); ?></span> &#9660;
                            </button>
                            <div class="fabricator-row-dropdown" id="fabricator-fsel-bulk-action-dd" hidden>
                                <button class="fabricator-dd-item fabricator-dd-item--danger" data-action="delete">
                                    <i class="fa-solid fa-trash"></i> <?php esc_html_e('Delete', 'formfabricator'); ?>
                                </button>
                            </div>
                        </div>
                        <button class="button fabricator-list-btn button-primary" id="fabricator-fsel-bulk-apply">
                            <?php esc_html_e('Apply', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>
                <!-- Center: search -->
                <div class="fabricator-toolbar-center" id="fabricator-fsel-toolbar-center"<?php echo esc_attr($noSelects); ?>>
                    <input type="search" id="fabricator-fsel-form-search"
                           placeholder="<?php echo esc_attr__('Search selections…', 'formfabricator'); ?>" autocomplete="off">
                </div>
                <!-- Right: new -->
                <div class="fabricator-toolbar-right">
                    <button type="button" class="button button-primary fabricator-list-btn fabricator-fsel-new-btn">
                        <?php esc_html_e('+ New Selection', 'formfabricator'); ?>
                    </button>
                </div>
            </div>

            <div class="fabricator-list-empty" id="fabricator-fsel-empty"<?php echo !empty($selects) ? ' hidden' : ''; ?>>
                <h2><?php esc_html_e('No form selections yet', 'formfabricator'); ?></h2>
                <p><?php esc_html_e('Create your first selection and embed it via shortcode on any page.', 'formfabricator'); ?></p>
                <button type="button" class="button button-primary fabricator-fsel-new-btn">
                    <?php esc_html_e('+ Create First Selection', 'formfabricator'); ?>
                </button>
            </div>

            <div class="fabricator-form-list" id="fabricator-fsel-list">
                <?php foreach ($selects as $fsel) : ?>
                    <?php self::renderRow($fsel); ?>
                <?php endforeach; ?>
                <div class="fabricator-no-results" id="fabricator-fsel-no-results" hidden></div>
            </div>
        </div>

        <!-- Editor modal -->
        <div id="fabricator-fsel-modal" class="fabricator-modal-backdrop" hidden>
            <div class="fabricator-modal fabricator-modal--settings" role="dialog" aria-modal="true">

                <div class="fabricator-modal-header">
                    <div class="fabricator-settings-titlerow">
                        <span class="fabricator-settings-field-icon">
                            <i class="fa-solid fa-layer-group"></i>
                        </span>
                        <h2 class="fabricator-modal-title" id="fabricator-fsel-modal-title"><?php esc_html_e('Edit selection', 'formfabricator'); ?></h2>
                    </div>
                    <button class="fabricator-modal-close" type="button" id="fabricator-fsel-cancel">&#x2715;</button>
                </div>

                <div class="fabricator-stab-bar">
                    <button class="fabricator-stab fabricator-stab-active"><?php esc_html_e('General', 'formfabricator'); ?></button>
                </div>

                <div class="fabricator-modal-body fabricator-settings-body">
                    <div class="fabricator-stab-panel fabricator-stab-active">

                        <div class="fabricator-sp-row">
                            <label class="fabricator-sp-label"><?php esc_html_e('Name of this selection', 'formfabricator'); ?></label>
                            <input type="text" id="fabricator-fsel-title-input" class="fabricator-sp-input"
                                   placeholder="<?php echo esc_attr__('e.g. Contact Selection', 'formfabricator'); ?>">
                        </div>

                        <div class="fabricator-sp-row">
                            <label class="fabricator-sp-label"><?php esc_html_e('Forms in this selection', 'formfabricator'); ?></label>
                            <div class="fabricator-fsel-cols-header" id="fabricator-fsel-col-header" hidden>
                                <span></span>
                                <span><?php esc_html_e('Form', 'formfabricator'); ?></span>
                                <span><?php esc_html_e('Label', 'formfabricator'); ?></span>
                                <span><?php esc_html_e('Description', 'formfabricator'); ?></span>
                                <span><i class="fa-regular fa-star"></i></span>
                                <span></span>
                            </div>
                            <div id="fabricator-fsel-items"
                                 style="display:flex;flex-direction:column;gap:4px;margin-bottom:8px;">
                                <!-- items injected by JS -->
                            </div>

                            <!-- Add button + dropdown -->
                            <div style="position:relative;">
                                <button type="button" class="fabricator-sp-add-option" id="fabricator-fsel-add-btn">
                                    <i class="fa-solid fa-plus"></i> <?php esc_html_e('Add form', 'formfabricator'); ?>
                                </button>
                                <div id="fabricator-fsel-search-wrap" hidden
                                     style="position:absolute;left:0;right:0;z-index:1000;
                                            background:#fff;border:1px solid #dcdcde;
                                            border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.15);
                                            overflow:hidden;">
                                    <div class="fabricator-fsel-search-row">
                                        <i class="fa-solid fa-magnifying-glass fabricator-fsel-search-icon"></i>
                                        <input type="text" id="fabricator-fsel-search"
                                               class="fabricator-fsel-search-input"
                                               placeholder="<?php echo esc_attr__('Search form…', 'formfabricator'); ?>" autocomplete="off">
                                    </div>
                                    <div id="fabricator-fsel-search-results"
                                         style="max-height:200px;overflow-y:auto;
                                                border-top:1px solid #f0f0f1;"></div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="fabricator-settings-footer">
                    <button class="fabricator-btn-primary" id="fabricator-fsel-save"><?php esc_html_e('Save', 'formfabricator'); ?></button>
                </div>

            </div>
        </div>

        <!-- Delete confirmation modal -->
        <div id="fabricator-fsel-del-modal" class="fabricator-modal-backdrop" hidden>
            <div class="fabricator-modal">
                <p class="fabricator-modal-msg"><?php esc_html_e('Really delete form selection?', 'formfabricator'); ?></p>
                <div class="fabricator-modal-actions">
                    <button class="button fabricator-list-btn" id="fabricator-fsel-del-cancel"><?php esc_html_e('Cancel', 'formfabricator'); ?></button>
                    <button class="button fabricator-list-btn fabricator-btn-danger" id="fabricator-fsel-del-confirm"><?php esc_html_e('Delete', 'formfabricator'); ?></button>
                </div>
            </div>
        </div>

        <?php // Particle background + form-select page JS: assets/js/admin-editor-canvas.js and assets/js/admin-formselect.js. ?>
        <?php self::renderScript($save_nonce, $all_forms, $selects); ?>
        <?php
    }

    /**
     * Renders a single form-select list row.
     *
     * @param FormSelectModel $fsel The form-select model instance to render.
     */
    private static function renderRow(FormSelectModel $fsel): void
    {
        $shortcode = '[fabricator_form_select id="' . $fsel->id . '"]';
        $count     = count($fsel->items);
        $del_nonce = wp_create_nonce('fabricator_fsel_delete_' . $fsel->id);
        ?>
        <div class="fabricator-form-row"
             data-id="<?php echo esc_attr($fsel->id); ?>"
             data-title="<?php echo esc_attr(strtolower($fsel->title)); ?>">
            <label class="fabricator-row-check-wrap">
                <input type="checkbox" class="fabricator-row-check"
                       value="<?php echo esc_attr($fsel->id); ?>"
                       data-del-nonce="<?php echo esc_attr($del_nonce); ?>">
            </label>
            <div class="fabricator-form-row-icon">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <div class="fabricator-form-row-main">
                <span class="fabricator-form-row-title fabricator-fsel-edit-link"
                      style="cursor:pointer;"
                      data-id="<?php echo esc_attr($fsel->id); ?>">
                    <?php echo esc_html($fsel->title); ?>
                </span>
                <div class="fabricator-form-row-meta">
                    <?php // translators: %d: number of forms in the selection. ?>
                    <span><?php echo esc_html(sprintf(_n('%d Form', '%d Forms', $count, 'formfabricator'), $count)); ?></span>
                    <span class="fabricator-meta-sep">&middot;</span>
                    <code class="fabricator-form-row-code"><?php echo esc_html($shortcode); ?></code>
                </div>
            </div>
            <div class="fabricator-form-row-actions">
                <button type="button" class="button fabricator-btn-edit fabricator-fsel-edit-btn"
                        data-id="<?php echo esc_attr($fsel->id); ?>">
                    <?php esc_html_e('Edit', 'formfabricator'); ?>
                </button>
                <div class="fabricator-row-menu-wrap">
                    <button class="button fabricator-row-menu-btn" title="<?php echo esc_attr__('More actions', 'formfabricator'); ?>">&#8942;</button>
                    <div class="fabricator-row-dropdown" hidden>
                        <button class="fabricator-dd-item fabricator-copy-shortcode"
                                data-code="<?php echo esc_attr($shortcode); ?>">
                            <i class="fa-solid fa-clipboard"></i> <?php esc_html_e('Copy shortcode', 'formfabricator'); ?>
                        </button>
                        <div class="fabricator-dd-sep"></div>
                        <button class="fabricator-dd-item fabricator-dd-item--danger fabricator-fsel-del-btn"
                                data-id="<?php echo esc_attr($fsel->id); ?>"
                                data-nonce="<?php echo esc_attr($del_nonce); ?>">
                            <i class="fa-solid fa-trash"></i> <?php esc_html_e('Delete', 'formfabricator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* AJAX handlers                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * AJAX handler to save or create a form-select entry.
     *
     * @return void
     */
    public static function ajaxSave(): void
    {
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('fabricator_fsel_save', 'nonce');

        $id        = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via the outer sanitize_text_field() call; WPCS loses track through the intermediate Sanitize::str() static call.
        $title     = sanitize_text_field(\FabricatorForms\Utils\Sanitize::str(wp_unslash($_POST['title'] ?? '')));
        $items_raw = json_decode(\FabricatorForms\Utils\Sanitize::str(sanitize_textarea_field(wp_unslash($_POST['items'] ?? '[]'))), true);
        if (!is_array($items_raw)) {
            wp_send_json_error(['message' => 'Invalid data.']);
        }
        if (count($items_raw) > 200) {
            wp_send_json_error(['message' => 'Too many items.']);
            return;
        }

        $new_id = FormSelectModel::save(['title' => $title, 'items' => $items_raw], $id, true);
        $fsel   = FormSelectModel::get($new_id);
        if (!$fsel) {
            wp_send_json_error(['message' => 'Save failed.'], 500);
            return;
        }

        ob_start();
        self::renderRow($fsel);
        $row_html = ob_get_clean();

        $response = [
            'id'     => $new_id,
            'html'   => $row_html,
            'is_new' => $id === 0,
            'title'  => $fsel->title,
            'items'  => $fsel->items,
        ];
        wp_send_json_success($response);
    }

    /**
     * AJAX handler to delete a form-select entry.
     *
     * @return void
     */
    public static function ajaxDelete(): void
    {
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
        check_ajax_referer('fabricator_fsel_delete_' . $id, 'nonce');

        FormSelectModel::delete($id, true);
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------ */
    /* Frontend shortcode                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Renders the fabricator_form_select shortcode output.
     *
     * @param array $atts Shortcode attributes.
     * @return string Rendered HTML output.
     */
    public static function shortcode(array $atts): string
    {
        $atts = shortcode_atts(['id' => 0], $atts);
        $id   = (int) $atts['id'];
        if ($id <= 0) {
            return '';
        }

        $fsel = FormSelectModel::get($id);
        if (!$fsel || empty($fsel->items)) {
            return '';
        }

        // Server picks the initially-shown form (falls back to index 0 if none marked
        // favorite); the front-end script then reads data-fav to preselect the same item
        $fav_idx = 0;
        foreach ($fsel->items as $i => $item) {
            if ($item['favorite']) {
                $fav_idx = $i;
                break;
            }
        }

        ob_start();
        $uid = 'fsel-' . $id;
        ?>
        <div class="fsel-wrap" id="<?php echo esc_attr($uid); ?>"
             data-fav="<?php echo esc_attr($fav_idx); ?>">

            <div class="fsel-selector">
                <div class="fsel-trigger" role="button" tabindex="0"
                     aria-haspopup="listbox" aria-expanded="false">
                    <div class="fsel-trigger-inner">
                        <strong class="fsel-trigger-label"></strong>
                        <span class="fsel-trigger-desc"></span>
                    </div>
                    <svg class="fsel-chevron" viewBox="0 0 20 20" fill="none"
                         xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M5 7.5 10 12.5 15 7.5" stroke="currentColor"
                              stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>

                <div class="fsel-options" role="listbox" hidden>
                <?php foreach ($fsel->items as $i => $item) :
                    $form  = FormModel::get($item['form_id']);
                    if (!$form) {
                        continue;
                    }
                    $label = $item['label'] !== '' ? $item['label'] : $form->title;
                    $desc  = $item['description'];
                    ?>
                    <div class="fsel-option<?php echo $i === $fav_idx ? ' fsel-option--active' : ''; ?>"
                         role="option"
                         aria-selected="<?php echo $i === $fav_idx ? 'true' : 'false'; ?>"
                         data-idx="<?php echo esc_attr($i); ?>"
                         data-label="<?php echo esc_attr($label); ?>"
                         data-desc="<?php echo esc_attr($desc); ?>"
                         tabindex="-1">
                        <strong class="fsel-opt-label"><?php echo esc_html($label); ?></strong>
                        <?php if ($desc !== '') : ?>
                            <span class="fsel-opt-desc"><?php echo esc_html($desc); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            </div><!-- /.fsel-selector -->

            <div class="fsel-forms">
                <?php foreach ($fsel->items as $i => $item) :
                    $form = FormModel::get($item['form_id']);
                    if (!$form) {
                        continue;
                    }
                    ?>
                    <div class="fsel-form<?php echo $i !== $fav_idx ? ' fsel-form--hidden' : ''; ?>"
                         data-idx="<?php echo esc_attr($i); ?>">
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FormRenderer::render() returns pre-escaped HTML; each field handler escapes its own output internally. ?>
                        <?php echo FormRenderer::render($item['form_id']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /* Inline JS                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Localizes assets/js/admin-formselect.js with this page's translated strings and data.
     *
     * @param string             $save_nonce Nonce for the save AJAX action.
     * @param FormModel[]        $all_forms  Every form, for the "add form" picker.
     * @param FormSelectModel[]  $selects    Existing form-select entries.
     * @return void
     */
    private static function renderScript(string $save_nonce, array $all_forms, array $selects): void
    {
        $fsel_i18n = [
            // translators: %d is replaced client-side with the number of selected form selections.
            'selectedCount'    => __('%d selected', 'formfabricator'),
            'createTitle'      => __('Create new selection', 'formfabricator'),
            'newSelectionName' => __('New selection', 'formfabricator'),
            'create'           => __('Create', 'formfabricator'),
            'editTitle'        => __('Edit selection', 'formfabricator'),
            'save'             => __('Save', 'formfabricator'),
            'label'            => __('Label', 'formfabricator'),
            'description'      => __('Description', 'formfabricator'),
            'preselectDefault' => __('Preselect as default', 'formfabricator'),
            'remove'           => __('Remove', 'formfabricator'),
            'noFormsFound'     => __('No forms found.', 'formfabricator'),
        ];
        ?>
        wp_localize_script(
            'fabricator-forms-admin-formselect',
            'FabricatorFormSelectPage',
            [
            'i18n'      => $fsel_i18n,
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'saveNonce' => $save_nonce,
            'allForms'  => array_map(fn($f) => ['id' => $f->id, 'title' => $f->title], $all_forms),
            'fselData'  => array_map(fn($s) => ['id' => $s->id, 'title' => $s->title, 'items' => $s->items], $selects),
            ]
        );
        ?>
        <?php
    }
}
