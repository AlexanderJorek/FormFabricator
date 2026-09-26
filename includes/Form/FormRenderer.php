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
 * @version   1.0.7
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Form;

defined('ABSPATH') || exit;

use FabricatorForms\Fields\FieldRegistry;

/**
 * Renders fabricator_form shortcode output and form HTML strings.
 */
class FormRenderer
{
    /**
     * Handles the [fabricator_form] shortcode and returns the rendered form HTML.
     *
     * @param array|string $atts Shortcode attributes (expects 'id' key).
     * @return string Rendered form HTML or empty string.
     */
    public static function shortcode($atts): string
    {
        // Not `array $atts`: shortcode_parse_atts() returns '' (not []) for a bare [fabricator_form].
        $atts    = is_array($atts) ? $atts : [];
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

        /* "Show button when … conditions match": the same data-conditions contract the fields use, so front.js shows
           and hides the footer with them. FormProcessor re-checks the rules, since a hidden button stops nobody. */
        $submit_cond      = (array) ($form->settings['submit_conditions'] ?? []);
        $submit_cond_attr = '';
        if (!empty($submit_cond['rules'])) {
            $submit_cond_attr = ' data-conditions="' . esc_attr(
                (string) wp_json_encode(
                    [
                        'action' => 'show',
                        'match'  => ($submit_cond['match'] ?? 'all') === 'any' ? 'any' : 'all',
                        'rules'  => array_values((array) $submit_cond['rules']),
                    ]
                )
            ) . '"';
        }

        // Backstop for Assets::enqueueFront(), which misses widgets/page-builders/FSE embeds; idempotent.
        \FabricatorForms\Utils\Assets::ensureFrontAssets();

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

        // Two forms on one page repeated every id, so a label could focus the other form's input. Every form after
        // the first on the page gets a suffix on its ids (uniqueIds()), which front.js reads back.
        // Counted per page, not per form id: a form selection renders several *different* forms, and two of them
        // using the same field id ("email", say) collided while each was only its own first copy.
        self::$render_count++;
        $id_suffix = self::$render_count > 1 ? '--' . self::$render_count : '';

        ob_start();
        ?>
        <div class="fabricator-form-wrap" id="fabricator-form-<?php echo esc_attr($form_id); ?>">
            <div class="fabricator-form-messages" role="alert" aria-live="polite" style="display:none;"></div>
            <form
                class="fabricator-form"
                id="fabricator-form-inner-<?php echo esc_attr($form_id); ?>"
                method="post"
                action="<?php echo esc_url($ajax_url); ?>"
                <?php echo $has_upload ? 'enctype="multipart/form-data"' : ''; ?>
                novalidate
                data-form-id="<?php echo esc_attr($form_id); ?>"
                <?php echo $has_pages ? 'data-has-pages="true"' : ''; ?>
                <?php echo $id_suffix !== '' ? 'data-fabricator-id-suffix="' . esc_attr($id_suffix) . '"' : ''; ?>
            >
                <input type="hidden" name="action"     value="fabricator_forms_submit">
                <input type="hidden" name="form_id"    value="<?php echo esc_attr($form_id); ?>">
                <!-- Filled in by front.js from fabricator_forms_get_token immediately before submit. -->
                <input type="hidden" name="fabricator_nonce" value="" class="fabricator-nonce-field">
                <input type="hidden" name="fabricator_submission_token" value="" class="fabricator-submission-token-field">
                <!-- Honeypot -->
                <input type="text" name="fabricator_hp_field"
                       style="display:none!important" tabindex="-1" autocomplete="off">

                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderFields() returns pre-escaped HTML; each field handler escapes its own output internally. ?>
                <?php echo self::renderFields($form->fields); ?>

                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr()'d at assignment above. ?>
                <div class="fabricator-form-footer"<?php echo $submit_cond_attr; ?>>
                    <?php // Disabled by default: prevents a JS-disabled visitor from POSTing with no nonce; front.js enables it once ready. ?>
                    <button type="submit" class="fabricator-submit-btn" disabled
                            data-working="<?php echo esc_attr($submit_working); ?>"
                            data-success="<?php echo esc_attr($success_msg); ?>">
                        <span class="fabricator-submit-label"><?php echo esc_html($submit_label); ?></span>
                        <span class="fabricator-submit-spinner" aria-hidden="true" style="display:none;"></span>
                    </button>
                    <noscript>
                        <p class="fabricator-noscript-notice">
                            <?php echo esc_html__('This form requires JavaScript to be enabled in your browser in order to submit.', 'formfabricator'); ?>
                        </p>
                    </noscript>
                </div>
            </form>
        </div>
        <?php
        $html = (string) ob_get_clean();
        return $id_suffix === '' ? $html : self::uniqueIds($html, $id_suffix);
    }

