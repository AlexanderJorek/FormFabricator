<?php

/**
 * MPDF HTML layout template for form submission PDF output.
 *
 * NOTE: This is rendered by mPDF, not the browser — it is not subject to WordPress's
 * default output-escaping/KSES filters or CSP. Field values echoed here ($value in the
 * 'field' closure) arrive already pre-escaped by each field type's own pdfData()/map()
 * handler (see includes/PDF/Generator.php); they additionally pass through a narrow
 * wp_kses() allowlist (FABRICATOR_PDF_ALLOWED_VALUE_TAGS, defined below) here as defense-in-depth.
 * in case a field renderer's own escaping is ever incomplete.
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
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

defined('ABSPATH') || exit;

$fabricator_defaults     = \FabricatorForms\Admin\PDFLayoutEditor::defaults();
$fabricator_raw          = (array) get_option('fabricator_forms_pdf_layout', []);
$fabricator_o             = array_merge($fabricator_defaults, $fabricator_raw);
$fabricator_field_layout = get_option('fabricator_forms_field_layout', 'block');

// Defense-in-depth: validate these are actually hex color values before they get
// interpolated into a <style> block / inline CSS `style="..."` attribute below.
// esc_attr() alone only protects the HTML-attribute context (quotes/entities) — it
// does not block CSS-syntax characters like `;`, `(`, `)`, so a value that reached
// here as anything other than a well-formed color (e.g. a stale/foreign option
// value bypassing PDFLayoutEditor::save()'s own sanitize_hex_color() call) could
// otherwise break out of the intended CSS property value.
$fabricator_hex_color_re = '/^#[0-9a-fA-F]{3,8}$/';
$fabricator_accent = preg_match($fabricator_hex_color_re, (string) $fabricator_o['accent_color'])
    ? $fabricator_o['accent_color'] : $fabricator_defaults['accent_color'];
$fabricator_sep = preg_match($fabricator_hex_color_re, (string) $fabricator_o['separator_color'])
    ? $fabricator_o['separator_color'] : $fabricator_defaults['separator_color'];
$fabricator_accent    = esc_attr($fabricator_accent);
$fabricator_sep       = esc_attr($fabricator_sep);
$fabricator_fs        = (int) $fabricator_o['font_size_body'];
$fabricator_title_fs  = (int) $fabricator_o['title_size'];
$fabricator_logo_w    = (int) $fabricator_o['logo_width'];
$fabricator_font      = match ($fabricator_o['font_family']) {
    'dejavuserif'    => 'dejavuserif',
    'dejavusansmono' => 'dejavusansmono',
    'freemono'       => 'freemono',
    default          => 'dejavusans',
};

// Fail closed, not open: if the stored logo_url doesn't resolve to a local
// media-library attachment (stale/deleted attachment, or an option value
// written by anything other than PDFLayoutEditor::save()'s sideload-on-save
// path), never fall back to fetching the raw URL — mPDF has no SSRF guard of
// its own and would fetch an external URL directly.
$fabricator_logo_post_id = !empty($fabricator_o['logo_url']) ? attachment_url_to_postid($fabricator_o['logo_url']) : 0;
$fabricator_logo_path    = $fabricator_logo_post_id ? (get_attached_file($fabricator_logo_post_id) ?: '') : '';

$fabricator_section_hidden = is_array($fabricator_o['section_hidden']) ? $fabricator_o['section_hidden'] : [];

$fabricator_margin_top_mm = (int) ($fabricator_o['margin_top'] ?? 15);

// Shared inline-formatting allowlist for admin-configured rich text (header
// "title" builder element content, and footer text) rendered into mPDF HTML.
// Both are run through wp_kses() with this allowlist before use so a
// careless/compromised admin-settings write can't inject arbitrary HTML/script
// via these option values.
//
// Defined via defined()-guarded define() rather than top-level `const`: this
// file is `include`d (not `include_once`) by Generator::generate(), which can
// run more than once per PHP process (e.g. multiple submissions handled in one
// request/CLI run) — a plain top-level `const` would fatal with "cannot
// redeclare constant" on the second inclusion.
if (!defined('FABRICATOR_PDF_HEADER_TITLE_ALLOWED_TAGS')) {
    define(
        'FABRICATOR_PDF_HEADER_TITLE_ALLOWED_TAGS',
        [
        'b'      => [],
        'strong' => [],
        'i'      => [],
        'em'     => [],
        'u'      => [],
        's'      => [],
        'del'    => [],
        'sup'    => [],
        'sub'    => [],
        'span'   => [],
        'br'     => [],
        ]
    );
}

// Defense-in-depth allowlist applied by Generator.php to each field's raw
// cell_html, before it wraps that value with the invisible marker spans and
// <img> tags that make up the rest of $cell_html — applying this allowlist any
// later would strip those trusted structural tags too. Field renderers are
// expected to already escape their own output (see the class docblock at the
// top of this file), but this narrow allowlist guards against any renderer
// that forgets, while still allowing the simple formatting (e.g. multi-line
// values using <br>) some renderers rely on.
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
    'margin_left_mm'   => (int) ($fabricator_o['margin_left']   ?? 15),
    'margin_right_mm'  => (int) ($fabricator_o['margin_right']  ?? 15),
    'margin_bottom_mm' => (int) ($fabricator_o['margin_bottom'] ?? 15),

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
        $fabricator_hex_color_re
    ): string {
        $hb = $fabricator_o['header_layout'] ?? [];
        $elements = $hb['elements'] ?? [];

        /* ── Grid-based header (header builder was used) ── */
        if (!empty($elements)) {
            $cols     = 42;
            $margin_l = (int) ($fabricator_o['margin_left']  ?? 15);
            $margin_r = (int) ($fabricator_o['margin_right'] ?? 15);
            $margin_t = (int) ($fabricator_o['margin_top']   ?? 15);
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
                    // Fail closed, not open: PDFLayoutEditor::save() only ever persists a
                    // 'src' that already resolved to a local media-library attachment (via
                    // resolveImageSrc()/media_sideload_image()'s SSRF-safe fetch). If this
                    // value doesn't resolve to a real local file — a stale/deleted
                    // attachment, or a header_layout option written by any other path —
                    // never fall back to the raw value, since mPDF has no SSRF guard of
                    // its own and would fetch an external URL directly.
                    $post_id  = attachment_url_to_postid($el['src']);
                    $img_path = $post_id ? (get_attached_file($post_id) ?: '') : '';
                    if ($img_path !== '') {
                        $out .= '<img src="' . esc_attr($img_path) . '" style="width:' . $el_w_mm . 'mm;height:auto;" />';
                    }
                } elseif ($type === 'title') {
                    $fs = max(6, (int) ($el['size'] ?? 18));
                    // Defense-in-depth: validate hex color format before it lands in an
                    // inline `style="..."` CSS property value — esc_attr() alone only
                    // protects the HTML-attribute context, not CSS syntax.
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
        // $value has already been run through FABRICATOR_PDF_ALLOWED_VALUE_TAGS by Generator.php,
        // before it was wrapped with the invisible marker spans / <img> tags this
        // closure now receives — sanitizing again here would strip those trusted tags.
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
        return '
        <p class="section-legal">
            <strong>' . esc_html__('Legal Notice:', 'formfabricator') . '</strong>
            ' . esc_html__('This document represents the original. Any change, manipulation, or modification invalidates this document. This document was issued in electronic form and must be kept exclusively in electronic form. Any printout is merely a copy and has no legal validity.', 'formfabricator') . '
        </p>';
    },

    'footer' => function () use ($fabricator_o, $fabricator_sep): string {
        $text = $fabricator_o['footer_text'] ?? '';
        if (!$text) {
            return '';
        }
        $text = str_replace(
            ['{site_name}', '{site_url}', '{date}'],
            [get_bloginfo('name'), get_bloginfo('url'), current_time('d.m.Y')],
            $text
        );
        // Defense-in-depth: footer_text is an admin-configured option value (same
        // trust level as the header "title" element content above), so apply the
        // same wp_kses() allowlist before it's returned unescaped into mPDF HTML.
        return wp_kses($text, FABRICATOR_PDF_HEADER_TITLE_ALLOWED_TAGS);
    },
];
