<?php

/**
 * MPDF HTML layout template for form submission PDF output.
 *
 * NOTE: This is rendered by mPDF, not the browser — it is not subject to WordPress's
 * default output-escaping/KSES filters or CSP. Field values echoed here ($value in the
 * 'field' closure) arrive already pre-escaped by each field type's own pdfData()/map()
 * handler, and are then passed through a narrow wp_kses() allowlist as defense-in-depth
 * in case a field renderer's own escaping is ever incomplete.
 *
 * That wp_kses() call is NOT in this file: it runs once in Generator.php (on $pdf['cell_html'],
 * against FABRICATOR_PDF_ALLOWED_VALUE_TAGS or Utils\HtmlSanitizer::allowedTags()) before the
 * value is handed to the 'field' closure, and it has to happen there because Generator wraps the
 * result in marker spans and <img> tags that a later kses() pass would strip. This file only
 * DEFINES that constant (below) for Generator to use. Values reaching the closures here are
 * therefore already sanitized — do not read the definition below as a second filtering layer.
 * The one kses() this file performs is on the header title (line ~184), against the separate
 * FABRICATOR_PDF_HEADER_TITLE_ALLOWED_TAGS.
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

defined('ABSPATH') || exit;

// Generator also runs outside wp-admin (REST, cron, WP-CLI, another plugin's do_action()), where Plugin::init() never
// loaded the admin classes, so this template died with a fatal error there.
if (!class_exists(\FabricatorForms\Admin\PDFLayoutEditor::class)) {
    require_once FABRICATOR_FORMS_PATH . 'includes/Admin/PDFLayoutEditor.php';
}
$fabricator_defaults     = \FabricatorForms\Admin\PDFLayoutEditor::defaults();
$fabricator_raw          = (array) get_option('fabricator_forms_pdf_layout', []);
$fabricator_o             = array_merge($fabricator_defaults, $fabricator_raw);
$fabricator_field_layout = get_option('fabricator_forms_field_layout', 'block');

// Validate these are hex colors before interpolating into CSS; esc_attr() alone doesn't block `;`/`(`/`)`.
$fabricator_hex_color_re = '/^#[0-9a-fA-F]{3,8}$/';
$fabricator_accent = preg_match($fabricator_hex_color_re, (string) $fabricator_o['accent_color'])
    ? $fabricator_o['accent_color'] : $fabricator_defaults['accent_color'];
$fabricator_sep = preg_match($fabricator_hex_color_re, (string) $fabricator_o['separator_color'])
    ? $fabricator_o['separator_color'] : $fabricator_defaults['separator_color'];
$fabricator_accent    = esc_attr($fabricator_accent);
$fabricator_sep       = esc_attr($fabricator_sep);
// Clamped, not merely cast: these land unquoted in CSS font-size declarations below. Bounds match PDFLayoutEditor's save()/ajaxPreview().
$fabricator_fs        = min(20, max(6, (int) $fabricator_o['font_size_body']));
$fabricator_title_fs  = min(36, max(10, (int) $fabricator_o['title_size']));
$fabricator_logo_w    = min(400, max(40, (int) $fabricator_o['logo_width']));
$fabricator_font      = match ($fabricator_o['font_family']) {
    'dejavuserif'    => 'dejavuserif',
    'dejavusansmono' => 'dejavusansmono',
    'freemono'       => 'freemono',
    default          => 'dejavusans',
};

// Fail closed: never fall back to fetching the raw logo_url directly, since mPDF has no SSRF guard of its own.
$fabricator_logo_post_id = !empty($fabricator_o['logo_url']) ? attachment_url_to_postid($fabricator_o['logo_url']) : 0;
$fabricator_logo_path    = $fabricator_logo_post_id ? (get_attached_file($fabricator_logo_post_id) ?: '') : '';

$fabricator_section_hidden = is_array($fabricator_o['section_hidden']) ? $fabricator_o['section_hidden'] : [];

// Clamped at render time too, with PDFLayoutEditor::save()'s bounds: the option can also come from an older version or a
// direct database edit, and these values drive page geometry and the header's absolute positions.
$fabricator_margin_top_mm    = min(50, max(0, (int) ($fabricator_o['margin_top'] ?? 15)));
$fabricator_margin_left_mm   = min(50, max(0, (int) ($fabricator_o['margin_left'] ?? 15)));
$fabricator_margin_right_mm  = min(50, max(0, (int) ($fabricator_o['margin_right'] ?? 15)));
$fabricator_margin_bottom_mm = min(50, max(0, (int) ($fabricator_o['margin_bottom'] ?? 15)));

// defined()-guarded since this file is include()d (not include_once) and can run more than once per process.
if (!defined('FABRICATOR_PDF_HEADER_TITLE_ALLOWED_TAGS')) {
    define(
        'FABRICATOR_PDF_HEADER_TITLE_ALLOWED_TAGS',
        [
        // Must match PDFLayoutEditor::sanitizeHeaderLayout()'s save-time allowlist (style on u/span), or accepted styling gets silently stripped here.
        'b'      => [],
        'strong' => [],
        'i'      => [],
        'em'     => [],
        'u'      => ['style' => true],
        's'      => [],
        'del'    => [],
        'sup'    => [],
        'sub'    => [],
        'span'   => ['style' => true],
        'br'     => [],
        ]
    );
}

// Defense-in-depth allowlist applied to each field's raw cell_html before Generator.php wraps it with marker spans.
if (!defined('FABRICATOR_PDF_ALLOWED_VALUE_TAGS')) {
    define(
        'FABRICATOR_PDF_ALLOWED_VALUE_TAGS',
        [
        'br'     => [],
        'strong' => [],
        'em'     => [],
        ]
    );
}

return [
    'margin_top_mm'    => $fabricator_margin_top_mm,
    'margin_left_mm'   => $fabricator_margin_left_mm,
    'margin_right_mm'  => $fabricator_margin_right_mm,
    'margin_bottom_mm' => $fabricator_margin_bottom_mm,

    'section_hidden' => $fabricator_section_hidden,

    'base_css' => function () use ($fabricator_accent, $fabricator_sep, $fabricator_fs, $fabricator_title_fs, $fabricator_font): string {
        return '
        <style>
            body        { font-family:' . $fabricator_font . '; font-size:' . $fabricator_fs . 'pt; }
            .field-block { margin-bottom:14px; }
            .field-label { font-weight:bold; font-size:' . $fabricator_title_fs . 'pt; margin-bottom:4px; color:#222; }
            .field-separator-thin  { border-bottom:1px solid ' . $fabricator_sep . '; margin-bottom:4px; }
            .field-value           { font-size:' . $fabricator_fs . 'pt; margin-bottom:5px; color:#333; }
            .field-separator-thick { border-bottom:3px solid ' . $fabricator_accent . '; margin-top:2px; }
            .pdf-link   { font-size:' . ($fabricator_fs - 1) . 'pt; margin-top:4px; display:block; }
            .section-metadata { background:#f9f9f9; border:1px solid #e0e0e0;'
            . ' padding:8px 10px; font-size:' . ($fabricator_fs - 2) . 'pt; margin-bottom:12px; }
            .section-legal    { font-size:' . ($fabricator_fs - 3) . 'pt; color:#666; margin-top:8px; line-height:1.4; }
        </style>';
    },

    'header' => function (string $title) use (
        $fabricator_logo_path,
        $fabricator_logo_w,
        $fabricator_title_fs,
        $fabricator_o,
        $fabricator_hex_color_re,
        $fabricator_margin_top_mm,
        $fabricator_margin_left_mm,
        $fabricator_margin_right_mm
    ): string {
        $hb = $fabricator_o['header_layout'] ?? [];
        $elements = $hb['elements'] ?? [];

        /* ── Grid-based header (header builder was used) ── */
        if (!empty($elements)) {
            $cols     = 42;
            $margin_l = $fabricator_margin_left_mm;
            $margin_r = $fabricator_margin_right_mm;
            $margin_t = $fabricator_margin_top_mm;
            $w_mm     = 210 - $margin_l - $margin_r;
            $cell_mm  = round($w_mm / $cols, 4); // square cells: same unit for x and y

            /* Header height = lowest element's bottom edge in cells × cell_mm */
            $max_bottom = 0;
            foreach ($elements as $el) {
                $b = (int) ($el['y'] ?? 0) + max(1, (int) ($el['h'] ?? 1));
                if ($b > $max_bottom) {
                    $max_bottom = $b;
                }
            }
            $header_h_mm = round($max_bottom * $cell_mm, 2);

            // Spacer div occupies the header area in the content flow so fields below
            // start after it; the header elements themselves are position:absolute.
            $out = '<div style="height:' . $header_h_mm . 'mm;">&nbsp;</div>';

            foreach ($elements as $el) {
                $ex      = max(0, (int) ($el['x'] ?? 0));
                $ey      = max(0, (int) ($el['y'] ?? 0));
                $ew      = max(1, (int) ($el['w'] ?? $cols));
                $eh      = max(1, (int) ($el['h'] ?? 1));
                $type    = $el['type'] ?? '';
                $el_w_mm = round($ew * $cell_mm, 2);
                $el_h_mm = round($eh * $cell_mm, 2);
                $abs_l   = round($margin_l + $ex * $cell_mm, 2); // from page left edge
                $abs_t   = round($margin_t + $ey * $cell_mm, 2); // from page top edge

                $out .= '<div style="position:absolute;left:' . $abs_l . 'mm;top:' . $abs_t . 'mm;'
                      . 'width:' . $el_w_mm . 'mm;height:' . $el_h_mm . 'mm;overflow:hidden;">';

                if ($type === 'image' && !empty($el['src'])) {
                    // Fail closed: never fall back to the raw src value, since mPDF has no SSRF guard of its own.
                    $post_id  = attachment_url_to_postid($el['src']);
                    $img_path = $post_id ? (get_attached_file($post_id) ?: '') : '';
                    if ($img_path !== '') {
                        $out .= '<img src="' . esc_attr($img_path) . '" style="width:' . $el_w_mm . 'mm;height:auto;" />';
                    }
                } elseif ($type === 'title') {
                    // Same 6..72 clamp as PDFLayoutEditor::sanitizeHeaderLayout()/hbSize(), so this render path can't honour an unbounded stored size.
                    $fs = min(72, max(6, (int) ($el['size'] ?? 18)));
                    // Validate hex color format before it lands in a CSS property value; esc_attr() alone isn't enough.
                    $raw_color = (string) ($el['color'] ?? '#1d2327');
                    $color     = esc_attr(
                        preg_match($fabricator_hex_color_re, $raw_color) ? $raw_color : '#1d2327'
                    );
                    $align = in_array($el['align'] ?? '', ['left','center','right'], true)
                           ? $el['align'] : 'left';
                    $raw   = $el['content'] ?? $el['text'] ?? '{form_title}';
                    $raw   = str_replace('{form_title}', esc_html($title), $raw);
                    $safe  = wp_kses($raw, FABRICATOR_PDF_HEADER_TITLE_ALLOWED_TAGS);
                    $out .= '<div style="font-size:' . $fs . 'pt;color:' . $color
                          . ';text-align:' . $align . ';line-height:' . $el_h_mm . 'mm;">'
                          . $safe . '</div>';
                }

                $out .= '</div>';
            }

            return $out;
        }

        /* ── Default header (no builder layout set) ── */
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $fabricator_logo_path comes from get_attached_file() on a resolved attachment id, never raw user text.
        $has_logo = file_exists($fabricator_logo_path) && is_readable($fabricator_logo_path);
        if (!$has_logo && !empty($fabricator_o['logo_url'])) {
            \FabricatorForms\fabricator_log("PDF header: logo missing at {$fabricator_logo_path}");
        }

        if ($has_logo) {
            $logo_cell  = '<td style="width:' . $fabricator_logo_w . 'px;vertical-align:middle;">'
                . '<img src="' . esc_attr($fabricator_logo_path) . '" style="width:' . $fabricator_logo_w
                . 'px;height:auto;" /></td>';
            $title_cell = '<td style="text-align:right;vertical-align:middle;'
                . 'font-size:' . $fabricator_title_fs . 'pt;font-weight:bold;'
                . 'padding-left:10px;">' . esc_html($title) . '</td>';
        } else {
            $logo_cell  = '';
            $title_cell = '<td style="text-align:left;vertical-align:middle;'
                . 'font-size:' . $fabricator_title_fs . 'pt;font-weight:bold;">'
                . esc_html($title) . '</td>';
        }

        return '
        <table style="width:100%;border-collapse:collapse;margin-bottom:6px;">
            <tr>' . $logo_cell . $title_cell . '</tr>
        </table>';
    },

    'field' => function (string $label, string $value) use ($fabricator_field_layout, $fabricator_title_fs, $fabricator_fs): string {
        $lbl_style = 'font-weight:bold;font-size:' . $fabricator_title_fs . 'pt;color:#222;';
        $val_style = 'font-size:' . $fabricator_fs . 'pt;color:#333;';
        // $value is already kses()'d by Generator.php before the marker spans/<img> were added; don't re-sanitize.
        if ($fabricator_field_layout === 'inline') {
            return '
        <div class="field-block">
            <span style="' . $lbl_style . '">' . esc_html($label) . ':</span>'
            . ' <span style="' . $val_style . '">' . $value . '</span>'
            . '<div class="field-separator-thick"></div>
        </div>';
        }
        return '
        <div class="field-block">
            <div style="' . $lbl_style . 'margin-bottom:4px;">' . esc_html($label) . '</div>
            <div class="field-separator-thin"></div>
            <div style="' . $val_style . 'margin-bottom:5px;">' . $value . '</div>
            <div class="field-separator-thick"></div>
        </div>';
    },

    'image' => function (string $varname): string {
        return '<div style="margin:8px 0;">'
            . '<img src="var:' . esc_attr($varname)
            . '" style="max-width:100%;max-height:300px;border:1px solid #ccc;padding:4px;" />'
            . '</div>';
    },

    'document_metadata' => function (array $data) use ($fabricator_fs): string {
        $metadata = $data['metadata'] ?? [];
        return '
        <div class="section-metadata">
            <strong>' . esc_html__('Metadata', 'formfabricator') . '</strong><br>
            ' . esc_html__('Created:', 'formfabricator') . ' ' . esc_html($metadata['generated'] ?? '') . '<br>
            ' . esc_html__('Form:', 'formfabricator') . ' ' . esc_html($metadata['form_name'] ?? '')
            . ' (ID: ' . esc_html((string) ($metadata['form_id'] ?? '')) . ')
        </div>';
    },

    'legal_notice' => function (): string {
        // Bound to a variable: the trailing-comment convention can't be used inside this concatenated string without printing into the PDF.
        // phpcs:ignore Generic.Files.LineLength -- single translatable msgid, see above.
        $legal_text = esc_html__('This document represents the original. Any change, manipulation, or modification invalidates this document. This document was issued in electronic form and must be kept exclusively in electronic form. Any printout is merely a copy and has no legal validity.', 'formfabricator');
        return '
        <p class="section-legal">
            <strong>' . esc_html__('Legal Notice:', 'formfabricator') . '</strong>
            ' . $legal_text . '
        </p>';
    },

    'footer' => function () use ($fabricator_o, $fabricator_sep): string {
        $text = $fabricator_o['footer_text'] ?? '';
        if (!$text) {
            return '';
        }
        $text = str_replace(
            ['{site_name}', '{site_url}', '{date}'],
            // The site's own date format (Settings > General), not a fixed d.m.Y that reads ambiguously outside the DACH region.
            [get_bloginfo('name'), get_bloginfo('url'), (string) wp_date((string) (get_option('date_format') ?: 'Y-m-d'))],
            $text
        );
        // Defense-in-depth: same trust level as the header title above, so apply the same wp_kses() allowlist.
        return wp_kses($text, FABRICATOR_PDF_HEADER_TITLE_ALLOWED_TAGS);
    },
];