    /**
     * How many forms have been rendered during this request, whichever form each one was.
     *
     * @var int
     */
    private static int $render_count = 0;

    /**
     * Appends $suffix to every id in $html and to each reference to one of those ids (label for, form, list, headers,
     * aria-* id lists, in-page #links), so a repeated copy of a form points only at itself. Field classes are left
     * untouched, and names too: they are what the server reads, and each copy is its own <form>.
     *
     * @param string $html   Rendered form markup.
     * @param string $suffix Suffix such as "--2".
     * @return string
     */
    private static function uniqueIds(string $html, string $suffix): string
    {
        if (!preg_match_all('/\sid\s*=\s*(["\'])(.*?)\1/i', $html, $m)) {
            return $html;
        }
        $ids  = array_flip($m[2]);
        $html = (string) preg_replace_callback(
            '/(\s(?:id|for|form|list|headers|aria-(?:describedby|labelledby|controls|owns|activedescendant|errormessage|details|flowto))\s*=\s*)(["\'])(.*?)\2/i',
            static function (array $a) use ($ids, $suffix): string {
                // Space-separated id lists (aria-describedby="a b") are suffixed reference by reference.
                $refs = preg_split('/(\s+)/', $a[3], -1, PREG_SPLIT_DELIM_CAPTURE);
                foreach ($refs as $i => $ref) {
                    if ($ref !== '' && isset($ids[$ref])) {
                        $refs[$i] = $ref . $suffix;
                    }
                }
                return $a[1] . $a[2] . implode('', $refs) . $a[2];
            },
            $html
        );
        return (string) preg_replace_callback(
            '/(\shref\s*=\s*)(["\'])#(.*?)\2/i',
            static fn(array $a): string => $a[1] . $a[2] . '#' . (isset($ids[$a[3]]) ? $a[3] . $suffix : $a[3]) . $a[2],
            $html
        );
    }

    /**
     * Returns the data-conditions attribute for a field, or '' when it has no condition rules.
     *
     * @param array|null $field_cfg Field configuration, or null.
     * @return string Ready-to-print attribute, including its leading space, or ''.
     */
    private static function conditionAttr(?array $field_cfg): string
    {
        if (!$field_cfg || empty($field_cfg['conditions']['rules'])) {
            return '';
        }
        return ' data-conditions="' . esc_attr((string) wp_json_encode($field_cfg['conditions'])) . '"';
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
            $html   .= '<div class="fabricator-form-page fabricator-page-active" data-page="0">';
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
                $html .= '<div class="fabricator-row"' . $group_cond . '><div class="fabricator-col fabricator-col-12">'
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

            $cond_attr = self::conditionAttr($field_cfg);

            if ($cols === 6) {
                $next      = $fields[$i + 1] ?? null;
                $next_id   = $next['id']      ?? '';
                $next_cols = (int)($next['cols'] ?? 12);

                $next_handler = ($next && $next_cols === 6 && $next_id)
                    ? FieldRegistry::get($next['type'] ?? '') : null;
                // Page-break/group fields must never pair: render() on a group field silently drops its children (no-op fallback).
                if ($next_handler && ($next_handler->isPageBreak() || $next_handler->isGroupContainer())) {
                    $next_handler = null;
                }

                if ($next_handler) {
                    // Each field's condition goes on its own column, not the shared row, so the pair toggles independently.
                    $col_a_cond = self::conditionAttr($field_cfg);
                    $col_b_cond = self::conditionAttr($next);
                    $col_a  = '<div class="fabricator-col fabricator-col-6"' . $col_a_cond . '>';
                    $col_a .= $handler->render($field_cfg, $field_id) . '</div>';
                    $col_b  = '<div class="fabricator-col fabricator-col-6"' . $col_b_cond . '>';
                    $col_b .= $next_handler->render($next, $next_id) . '</div>';
                    $html  .= '<div class="fabricator-row fabricator-row--pair">' . $col_a . $col_b . '</div>';
                    $i += 2;
                    continue;
                }

                $html .= '<div class="fabricator-row"' . $cond_attr . '><div class="fabricator-col fabricator-col-6">'
                    . $handler->render($field_cfg, $field_id)
                    . '</div></div>';
            } else {
                $html .= '<div class="fabricator-row"' . $cond_attr . '><div class="fabricator-col fabricator-col-12">'
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
