<?php

/**
 * AJAX handler for PDF hash-seal verification.
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
 */

namespace FabricatorForms\Admin;

defined('ABSPATH') || exit;

use Smalot\PdfParser\Parser;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\PDF\PdfUtils;

add_action(
    'wp_ajax_fabricator_verify_push_lines',
    function () {

        /* ---- Capability ---- */
        if (!\FabricatorForms\Plugin::userCan('use_verifier')) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — user ' . get_current_user_id() . ' lacks use_verifier capability.');
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        /* ---- Nonce ---- */
        check_ajax_referer('fabricator_verifier_nonce', 'nonce');

        /* ---- Raise limits for heavy PDF parsing (hard ceilings; handleUpload()'s soft budget aborts first) ----
           Only reached after the capability + nonce checks above, so an unauthorized/unverified
           request can't force these resource-limit changes on the server. */
        @ini_set('memory_limit', '3072M'); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- resource-limit raise for heavy PDF parsing.
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- resource-limit raise for heavy PDF parsing.
        @ini_set('pcre.backtrack_limit', '268435456');
        if (!ini_get('safe_mode')) {
            set_time_limit(1800); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- hard ceiling, should never be reached; see soft budget below.
        }

        /* ---- Rate limit: bounds self-DoS from repeated raised-limit requests ---- */
        $rl_key = 'verify_' . get_current_user_id();
        if (\FabricatorForms\Utils\RateLimiter::increment($rl_key, 5) > 1) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rate-limited user ' . get_current_user_id() . '.');
            wp_send_json_error(['message' => 'Please wait before verifying another PDF.'], 429);
        }

        /* ---- Global concurrency cap: at most 3 of this handler running at once, across everyone ---- */
        $fabricator_cs_bucket = 'verify';
        $fabricator_cs_token  = \FabricatorForms\Utils\ConcurrencySlot::acquire($fabricator_cs_bucket, 3, 900);
        if ($fabricator_cs_token === false) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — concurrency cap reached.');
            wp_send_json_error(
                [
                'message'     => 'Server busy verifying other PDFs right now.',
                'code'        => 'busy',
                'retry_after' => 8,
                ],
                429
            );
        }
        // Releases the slot on script end (covers wp_die() too); TTL is the rare-case backstop.
        register_shutdown_function(
            static function () use ($fabricator_cs_bucket, $fabricator_cs_token) {
                \FabricatorForms\Utils\ConcurrencySlot::release($fabricator_cs_bucket, $fabricator_cs_token);
            }
        );

        /* ---- Input ---- */
        $pdf_token   = sanitize_key($_POST['pdf_token'] ?? '');
        $visualLines = isset($_POST['visualLines'])
        ? json_decode(\FabricatorForms\Utils\Sanitize::str(sanitize_textarea_field(wp_unslash($_POST['visualLines'])), '[]'), true)
        : [];

        if (!$pdf_token) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — missing pdf_token (user ' . get_current_user_id() . ').');
            wp_send_json_error(['message' => 'Invalid input: missing token'], 400);
        }
        // Bounds worst-case comparison cost against a crafted payload while still allowing
        // large-but-legitimate multi-page PDFs sized up to MAX_PDF_BYTES.
        $visualLines = array_slice(
            array_values(
                array_filter(
                    is_array($visualLines) ? $visualLines : [],
                    'is_string'
                )
            ),
            0,
            50000
        );
        foreach ($visualLines as $i => $line) {
            if (strlen($line) > 20000) {
                $visualLines[$i] = substr($line, 0, 20000);
            }
        }

        /* ---- Resolve path from transient (avoids URL-to-path mapping) ---- */
        $pdf_transient = get_transient('fabricator_pdf_' . $pdf_token);
        if (!is_array($pdf_transient) || !isset($pdf_transient['path']) || !is_string($pdf_transient['path'])) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — token not found or expired (user ' . get_current_user_id() . ').');
            wp_send_json_error(['message' => 'PDF not found or token expired'], 404);
        }
        if ((int)($pdf_transient['uid'] ?? -1) !== get_current_user_id()) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — token owned by a different user than ' . get_current_user_id() . '.');
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $target_path = $pdf_transient['path'];

        $upload_dir   = wp_upload_dir();
        $safe_dir     = $upload_dir['basedir'] . '/fabricator-secure-pdf';
        $verfiles_dir = $safe_dir . '/verfiles';

        /* ---- Path-traversal guard ---- */
        $real_verfiles_dir = realpath($verfiles_dir);
        $real_target_path  = realpath($target_path);
        if (!$real_verfiles_dir
            || !$real_target_path
            || strpos($real_target_path, $real_verfiles_dir . DIRECTORY_SEPARATOR) !== 0
        ) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — path-traversal guard failed for token-resolved path.');
            wp_send_json_error(['message' => 'Invalid PDF path'], 400);
        }

        /* ---- MIME re-validation on the server-side path ---- */
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected_mime = $finfo->file($real_target_path);
        if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — stored file MIME re-check failed, detected "' . $detected_mime . '".');
            wp_send_json_error(['message' => 'File is not a valid PDF'], 400);
        }

        $file_size = filesize($real_target_path);
        if ($file_size > Verificationpage::MAX_PDF_BYTES) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms fabricator_verify_push_lines: rejected — stored file is '
                . round($file_size / 1048576, 1) . 'MB, exceeds MAX_PDF_BYTES ('
                . round(Verificationpage::MAX_PDF_BYTES / 1048576) . 'MB).'
            );
            wp_send_json_error(
                [
                'message' => 'PDF too large ('
                . round($file_size / 1048576, 1)
                . ' MB). Maximum for verification is '
                . round(Verificationpage::MAX_PDF_BYTES / 1048576)
                . ' MB.',
                ],
                400
            );
        }

        $file = [
        'name'     => preg_replace('/^[0-9a-f]{16}-/i', '', basename($real_target_path)),
        'tmp_name' => $real_target_path,
        'type'     => $detected_mime,
        'error'    => 0,
        'size'     => $file_size,
        ];

        /* ---- Capture output ---- */
        ob_start();
        try {
            Verificationpage::handleUpload($file, $visualLines, $pdf_token);
        } catch (\Throwable $ajax_err) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: handleUpload threw: ' . $ajax_err->getMessage());
            echo '<p style="color:red">' . esc_html__('Internal error while processing this PDF. See server log for details.', 'formfabricator') . '</p>';
        }
        $raw_html = ob_get_clean();

        if ($raw_html === false || $raw_html === '') {
            \FabricatorForms\fabricator_log(
                'FabricatorForms fabricator_verify_push_lines: raw_html is empty after handleUpload — ob level was '
                . ob_get_level()
            );
            wp_send_json_error(['message' => 'PDF processing produced no output. Check the PHP error log.'], 500);
            return;
        }

        /* ---- SANITIZE OUTPUT (critical) ---- */
        try {
            $safe_html = fabricator_sanitize_verifier_html($raw_html);
        } catch (\Throwable $san_err) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: fabricator_sanitize_verifier_html threw: ' . $san_err->getMessage());
            wp_send_json_error(['message' => 'Output sanitization failed. See server log for details.'], 500);
            return;
        }

        if ($safe_html === '') {
            \FabricatorForms\fabricator_log(
                'FabricatorForms fabricator_verify_push_lines: safe_html is empty after wp_kses (raw len='
                . strlen($raw_html) . ')'
            );
            // Fall back to escaping raw html if kses strips everything (e.g. encoding issue)
            $safe_html = '<p style="color:orange">Result was sanitized to empty. Check PHP error log.</p>';
        }

        delete_transient('fabricator_vp_' . $pdf_token);

        wp_send_json_success(
            [
            'lines_received' => count($visualLines),
            'pdf'            => basename($real_target_path),
            'html'           => $safe_html,
            ]
        );
    }
);

/* ---- Progress polling endpoint ---- */
add_action(
    'wp_ajax_fabricator_verify_progress',
    function () {
        // Capability-first, matching fabricator_verify_push_lines/fabricator_serve_pdf,
        // so this doesn't rely on nonce-then-capability ordering being
        // preserved if either check is edited independently later.
        if (!\FabricatorForms\Plugin::userCan('use_verifier')) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_progress: rejected — user ' . get_current_user_id() . ' lacks use_verifier capability.');
            wp_send_json_error([], 403);
        }
        check_ajax_referer('fabricator_verifier_nonce', 'nonce');
        $key  = sanitize_key($_POST['token'] ?? '');
        $data = $key ? get_transient('fabricator_vp_' . $key) : false;
        if ($data && (int)($data['uid'] ?? -1) !== get_current_user_id()) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_progress: rejected — progress token owned by a different user than ' . get_current_user_id() . '.');
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        if (is_array($data)) {
            unset($data['uid']);
        }
        wp_send_json_success($data ?: ['step' => '', 'pct' => 0]);
    }
);

/* ---- Authenticated PDF file-serving endpoint ---- */
add_action(
    'wp_ajax_fabricator_serve_pdf',
    function () {

        if (!\FabricatorForms\Plugin::userCan('use_verifier')) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — user ' . get_current_user_id() . ' lacks use_verifier capability.');
            wp_die('Forbidden', '', ['response' => 403]);
        }

        // Nonce/token are posted in the request body by verification.js (not query-string
        // params) so they don't end up in server logs, browser history, or a Referer header.
        if (!wp_verify_nonce(sanitize_key($_POST['nonce'] ?? ''), 'fabricator_verifier_nonce')) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — nonce verification failed (user ' . get_current_user_id() . ').');
            wp_die('Nonce verification failed', '', ['response' => 403]);
        }

        $token = sanitize_key($_POST['token'] ?? '');
        if (!$token) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — missing token (user ' . get_current_user_id() . ').');
            wp_die('Missing token', '', ['response' => 400]);
        }

        $pdf_transient = get_transient('fabricator_pdf_' . $token);
        $path = is_array($pdf_transient) ? ($pdf_transient['path'] ?? null) : null;
        if (!$path || !is_string($path) || !file_exists($path)) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — token not found, expired, or target file missing.');
            wp_die('PDF not found or token expired', '', ['response' => 404]);
        }
        if ((int)($pdf_transient['uid'] ?? -1) !== get_current_user_id()) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — token owned by a different user than ' . get_current_user_id() . '.');
            wp_die('Forbidden', '', ['response' => 403]);
        }

        // Extra path-safety check
        $upload_dir   = wp_upload_dir();
        $safe_dir     = realpath($upload_dir['basedir'] . '/fabricator-secure-pdf');
        $real_path    = realpath($path);
        if (!$safe_dir || !$real_path || strpos($real_path, $safe_dir . DIRECTORY_SEPARATOR) !== 0) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — path-traversal guard failed for token-resolved path.');
            wp_die('Invalid path', '', ['response' => 403]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected_mime = $finfo->file($real_path);
        if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
            \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — stored file MIME re-check failed, detected "' . $detected_mime . '".');
            wp_die('Not a PDF', '', ['response' => 400]);
        }

        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="verified.pdf"');
        header('Content-Length: ' . filesize($real_path));
        header('Cache-Control: no-store');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- deliberate streaming read: verified PDFs can be up to MAX_PDF_BYTES (500MB); get_contents() would buffer the whole file into memory instead of streaming it to the client.
        readfile($real_path);
        exit;
    }
);

/**
 * Sanitizes HTML output from the PDF verifier using wp_kses, with data-URI preservation.
 *
 * @param string $html Raw HTML to sanitize.
 * @return string Sanitized HTML.
 */
function fabricator_sanitize_verifier_html(string $html): string
{

    $allowed = [
        // Layout & containers
        'div'    => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
        'p'      => ['class' => true, 'style' => true, 'data-*' => true],
        'span'   => ['class' => true, 'style' => true, 'data-*' => true],
        'pre'    => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
        'code'   => ['class' => true, 'style' => true, 'data-*' => true],

        // Buttons / interactivity
        'button' => ['class' => true, 'type' => true, 'data-*' => true, 'style' => true],

        // Lists
        'ul' => [], 'ol' => [], 'li' => [],

        // Tables
        'table' => ['class' => true, 'style' => true],
        'thead' => [], 'tbody' => [],
        'tr' => ['class' => true, 'data-target' => true, 'title' => true],
        'th' => ['class' => true, 'scope' => true], 'td' => ['class' => true, 'colspan' => true, 'rowspan' => true],

        // Formatting
        'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'br' => [], 'hr' => [],

        // Images / SVG / media
        'img' => [
            'src' => true, 'alt' => true, 'class' => true, 'id' => true,
            'width' => true, 'height' => true, 'style' => true, 'data-*' => true,
        ],
        'svg' => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
        'canvas' => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
    ];

    // wp_kses uses regex internally and catastrophically fails on multi-MB strings
    // (e.g. base64-encoded image data URIs). Extract data URIs before sanitizing
    // and restore them afterwards — they are PHP-generated, not user-supplied.
    $data_uris = [];
    $html = preg_replace_callback(
        '/\bsrc=(["\'])data:[^"\']+\1/i',
        static function (array $m) use (&$data_uris): string {
            $key = '__FABRICATOR_DATA_URI_' . count($data_uris) . '__';
            $data_uris[$key] = $m[0];
            return 'src=' . $m[1] . $key . $m[1];
        },
        $html
    );

    $html = wp_kses($html, $allowed);

    // Restore data URIs — replace the placeholder src attributes verbatim.
    foreach ($data_uris as $key => $original) {
        $html = str_replace('src="' . $key . '"', $original, $html);
        $html = str_replace("src='" . $key . "'", $original, $html);
    }

    return $html;
}


/**
 * Admin page for uploading and verifying PDF seal signatures.
 */
final class Verificationpage
{
    /**
     * Registers the verification page menu and suppresses admin notices.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
        add_action(
            'in_admin_header',
            static function (): void {
                $screen = get_current_screen();
                if ($screen && $screen->id === 'fabricator-forms_page_fabricator-pdf-verification') {
                    remove_all_actions('admin_notices');
                    remove_all_actions('all_admin_notices');
                    remove_all_actions('user_admin_notices');
                    remove_all_actions('network_admin_notices');
                }
            }
        );
        add_action('fabricator_verifier_cleanup_files', [self::class, 'cronCleanupFiles']);

        // Fallback sweep: age-deletes anything older than SWEEP_MAX_AGE, in case a
        // per-file wp_schedule_single_event() cleanup never fires (WP-Cron isn't guaranteed).
        add_action('fabricator_verifier_sweep_tmp_dirs', [self::class, 'cronSweepTmpDirs']);
        if (!wp_next_scheduled('fabricator_verifier_sweep_tmp_dirs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'fabricator_verifier_sweep_tmp_dirs');
        }
    }

    /**
     * Lazily initializes and returns the WP_Filesystem API instance (admin-request contexts only).
     *
     * @return \WP_Filesystem_Base|null The filesystem instance, or null if initialization failed.
     */
    private static function getWpFilesystem(): ?object
    {
        global $wp_filesystem;
        if (!$wp_filesystem instanceof \WP_Filesystem_Base) {
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem();
        }
        return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
    }

    /**
     * Maximum age (seconds) a temp file may sit before the fallback sweep removes it.
     *
     * @var int
     */
    private const SWEEP_MAX_AGE = 3600;

    /**
     * Maximum accepted PDF size for verification, in bytes. 500MB gives headroom above a
     * default-config worst case (multiple upload fields, each admin-raisable past 10MB).
     *
     * @var int
     */
    public const MAX_PDF_BYTES = 500 * 1024 * 1024;

    // WP-Cron callback (hourly): sweeps temp directories for files older than SWEEP_MAX_AGE.
    public static function cronSweepTmpDirs(): void
    {
        $upload_dir = wp_upload_dir();
        $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';
        $now        = time();

        foreach (['/verfiles', '/verimages'] as $sub) {
            $dir = $safe_dir . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((glob($dir . '/*') ?: []) as $file) {
                if (!is_file($file) || basename($file) === 'index.php') {
                    continue;
                }
                $mtime = @filemtime($file);
                if ($mtime !== false && ($now - $mtime) > self::SWEEP_MAX_AGE) {
                    wp_delete_file($file);
                    if (file_exists($file)) {
                        \FabricatorForms\fabricator_log("FabricatorForms Verificationpage: sweep failed to remove stale temp file {$file}");
                    }
                }
            }
        }
    }

