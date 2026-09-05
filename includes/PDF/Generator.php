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
 * @version   1.0.5
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
use FabricatorForms\Fields\HtmlField;

defined('ABSPATH') || exit;

class Generator
{
    /**
     * Generates a PDF from normalized submission data and returns its path.
     *
     * @param array  $mapped     Normalized field data from FieldRegistry::mapSubmission().
     * @param int    $form_id    The form identifier.
     * @param string $form_title Human-readable form title used in the PDF header.
     * @return string|false Absolute path to the generated PDF, or false on failure.
     */
    // Renders twice (PASS 1/2 below): the seal is an HMAC of the content but must also be embedded in it.
    public static function generate(array $mapped, int $form_id, string $form_title = ''): string|false
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
            'generated' => current_time('mysql'),
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

            // Sanitize before wrapping with marker spans/<img> below, since kses() after wrapping would strip those too.
            // HtmlField's rawHtml(..., true) content already passed a wider kses pass upstream, so it gets that
            // same wider allowlist here instead of the narrow default, or rich markup would get stripped to text.
            // pdfData() is a soft contract; fall back to [] / '' rather than touching $pdf[...] directly so one
            // misbehaving third-party field handler can't fatal the whole submission (array_keys(null) is a TypeError).
            $pdf_image_vars     = is_array($pdf['image_vars'] ?? null) ? $pdf['image_vars'] : [];
            $pdf_sealed_uploads = is_array($pdf['sealed_uploads'] ?? null) ? $pdf['sealed_uploads'] : [];

            $allowed_tags = ($pdf['trusted_rich_html'] ?? false)
                ? HtmlField::trustedPdfAllowedTags()
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
                ? $layout['field']($field['label'] ?? '', $cell_html)
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
            $prev_backtrack = (int)ini_get('pcre.backtrack_limit');
            // Legitimate resource-limit raise required for mPDF's regex-heavy HTML parsing
            // on large forms; restored in the finally block below.
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- see comment above
            ini_set('pcre.backtrack_limit', (string)max($prev_backtrack, 16 * 1024 * 1024));

            $upload_dir = wp_upload_dir();
            $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';

