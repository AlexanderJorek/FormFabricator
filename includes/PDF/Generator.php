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
 * @version   1.0.7
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
     * Most different font names sealed as allowed. A document from this plugin uses a handful; the cap only matters for
     * names that uploaded image bytes happen to contain, which appear in no font resource the verifier checks.
     *
     * @var int
     */
    private const MAX_SEALED_FONTS = 256;

    /**
     * Generates a PDF from normalized submission data and returns its path.
     *
     * @param array  $mapped     Normalized field data from FieldRegistry::mapSubmission().
     * @param int    $form_id    The form identifier.
     * @param string $form_title Human-readable form title used in the PDF header.
     * @param bool   $seal       Embed the HMAC seal. False for layout previews: a preview signed with the
     *                           production key would verify as an authentic submission.
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

        $metadata = [
            // Site-local time plus its UTC offset: local keeps the PDF's "Created:" line readable, the offset removes the
            // hour that repeats at the daylight-saving changeover.
            'generated' => wp_date('Y-m-d H:i:s P'),
            'nonce'     => bin2hex(random_bytes(16)),
            'form_id'   => $form_id,
            'form_name' => $title,
        ];

        $full_data      = ['metadata' => $metadata, 'fields' => $mapped];
        $section_hidden = $layout['section_hidden'] ?? [];

        $fields_html = '';

        foreach ($mapped as $key => $field) {
            if (!isset($field['value'])) {
                continue;
            }

            // A crafted form-JSON import with a `]`/`<` in a field key could break the marker-parsing regex below.
            if (!ctype_alnum(str_replace(['_', '-'], '', (string) $key))) {
                \FabricatorForms\fabricator_log('FabricatorForms Generator: rejected suspicious field key: ' . $key);
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

            // Sanitize before wrapping with marker spans/<img> below — kses() after wrapping would strip those too.
            // pdfData() is a soft contract; fall back to [] / '' so a misbehaving field handler can't fatal (array_keys(null) is a TypeError).
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
            $cell_html = $start . $cell_html . $end;

            $fields_html .= ($pdf['labeled'] ?? true)
                // The label must be a string: layout.php's closure is typed, and this runs before the try below, so an array
                // label from a crafted import ended every PDF-attaching submission of that form in an uncaught TypeError.
                ? $layout['field'](is_string($field['label'] ?? null) ? $field['label'] : '', $cell_html)
                : '<div class="field-block">' . $cell_html . '</div>';
        }

        /* ---- Assemble HTML in fixed section order ---- */
        $html = '<base href="">';
        $html .= $layout['base_css']();

        if (!in_array('header', $section_hidden, true)) {
            $html .= $layout['header']($title);
        }
        if (!in_array('fields', $section_hidden, true) && !in_array('signatures', $section_hidden, true)) {
            $html .= $fields_html;
        }
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

            $upload_dir = wp_upload_dir();
            $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';

            // The transient alone would leave a directory unhardened for up to 24 hours after it is
            // removed or its guard files are deleted; is_dir() costs a cached stat per PDF.
            if (!get_transient('fabricator_pdf_dirs_ready') || !is_dir($safe_dir . '/pdf')) {
                // One shared implementation: this triad was duplicated here and three times in Verificationpage; SecureDir::harden() also routes writes through WP_Filesystem when direct.
                \FabricatorForms\Utils\SecureDir::harden(
                    $safe_dir,
                    array_map(static fn($sub) => $safe_dir . $sub, ['', '/pdf', '/embed', '/mpdf'])
                );
                set_transient('fabricator_pdf_dirs_ready', true, DAY_IN_SECONDS);
            }

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

            // Per-document page-number aliases (see PageAliasMpdf): random, so submitted text can never contain them.
            // The admin's footer text may use {PAGENO}/{nbpg}/{nb} (the layout editor previews them), mapped here.
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
            ];

            /* ---- PASS 1: font discovery ---- */
            $mpdf = new PageAliasMpdf($mpdf_config);
            self::configureMpdfInstance($mpdf, $grid_svg, $user_footer_text, $image_vars, $pageno_alias);

            self::writeHtmlChunked($mpdf, $html);

            // Random, not form name + minute: two submissions of one form in the same minute shared this path,
            // so each could overwrite or delete the other's PASS-1 file and seal the wrong hash sets.
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
                // Both walked forward rather than collected with preg_match_all(): an uploaded JPEG goes into this file
                // byte for byte, and a JPEG can carry any bytes in a comment, so a visitor could fill it with these
                // markers and make the match lists several times the upload's size. The font set is also held to what a
                // document can plausibly use; a real one names a handful, so nothing real is left out.
                $font_at = 0;
                while (preg_match('/\/BaseFont\s*\/([A-Za-z0-9\+\-_]+)/', $pdf_raw, $m, PREG_OFFSET_CAPTURE, $font_at) === 1) {
                    $font_at = $m[0][1] + strlen($m[0][0]);
                    if (count($fonts) < self::MAX_SEALED_FONTS) {
                        $fonts[preg_replace('/^[A-Z]{6}\+/', '', $m[1][0])] = true;
                    }
                }
                $expected_pages = 0;
                $page_at        = 0;
                while (preg_match('/\/Type\s*\/Page\b/', $pdf_raw, $pm, PREG_OFFSET_CAPTURE, $page_at) === 1) {
                    $expected_pages++;
                    $page_at = $pm[0][1] + strlen($pm[0][0]);
                }
                $content_hashes   = PdfUtils::hashPageContentStreams($pdf_raw, 2);
                $image_hashes     = self::hashImageXObjects($pdf_raw);
                $font_prog_hashes = PdfUtils::hashFontProgramStreams($pdf_raw);
                $all_stream_hashes = PdfUtils::hashAllCompressedStreams($pdf_raw);
                $seal_page_text    = PdfUtils::sealPageTextFingerprint($pdf_raw);
            }
            $fonts             = array_keys($fonts);
            $font_prog_hashes  = $font_prog_hashes  ?? [];
            $all_stream_hashes = $all_stream_hashes ?? [];
            $seal_page_text    = $seal_page_text    ?? '';
            wp_delete_file($sl_path);
            if (file_exists($sl_path)) {
                \FabricatorForms\fabricator_log('FabricatorForms Generator: failed to delete temp PDF: ' . $sl_path);
            }

            // All seal inputs come from PASS 1; compute now so the seal div can join a single writeHtmlChunked call.
            $pdf_meta = [
                'title'   => self::normalizeFieldValue($title),
                'author'  => self::normalizeFieldValue((string) get_bloginfo('name')),
                'creator' => 'FormFabricator',
            ];

            if ($seal) {
                // Unreadable PASS-1 output, or no content streams, would seal empty hash sets that the verifier
                // reports as "not recorded" rather than failing. A real mPDF document always has content streams.
                if ($pdf_raw === false || $content_hashes === []) {
                    throw new \RuntimeException('FabricatorForms Generator: PASS 1 yielded no readable content streams; refusing to seal.');
                }
                $seal_data = [
                    'generated'       => trim((string)$metadata['generated']),
                    'key_id'          => HashSeal::getCurrentKeyId(),
                    'nonce'           => (string)$metadata['nonce'],
                    'form_id'         => (int)$metadata['form_id'],
                    'form_name'       => self::normalizeFieldValue($metadata['form_name']),
                    'fields'          => self::buildSealFields($mapped),
                    'uploads'         => $sealed_uploads,
                    'template'        => $template,
                    'fonts'           => $fonts,
                    'expected_pages'  => $expected_pages,
                    'content_streams' => $content_hashes,
                    'image_hashes'      => $image_hashes,
                    'font_prog_hashes'  => $font_prog_hashes,
                    'all_stream_hashes' => $all_stream_hashes,
                    'pdf_meta'          => $pdf_meta,
                    // Key order is part of the HMAC input; Verificationpage::rebuildPayload() builds the same order.
                    'seal_page_text'    => $seal_page_text,
                ];

                $hash = HashSeal::generate($seal_data);
                $seal_data['seal'] = $hash;

                $seal_json = wp_json_encode($seal_data);
                if ($seal_json === false) {
                    throw new \RuntimeException(
                        'FabricatorForms Generator: JSON encode failed — ' . json_last_error_msg()
                    );
                }
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding so the seal JSON survives mPDF text rendering, not obfuscation.
                $seal_base64 = base64_encode($seal_json);

                $seal_div = '<div style="font-size:0.1px;line-height:0.1px;color:#000;">'
                    . '---BEGIN-SEAL---' . $seal_base64 . '---END-SEAL---'
                    . '</div>';

                $html .= $seal_div;
            }

            /* ---- PASS 2: final PDF with seal ---- */
            $mpdf = new PageAliasMpdf($mpdf_config);
            $mpdf->SetTitle($pdf_meta['title']);
            $mpdf->SetAuthor($pdf_meta['author']);
            $mpdf->SetCreator($pdf_meta['creator']);
            self::configureMpdfInstance($mpdf, $grid_svg, $user_footer_text, $image_vars, $pageno_alias);

            self::writeHtmlChunked($mpdf, $html);

            // Random filename: the old guessable naming could be served directly on servers that ignore .htaccess.
            $final_path = $pdf_dir . '/Entry_' . bin2hex(random_bytes(16)) . '.pdf';
            $mpdf->Output($final_path, \Mpdf\Output\Destination::FILE);

            return $final_path;
        } catch (MpdfException $e) {
            \FabricatorForms\fabricator_log('FabricatorForms Generator error: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            // Also catches HashSeal::generate()'s \RuntimeException — uncaught it would disclose a stack trace (CWE-209) under WP_DEBUG_DISPLAY.
            \FabricatorForms\fabricator_log('FabricatorForms Generator error: ' . $e->getMessage());
            return false;
        } finally {
            // Must restore on every exit path or the setting stays elevated for the rest of the PHP-FPM worker's life.
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
                if ($mtime !== false && ($now - $mtime) > self::SWEEP_MAX_AGE) {
                    wp_delete_file($file);
                    if (file_exists($file)) {
                        \FabricatorForms\fabricator_log("FabricatorForms Generator: sweep failed to remove stale temp PDF {$file}");
                    }
                }
            }
        }

        self::sweepMailSenderTmpDirs($now);
    }

    // Backstop for MailSender's shutdown-function cleanup, which never runs if PHP dies first (fatal/OOM/kill).
    private static function sweepMailSenderTmpDirs(int $now): void
    {
        // Both places MailSender::tempBaseDir() creates them: the system temp dir, and the protected PDF folder it uses when
        // WordPress's temp dir lies inside the site.
        $bases = array_unique([
            rtrim(get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR,
            wp_upload_dir()['basedir'] . '/fabricator-secure-pdf/mail/',
        ]);
        foreach ($bases as $base) {
            foreach ((glob($base . 'fabricator_*', GLOB_ONLYDIR) ?: []) as $dir) {
                $mtime = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => filemtime($dir));
                if ($mtime === false || ($now - $mtime) <= self::SWEEP_MAX_AGE) {
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
     * @return void
     */
    private static function configureMpdfInstance(
        PageAliasMpdf $mpdf,
        string $grid_svg,
        string $user_footer_text,
        array $image_vars,
        string $pageno_alias
    ): void {
        $mpdf->SetDefaultBodyCSS('background', "url('" . str_replace("'", "%27", $grid_svg) . "')");
        $mpdf->SetDefaultBodyCSS('background-repeat', 'repeat');
        $mpdf->SetDefaultBodyCSS('background-position', 'center center');
        $mpdf->setPagenoAlias($pageno_alias);
        $mpdf->SetHTMLFooter(self::footerHtml($user_footer_text, $pageno_alias, (string) $mpdf->aliasNbPgGp));
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
            // translators: %1$s: current page number placeholder, %2$s: total page count placeholder (both substituted by mPDF at render time).
            . sprintf(__('Page %1$s of %2$s', 'formfabricator'), $pageno_alias, $nbpg_alias)
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
        $cached = get_transient('fabricator_pdf_template_fingerprints');
        if (is_array($cached)) {
            return $cached;
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

        $raw = (array) \get_option('fabricator_forms_pdf_layout', []);

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

        set_transient('fabricator_pdf_template_fingerprints', $template, HOUR_IN_SECONDS);
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

        // Collect SMask object numbers so alpha-channel XObjects are skipped.
        // Walked forward into a set rather than collected with preg_match_all(), for the reason given where the fonts are
        // gathered: uploaded JPEG bytes sit in this file and can repeat the marker at will.
        $smask_nums = [];
        $smask_at   = 0;
        while (preg_match('/\/SMask\s+(\d+)\s+\d+\s+R/', $pdf_raw, $sm, PREG_OFFSET_CAPTURE, $smask_at) === 1) {
            $smask_at = $sm[0][1] + strlen($sm[0][0]);
            if (count($smask_nums) < PdfUtils::MAX_OBJECTS) {
                $smask_nums[$sm[1][0]] = true;
            }
        }

        $offset = 0;
        // Forward cursors (PdfUtils::nextAt()), as in the verifier's walk: this reads the plugin's own output, but uploaded
        // image bytes sit inside it and can hold any sequence, and a strpos() per XObject searched to the end of the file
        // whenever its needle was missing. The results are strpos()'s exactly, so the sealed hashes are unchanged.
        $walk_cache = [];
        while (($pos = strpos($pdf_raw, '/XObject', $offset)) !== false) {
            // A negative offset searches backwards from $pos in place; strrpos(substr()) copied the whole prefix for
            // every XObject, quadratic in file size. Same result, including 0 when there is no newline.
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


    /* hashAllCompressedStreams(), hashPageContentStreams() and isPageContentStream() moved to PdfUtils
       (hashAllCompressedStreams(), hashPageContentStreams(), looksLikeContentStream()), shared with the verifier so
       sealing and checking can never drift apart. */

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
     * Builds the normalized fields array used as seal input from mapped submission data.
     *
     * @param array $mapped Normalized field data from FieldRegistry::mapSubmission().
     * @return array Array of label/value pairs with normalized string values.
     */
    private static function buildSealFields(array $mapped): array
    {
        $fields = [];
        foreach ($mapped as $field) {
            $seal_handler = FieldRegistry::get($field['type'] ?? '');
            $fields[] = [
                'label' => (string)($field['label'] ?? ''),
                'value' => (
                    ($seal_handler === null || $seal_handler->includeValueInSeal())
                    && isset($field['value'])
                    && is_string($field['value'])
                ) ? self::normalizeFieldValue($field['value']) : '',
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