    /**
     * WP-Cron callback that deletes temp verifier files after a delay (avoids blocking a PHP-FPM worker).
     *
     * @param array<int, string> $files Absolute paths to delete.
     */
    public static function cronCleanupFiles(array $files): void
    {
        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }
            clearstatcache(true, $file);
            for ($i = 0; $i < 5; $i++) {
                if (!file_exists($file)) {
                    break;
                }
                wp_delete_file($file);
                clearstatcache(true, $file);
                if (!file_exists($file)) {
                    break;
                }
                usleep(200000);
                clearstatcache(true, $file);
            }
        }
    }

    /**
     * Appends fabricator-verification-page body class on the verification page.
     *
     * @param string $classes Existing admin body classes.
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        $page_slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($page_slug === 'fabricator-pdf-verification') {
            $classes .= ' fabricator-verification-page';
        }
        return $classes;
    }

    /**
     * Registers the PDF Verification submenu page.
     *
     * @return void
     */
    public static function menu(): void
    {
        if (\FabricatorForms\Plugin::userCan('use_verifier')) {
            add_submenu_page(
                'fabricator-forms',
                __('FormFabricator Verification', 'formfabricator'),
                __('PDF Verification', 'formfabricator'),
                'read',
                'fabricator-pdf-verification',
                [self::class, 'render']
            );
        }
    }

    /**
     * Renders the PDF upload and verification results page.
     *
     * @return void
     */
    public static function render(): void
    {
        // This handles file uploads to disk, so it re-checks explicitly rather than relying solely on admin_menu's submenu registration.
        if (!\FabricatorForms\Plugin::userCan('use_verifier')) {
            \FabricatorForms\fabricator_log('FabricatorForms Verificationpage::render: rejected — user ' . get_current_user_id() . ' lacks use_verifier capability.');
            wp_die(esc_html__('Insufficient permissions.', 'formfabricator'), '', ['response' => 403]);
        }
        echo '<canvas id="fabricator-particle-canvas" aria-hidden="true"></canvas>';
        echo '<div class="wrap fabricator-verification-wrap">';
        echo '<div id="fabricator-verification-body">';

        // --- Handle POST uploads securely ---
        $is_request_post = strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET'))) === 'POST';
        if ($is_request_post) {
            // Nonce is the unconditional first gate — before touching any $_FILES.
            if (!isset($_POST['fabricator_verifier_nonce'])
                || !check_admin_referer('fabricator_verifier_upload', 'fabricator_verifier_nonce')
            ) {
                \FabricatorForms\fabricator_log('FabricatorForms Verificationpage::render: rejected — nonce verification failed (user ' . get_current_user_id() . ').');
                wp_die('Security check failed', 'Error', ['response' => 403]);
            }
        }
        // Collected during the upload loop below, then localized once (not echoed per-file as
        // inline <script> tags) — see the wp_localize_script() call after the loop.
        $verification_queue = [];
        if ($is_request_post && !empty($_FILES['pdfs']['name'][0])) {
            // Process uploaded files
            $upload_dir = wp_upload_dir();
            $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';

            // Ensure directories exist with restricted permissions.
            $wp_filesystem = self::getWpFilesystem();

            foreach (['', '/verfiles', '/log'] as $sub) {
                $dir = $safe_dir . $sub;
                if (!is_dir($dir)) {
                    wp_mkdir_p($dir);
                    if ($wp_filesystem) {
                        $wp_filesystem->chmod($dir, 0750);
                    }
                    file_put_contents($dir . '/index.php', "<?php // Silence is golden ?>");
                    if ($wp_filesystem) {
                        $wp_filesystem->chmod($dir . '/index.php', 0640);
                    }
                }
            }

            // Block all direct HTTP access — .htaccess is the last line of defence.
            $htaccess = $safe_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents(
                    $htaccess,
                    "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                );
                if ($wp_filesystem) {
                    $wp_filesystem->chmod($htaccess, 0640);
                }
            }

            $verfiles_dir = $safe_dir . '/verfiles';

            $max_upload_bytes = Verificationpage::MAX_PDF_BYTES;

            $uploaded_tmp_names = isset($_FILES['pdfs']['tmp_name']) && is_array($_FILES['pdfs']['tmp_name'])
                ? array_map('sanitize_text_field', wp_unslash($_FILES['pdfs']['tmp_name']))
                : [];

            // Mirror UploadField's client-hint logic (includes/Fields/UploadField.php) — cap
            // to the server's actual max_file_uploads ini limit rather than an arbitrary number.
            $max_files = max(1, (int)(ini_get('max_file_uploads') ?: 20));
            if (count($uploaded_tmp_names) > $max_files) {
                \FabricatorForms\fabricator_log(
                    'FabricatorForms Verificationpage::render: batch upload truncated — '
                    . count($uploaded_tmp_names) . ' files submitted, max_file_uploads limit is ' . $max_files . '.'
                );
                echo wp_kses_post(
                    self::noticeHtml(
                        // translators: %d: maximum number of files accepted per upload.
                        sprintf(esc_html__('Too many files selected. Only the first %d will be processed.', 'formfabricator'), $max_files),
                        'warning'
                    )
                );
                $uploaded_tmp_names = array_slice($uploaded_tmp_names, 0, $max_files, true);
            }

            foreach ($uploaded_tmp_names as $key => $tmpName) {
                $original_name = isset($_FILES['pdfs']['name'][$key])
                    ? sanitize_file_name(wp_unslash($_FILES['pdfs']['name'][$key]))
                    : '(unknown)';

                if (!is_readable($tmpName) || !is_uploaded_file($tmpName)) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::render: upload skipped for "' . $original_name
                        . '" — failed is_uploaded_file()/is_readable() check (possible spoofed or malformed multipart entry).'
                    );
                    echo wp_kses_post(
                        self::noticeHtml(
                            // translators: %s: uploaded file name.
                            sprintf(esc_html__('Upload skipped: "%s" could not be read from the upload.', 'formfabricator'), esc_html($original_name)),
                            'warning'
                        )
                    );
                    continue;
                }

                // File size guard — use the actual file on disk, not the browser-reported size.
                if (filesize($tmpName) > $max_upload_bytes) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::render: upload skipped for "' . $original_name . '" — '
                        . round(filesize($tmpName) / 1048576, 1) . 'MB exceeds ' . round($max_upload_bytes / 1048576) . 'MB limit.'
                    );
                    echo wp_kses_post(
                        self::noticeHtml(
                            // translators: %d: maximum accepted file size in MB.
                            sprintf(esc_html__('Upload skipped: file exceeds %d MB limit.', 'formfabricator'), (int) round($max_upload_bytes / 1048576)),
                            'warning'
                        )
                    );
                    continue;
                }

                $type_check = wp_check_filetype_and_ext(
                    $tmpName,
                    $original_name,
                    ['pdf' => 'application/pdf']
                );

                if (($type_check['ext'] ?? '') !== 'pdf') {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::render: upload skipped for "' . $original_name
                        . '" — wp_check_filetype_and_ext() did not resolve to pdf (ext: '
                        . ($type_check['ext'] ?? '(none)') . ', type: ' . ($type_check['type'] ?? '(none)') . ').'
                    );
                    echo wp_kses_post(self::noticeHtml(__('Upload skipped: only PDF files are allowed.', 'formfabricator'), 'warning'));
                    continue;
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detected_mime = $finfo->file($tmpName);
                if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::render: upload skipped for "' . $original_name
                        . '" — finfo MIME re-check detected "' . $detected_mime . '" instead of application/pdf.'
                    );
                    echo wp_kses_post(self::noticeHtml(__('Upload skipped: MIME validation failed.', 'formfabricator'), 'warning'));
                    continue;
                }

                $safe_name = sanitize_file_name($original_name);

                if ($safe_name === '' || !preg_match('/\.pdf$/i', $safe_name)) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::render: upload skipped — filename sanitized to "'
                        . $safe_name . '" from original "' . $original_name . '", not a valid .pdf name.'
                    );
                    echo wp_kses_post(self::noticeHtml(__('Upload skipped: invalid PDF filename.', 'formfabricator'), 'warning'));
                    continue;
                }

                // Always use a unique random prefix — never rely on time() for collision avoidance.
                $storage_name = bin2hex(random_bytes(8)) . '-' . $safe_name;
                $target_path  = $verfiles_dir . '/' . $storage_name;

                // is_uploaded_file() + copy() + delete reproduces move_uploaded_file()'s
                // validate-then-move behavior without calling the forbidden function itself.
                $moved = is_uploaded_file($tmpName) && copy($tmpName, $target_path);
                if ($moved) {
                    wp_delete_file($tmpName);
                    self::scheduleDeletion($target_path);

                    // Short-lived transient token; TTL must outlive the 1800s parse hard ceiling.
                    $token = bin2hex(random_bytes(16));
                    set_transient(
                        'fabricator_pdf_' . $token,
                        ['path' => $target_path, 'uid' => get_current_user_id()],
                        2100
                    ); // 35 minutes

                    // nonce/token travel in the POST body (see verification.js), not as query
                    // params — a GET URL with these as query args would land in server logs,
                    // browser history, and any Referer header sent from the resulting page.
                    $verification_queue[] = [
                        'url'   => esc_url_raw(admin_url('admin-ajax.php')),
                        'action' => 'fabricator_serve_pdf',
                        'nonce' => wp_create_nonce('fabricator_verifier_nonce'),
                        'token' => $token,
                        'name'  => $safe_name,
                    ];
                }
            }
        }

        // Localized once for the whole batch; verification.js reads this on load to seed its queue.
        wp_localize_script('fabricator-verifier-data', 'FabricatorVerifierQueueData', $verification_queue);

        // --- Render drag-and-drop form with nonce ---
        // Verification page styles: assets/css/admin-verification.css (enqueued in Utils/Assets.php).

        echo '<form id="pdf-upload-form" method="post" enctype="multipart/form-data">';
        wp_nonce_field('fabricator_verifier_upload', 'fabricator_verifier_nonce');
        $idle_style   = $is_request_post ? ' style="' . esc_attr('display:none') . '"' : '';
        $scanmore_cls = $is_request_post ? ' class="' . esc_attr('fabricator-pdf-visible') . '"' : '';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr()/esc_html()'d above and inline.
        echo '
        <div id="fabricator-pdf-idle-state"' . $idle_style . '>
            <div class="fabricator-pdf-idle-card">
                <h2>' . esc_html__('PDF Verification', 'formfabricator') . '</h2>
                <p>' . esc_html__('Upload one or more generated PDFs to verify their embedded seal and check for tampering.', 'formfabricator') . '</p>
                <div id="drop-zone">
                    ' . esc_html__('Drag & drop PDF files here', 'formfabricator') . '<br>
                    <small style="opacity:.75">' . esc_html__('or click to select', 'formfabricator') . '</small>
                </div>
                <input type="file" name="pdfs[]" id="pdf-input"
                    accept="application/pdf" multiple style="display:none;">
                <ul id="fabricator-pdf-file-queue"></ul>
                <button id="fabricator-pdf-verify-btn" class="button button-primary"
                    type="submit" style="width:100%;justify-content:center;" disabled>' . esc_html__('Verify PDFs', 'formfabricator') . '</button>
            </div>
        </div>
        </form>

        <div id="fabricator-pdf-scan-more-backdrop">
            <div class="fabricator-pdf-idle-card">
                <button id="fabricator-pdf-scan-more-close" title="' . esc_attr__('Close', 'formfabricator') . '">&times;</button>
                <h2>' . esc_html__('Scan more PDFs', 'formfabricator') . '</h2>
                <p>' . esc_html__('Add more PDFs to verify.', 'formfabricator') . '</p>
                <div id="drop-zone-more">
                    ' . esc_html__('Drag & drop PDF files here', 'formfabricator') . '<br>
                    <small style="opacity:.75">' . esc_html__('or click to select', 'formfabricator') . '</small>
                </div>
                <input type="file" id="pdf-input-more" accept="application/pdf" multiple style="display:none;">
                <ul id="fabricator-pdf-file-queue-more"></ul>
                <button id="fabricator-pdf-verify-more-btn" class="button button-primary"
                    style="width:100%;justify-content:center;" disabled>' . esc_html__('Verify PDFs', 'formfabricator') . '</button>
            </div>
        </div>

        <button id="fabricator-pdf-scan-more-btn" type="button"' . $scanmore_cls . '>+ ' . esc_html__('Scan more PDFs', 'formfabricator') . '</button>

        <div id="fabricator-pdf-upload-overlay">
            <div class="fabricator-pdf-idle-card">
                <div id="fabricator-pdf-upload-spinner"></div>
                <h2>' . esc_html__('Uploading…', 'formfabricator') . '</h2>
                <p>' . esc_html__('Please keep this page open — this can take a while for large files.', 'formfabricator') . '</p>
            </div>
        </div>

        <div id="fabricator-pdf-verification-results"></div>
        ';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        // Drag & drop, file queue, and image-slot JS lives in assets/js/admin-verification.js.
    }

    private static array $image_slots = [];
    private static array $files_to_delete = [];
    private static array $pdfs_to_delete = [];
    private static bool $image_cleanup_registered = false;
    private static bool $pdf_cleanup_registered = false;
    private static string $progressKey = '';

    /**
     * Stores upload verification progress in a transient.
     *
     * @param string $step Current progress step label.
     * @param int    $pct  Progress percentage (0-100).
     */
    private static function setProgress(string $step, int $pct): void
    {
        if (self::$progressKey === '') {
            return;
        }
        set_transient(
            'fabricator_vp_' . self::$progressKey,
            ['step' => $step, 'pct' => $pct, 'uid' => get_current_user_id()],
            120
        );
    }

    /**
     * Wall-clock checkpoint between the heavy regex passes in handleUpload(); throws once over budget.
     *
     * @param float  $startTime  Result of microtime(true) captured at parse start.
     * @param float  $maxSeconds Maximum seconds allowed before aborting.
     * @param string $passLabel  Label of the pass that just completed (for the log).
     * @throws \RuntimeException When the elapsed time exceeds $maxSeconds.
     */
    private static function checkParseTimeBudget(float $startTime, float $maxSeconds, string $passLabel): void
    {
        $elapsed = microtime(true) - $startTime;
        if ($elapsed <= $maxSeconds) {
            return;
        }
        \FabricatorForms\fabricator_log(
            'FabricatorForms handleUpload: aborting after [' . $passLabel . '] pass — '
            . round($elapsed, 1) . 's elapsed (limit ' . $maxSeconds . 's)'
        );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never echoed directly; matched via str_contains() against fixed literals before echoing a mapped friendly message.
        throw new \RuntimeException('Parsing timed out after ' . $passLabel . '.');
    }

    /**
     * Processes a single uploaded PDF file and outputs verification results HTML.
     *
     * @param array  $file        Uploaded file data from $_FILES.
     * @param array  $visualLines Lines of text extracted for visual display.
     * @param string $progressKey Transient key for progress reporting.
     */
    public static function handleUpload(array $file, array $visualLines = [], string $progressKey = ''): void
    {
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- values here are already
        // escaped/int-cast/hashed/regex-constrained; WPCS can't trace escaping through interpolation.
        self::$progressKey = $progressKey;
        // Soft time budget, scaled to file size, aborts well before the 1800s hard ceiling.
        $fabricator_parse_start       = microtime(true);
        $fabricator_parse_file_mb     = max(1, (int) ceil(($file['size'] ?? 0) / 1048576));
        $fabricator_parse_max_seconds = min(600, 30 + ($fabricator_parse_file_mb * 2));
        $file_name = sanitize_file_name((string) ($file['name'] ?? 'document.pdf'));
        static $upload_id_counter = 0;
        $uid_prefix = 'fabricator-pdf-' . (++$upload_id_counter) . '-' . substr(md5($file_name), 0, 8);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            \FabricatorForms\fabricator_log('FabricatorForms handleUpload: rejected "' . $file_name . '" — PHP upload error code ' . $file['error'] . '.');
            // translators: %s: uploaded file name.
            $msg = sprintf(__('Upload failed for %s.', 'formfabricator'), esc_html($file_name));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml() wp_kses_post()'s
            // its $message argument internally.
            echo self::noticeHtml($msg, 'error');
            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected_mime = $finfo->file($file['tmp_name']);
        if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
            \FabricatorForms\fabricator_log('FabricatorForms handleUpload: rejected "' . $file_name . '" — finfo detected MIME "' . $detected_mime . '" instead of application/pdf.');
            // translators: %s: uploaded file name.
            $msg = sprintf(__('Invalid file type for %s.', 'formfabricator'), esc_html($file_name));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml() wp_kses_post()'s
            // its $message argument internally.
            echo self::noticeHtml($msg, 'error');
            return;
        }

        // --- Store visual lines if provided ---
        $upload_dir   = wp_upload_dir();
        $safe_dir     = $upload_dir['basedir'] . '/fabricator-secure-pdf';

        $wp_filesystem = self::getWpFilesystem();

        foreach (['', '/log'] as $sub) {
            $dir = $safe_dir . $sub;
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
                if ($wp_filesystem) {
                    $wp_filesystem->chmod($dir, 0750);
                }
                file_put_contents($dir . '/index.php', "<?php // Silence is golden ?>");
                if ($wp_filesystem) {
                    $wp_filesystem->chmod($dir . '/index.php', 0640);
                }
            }
        }

        $htaccess = $safe_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents(
                $htaccess,
                "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
            if ($wp_filesystem) {
                $wp_filesystem->chmod($htaccess, 0640);
            }
        }

        // $visualLines is already available as a parameter — no disk round-trip needed.

        self::setProgress(__('Byte scan: searching for seal…', 'formfabricator'), 5);

        // Incremental-update / shadow-attack guard: a legit PDF has exactly one %%EOF; a second signals objects appended after the original xref table to alter content while keeping the seal intact.
        $raw_for_guard = @file_get_contents($file['tmp_name']);
        if ($raw_for_guard === false) {
            \FabricatorForms\fabricator_log('FabricatorForms handleUpload: rejected "' . $file_name . '" — file_get_contents() failed reading the uploaded temp file.');
            // translators: %s: uploaded file name.
            $msg = sprintf(__('Could not read PDF file: %s.', 'formfabricator'), esc_html($file_name));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml() wp_kses_post()'s
            // its $message argument internally.
            echo self::noticeHtml($msg, 'error');
            return;
        }
        $eof_count                    = substr_count($raw_for_guard, '%%EOF');
        $incremental_update_detected  = $eof_count > 1;
        $incremental_update_eof_count = $eof_count;
        // Count seal markers in uncompressed (plain-text) parts of the raw bytes.
        // FlateDecode streams are handled by the pdfparser pass; this catches fakes
        // injected into uncompressed streams or appended raw text.
        $raw_plain_seal_count         = substr_count($raw_for_guard, '---BEGIN-SEAL---');
        unset($raw_for_guard);

        // Raw-byte preflight: scan compressed streams for the seal marker without
        // loading the full PDF object graph. Avoids calling pdfparser (and its
        // memory overhead) entirely for PDFs that have no fabricator seal.
        if (!self::rawPdfHasSeal($file['tmp_name'])) {
            // rawPdfHasSeal() itself logs details (skipped/oversized streams) when
            // relevant — this just records that this file was rejected at this gate.
            \FabricatorForms\fabricator_log('FabricatorForms handleUpload: rejected "' . $file_name . '" — raw-byte preflight found no seal marker.');
            // translators: %s: uploaded file name.
            $msg = sprintf(__('%s does not contain a fabricator-pdf seal and cannot be verified.', 'formfabricator'), esc_html($file_name)); // phpcs:ignore Generic.Files.LineLength
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml() wp_kses_post()'s
            // its $message argument internally.
            echo self::noticeHtml($msg, 'error');
            return;
        }

        self::setProgress(__('Parsing PDF…', 'formfabricator'), 10);

        $document_modified = null;
        ob_start();
        $outer_ob_level = ob_get_level();
        try {
            // $incremental_update_detected is set before the try block — make it available inside.
            $incremental_update_detected = $incremental_update_detected ?? false;
            $parser = new Parser();
            $pdf = $parser->parseFile($file['tmp_name']);
            $text = $pdf->getText();

            // Re-derive the soft parse budget from decompressed text volume, not on-disk size — a small compressed PDF can still unpack to a huge string (see BaseField::TEXT_FIELD_HARD_CAP, which bounds only per-field, not in aggregate).
            $fabricator_parse_text_mb    = strlen($text) / 1048576;
            $fabricator_parse_max_seconds = max(
                $fabricator_parse_max_seconds,
                min(600, 30 + ($fabricator_parse_text_mb * 4))
            );

            // --- Extract Seal (exactly one allowed) ---
            $seal_count = preg_match_all('/---BEGIN-SEAL---(.*?)---END-SEAL---/s', $text, $matches);
            if ($seal_count === 0) {
                throw new \RuntimeException("Seal not found in {$file_name}.");
            }
            // Multiple seal blocks: keep using the last one so other checks still run, but record the violation for the panel (also flags a fake seal injected into a plain uncompressed stream).
            $multiple_seals_detected = $seal_count > 1
                || ($raw_plain_seal_count ?? 0) > 0;

            // Save now — $matches will be overwritten by later preg_match_all calls.
            $text_seal_b64_list = array_values(
                array_filter(
                    array_map('trim', $matches[1] ?? []),
                    fn($s) => $s !== ''
                )
            );

            $seal_base64 = trim($multiple_seals_detected ? end($matches[1]) : $matches[1][0]);

            self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'seal extraction');

            if (strlen($seal_base64) > 65536) {
                throw new \RuntimeException("Seal is implausibly large in {$file_name}.");
            }

            $seal_json = base64_decode($seal_base64, true);
            if ($seal_json === false || strlen($seal_json) < 2) {
                throw new \RuntimeException("Base64 decode of seal failed for {$file_name}.");
            }

            $seal_data = json_decode($seal_json, true, 512, JSON_THROW_ON_ERROR);

            self::setProgress(__('Seal found — reconstructing payload…', 'formfabricator'), 25);

            // --- Rebuild payload ---
            $rebuilt_payload = self::rebuildPayload($seal_data);

            self::setProgress(__('HMAC check…', 'formfabricator'), 35);

            // --- HMAC check (early, before any output, so it's available for the summary) ---
            $seal_result      = HashSeal::verify($rebuilt_payload, $seal_data['seal']);
            $seal_matches     = $seal_result['valid'];
            $seal_key_status  = $seal_result['key_status'];
            $seal_compromised = $seal_result['compromised'];

            // --- Seal vs rebuilt diff ---
            $original_payload = $seal_data;
            unset($original_payload['seal']);
            $diffs = self::diffArrays($original_payload, $rebuilt_payload);
            $seal_rebuilt_match = empty($diffs);

            /* ---- PDF RAW PREPARATION ----
               Hoisted here (before the font-program integrity check) because that
               check reads $pdf_raw. It previously stayed unset until the "Multiple
               seals detail section" much further down, so this check always ran
               against an undefined variable — PHP treats that as null, and
               null !== false is true, so the branch always executed with an empty
               string in place of the real PDF bytes. hashFontProgramStreams('')
               then always returned [], so any seal with recorded font_prog_hashes
               was flagged as a font-program mismatch on every legitimate PDF. */
            $pdf_raw = null;

            if (!empty($file['tmp_name']) && is_readable($file['tmp_name'])) {
                $pdf_raw = file_get_contents($file['tmp_name']);
            } else {
                $pdf_raw = false;
            }

            // --- Font program integrity check (computed before the inner buffer so the fonts section can
            // display it) ---
            $font_prog_mismatch = false;
            $sealed_fp          = [];
            $live_fp            = [];
            if ($pdf_raw !== false) {
                $sealed_fp          = array_values(array_map('strval', (array) ($seal_data['font_prog_hashes'] ?? [])));
                $live_fp            = self::hashFontProgramStreams((string) $pdf_raw);
                sort($sealed_fp);
                sort($live_fp);
                $font_prog_mismatch = ($live_fp !== $sealed_fp);
                if ($font_prog_mismatch) {
                    $font_missmatch = true;
                }
            }

            // ---- Start inner buffer: all detail HTML goes here so the summary panel can be prepended ----
            ob_start();

            // --- Structure integrity section (only present when incremental update detected) ---
            if ($incremental_update_detected) {
                $struct_section_id = 'fabricator-pdf-content-structure-' . $uid_prefix;
                $struct_sec_attr = esc_attr($uid_prefix);
                echo "<div class='fabricator-pdf-detail-section'"
                   . " id='fabricator-pdf-section-structure-{$struct_sec_attr}'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($struct_section_id) . "'>" . esc_html__('PDF Structure', 'formfabricator') . "</button>";
                echo "<span class='fabricator-pdf-detail-badge fabricator-pdf-badge-fail'>" . esc_html__('FAIL', 'formfabricator') . "</span>";
                echo "</div>";
                $eof_n_disp = (int) $incremental_update_eof_count;
                echo "<div id='" . esc_attr($struct_section_id) . "'"
                   . " class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";
                echo "<div class='fabricator-pdf-hash-list'>";
                echo "<div class='fabricator-pdf-hash-row fabricator-pdf-hash-row--fail'>";
                echo "<span class='fabricator-pdf-hash-label'>%%EOF count</span>";
                echo "<span class='fabricator-pdf-hash-value'>{$eof_n_disp} (expected: 1)</span>";
                echo "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>FAIL</span>";
                echo "</div>";
                echo "<p style='margin:10px 14px 8px;font-size:12px;color:#444;line-height:1.6'>";
                echo wp_kses_post(sprintf(
                    /* translators: %s: the literal PDF "%%EOF" end-of-file marker, wrapped in <code> */
                    __(
                        // phpcs:ignore Generic.Files.LineLength -- WordPress.WP.I18n.NonSingularStringLiteralText requires __() to receive a single unbroken string literal, so it cannot be wrapped via concatenation.
                        'A valid PDF has exactly <strong>one</strong> %s marker. Each extra marker signals that content was <strong>appended after the original cross-reference table</strong> was written. This is the standard technique for a <strong>PDF shadow attack / incremental update</strong>: an attacker appends new objects that override visible content while leaving the original seal intact.',
                        'formfabricator'
                    ),
                    '<code>%%EOF</code>'
                ));
                echo "</p>";
                echo "</div>";
                echo "</div>";
                echo "</div>";
            }

            // --- Raw debug: Seal Data + Rebuilt Payload (info-only, always collapsed) ---
            $seal_id    = sanitize_html_class($uid_prefix . '-seal');
            $rebuilt_id = sanitize_html_class($uid_prefix . '-rebuilt');
            echo "<div class='fabricator-pdf-detail-section' id='fabricator-pdf-section-raw-" . esc_attr($uid_prefix) . "'>";
            echo "<div class='fabricator-pdf-detail-hdr'>";
            echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
               . " data-target='" . esc_attr($uid_prefix) . "-raw-content'>" . esc_html__('Raw Seal & Rebuilt Data', 'formfabricator') . "</button>";
            echo "<span class='fabricator-pdf-detail-badge fabricator-pdf-badge-info'>INFO</span>";
            echo "</div>";
            $flags      = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
            $json_seal  = esc_html((string) wp_json_encode($seal_data, $flags));
            $json_built = esc_html((string) wp_json_encode($rebuilt_payload, $flags));

            $raw_id = esc_attr($uid_prefix) . '-raw-content';
            echo "<div id='{$raw_id}' class='fabricator-pdf-hidden fabricator-pdf-detail-content' style='padding:0;'>";

            // Column headers — outside the scroll container so they stay fixed
            echo "<div class='fabricator-pdf-sbs-headers'>";
            echo "<div class='fabricator-pdf-sbs-col-label'>" . esc_html__('Seal', 'formfabricator') . "</div>";
            echo "<div class='fabricator-pdf-sbs-col-label'>" . esc_html__('Rebuilt', 'formfabricator') . "</div>";
            echo "</div>";

            // Single scrollable container — one scroll event, both columns move together
            echo "<div class='fabricator-pdf-sbs-scroll'>";
            echo "<pre class='fabricator-pdf-sbs-pre'>{$json_seal}</pre>";
            echo "<div class='fabricator-pdf-sbs-divider'></div>";
            echo "<pre class='fabricator-pdf-sbs-pre'>{$json_built}</pre>";
            echo "</div>";

            if (!$seal_rebuilt_match) {
                $json_diff = esc_html((string) wp_json_encode($diffs, $flags));
                echo "<div style='padding:12px 14px;border-top:1px solid #f5c6cb;'>";
                echo "<div class='fabricator-pdf-seal-pane__label'"
                    . " style='color:#721c24;margin-bottom:4px;'>" . esc_html__('Differences', 'formfabricator') . "</div>";
                echo "<pre class='fabricator-pdf-json-pre'"
                    . " style='border-color:#f5c6cb;color:#721c24;margin:0;'>{$json_diff}</pre>";
                echo "</div>";
            }
            echo "</div></div>";

            // --- Visual content check using invisible field markers ---
            $visual_mismatch_found = false;
            $field_mismatch_count  = 0;

            $normalize = function (string $s): string {
                // NFKC normalization collapses Unicode lookalikes (Cyrillic а→a,
                // fullwidth digits, ligatures, etc.) to their canonical ASCII forms.
                if (class_exists('Normalizer')) {
                    $n = \Normalizer::normalize($s, \Normalizer::NFKC);
                    if ($n !== false) {
                        $s = $n;
                    }
                }
                $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Expand common OpenType ligatures substituted by mPDF (U+FB00–FB06)
                $lig_from = ["\xEF\xAC\x80", "\xEF\xAC\x81", "\xEF\xAC\x82",
                             "\xEF\xAC\x83", "\xEF\xAC\x84", "\xEF\xAC\x85", "\xEF\xAC\x86"];
                $lig_to   = ['ff', 'fi', 'fl', 'ffi', 'ffl', 'st', 'st'];
                $s = str_replace($lig_from, $lig_to, $s);
                $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s); // remove control chars
                $s = str_replace(["\xC2\xA0", "\xAD"], ' ', $s); // NBSP + soft hyphen
                $s = preg_replace('/\s+/u', ' ', $s);            // normalize whitespace
                $s = preg_replace('/([,;])\s*/u', '$1 ', $s);    // one space after , and ;

                return trim($s);
            };

            // Normalize full PDF text
            $normalized_pdf = $normalize($text);
            $normalized_pdf = preg_replace(
                '/\[FABRICATOR_PDF_PAGENO_START\].*?\[FABRICATOR_PDF_PAGENO_END\]/s',
                '',
                $normalized_pdf
            );
            $fields = $rebuilt_payload['fields'] ?? [];

            self::setProgress(__('Checking fields…', 'formfabricator'), 45);

            // Section wrapper — badge + open-state injected after check runs via post-processing
            $all_visual_id = 'fabricator-pdf-content-fields-' . $uid_prefix;
            echo "<div class='fabricator-pdf-detail-section' id='fabricator-pdf-section-fields-" . esc_attr($uid_prefix) . "'>";
            echo "<div class='fabricator-pdf-detail-hdr' id='fabricator-pdf-hdr-fields-" . esc_attr($uid_prefix) . "'>";
            echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
               . " data-target='" . esc_attr($all_visual_id) . "'>" . esc_html__('Field Content', 'formfabricator') . "</button>";
            echo "<span id='fabricator-pdf-badge-fields-" . esc_attr($uid_prefix)
               . "' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>PASS</span>";
            echo "</div>";
            echo "<div id='" . esc_attr($all_visual_id) . "' class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";
            echo "<div class='fabricator-pdf-cmp-list'>";

            // Track processed array entries and start markers
            $processed_fields = [];
            $processed_markers = [];

            do {
                $new_start_found = false;

                $field_pattern = '/\[FABRICATOR_PDF_FIELD_([^\]]+)\](.*?)\[FABRICATOR_PDF_FIELD_END\]/s';
                if (preg_match_all($field_pattern, $normalized_pdf, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $start_marker = $match[1];
                        $pdf_field_text = $normalize($match[2]);

                        // Skip already processed markers
                        if (in_array($start_marker, $processed_markers, true)) {
                            continue;
                        }

                        // Find next unprocessed field in the payload
                        $payload_index = null;
                        foreach ($fields as $i => $f) {
                            if (!in_array($i, $processed_fields, true)) {
                                $payload_index = $i;
                                break;
                            }
                        }

                        if ($payload_index === null) {
                            echo "<div class='fabricator-pdf-cmp-row fabricator-pdf-cmp-row--fail'>"
                               . "<div class='fabricator-pdf-cmp-header'>"
                               . "<span class='fabricator-pdf-cmp-label'>" . esc_html__('Unknown field', 'formfabricator') . "</span>"
                               . "<span class='fabricator-pdf-cmp-marker'>" . esc_html($start_marker) . "</span>"
                               . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>" . esc_html__('NOT IN SEAL', 'formfabricator') . "</span>"
                               . "</div></div>\n";
                            $processed_markers[] = $start_marker;
                            $new_start_found = true;
                            continue;
                        }

                        $payload_field = $fields[$payload_index];
                        $expected_text = $normalize($payload_field['value'] ?? '');

                        // Remove all zero-width spaces, trim, and normalize whitespace again
                        $pdf_field_text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $pdf_field_text);
                        $expected_text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $expected_text);

                        $repair_missing_spaces_strict = function (string $pdf, string $canonical): ?string {
                            $p = 0;
                            $c = 0;
                            $out = '';

                            $pdf_len = mb_strlen($pdf);
                            $can_len = mb_strlen($canonical);

                            while ($p < $pdf_len && $c < $can_len) {
                                $pdf_ch = mb_substr($pdf, $p, 1);
                                $can_ch = mb_substr($canonical, $c, 1);

                                // Exact match
                                if ($pdf_ch === $can_ch) {
                                    $out .= $pdf_ch;
                                    $p++;
                                    $c++;
                                    continue;
                                }

                                // Canonical has space, PDF lost it → allow ONE thing
                                if ($can_ch === ' ' && $pdf_ch !== ' ') {
                                    $out .= ' ';
                                    $c++;
                                    continue;
                                }

                                // Anything else is a real mismatch
                                return null;
                            }

                            // Canonical must be fully consumed; extra trailing PDF text (layout bleed) is
                            // tolerated
                            if (trim(mb_substr($canonical, $c)) !== '') {
                                return null;
                            }

                            return $out;
                        };

                        $repaired_pdf = $repair_missing_spaces_strict(
                            $pdf_field_text,
                            $expected_text
                        );

                        if ($repaired_pdf === null) {
                            $matches_visual = false;
                        } else {
                            $matches_visual = ($repaired_pdf === $expected_text);
                            $pdf_field_text = $repaired_pdf; // IMPORTANT: for printing
                        }

                        if (!$matches_visual) {
                            $visual_mismatch_found = true;
                            $field_mismatch_count++;
                        }

                        // Display results as comparison card
                        $label      = $payload_field['label'] ?? '';
                        $row_state  = $matches_visual ? 'pass' : 'fail';
                        $pill_state = $matches_visual ? 'pass' : 'fail';
                        $pill_text  = $matches_visual ? esc_html__('MATCH', 'formfabricator') : esc_html__('MISMATCH', 'formfabricator');
                        $display_label = $label !== '' ? esc_html($label) : 'Field #' . (int) $payload_index;

                        echo "<div class='fabricator-pdf-cmp-row fabricator-pdf-cmp-row--{$row_state}'>";
                        echo "<div class='fabricator-pdf-cmp-header'>"
                           . "<span class='fabricator-pdf-cmp-label'>{$display_label}</span>"
                           . "<span class='fabricator-pdf-cmp-marker'>" . esc_html($start_marker) . "</span>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--{$pill_state}'>{$pill_text}</span>"
                           . "</div>";
                        echo "<div class='fabricator-pdf-cmp-body'>";
                        $seal_val = esc_html((string) ($payload_field['value'] ?? ''));
                        echo "<div class='fabricator-pdf-cmp-col'>"
                           . "<div class='fabricator-pdf-cmp-col__label'>" . esc_html__('Seal', 'formfabricator') . "</div>"
                           . "<div class='fabricator-pdf-cmp-col__value'>{$seal_val}</div>"
                           . "</div>";
                        echo "<div class='fabricator-pdf-cmp-col'>"
                           . "<div class='fabricator-pdf-cmp-col__label'>" . esc_html__('PDF', 'formfabricator') . "</div>"
                           . "<div class='fabricator-pdf-cmp-col__value'>" . esc_html((string) $pdf_field_text) . "</div>"
                           . "</div>";
                        echo "</div>"; // fabricator-pdf-cmp-body

                        if (!$matches_visual) {
                            $diff_parts = [];
                            $len = min(100, max(mb_strlen($expected_text), mb_strlen($pdf_field_text)));
                            for ($j = 0; $j < $len; $j++) {
                                $exp_char = mb_substr($expected_text, $j, 1);
                                $pdf_char = mb_substr($pdf_field_text, $j, 1);
                                if ($exp_char !== $pdf_char) {
                                    $diff_parts[] = 'pos ' . $j . ': &laquo;' . esc_html((string) $exp_char)
                                                  . '&raquo; vs &laquo;' . esc_html((string) $pdf_char) . '&raquo;';
                                }
                            }
                            if (!empty($diff_parts)) {
                                echo "<div class='fabricator-pdf-diff-row'>"
                                . implode(' &nbsp;|&nbsp; ', $diff_parts) . "</div>";
                            }
                        }

                        echo "</div>\n"; // fabricator-pdf-cmp-row

                        // Mark both as processed
                        $processed_fields[] = $payload_index;
                        $processed_markers[] = $start_marker;
                        $new_start_found = true;
                    }
                }
            } while ($new_start_found);

            self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'field extraction');

            echo "</div>"; // fabricator-pdf-cmp-list
            echo "</div>"; // fabricator-pdf-detail-content
            echo "</div>"; // fabricator-pdf-detail-section

            // --- Multiple seals detail section ---
            // Each seal is verified against the same key lookup as the primary; $multiple_seals_detected forces the verdict to fail regardless of individual HMAC validity.
            if ($multiple_seals_detected && $pdf_raw !== false) {
                // Collect all seals: the real one from pdfparser text (pre-saved list), plus any injected ones found only after the last %%EOF (full raw bytes false-positive on compressed data).
                $all_seals_b64 = [];
                foreach ($text_seal_b64_list as $m) {
                    if ($m !== '' && !in_array($m, $all_seals_b64, true)) {
                        $all_seals_b64[] = $m;
                    }
                }
                $last_eof_pos = strrpos((string) $pdf_raw, '%%EOF');
                $appended_raw = $last_eof_pos !== false
                    ? substr((string) $pdf_raw, $last_eof_pos + 5)
                    : '';
                preg_match_all('/---BEGIN-SEAL---(.*?)---END-SEAL---/s', $appended_raw, $raw_text_found);
                foreach ($raw_text_found[1] as $rb64) {
                    $rb64 = trim($rb64);
                    if ($rb64 !== '' && !in_array($rb64, $all_seals_b64, true)) {
                        $all_seals_b64[] = $rb64;
                    }
                }

                $seals_section_id = 'fabricator-pdf-content-seals-' . $uid_prefix;
                echo "<div class='fabricator-pdf-detail-section'"
                   . " id='fabricator-pdf-section-seals-" . esc_attr($uid_prefix) . "'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($seals_section_id) . "'>" . esc_html__('Seal Blocks', 'formfabricator') . "</button>";
                echo "<span class='fabricator-pdf-detail-badge fabricator-pdf-badge-fail'>" . esc_html__('FAIL', 'formfabricator') . "</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($seals_section_id) . "'"
                   . " class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";
                echo "<div class='fabricator-pdf-hash-list'>";

                $total_seals = count($all_seals_b64);
                echo "<p style='margin:8px 14px 4px;font-size:12px;color:#d63638;font-weight:600'>";
                echo esc_html(
                    sprintf(
                        /* translators: %d: number of seal blocks found in the PDF (should be exactly 1). */
                        __('%d seal block(s) found — exactly 1 is expected.', 'formfabricator'),
                        $total_seals
                    )
                ) . ' ';
                echo esc_html__('Any extra seal block is proof of tampering regardless of its HMAC status.', 'formfabricator');
                echo "</p>";

                foreach ($all_seals_b64 as $idx => $sb64) {
                    $sb64      = trim((string) $sb64);
                    $seal_num  = $idx + 1;
                    $is_ok     = false;
                    $sd        = null;
                    $parse_err = '';

                    if (strlen($sb64) > 65536) {
                        $parse_err = 'implausibly large — rejected';
                    } else {
                        $sj = base64_decode($sb64, true);
                        if ($sj === false) {
                            $parse_err = 'base64 decode failed';
                        } else {
                            $sd = json_decode($sj, true);
                            if (!is_array($sd)) {
                                $parse_err = 'JSON decode failed';
                            } else {
                                try {
                                    $rp    = self::rebuildPayload($sd);
                                    $vr    = HashSeal::verify($rp, (string)($sd['seal'] ?? ''));
                                    $is_ok = $vr['valid'];
                                } catch (\Throwable $sve) {
                                    \FabricatorForms\fabricator_log('FabricatorForms Verificationpage: seal HMAC check threw: ' . $sve->getMessage());
                                    $is_ok     = false;
                                    $parse_err = 'HMAC check failed';
                                }
                            }
                        }
                    }

                    $row_cls  = $is_ok ? 'fabricator-pdf-hash-row--pass' : 'fabricator-pdf-hash-row--fail';
                    $pill_cls = $is_ok ? 'fabricator-pdf-pill--pass'     : 'fabricator-pdf-pill--fail';
                    $pill_txt = $is_ok ? esc_html__('AUTHENTIC', 'formfabricator') : esc_html__('FORGED / INVALID', 'formfabricator');

                    $seal_row_style = 'flex-direction:column;align-items:flex-start;gap:6px;padding:10px 14px';
                    echo "<div class='fabricator-pdf-hash-row {$row_cls}' style='{$seal_row_style}'>";
                    echo "<div style='display:flex;align-items:center;gap:8px;width:100%'>";
                    echo "<strong style='flex:1'>Seal #" . (int)$seal_num . "</strong>";
                    echo "<span class='fabricator-pdf-pill {$pill_cls}'>{$pill_txt}</span>";
                    echo "</div>";

                    // Always show a truncated preview of the raw base64 between the markers.
                    $b64_preview = strlen($sb64) > 120
                        ? esc_html(substr($sb64, 0, 60)) . '…' . esc_html(substr($sb64, -30))
                        : esc_html($sb64);
                    echo "<div style='font-size:10px;color:#787c82;font-family:monospace;word-break:break-all'>";
                    echo "Base64: {$b64_preview}";
                    echo "</div>";

                    if ($parse_err !== '') {
                        echo "<div style='font-size:11px;color:#d63638'>" . esc_html($parse_err) . "</div>";
                    } elseif (is_array($sd)) {
                        // Show key fields from the seal so the admin can identify which is real
                        $s_form    = esc_html((string) ($sd['form_name'] ?? '—'));
                        $s_id      = esc_html((string) ($sd['form_id']   ?? '—'));
                        $s_gen     = esc_html((string) ($sd['generated'] ?? '—'));
                        $s_fields  = is_array($sd['fields'] ?? null) ? count($sd['fields']) : '—';
                        $s_pages   = esc_html((string) ($sd['expected_pages'] ?? '—'));
                        echo "<table style='font-size:11px;border-collapse:collapse;width:100%'>";
                        echo "<tr><td style='color:#787c82;padding:1px 8px 1px 0;white-space:nowrap'>" . esc_html__('Form', 'formfabricator') . "</td>"
                           . "<td>" . $s_form . " (ID: " . $s_id . ")</td></tr>";
                        echo "<tr><td style='color:#787c82;padding:1px 8px 1px 0'>" . esc_html__('Generated', 'formfabricator') . "</td>"
                           . "<td>" . $s_gen . "</td></tr>";
                        echo "<tr><td style='color:#787c82;padding:1px 8px 1px 0'>" . esc_html__('Fields', 'formfabricator') . "</td>"
                           . "<td>" . $s_fields . "</td></tr>";
                        echo "<tr><td style='color:#787c82;padding:1px 8px 1px 0'>" . esc_html__('Pages', 'formfabricator') . "</td>"
                           . "<td>" . $s_pages . "</td></tr>";
                        echo "</table>";
                    }

                    echo "</div>";
                }

                echo "</div></div></div>";
            }

            $unexpected_detected     = false;
            $annotation_mismatch     = false;
            $pagecount_mismatch      = false;
            $image_missmatch         = false;
            $font_missmatch          = false;
            $content_stream_mismatch = false;

            self::$image_slots = [];

            /* ─────────────────────── ANNOTATION PROCESSING ─────────────────────── */

            // $visualLines comes directly from the AJAX parameter — no log file read needed.
            if (!is_array($visualLines)) {
                $visualLines = [];
            }

            $annotation_fail_count = 0;

            self::setProgress(__('Checking annotations…', 'formfabricator'), 58);

            // Section wrapper — badge injected after annotation check via post-processing
            echo "<div class='fabricator-pdf-detail-section' id='fabricator-pdf-section-annots-" . esc_attr($uid_prefix) . "'>";

            $fold_id = 'fabricator-pdf-content-annots-' . $uid_prefix;
            echo "<div class='fabricator-pdf-detail-hdr'>";
            echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
               . " data-target='" . esc_attr($fold_id) . "'>"
               . esc_html__('Annotations', 'formfabricator') . "</button>";
            $annot_badge_id = 'fabricator-pdf-badge-annots-' . esc_attr($uid_prefix);
            echo "<span id='{$annot_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>PASS</span>";
            echo "</div>";
            echo "<div id='" . esc_attr($fold_id) . "'"
               . " class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";

            $fold_dupes_id = 'fold_dupecheck_' . uniqid();
            echo "<div class='fabricator-pdf-subsection'>";
            echo "<button type='button' class='fabricator-pdf-subtoggle fabricator-pdf-toggle'"
               . " data-target='" . esc_attr($fold_dupes_id) . "'>"
               . "<span class='fabricator-pdf-subtoggle__icon'>&#9656;</span> " . esc_html__('Chunk Check', 'formfabricator')
               . "</button>";
            echo "<div id='" . esc_attr($fold_dupes_id) . "'"
               . " class='fabricator-pdf-hidden fabricator-pdf-detail-content' style='padding:0;'>";
            echo "<div class='fabricator-pdf-cmp-list'>";

            $fields = $rebuilt_payload['fields'] ?? [];
            $processed_fields = [];
            $processed_markers = [];
            $potential_dupes = []; // <-- store actual dupes here

            $inside_field = false;
            $current_marker = '';
            $current_chunks = [];

            $fabricator_total_lines = count($visualLines);

            foreach ($visualLines as $line_number => $chunk) {
                // Interim progress so a large-but-valid submission (see the raised
                // visualLines cap above) doesn't look frozen while this loop works
                // through the full comparison instead of silently truncating data.
                if ($fabricator_total_lines > 0 && $line_number % 1000 === 0) {
                    self::setProgress(
                        sprintf(
                            /* translators: 1: lines processed so far, 2: total lines to process. */
                            __('Compiling results… (%1$d/%2$d)', 'formfabricator'),
                            $line_number,
                            $fabricator_total_lines
                        ),
                        58
                    );
                }

                // Strip numeric line prefixes like "58: "
                $chunk_clean = preg_replace('/^\d+:\s*/', '', $chunk);

                // --- Detect end of field first ---
                if ($inside_field && preg_match('/^\[FABRICATOR_PDF_FIELD_END\]/', $chunk_clean)) {
                    $inside_field = false;

                    // Concatenate chunks
                    $full_field_text = implode('', $current_chunks);

                    // Remove page markers inside field
                    $full_field_text = preg_replace(
                        '/\[FABRICATOR_PDF_PAGENO_START\].*?\[FABRICATOR_PDF_PAGENO_END\]/s',
                        '',
                        $full_field_text
                    );

                    if (!in_array($current_marker, $processed_markers, true)) {
                        // Find next unprocessed payload field
                        $payload_index = null;
                        foreach ($fields as $i => $f) {
                            if (!in_array($i, $processed_fields, true)) {
                                $payload_index = $i;
                                break;
                            }
                        }

                        if ($payload_index !== null) {
                            $payload_field = $fields[$payload_index];
                            $expected_text = $payload_field['value'] ?? '';

                            $found_pos = mb_strpos($full_field_text, $expected_text);
                            $potential_dupe = ($found_pos === false || count($current_chunks) > 1);
                            $snippet = $current_chunks[0] ?? '';

                            // Only store actual dupes
                            if ($potential_dupe) {
                                $potential_dupes[] = [
                                    'Sealdata'   => $expected_text,
                                    'chunkcount' => count($current_chunks),
                                ];
                            }

                            $label      = $payload_field['label'] ?? '';
                            $row_state  = $potential_dupe ? 'warn' : 'pass';
                            $pill_state = $potential_dupe ? 'warn' : 'pass';
                            $pill_text  = $potential_dupe ? __('MULTI-CHUNK', 'formfabricator') : __('OK', 'formfabricator');
                            $disp_label = $label !== '' ? esc_html($label) : esc_html($current_marker);
                            // translators: %d: number of text chunks the field's content was split into.
                            $chunk_count_label = sprintf(_n('%d chunk', '%d chunks', count($current_chunks), 'formfabricator'), count($current_chunks));

                            echo "<div class='fabricator-pdf-cmp-row fabricator-pdf-cmp-row--{$row_state}'>";
                            echo "<div class='fabricator-pdf-cmp-header'>"
                               . "<span class='fabricator-pdf-cmp-label'>{$disp_label}</span>"
                               . "<span class='fabricator-pdf-cmp-marker'>" . esc_html($current_marker) . "</span>"
                               . "<span class='fabricator-pdf-pill fabricator-pdf-pill--{$pill_state}'>" . esc_html($pill_text) . "</span>"
                               . "<span style='font-size:10px;color:#787c82;margin-left:4px;'>"
                               . esc_html($chunk_count_label) . "</span>"
                               . "</div>";
                            echo "<div class='fabricator-pdf-cmp-body'>";
                            $seal_v = esc_html((string) $expected_text);
                            $pdf_v  = esc_html((string) $snippet);
                            echo "<div class='fabricator-pdf-cmp-col'><div class='fabricator-pdf-cmp-col__label'>" . esc_html__('Seal', 'formfabricator') . "</div>"
                               . "<div class='fabricator-pdf-cmp-col__value'>{$seal_v}</div></div>";
                            echo "<div class='fabricator-pdf-cmp-col'><div class='fabricator-pdf-cmp-col__label'>" . esc_html__('PDF chunk', 'formfabricator') . "</div>"
                               . "<div class='fabricator-pdf-cmp-col__value'>{$pdf_v}</div></div>";
                            echo "</div></div>\n"; // fabricator-pdf-cmp-body + fabricator-pdf-cmp-row

                            $processed_fields[] = $payload_index;
                        } else {
                            echo "<div class='fabricator-pdf-cmp-row fabricator-pdf-cmp-row--fail'>"
                               . "<div class='fabricator-pdf-cmp-header'>"
                               . "<span class='fabricator-pdf-cmp-label'>" . esc_html__('Unknown marker', 'formfabricator') . "</span>"
                               . "<span class='fabricator-pdf-cmp-marker'>" . esc_html($current_marker) . "</span>"
                               . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>" . esc_html__('NOT IN SEAL', 'formfabricator') . "</span>"
                               . "</div></div>\n";
                        }

                        $processed_markers[] = $current_marker;
                    }

                    $current_marker = '';
                    $current_chunks = [];
                    continue;
                }

                // --- Detect start of field ---
                if (preg_match('/^\[FABRICATOR_PDF_FIELD_([^\]]+)\]/', $chunk_clean, $start_match)) {
                    $inside_field = true;
                    $current_marker = $start_match[1];
                    $current_chunks = [];
                    continue;
                }

                // Accumulate chunks if inside a field
                if ($inside_field) {
                    $current_chunks[] = $chunk_clean;
                }
            }

            echo "</div>"; // fabricator-pdf-cmp-list
            echo "</div>"; // fold_dupes_id content
            echo "</div>"; // fabricator-pdf-subsection

            // --- RAW PDF ANNOTATION EXTRACTION & SEAL CHECK ---
            if ($pdf_raw === false) {
                \FabricatorForms\fabricator_log('FabricatorForms handleUpload: could not re-read "' . $file_name . '" for annotation/seal extraction — file_get_contents() failed.');
                // translators: %s: uploaded file name.
                $msg = sprintf(__('Could not read PDF content for %s.', 'formfabricator'), esc_html($file_name));
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml()
                // wp_kses_post()'s its $message argument internally.
                echo self::noticeHtml($msg, 'error');
            } else {
                $annotations = [];

                // List of standard PDF annotation subtypes (from the PDF spec)
                $validTypes = [
                    'Text','FreeText','Highlight','Underline','Squiggly','StrikeOut',
                    'Line','Square','Circle','Polygon','PolyLine',
                    'Ink','Stamp','Popup','FileAttachment','Sound','Movie',
                    'Screen','Widget','PrinterMark','TrapNet','Watermark',
                    '3D','Redact','Projection','RichMedia'
                ];

                // --- 1) Scan all objects for /Type /Annot ---
                if (preg_match_all('/(\d+\s+\d+)\s+obj([\s\S]*?)endobj/s', $pdf_raw, $allObjs)) {
                    foreach ($allObjs[1] as $index => $objId) {
                        $rawDict = $allObjs[2][$index];

                        if (preg_match('/\/Type\s*\/Annot\b/i', $rawDict)) {
                            // Extract /Subtype if present
                            $subtype = null;
                            if (preg_match('/\/Subtype\s*\/([A-Za-z0-9]+)/i', $rawDict, $subMatch)) {
                                $subtype = $subMatch[1];
                            }

                            // Only accept known types
                            if ($subtype !== null && !in_array($subtype, $validTypes, true)) {
                                $subtype = 'UNKNOWN';
                            }

                            // Extract /Rect if present
                            $rect = [0,0,0,0];
                            if (preg_match('/\/Rect\s*\[(.*?)\]/s', $rawDict, $r)) {
                                $coords = preg_split('/\s+/', trim($r[1]));
                                $rect = array_map('floatval', $coords);
                            }

                            // Extract /Contents if present
                            $content = '';
                            if (preg_match('/\/Contents\s*\((.*?)\)/s', $rawDict, $c)) {
                                $content = $c[1];
                            }

                            // --- If /Contents is empty, try to extract /URI from /A dictionary ---
                            $uri_pat = '/\/A\s*<<[^>]*\/URI\s*\((.*?)\)/';
                            if ($content === '' && preg_match($uri_pat, $rawDict, $uriMatch)) {
                                $content = $uriMatch[1];
                            }

                            $content = preg_replace('/^mailto:/i', '', $content);

                            // Save rawDict for debugging if needed
                            $annotations[] = [
                                'type'    => $subtype ?? 'UNKNOWN',
                                'content' => $content,
                                'rect'    => $rect,
                                'objId'   => $objId,
                                'raw'     => $rawDict,
                            ];
                        }
                    }
                }

                // --- 2) Optional: check /Annots references for completeness ---
                if (str_contains($pdf_raw, '/Annots')) {
                    if (preg_match_all('/\/Annots\s*\[((?:\d+\s+\d+\s+R\s*)+)\]/', $pdf_raw, $annotRefs)) {
                        foreach ($annotRefs[1] as $pageIndex => $refs) {
                            if (preg_match_all('/(\d+\s+\d+)\s+R/', $refs, $objMatches)) {
                                foreach ($objMatches[1] as $objId) {
                                    // Already processed? skip
                                    $exists = false;
                                    foreach ($annotations as $a) {
                                        if ($a['objId'] === $objId) {
                                            $exists = true;
                                            break;
                                        }
                                    }
                                    if (!$exists) {
                                        $annotations[] = [
                                            'type'    => 'UNKNOWN (from /Annots)',
                                            'content' => '',
                                            'rect'    => null,
                                            'objId'   => $objId,
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }

                self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'object/annotation extraction');

                // --- 3) Foldable unified annotation report ---
                $all_annots_id = sanitize_html_class($uid_prefix . '-all-annots');
                echo "<div class='fabricator-pdf-subsection'>";
                $annot_btn_target = esc_attr($all_annots_id);
                echo "<button type='button' class='fabricator-pdf-subtoggle fabricator-pdf-toggle'"
                   . " data-target='{$annot_btn_target}'>"
                   . "<span class='fabricator-pdf-subtoggle__icon'>&#9656;</span> " . esc_html__('Annotation List', 'formfabricator')
                   . "</button>";
                echo "<div id='" . esc_attr($all_annots_id) . "'"
                   . " class='fabricator-pdf-hidden fabricator-pdf-detail-content' style='padding:0;'>";
                echo "<div class='fabricator-pdf-cmp-list'>";

                if (!empty($annotations)) {
                    $matched_fields = []; // track fields already matched
                    $potential_dupes_remaining = [];

                    // Initialize remaining counts for potential dupes
                    foreach ($potential_dupes ?? [] as $pd_idx => $pd) {
                        $potential_dupes_remaining[$pd_idx] = $pd['chunkcount'] - 1; // initial subtraction
                    }

                    foreach ($annotations as $i => $ann) {
                        // Determine the "content" to match
                        $content_to_match = '';

                        if (isset($ann['content']) && $ann['content'] !== '') {
                            $content_to_match = $ann['content'];
                        } elseif (!empty($ann['raw'])) {
                            if (preg_match('/\/A\s*<<[^>]*\/URI\s*\((.*?)\)/', $ann['raw'], $uriMatch)) {
                                $content_to_match = $uriMatch[1];
                            }
                        }
                        // Gap D: also check /V — widget annotations may hold value there instead of /Contents.
                        if ($content_to_match === '' && !empty($ann['raw'])) {
                            if (preg_match('/\/V\s*\(([^)]*)\)/', $ann['raw'], $vMatch)) {
                                $content_to_match = $vMatch[1];
                            }
                        }

                        $content_to_match = trim($content_to_match);

                        // Track match type
                        $match_type = 'No'; // default
                        $matched_field = null;

                        if ($content_to_match !== '') {
                            // --- Sealdata match first ---
                            foreach ($seal_data['fields'] ?? [] as $idx => $field) {
                                if (!is_array($field)) {
                                    continue;
                                }
                                if (in_array($idx, $matched_fields, true)) {
                                    continue;
                                }
                                $field_value = trim((string) ($field['value'] ?? ''));
                                if ($field_value === '') {
                                    continue;
                                }

                                if (stripos($field_value, $content_to_match) !== false) {
                                    $matched_field = $field_value;
                                    $matched_fields[] = $idx;
                                    $match_type = 'Yes';
                                    break;
                                }
                            }

                            // --- Fallback to potential dupes ---
                            if ($match_type === 'No') {
                                foreach ($potential_dupes ?? [] as $pd_idx => $pd) {
                                    $seal_text = $pd['Sealdata'];
                                    if ($potential_dupes_remaining[$pd_idx] <= 0) {
                                        continue;
                                    }

                                    if (stripos($seal_text, $content_to_match) !== false) {
                                        $matched_field = $seal_text;
                                        $potential_dupes_remaining[$pd_idx]--;
                                        $match_type = 'Dupe Match';
                                        break;
                                    }
                                }
                            }
                        }

                        if ($match_type === 'No') {
                            $annotation_mismatch = true;
                            $annotation_fail_count++;
                        }

                        $matched_to_display = $matched_field ?? '';

                        $row_state = match ($match_type) {
                            'Yes'        => 'pass',
                            'Dupe Match' => 'warn',
                            default      => 'fail',
                        };
                        $pill_state = $row_state;
                        $pill_text  = esc_html(match ($match_type) {
                            'Yes'        => __('MATCH', 'formfabricator'),
                            'Dupe Match' => __('DUPE', 'formfabricator'),
                            default      => __('UNMATCHED', 'formfabricator'),
                        });

                        $ann_label = 'Annot #' . ($i + 1) . ' — ' . esc_html($ann['type']);
                        echo "<div class='fabricator-pdf-cmp-row fabricator-pdf-cmp-row--{$row_state}'>";
                        echo "<div class='fabricator-pdf-cmp-header'>"
                           . "<span class='fabricator-pdf-cmp-label'>{$ann_label}</span>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--{$pill_state}'>{$pill_text}</span>"
                           . "</div>";
                        echo "<div class='fabricator-pdf-cmp-body'>";
                        echo "<div class='fabricator-pdf-cmp-col'>"
                           . "<div class='fabricator-pdf-cmp-col__label'>" . esc_html__('PDF content', 'formfabricator') . "</div>"
                           . "<div class='fabricator-pdf-cmp-col__value'>" . esc_html($content_to_match) . "</div>"
                           . "</div>";
                        echo "<div class='fabricator-pdf-cmp-col'>"
                           . "<div class='fabricator-pdf-cmp-col__label'>" . esc_html__('Matched to seal', 'formfabricator') . "</div>"
                           . "<div class='fabricator-pdf-cmp-col__value'>" . esc_html($matched_to_display) . "</div>"
                           . "</div>";
                        echo "</div></div>\n";
                    }
                } else {
                    echo "<p class='fabricator-pdf-empty-state'>" . esc_html__('No annotations found in PDF.', 'formfabricator') . "</p>";
                }

                echo "</div>"; // fabricator-pdf-cmp-list
                echo "</div>"; // all_annots_id content
                echo "</div>"; // all_annots fabricator-pdf-detail-section
            }

            echo "</div>"; // close annotation section foldable content
            echo "</div>"; // close annotation section wrapper

            /* ─────────────────────────── OBJECT PROCESSING ────────────────────────── */

            if ($pdf_raw !== false) {
                // PAGE COUNT CHECK
                preg_match_all('/\/Type\s*\/Page\b/', $pdf_raw, $page_matches);
                $object_page_count = count($page_matches[0]);
                $expected_pages = $rebuilt_payload['expected_pages'] ?? $object_page_count; // fallback

                if ($object_page_count !== $expected_pages) {
                    $pagecount_mismatch = true;
                    $unexpected_detected = true;
                }

                self::setProgress(__('Checking page count…', 'formfabricator'), 68);

                $page_box_id    = 'fabricator-pdf-content-pgcount-' . $uid_prefix;
                $pgcount_sec_id = 'fabricator-pdf-section-pgcount-' . esc_attr($uid_prefix);
                echo "<div class='fabricator-pdf-detail-section' id='{$pgcount_sec_id}'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($page_box_id) . "'>" . esc_html__('Page Count', 'formfabricator') . "</button>";
                $pgcount_badge = $pagecount_mismatch ? 'fabricator-pdf-badge-fail' : 'fabricator-pdf-badge-pass';
                $pgcount_label = $pagecount_mismatch ? __('FAIL', 'formfabricator') : __('PASS', 'formfabricator');
                echo "<span class='fabricator-pdf-detail-badge {$pgcount_badge}'>" . esc_html($pgcount_label) . "</span>";
                echo "</div>";
                $pgcount_hidden = $pagecount_mismatch ? '' : ' fabricator-pdf-hidden';
                echo "<div id='" . esc_attr($page_box_id) . "' class='fabricator-pdf-detail-content{$pgcount_hidden}'>";
                echo "<div class='fabricator-pdf-stat-row'>";
                echo "<div class='fabricator-pdf-stat'><div class='fabricator-pdf-stat__label'>" . esc_html__('Seal', 'formfabricator') . "</div>"
                   . "<div class='fabricator-pdf-stat__value'>{$expected_pages}</div></div>";
                echo "<div class='fabricator-pdf-stat-sep'>→</div>";
                echo "<div class='fabricator-pdf-stat'><div class='fabricator-pdf-stat__label'>" . esc_html__('PDF', 'formfabricator') . "</div>"
                   . "<div class='fabricator-pdf-stat__value'>{$object_page_count}</div></div>";
                echo "</div>";
                if ($pagecount_mismatch) {
                    echo "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>" . esc_html__('MISMATCH', 'formfabricator') . "</span>";
                } else {
                    echo "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>" . esc_html__('OK', 'formfabricator') . "</span>";
                }
                echo "</div>";
                echo "</div>";

                // Use exact XObject stream hashes when available (new seals).
                // Fall back to thumbnail hashes from uploads/template for old seals.
                $exact_image_hashes = $rebuilt_payload['image_hashes'] ?? [];
                $use_exact_hashes   = !empty($exact_image_hashes);

                $allowed_template_hashes = array_column($rebuilt_payload['template'], 'sha256');
                $allowed_upload_hashes   = array_column($rebuilt_payload['uploads'], 'sha256');
                $allowed_hashes          = array_merge($allowed_template_hashes, $allowed_upload_hashes);

                // IMAGE CHECK
                self::setProgress(__('Checking images…', 'formfabricator'), 75);

                $image_section_id  = 'fabricator-pdf-content-images-' . $uid_prefix;
                $image_section_sec = 'fabricator-pdf-section-images-' . esc_attr($uid_prefix);
                echo "<div class='fabricator-pdf-detail-section' id='{$image_section_sec}'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($image_section_id) . "'>Image Hashes</button>";
                $img_badge_id = 'fabricator-pdf-badge-images-' . esc_attr($uid_prefix);
                echo "<span id='{$img_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($image_section_id) . "'"
                   . " class='fabricator-pdf-hidden fabricator-pdf-detail-content'"
                   . " style='background:#f4f4f4; padding:10px; border:1px solid #ddd;'>";

                if (str_contains($pdf_raw, '/XObject')) {
                    // Pre-collect SMask object numbers so they're skipped as standalone images (they're alpha channels).
                    $smask_obj_nums = [];
                    if (preg_match_all('/\/SMask\s+(\d+)\s+\d+\s+R/', $pdf_raw, $_sm)) {
                        $smask_obj_nums = array_flip($_sm[1]);
                        unset($_sm);
                    }

                    // Ensure image output directory exists (HTTP-blocked); hoisted out of the recursive
                    // $scanXObjects closure to avoid repeating the same stat/write calls per image.
                    $upload_dir = wp_upload_dir();
                    $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';
                    $ver_dir    = $safe_dir . '/verimages';

                    $wp_filesystem = self::getWpFilesystem();

                    foreach ([$safe_dir, $ver_dir] as $dir) {
                        if (!is_dir($dir)) {
                            wp_mkdir_p($dir);
                            if ($wp_filesystem) {
                                $wp_filesystem->chmod($dir, 0750);
                            }
                            file_put_contents($dir . '/index.php', "<?php // Silence is golden ?>");
                            if ($wp_filesystem) {
                                $wp_filesystem->chmod($dir . '/index.php', 0640);
                            }
                        }
                    }

                    // Ensure the parent .htaccess exists (Generator may not have run yet).
                    $htaccess = $safe_dir . '/.htaccess';
                    if (!file_exists($htaccess)) {
                        file_put_contents(
                            $htaccess,
                            "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                        );
                        if ($wp_filesystem) {
                            $wp_filesystem->chmod($htaccess, 0640);
                        }
                    }

                    $scanXObjects = function ($pdf_raw, $parentName = null, array $visited = [])
 use (&$scanXObjects, $allowed_hashes, $rebuilt_payload, $smask_obj_nums, $exact_image_hashes, $use_exact_hashes, $safe_dir, $ver_dir) {
                        $offset = 0;
                        $found  = false;

                        while (($pos = strpos($pdf_raw, '/XObject', $offset)) !== false) {
                            $found = true;

                            $obj_start_line = strrpos(substr($pdf_raw, 0, $pos), "\n") ?: 0;
                            $obj_start      = $obj_start_line + 1;

                            // Resolve the PDF object number — look back up to 4 KB from
                            // /XObject so large image dictionaries are handled correctly.
                            $look_back = substr($pdf_raw, max(0, $pos - 4096), min($pos, 4096));
                            $obj_num   = null;
                            if (preg_match_all('/(\d+)\s+\d+\s+obj\b/', $look_back, $_all_nm)) {
                                $obj_num = end($_all_nm[1]); // last = closest to /XObject
                            }
                            unset($_all_nm);

                            if ($obj_num !== null && isset($smask_obj_nums[$obj_num])) {
                                $offset = $pos + 10;
                                continue;
                            }

                                    $obj_end = strpos($pdf_raw, 'endobj', $obj_start);
                            if ($obj_end === false) {
                                $offset = $pos + 10;
                                continue;
                            }

                                    $fullObj = substr($pdf_raw, $obj_start, $obj_end + 6 - $obj_start);
                                    $isImage = str_contains($fullObj, '/Subtype /Image');

                                    // Extract filters
                                    preg_match_all('/\/Filter\s*\/([A-Za-z0-9]+)/i', $fullObj, $fMatches);
                                    $filters = $fMatches[1] ?: [];

                                    // Extract width & height
                                    $width = $height = null;

                                    // Direct integer or float
                            if (preg_match('/\/Width\s+([0-9.]+)/', $fullObj, $m)) {
                                $width = (int)round($m[1]);
                            }
                            if (preg_match('/\/Height\s+([0-9.]+)/', $fullObj, $m)) {
                                $height = (int)round($m[1]);
                            }

                                    // Indirect reference (e.g. /Width 12 0 R)
                            $wh_pat = '/\/(Width|Height)\s+(\d+)\s+0\s+R/';
                            if ((!$width || !$height)
                                && preg_match_all($wh_pat, $fullObj, $refs, PREG_SET_ORDER)
                            ) {
                                foreach ($refs as $r) {
                                    $refNum  = $r[2];
                                    $ref_pat = '/' . $refNum . '\s+0\s+obj\s+(.*?)\s+endobj/s';
                                    if (preg_match($ref_pat, $pdf_raw, $refObj)) {
                                        if ($r[1] === 'Width') {
                                            $width  = (int)trim($refObj[1]);
                                        }
                                        if ($r[1] === 'Height') {
                                            $height = (int)trim($refObj[1]);
                                        }
                                    }
                                }
                            }

                                    // Reject implausible /Width, /Height before they size any allocation/loop bound —
                                    // JPEG gets the full pixel cap (hashed raw, no per-pixel loop); other filters get half.
                            $dims_over_cap = false;
                            $safe_pixels = PdfUtils::maxSafePixels();
                            // Require DCTDecode as the SOLE filter for the higher cap — otherwise a crafted PDF
                            // could claim it while still routing through the unvectorized per-pixel loops below.
                            $pixel_cap = (count($filters) === 1 && $filters[0] === 'DCTDecode')
                                ? $safe_pixels
                                : (int) ($safe_pixels / 2);
                            if ($width !== null && $height !== null) {
                                if ($width < 0 || $height < 0 || $width > 20000 || $height > 20000
                                    || ($width * $height) > $pixel_cap
                                ) {
                                    $dims_over_cap = true;
                                    $width = $height = null;
                                }
                            }

                                    // Extract stream
                                    $stream_pos = strpos($pdf_raw, 'stream', $obj_start);
                                    $endstream_pos = strpos($pdf_raw, 'endstream', $stream_pos);
                                    $stream_data = '';
                                    $decoded = null;

                            if ($stream_pos !== false && $endstream_pos !== false) {
                                $stream_data = substr($pdf_raw, $stream_pos + 6, $endstream_pos - ($stream_pos + 6));
                                $stream_data = ltrim($stream_data, "\r\n");

                                $decoded = $stream_data;
                                if (in_array('FlateDecode', $filters, true)) {
                                    // Bound both input and output size against a decompression-bomb stream.
                                    $try = (strlen($stream_data) <= 67108864) ? @gzuncompress($stream_data) : false;
                                    if ($try !== false && strlen($try) <= 67108864) {
                                        $decoded = $try;
                                    }
                                }
                            }

                                    $bytes    = strlen($decoded ?? '');
                                    $channels = 0;

                            if ($isImage) {
                                // Determine metadata
                                preg_match('/\/BitsPerComponent\s+(\d+)/', $fullObj, $bpcMatch);
                                $bpc = (int)($bpcMatch[1] ?? 8);

                                preg_match('/\/ColorSpace\s*(\/[A-Za-z0-9]+|\[.+?\])/s', $fullObj, $csMatch);
                                $csRaw = $csMatch[1] ?? '';
                                $colorspace = 'unknown';
                                $channels = 0;
                                $palette = null;

                                $isImageMask = str_contains($fullObj, '/ImageMask true');

                                // Standard color spaces
                                if (is_string($csRaw)) {
                                    switch ($csRaw) {
                                        case '/DeviceRGB':
                                                $colorspace = 'DeviceRGB';
                                            $channels = 3;
                                            break;
                                        case '/DeviceGray':
                                                $colorspace = 'DeviceGray';
                                            $channels = 1;
                                            break;
                                        case '/DeviceCMYK':
                                                $colorspace = 'DeviceCMYK';
                                            $channels = 4;
                                            break;
                                    }
                                }

                                // Indexed color spaces have TWO streams (image index + palette lookup); both must decode.

                                // Indexed color spaces
                                if (str_starts_with($csRaw, '[')
                                    && preg_match('/\/Indexed\s+\/DeviceRGB\s+(\d+)\s+(\d+)\s+0\s+R/', $csRaw, $m)
                                ) {
                                    $colorspace = 'IndexedRGB';
                                    $channels = 1; // IMPORTANT: index stream is 1 channel
                                    $hival = (int)$m[1];
                                    $paletteObjNum = (int)$m[2];

                                    // Per the PDF spec, hival for an 8-bit index stream can never
                                    // exceed 255 — reject anything outside that range before it
                                    // drives an allocation/loop bound below.
                                    if ($hival < 0 || $hival > 255) {
                                        $hival = null;
                                    }

                                    if ($hival !== null) {
                                        $pal_pat = '/' . $paletteObjNum . '\s+0\s+obj\s+(.*?)\s+endobj/s';
                                        if (preg_match($pal_pat, $pdf_raw, $palObj)) {
                                            // Extract palette filters
                                            preg_match_all('/\/Filter\s*\/([A-Za-z0-9]+)/', $palObj[1], $pf);
                                            $palFilters = $pf[1] ?? [];

                                            if (preg_match('/stream\s*(.*?)\s*endstream/s', $palObj[1], $palStream)) {
                                                $lookup = ltrim($palStream[1], "\r\n");

                                                // Decode palette stream — bounded against a decompression bomb.
                                                if (in_array('FlateDecode', $palFilters, true)) {
                                                    $try = (strlen($lookup) <= 67108864) ? @gzuncompress($lookup) : false;
                                                    if ($try !== false && strlen($try) <= 67108864) {
                                                        $lookup = $try;
                                                    }
                                                }

                                                // Validate palette length
                                                $expectedLen = ($hival + 1) * 3;
                                                if (strlen($lookup) < $expectedLen) {
                                                    throw new \RuntimeException("Indexed palette too short");
                                                }

                                                // Build palette
                                                $palette = [];
                                                for ($i = 0; $i <= $hival; $i++) {
                                                    $off = $i * 3;
                                                    $palette[$i] = [
                                                        ord($lookup[$off]),
                                                        ord($lookup[$off + 1]),
                                                        ord($lookup[$off + 2]),
                                                    ];
                                                }
                                            }
                                        }
                                    }
                                }

                                // JPEG hash is on raw encoded bytes, so colorspace is irrelevant; treat as DeviceRGB.
                                if ($channels <= 0 && in_array('DCTDecode', $filters, true)) {
                                    $colorspace = 'DeviceRGB';
                                    $channels   = 3;
                                }

                                // --- Collect failure reasons ---
                                $failureReasons = [];
                                if ($decoded === null) {
                                    $failureReasons[] = 'Decoded stream is NULL';
                                }
                                if ($dims_over_cap) {
                                    $failureReasons[] = 'Image exceeds the ' . (int)($pixel_cap / 1_000_000)
                                        . '-megapixel verification size limit for this image type and was '
                                        . 'skipped — this is a size cap, not evidence of tampering.';
                                } elseif (!$width || !$height) {
                                    $failureReasons[] = "Invalid dimensions ({$width}×{$height})";
                                }
                                if ($isImageMask) {
                                    $failureReasons[] = "Image is a mask";
                                }
                                if ($channels <= 0) {
                                    $failureReasons[] = "Unsupported ColorSpace: {$colorspace}";
                                }

                                // Always display metadata if anything is wrong
                                if ($failureReasons) {
                                    // An unreadable image XObject is suspicious — record it as a mismatch
                                    // so a tampered/corrupted image can't slip through by being undecodable.
                                    self::$image_slots[] = [
                                        'label'        => 'Unreadable XObject',
                                        'allowed'      => 0,
                                        'isBackground' => false,
                                        'hash'         => '',
                                        'check_hash'   => '',
                                        'colorspace'   => $colorspace,
                                        'width'        => $width,
                                        'height'       => $height,
                                    ];

                                    echo "<div style='margin:10px; padding:8px;"
                                       . " border:2px dashed #c00; background:#fff6f6;'>";
                                    echo "<b>Image could not be recreated from stream — counted as mismatch</b><br>";
                                    echo "<ul style='margin:5px 0; padding-left:18px;'>";
                                    foreach ($failureReasons as $r) {
                                        echo "<li>" . esc_html($r) . "</li>";
                                    }
                                    echo "</ul>";

                                    echo "<div style='font-size:11px; color:#333;'>";
                                    echo "<b>Image metadata:</b><br>";
                                    echo "• Filters: " . esc_html(implode(', ', $filters)) . "<br>";
                                    echo "• Width × Height: " . (int)$width . " × " . (int)$height . "<br>";
                                    echo "• BitsPerComponent: " . (int)$bpc . "<br>";
                                    echo "• ColorSpace: " . esc_html($colorspace) . "<br>";
                                    echo "• Channels: " . (int)$channels . "<br>";
                                    echo "• ImageMask: " . ($isImageMask ? 'true' : 'false') . "<br>";
                                    $dec_size = $decoded !== null ? strlen($decoded) . ' bytes' : 'n/a';
                                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $dec_size is only ever an (int) length + the literal ' bytes', or the literal 'n/a'; never derived from request/file content.
                                    echo "• Decoded size: {$dec_size}<br>";
                                    echo "</div></div>";

                                    $offset = $obj_end + 6;
                                    continue;
                                }
                            }

                            if ($isImage && $decoded !== null && $width && $height) {
                                // --- Image metadata ---
                                preg_match('/\/BitsPerComponent\s+(\d+)/', $fullObj, $bpcMatch);
                                $bpc = (int)($bpcMatch[1] ?? 8);

                                $palette    = [];
                                $colorspace = 'DeviceRGB'; // default
                                if (preg_match('/\/ColorSpace\s*\[\s*\/Indexed\s*\/([A-Za-z0-9]+)/', $fullObj, $m)) {
                                    $colorspace = 'IndexedRGB';
                                    $baseSpace  = $m[1]; // usually DeviceRGB
                                    if ($baseSpace !== 'DeviceRGB') {
                                        // The palette decoder below always reads 3-byte RGB triples; flag other bases.
                                        $failureReasons[] = "Unsupported Indexed base colorspace: {$baseSpace}";
                                    }
                                } elseif (preg_match('/\/ColorSpace\s*\/([A-Za-z0-9]+)/', $fullObj, $m)) {
                                    $colorspace = $m[1];
                                }

                                $isImageMask = str_contains($fullObj, '/ImageMask true');

                                preg_match('/\/Decode\s*\[(.*?)\]/', $fullObj, $decodeMatch);
                                $invert = isset($decodeMatch[1]) && trim($decodeMatch[1]) === '1 0';

                                preg_match('/\/DecodeParms\s*<<(.+?)>>/s', $fullObj, $dpMatch);
                                $predictor = 1;
                                $colors = null;
                                if ($dpMatch) {
                                    if (preg_match('/\/Predictor\s+(\d+)/', $dpMatch[1], $m)) {
                                        $predictor = (int)$m[1];
                                    }
                                    if (preg_match('/\/Colors\s+(\d+)/', $dpMatch[1], $m)) {
                                        $colors = (int)$m[1];
                                    }
                                }

                                // --- Skip unsupported ---
                                if ($isImageMask || $bpc > 8 || $bpc < 1) {
                                    $offset = $obj_end + 6;
                                    continue;
                                }

                                // --- Determine channels ---
                                $channels = match ($colorspace) {
                                    'DeviceRGB' => 3,
                                    'DeviceGray' => 1,
                                    'DeviceCMYK' => 4,
                                    'IndexedRGB' => 1,
                                    default => 0
                                };

                                // JPEG hash is on raw DCT bytes, so colorspace is irrelevant; treat as DeviceRGB.
                                if ($channels === 0 && in_array('DCTDecode', $filters, true)) {
                                    $colorspace = 'DeviceRGB';
                                    $channels   = 3;
                                }

                                if ($channels === 0) {
                                    $failureReasons[] = "Unsupported ColorSpace: {$colorspace}";
                                    $offset = $obj_end + 6;
                                    continue;
                                }

                                if ($width === null || $height === null) {
                                    $failureReasons[] = 'Implausible or missing image dimensions';
                                    $offset = $obj_end + 6;
                                    continue;
                                }

                                        // --- PNG Predictor (skip for IndexedRGB) ---
                                if ($predictor >= 10 && $decoded !== null && $colorspace !== 'IndexedRGB') {
                                    $rowSize = $width * $channels;
                                    $out = '';
                                    $prev = str_repeat("\0", $rowSize);
                                    $i = 0;
                                    for ($y = 0; $y < $height; $y++) {
                                        if ($i >= strlen($decoded)) {
                                            break;
                                        }
                                        $filter = ord($decoded[$i++]);
                                        $row = substr($decoded, $i, $rowSize);
                                        $i += strlen($row);
                                        $row = str_pad($row, $rowSize, "\0");
                                        for ($j = 0; $j < $rowSize; $j++) {
                                            $cur = ord($row[$j]);
                                            $up  = ord($prev[$j]);
                                            $left = $j >= $channels ? ord($row[$j - $channels]) : 0;
                                            switch ($filter) {
                                                case 0:
                                                    $row[$j] = chr($cur);
                                                    break;
                                                case 1:
                                                    $row[$j] = chr(($cur + $left) & 0xFF);
                                                    break;
                                                case 2:
                                                    $row[$j] = chr(($cur + $up) & 0xFF);
                                                    break;
                                                case 3:
                                                    $row[$j] = chr(($cur + intdiv($left + $up, 2)) & 0xFF);
                                                    break;
                                                case 4:
                                                    $prev_c = $j >= $channels ? ord($prev[$j - $channels]) : 0;
                                                    $p  = $left + $up - $prev_c;
                                                    $pa = abs($p - $left);
                                                    $pb = abs($p - $up);
                                                    $pc = abs($p - $prev_c);
                                                    $paeth = ($pa <= $pb && $pa <= $pc)
                                                    ? $left
                                                    : (($pb <= $pc) ? $up : $prev_c);
                                                    $row[$j] = chr(($cur + $paeth) & 0xFF);
                                                    break;
                                                default:
                                                    $row[$j] = chr($cur);
                                            }
                                        }
                                        $out .= $row;
                                        $prev = $row;
                                    }
                                    $decoded = $out;
                                }

                                        // --- Handle IndexedRGB safely ---
                                $is_indexed = $colorspace === 'IndexedRGB'
                                    && $decoded !== null
                                    && is_array($palette)
                                    && count($palette) > 0;
                                if ($is_indexed) {
                                    if ($predictor >= 10) {
                                        $decoded = self::undoPngPredictorIndexed($decoded, $width, $bpc);
                                    }

                                    // --- Map indices to RGB and invert colors ---
                                    $expanded = '';
                                    $paletteSize = count($palette);
                                    for ($y = 0; $y < $height; $y++) {
                                        $rowStart = $y * $width;
                                        for ($x = 0; $x < $width; $x++) {
                                            $byte_pos = $rowStart + $x;
                                            $idx = ($byte_pos < strlen($decoded)) ? ord($decoded[$byte_pos]) : 0;
                                            if ($idx >= $paletteSize) {
                                                $idx = $paletteSize - 1;
                                            }

                                            $rgbEntry = $palette[$idx];
                                            if (is_string($rgbEntry)) {
                                                $rgbEntry = str_pad(substr($rgbEntry, 0, 3), 3, "\0");
                                                $rgb = array_map('ord', str_split($rgbEntry));
                                            } elseif (is_array($rgbEntry) && count($rgbEntry) >= 3) {
                                                $rgb = array_map(
                                                    fn($v) => is_string($v) ? ord($v) : (int) $v,
                                                    array_slice($rgbEntry, 0, 3)
                                                );
                                            } else {
                                                $rgb = [0,0,0];
                                            }

                                            $expanded .= chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]);
                                        }
                                    }

                                    $decoded = $expanded;
                                    $channels = 3;
                                }

                                        // --- Ensure full buffer ---
                                        $expected_len = $width * $height * $channels;
                                if (strlen($decoded) < $expected_len) {
                                    $decoded = str_pad($decoded, $expected_len, "\0");
                                }

                                        $is_jpeg_obj = in_array('DCTDecode', $filters, true);

                                        // Exact XObject hash (new seals): raw compressed bytes, same across passes.
                                if ($use_exact_hashes) {
                                    $check_hash        = hash('sha256', $stream_data);
                                    $hash_method_label = 'Exact XObject stream (sha256)';
                                    $img_id            = $check_hash;
                                } else {
                                    // Legacy seal: thumbnail 8×8 quantised comparison
                                    $gd_available = function_exists('imagecreatefromstring');
                                    $gd_src = ($is_jpeg_obj && $gd_available)
                                        ? @imagecreatefromstring($decoded)
                                        : null;
                                    if (!$is_jpeg_obj && $gd_available) {
                                        $gd_src = imagecreatetruecolor($width, $height);
                                        $idx = 0;
                                        for ($ty = 0; $ty < $height; $ty++) {
                                            for ($tx = 0; $tx < $width; $tx++) {
                                                if ($colorspace === 'IndexedRGB' || $colorspace === 'DeviceRGB') {
                                                    $tr = ord($decoded[$idx++] ?? "\x00");
                                                    $tg = ord($decoded[$idx++] ?? "\x00");
                                                    $tb = ord($decoded[$idx++] ?? "\x00");
                                                } elseif ($colorspace === 'DeviceGray') {
                                                    $tg = ord($decoded[$idx++] ?? "\x00");
                                                    $tr = $tb = $tg;
                                                } else {
                                                    $tc  = ord($decoded[$idx++] ?? "\x00") / 255;
                                                    $tm  = ord($decoded[$idx++] ?? "\x00") / 255;
                                                    $tyC = ord($decoded[$idx++] ?? "\x00") / 255;
                                                    $tk  = ord($decoded[$idx++] ?? "\x00") / 255;
                                                    $tr  = (int)(255 * (1 - min(1, $tc + $tk)));
                                                    $tg  = (int)(255 * (1 - min(1, $tm + $tk)));
                                                    $tb  = (int)(255 * (1 - min(1, $tyC + $tk)));
                                                }
                                                imagesetpixel(
                                                    $gd_src,
                                                    $tx,
                                                    $ty,
                                                    imagecolorallocate($gd_src, $tr, $tg, $tb)
                                                );
                                            }
                                        }
                                    }
                                    if ($gd_src !== false && $gd_src !== null) {
                                        $thumb = imagecreatetruecolor(8, 8);
                                        imagecopyresampled(
                                            $thumb,
                                            $gd_src,
                                            0,
                                            0,
                                            0,
                                            0,
                                            8,
                                            8,
                                            imagesx($gd_src),
                                            imagesy($gd_src)
                                        );
                                        if (!$is_jpeg_obj) {
                                            imagedestroy($gd_src);
                                        }
                                        $pixels = '';
                                        for ($ty = 0; $ty < 8; $ty++) {
                                            for ($tx = 0; $tx < 8; $tx++) {
                                                $tc = imagecolorat($thumb, $tx, $ty);
                                                $pixels .= chr((($tc >> 16) & 0xFF) & ~7)
                                                         . chr((($tc >> 8)  & 0xFF) & ~7)
                                                         . chr(($tc         & 0xFF) & ~7);
                                            }
                                        }
                                        imagedestroy($thumb);
                                        $img_id            = hash('sha256', $pixels);
                                        $hash_method_label = 'Thumbnail 8×8 quantised (thumbnail8x8q8)';
                                    } else {
                                        $dim_key           = $colorspace . '|' . $width . '|' . $height;
                                        $img_id            = hash('sha256', $dim_key);
                                        $hash_method_label = 'Dimension fingerprint (GD decode failed)';
                                    }
                                    $check_hash = $img_id;
                                }

                                        $uid = 'img_' . uniqid();

                                        // Determine file extension
                                        $ext = in_array('DCTDecode', $filters, true) ? 'jpg' : 'png';
                                        $imgFile = $ver_dir . "/xobject_{$check_hash}.{$ext}";

                                        // --- Emit empty slot FIRST (no logic) ---
                                // --- Prepare slot metadata ---
                                $is_allowed = $use_exact_hashes
                                    ? in_array($check_hash, $exact_image_hashes, true)
                                    : in_array($check_hash, $allowed_hashes, true);

                                        self::emitImageSlot(
                                            $uid,
                                            [
                                            'colorspace'  => $colorspace,
                                            'width'       => $width,
                                            'height'      => $height,
                                            'img_id'      => $img_id,
                                            'decoded_len' => strlen($decoded),
                                            'allowed'     => $is_allowed ? 1 : 0,
                                            'file_path'   => $imgFile,
                                            ]
                                        );
                                self::$image_slots[$uid] = [
                                    'allowed' => $is_allowed ? 1 : 0,
                                    'colorspace' => $colorspace,
                                    'width' => $width,
                                    'height' => $height,
                                    'isBackground' => false,
                                ];

                                // --- Decode SMask (alpha channel) for this image, if any ---
                                // Detect directly from the image's own dictionary (avoids the
                                // expensive full-PDF obj scan that caused catastrophic backtracking).
                                $smask_decoded = null;
                                $_sm_ref_num   = null;
                                if (preg_match('/\/SMask\s+(\d+)\s+\d+\s+R/', $fullObj, $_sm_ref)) {
                                    $_sm_ref_num = $_sm_ref[1];
                                    unset($_sm_ref);
                                }
                                if ($_sm_ref_num !== null) {
                                    $sm_num = $_sm_ref_num;
                                    if (preg_match(
                                        '/\b' . preg_quote($sm_num, '/') . '\s+\d+\s+obj\b(.*?)endobj/s',
                                        $pdf_raw,
                                        $_sm_obj
                                    )) {
                                        $sm_body = $_sm_obj[1];
                                        preg_match_all('/\/Filter\s*\/([A-Za-z0-9]+)/', $sm_body, $_sm_f);
                                        $sm_filters = $_sm_f[1] ?? [];
                                        if (preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $sm_body, $_sm_s)) {
                                            $sm_raw  = $_sm_s[1];
                                            $sm_data = $sm_raw;
                                            if (in_array('FlateDecode', $sm_filters, true)) {
                                                $sm_try = (strlen($sm_raw) <= 67108864) ? @gzuncompress($sm_raw) : false;
                                                if ($sm_try !== false && strlen($sm_try) <= 67108864) {
                                                    $sm_data = $sm_try;
                                                }
                                            }
                                            // Undo PNG predictor for 1-channel (DeviceGray) SMask.
                                            $sm_predictor = 1;
                                            if (preg_match('/\/DecodeParms\s*<<(.*?)>>/s', $sm_body, $_sm_dp)
                                                && preg_match('/\/Predictor\s+(\d+)/', $_sm_dp[1], $_sm_pp)
                                            ) {
                                                $sm_predictor = (int)$_sm_pp[1];
                                            }
                                            if ($sm_predictor >= 10) {
                                                $sm_out  = '';
                                                $sm_prev = str_repeat("\0", $width);
                                                $sm_i    = 0;
                                                for ($sy = 0; $sy < $height; $sy++) {
                                                    if ($sm_i >= strlen($sm_data)) {
                                                        break;
                                                    }
                                                    $sm_ftype = ord($sm_data[$sm_i++]);
                                                    $sm_row   = str_pad(
                                                        substr($sm_data, $sm_i, $width),
                                                        $width,
                                                        "\0"
                                                    );
                                                    $sm_i += $width;
                                                    for ($sx = 0; $sx < $width; $sx++) {
                                                        $sc   = ord($sm_row[$sx]);
                                                        $sup  = ord($sm_prev[$sx]);
                                                        $slft = $sx > 0 ? ord($sm_row[$sx - 1]) : 0;
                                                        switch ($sm_ftype) {
                                                            case 1:
                                                                $sm_row[$sx] = chr(($sc + $slft) & 0xFF);
                                                                break;
                                                            case 2:
                                                                $sm_row[$sx] = chr(($sc + $sup) & 0xFF);
                                                                break;
                                                            case 3:
                                                                $sm_row[$sx] = chr(
                                                                    ($sc + intdiv($slft + $sup, 2)) & 0xFF
                                                                );
                                                                break;
                                                            case 4:
                                                                $spc   = $sx > 0 ? ord($sm_prev[$sx - 1]) : 0;
                                                                $sp    = $slft + $sup - $spc;
                                                                $spa   = abs($sp - $slft);
                                                                $spb   = abs($sp - $sup);
                                                                $spc_a = abs($sp - $spc);
                                                                $spaeth = ($spa <= $spb && $spa <= $spc_a)
                                                                    ? $slft : (($spb <= $spc_a) ? $sup : $spc);
                                                                $sm_row[$sx] = chr(($sc + $spaeth) & 0xFF);
                                                                break;
                                                            default:
                                                                $sm_row[$sx] = chr($sc);
                                                        }
                                                    }
                                                    $sm_out  .= $sm_row;
                                                    $sm_prev  = $sm_row;
                                                }
                                                $sm_data = $sm_out;
                                            }
                                            $smask_decoded = $sm_data;
                                        }
                                        unset($_sm_obj, $_sm_f, $_sm_s, $_sm_dp, $_sm_pp);
                                    }
                                }

                                // Build a data URI so the .htaccess-protected verimages/ dir stays non-HTTP-accessible.
                                $data_uri = '';
                                if (!file_exists($imgFile)) {
                                    if ($ext === 'jpg') {
                                        file_put_contents($imgFile, $decoded);
                                        $data_uri = 'data:image/jpeg;base64,' . base64_encode($decoded);
                                    } else {
                                        $im = imagecreatetruecolor($width, $height);
                                        // Enable alpha so the SMask can be written as transparency.
                                        imagealphablending($im, false);
                                        imagesavealpha($im, true);
                                        $idx = 0;

                                        for ($y = 0; $y < $height; $y++) {
                                            for ($x = 0; $x < $width; $x++) {
                                                if ($colorspace === 'IndexedRGB' || $colorspace === 'DeviceRGB') {
                                                    $r = ord($decoded[$idx++]);
                                                    $g = ord($decoded[$idx++]);
                                                    $b = ord($decoded[$idx++]);
                                                } elseif ($colorspace === 'DeviceGray') {
                                                    $g = ord($decoded[$idx++]);
                                                    $r = $b = $g;
                                                } else { // CMYK
                                                    $c = ord($decoded[$idx++]) / 255;
                                                    $m = ord($decoded[$idx++]) / 255;
                                                    $yC = ord($decoded[$idx++]) / 255;
                                                    $k = ord($decoded[$idx++]) / 255;
                                                    $r = (int)(255 * (1 - min(1, $c + $k)));
                                                    $g = (int)(255 * (1 - min(1, $m + $k)));
                                                    $b = (int)(255 * (1 - min(1, $yC + $k)));
                                                }
                                                if ($invert) {
                                                    $r = 255 - $r;
                                                    $g = 255 - $g;
                                                    $b = 255 - $b;
                                                }
                                                // SMask: 0=transparent, 255=opaque.
                                                // GD alpha: 0=opaque, 127=transparent.
                                                if ($smask_decoded !== null) {
                                                    $sm_byte  = ord($smask_decoded[$y * $width + $x] ?? "\xff");
                                                    $gd_alpha = (int)((255 - $sm_byte) / 2); // truncate: max 127 when sm_byte=0
                                                } else {
                                                    $gd_alpha = 0; // fully opaque
                                                }
                                                imagesetpixel(
                                                    $im,
                                                    $x,
                                                    $y,
                                                    imagecolorallocatealpha($im, $r, $g, $b, $gd_alpha)
                                                );
                                            }
                                        }
                                        imagepng($im, $imgFile, 9);
                                        imagedestroy($im);
                                        $raw_png = file_get_contents($imgFile);
                                        if ($raw_png !== false) {
                                            $data_uri = 'data:image/png;base64,' . base64_encode($raw_png);
                                        }
                                    }
                                } else {
                                    // Already cached — read from disk for the data URI.
                                    $cached = file_get_contents($imgFile);
                                    if ($cached !== false) {
                                        $mime_type = $ext === 'jpg' ? 'image/jpeg' : 'image/png';
                                        $data_uri  = "data:{$mime_type};base64," . base64_encode($cached);
                                    }
                                }

                                // --- Build HTML content ---
                                // Image is embedded as a data URI — the verimages/ directory
                                // is HTTP-blocked by .htaccess so no public URL is used.
                                $html  = "<div style='margin:10px 0; padding:8px; border:1px solid #ccc'>";

                                $html .= "<div style='font-size:10px;color:#666;margin-bottom:4px'>";
                                $html .= "{$colorspace} | {$width}×{$height} | {$channels}ch";
                                $html .= "</div>";

                                // --- SUSPECT FOUND LABEL (RESTORED) ---
                                $html .= "<div style='margin-bottom:4px'>";
                                $html .= "<b>Suspect Found:</b> " . esc_html((string)$check_hash);
                                $html .= "</div>";

                                // --- STATUS AREA ---
                                $html .= "<div class='img-status' style='margin-bottom:6px'>";
                                if ($is_allowed) {
                                    $html .= "<span style='color:green;font-weight:bold'>"
                                           . "Suspect is determined as usual.</span>";
                                } else {
                                    $html .= "<span style='color:red;font-weight:bold'>Visual mismatch detected</span>";
                                    $html .= "<div style='margin-top:6px;padding:6px;"
                                           . "background:#fff0f0;border:1px solid #f99;"
                                           . "font-size:11px;font-family:monospace'>";
                                    $html .= "<b>Why flagged:</b><br>";
                                    $html .= "Hash method: " . esc_html($hash_method_label ?? '') . "<br>";
                                    $html .= "Computed hash: <b>{$check_hash}</b><br>";
                                    $pool  = $use_exact_hashes ? $exact_image_hashes : $allowed_hashes;
                                    $html .= "Allowed hashes in seal (" . count($pool) . "):<br>";
                                    foreach ($pool as $ah) {
                                        $html .= "&nbsp;&nbsp;" . esc_html($ah) . "<br>";
                                    }
                                    $html .= "</div>";
                                }
                                $html .= "</div>";

                                // --- IMAGE ---
                                if ($data_uri !== '') {
                                    $html .= "<img src='" . esc_attr($data_uri) . "'"
                                           . " style='max-width:100%; height:auto;"
                                           . " border:1px solid #999; display:block; margin-bottom:6px'>";
                                } else {
                                    $html .= "<p style='color:orange'>[Image could not be rendered]</p>";
                                }

                                // --- IMAGE INFO (now BELOW image) ---
                                $html .= "<div style='margin:5px 0; padding:5px;"
                                       . " border:1px solid #666; background:#f9f9f9; font-size:10px'>";
                                $html .= "Image ID: <b>{$img_id}</b><br>";
                                $html .= "Colorspace: {$colorspace}<br>";
                                $html .= "Width × Height: {$width}×{$height}<br>";
                                $html .= "Decoded length: " . strlen($decoded) . " bytes";
                                $html .= "</div>";

                                $html .= "</div>";

                                // --- Fill slot ---
                                self::fillImageSlot($uid, $html);
                            }

                                    // --- Recurse if Form XObject ---
                            $xObjDict     = [];
                            $is_form_xobj = str_contains($fullObj, '/Subtype /Form')
                                && preg_match('/\/XObject\s*<<(.+?)>>/is', $fullObj, $xObjDict);
                            if ($is_form_xobj) {
                                preg_match_all('/\/[A-Za-z0-9]+\s+(\d+\s+0\s+R)/', $xObjDict[1], $refs);
                                if (!empty($refs[1])) {
                                    foreach ($refs[1] as $ref) {
                                        $objRefNum = preg_replace('/\s0 R/', '', $ref);
                                        // Guard against circular /XObject references (A -> B -> A) exhausting the stack.
                                        if (isset($visited[$objRefNum])) {
                                            continue;
                                        }
                                        $pattern = '/' . preg_quote($objRefNum, '/') . '\s0\sobj(.*?)endobj/s';
                                        if (preg_match($pattern, $pdf_raw, $refObj)) {
                                            $scanXObjects($refObj[0], $objRefNum, $visited + [$objRefNum => true]);
                                        }
                                    }
                                }
                            }

                                    $offset = $obj_end + 6;
                        }

                        if (!$found && !$parentName) {
                            echo "[XObject Scan] No XObjects found.\n";
                        }
                    };

                    $scanXObjects($pdf_raw);

                    // --- Final visual classification ---
                    $contains_background_images = false; // retained for downstream verdict compat
                    $image_missmatch            = false;

                    foreach (self::$image_slots as $slot) {
                        if ((int)$slot['allowed'] !== 1) {
                            $image_missmatch = true;
                        }
                    }
                }

                echo "</div>"; // close image section foldable content
                echo "</div>"; // close image section wrapper


                /* ─────────────────────── CONTENT STREAM INTEGRITY ─────────────────────── */

                $allowed_content_hashes = $rebuilt_payload['content_streams'] ?? [];
                $content_stream_mismatch = false;

                self::setProgress(__('Checking content streams…', 'formfabricator'), 87);

                $cs_section_id  = 'fabricator-pdf-content-streams-' . $uid_prefix;
                $cs_section_sec = 'fabricator-pdf-section-streams-' . esc_attr($uid_prefix);
                echo "<div class='fabricator-pdf-detail-section' id='{$cs_section_sec}'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($cs_section_id) . "'>Content Streams</button>";
                $cs_badge_id = 'fabricator-pdf-badge-streams-' . esc_attr($uid_prefix);
                echo "<span id='{$cs_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($cs_section_id) . "' class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";

                if (empty($allowed_content_hashes)) {
                    echo "<p class='fabricator-pdf-empty-state'>No content stream hashes in seal "
                       . "(PDF generated before this feature was added).</p>";
                } else {
                    // Extract all page content streams from the PDF (exclude seal stream and binary streams)
                    $pdf_content_hashes = [];
                    $cs_offset = 0;
                    while (true) {
                        $cs_pos  = strpos($pdf_raw, "stream\r\n", $cs_offset);
                        $cs_pos2 = strpos($pdf_raw, "stream\n", $cs_offset);
                        if ($cs_pos === false && $cs_pos2 === false) {
                            break;
                        }
                        if ($cs_pos === false) {
                            $cs_pos = $cs_pos2;
                        } elseif ($cs_pos2 !== false && $cs_pos2 < $cs_pos) {
                            $cs_pos = $cs_pos2;
                        }

                        $cs_eol  = (substr($pdf_raw, $cs_pos + 6, 2) === "\r\n") ? 2 : 1;
                        $cs_bs   = $cs_pos + 6 + $cs_eol;
                        $cs_be   = strpos($pdf_raw, 'endstream', $cs_bs);
                        if ($cs_be === false) {
                            $cs_offset = $cs_pos + 7;
                            continue;
                        }

                        $cs_body = substr($pdf_raw, $cs_bs, $cs_be - $cs_bs);
                        $cs_dec  = (strlen($cs_body) <= 67108864) ? @gzuncompress($cs_body) : false;
                        if ($cs_dec === false) {
                            $cs_dec = (strlen($cs_body) <= 67108864) ? @gzinflate(substr($cs_body, 2)) : false;
                        }
                        if ($cs_dec !== false && strlen($cs_dec) > 67108864) {
                            $cs_dec = false; // decompression bomb: discard
                        }
                        // Fall back to raw bytes for uncompressed streams — prevents
                        // uncompressed injected streams from being silently skipped.
                        $cs_check = $cs_dec !== false ? $cs_dec : $cs_body;

                        if (self::isPageContentStream($cs_check)) {
                            // Skip the seal stream (differs between Pass 1 and Pass 2).
                            $is_seal = str_contains($cs_check, '---BEGIN-SEAL---')
                                    || str_contains($cs_check, "\x00-\x00-\x00-\x00B\x00E\x00G\x00I\x00N");
                            if (!$is_seal) {
                                $pdf_content_hashes[] = hash('sha256', $cs_check);
                            }
                        }

                        $cs_offset = $cs_be + 9;
                    }

                    $seal_hash_set = array_flip($allowed_content_hashes);

                    $n_seal = count($allowed_content_hashes);
                    $n_pdf  = count($pdf_content_hashes);
                    echo "<p class='fabricator-pdf-hash-summary'>"
                       . "{$n_seal} stream(s) in seal &nbsp;·&nbsp; {$n_pdf} verifiable in PDF</p>";
                    echo "<div class='fabricator-pdf-hash-list'>";
                    foreach ($pdf_content_hashes as $pdf_hash) {
                        $short = esc_html(substr($pdf_hash, 0, 20));
                        if (isset($seal_hash_set[$pdf_hash])) {
                            echo "<div class='fabricator-pdf-hash-row fabricator-pdf-hash-row--pass'>"
                               . "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>MATCH</span>"
                               . "<code>{$short}…</code></div>";
                        } else {
                            $content_stream_mismatch = true;
                            echo "<div class='fabricator-pdf-hash-row fabricator-pdf-hash-row--fail'>"
                               . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>UNRECOGNISED</span>"
                               . "<code>{$short}…</code>"
                               . "<span style='font-size:11px;color:#721c24;'>not in seal</span></div>";
                        }
                    }
                    echo "</div>";
                    if (!$content_stream_mismatch) {
                        echo "<p style='margin-top:8px;'>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>All streams accounted for</span></p>";
                    }
                }

                echo "</div>"; // close content streams foldable content
                echo "</div>"; // close content streams section wrapper

                /* ─────────────────────── GENERIC TYPE PROCESSING ──────────────────────── */

                self::setProgress(__('Checking fonts…', 'formfabricator'), 94);

                // ── Fonts section
                // ─────────────────────────────────────────────────────
                $fonts_section_id  = 'fabricator-pdf-content-fonts-' . $uid_prefix;
                $fonts_section_sec = 'fabricator-pdf-section-fonts-' . esc_attr($uid_prefix);
                echo "<div class='fabricator-pdf-detail-section' id='{$fonts_section_sec}'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($fonts_section_id) . "'>Fonts</button>";
                $fonts_badge_id = 'fabricator-pdf-badge-fonts-' . esc_attr($uid_prefix);
                echo "<span id='{$fonts_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($fonts_section_id) . "'"
                   . " class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";

                if (preg_match_all('/\/Type\s*\/(\w+)/', $pdf_raw, $matches, PREG_SET_ORDER)) {
                    $verified_fonts = null;

                    foreach ($matches as $m) {
                        $type = $m[1];

                        if ($type !== 'Font') {
                            continue;
                        }

                        if ($verified_fonts === null) {
                            $verified_fonts = [
                                'used'       => [],
                                'missing'    => [],
                                'unexpected' => [],
                            ];

                            $allowed_fonts = $rebuilt_payload['fonts'] ?? [];

                            // --- 1a. Collect font references from /Resources ---
                            $font_refs = [];
                            if (preg_match_all('/\/Font\s*<<([\s\S]*?)>>/i', $pdf_raw, $blocks)) {
                                foreach ($blocks[1] as $block) {
                                    if (preg_match_all('/\/\w+\s+(\d+\s+\d+)\s+R/', $block, $m)) {
                                        foreach ($m[1] as $ref) {
                                            $font_refs[$ref] = true;
                                        }
                                    }
                                }
                            }

                            // --- 1b. Resolve font objects & extract BaseFont ---
                            foreach (array_keys($font_refs) as $ref) {
                                if (!preg_match('/' . preg_quote($ref, '/') . '\s+obj\s*<<(.*?)>>/is', $pdf_raw, $obj)) {
                                    continue;
                                }
                                if (!preg_match('/\/BaseFont\s*\/([^\s\/]+)/', $obj[1], $bf)) {
                                    continue;
                                }

                                // Normalize subset prefix
                                $font_name = preg_replace('/^[A-Z]{6}\+/', '', $bf[1]);
                                $verified_fonts['used'][$font_name] = true;
                            }

                            $used_fonts = array_keys($verified_fonts['used']);

                            // --- 1c. Compare ---
                            foreach ($used_fonts as $font) {
                                if (!in_array($font, $allowed_fonts, true)) {
                                    $verified_fonts['unexpected'][] = $font;
                                }
                            }

                            foreach ($allowed_fonts as $font) {
                                if (!in_array($font, $used_fonts, true)) {
                                    $verified_fonts['missing'][] = $font;
                                }
                            }

                            $all_font_names = array_unique(array_merge($allowed_fonts, $used_fonts));
                            sort($all_font_names);
                            echo "<div class='fabricator-pdf-hash-list'>";
                            foreach ($all_font_names as $font) {
                                $in_seal = in_array($font, $allowed_fonts, true);
                                $in_pdf  = in_array($font, $used_fonts, true);
                                if ($in_seal && $in_pdf) {
                                    $row_cls = 'fabricator-pdf-hash-row--pass';
                                    $pill    = "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>OK</span>";
                                } elseif (!$in_seal && $in_pdf) {
                                    $row_cls = 'fabricator-pdf-hash-row--fail';
                                    $pill    = "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>UNDECLARED</span>";
                                } else {
                                    $row_cls = 'fabricator-pdf-hash-row--warn';
                                    $pill    = "<span class='fabricator-pdf-pill fabricator-pdf-pill--warn'>UNUSED</span>";
                                }
                                echo "<div class='fabricator-pdf-hash-row {$row_cls}'>"
                                   . $pill
                                   . "<code>" . esc_html($font) . "</code>"
                                   . "</div>";
                            }
                            echo "</div>";

                            if ($verified_fonts['unexpected'] || $verified_fonts['missing']) {
                                $font_missmatch = true;
                            }
                        }
                    }
                }

                if ($font_prog_mismatch) {
                    echo "<p style='margin-top:8px;'>"
                       . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>Font binary programs do not match the seal</span></p>";
                }

                if (!$font_missmatch) {
                    echo "<p style='margin-top:8px;'>"
                       . "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>All fonts match the seal</span></p>";
                }

                echo "</div>"; // close fonts foldable content
                echo "</div>"; // close fonts section wrapper

                // ── PDF Objects section
                // ────────────────────────────────────────────────
                $objects_section_id  = 'fabricator-pdf-content-objects-' . $uid_prefix;
                $objects_section_sec = 'fabricator-pdf-section-objects-' . esc_attr($uid_prefix);
                echo "<div class='fabricator-pdf-detail-section' id='{$objects_section_sec}'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($objects_section_id) . "'>PDF Objects</button>";
                $objects_badge_id = 'fabricator-pdf-badge-objects-' . esc_attr($uid_prefix);
                echo "<span id='{$objects_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($objects_section_id) . "'"
                   . " class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";

                $unexpected_types = [];
                if (!empty($matches)) {
                    // Whitelist
                    $safe_types = [
                        'Pages', 'Catalog', 'ExtGState', 'Pattern',
                        'FontDescriptor', 'Group', 'XRef', 'ObjStm', 'Trailer',
                    ];
                    foreach ($matches as $m) {
                        $type = $m[1];
                        if (in_array($type, $safe_types, true)) {
                            continue;
                        }
                        if ($type === 'Page' && $pagecount_mismatch === false) {
                            continue;
                        }
                        if ($type === 'Annot' && $annotation_mismatch === false) {
                            continue;
                        }
                        if ($type === 'Font' && $font_missmatch === false) {
                            continue;
                        }
                        if ($type === 'XObject' && $image_missmatch === false) {
                            if (preg_match('/\/Subtype\s*\/Image/i', $pdf_raw)) {
                                continue;
                            }
                        }
                        $unexpected_types[] = $type;
                        $unexpected_detected = true;
                    }
                }

                if ($unexpected_detected) {
                    echo "<div class='fabricator-pdf-tag-list'>";
                    foreach (array_unique($unexpected_types) as $utype) {
                        echo "<span class='fabricator-pdf-tag'>" . esc_html($utype) . "</span>";
                    }
                    echo "</div>";
                } else {
                    echo "<p class='fabricator-pdf-empty-state'>No unexpected PDF objects detected.</p>";
                }

                echo "</div>"; // close objects foldable content
                echo "</div>"; // close objects section wrapper
            }

            // --- Collect inner detail HTML ---
            $inner_html = ob_get_clean();

            // --- Post-process: update badge classes based on computed booleans ---
            $bdg_pass = "' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>PASS";
            $bdg_fail = "' class='fabricator-pdf-detail-badge fabricator-pdf-badge-fail'>FAIL";
            $bdg_flip = static function (
                string &$html,
                string $id,
                string $pass,
                string $fail
            ): void {
                $html = str_replace("id='" . $id . $pass, "id='" . $id . $fail, $html);
            };

            if ($field_mismatch_count > 0) {
                $bdg_flip(
                    $inner_html,
                    'fabricator-pdf-badge-fields-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($annotation_fail_count > 0) {
                $bdg_flip(
                    $inner_html,
                    'fabricator-pdf-badge-annots-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($image_missmatch) {
                $bdg_flip(
                    $inner_html,
                    'fabricator-pdf-badge-images-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($font_missmatch) {
                $bdg_flip(
                    $inner_html,
                    'fabricator-pdf-badge-fonts-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($unexpected_detected) {
                $bdg_flip(
                    $inner_html,
                    'fabricator-pdf-badge-objects-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($content_stream_mismatch) {
                $bdg_flip(
                    $inner_html,
                    'fabricator-pdf-badge-streams-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }

            // --- PDF Metadata integrity check ---
            $meta_mismatch  = false;
            $sealed_meta    = $rebuilt_payload['pdf_meta'] ?? [];
            $meta_section_id = 'fabricator-pdf-content-meta-' . $uid_prefix;

            if (!empty($sealed_meta)) {
                // Helper: decode a PDF string value — plain ASCII or UTF-16BE (þÿ BOM).
                $decode_pdf_str = static function (string $raw): string {
                    // Strip surrounding parens if present
                    $raw = trim($raw);
                    if (str_starts_with($raw, '(') && str_ends_with($raw, ')')) {
                        $raw = substr($raw, 1, -1);
                    }
                    // UTF-16BE: starts with BOM \xFE\xFF
                    if (str_starts_with($raw, "\xFE\xFF")) {
                        $decoded = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
                        return $decoded !== false ? $decoded : $raw;
                    }
                    return $raw;
                };

                // Use the LAST definition of the /Info object, matching how a PDF viewer reads incremental updates.
                $pdf_meta_found = ['title' => '', 'author' => '', 'creator' => ''];
                if (preg_match('/\/Info\s+(\d+)\s+\d+\s+R/', $pdf_raw, $info_ref)) {
                    $obj_num      = $info_ref[1];
                    $info_pattern = '/' . preg_quote($obj_num, '/') . '\s+\d+\s+obj\s*<<(.*?)>>/s';
                    // preg_match_all + take last match = "last definition wins"
                    if (preg_match_all($info_pattern, $pdf_raw, $info_matches) && !empty($info_matches[1])) {
                        $info_dict = end($info_matches[1]);
                        foreach (['Title' => 'title', 'Author' => 'author', 'Creator' => 'creator'] as $key => $slot) {
                            if (preg_match('/\/' . $key . '\s*\(([^)]*)\)/', $info_dict, $vm)) {
                                $pdf_meta_found[$slot] = $decode_pdf_str('(' . $vm[1] . ')');
                            } elseif (preg_match('/\/' . $key . '\s*<([^>]*)>/', $info_dict, $vm)) {
                                $hex       = preg_replace('/\s+/', '', $vm[1]);
                                $raw_bytes = @hex2bin($hex);
                                $pdf_meta_found[$slot] = $raw_bytes !== false
                                    ? $decode_pdf_str($raw_bytes) : '';
                            }
                        }
                    }
                }

                ob_start();
                echo "<div class='fabricator-pdf-detail-section' id='fabricator-pdf-section-meta-" . esc_attr($uid_prefix) . "'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($meta_section_id) . "'>PDF Metadata</button>";
                echo "<span id='fabricator-pdf-badge-meta-" . esc_attr($uid_prefix) . "'"
                   . " class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($meta_section_id) . "' class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";
                echo "<div class='fabricator-pdf-hash-list'>";

                $meta_labels = ['title' => 'Title', 'author' => 'Author', 'creator' => 'Creator'];
                foreach ($meta_labels as $slot => $label) {
                    $expected = trim((string)($sealed_meta[$slot] ?? ''));
                    $actual   = trim((string)($pdf_meta_found[$slot] ?? ''));
                    $match    = ($expected === $actual);
                    if (!$match) {
                        $meta_mismatch = true;
                    }
                    $row_cls    = $match ? 'fabricator-pdf-hash-row--pass' : 'fabricator-pdf-hash-row--fail';
                    $pill_cls   = $match ? 'fabricator-pdf-pill--pass' : 'fabricator-pdf-pill--fail';
                    $pill_text  = $match ? esc_html__('MATCH', 'formfabricator') : esc_html__('MISMATCH', 'formfabricator');
                    echo "<div class='fabricator-pdf-hash-row {$row_cls}'>"
                       . "<span class='fabricator-pdf-pill {$pill_cls}'>{$pill_text}</span>"
                       . "<code>" . esc_html($label) . "</code>"
                       . "<span style='color:#50575e;font-size:11px;margin-left:4px;'>"
                       . esc_html($actual !== '' ? $actual : '(leer)')
                       . ($match ? '' : ' <em style="color:#d63638;">erwartet: ' . esc_html($expected) . '</em>')
                       . "</span>"
                       . "</div>";
                }

                echo "</div>"; // fabricator-pdf-hash-list
                echo "</div>"; // meta section content
                echo "</div>"; // meta section wrapper
                $meta_html = ob_get_clean();
                $inner_html = ($meta_html ?: '') . ($inner_html ?? '');
            }

            if ($meta_mismatch) {
                $meta_badge_pass = "id='fabricator-pdf-badge-meta-{$uid_prefix}'"
                    . " class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>PASS";
                $meta_badge_fail = "id='fabricator-pdf-badge-meta-{$uid_prefix}'"
                    . " class='fabricator-pdf-detail-badge fabricator-pdf-badge-fail'>FAIL";
                $inner_html = str_replace($meta_badge_pass, $meta_badge_fail, $inner_html ?? '');
            }

            // All-stream fingerprint check (Gap B catch-all): flag a live stream with no matching sealed
            // hash (injected content); a sealed hash missing from the live PDF is not flagged (mere removal).
            $all_stream_mismatch = false;
            if ($pdf_raw !== false) {
                $sealed_as_index = array_flip(
                    array_map('strval', (array) ($seal_data['all_stream_hashes'] ?? []))
                );
                foreach (self::hashAllCompressedStreams((string) $pdf_raw) as $h) {
                    if (!isset($sealed_as_index[$h])) {
                        $all_stream_mismatch = true;
                        break;
                    }
                }
            }

            // Final verdict: $seal_matches uses hash_equals() against a server-resolved key (never the
            // upload's own payload); structural-tamper flags are OR'd in on top.
            $visual_modified   = $visual_mismatch_found === true;
            $contains_background_images = (bool)($contains_background_images ?? false);
            $any_pdf_issue     = $incremental_update_detected || $multiple_seals_detected
                || $unexpected_detected || $annotation_mismatch || $pagecount_mismatch
                || $image_missmatch || $font_missmatch || $content_stream_mismatch
                || $meta_mismatch || $all_stream_mismatch;
            $document_modified = !$seal_matches || $visual_modified || $any_pdf_issue;

            // --- Summary panel ---
            echo self::renderSummaryPanel(
                [
                'seal_matches'               => $seal_matches,
                'seal_key_status'            => $seal_key_status  ?? 'active',
                'seal_compromised'           => $seal_compromised ?? false,
                'visual_modified'            => $visual_modified,
                'field_mismatch_count'       => $field_mismatch_count,
                'annotation_fail_count'      => $annotation_fail_count,
                'pagecount_mismatch'         => $pagecount_mismatch,
                'image_missmatch'            => $image_missmatch,
                'font_missmatch'             => $font_missmatch,
                'unexpected_detected'        => $unexpected_detected,
                'content_stream_mismatch'    => $content_stream_mismatch,
                'meta_mismatch'                  => $meta_mismatch,
                'incremental_update_detected'    => $incremental_update_detected,
                'incremental_update_eof_count'   => $incremental_update_eof_count ?? $eof_count ?? 1,
                'multiple_seals_detected'        => $multiple_seals_detected ?? false,
                'all_stream_mismatch'            => $all_stream_mismatch ?? false,
                'contains_background_images'     => $contains_background_images,
                'document_modified'          => $document_modified,
                'file_name'                  => $file_name,
                'uid_prefix'                 => $uid_prefix,
                'doc_nonce'                  => (string) ($seal_data['nonce'] ?? ''),
                ]
            );

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ob_get_clean() capture whose contents were already escaped at each echo() above.
            echo $inner_html;
        } catch (\Throwable $e) {
            while (ob_get_level() > $outer_ob_level) {
                ob_end_clean();
            }
            $raw_msg = $e->getMessage();
            \FabricatorForms\fabricator_log('FabricatorForms Verificationpage: ' . $raw_msg);
            // Map technical exception messages to user-friendly German.
            $fn           = esc_html($file_name);
            $friendly_msg = match (true) {
                str_contains($raw_msg, 'Parsing timed out')
                    => $fn . ': ' . __('PDF parsing took too long and was stopped for safety. Please try a smaller or simpler PDF.', 'formfabricator'),
                str_contains($raw_msg, 'Seal not found')
                    => $fn . ': ' . __('No FormFabricator seal found. Please only upload original documents.', 'formfabricator'),
                str_contains($raw_msg, 'Base64 decode')
                    => $fn . ': ' . __('The seal in the document is corrupted and cannot be read.', 'formfabricator'),
                str_contains($raw_msg, 'Seal is implausibly large')
                    => $fn . ': ' . __('The seal in the document is unusually large — file rejected.', 'formfabricator'),
                str_contains($raw_msg, 'Object list not found')
                    => $fn . ': ' . __('The PDF structure is invalid or the document is encrypted.', 'formfabricator'),
                str_contains($raw_msg, 'not a valid PDF')
                    => $fn . ': ' . __('The file is not a valid PDF document.', 'formfabricator'),
                str_contains($raw_msg, 'Indexed palette too short')
                    => $fn . ': ' . __('An embedded image in the document has invalid color data and could not be processed.', 'formfabricator'),
                default
                    => $fn . ': ' . __('The document could not be processed. See server log for details.', 'formfabricator'),
            };
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() + hardcoded strings; noticeHtml() also wp_kses_post()'s it.
            echo self::noticeHtml($friendly_msg, 'error');
        } finally {
            $pdf_content = ob_get_clean();
        }

        $segment_id      = sanitize_html_class($uid_prefix . '-segment');
        $legacy_statuses = ['rotated-legacy', 'compromised-legacy'];
        $is_legacy       = in_array($seal_key_status ?? '', $legacy_statuses, true);
        $is_compromised  = ($seal_compromised ?? false)
            || ($seal_key_status ?? '') === 'compromised-legacy';

        if ($document_modified === null) {
            $verdict_icon  = '';
            $verdict_label = __('Error', 'formfabricator');
            $border_color  = '#b32d2e';
            $badge_bg      = '#b32d2e';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        } elseif ($document_modified) {
            $verdict_icon  = 'fa-solid fa-triangle-exclamation';
            $verdict_label = __('Modified', 'formfabricator');
            $border_color  = '#d63638';
            $badge_bg      = '#d63638';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        } elseif ($is_compromised) {
            $verdict_icon  = 'fa-solid fa-triangle-exclamation';
            $verdict_label = __('Compromised Key', 'formfabricator');
            $border_color  = '#d97706';
            $badge_bg      = '#d97706';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        } elseif (($seal_key_status ?? 'active') !== 'active') {
            $verdict_icon  = 'fa-solid fa-rotate-left';
            $verdict_label = __('Rotated Key', 'formfabricator');
            $border_color  = '#65a30d';
            $badge_bg      = '#65a30d';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        } else {
            $verdict_icon  = 'fa-solid fa-check';
            $verdict_label = __('Authentic', 'formfabricator');
            $border_color  = '#00a32a';
            $badge_bg      = '#00a32a';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        }

        $hdr_wrap_style = 'border:2px solid ' . esc_attr($border_color)
            . ';border-left-width:5px;margin:15px 0;border-radius:4px;overflow:hidden;';
        $hdr_btn_style  = 'display:grid;grid-template-columns:1fr auto 1fr;align-items:center;'
            . 'width:100%;padding:10px 14px;background:' . esc_attr($hdr_bg)
            . ';border:none;border-bottom:1px solid #dcdcde;cursor:pointer;font-size:13px;'
            . 'gap:12px;box-sizing:border-box;';
        $hdr_name_style = 'grid-column:2;font-weight:600;color:#1d2327;overflow:hidden;'
            . 'text-overflow:ellipsis;white-space:nowrap;text-align:center;';

        $badge_base_style = 'font-weight:700;font-size:12px;padding:3px 10px;border-radius:3px;'
            . 'letter-spacing:.5px;white-space:nowrap;';
        $verdict_icon_html = $verdict_icon
            ? '<i class="' . esc_attr($verdict_icon) . '" aria-hidden="true"></i> '
            : '';
        $verdict_badge = '<span class="fabricator-pdf-pdf-hdr-verdict" style="background:'
            . esc_attr($badge_bg) . ';color:' . esc_attr($badge_text) . ';' . $badge_base_style . '">'
            . $verdict_icon_html . esc_html($verdict_label) . '</span>';

        if ($is_legacy) {
            $legacy_badge = '<span class="fabricator-pdf-verdict-legacy" style="background:#1a56db;color:#fff;'
                . $badge_base_style . '">Legacy</span>';
            $hdr_right = '<span style="grid-column:3;justify-self:end;display:flex;gap:6px;'
                . 'align-items:center;">' . $verdict_badge . $legacy_badge . '</span>';
        } else {
            $hdr_right = '<span style="grid-column:3;justify-self:end;flex-shrink:0;">'
                . $verdict_badge . '</span>';
        }

        echo "<div style='" . $hdr_wrap_style . "'>";
        echo "<button type='button' class='fabricator-pdf-toggle fabricator-pdf-pdf-hdr'"
            . " data-target='" . esc_attr($segment_id) . "'"
            . " style='" . $hdr_btn_style . "'>";
        echo "<span></span>";
        echo "<span class='fabricator-pdf-pdf-hdr-name' style='" . $hdr_name_style . "'>"
            . esc_html($file_name) . "</span>";
        echo $hdr_right;
        echo "</button>";
        echo "<div id='" . esc_attr($segment_id) . "' class='fabricator-pdf-hidden' style='padding:10px;'>";
        echo $pdf_content;
        echo "</div>";
        echo "</div>";
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }


    /**
     * Scans raw PDF bytes for the FF seal marker without loading the full object graph.
     *
     * @param string $path Absolute filesystem path to the PDF file.
     * @return bool True if the seal marker is found, false otherwise.
     */
    private static function rawPdfHasSeal(string $path): bool
    {
        // Must read the FULL file — large embedded images push page content streams
        // to the beginning of the file, well outside any 2MB tail window.
        $raw = @file_get_contents($path);
        if ($raw === false) {
            \FabricatorForms\fabricator_log('FabricatorForms rawPdfHasSeal: file_get_contents() failed for ' . basename($path) . '.');
            return false;
        }

        // Find every stream…endstream block.
        preg_match_all('/<<([^>]*)>>\s*stream\r?\n([\s\S]*?)\nendstream/m', $raw, $blocks, PREG_SET_ORDER);

        // Tracks streams skipped by the decompression-bomb guard, so "no seal found" can be traced to its cause.
        $skipped_oversized_streams = 0;
        $skipped_failed_decompress = 0;

        foreach ($blocks as $block) {
            $dict   = $block[1];
            $stream = $block[2];

            // Only bother decompressing FlateDecode streams.
            if (!preg_match('/\/Filter\s*\/FlateDecode/', $dict)) {
                continue;
            }

            // Refuse to decompress streams that would expand beyond 64 MB —
            // a crafted FlateDecode bomb could expand 50 MB of compressed data to GBs.
            if (strlen($stream) > 67108864) {
                $skipped_oversized_streams++;
                continue;
            }
            $dec = @gzuncompress($stream);
            if ($dec === false) {
                $dec = @gzinflate($stream);
            }
            if ($dec === false || strlen($dec) < 32 || strlen($dec) > 67108864) {
                $skipped_failed_decompress++;
                continue;
            }

            // Try even and odd byte alignments of the 2-byte Unicode pairs.
            for ($start = 0; $start <= 1; $start++) {
                $ascii = '';
                for ($i = $start; $i + 1 < strlen($dec); $i += 2) {
                    if ($dec[$i] === "\x00") {
                        $ascii .= $dec[$i + 1];
                    }
                }
                if (str_contains($ascii, '---BEGIN-SEAL---')) {
                    return true;
                }
            }

            // Also check plain ASCII (some streams are not Unicode-paired).
            if (str_contains($dec, '---BEGIN-SEAL---')) {
                return true;
            }
        }

        if ($skipped_oversized_streams > 0 || $skipped_failed_decompress > 0) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms rawPdfHasSeal: no seal found in ' . basename($path) . ' — '
                . $skipped_oversized_streams . ' FlateDecode stream(s) skipped for exceeding the 64MB decompression-bomb guard, '
                . $skipped_failed_decompress . ' stream(s) skipped for failing to decompress or decompressing outside plausible bounds. '
                . 'If this document is legitimate, the seal may be inside one of the skipped streams.'
            );
        }

        return false;
    }

    /**
     * Determines whether a decoded stream body is a PDF page content stream.
     *
     * @param string $decoded Decompressed stream bytes.
     * @return bool True if the stream looks like a page content stream.
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
        return (bool) preg_match('/\bBT\b|\bq\b|\bQ\b|\bcm\b|\bTf\b|\bTj\b|\bTd\b/', $decoded);
    }

    /**
     * Renders the verification summary panel HTML for a single PDF.
     *
     * @param array $d Associative array of verification result flags and metadata (seal_matches,
     * document_modified, uid_prefix, file_name, etc.).
     * @return string HTML for the summary panel.
     */
    private static function renderSummaryPanel(array $d): string
    {
        $pass     = '<span class="fabricator-pdf-chk-pass">&#10003;</span>';
        $fail     = '<span class="fabricator-pdf-chk-fail">&#10007;</span>';
        $warn     = '<span class="fabricator-pdf-chk-warn">&#9888;</span>';
        $rotated  = '<span class="fabricator-pdf-chk-rotated">&#8634;</span>';
        $caret    = '<span class="fabricator-pdf-row-caret">&#8250;</span>';

        $row = function (bool $ok, string $label, string $detail, string $target_id)
 use ($pass, $fail, $caret): string {
            $icon   = $ok ? $pass : $fail;
            $status = $ok
                ? '<span class="fabricator-pdf-row-ok">' . esc_html__('OK', 'formfabricator') . '</span>'
                : '<span class="fabricator-pdf-row-fail">' . esc_html($detail) . '</span>';
            return "<tr class='fabricator-pdf-toggle fabricator-pdf-summary-row' data-target='"
                . esc_attr($target_id) . "' title='" . esc_attr__('Show details', 'formfabricator') . "'>"
                . "<td>{$icon}</td>"
                . '<td>' . esc_html($label) . '</td>'
                . "<td class='fabricator-pdf-row-detail'>{$status}</td>"
                . "<td class='fabricator-pdf-row-caret-cell'>{$caret}</td>"
                . "</tr>\n";
        };

        $uid  = $d['uid_prefix'];
        $rows = '';

        if (!empty($d['incremental_update_detected'])) {
            $rows .= $row(
                false,
                __('PDF structure', 'formfabricator'),
                __('Incremental update detected', 'formfabricator'),
                'fabricator-pdf-content-structure-' . $uid
            );
        }

        if (!empty($d['multiple_seals_detected'])) {
            $rows .= $row(
                false,
                __('Seal integrity', 'formfabricator'),
                __('Extra seal block(s) injected — document has been tampered with', 'formfabricator'),
                'fabricator-pdf-content-seals-' . $uid
            );
        }

        $seal_detail = __('MISMATCH — document may have been tampered with', 'formfabricator');
        $rows .= $row($d['seal_matches'], __('Cryptographic seal (HMAC)', 'formfabricator'), $seal_detail, $uid . '-raw-content');

        if ($d['seal_matches']) {
            $key_status       = (string)($d['seal_key_status'] ?? 'active');
            $key_compromised  = !empty($d['seal_compromised']);
            if ($key_compromised) {
                $rows .= "<tr class='fabricator-pdf-summary-row'>"
                    . "<td>{$warn}</td>"
                    . "<td>" . esc_html__('Seal key', 'formfabricator') . "</td>"
                    . "<td class='fabricator-pdf-row-detail' colspan='2'>"
                    . "<span class='fabricator-pdf-row-warn'>"
                    . esc_html__('COMPROMISED — manual verification strongly recommended', 'formfabricator')
                    . "</span></td></tr>\n";
            } elseif (in_array($key_status, ['rotated-legacy', 'compromised-legacy'], true)) {
                $rows .= "<tr class='fabricator-pdf-summary-row'>"
                    . "<td>&#10003;</td>"
                    . "<td>" . esc_html__('Seal key', 'formfabricator') . "</td>"
                    . "<td class='fabricator-pdf-row-detail' colspan='2'>"
                    . "<span style='background:#1a56db;color:#fff;font-size:11px;font-weight:700;"
                    . "padding:2px 8px;border-radius:3px;letter-spacing:.4px;'>" . esc_html__('Legacy', 'formfabricator') . "</span>"
                    . "&nbsp;" . esc_html__('Manually imported key', 'formfabricator')
                    . "</td></tr>\n";
            } elseif ($key_status !== 'active') {
                $rows .= "<tr class='fabricator-pdf-summary-row'>"
                    . "<td>{$rotated}</td>"
                    . "<td>" . esc_html__('Seal key', 'formfabricator') . "</td>"
                    . "<td class='fabricator-pdf-row-detail' colspan='2'>"
                    . "<span class='fabricator-pdf-row-rotated'>"
                    . esc_html__('Signed with a rotated (older) key', 'formfabricator')
                    . "</span></td></tr>\n";
            }
        }

        $fields_ok = $d['field_mismatch_count'] === 0;
        $rows .= $row(
            $fields_ok,
            __('Visual field content', 'formfabricator'),
            // translators: %d: number of visible form fields whose content no longer matches the seal.
            sprintf(__('%d field(s) do not match the seal', 'formfabricator'), $d['field_mismatch_count']),
            'fabricator-pdf-content-fields-' . $uid
        );

        $annots_ok = $d['annotation_fail_count'] === 0;
        $rows .= $row(
            $annots_ok,
            __('Annotations', 'formfabricator'),
            // translators: %d: number of PDF annotations that don't match the seal.
            sprintf(__('%d annotation(s) unmatched', 'formfabricator'), $d['annotation_fail_count']),
            'fabricator-pdf-content-annots-' . $uid
        );

        $rows .= $row(!$d['pagecount_mismatch'], __('Page count', 'formfabricator'), __('MISMATCH', 'formfabricator'), 'fabricator-pdf-content-pgcount-' . $uid);

        $rows .= $row(
            !$d['image_missmatch'],
            __('Image hashes', 'formfabricator'),
            __('MISMATCH — image content changed', 'formfabricator'),
            'fabricator-pdf-content-images-' . $uid
        );

        $cs_ok = !($d['content_stream_mismatch'] ?? false);
        $rows .= $row(
            $cs_ok,
            __('Page content integrity', 'formfabricator'),
            __('Page content was modified or added', 'formfabricator'),
            'fabricator-pdf-content-streams-' . $uid
        );

        $rows .= $row(
            !$d['font_missmatch'],
            __('Fonts', 'formfabricator'),
            __('Undeclared or missing fonts detected', 'formfabricator'),
            'fabricator-pdf-content-fonts-' . $uid
        );

        $rows .= $row(
            !$d['unexpected_detected'],
            __('PDF Objects', 'formfabricator'),
            __('Unexpected object types detected', 'formfabricator'),
            'fabricator-pdf-content-objects-' . $uid
        );

        if (isset($d['meta_mismatch'])) {
            $rows .= $row(
                !$d['meta_mismatch'],
                __('PDF Metadata', 'formfabricator'),
                __('Title/Author/Creator tampered', 'formfabricator'),
                'fabricator-pdf-content-meta-' . $uid
            );
        }

        if (!empty($d['all_stream_mismatch'])) {
            $rows .= $row(
                false,
                __('Stream fingerprint', 'formfabricator'),
                __('A compressed stream was added or modified', 'formfabricator'),
                'fabricator-pdf-content-streams-' . $uid
            );
        }

        $verdict_class = $d['document_modified'] ? 'fabricator-pdf-verdict-fail' : 'fabricator-pdf-verdict-pass';
        $verdict_text  = $d['document_modified']
            ? '&#10007; ' . esc_html($d['file_name']) . ' — ' . esc_html__('MODIFIED or INVALID', 'formfabricator')
            : '&#10003; ' . esc_html($d['file_name']) . ' — ' . esc_html__('Authentic', 'formfabricator');

        $nonce_html = '';
        if (!empty($d['doc_nonce'])) {
            $nonce_disp = esc_html((string) $d['doc_nonce']);
            $nonce_html = "<div class='fabricator-pdf-doc-id'>" . esc_html__('Document ID:', 'formfabricator') . " <code>{$nonce_disp}</code></div>";
        }

        return "<div class='fabricator-pdf-summary-panel'>"
            . "<div class='fabricator-pdf-summary-verdict {$verdict_class}'>{$verdict_text}</div>"
            . $nonce_html
            . "<table class='fabricator-pdf-summary-table'>"
            . "<thead><tr><th></th><th>" . esc_html__('Check', 'formfabricator') . "</th><th></th><th></th></tr></thead>"
            . "<tbody>{$rows}</tbody>"
            . "</table>"
            . "</div>";
    }


    /**
     * Reconstructs the canonical HMAC payload array from raw seal data.
     *
     * @param array $seal_data Decoded seal JSON as an associative array.
     * @return array Canonical payload ready for HMAC verification.
     */
    private static function rebuildPayload(array $seal_data): array
    {
        // Key order must exactly match Generator::$seal_data; key_id is omitted for pre-UUID PDFs to preserve the original HMAC input.
        $rebuilt = ['generated' => (string) ($seal_data['generated'] ?? '')];
        if (!empty($seal_data['key_id'])) {
            $rebuilt['key_id'] = (string) $seal_data['key_id'];
        }
        $rebuilt += [
            'nonce'            => (string) ($seal_data['nonce'] ?? ''),
            'form_id'          => (int) ($seal_data['form_id'] ?? 0),
            'form_name'        => trim((string) ($seal_data['form_name'] ?? '')),
            'fields'           => [],
            'uploads'          => [],
            'template'         => [],
            'fonts'            => [],
            'expected_pages'   => (int) ($seal_data['expected_pages'] ?? 0),
            'content_streams'  => [],
            'image_hashes'     => [],
            'font_prog_hashes'  => array_values(
                array_map('strval', (array) ($seal_data['font_prog_hashes'] ?? []))
            ),
            'all_stream_hashes' => array_values(
                array_map('strval', (array) ($seal_data['all_stream_hashes'] ?? []))
            ),
            'pdf_meta'          => (static function ($pdf_meta) {
                $pdf_meta = is_array($pdf_meta) ? $pdf_meta : [];
                return [
                    'title'   => (string)($pdf_meta['title']   ?? ''),
                    'author'  => (string)($pdf_meta['author']  ?? ''),
                    'creator' => (string)($pdf_meta['creator'] ?? ''),
                ];
            })($seal_data['pdf_meta'] ?? null),
        ];

        foreach ((array) ($seal_data['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $rebuilt['fields'][] = [
                'label' => trim((string) ($field['label'] ?? '')),
                'value' => self::normalizeValue($field['value'] ?? ''),
            ];
        }

        if (is_array($seal_data['uploads'] ?? null)) {
            foreach ($seal_data['uploads'] as $u) {
                if (!is_array($u)) {
                    continue;
                }
                $rebuilt['uploads'][] = [
                    'name'   => (string) ($u['name'] ?? ''),
                    'mime'   => (string) ($u['mime'] ?? ''),
                    'sha256' => (string) ($u['sha256'] ?? ''),
                ];
            }
        }

        if (is_array($seal_data['template'] ?? null)) {
            foreach ($seal_data['template'] as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $rebuilt['template'][] = [
                    'name'   => (string) ($t['name'] ?? ''),
                    'mime'   => (string) ($t['mime'] ?? ''),
                    'sha256' => (string) ($t['sha256'] ?? ''),
                ];
            }
        }

        if (is_array($seal_data['fonts'] ?? null)) {
            $rebuilt['fonts'] = array_values(array_map('strval', $seal_data['fonts']));
        }

        if (is_array($seal_data['content_streams'] ?? null)) {
            $rebuilt['content_streams'] = array_values(array_map('strval', $seal_data['content_streams']));
        }

        if (is_array($seal_data['image_hashes'] ?? null)) {
            $rebuilt['image_hashes'] = array_values(array_map('strval', $seal_data['image_hashes']));
        }

        return $rebuilt;
    }

    /**
     * Hashes every compressed (non-page-content) stream in a raw PDF for catch-all stream-injection detection.
     *
     * @param string $pdf_raw Raw PDF file bytes.
     * @return array Sorted array of SHA-256 hex strings.
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
            // Exclude page content streams — handled by content_streams and unstable
            // between PASS 1 (seal) and PASS 2 (final PDF) due to seal embedding.
            if (!self::isPageContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
            $offset = $be + 9;
        }
        sort($hashes);
        return $hashes;
    }

    /**
     * Extracts and hashes font program streams from FontDescriptor objects.
     *
     * @param string $pdf_raw Raw PDF file bytes.
     * @return array Sorted array of SHA-256 hex strings.
     */
    private static function hashFontProgramStreams(string $pdf_raw): array
    {
        $hashes   = [];
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
     * Normalizes a seal field value for comparison against PDF text.
     *
     * @param string $value Raw field value from the seal payload.
     * @return string Normalized plain-text value.
     */
    private static function normalizeValue(string $value): string
    {
        // Decode HTML entities
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Strip HTML tags
        $value = wp_strip_all_tags($value);

        // Normalize whitespace
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Recursively computes the differences between two associative arrays.
     *
     * @param array  $a    First array (seal payload).
     * @param array  $b    Second array (rebuilt payload).
     * @param string $path Dot-notation key path prefix for nested calls.
     * @return array List of difference description strings.
     */
    private static function diffArrays(array $a, array $b, string $path = ''): array
    {
        $diffs = [];
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));

        foreach ($keys as $key) {
            $currentPath = $path === '' ? $key : $path . '.' . $key;

            if (!array_key_exists($key, $a)) {
                $diffs[] = "Missing in A: {$currentPath}";
                continue;
            }

            if (!array_key_exists($key, $b)) {
                $diffs[] = "Missing in B: {$currentPath}";
                continue;
            }

            if (is_array($a[$key]) && is_array($b[$key])) {
                $diffs = array_merge($diffs, self::diffArrays($a[$key], $b[$key], $currentPath));
            } elseif (is_array($a[$key]) || is_array($b[$key])) {
                // Type mismatch between the two payloads — report without casting an array to string.
                $diffs[] = "Type mismatch at {$currentPath}\n"
                    . 'A: ' . wp_json_encode($a[$key])
                    . "\nB: " . wp_json_encode($b[$key]);
            } else {
                if ((string)$a[$key] !== (string)$b[$key]) {
                    $diffs[] = "Mismatch at {$currentPath}\n"
                        . 'A: ' . wp_json_encode($a[$key])
                        . "\nB: " . wp_json_encode($b[$key]);
                }
            }
        }

        return $diffs;
    }

    /**
     * Builds a styled notice card HTML string for pre-flight rejections.
     *
     * @param string $message Notice text (may contain safe HTML).
     * @param string $type    Card type: 'error', 'warning', 'success', or 'info'.
     * @return string HTML notice card markup.
     */
    private static function noticeHtml(string $message, string $type = 'error'): string
    {
        if ($type === 'error') {
            return sprintf(
                '<div class="fabricator-vpc fabricator-vpc--error">'
                . '<div class="fabricator-vpc__header">'
                . '<span class="fabricator-vpc__icon"><span class="dashicons dashicons-warning"></span></span>'
                . '</div>'
                . '<div class="fabricator-vpc__step">%s</div>'
                . '</div>',
                wp_kses_post($message)
            );
        }

        $class = match ($type) {
            'success' => 'notice notice-success',
            'warning' => 'notice notice-warning',
            'info'    => 'notice notice-info',
            default   => 'notice notice-error',
        };
        return sprintf(
            '<div class="%s is-dismissible"><p><strong>%s</strong></p></div>',
            esc_attr($class),
            wp_kses_post($message)
        );
    }

    /**
     * Reverses PNG predictor filtering (None/Sub/Up) on an indexed-color image stream, byte-by-byte.
     *
     * @param string $data  Raw (still-filtered) indexed image stream bytes.
     * @param int    $width Image width in pixels.
     * @param int    $bpc   Bits per component (typically 8 for indexed images).
     * @return string Decoded index-stream bytes with predictor removed.
     */
    private static function undoPngPredictorIndexed(
        string $data,
        int $width,
        int $bpc
    ): string {
        $rowBytes = (int)ceil($width * $bpc / 8);
        $out = '';
        $prev = str_repeat("\0", $rowBytes);
        $i = 0;

        while ($i < strlen($data)) {
            $filter = ord($data[$i++]);
            $row = substr($data, $i, $rowBytes);
            $i += $rowBytes;
            $row = str_pad($row, $rowBytes, "\0");

            for ($j = 0; $j < $rowBytes; $j++) {
                $cur = ord($row[$j]);
                $up  = ord($prev[$j]);

                switch ($filter) {
                    case 0: // None
                        break;
                    case 1: // Sub (byte-wise!)
                        $left = $j > 0 ? ord($row[$j - 1]) : 0;
                        $cur = ($cur + $left) & 0xFF;
                        break;
                    case 2: // Up
                        $cur = ($cur + $up) & 0xFF;
                        break;
                    default:
                        // Other PNG filters are illegal for PDF predictors
                        break;
                }

                $row[$j] = chr($cur);
            }

            $out .= $row;
            $prev = $row;
        }

        return $out;
    }

    /**
     * Emits an empty placeholder div for a PDF image XObject; JS later fills it with the rendered card.
     *
     * @param string $uid  Unique slot identifier used as the element ID.
     * @param array  $meta Image metadata (colorspace, width, height, img_id, decoded_len, allowed, file_path).
     */
    private static function emitImageSlot(string $uid, array $meta): void
    {

        self::$files_to_delete[] = $meta['file_path'];

        if (!self::$image_cleanup_registered) {
            self::$image_cleanup_registered = true;

            register_shutdown_function(
                static function () {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    // Delay removal so the browser can fetch images first; hand the unlink off to WP-Cron.
                    wp_schedule_single_event(time() + 120, 'fabricator_verifier_cleanup_files', [self::$files_to_delete]);
                }
            );
        }

        echo "<div id='" . esc_attr($uid) . "' class='img-slot'
            data-colorspace='" . esc_attr((string) ($meta['colorspace'] ?? '')) . "'
            data-width='" . (int) ($meta['width'] ?? 0) . "'
            data-height='" . (int) ($meta['height'] ?? 0) . "'
            data-imgid='" . esc_attr((string) ($meta['img_id'] ?? '')) . "'
            data-decodedlen='" . (int) ($meta['decoded_len'] ?? 0) . "'
            data-allowed='" . (int) ($meta['allowed'] ?? 0) . "'
        ></div>";
    }

    /**
     * Schedules a temp PDF file for deletion after the same 2100s window as its serving transient's TTL.
     * Keep this window in sync with that transient's TTL and set_time_limit() above if either changes.
     *
     * @param string $file_path Absolute path to the PDF file to delete.
     */
    private static function scheduleDeletion(string $file_path): void
    {
        self::$pdfs_to_delete[] = $file_path;

        if (!self::$pdf_cleanup_registered) {
            self::$pdf_cleanup_registered = true;

            register_shutdown_function(
                static function () {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    // Same rationale as emitImageSlot() above — offload the
                    // delayed unlink to WP-Cron instead of sleeping in-worker.
                    wp_schedule_single_event(time() + 2100, 'fabricator_verifier_cleanup_files', [self::$pdfs_to_delete]);
                }
            );
        }
    }

    /**
     * Fills a previously emitted image slot with rendered image card HTML.
     *
     * @param string $uid  Slot identifier matching the placeholder element ID.
     * @param string $html Rendered image card HTML to inject into the slot.
     */
    private static function fillImageSlot(string $uid, string $html): void
    {
        // Defensive: never emit empty content
        if ($html === '') {
            echo '<!-- FF: empty image slot content for ' . esc_html($uid) . ' -->';
            return;
        }

        // Wrap content so JS can relocate it safely
        echo sprintf(
            '<div class="img-slot-content" data-slot="%s">%s</div>',
            esc_attr($uid),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is pre-built, already-escaped HTML from handleUpload() (see the phpcs:disable block there for details).
            $html
        );
    }
}
