<?php

/**
 * Renders form HTML for front-end display.
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

namespace ForgeForms\Form;

defined('ABSPATH') || exit;

use ForgeForms\Fields\FieldRegistry;

/**
 * Renders forge_form shortcode output and form HTML strings.
 */
class FormRenderer
{
    /**
     * Handles the [forge_form] shortcode and returns the rendered form HTML.
     *
     * @param array $atts Shortcode attributes (expects 'id' key).
     * @return string Rendered form HTML or empty string.
     */
    public static function shortcode(array $atts): string
    {
        $form_id = (int)($atts['id'] ?? 0);
        if (!$form_id) {
            return '';
        }
        return self::render($form_id);
    }

    /**
     * Renders a form as an HTML string.
     *
     * @param int        $form_id           Post ID of the form to render.
     * @param array      $settings_override Optional settings to override form defaults.
     * @param array|null $fields_override   Optional fields to use instead of the stored form's fields
     *                                      (used for unsaved-form preview).
     * @return string Rendered HTML string.
     */
    public static function render(int $form_id, array $settings_override = [], ?array $fields_override = null): string
    {
        $form = FormModel::get($form_id);
        if (!$form) {
            // Allow preview of unsaved forms: synthesise a minimal form object
            // when form_id=0 but a fields override is provided.
            if ($form_id === 0 && $fields_override !== null) {
                $form           = new \stdClass();
                $form->id       = 0;
                $form->title    = '';
                $form->fields   = [];
                $form->settings = [];
            } else {
                return '';
            }
        }
        if (!empty($settings_override)) {
            $form->settings = array_merge($form->settings, $settings_override);
        }
        if ($fields_override !== null) {
            $form->fields = $fields_override;
        }

        /* Not generated here: this HTML is served to every visitor of a cacheable page, so baking in
           a nonce/token would collide across visitors. front.js fetches both fresh via AJAX before submit. */
        $ajax_url     = admin_url('admin-ajax.php');
        $submit_label   = $form->settings['submit_label']   ?? __('Submit', 'formfabricator');
        $submit_working = $form->settings['submit_working'] ?? __('Sending…', 'formfabricator');
        $success_msg    = $form->settings['success_message'] ?? __('Thank you!', 'formfabricator');

        /* Resolve one handler per unique field type; let each field enqueue its own scripts. */
        $seen_handlers = [];
        foreach ($form->fields as $f) {
            $type = $f['type'] ?? '';
            if ($type && !isset($seen_handlers[$type])) {
                $h = FieldRegistry::get($type);
                if ($h) {
                    $seen_handlers[$type] = $h;
                    $h->enqueueFrontScripts();
                }
            }
        }
        $has_upload = self::anyFieldHandler($seen_handlers, static fn($h) => $h->needsMultipartEncoding());
        $has_pages  = self::anyFieldHandler($seen_handlers, static fn($h) => $h->isPageBreak());

        ob_start();
        ?>
        <div class="forge-form-wrap" id="forge-form-<?php echo esc_attr($form_id); ?>">
            <div class="forge-form-messages" role="alert" aria-live="polite" style="display:none;"></div>
            <form
                class="forge-form"
                id="forge-form-inner-<?php echo esc_attr($form_id); ?>"
                method="post"
                action="<?php echo esc_url($ajax_url); ?>"
                <?php echo $has_upload ? 'enctype="multipart/form-data"' : ''; ?>
                novalidate
                data-form-id="<?php echo esc_attr($form_id); ?>"
                <?php echo $has_pages ? 'data-has-pages="true"' : ''; ?>
            >
                <input type="hidden" name="action"     value="forge_forms_submit">
                <input type="hidden" name="form_id"    value="<?php echo esc_attr($form_id); ?>">
                <!-- Filled in by front.js from forge_forms_get_token immediately before submit. -->
                <input type="hidden" name="forge_nonce" value="" class="forge-nonce-field">
                <input type="hidden" name="forge_submission_token" value="" class="forge-submission-token-field">
                <!-- Honeypot -->
                <input type="text" name="forge_hp_field"
                       style="display:none!important" tabindex="-1" autocomplete="off">

                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderFields() returns pre-escaped HTML; each field handler escapes its own output internally. ?>
                <?php echo self::renderFields($form->fields); ?>

                <div class="forge-form-footer">
                    <button type="submit" class="forge-submit-btn"
                            data-working="<?php echo esc_attr($submit_working); ?>"
                            data-success="<?php echo esc_attr($success_msg); ?>">
                        <span class="forge-submit-label"><?php echo esc_html($submit_label); ?></span>
                        <span class="forge-submit-spinner" aria-hidden="true" style="display:none;"></span>
                    </button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders a flat list of fields (used for group children). No page-break handling; fields use their own plain IDs.
     *
     * @param array $fields Array of field config arrays.
     */
    private static function renderChildFields(array $fields): string
    {
        return self::renderFields($fields, insideGroup: true);
    }

    /**
     * Renders form fields as HTML, handling page-break divs and column layout.
     *
     * @param array $fields      Array of field configuration arrays.
     * @param bool  $insideGroup Whether the fields are inside a group field.
     * @return string HTML string of rendered fields.
     */
    private static function renderFields(array $fields, bool $insideGroup = false): string
    {
        $html    = '';
        $page    = 0;
        $in_page = false;
        $count   = count($fields);
        $i       = 0;

        /* If the form has any pagebreaks, wrap everything in page divs.
         * Open page 0 immediately so fields before the first pagebreak are included. */
        $has_pagebreaks = !$insideGroup && self::anyFieldHandler(
            array_map(
                static fn($f) => FieldRegistry::get($f['type'] ?? ''),
                $fields
            ),
            static fn($h) => $h !== null && $h->isPageBreak()
        );
        if ($has_pagebreaks) {
            $html   .= '<div class="forge-form-page forge-page-active" data-page="0">';
            $in_page = true;
            $page    = 1;
        }

        while ($i < $count) {
            $field_cfg = $fields[$i];
            $field_id  = $field_cfg['id']   ?? '';
            $cols      = (int)($field_cfg['cols'] ?? 12);

            if (!$field_id) {
                $i++;
                continue;
            }

            $handler = FieldRegistry::get($field_cfg['type'] ?? '');
            if (!$handler) {
                $i++;
                continue;
            }

            if ($handler->isGroupContainer()) {
                $children_html = self::renderChildFields($field_cfg['children'] ?? []);
                $group_cond    = method_exists($handler, 'rowCondAttr') ? $handler->rowCondAttr($field_cfg) : '';
                $html .= '<div class="forge-row"' . $group_cond . '><div class="forge-col forge-col-12">'
                    . $handler->openTag($field_cfg, $field_id)
                    . $children_html
                    . $handler->closeTag()
                    . '</div></div>';
                $i++;
                continue;
            }

            if ($handler->isPageBreak() && !$insideGroup) {
                $html .= $handler->renderBreak($field_cfg, $page);
                $in_page = true;
                $page++;
                $i++;
                continue;
            }

            $cond_attr = !empty($field_cfg['conditions']['rules'])
                ? ' data-conditions="' . esc_attr(wp_json_encode($field_cfg['conditions'])) . '"'
                : '';

            if ($cols === 6) {
                $next      = $fields[$i + 1] ?? null;
                $next_id   = $next['id']      ?? '';
                $next_cols = (int)($next['cols'] ?? 12);

                $next_handler = ($next && $next_cols === 6 && $next_id)
                    ? FieldRegistry::get($next['type'] ?? '') : null;
                // Page-break and group-container fields have their own dedicated rendering
                // path (renderBreak() / openTag()+children+closeTag()) and must never be
                // treated as a 2-column pairing partner: calling render() on a group field
                // directly hits its documented no-op fallback (openTag()+closeTag() with no
                // children), silently dropping every field inside the group from the output
                // even though FormProcessor still enforces their `required` rules server-side.
                if ($next_handler && ($next_handler->isPageBreak() || $next_handler->isGroupContainer())) {
                    $next_handler = null;
                }

                if ($next_handler) {
                    // Each field's own condition goes on ITS OWN column, not the shared row —
                    // that way the two fields still toggle independently, but stay visually
                    // paired side by side (the row/flex layout is untouched either way; only
                    // whichever column is hidden collapses) instead of each getting its own
                    // full-width row and stacking vertically once conditions are satisfied.
                    $col_a_cond = !empty($field_cfg['conditions']['rules'])
                        ? ' data-conditions="' . esc_attr(wp_json_encode($field_cfg['conditions'])) . '"'
                        : '';
                    $col_b_cond = !empty($next['conditions']['rules'])
                        ? ' data-conditions="' . esc_attr(wp_json_encode($next['conditions'])) . '"'
                        : '';
                    $col_a  = '<div class="forge-col forge-col-6"' . $col_a_cond . '>';
                    $col_a .= $handler->render($field_cfg, $field_id) . '</div>';
                    $col_b  = '<div class="forge-col forge-col-6"' . $col_b_cond . '>';
                    $col_b .= $next_handler->render($next, $next_id) . '</div>';
                    $html  .= '<div class="forge-row forge-row--pair">' . $col_a . $col_b . '</div>';
                    $i += 2;
                    continue;
                }

                $html .= '<div class="forge-row"' . $cond_attr . '><div class="forge-col forge-col-6">'
                    . $handler->render($field_cfg, $field_id)
                    . '</div></div>';
            } else {
                $html .= '<div class="forge-row"' . $cond_attr . '><div class="forge-col forge-col-12">'
                    . $handler->render($field_cfg, $field_id)
                    . '</div></div>';
            }

            $i++;
        }

        if ($in_page) {
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Returns true if any handler in the given array satisfies $check. Accepts null entries (from unresolved field types) and skips them.
     *
     * @param array    $handlers Array of BaseField|null values.
     * @param callable $check    fn(BaseField): bool
     */
    private static function anyFieldHandler(array $handlers, callable $check): bool
    {
        foreach ($handlers as $h) {
            if ($h !== null && $check($h)) {
                return true;
            }
        }
        return false;
    }
}
