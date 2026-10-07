<?php

/**
 * Generates PDF documents from form submissions using mPDF.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.8
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\PDF;

use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\HTMLParserMode;
use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Utils\HtmlSanitizer;

defined('ABSPATH') || exit;

class Generator
{
    /**
     * How the metadata block dates a PDF (wp_date()): site-local time plus UTC offset, readable and unambiguous at the
     * daylight-saving changeover. The layout editor's preview shows its sample date the same way.
     *
     * @var string
     */
    public const METADATA_DATE_FORMAT = 'Y-m-d H:i:s P';

    /**
     * Most different font names sealed as allowed. A document from this plugin uses a handful.
     *
     * @var int
     */
    private const MAX_SEALED_FONTS = 256;

    /**
     * Characters per line of the seal text. At the seal's 0.1 px a line this long is about 13 mm wide, so mPDF never
     * has to break one, and a line costs mPDF time in proportion to its length.
     *
     * @var int
     */
    private const SEAL_LINE_CHARS = 1000;

    /**
     * mPDF font families that draw what the chosen font lacks, tried in this order, each by the one file shipped
     * (fontConfig()): GNU FreeSerif for rarer letters and symbols, then Quivira for further symbols (technical, box
     * drawing, enclosed characters).
     *
     * @var array<string, string> Family => font file.
     */
    private const FALLBACK_FONTS = ['freeserif' => 'FreeSerif.ttf', 'quivira' => 'Quivira.otf'];

    /**
     * Drawn where the PDF can draw neither a character nor anything for it (drawableText()).
     *
     * @var string
     */
    private const MISSING_GLYPH = "\u{FFFD}";

    /**
     * Generates a PDF from normalized submission data and returns its path.
     *
     * @param array  $mapped     Normalized field data from FieldRegistry::mapSubmission().
     * @param int    $form_id    The form identifier.
     * @param string $form_title Human-readable form title used in the PDF header.
     * @param bool   $seal       Embed the HMAC seal. False for layout previews, which must never verify.
     * @return string|false Absolute path to the generated PDF, or false on failure.
     */
    // Renders twice (PASS 1/2 below): the seal is an HMAC of the content but must also be embedded in it.
    public static function generate(array $mapped, int $form_id, string $form_title = '', bool $seal = true): string|false
    {
        if (empty($mapped)) {
            \FabricatorForms\fabricator_log('FabricatorForms Generator: No data provided');
            return false;
        }

        $layout = include FABRICATOR_FORMS_PATH . 'includes/PDF/templates/layout.php';

        $image_vars     = [];
        $sealed_uploads = [];

        $title = $form_title !== '' ? $form_title : __('Form submission', 'formfabricator');

        // The values the PDF and the seal are both made from, as the PDF can draw them (drawableText()); the mail keeps
        // what was sent. Labels and the title only decide whether the fallback font is needed.
        $needs_fallback = false;
        $glyphs         = self::glyphTest(is_string($layout['font_family'] ?? null) ? $layout['font_family'] : 'dejavusans');
        if ($glyphs !== null) {
            foreach ($mapped as $key => $field) {
                if (is_string($field['value'] ?? null)) {
                    $mapped[$key]['value'] = self::drawableText($field['value'], $glyphs, $needs_fallback);
                }
                if (is_string($field['label'] ?? null)) {
                    self::drawableText($field['label'], $glyphs, $needs_fallback);
                }
            }
            self::drawableText($title, $glyphs, $needs_fallback);
        }

        $metadata = [
            'generated' => wp_date(self::METADATA_DATE_FORMAT),
            'nonce'     => bin2hex(random_bytes(16)),
            'form_id'   => $form_id,
            'form_name' => $title,
        ];

        $full_data      = ['metadata' => $metadata, 'fields' => $mapped];
        $section_hidden = $layout['section_hidden'] ?? [];

        $fields_html = '';
        $frame_open  = false;
        // Every answer is sealed, hidden ones too (hiding is not redacting), each marked as shown or not.
        $sealable    = [];
        $shown       = [];
        // The PDF Layout's "Form fields" and "Signatures & Uploads" toggles.
        $hide_fields = in_array('fields', $section_hidden, true);
        $hide_media  = in_array('signatures', $section_hidden, true);

        foreach ($mapped as $key => $field) {
            if (!isset($field['value'])) {
                continue;
            }

            // A crafted form-JSON import with a `]`/`<` in a field key could break the marker-parsing regex below.
            if (!ctype_alnum(str_replace(['_', '-'], '', (string) $key))) {
                \FabricatorForms\fabricator_log('FabricatorForms Generator: rejected suspicious field key: ' . $key);
                continue;
            }
            $sealable[$key] = $field;
            if ($hide_fields || ($hide_media && in_array($field['type'] ?? '', ['signature', 'upload'], true))) {
                continue;
            }
            $field_id = 'field_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $key);
            $handler  = FieldRegistry::get($field['type'] ?? '');
            $pdf      = $handler ? $handler->pdfData($field) : [
                'cell_html'         => esc_html((string)($field['value'] ?? '')),
                'labeled'           => true,
                'image_vars'        => [],
                'sealed_uploads'    => [],
                'trusted_rich_html' => false,
            ];

            // Sanitized before the marker spans and <img> are added, which kses would strip. Defaults guard a
            // misbehaving pdfData().
            $pdf_image_vars     = is_array($pdf['image_vars'] ?? null) ? $pdf['image_vars'] : [];
            $pdf_sealed_uploads = is_array($pdf['sealed_uploads'] ?? null) ? $pdf['sealed_uploads'] : [];

            $allowed_tags = ($pdf['trusted_rich_html'] ?? false)
                ? HtmlSanitizer::allowedTags()
                : FABRICATOR_PDF_ALLOWED_VALUE_TAGS;
            $cell_html = wp_kses((string)($pdf['cell_html'] ?? ''), $allowed_tags);
            foreach (array_keys($pdf_image_vars) as $var) {
                $cell_html .= $layout['image']($var);
            }

            $image_vars     = array_merge($image_vars, $pdf_image_vars);
            $sealed_uploads = array_merge($sealed_uploads, $pdf_sealed_uploads);

            $start     = '<span style="font-size:0.1px;line-height:0.1px;color:#000;position:absolute;">'
                . '[FABRICATOR_PDF_FIELD_' . esc_html($field_id) . ']</span>';
            $end       = '<span style="font-size:0.1px;line-height:0.1px;color:#000;position:absolute;">'
                . '[FABRICATOR_PDF_FIELD_END]</span>';
            // An empty cell (no markup at all) still carries the markers the verifier needs.
            $shows_nothing = trim($cell_html) === '';
            $cell_html     = $start . $cell_html . $end;

            // A box opens outside the field's markers; one still open is closed first, so boxes never nest.
            $frame = is_array($pdf['frame'] ?? null) ? $pdf['frame'] : null;
            if (($frame[0] ?? '') === 'open') {
                if ($frame_open) {
                    $fields_html .= $layout['frame_close']();
                }
                $fields_html .= $layout['frame_open'](is_string($frame[1] ?? null) ? $frame[1] : '');
                $frame_open   = true;
            }

            $fields_html .= ($pdf['labeled'] ?? true)
                // A string only: the closure is typed, and this runs outside the try below.
                ? $layout['field'](is_string($field['label'] ?? null) ? $field['label'] : '', $cell_html)
                : ($shows_nothing
                    // Takes no visible space, while the markers still reach the PDF's text.
                    ? '<div style="font-size:0.1px;line-height:0.1px;margin:0;padding:0;height:0;">' . $cell_html . '</div>'
                    : '<div class="field-block">' . $cell_html . '</div>');

            if (($frame[0] ?? '') === 'close' && $frame_open) {
                $fields_html .= $layout['frame_close']();
                $frame_open   = false;
            }
            $shown[$key] = true;
        }
        if ($frame_open) {
            $fields_html .= $layout['frame_close']();
        }

        /* ---- Assemble HTML in fixed section order ---- */
        $html = '<base href="">';
        $html .= $layout['base_css']();

        if (!in_array('header', $section_hidden, true)) {
            $html .= $layout['header']($title);
        }
        $html .= $fields_html;
        if (!in_array('metadata', $section_hidden, true)) {
            $html .= $layout['document_metadata']($full_data);
        }
        if (!in_array('legal', $section_hidden, true) && isset($layout['legal_notice'])) {
            $html .= $layout['legal_notice']();
        }
        /* footer rendered per-page via SetHTMLFooter — not inline */

        /* ---- Template image fingerprints ---- */
        $template = self::buildTemplateFingerprints();

        /* ---- mPDF setup ---- */
        try {
            // Raised only when below mPDF's need, and restored below only if raised here.
            $prev_backtrack   = (int)ini_get('pcre.backtrack_limit');
            $raised_backtrack = false;
            if ($prev_backtrack < 16 * 1024 * 1024) {
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.IniSet.Risky -- needed for mPDF's regex-heavy parsing on large forms; only raised when lower, restored in finally below.
                $raised_backtrack = ini_set('pcre.backtrack_limit', (string)(16 * 1024 * 1024)) !== false;
            }

            $safe_dir  = self::secureDir();
            $pdf_dir   = $safe_dir . '/pdf';
            $mpdf_temp = $safe_dir . '/mpdf';
            $grid_svg  = FABRICATOR_FORMS_PATH . 'includes/PDF/templates/construction-grid.svg';


            $margin_top    = (int) ($layout['margin_top_mm']    ?? 30);
            $margin_left   = (int) ($layout['margin_left_mm']   ?? 15);
            $margin_right  = (int) ($layout['margin_right_mm']  ?? 15);
            $margin_bottom = (int) ($layout['margin_bottom_mm'] ?? 15);

            /* The layout's footer callback returns plain text. */
            $user_footer_text = isset($layout['footer'])
                ? trim((string) $layout['footer']())
                : '';

            // Random per-document page aliases (PageAliasMpdf), which submitted text can't contain. The admin's footer
            // may use {PAGENO}/{nbpg}/{nb}.
            $alias_suffix     = bin2hex(random_bytes(6));
            $pageno_alias     = '{fabpageno' . $alias_suffix . '}';
            $nbpg_alias       = '{fabnbpg' . $alias_suffix . '}';
            $nb_alias         = '{fabnb' . $alias_suffix . '}';
            $user_footer_text = str_replace(['{PAGENO}', '{nbpg}', '{nb}'], [$pageno_alias, $nbpg_alias, $nb_alias], $user_footer_text);
            // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- hardcoded literal path, not request-influenced.
            require_once FABRICATOR_FORMS_PATH . 'includes/PDF/PageAliasMpdf.php';
            /* Reserve enough vertical space: ~5mm per line of user footer text. */
            $footer_lines  = $user_footer_text !== '' ? max(1, substr_count($user_footer_text, "\n") + 1) : 0;
            $footer_margin = $footer_lines > 0 ? 5 + ($footer_lines * 5) : 5;

            $mpdf_config = [
                'tempDir'       => $mpdf_temp,
                'margin_top'    => $margin_top,
                'margin_left'   => $margin_left,
                'margin_right'  => $margin_right,
                'margin_bottom' => $margin_bottom,
                'margin_header' => 3,
                'margin_footer' => $footer_margin,
                // Never subset: PASS 2's extra seal text would pull in glyphs PASS 1 didn't render, making fonts differ.
                'percentSubset' => 0,
                'aliasNbPg'     => $nb_alias,
                'aliasNbPgGp'   => $nbpg_alias,
                // /Producer then reads exactly "mPDF", which the seal records (pdf_meta), and no PDF names the library's
                // version for anyone looking for one with a known flaw.
                'exposeVersion' => false,
                // Only when the text needs it: checking every character against the fallback costs layout time, and a
                // document that never draws from it keeps the fonts it always had.
                'useSubstitutions' => $needs_fallback,
                'backupSubsFont'   => array_keys(self::FALLBACK_FONTS),
                'backupSIPFont'    => (string) array_key_first(self::FALLBACK_FONTS),
            ] + self::fontConfig();

            /* ---- PASS 1: font discovery ---- */
            $mpdf = new PageAliasMpdf($mpdf_config);
            self::configureMpdfInstance($mpdf, $grid_svg, $user_footer_text, $image_vars, $pageno_alias, !in_array('footer', $section_hidden, true));

            self::writeHtmlChunked($mpdf, $html);

            // Random, so concurrent submissions never share a PASS-1 file.
            $sl_path = $mpdf_temp . '/SL_' . bin2hex(random_bytes(16)) . '.pdf';
            $mpdf->Output($sl_path, \Mpdf\Output\Destination::FILE);
            unset($mpdf);

            $fonts           = [];
            $expected_pages  = 0;
            $content_hashes  = [];
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read of a path this request just wrote; wp_remote_get() is for remote URLs only.
            $pdf_raw         = file_get_contents($sl_path);

            $image_hashes = [];
            if ($pdf_raw !== false) {
                // Outside image data, or an uploaded JPEG's comment could seal a font the verifier then misses. Walked
                // forward, so repeated matches can't build large lists.
                $find_font = PdfUtils::finderOutsideImageData($pdf_raw, '/\/BaseFont\s*\/([A-Za-z0-9\+\-_]+)/');
                $font_at   = 0;
                while ($find_font($font_at, $m)) {
                    $font_at = $m[0][1] + strlen($m[0][0]);
                    if (count($fonts) < self::MAX_SEALED_FONTS) {
                        $fonts[preg_replace('/^[A-Z]{6}\+/', '', $m[1][0])] = true;
                    }
                }
                $expected_pages = PdfUtils::countPageObjects($pdf_raw);
                $content_hashes   = PdfUtils::hashPageContentStreams($pdf_raw, 2);
                $image_hashes     = self::hashImageXObjects($pdf_raw);
                $font_prog_hashes = PdfUtils::hashFontProgramStreams($pdf_raw);
                $all_stream_hashes = PdfUtils::hashAllCompressedStreams($pdf_raw);
                $seal_page_text    = PdfUtils::sealPageTextFingerprint($pdf_raw);
                // Links come only from the form author's HTML (an e-mail address becomes a mailto link); the verifier
                // accepts no other annotation.
                $links             = PdfUtils::linkTargets($pdf_raw);
            }
            $fonts             = array_keys($fonts);
            $font_prog_hashes  = $font_prog_hashes  ?? [];
            $all_stream_hashes = $all_stream_hashes ?? [];
            $seal_page_text    = $seal_page_text    ?? '';
            $links             = $links             ?? [];
            wp_delete_file($sl_path);
            if (file_exists($sl_path)) {
                \FabricatorForms\fabricator_log('FabricatorForms Generator: failed to delete temp PDF: ' . $sl_path);
            }

            // All seal inputs come from PASS 1; compute now so the seal div can join a single writeHtmlChunked call.
            $pdf_meta = [
                'title'   => self::normalizeFieldValue($title),
                'author'  => self::normalizeFieldValue((string) get_bloginfo('name')),
                'creator' => 'FormFabricator',
                // What mPDF writes as /Producer with exposeVersion off (mpdf_config above).
                'producer' => 'mPDF',
            ];

            if ($seal) {
                // Empty hash sets would verify as "not recorded"; a real mPDF document always has content streams.
                if ($pdf_raw === false || $content_hashes === []) {
                    throw new \RuntimeException('FabricatorForms Generator: PASS 1 yielded no readable content streams; refusing to seal.');
                }
                $seal_data = [
                    'generated'       => trim((string)$metadata['generated']),
                    'key_id'          => HashSeal::getCurrentKeyId(),
                    'nonce'           => (string)$metadata['nonce'],
                    'form_id'         => (int)$metadata['form_id'],
                    'form_name'       => self::normalizeFieldValue($metadata['form_name']),
                    'fields'          => self::buildSealFields($sealable, $shown),
                    // Records, not checks: the verifier compares embedded images through image_hashes.
                    'uploads'         => $sealed_uploads,
                    'template'        => $template,
                    'fonts'           => $fonts,
                    'expected_pages'  => $expected_pages,
                    'content_streams' => $content_hashes,
                    'image_hashes'      => $image_hashes,
                    'font_prog_hashes'  => $font_prog_hashes,
                    'all_stream_hashes' => $all_stream_hashes,
                    'pdf_meta'          => $pdf_meta,
                    'links'             => $links,
                    // Key order is part of the HMAC input; Verificationpage::rebuildPayload() builds the same order.
                    'seal_page_text'    => $seal_page_text,
                ];

                $hash = HashSeal::generate($seal_data);
                $seal_data['seal'] = $hash;

                // Transport only (the HMAC uses HashSeal::generate()'s own encoding); unescaped Unicode is smaller.
                $seal_json = wp_json_encode($seal_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($seal_json === false) {
                    \FabricatorForms\fabricator_log('FabricatorForms Generator: seal JSON encode failed — ' . json_last_error_msg());
                    throw new \RuntimeException('FabricatorForms Generator: seal JSON encode failed.');
                }
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding so the seal JSON survives mPDF text rendering, not obfuscation.
                $seal_base64 = base64_encode($seal_json);
                // Past what the verifier reads, the PDF could never be verified.
                if (strlen($seal_base64) > HashSeal::MAX_SEAL_BLOCK_BYTES) {
                    \FabricatorForms\fabricator_log('FabricatorForms Generator: seal of ' . strlen($seal_base64) . ' bytes exceeds HashSeal::MAX_SEAL_BLOCK_BYTES.');
                    throw new \RuntimeException('FabricatorForms Generator: seal too large.');
                }

                // Lines of SEAL_LINE_CHARS, since mPDF breaks an over-long word in quadratic time; the verifier joins them
                // without whitespace. No ligatures, which would read back "fi" as U+FB01.
                $seal_div = '<div style="font-size:0.1px;line-height:0.1px;color:#000;font-variant-ligatures:none;">'
                    . '---BEGIN-SEAL---' . implode('<br>', str_split($seal_base64, self::SEAL_LINE_CHARS)) . '---END-SEAL---'
                    . '</div>';

                $html .= $seal_div;
            }

            /* ---- PASS 2: final PDF with seal ---- */
            $mpdf = new PageAliasMpdf($mpdf_config);
            $mpdf->SetTitle($pdf_meta['title']);
            $mpdf->SetAuthor($pdf_meta['author']);
            $mpdf->SetCreator($pdf_meta['creator']);
            self::configureMpdfInstance($mpdf, $grid_svg, $user_footer_text, $image_vars, $pageno_alias, !in_array('footer', $section_hidden, true));

            self::writeHtmlChunked($mpdf, $html);

            // Random filename: a guessable one could be fetched directly on servers that ignore .htaccess.
            $final_path = $pdf_dir . '/Entry_' . bin2hex(random_bytes(16)) . '.pdf';
            $mpdf->Output($final_path, \Mpdf\Output\Destination::FILE);

            return $final_path;
        } catch (MpdfException $e) {
            \FabricatorForms\fabricator_log('FabricatorForms Generator error: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            // Also HashSeal's \RuntimeException, whose stack trace WP_DEBUG_DISPLAY would show.
            \FabricatorForms\fabricator_log('FabricatorForms Generator error: ' . $e->getMessage());
            return false;
        } finally {
            // On every exit path, or the setting stays raised for the rest of the worker's life.
            if (!empty($raised_backtrack) && isset($prev_backtrack)) {
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.IniSet.Risky -- restoring the setting raised above, must run regardless of exit path.
                ini_set('pcre.backtrack_limit', (string)$prev_backtrack);
            }
        }
    }

    /**
     * Max age (seconds) before the fallback sweep removes a stale temp PDF, e.g. after a crash mid-request.
     *
     * @var int
     */
    private const SWEEP_MAX_AGE = 3600;

    // WP-Cron callback (hourly): sweeps stale *.pdf files, leaving mPDF's own persistent cache files alone.
    public static function cronSweepTmpDirs(): void
    {
        self::sweepTmpDirs(self::SWEEP_MAX_AGE);
    }

    /**
     * Removes the temp PDFs and mail-attachment folders older than $max_age seconds. Not the cron callback itself,
     * which WordPress calls with an empty string.
     *
     * @param int $max_age Seconds since the last change; younger ones may belong to a request still running.
     * @return void
     */
    public static function sweepTmpDirs(int $max_age): void
    {
        $upload_dir = wp_upload_dir();
        $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';
        $now        = time();

        foreach (['/pdf', '/mpdf'] as $sub) {
            $dir = $safe_dir . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((glob($dir . '/*.pdf') ?: []) as $file) {
                if (!is_file($file)) {
                    continue;
                }
                // A concurrent request may delete the file between is_file() and here; false is then the right answer.
                $mtime = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => filemtime($file));
                if ($mtime !== false && ($now - $mtime) > $max_age) {
                    wp_delete_file($file);
                    if (file_exists($file)) {
                        \FabricatorForms\fabricator_log("FabricatorForms Generator: sweep failed to remove stale temp PDF {$file}");
                    }
                }
            }
        }

        self::sweepMailSenderTmpDirs($now, $max_age);
    }

    // Backstop for MailSender's shutdown-function cleanup, which never runs if PHP dies first (fatal/OOM/kill).
    private static function sweepMailSenderTmpDirs(int $now, int $max_age): void
    {
        // Both places MailSender::tempBaseDir() creates them.
        $bases = array_unique([
            rtrim(get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR,
            wp_upload_dir()['basedir'] . '/fabricator-secure-pdf/mail/',
        ]);
        foreach ($bases as $base) {
            foreach ((glob($base . 'fabricator_*', GLOB_ONLYDIR) ?: []) as $dir) {
                $mtime = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => filemtime($dir));
                if ($mtime === false || ($now - $mtime) <= $max_age) {
                    continue;
                }
                // Same routine as the request's own shutdown cleanup, including its per-file subdirectories.
                \FabricatorForms\Form\MailSender::removeTempTree($dir);
            }
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Applies shared body-background, footer, and image-vars config to both PASS 1 and PASS 2 mPDF instances.
     *
     * @param PageAliasMpdf $mpdf             The mPDF instance to configure.
     * @param string        $grid_svg         Absolute path to the background grid SVG.
     * @param string        $user_footer_text User-configured footer text (already trimmed, aliases mapped).
     * @param array         $image_vars       Image variable map for inline images.
     * @param string        $pageno_alias     This document's current-page alias.
     * @param bool          $show_footer      False when the PDF Layout hides the footer: no footer text, no page numbers.
     * @return void
     */
    private static function configureMpdfInstance(
        PageAliasMpdf $mpdf,
        string $grid_svg,
        string $user_footer_text,
        array $image_vars,
        string $pageno_alias,
        bool $show_footer = true
    ): void {
        $mpdf->SetDefaultBodyCSS('background', "url('" . str_replace("'", "%27", $grid_svg) . "')");
        $mpdf->SetDefaultBodyCSS('background-repeat', 'repeat');
        $mpdf->SetDefaultBodyCSS('background-position', 'center center');
        $mpdf->setPagenoAlias($pageno_alias);
        // The "Footer" section toggle of the PDF Layout editor.
        if ($show_footer) {
            $mpdf->SetHTMLFooter(self::footerHtml($user_footer_text, $pageno_alias, (string) $mpdf->aliasNbPgGp));
        }
        if (!empty($image_vars)) {
            $mpdf->imageVars = $image_vars;
        }
    }

    /**
     * Returns the mPDF HTML footer string with page number tokens.
     *
     * @param string $user_text    Admin footer text, already kses()'d, aliases mapped.
     * @param string $pageno_alias This document's current-page alias.
     * @param string $nbpg_alias   This document's page-total alias.
     * @return string HTML footer markup.
     */
    private static function footerHtml(string $user_text, string $pageno_alias, string $nbpg_alias): string
    {
        $pageno = '<span style="font-size:0.1px;line-height:0.1px;color:#fff;">'
            . '[FABRICATOR_PDF_PAGENO_START]</span>'
            // Translations are escaped too; the aliases are letters, digits and braces.
            // translators: %1$s: current page number placeholder, %2$s: total page count placeholder (both substituted by mPDF at render time).
            . sprintf(esc_html__('Page %1$s of %2$s', 'formfabricator'), $pageno_alias, $nbpg_alias)
            . '<span style="font-size:0.1px;line-height:0.1px;color:#fff;">'
            . '[FABRICATOR_PDF_PAGENO_END]</span>';

        $border = '';

        if ($user_text === '') {
            return '<div style="text-align:right;font-size:10pt;' . $border . '">'
                . $pageno . '</div>';
        }

        // $user_text is already kses()'d safe HTML from layout.php's footer(); re-escaping would defeat that allowlist.
        $safe_text = str_replace(["\r\n", "\r"], "\n", $user_text);
        $safe_text = str_replace("\n", '<br>', $safe_text);
        return '<table style="width:100%;border-collapse:collapse;' . $border . 'font-size:8pt;">'
            . '<tr>'
            . '<td style="text-align:left;color:#888;vertical-align:bottom;">' . $safe_text . '</td>'
            . '<td style="text-align:right;white-space:nowrap;font-size:10pt;'
            . 'vertical-align:bottom;">' . $pageno . '</td>'
            . '</tr>'
            . '</table>';
    }

    /**
     * Builds SHA-256 fingerprint records for all PDF template asset files.
     *
     * @return array Array of fingerprint entries, each with name, mime, and sha256 keys.
     */
    private static function buildTemplateFingerprints(): array
    {
        $raw = (array) \get_option('fabricator_forms_pdf_layout', []);
        // Keyed by the layout inputs, so a preview of an unsaved layout never seeds real submissions.
        $cache_key = hash('sha256', (string) wp_json_encode([$raw['logo_url'] ?? '', $raw['header_layout']['elements'] ?? []]));
        $cached    = get_transient('fabricator_pdf_template_fingerprints');
        if (is_array($cached) && ($cached['key'] ?? null) === $cache_key && is_array($cached['template'] ?? null)) {
            return $cached['template'];
        }

        // Null without PHP's fileinfo extension; PDF generation must not die on it (this runs before the try below).
        $finfo    = class_exists('finfo') ? new \finfo(FILEINFO_MIME_TYPE) : null;
        $seen     = [];
        $template = [];

        $fingerprint = function (string $path, string $name) use ($finfo, &$seen, &$template): void {
            $real = realpath($path);
            if ($real === false || !is_readable($real) || isset($seen[$real])) {
                return;
            }
            $seen[$real] = true;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read of a resolved image path; wp_remote_get() is for remote URLs only.
            $data = file_get_contents($real);
            if ($data === false) {
                return;
            }
            $mime = ($finfo !== null ? $finfo->file($real) : false) ?: (wp_check_filetype($real)['type'] ?: 'application/octet-stream');
            $th   = str_starts_with($mime, 'image/') ? PdfUtils::thumbnailHash($data) : null;
            $template[] = ['name' => $name, 'mime' => $mime, 'sha256' => $th ?? hash('sha256', $data)];
        };

        $fingerprint(FABRICATOR_FORMS_PATH . 'includes/PDF/templates/construction-grid.svg', 'construction-grid.svg');

        // Custom logo only — no fallback
        if (!empty($raw['logo_url'])) {
            $post_id   = \attachment_url_to_postid($raw['logo_url']);
            $logo_path = $post_id ? (\get_attached_file($post_id) ?: '') : '';
            if ($logo_path !== '') {
                $fingerprint($logo_path, basename($logo_path));
            }
        }

        // Header-builder image elements
        $elements = $raw['header_layout']['elements'] ?? [];
        foreach ($elements as $el) {
            if (($el['type'] ?? '') !== 'image' || empty($el['src'])) {
                continue;
            }
            $post_id  = \attachment_url_to_postid($el['src']);
            $img_path = $post_id ? (\get_attached_file($post_id) ?: '') : '';
            if ($img_path !== '') {
                $fingerprint($img_path, basename($img_path));
            }
        }

        set_transient('fabricator_pdf_template_fingerprints', ['key' => $cache_key, 'template' => $template], HOUR_IN_SECONDS);
        return $template;
    }

    /**
     * Hashes image XObject streams, skipping SMask alpha channels; matches Verificationpage's decoding so hashes agree.
     *
     * @param string $pdf_raw Raw PDF binary string.
     * @return array SHA-256 hashes of raw compressed image XObject streams.
     */
    private static function hashImageXObjects(string $pdf_raw): array
    {
        $hashes = [];

        // SMask object numbers, so alpha channels are skipped: outside image data, walked forward, as the verifier does.
        $smask_nums = [];
        $smask_at   = 0;
        $find_smask = PdfUtils::finderOutsideImageData($pdf_raw, '/\/SMask\s+(\d+)\s+\d+\s+R/');
        while ($find_smask($smask_at, $sm)) {
            $smask_at = $sm[0][1] + strlen($sm[0][0]);
            if (count($smask_nums) < PdfUtils::MAX_OBJECTS) {
                $smask_nums[$sm[1][0]] = true;
            }
        }

        $offset = 0;
        // Forward cursors (PdfUtils::nextAt()), as in the verifier: uploaded image bytes sit inside this file.
        $walk_cache = [];
        $find_xobject = PdfUtils::finderOutsideImageData($pdf_raw, '/\/XObject/');
        $xobject_from = 0;
        while ($find_xobject($xobject_from = max($offset, $xobject_from), $xobject_match)) {
            $pos          = $xobject_match[0][1];
            $xobject_from = $pos + 1;
            // A negative offset searches backwards in place, without copying the prefix.
            $line_start = $pos > 0 ? (strrpos($pdf_raw, "\n", $pos - strlen($pdf_raw) - 1) ?: 0) : 0;
            $obj_start  = $line_start + 1;
            $obj_end    = PdfUtils::nextAt($pdf_raw, 'endobj', $obj_start, $walk_cache);
            if ($obj_end === false) {
                $offset = $pos + 10;
                continue;
            }

            $full_obj = substr($pdf_raw, $obj_start, $obj_end + 6 - $obj_start);

            if (!str_contains($full_obj, '/Subtype /Image')) {
                $offset = $obj_end + 6;
                continue;
            }

            // Skip SMask XObjects (alpha channels).
            $look_back = substr($pdf_raw, max(0, $obj_start - 100), 100);
            if (preg_match('/(\d+)\s+\d+\s+obj\s*$/', $look_back, $nm) && isset($smask_nums[$nm[1]])) {
                $offset = $obj_end + 6;
                continue;
            }

            // Extract stream — use same boundary logic as Verificationpage.
            $sp  = PdfUtils::nextAt($pdf_raw, 'stream', $obj_start, $walk_cache);
            $esp = $sp !== false ? PdfUtils::nextAt($pdf_raw, 'endstream', $sp, $walk_cache) : false;
            if ($sp === false || $esp === false) {
                $offset = $obj_end + 6;
                continue;
            }

            $raw = ltrim(substr($pdf_raw, $sp + 6, $esp - ($sp + 6)), "\r\n");

            // Hash raw compressed bytes — identical between PASS 1 and PASS 2.
            $hashes[] = hash('sha256', $raw);
            $offset = $obj_end + 6;
        }

        return $hashes;
    }

    /**
     * Writes an HTML string to mPDF in chunks to avoid PCRE backtrack limit errors.
     *
     * @param Mpdf   $mpdf      The mPDF instance to write into.
     * @param string $html      Full HTML string to render.
     * @param int    $chunkSize Maximum byte size of each chunk.
     */
    private static function writeHtmlChunked(Mpdf $mpdf, string $html, int $chunkSize = 1500000): void
    {
        $html  = str_replace(["\r\n", "\r"], "\n", $html);
        $parts = preg_split('/(<\/[^>]+>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $buf   = '';
        $first = true;
        foreach ($parts as $part) {
            $buf .= $part;
            if (strlen($buf) >= $chunkSize) {
                $mpdf->WriteHTML($buf, $first ? HTMLParserMode::DEFAULT_MODE : HTMLParserMode::HTML_BODY);
                $buf = '';
                $first = false;
            }
        }
        if ($buf !== '') {
            $mpdf->WriteHTML($buf, $first ? HTMLParserMode::DEFAULT_MODE : HTMLParserMode::HTML_BODY);
        }
    }

    /**
     * The protected folder for PDFs and mPDF's temporary files, hardened when it is new or was removed.
     *
     * @return string Its path.
     */
    private static function secureDir(): string
    {
        $upload_dir = wp_upload_dir();
        $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';
        // is_dir() too, so a removed directory is re-hardened at once rather than when the transient expires.
        if (!get_transient('fabricator_pdf_dirs_ready') || !is_dir($safe_dir . '/pdf')) {
            \FabricatorForms\Utils\SecureDir::harden(
                $safe_dir,
                array_map(static fn($sub) => $safe_dir . $sub, ['', '/pdf', '/embed', '/mpdf'])
            );
            set_transient('fabricator_pdf_dirs_ready', true, DAY_IN_SECONDS);
        }
        return $safe_dir;
    }

    /**
     * mPDF's font settings, trimmed to the files the release build ships:
     *
     * - Each of FALLBACK_FONTS uses its regular file for every style (the others cover fewer characters, or don't exist).
     * - No Condensed DejaVu: generic names ("serif", "Arial") resolve to DejaVu Sans or Serif. Removed, not pointed at
     *   the regular files, since mPDF caches measurements by family name.
     *
     * @return array{fontdata: array, sans_fonts: string[], serif_fonts: string[]}
     */
    private static function fontConfig(): array
    {
        $defaults = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontdata = $defaults['fontdata'];
        foreach (self::FALLBACK_FONTS as $family => $file) {
            $fontdata[$family] = ['R' => $file, 'B' => $file, 'I' => $file, 'BI' => $file] + ($fontdata[$family] ?? []);
        }
        unset($fontdata['dejavusanscondensed'], $fontdata['dejavuserifcondensed']);
        $first = static fn(array $list, string $family): array => array_values(array_unique(array_merge(
            [$family],
            array_diff($list, ['dejavusanscondensed', 'dejavuserifcondensed'])
        )));
        return [
            'fontdata'    => $fontdata,
            'sans_fonts'  => $first($defaults['sans_fonts'], 'dejavusans'),
            'serif_fonts' => $first($defaults['serif_fonts'], 'dejavuserif'),
        ];
    }

    /**
     * Where the PDF can draw a code point: 1 in every style of $family (a value may be bold or italic), 2 only in
     * FALLBACK_FONTS, 0 nowhere.
     *
     * @param string $family The body's mPDF font family.
     * @return \Closure(int): int|null Null when the fonts can't be read; the text is then left as it is.
     */
    private static function glyphTest(string $family): ?\Closure
    {
        try {
            $mpdf  = new \Mpdf\Mpdf(['tempDir' => self::secureDir() . '/mpdf'] + self::fontConfig());
            $maps  = [];
            foreach (['', 'B', 'I', 'BI'] as $style) {
                $mpdf->SetFont($family, $style);
                $maps[] = (string) $mpdf->CurrentFont['cw'];
            }
            $fallbacks = [];
            foreach (array_keys(self::FALLBACK_FONTS) as $fallback_family) {
                $mpdf->SetFont($fallback_family, '');
                $fallbacks[] = (string) $mpdf->CurrentFont['cw'];
            }
        } catch (\Throwable $e) {
            \FabricatorForms\fabricator_log('FabricatorForms Generator: could not read the PDF fonts\' characters: ' . $e->getMessage());
            return null;
        }
        // mPDF's width table: two bytes per code point, zero for one the font lacks.
        $has = static fn(string $cw, int $c): bool => isset($cw[2 * $c + 1]) && ($cw[2 * $c] !== "\0" || $cw[2 * $c + 1] !== "\0");
        return static function (int $c) use ($maps, $fallbacks, $has): int {
            // Beyond the BMP, mPDF's text doesn't read back as its characters: treated as undrawable.
            if ($c > 0xFFFF) {
                return 0;
            }
            foreach ($maps as $cw) {
                if (!$has($cw, $c)) {
                    foreach ($fallbacks as $fallback) {
                        if ($has($fallback, $c)) {
                            return 2;
                        }
                    }
                    return 0;
                }
            }
            return 1;
        };
    }

    /**
     * $text as the PDF can draw it, so the PDF and the seal hold the same characters. An undrawable character becomes
     * its compatibility form when that can be drawn ("𝔏" as "L"), else MISSING_GLYPH, or nothing when it shows nothing
     * itself (a format character or combining mark). A numeric character reference counts as its character.
     *
     * @param string               $text           Text or HTML.
     * @param \Closure(int): int   $glyphs         glyphTest().
     * @param bool                 $needs_fallback Set when a character is drawn from FALLBACK_FONTS.
     * @return string
     */
    private static function drawableText(string $text, \Closure $glyphs, bool &$needs_fallback): string
    {
        if (!preg_match('/[^\x00-\x7F]|&#/', $text)) {
            return $text;
        }
        $replace = static function (int $cp, string $as) use ($glyphs, &$needs_fallback): string {
            $where = $glyphs($cp);
            if ($where === 2) {
                $needs_fallback = true;
            }
            if ($where !== 0) {
                return $as;
            }
            $char = mb_chr($cp, 'UTF-8');
            if (!is_string($char)) {
                return self::MISSING_GLYPH;
            }
            $plain = self::plainMathLetter($cp)
                ?? (class_exists('Normalizer') ? \Normalizer::normalize($char, \Normalizer::NFKC) : false);
            if (is_string($plain) && $plain !== $char && $plain !== '') {
                $codes = array_map(static fn(string $c): int => (int) mb_ord($c, 'UTF-8'), mb_str_split($plain, 1, 'UTF-8'));
                $where = array_map($glyphs, $codes);
                if (!in_array(0, $where, true)) {
                    $needs_fallback = $needs_fallback || in_array(2, $where, true);
                    return $plain;
                }
            }
            return preg_match('/^[\p{Cf}\p{M}]$/u', $char) === 1 ? '' : self::MISSING_GLYPH;
        };
        $out = preg_replace_callback(
            '/&#(?:[xX]([0-9a-fA-F]{1,6})|([0-9]{1,7}));/',
            static fn(array $m): string => $replace($m[1] !== '' ? (int) hexdec($m[1]) : (int) $m[2], $m[0]),
            $text
        );
        $out = $out === null ? null : preg_replace_callback(
            '/[^\x00-\x7F]/u',
            static fn(array $m): string => $replace((int) mb_ord($m[0], 'UTF-8'), $m[0]),
            $out
        );
        // Invalid UTF-8 fails under /u; such text is left as it was.
        return $out === null ? $text : self::separateEscapePairs($out);
    }

    /**
     * $text with an invisible COMBINING GRAPHEME JOINER (U+034F) between a character whose code ends in 0x5C and one
     * from U+2000–U+20FF or U+2800–U+29FF: "Ŝ€" as "Ŝ\u{034F}€", in the PDF and the seal alike.
     *
     * mPDF writes two bytes per character, so such a pair puts a backslash byte before a space, "(" or ")" byte, which
     * pdfparser unescapes twice, misreading the rest of the line. Other such byte values start CJK characters, which
     * drawableText() has already replaced.
     *
     * @param string $text Text or HTML.
     * @return string
     */
    private static function separateEscapePairs(string $text): string
    {
        static $pattern = null;
        if ($pattern === null) {
            $ends = '';
            for ($high = 0; $high <= 0xFF; $high++) {
                // U+D800–U+DFFF are surrogates, no characters: PCRE refuses them in a UTF-8 pattern.
                if ($high < 0xD8 || $high > 0xDF) {
                    $ends .= sprintf('\x{%04X}', ($high << 8) | 0x5C);
                }
            }
            $pattern = '/([' . $ends . '])(?=[\x{2000}-\x{20FF}\x{2800}-\x{29FF}])/u';
        }
        return preg_replace($pattern, "\$1\u{034F}", $text) ?? $text;
    }

    /**
     * The plain letter or digit for a Latin letter or digit of the Mathematical Alphanumeric Symbols block ("𝔏" is "L"),
     * without the intl extension Normalizer needs. The block holds 13 styles of A–Z a–z, Greek, then 5 styles of 0–9.
     *
     * @param int $cp Code point.
     * @return string|null Null outside those ranges.
     */
    private static function plainMathLetter(int $cp): ?string
    {
        if ($cp >= 0x1D400 && $cp < 0x1D400 + 13 * 52) {
            $index = ($cp - 0x1D400) % 52;
            return chr($index < 26 ? ord('A') + $index : ord('a') + $index - 26);
        }
        if ($cp >= 0x1D7CE && $cp <= 0x1D7FF) {
            return chr(ord('0') + ($cp - 0x1D7CE) % 10);
        }
        return null;
    }

    /**
     * Builds the normalized fields array used as seal input from mapped submission data.
     *
     * "shown" is false for a field the layout hides: the seal holds its answer, but it has no markers to pair with.
     *
     * @param array               $mapped Normalized field data from FieldRegistry::mapSubmission().
     * @param array<string, true> $shown  Keys of the entries the PDF shows.
     * @return array Array of label/value pairs with normalized string values.
     */
    private static function buildSealFields(array $mapped, array $shown): array
    {
        $fields = [];
        foreach ($mapped as $key => $field) {
            $seal_handler = FieldRegistry::get($field['type'] ?? '');
            $fields[] = [
                'label' => (string)($field['label'] ?? ''),
                'value' => (
                    ($seal_handler === null || $seal_handler->includeValueInSeal())
                    && isset($field['value'])
                    && is_string($field['value'])
                ) ? self::normalizeFieldValue($field['value']) : '',
                'shown' => isset($shown[$key]),
            ];
        }
        return $fields;
    }

    /**
     * Normalises a field value by decoding HTML entities, stripping tags, and collapsing whitespace.
     *
     * @param string $value Raw field value string.
     * @return string Normalised plain-text value.
     */
    private static function normalizeFieldValue(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = wp_strip_all_tags($value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