            if (!get_transient('fabricator_pdf_dirs_ready')) {
                // A restrictive umask makes mkdir()/file_put_contents() create the
                // dir/file at 0750/0640 from the moment they exist, instead of at
                // the OS default (often 0755/0644) and only tightened by chmod()
                // afterward — closing the brief window where a local unprivileged
                // user could read submission PDFs before the chmod() call below runs.
                $prev_umask = umask(0027);
                try {
                    foreach (['', '/pdf', '/embed', '/mpdf'] as $sub) {
                        $dir = $safe_dir . $sub;
                        if (!is_dir($dir)) {
                            wp_mkdir_p($dir);
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- this directory setup can run on a front-end form-submission AJAX request (no WP admin context), where WP_Filesystem() may fall back to prompting for FTP/SSH credentials it cannot obtain; the umask(0027) set above already tightens the effective permissions from creation, this chmod is defense-in-depth on plugin-owned secure-storage dirs, not user-facing content.
                            chmod($dir, 0750);
                            file_put_contents($dir . '/index.php', '<?php // Silence is golden ?>');
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same front-end/no-credentials-prompt rationale as above; plugin-owned index.php silence file.
                            chmod($dir . '/index.php', 0640);
                        }
                    }
                    $htaccess = $safe_dir . '/.htaccess';
                    if (!file_exists($htaccess)) {
                        file_put_contents(
                            $htaccess,
                            "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                        );
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same front-end/no-credentials-prompt rationale as above; plugin-owned .htaccess lockdown file.
                        chmod($htaccess, 0640);
                    }
                } finally {
                    umask($prev_umask);
                }
                set_transient('fabricator_pdf_dirs_ready', true, DAY_IN_SECONDS);
            }

            $pdf_dir   = $safe_dir . '/pdf';
            $mpdf_temp = $safe_dir . '/mpdf';
            $grid_svg  = FABRICATOR_FORMS_PATH . 'includes/PDF/templates/construction-grid.svg';

            $form_name_clean = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $title), 0, 80);
            $date_time       = wp_date('D_d_m_Y_T_H_i');

            $margin_top    = (int) ($layout['margin_top_mm']    ?? 30);
            $margin_left   = (int) ($layout['margin_left_mm']   ?? 15);
            $margin_right  = (int) ($layout['margin_right_mm']  ?? 15);
            $margin_bottom = (int) ($layout['margin_bottom_mm'] ?? 15);

            /* Strip any HTML wrapper that older cached versions of layout.php
               may have returned (e.g. a <div style="border-top:...">). */
            $user_footer_text = isset($layout['footer'])
                ? trim((string) $layout['footer']())
                : '';
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
                // Always embed the complete font, never a subset: PASS 2's extra seal text can pull in glyphs
                // PASS 1 didn't render, so subsetting would make the two passes embed byte-different fonts.
                'percentSubset' => 0,
            ];

            /* ---- PASS 1: font discovery ---- */
            $mpdf = new Mpdf($mpdf_config);
            self::configureMpdfInstance($mpdf, $grid_svg, $user_footer_text, $image_vars);

            self::writeHtmlChunked($mpdf, $html);

            $sl_path = $mpdf_temp . "/SL_{$form_name_clean}_{$date_time}.pdf";
            $mpdf->Output($sl_path, \Mpdf\Output\Destination::FILE);
            unset($mpdf);

            $fonts           = [];
            $expected_pages  = 0;
            $content_hashes  = [];
            $pdf_raw         = file_get_contents($sl_path);

            $image_hashes = [];
            if ($pdf_raw !== false) {
                if (preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9\+\-_]+)/', $pdf_raw, $m)) {
                    foreach ($m[1] as $font) {
                        $fonts[preg_replace('/^[A-Z]{6}\+/', '', $font)] = true;
                    }
                }
                preg_match_all('/\/Type\s*\/Page\b/', $pdf_raw, $pm);
                $expected_pages  = count($pm[0]);
                $content_hashes   = self::hashPageContentStreams($pdf_raw);
                $image_hashes     = self::hashImageXObjects($pdf_raw);
                $font_prog_hashes = self::hashFontProgramStreams($pdf_raw);
                $all_stream_hashes = self::hashAllCompressedStreams($pdf_raw);
            }
            $fonts             = array_keys($fonts);
            $font_prog_hashes  = $font_prog_hashes  ?? [];
            $all_stream_hashes = $all_stream_hashes ?? [];
            wp_delete_file($sl_path);
            if (file_exists($sl_path)) {
                \FabricatorForms\fabricator_log('FabricatorForms Generator: failed to delete temp PDF: ' . $sl_path);
            }

            // All seal inputs come from PASS 1 — compute it now so the seal div
            // can be appended to $html and written in a single writeHtmlChunked call.
            $pdf_meta = [
                'title'   => self::normalizeFieldValue($title),
                'author'  => self::normalizeFieldValue((string) get_bloginfo('name')),
                'creator' => 'FormFabricator',
            ];

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
            ];

            $hash = HashSeal::generate($seal_data);
            $seal_data['seal'] = $hash;

            $seal_json = wp_json_encode($seal_data);
            if ($seal_json === false) {
                throw new \RuntimeException(
                    'FabricatorForms Generator: JSON encode failed — ' . json_last_error_msg()
                );
            }
            $seal_base64 = base64_encode($seal_json);

            $seal_div = '<div style="font-size:0.1px;line-height:0.1px;color:#000;">'
                . '---BEGIN-SEAL---' . $seal_base64 . '---END-SEAL---'
                . '</div>';

            $html .= $seal_div;

            /* ---- PASS 2: final PDF with seal ---- */
            $mpdf = new Mpdf($mpdf_config);
            $mpdf->SetTitle($pdf_meta['title']);
            $mpdf->SetAuthor($pdf_meta['author']);
            $mpdf->SetCreator($pdf_meta['creator']);
            self::configureMpdfInstance($mpdf, $grid_svg, $user_footer_text, $image_vars);

            self::writeHtmlChunked($mpdf, $html);

            $final_path = $pdf_dir . "/Entry_{$form_name_clean}_{$date_time}.pdf";
            $mpdf->Output($final_path, \Mpdf\Output\Destination::FILE);

            return $final_path;
        } catch (MpdfException $e) {
            \FabricatorForms\fabricator_log('FabricatorForms Generator error: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            // Broader safety net alongside the MpdfException catch above: this method's
            // signature promises string|false, but code inside the try block can also
            // throw \RuntimeException (HashSeal::generate()'s master-key/JSON-encode
            // failures) which MpdfException alone wouldn't catch. Left uncaught, that
            // exception propagates through MailSender::onSubmission() and
            // FormProcessor's do_action('fabricator_forms_submission', ...) — neither of
            // which wrap this call in a try/catch — turning a PDF/key-config problem
            // into an uncaught-exception fatal on the visitor's form submission, and a
            // stack-trace disclosure (CWE-209) on any site with WP_DEBUG_DISPLAY on.
            \FabricatorForms\fabricator_log('FabricatorForms Generator error: ' . $e->getMessage());
            return false;
        } finally {
            // Any exit path (including a \Throwable not caught above, e.g. from
            // HashSeal::generate()/wp_json_encode failure) must restore this
            // process-wide ini setting, or it stays elevated for the rest of
            // the PHP-FPM worker's lifetime.
            if (isset($prev_backtrack)) {
                // Restoring the process-wide ini setting raised above; must run regardless of exit path.
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- see comment above
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
                $mtime = @filemtime($file);
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

    // MailSender::materializeUploadAttachments() creates get_temp_dir() . 'fabricator_<uuid>/'
    // per submission (may contain PII, e.g. ID-document uploads) and normally deletes it via
    // register_shutdown_function() right after sending — but that never runs if PHP dies first
    // (fatal error, OOM, kill). Piggybacking on this same hourly sweep, rather than leaving those
    // directories to accumulate indefinitely, is what "no local data storage" actually requires
    // under crash conditions too.
    private static function sweepMailSenderTmpDirs(int $now): void
    {
        $sys_tmp = rtrim(get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
        foreach ((glob($sys_tmp . 'fabricator_*', GLOB_ONLYDIR) ?: []) as $dir) {
            $mtime = @filemtime($dir);
            if ($mtime === false || ($now - $mtime) <= self::SWEEP_MAX_AGE) {
                continue;
            }
            foreach ((glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*') ?: []) as $file) {
                if (is_file($file)) {
                    wp_delete_file($file);
                }
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP-Cron runs outside any request/admin context, so WP_Filesystem() direct-mode initialization cannot be relied on here; this is this plugin's own private temp directory, not user-facing WP_Filesystem-managed content (same rationale as MailSender's own shutdown-function cleanup).
            if (!@rmdir($dir)) {
                \FabricatorForms\fabricator_log("FabricatorForms Generator: sweep failed to remove stale temp dir {$dir}");
            }
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Applies shared body-background, footer, and image-vars config to both PASS 1 and PASS 2 mPDF instances.
     *
     * @param Mpdf   $mpdf             The mPDF instance to configure.
     * @param string $grid_svg         Absolute path to the background grid SVG.
     * @param string $user_footer_text User-configured footer text (already trimmed).
     * @param array  $image_vars       Image variable map for inline images.
     * @return void
     */
    private static function configureMpdfInstance(
        Mpdf $mpdf,
        string $grid_svg,
        string $user_footer_text,
        array $image_vars
    ): void {
        $mpdf->SetDefaultBodyCSS('background', "url('" . str_replace("'", "%27", $grid_svg) . "')");
        $mpdf->SetDefaultBodyCSS('background-repeat', 'repeat');
        $mpdf->SetDefaultBodyCSS('background-position', 'center center');
        $mpdf->SetHTMLFooter(self::footerHtml($user_footer_text));
        if (!empty($image_vars)) {
            $mpdf->imageVars = $image_vars;
        }
    }

    /**
     * Returns the mPDF HTML footer string with page number tokens.
     *
     * @return string HTML footer markup.
     */
    private static function footerHtml(string $user_text = ''): string
    {
        $pageno = '<span style="font-size:0.1px;line-height:0.1px;color:#fff;">'
            . '[FABRICATOR_PDF_PAGENO_START]</span>'
            // translators: %1$s: current page number placeholder, %2$s: total page count placeholder (both substituted by mPDF at render time).
            . sprintf(__('Page %1$s of %2$s', 'formfabricator'), '{PAGENO}', '{nbpg}')
            . '<span style="font-size:0.1px;line-height:0.1px;color:#fff;">'
            . '[FABRICATOR_PDF_PAGENO_END]</span>';

        $border = '';

        if ($user_text === '') {
            return '<div style="text-align:right;font-size:10pt;' . $border . '">'
                . $pageno . '</div>';
        }

        // $user_text already passed through wp_kses(FABRICATOR_PDF_HEADER_TITLE_ALLOWED_TAGS) in
        // layout.php's footer() closure — it is safe HTML, not plain text. Re-escaping
        // it here would turn already-permitted tags (<strong>, <em>, <span>, ...) into
        // visible literal text, silently defeating that allowlist.
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

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $seen     = [];
        $template = [];

        $fingerprint = function (string $path, string $name) use ($finfo, &$seen, &$template): void {
            $real = realpath($path);
            if ($real === false || !is_readable($real) || isset($seen[$real])) {
                return;
            }
            $seen[$real] = true;
            $data = file_get_contents($real);
            if ($data === false) {
                return;
            }
            $mime = $finfo->file($real) ?: 'application/octet-stream';
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
     * Hashes every decoded page content stream found in the raw PDF bytes.
     *
     * @param string $pdf_raw Raw PDF binary string.
     * @return array Sorted SHA-256 hashes of decoded page content streams.
     */
    private static function hashPageContentStreams(string $pdf_raw): array
    {
        $hashes = [];
        $offset = 0;
        while (true) {
            $pos  = strpos($pdf_raw, "stream\r\n", $offset);
            $pos2 = strpos($pdf_raw, "stream\n", $offset);
            if ($pos === false && $pos2 === false) {
                break;
            }
            if ($pos === false) {
                $pos = $pos2;
            } elseif ($pos2 !== false && $pos2 < $pos) {
                $pos = $pos2;
            }

            $eol = (substr($pdf_raw, $pos + 6, 2) === "\r\n") ? 2 : 1;
            $bs  = $pos + 6 + $eol;
            $be  = strpos($pdf_raw, 'endstream', $bs);
            if ($be === false) {
                $offset = $pos + 7;
                continue;
            }

            $body = substr($pdf_raw, $bs, $be - $bs);
            if (strlen($body) > 67108864) {
                $offset = $be + 9;
                continue;
            }
            $dec  = @gzuncompress($body) ?: @gzinflate(substr($body, 2));

            if ($dec !== false && strlen($dec) <= 67108864 && self::isPageContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
            $offset = $be + 9;
        }
        return $hashes;
    }

    /**
     * Hashes every image XObject stream in the PDF, skipping SMask alpha channels. Uses the same decoding
     * pipeline as Verificationpage so the hashes match exactly. Called on PASS-1 output; PASS-2 produces
     * byte-identical XObjects.
     *
     * @param string $pdf_raw Raw PDF binary string.
     * @return array SHA-256 hashes of raw compressed image XObject streams.
     */
    private static function hashImageXObjects(string $pdf_raw): array
    {
        $hashes = [];

        // Collect SMask object numbers so alpha-channel XObjects are skipped.
        $smask_nums = [];
        if (preg_match_all('/\/SMask\s+(\d+)\s+\d+\s+R/', $pdf_raw, $sm)) {
            $smask_nums = array_flip($sm[1]);
        }

        $offset = 0;
        while (($pos = strpos($pdf_raw, '/XObject', $offset)) !== false) {
            $line_start = strrpos(substr($pdf_raw, 0, $pos), "\n") ?: 0;
            $obj_start  = $line_start + 1;
            $obj_end    = strpos($pdf_raw, 'endobj', $obj_start);
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
            $sp  = strpos($pdf_raw, 'stream', $obj_start);
            $esp = $sp !== false ? strpos($pdf_raw, 'endstream', $sp) : false;
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
     * Hashes every non-content compressed stream in the PDF; content streams are excluded since they change between passes.
     *
     * @param string $pdf_raw Raw PDF binary string.
     * @return array Sorted SHA-256 hashes of all non-content compressed streams.
     */
    private static function hashAllCompressedStreams(string $pdf_raw): array
    {
        $hashes = [];
        $offset = 0;
        while (true) {
            $pos  = strpos($pdf_raw, "stream\r\n", $offset);
            $pos2 = strpos($pdf_raw, "stream\n", $offset);
            if ($pos === false && $pos2 === false) {
                break;
            }
            if ($pos === false) {
                $pos = $pos2;
            } elseif ($pos2 !== false && $pos2 < $pos) {
                $pos = $pos2;
            }
            $eol = (substr($pdf_raw, $pos + 6, 2) === "\r\n") ? 2 : 1;
            $bs  = $pos + 6 + $eol;
            $be  = strpos($pdf_raw, 'endstream', $bs);
            if ($be === false) {
                $offset = $bs;
                continue;
            }
            $body = substr($pdf_raw, $bs, $be - $bs);
            if (strlen($body) > 67108864) {
                $offset = $be + 9;
                continue;
            }
            $dec = @gzuncompress($body) ?: @gzinflate($body);
            if ($dec === false || strlen($dec) > 67108864) {
                $offset = $be + 9;
                continue;
            }
            // Skip page content streams; handled by content_streams and change between passes.
            if (!self::isPageContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
            $offset = $be + 9;
        }
        sort($hashes);
        return $hashes;
    }

    /**
     * Hashes every embedded font program stream (TrueType, CIDFontType2, Type1), sorted for order-independence.
     *
     * @param string $pdf_raw Raw PDF binary string.
     * @return array Sorted SHA-256 hashes of decoded font program streams.
     */
    private static function hashFontProgramStreams(string $pdf_raw): array
    {
        $hashes = [];
        // mPDF writes FontDescriptors as independent objects ("<N> 0 obj << /Type /FontDescriptor ... >>").
        // Find every such object body, then follow its /FontFile, /FontFile2, /FontFile3 reference.
        $pat_desc = '/\d+\s+\d+\s+obj\s*<<([\s\S]*?\/Type\s*\/FontDescriptor[\s\S]*?)>>\s*endobj/m';
        if (!preg_match_all($pat_desc, $pdf_raw, $descs, PREG_SET_ORDER)) {
            return $hashes;
        }

        $seen = [];
        foreach ($descs as $desc) {
            if (!preg_match('/\/FontFile[23]?\s+(\d+)\s+\d+\s+R/', $desc[1], $ref)) {
                continue;
            }
            $obj_num = (int) $ref[1];
            if (isset($seen[$obj_num])) {
                continue;
            }
            $seen[$obj_num] = true;

            $pat_obj = '/' . $obj_num . '\s+\d+\s+obj[\s\S]*?stream\r?\n([\s\S]*?)\r?\nendstream/m';
            if (!preg_match($pat_obj, $pdf_raw, $so)) {
                continue;
            }
            $body = $so[1];
            if (strlen($body) > 67108864) {
                continue;
            }
            $dec = @gzuncompress($body) ?: @gzinflate($body);
            if ($dec === false || strlen($dec) > 67108864) {
                $dec = $body;
            }
            $hashes[] = hash('sha256', $dec);
        }

        sort($hashes);
        return $hashes;
    }

    /**
     * Returns true when the decoded stream data looks like a PDF page content stream.
     *
     * @param string $decoded Decompressed stream data.
     * @return bool True if the stream appears to be a page content stream.
     */
    private static function isPageContentStream(string $decoded): bool
    {
        $head = substr($decoded, 0, 16);
        for ($i = 0; $i < strlen($head); $i++) {
            $b = ord($head[$i]);
            if ($b < 9 || ($b > 13 && $b < 32 && $b !== 27)) {
                return false;
            }
        }
        return (bool)preg_match('/\bBT\b|\bq\b|\bQ\b|\bcm\b|\bTf\b|\bTj\b|\bTd\b/', $decoded);
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
