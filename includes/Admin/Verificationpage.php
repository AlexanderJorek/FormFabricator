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
 * @version   1.0.7
 * @link      https://github.com/AlexanderJorek/FormFabricator
 */

namespace FabricatorForms\Admin;

defined('ABSPATH') || exit;

use FabricatorForms\PDF\HashSeal;
use FabricatorForms\PDF\PdfUtils;

add_action('wp_ajax_fabricator_verify_push_lines', __NAMESPACE__ . '\\fabricator_ajax_verify_push_lines');

/**
 * AJAX: verifies one stored PDF against the text lines pdf.js extracted in the browser, and returns the rendered result.
 *
 * A named function rather than a closure, so other code can unhook or replace it.
 *
 * @return void
 */
function fabricator_ajax_verify_push_lines(): void
{
    /* ---- Capability + Nonce ---- */
    \FabricatorForms\Utils\AjaxGuard::require(
        'use_verifier',
        'fabricator_verifier_nonce',
        'nonce',
        __('Forbidden', 'formfabricator'),
        'FabricatorForms fabricator_verify_push_lines: rejected — user ' . get_current_user_id() . ' lacks use_verifier capability.'
    );

    // The page is still working through its batch: keep this user's waiting copies.
    \FabricatorForms\Utils\VerifierCleanup::refreshPending();

    /* ---- Raise non-memory limits for heavy PDF parsing (hard ceilings; soft budget aborts first) — placed after auth checks so an unverified request can't trigger it ---- */
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.IniSet.Risky -- resource-limit raise for heavy PDF parsing of uploaded files; the known whole-file lazy regexes are now linear scans, and the parse-time budget bounds the rest.
    if (ini_set('pcre.backtrack_limit', '268435456') === false) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: the host refused raising pcre.backtrack_limit; large PDFs may fail to parse.');
    }
    // 900 s: handleUpload()'s soft budget tops out at 600 s and is checked inside the long loops, so this only stops a
    // single call that never returns. (The safe_mode check that guarded this was dead: safe_mode left PHP in 5.4.)
    if (function_exists('set_time_limit')) {
        set_time_limit(900); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- hard ceiling; see comment above.
    }

    /* ---- Rate limit: bounds self-DoS from repeated raised-limit requests ---- */
    $rl_key = 'verify_' . get_current_user_id();
    if (\FabricatorForms\Utils\RateLimiter::increment($rl_key, 5) > 1) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rate-limited user ' . get_current_user_id() . '.');
        wp_send_json_error(['message' => __('Please wait before verifying another PDF.', 'formfabricator')], 429);
    }

    /* Memory reservation happens below, once the PDF's size is known — it's sized from the file. */

    /* ---- Input ---- */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
    $pdf_token   = sanitize_key($_POST['pdf_token'] ?? '');
    // Decoded raw, not through sanitize_textarea_field(): that stripped %xx sequences and anything shaped like a tag,
    // silently changing extracted PDF text lines that legitimately contain them. Each line is UTF-8-checked below
    // and is only ever compared with the seal or printed through esc_html().
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via AjaxGuard::require(); the sniff can't see through the static-method call.
    $visualLines = isset($_POST['visualLines'])
    // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified via AjaxGuard::require(), which the sniff can't see through; see comment above.
    ? json_decode(\FabricatorForms\Utils\Cast::stringOrDefault(wp_unslash($_POST['visualLines']), '[]'), true)
    : [];

    if (!$pdf_token) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — missing pdf_token (user ' . get_current_user_id() . ').');
        wp_send_json_error(['message' => __('Invalid input: missing token', 'formfabricator')], 400);
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
            // mb_strcut(): a byte cut through a multibyte character would fail the UTF-8 check below and drop the line.
            $line = mb_strcut($line, 0, 20000, 'UTF-8');
        }
        // pdf.js text extraction never yields invalid UTF-8; such a line is emptied rather than compared as raw bytes.
        $visualLines[$i] = wp_check_invalid_utf8($line);
    }

    /* ---- Resolve path from transient (avoids URL-to-path mapping) ---- */
    $pdf_transient = get_transient('fabricator_pdf_' . $pdf_token);
    if (!is_array($pdf_transient) || !isset($pdf_transient['path']) || !is_string($pdf_transient['path'])) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — token not found or expired (user ' . get_current_user_id() . ').');
        wp_send_json_error(['message' => __('PDF not found or token expired', 'formfabricator')], 404);
    }
    if ((int)($pdf_transient['uid'] ?? -1) !== get_current_user_id()) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — token owned by a different user than ' . get_current_user_id() . '.');
        wp_send_json_error(['message' => __('Forbidden', 'formfabricator')], 403);
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
        wp_send_json_error(['message' => __('Invalid PDF path', 'formfabricator')], 400);
    }

    /* ---- MIME re-validation on the server-side path ---- */
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $detected_mime = $finfo->file($real_target_path);
    if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: rejected — stored file MIME re-check failed, detected "' . $detected_mime . '".');
        wp_send_json_error(['message' => __('File is not a valid PDF', 'formfabricator')], 400);
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
            'message' => sprintf(
                /* translators: %1$s: file size in MB, %2$d: maximum accepted size in MB */
                __('PDF too large (%1$s MB). Maximum for verification is %2$d MB.', 'formfabricator'),
                round($file_size / 1048576, 1),
                (int) round(Verificationpage::MAX_PDF_BYTES / 1048576)
            ),
            ],
            400
        );
    }

    /* ---- Reserve memory now that the PDF's real size is known; shares MemoryBudget::BUCKET with public form submissions so both compete for the same RAM pool ---- */
    $fabricator_mem_estimate = \FabricatorForms\Utils\MemoryBudget::estimateBytes($file_size);
    $fabricator_mem_budget   = \FabricatorForms\Utils\MemoryBudget::budgetBytes();
    // Logs and answers a reservation that doesn't fit; wp_send_json_error() ends the request.
    $fabricator_refuse_memory = static function (int $needed) use ($fabricator_mem_budget): void {
        $fabricator_needed_mb = (int) round($needed / 1048576);
        $fabricator_budget_mb = (int) round($fabricator_mem_budget / 1048576);
        \FabricatorForms\fabricator_log(
            'FabricatorForms fabricator_verify_push_lines: rejected — memory budget unavailable (needed '
            . $fabricator_needed_mb . 'MB, budget ' . $fabricator_budget_mb . 'MB, '
            . (int) round(\FabricatorForms\Utils\MemoryBudget::reservedBytes() / 1048576) . 'MB already reserved).'
        );
        /* A job larger than the entire budget will never succeed no matter how long the user
           waits, so say that instead of inviting a pointless retry. */
        $fabricator_never_fits = $needed > $fabricator_mem_budget;
        wp_send_json_error(
            [
            'message'     => $fabricator_never_fits
                ? sprintf(
                    /* translators: %1$d: memory this PDF needs in MB, %2$d: the host's configured budget in MB. */
                    __('This PDF needs about %1$d MB to verify, more than this site\'s %2$d MB budget. Raise it with FABRICATOR_MEMORY_BUDGET_MB in wp-config.php.', 'formfabricator'),
                    $fabricator_needed_mb,
                    $fabricator_budget_mb
                )
                : __('Server busy verifying other PDFs right now.', 'formfabricator'),
            'code'        => $fabricator_never_fits ? 'too_large' : 'busy',
            'retry_after' => $fabricator_never_fits ? 0 : 8,
            ],
            429
        );
    };
    // Always a slot, however small the file: a check parses the whole PDF, so its cost doesn't follow the size the way a
    // submission's does, and without a slot every PDF up to 8 MB ran uncounted, so MAX_HOLDERS never limited them.
    $fabricator_mem_token = \FabricatorForms\Utils\MemoryBudget::reserve($fabricator_mem_estimate, 900, true);
    if ($fabricator_mem_token === false) {
        $fabricator_refuse_memory($fabricator_mem_estimate);
    }
    // Released on script end (covers wp_die() too); the row's TTL is the rare-case backstop. By reference, since a
    // larger reservation replaces this one below when the file's streams inflate to more than its size suggests.
    register_shutdown_function(
        static function () use (&$fabricator_mem_token): void {
            if (is_string($fabricator_mem_token)) {
                \FabricatorForms\Utils\MemoryBudget::releaseReservation($fabricator_mem_token);
            }
        }
    );
    // Not restored: this handler always ends the request via wp_send_json_*().
    \FabricatorForms\Utils\MemoryBudget::raiseTo($fabricator_mem_estimate);

    // The reader unpacks the file's Flate streams, so a small file can unpack to far more than its size (CWE-409). Count
    // what they inflate to, plus slack for a stream the reader delimits differently, and reserve it before anything is
    // unpacked; the reader stays within that allowance (GuardedRawDataParser).
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read of the stored copy checked above; wp_remote_get() is for remote URLs.
    $fabricator_inflated  = PdfUtils::inflatedStreamBytes((string) file_get_contents($real_target_path), $fabricator_mem_budget);
    $fabricator_allowance = $fabricator_inflated + max(16 * 1024 * 1024, intdiv($fabricator_inflated, 4));
    if ($fabricator_inflated > 0) {
        \FabricatorForms\Utils\MemoryBudget::releaseReservation($fabricator_mem_token);
        $fabricator_mem_estimate += $fabricator_allowance;
        $fabricator_mem_token     = \FabricatorForms\Utils\MemoryBudget::reserve($fabricator_mem_estimate, 900, true);
        if ($fabricator_mem_token === false) {
            $fabricator_refuse_memory($fabricator_mem_estimate);
        }
        \FabricatorForms\Utils\MemoryBudget::raiseTo($fabricator_mem_estimate);
    }

    // The check runs now, whatever its outcome, so its copy and the images taken from it go when this request ends
    // instead of waiting for the unused-copy cleanup.
    register_shutdown_function(
        static function () use ($real_target_path, $pdf_token): void {
            Verificationpage::discardCheckedCopy($real_target_path, $pdf_token);
        }
    );

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
        Verificationpage::handleUpload($file, $visualLines, $pdf_token, $fabricator_allowance);
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
        wp_send_json_error(['message' => __('PDF processing produced no output. Check the PHP error log.', 'formfabricator')], 500);
        return;
    }

    /* ---- SANITIZE OUTPUT (critical) ---- */
    try {
        $safe_html = fabricator_sanitize_verifier_html($raw_html);
    } catch (\Throwable $san_err) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_push_lines: fabricator_sanitize_verifier_html threw: ' . $san_err->getMessage());
        wp_send_json_error(['message' => __('Output sanitization failed. See server log for details.', 'formfabricator')], 500);
        return;
    }

    if ($safe_html === '') {
        \FabricatorForms\fabricator_log(
            'FabricatorForms fabricator_verify_push_lines: safe_html is empty after wp_kses (raw len='
            . strlen($raw_html) . ')'
        );
        // Fall back to escaping raw html if kses strips everything (e.g. encoding issue)
        $safe_html = '<p style="color:orange">' . esc_html__('Result was sanitized to empty. Check PHP error log.', 'formfabricator') . '</p>';
    }

    delete_transient('fabricator_vp_' . $pdf_token);

    wp_send_json_success(
        [
        'lines_received' => count($visualLines),
        // No 'pdf' key: it disclosed the random storage filename, and verification.js never read it.
        'html'           => $safe_html,
        ]
    );
}

/* ---- Progress polling endpoint ---- */
add_action('wp_ajax_fabricator_verify_progress', __NAMESPACE__ . '\\fabricator_ajax_verify_progress');

/**
 * AJAX: reports a running verification's progress to the user who started it.
 *
 * A named function rather than a closure, so other code can unhook or replace it.
 *
 * @return void
 */
function fabricator_ajax_verify_progress(): void
{
    // Capability-first, matching fabricator_verify_push_lines/fabricator_serve_pdf,
    // so this doesn't rely on nonce-then-capability ordering being
    // preserved if either check is edited independently later.
    if (!\FabricatorForms\Plugin::userCan('use_verifier')) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_progress: rejected — user ' . get_current_user_id() . ' lacks use_verifier capability.');
        wp_send_json_error([], 403);
    }
    check_ajax_referer('fabricator_verifier_nonce', 'nonce');
    // Polled throughout a check, so a batch keeps its waiting copies for as long as the page is open.
    \FabricatorForms\Utils\VerifierCleanup::refreshPending();
    $key  = sanitize_key($_POST['token'] ?? '');
    $data = $key ? get_transient('fabricator_vp_' . $key) : false;
    if ($data && (int)($data['uid'] ?? -1) !== get_current_user_id()) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_verify_progress: rejected — progress token owned by a different user than ' . get_current_user_id() . '.');
        wp_send_json_error(['message' => __('Forbidden', 'formfabricator')], 403);
    }
    if (is_array($data)) {
        unset($data['uid']);
    }
    wp_send_json_success($data ?: ['step' => '', 'pct' => 0]);
}

/* ---- Authenticated PDF file-serving endpoint ---- */
add_action('wp_ajax_fabricator_serve_pdf', __NAMESPACE__ . '\\fabricator_ajax_serve_pdf');

/**
 * AJAX: streams a stored verification PDF back to the user who uploaded it.
 *
 * A named function rather than a closure, so other code can unhook or replace it.
 *
 * @return void
 */
function fabricator_ajax_serve_pdf(): void
{
    if (!\FabricatorForms\Plugin::userCan('use_verifier')) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — user ' . get_current_user_id() . ' lacks use_verifier capability.');
        wp_die(esc_html__('Forbidden', 'formfabricator'), '', ['response' => 403]);
    }

    // Nonce/token are posted in the request body by verification.js (not query-string
    // params) so they don't end up in server logs, browser history, or a Referer header.
    if (!wp_verify_nonce(sanitize_key($_POST['nonce'] ?? ''), 'fabricator_verifier_nonce')) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — nonce verification failed (user ' . get_current_user_id() . ').');
        wp_die(esc_html__('Nonce verification failed', 'formfabricator'), '', ['response' => 403]);
    }

    $token = sanitize_key($_POST['token'] ?? '');
    if (!$token) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — missing token (user ' . get_current_user_id() . ').');
        wp_die(esc_html__('Missing token', 'formfabricator'), '', ['response' => 400]);
    }

    $pdf_transient = get_transient('fabricator_pdf_' . $token);
    $path = is_array($pdf_transient) ? ($pdf_transient['path'] ?? null) : null;
    if (!$path || !is_string($path) || !file_exists($path)) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — token not found, expired, or target file missing.');
        wp_die(esc_html__('PDF not found or token expired', 'formfabricator'), '', ['response' => 404]);
    }
    if ((int)($pdf_transient['uid'] ?? -1) !== get_current_user_id()) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — token owned by a different user than ' . get_current_user_id() . '.');
        wp_die(esc_html__('Forbidden', 'formfabricator'), '', ['response' => 403]);
    }

    // A download means the page is still working through its batch: keep this user's waiting copies.
    \FabricatorForms\Utils\VerifierCleanup::refreshPending();

    // Extra path-safety check
    $upload_dir   = wp_upload_dir();
    $safe_dir     = realpath($upload_dir['basedir'] . '/fabricator-secure-pdf');
    $real_path    = realpath($path);
    if (!$safe_dir || !$real_path || strpos($real_path, $safe_dir . DIRECTORY_SEPARATOR) !== 0) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — path-traversal guard failed for token-resolved path.');
        wp_die(esc_html__('Invalid path', 'formfabricator'), '', ['response' => 403]);
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $detected_mime = $finfo->file($real_path);
    if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
        \FabricatorForms\fabricator_log('FabricatorForms fabricator_serve_pdf: rejected — stored file MIME re-check failed, detected "' . $detected_mime . '".');
        wp_die(esc_html__('Not a PDF', 'formfabricator'), '', ['response' => 400]);
    }

    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    // The file is an untrusted upload served from the admin's own origin, so it is sandboxed: no scripts, no forms and
    // no same-origin access to this site. allow-same-origin is deliberately absent; allow-downloads keeps the browser
    // viewer's save button working. Browsers render PDFs from their own viewer, which this does not restrict.
    header('Content-Security-Policy: sandbox allow-downloads; default-src \'none\'; object-src \'none\'');
    header('Content-Disposition: inline; filename="verified.pdf"');
    header('Content-Length: ' . filesize($real_path));
    header('Cache-Control: no-store');
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams instead of buffering; verified PDFs can be up to 500MB (MAX_PDF_BYTES).
    readfile($real_path);
    exit;
}

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
        // span id: every section badge is addressed by id; without it they all rendered without one.
        'span'   => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
        'pre'    => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
        'code'   => ['class' => true, 'style' => true, 'data-*' => true],

        // Buttons / interactivity
        'button' => ['class' => true, 'type' => true, 'data-*' => true, 'style' => true],

        // Lists, tables and formatting carry the attributes the report actually uses (inline styles, the verdict icon's
        // Font Awesome class): with bare entries here, wp_kses() silently dropped them and the page no longer matched the code.
        'ul' => ['class' => true, 'style' => true], 'ol' => ['class' => true, 'style' => true], 'li' => ['class' => true, 'style' => true],

        // Tables
        'table' => ['class' => true, 'style' => true],
        'thead' => [], 'tbody' => [],
        'tr' => ['class' => true, 'style' => true, 'data-target' => true, 'title' => true],
        'th' => ['class' => true, 'style' => true, 'scope' => true],
        'td' => ['class' => true, 'style' => true, 'colspan' => true, 'rowspan' => true],

        // Formatting
        'strong' => ['class' => true, 'style' => true], 'em' => ['class' => true, 'style' => true], 'b' => ['style' => true],
        'i' => ['class' => true, 'style' => true, 'aria-hidden' => true], 'br' => [], 'hr' => [],

        // Images / SVG / media
        'img' => [
            'src' => true, 'alt' => true, 'class' => true, 'id' => true,
            'width' => true, 'height' => true, 'style' => true, 'data-*' => true,
        ],
        'svg' => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
        'canvas' => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
    ];

    /* wp_kses regex chokes on multi-MB base64 data URIs, so they're lifted out before kses runs and spliced back after. */
    $data_uris = [];
    $html = preg_replace_callback(
        '/\bsrc=(["\'])data:[^"\']+\1/i',
        static function (array $m) use (&$data_uris): string {
            $quote = $m[1];
            $value = substr($m[0], strlen('src=') + 1, -1);
            if (!preg_match('#^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/=]*$#', $value)) {
                \FabricatorForms\fabricator_log(
                    'FabricatorForms fabricator_sanitize_verifier_html: dropped a data: URI that'
                    . ' did not match the expected base64 image shape.'
                );
                return 'src=' . $quote . $quote;
            }
            $key = '__FABRICATOR_DATA_URI_' . count($data_uris) . '__';
            $data_uris[$key] = $m[0];
            return 'src=' . $quote . $key . $quote;
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
        /* Verifier cron callbacks are hooked in Plugin::init(), not here — register() only runs under is_admin(), which wp-cron.php never is. */
    }

    /**
     * Maximum accepted PDF size for verification, in bytes. 500MB gives headroom above a
     * default-config worst case (multiple upload fields, each admin-raisable past 10MB).
     *
     * @var int
     */
    public const MAX_PDF_BYTES = 500 * 1024 * 1024;

    /**
     * Converts an absolute path inside the plugin's upload dir to a relative one, for storing in wp_options.
     *
     * @param string $absolute Absolute path inside the plugin upload directory.
     * @return string Relative path, or '' when the path is outside that directory.
     */
    private static function relativeTempPath(string $absolute): string
    {
        $base = wp_upload_dir()['basedir'] . '/fabricator-secure-pdf';
        $norm = str_replace(chr(92), '/', $absolute);
        $base = rtrim(str_replace(chr(92), '/', $base), '/') . '/';
        if (strncmp($norm, $base, strlen($base)) !== 0) {
            return '';
        }
        return substr($norm, strlen($base));
    }

    /**
     * WP-Cron callback for delayed deletions queued before 1.0.7. Copies now go through discardCheckedCopy() and
     * Utils\VerifierCleanup; this stays so events still pending from an update run.
     *
     * @param array<int, string> $files Paths relative to the protected PDF folder.
     */
    public static function cronCleanupFiles(array $files): void
    {
        /* Relative paths: absolute ones in the cron option would disclose the filesystem layout to anything that can read options. */
        $safe_dir = realpath(wp_upload_dir()['basedir'] . '/fabricator-secure-pdf');
        if ($safe_dir === false) {
            return;
        }
        foreach ($files as $relative) {
            if (!is_string($relative) || $relative === '') {
                continue;
            }
            // Reject traversal outright rather than relying on realpath() alone (the file may
            // already be gone, in which case realpath() returns false and tells us nothing).
            if (str_contains($relative, '..') || preg_match('#^([A-Za-z]:)?[\\\\/]#', $relative)) {
                continue;
            }
            $file = $safe_dir . DIRECTORY_SEPARATOR . ltrim(str_replace('\\', '/', $relative), '/');
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
            $hook = add_submenu_page(
                'fabricator-forms',
                __('FormFabricator Verification', 'formfabricator'),
                __('PDF Verification', 'formfabricator'),
                \FabricatorForms\Plugin::ACCESS_CAP_PREFIX . 'use_verifier',
                'fabricator-pdf-verification',
                [self::class, 'render']
            );
            if ($hook) {
                add_action('load-' . $hook, [self::class, 'handleUploadPost']);
            }
        }
    }

    /**
     * Name prefix of the per-user transient that carries a processed upload across handleUploadPost()'s redirect.
     *
     * @var string
     */
    private const BATCH_TRANSIENT_PREFIX = 'fabricator_vbatch_';

    /**
     * Processes an upload on the page's load- hook, before any output, then redirects (Post/Redirect/Get).
     *
     * Uploads used to be handled inside render(), so reloading or navigating back after a verification sent the PDFs
     * again, reprocessed them and used up the user's rate limit. The prepared queue and notices survive the redirect in
     * a short per-user transient, which render() reads once.
     *
     * @return void
     */
    public static function handleUploadPost(): void
    {
        $is_request_post = strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET'))) === 'POST';
        if (!$is_request_post) {
            return;
        }
        // This handles file uploads to disk, so it re-checks explicitly rather than relying solely on the page's capability.
        if (!\FabricatorForms\Plugin::userCan('use_verifier')) {
            \FabricatorForms\fabricator_log('FabricatorForms Verificationpage::handleUploadPost: rejected — user ' . get_current_user_id() . ' lacks use_verifier capability.');
            wp_die(esc_html__('Insufficient permissions.', 'formfabricator'), '', ['response' => 403]);
        }
        // Nonce is the unconditional first gate — before touching any $_FILES.
        if (!isset($_POST['fabricator_verifier_nonce'])
            || !check_admin_referer('fabricator_verifier_upload', 'fabricator_verifier_nonce')
        ) {
            \FabricatorForms\fabricator_log('FabricatorForms Verificationpage::handleUploadPost: rejected — nonce verification failed (user ' . get_current_user_id() . ').');
            wp_die(esc_html__('Security check failed', 'formfabricator'), 'Error', ['response' => 403]);
        }

        // Collected here, shown by render() after the redirect.
        $notices            = [];
        $verification_queue = [];
        $stored_tokens      = [];
        if (!empty($_FILES['pdfs']['name'][0])) {
            // Process uploaded files
            $upload_dir = wp_upload_dir();
            $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';

            // Ensure directories exist with restricted permissions.
            // No '/log': nothing ever writes there, and an empty directory in the uploads folder is one more thing to guard.
            \FabricatorForms\Utils\SecureDir::harden($safe_dir, array_map(static fn($sub) => $safe_dir . $sub, ['', '/verfiles']));

            $verfiles_dir = $safe_dir . '/verfiles';

            $max_upload_bytes = self::MAX_PDF_BYTES;

            // Neither unslashed nor text-sanitized: wp_magic_quotes() never slashes $_FILES, and wp_unslash() stripped the
            // backslashes out of Windows tmp paths, so every upload then failed is_readable() below. Each path is gated by
            // is_uploaded_file() before use.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see comment above.
            $uploaded_tmp_names = isset($_FILES['pdfs']['tmp_name']) && is_array($_FILES['pdfs']['tmp_name'])
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see comment above.
                ? array_filter($_FILES['pdfs']['tmp_name'], 'is_string')
                : [];

            // Mirror UploadField's client-hint logic (includes/Fields/UploadField.php) — cap
            // to the server's actual max_file_uploads ini limit rather than an arbitrary number.
            $max_files = max(1, (int)(ini_get('max_file_uploads') ?: 20));
            if (count($uploaded_tmp_names) > $max_files) {
                \FabricatorForms\fabricator_log(
                    'FabricatorForms Verificationpage::handleUploadPost: batch upload truncated — '
                    . count($uploaded_tmp_names) . ' files submitted, max_file_uploads limit is ' . $max_files . '.'
                );
                $notices[] = [
                    // translators: %d: maximum number of files accepted per upload.
                    sprintf(esc_html__('Too many files selected. Only the first %d will be processed.', 'formfabricator'), $max_files),
                    'warning',
                ];
                $uploaded_tmp_names = array_slice($uploaded_tmp_names, 0, $max_files, true);
            }

            foreach ($uploaded_tmp_names as $key => $tmpName) {
                $original_name = isset($_FILES['pdfs']['name'][$key]) && is_string($_FILES['pdfs']['name'][$key])
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_FILES is never slashed; see the tmp_name comment above.
                    ? sanitize_file_name($_FILES['pdfs']['name'][$key])
                    : '(unknown)';

                if (!is_readable($tmpName) || !is_uploaded_file($tmpName)) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::handleUploadPost: upload skipped for "' . $original_name
                        . '" — failed is_uploaded_file()/is_readable() check (possible spoofed or malformed multipart entry).'
                    );
                    $notices[] = [
                        // translators: %s: uploaded file name.
                        sprintf(esc_html__('Upload skipped: "%s" could not be read from the upload.', 'formfabricator'), esc_html($original_name)),
                        'warning',
                    ];
                    continue;
                }

                // File size guard — use the actual file on disk, not the browser-reported size.
                if (filesize($tmpName) > $max_upload_bytes) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::handleUploadPost: upload skipped for "' . $original_name . '" — '
                        . round(filesize($tmpName) / 1048576, 1) . 'MB exceeds ' . round($max_upload_bytes / 1048576) . 'MB limit.'
                    );
                    $notices[] = [
                        // translators: %d: maximum accepted file size in MB.
                        sprintf(esc_html__('Upload skipped: file exceeds %d MB limit.', 'formfabricator'), (int) round($max_upload_bytes / 1048576)),
                        'warning',
                    ];
                    continue;
                }

                $type_check = wp_check_filetype_and_ext(
                    $tmpName,
                    $original_name,
                    ['pdf' => 'application/pdf']
                );

                if (($type_check['ext'] ?? '') !== 'pdf') {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::handleUploadPost: upload skipped for "' . $original_name
                        . '" — wp_check_filetype_and_ext() did not resolve to pdf (ext: '
                        . ($type_check['ext'] ?? '(none)') . ', type: ' . ($type_check['type'] ?? '(none)') . ').'
                    );
                    $notices[] = [__('Upload skipped: only PDF files are allowed.', 'formfabricator'), 'warning'];
                    continue;
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detected_mime = $finfo->file($tmpName);
                if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::handleUploadPost: upload skipped for "' . $original_name
                        . '" — finfo MIME re-check detected "' . $detected_mime . '" instead of application/pdf.'
                    );
                    $notices[] = [__('Upload skipped: MIME validation failed.', 'formfabricator'), 'warning'];
                    continue;
                }

                $safe_name = sanitize_file_name($original_name);

                if ($safe_name === '' || !preg_match('/\.pdf$/i', $safe_name)) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms Verificationpage::handleUploadPost: upload skipped — filename sanitized to "'
                        . $safe_name . '" from original "' . $original_name . '", not a valid .pdf name.'
                    );
                    $notices[] = [__('Upload skipped: invalid PDF filename.', 'formfabricator'), 'warning'];
                    continue;
                }

                // Always use a unique random prefix — never rely on time() for collision avoidance.
                $storage_name = bin2hex(random_bytes(8)) . '-' . $safe_name;
                $target_path  = $verfiles_dir . '/' . $storage_name;

                /* ACCEPTED RISK: skips wp_handle_upload()'s filters — these are short-lived, HTTP-denied scratch copies, validated more strictly than the API default. */
                $moved = is_uploaded_file($tmpName) && copy($tmpName, $target_path);
                if ($moved) {
                    wp_delete_file($tmpName);

                    // Serving token. It expires with the copy after VerifierCleanup::UNUSED_TTL without use; the page's
                    // requests renew both while it works through the batch, and a finished check deletes both.
                    $token = bin2hex(random_bytes(16));
                    set_transient(
                        'fabricator_pdf_' . $token,
                        ['path' => $target_path, 'uid' => get_current_user_id()],
                        \FabricatorForms\Utils\VerifierCleanup::UNUSED_TTL
                    );
                    $stored_tokens[] = $token;

                    // nonce/token travel in the POST body (see verification.js) so they never land in server logs, history, or a Referer header.
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

        \FabricatorForms\Utils\VerifierCleanup::trackPending($stored_tokens);

        set_transient(
            self::BATCH_TRANSIENT_PREFIX . get_current_user_id(),
            ['queue' => $verification_queue, 'notices' => $notices],
            10 * MINUTE_IN_SECONDS
        );
        wp_safe_redirect(add_query_arg('verified', '1', admin_url('admin.php?page=fabricator-pdf-verification')));
        exit;
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
        // Notices collect here, not inside .fabricator-pdf-idle-card, so common.js's .wp-header-end placement works.
        \FabricatorForms\Utils\Assets::renderNoticeDock();
        echo '<div id="fabricator-verification-body">';

        // Uploads are processed by handleUploadPost() before any output; its outcome arrives here once, after the redirect.
        $batch_key = self::BATCH_TRANSIENT_PREFIX . get_current_user_id();
        $batch     = get_transient($batch_key);
        delete_transient($batch_key);
        $has_batch          = is_array($batch);
        $verification_queue = $has_batch && is_array($batch['queue'] ?? null) ? $batch['queue'] : [];
        foreach ($has_batch && is_array($batch['notices'] ?? null) ? $batch['notices'] : [] as $notice) {
            // Messages were escaped when they were built; noticeHtml() wp_kses_post()s them again.
            echo wp_kses_post(self::noticeHtml((string) ($notice[0] ?? ''), (string) ($notice[1] ?? 'warning')));
        }

        // Localized once for the whole batch; verification.js reads this on load to seed its queue.
        wp_localize_script('fabricator-verifier-data', 'FabricatorVerifierQueueData', $verification_queue);

        // --- Render drag-and-drop form with nonce ---
        // Verification page styles: assets/css/admin-verification.css (enqueued in Utils/Assets.php).

        echo '<form id="pdf-upload-form" method="post" enctype="multipart/form-data">';
        wp_nonce_field('fabricator_verifier_upload', 'fabricator_verifier_nonce');
        $idle_style   = $has_batch ? ' style="' . esc_attr('display:none') . '"' : '';
        $scanmore_cls = $has_batch ? ' class="' . esc_attr('fabricator-pdf-visible') . '"' : '';
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
    // Images extracted during this request's check, deleted with the checked copy by discardCheckedCopy().
    private static array $files_to_delete = [];
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
     * Resolves one object reference: from the file's index when the bytes are the whole file, by searching them otherwise.
     *
     * The XObject walk also runs over single objects' bytes when it follows a nested XObject; those are small, and no
     * index describes them, so they are still searched directly.
     *
     * @param array|null  $index From PdfUtils::objectDefinitionIndex() over $raw, or null when $raw is not the whole file.
     * @param string      $raw   Bytes to resolve the reference in.
     * @param string      $num   Object number (digits).
     * @param string|null $gen   Generation number (digits), or null for any.
     * @return array{header: string, body: string}|null
     */
    private static function definitionLookup(?array $index, string $raw, string $num, ?string $gen = null): ?array
    {
        return $index === null
            ? PdfUtils::lastObjectDefinition($raw, $num, $gen)
            : PdfUtils::definitionFromIndex($index, $raw, $num, $gen);
    }

    /**
     * Deepest a Form XObject may nest inside another for the walk to follow it, as pageContents() limits the page tree.
     * A document from this plugin nests none.
     *
     * @var int
     */
    private const MAX_XOBJECT_DEPTH = 32;

    /**
     * Most different /Type names distinctTypeNames() records; a document from this plugin uses about a dozen.
     *
     * @var int
     */
    private const MAX_TYPE_NAMES = 256;

    /**
     * Each /Type name in the file once, in order of first appearance, shaped as preg_match_all(…, PREG_SET_ORDER)
     * matches ([1] is the name) so the fonts and objects sections read it unchanged.
     *
     * Found with a cursor that only moves forward. preg_match_all() kept a match for every occurrence in the file,
     * several times its size, where the fonts check needs only "is there a Font" and the objects list is made unique
     * anyway. Distinct names are capped too, since each one is attacker-chosen text; past the cap a marker entry is
     * listed instead, which the objects section then reports as unexpected.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return array<int, array{1: string}>
     */
    private static function distinctTypeNames(string $pdf_raw): array
    {
        $names = [];
        $seen  = [];
        $at    = 0;
        while (preg_match('/\/Type\s*\/(\w+)/', $pdf_raw, $match, PREG_OFFSET_CAPTURE, $at) === 1) {
            $at   = $match[0][1] + strlen($match[0][0]);
            $name = $match[1][0];
            if (isset($seen[$name])) {
                continue;
            }
            if (count($seen) >= self::MAX_TYPE_NAMES) {
                $names[] = [1 => '(more than ' . self::MAX_TYPE_NAMES . ' different object types)'];
                break;
            }
            $seen[$name] = true;
            $names[]     = [1 => $name];
        }
        return $names;
    }

    /**
     * Yields [header, body] for each object, the pairs preg_match_all('/(\d+\s+\d+)\s+obj([\s\S]*?)endobj/s') produced.
     *
     * Identical because an object header ("N G obj") can never overlap an "endobj" marker: in a header, "obj" follows
     * whitespace, in "endobj" it follows "d". So walking the headers in order, skipping any that start inside the
     * previous object, and pairing each with the next "endobj" reproduces the regex's non-overlapping lazy matches;
     * the first header with no later "endobj" ends the walk, as the regex then found no further match either.
     *
     * One pair at a time, found with a cursor that only moves forward: the former preg_match_all() index of every
     * header, plus a copy of every body, measured several times the file's own size. See CLAUDE.md, "Scanning untrusted
     * PDF bytes".
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return \Generator<int, array{0: string, 1: string}>
     */
    private static function scanObjectBodies(string $pdf_raw): \Generator
    {
        $pos    = 0;
        $resume = 0;
        $seen   = 0;
        while (preg_match('/(\d+\s+\d+)\s+obj/', $pdf_raw, $hm, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $pos = $hm[0][1] + strlen($hm[0][0]);
            if ($hm[0][1] < $resume) {
                continue; // a header inside the previous object's body, which the regex had already consumed
            }
            // Backstop for PdfUtils::MAX_OBJECTS, which handleUpload() already checked before reading the file.
            if (++$seen > PdfUtils::MAX_OBJECTS) {
                throw new \LengthException('Too many objects in one PDF.');
            }
            $end = strpos($pdf_raw, 'endobj', $pos);
            if ($end === false) {
                return; // nothing closes this object, so nothing closes a later one either
            }
            yield [$hm[1][0], substr($pdf_raw, $pos, $end - $pos)];
            $resume = $end + 6;
            $pos    = $resume;
        }
    }

    /**
     * Processes a single uploaded PDF file and outputs verification results HTML.
     *
     * @param array  $file             Uploaded file data from $_FILES.
     * @param array  $visualLines      Lines of text extracted for visual display.
     * @param string $progressKey      Transient key for progress reporting.
     * @param int    $decode_allowance Most bytes the PDF reader may unpack, as the caller reserved memory for.
     */
    public static function handleUpload(array $file, array $visualLines = [], string $progressKey = '', int $decode_allowance = PHP_INT_MAX): void
    {
        /* EscapeOutput suppressions below are scoped per-block, not one blanket disable — vars are pre-escaped/regex-constrained at assignment, which WPCS can't follow. */
        self::$progressKey = $progressKey;
        // Soft time budget, scaled to file size, aborts well before the 900 s hard ceiling.
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
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml() runs $message through wp_kses_post() internally.
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see note atop handleUpload().
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
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
            return;
        }

        // --- Store visual lines if provided ---
        $upload_dir   = wp_upload_dir();
        $safe_dir     = $upload_dir['basedir'] . '/fabricator-secure-pdf';

        \FabricatorForms\Utils\SecureDir::harden($safe_dir, [$safe_dir]);

        // $visualLines is already available as a parameter — no disk round-trip needed.

        self::setProgress(__('Byte scan: searching for seal…', 'formfabricator'), 5);

        // Shadow-attack guard: a legit PDF has exactly one %%EOF; a second means objects were appended after the original xref table.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read of $_FILES tmp_name; wp_remote_get() (the sniff's suggestion) is for remote URLs, not this.
        $raw_for_guard = is_readable($file['tmp_name']) ? file_get_contents($file['tmp_name']) : false;
        if ($raw_for_guard === false) {
            \FabricatorForms\fabricator_log('FabricatorForms handleUpload: rejected "' . $file_name . '" — file_get_contents() failed reading the uploaded temp file.');
            // translators: %s: uploaded file name.
            $msg = sprintf(__('Could not read PDF file: %s.', 'formfabricator'), esc_html($file_name));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml() runs $message through wp_kses_post() internally.
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see note atop handleUpload().
            echo self::noticeHtml($msg, 'error');
            return;
        }
        $eof_count                    = substr_count($raw_for_guard, '%%EOF');
        $incremental_update_detected  = $eof_count > 1;
        $incremental_update_eof_count = $eof_count;
        // Catches seal-marker fakes injected into uncompressed streams or appended raw text; FlateDecode streams are handled by the pdfparser pass instead.
        $raw_plain_seal_count         = substr_count($raw_for_guard, '---BEGIN-SEAL---');
        // Refused before anything indexes the file: every object walk here, and pdfparser too, costs a fixed amount per
        // object, and a file of tiny objects cost many times its own size — a memory fatal instead of this refusal.
        $declared_objects             = PdfUtils::declaredObjectCount($raw_for_guard);
        unset($raw_for_guard);
        if ($declared_objects > PdfUtils::MAX_OBJECTS) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms handleUpload: rejected "' . $file_name . '" — ' . $declared_objects
                . ' object headers, more than the ' . PdfUtils::MAX_OBJECTS . ' a document from this plugin can have.'
            );
            // translators: %s: uploaded file name.
            $msg = sprintf(__('%s has far more parts than any document this plugin creates and was not read.', 'formfabricator'), esc_html($file_name));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml() runs $message through wp_kses_post() internally.
            echo self::noticeHtml($msg, 'error');
            return;
        }

        // Raw-byte preflight avoids calling pdfparser (and its memory overhead) entirely for PDFs with no fabricator seal.
        if (!self::rawPdfHasSeal($file['tmp_name'])) {
            // rawPdfHasSeal() itself logs details (skipped/oversized streams) when
            // relevant — this just records that this file was rejected at this gate.
            \FabricatorForms\fabricator_log('FabricatorForms handleUpload: rejected "' . $file_name . '" — raw-byte preflight found no seal marker.');
            // translators: %s: uploaded file name.
            $msg = sprintf(__('%s does not contain a fabricator-pdf seal and cannot be verified.', 'formfabricator'), esc_html($file_name)); // phpcs:ignore Generic.Files.LineLength
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml() wp_kses_post()'s
            // its $message argument internally.
            echo self::noticeHtml($msg, 'error');
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
            return;
        }

        self::setProgress(__('Parsing PDF…', 'formfabricator'), 10);

        $document_modified = null;
        /* Initialised here (not only where computed) so an early throw can't leave it undefined; false is the correct default. */
        $structural_tamper = false;
        $refused_streams   = [];
        $refused_unlisted  = 0;
        ob_start();
        $outer_ob_level = ob_get_level();
        try {
            // $incremental_update_detected is set before the try block — make it available inside.
            $incremental_update_detected = $incremental_update_detected ?? false;
            // Only what mPDF writes is unpacked, within what fabricator_verify_push_lines reserved memory for (CWE-409);
            // anything else stays packed and is listed under PDF Objects. Image data is dropped after parsing, since only
            // the text is read below.
            // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- hardcoded plugin path, not request-influenced.
            require_once FABRICATOR_FORMS_PATH . 'includes/PDF/GuardedRawDataParser.php';
            // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- hardcoded plugin path, not request-influenced.
            require_once FABRICATOR_FORMS_PATH . 'includes/PDF/GuardedPdfParser.php';
            $parser_config = new \Smalot\PdfParser\Config();
            $parser_config->setRetainImageContent(false);
            $parser           = new \FabricatorForms\PDF\GuardedPdfParser($parser_config, $decode_allowance);
            $pdf              = $parser->parseFile($file['tmp_name']);
            $refused_streams  = $parser->refusedStreams();
            $refused_unlisted = $parser->unlistedRefusedCount();
            $text = $pdf->getText();

            // Budget on decompressed text volume, not on-disk size — a small compressed PDF can still unpack to a huge string.
            $fabricator_parse_text_mb    = strlen($text) / 1048576;
            $fabricator_parse_max_seconds = max(
                $fabricator_parse_max_seconds,
                min(600, 30 + ($fabricator_parse_text_mb * 4))
            );

            // --- Extract Seal (exactly one allowed) ---
            // What the lazy seal regex found, from a linear scan (see PdfUtils::sealBlocks()).
            $seal_bodies = array_column(PdfUtils::sealBlocks($text), 2);
            $seal_count  = count($seal_bodies);
            if ($seal_count === 0) {
                throw new \RuntimeException("Seal not found in {$file_name}.");
            }
            // Multiple seal blocks: keep using the last one so other checks still run, but record the violation for the panel (also flags a fake seal injected into a plain uncompressed stream).
            $multiple_seals_detected = $seal_count > 1
                || ($raw_plain_seal_count ?? 0) > 0;

            $text_seal_b64_list = array_values(
                array_filter(
                    array_map('trim', $seal_bodies),
                    fn($s) => $s !== ''
                )
            );

            $seal_base64 = trim($multiple_seals_detected ? end($seal_bodies) : $seal_bodies[0]);

            self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'seal extraction');

            if (strlen($seal_base64) > 65536) {
                throw new \RuntimeException("Seal is implausibly large in {$file_name}.");
            }

            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the seal block read back out of the PDF (strict mode rejects malformed input). Not obfuscation.
            $seal_json = base64_decode($seal_base64, true);
            if ($seal_json === false || strlen($seal_json) < 2) {
                throw new \RuntimeException("Base64 decode of seal failed for {$file_name}.");
            }

            $seal_data = json_decode($seal_json, true, 512, JSON_THROW_ON_ERROR);
            /* JSON_THROW_ON_ERROR only guarantees well-formed JSON, not an object — reject scalars here or rebuildPayload(array) TypeErrors into a generic outer-catch failure. */
            if (!is_array($seal_data)) {
                throw new \RuntimeException("Seal for {$file_name} is not a JSON object.");
            }

            self::setProgress(__('Seal found — reconstructing payload…', 'formfabricator'), 25);

            // --- Rebuild payload ---
            $rebuilt_payload = self::rebuildPayload($seal_data);

            self::setProgress(__('HMAC check…', 'formfabricator'), 35);

            // --- HMAC check (early, before any output, so it's available for the summary) ---
            // Cast/default: a seal with no 'seal' member would otherwise TypeError into a generic outer-catch failure.
            $seal_result      = HashSeal::verify($rebuilt_payload, (string) ($seal_data['seal'] ?? ''));
            $seal_matches     = $seal_result['valid'];
            $seal_key_status  = $seal_result['key_status'];
            $seal_compromised = $seal_result['compromised'];

            // --- Seal vs rebuilt diff ---
            $original_payload = $seal_data;
            unset($original_payload['seal']);
            $diffs = self::diffArrays($original_payload, $rebuilt_payload);
            $seal_rebuilt_match = empty($diffs);

            /* ---- PDF RAW PREPARATION ---- */
            // Hoisted here because the font-program check below reads $pdf_raw — it used to stay unset until much later, so that check ran against undefined bytes and flagged every legitimate PDF.
            $pdf_raw = null;

            if (!empty($file['tmp_name']) && is_readable($file['tmp_name'])) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read of $_FILES tmp_name; wp_remote_get() (the sniff's suggestion) is for remote URLs, not this.
                $pdf_raw = file_get_contents($file['tmp_name']);
            } else {
                $pdf_raw = false;
            }
            // Every reference the checks below resolve reads from this, built in one pass over the file. Resolving each by
            // searching the whole file cost the number of references times the file, and that number is the uploader's.
            $object_index = PdfUtils::objectDefinitionIndex((string) $pdf_raw);

            // --- Font program integrity check (computed before the inner buffer so the fonts section can
            // display it) ---
            $font_prog_mismatch = false;
            $sealed_fp          = [];
            $live_fp            = [];
            if ($pdf_raw !== false) {
                $sealed_fp = array_values(array_map('strval', (array) ($seal_data['font_prog_hashes'] ?? [])));
                $live_fp   = PdfUtils::hashFontProgramStreams((string) $pdf_raw);
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
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
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
                // translators: %s: the literal PDF end-of-file marker "%%EOF".
                echo "<span class='fabricator-pdf-hash-label'>" . esc_html(sprintf(__('%s count', 'formfabricator'), '%%EOF')) . "</span>";
                // translators: %1$d: number of end-of-file markers found, %2$d: number expected.
                echo "<span class='fabricator-pdf-hash-value'>" . esc_html(sprintf(__('%1$d (expected: %2$d)', 'formfabricator'), $eof_n_disp, 1)) . "</span>";
                echo "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>" . esc_html__('FAIL', 'formfabricator') . "</span>";
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
            echo "<div class='fabricator-pdf-detail-section' id='fabricator-pdf-section-raw-" . esc_attr($uid_prefix) . "'>";
            echo "<div class='fabricator-pdf-detail-hdr'>";
            echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
               . " data-target='" . esc_attr($uid_prefix) . "-raw-content'>" . esc_html__('Raw Seal & Rebuilt Data', 'formfabricator') . "</button>";
            echo "<span class='fabricator-pdf-detail-badge fabricator-pdf-badge-info'>" . esc_html__('INFO', 'formfabricator') . "</span>";
            echo "</div>";
            $flags      = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
            $json_seal  = esc_html((string) wp_json_encode($seal_data, $flags));
            $json_built = esc_html((string) wp_json_encode($rebuilt_payload, $flags));

            /* Names the differing keys — the HMAC fails the whole payload with no clue which key differed. 'seal' is skipped: it's absent from the rebuild by design. */
            $seal_diff_keys = [];
            foreach (array_keys($seal_data) as $seal_key) {
                if ($seal_key === 'seal') {
                    continue;
                }
                if (!array_key_exists($seal_key, $rebuilt_payload)) {
                    $seal_diff_keys[] = $seal_key . ' (' . __('missing from rebuild', 'formfabricator') . ')';
                    continue;
                }
                if (wp_json_encode($seal_data[$seal_key]) !== wp_json_encode($rebuilt_payload[$seal_key])) {
                    $seal_diff_keys[] = $seal_key;
                }
            }
            foreach (array_keys($rebuilt_payload) as $built_key) {
                if (!array_key_exists($built_key, $seal_data)) {
                    $seal_diff_keys[] = $built_key . ' (' . __('added by rebuild', 'formfabricator') . ')';
                }
            }

            /* Classed, not inline-styled: an unstyled <p> here rendered flush against the left edge, since this region gets no padding from the surrounding container. */
            if ($seal_diff_keys !== []) {
                echo "<p class='fabricator-pdf-seal-diff fabricator-pdf-seal-diff--differs'><strong>"
                   . esc_html__('Keys differing between seal and rebuild:', 'formfabricator')
                   . "</strong> <code>" . esc_html(implode(', ', $seal_diff_keys)) . "</code></p>";
            } else {
                echo "<p class='fabricator-pdf-seal-diff fabricator-pdf-seal-diff--identical'>"
                   . esc_html__('Seal and rebuilt payload are byte-identical (excluding the seal hash itself).', 'formfabricator')
                   . "</p>";
            }

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
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
               . "' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>" . esc_html__('PASS', 'formfabricator') . "</span>";
            echo "</div>";
            echo "<div id='" . esc_attr($all_visual_id) . "' class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";
            echo "<div class='fabricator-pdf-cmp-list'>";

            // Payload fields are consumed strictly in order, so a cursor replaces the former in_array() scan over processed
            // indices; seen markers are a set. Both scans were quadratic in the field count.
            $field_keys        = is_array($fields) ? array_keys($fields) : [];
            $field_cursor      = 0;
            $processed_markers = [];

            do {
                $new_start_found = false;

                // The same spans the former lazy regex matched, found by a linear scan (see PdfUtils::fieldMarkerMatches()).
                $matches = PdfUtils::fieldMarkerMatches($normalized_pdf);
                if ($matches !== []) {
                    foreach ($matches as $match) {
                        $start_marker = $match[1];
                        $pdf_field_text = $normalize($match[2]);

                        // Skip already processed markers
                        if (isset($processed_markers[$start_marker])) {
                            continue;
                        }

                        // Next unprocessed field in the payload
                        $payload_index = $field_keys[$field_cursor] ?? null;

                        if ($payload_index === null) {
                            echo "<div class='fabricator-pdf-cmp-row fabricator-pdf-cmp-row--fail'>"
                               . "<div class='fabricator-pdf-cmp-header'>"
                               . "<span class='fabricator-pdf-cmp-label'>" . esc_html__('Unknown field', 'formfabricator') . "</span>"
                               . "<span class='fabricator-pdf-cmp-marker'>" . esc_html($start_marker) . "</span>"
                               . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>" . esc_html__('NOT IN SEAL', 'formfabricator') . "</span>"
                               . "</div></div>\n";
                            $processed_markers[$start_marker] = true;
                            $new_start_found = true;
                            continue;
                        }

                        $payload_field = $fields[$payload_index];
                        $expected_text = $normalize($payload_field['value'] ?? '');

                        // Remove all zero-width spaces, trim, and normalize whitespace again
                        $pdf_field_text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $pdf_field_text);
                        $expected_text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $expected_text);

                        $repair_missing_spaces_strict = function (string $pdf, string $canonical): ?string {
                            // Split into characters once: mb_substr($s, $i, 1) walks the string from its start on every call,
                            // which made this comparison quadratic in the field length for a crafted PDF.
                            $pdf_chars = mb_str_split($pdf);
                            $can_chars = mb_str_split($canonical);
                            $p = 0;
                            $c = 0;
                            $out = '';

                            $pdf_len = count($pdf_chars);
                            $can_len = count($can_chars);

                            while ($p < $pdf_len && $c < $can_len) {
                                $pdf_ch = $pdf_chars[$p];
                                $can_ch = $can_chars[$c];

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

                            // Canonical must be fully consumed, and so must the PDF text: tolerating trailing PDF text let a sealed
                            // "100" shown as "1000" match. Measured on the block and inline field layouts, a genuine field
                            // carries no text between its value and its end marker, so there is no layout bleed to allow for.
                            if (trim(implode('', array_slice($can_chars, $c))) !== '' || trim(implode('', array_slice($pdf_chars, $p))) !== '') {
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

                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
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
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
                            }
                        }

                        echo "</div>\n"; // fabricator-pdf-cmp-row

                        // Mark both as processed
                        $field_cursor++;
                        $processed_markers[$start_marker] = true;
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
                foreach (array_column(PdfUtils::sealBlocks($appended_raw), 2) as $rb64) {
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
                        $parse_err = __('The seal is implausibly large and was rejected.', 'formfabricator');
                    } else {
                        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the seal block read back out of the PDF (strict mode rejects malformed input). Not obfuscation.
                        $sj = base64_decode($sb64, true);
                        if ($sj === false) {
                            $parse_err = __('The seal could not be base64-decoded.', 'formfabricator');
                        } else {
                            $sd = json_decode($sj, true);
                            if (!is_array($sd)) {
                                $parse_err = __('The seal data is not valid JSON.', 'formfabricator');
                            } else {
                                try {
                                    $rp    = self::rebuildPayload($sd);
                                    $vr    = HashSeal::verify($rp, (string)($sd['seal'] ?? ''));
                                    $is_ok = $vr['valid'];
                                } catch (\Throwable $sve) {
                                    \FabricatorForms\fabricator_log('FabricatorForms Verificationpage: seal HMAC check threw: ' . $sve->getMessage());
                                    $is_ok     = false;
                                    $parse_err = __('The seal check could not be completed.', 'formfabricator');
                                }
                            }
                        }
                    }

                    $row_cls  = $is_ok ? 'fabricator-pdf-hash-row--pass' : 'fabricator-pdf-hash-row--fail';
                    $pill_cls = $is_ok ? 'fabricator-pdf-pill--pass'     : 'fabricator-pdf-pill--fail';
                    $pill_txt = $is_ok ? esc_html__('AUTHENTIC', 'formfabricator') : esc_html__('FORGED / INVALID', 'formfabricator');

                    $seal_row_style = 'flex-direction:column;align-items:flex-start;gap:6px;padding:10px 14px';
                    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
                    echo "<div class='fabricator-pdf-hash-row {$row_cls}' style='{$seal_row_style}'>";
                    echo "<div style='display:flex;align-items:center;gap:8px;width:100%'>";
                    // translators: %d: position of this seal among the seals found in the document.
                    echo "<strong style='flex:1'>" . esc_html(sprintf(__('Seal #%d', 'formfabricator'), (int) $seal_num)) . "</strong>";
                    echo "<span class='fabricator-pdf-pill {$pill_cls}'>{$pill_txt}</span>";
                    echo "</div>";

                    // Always show a truncated preview of the raw base64 between the markers.
                    $b64_preview = strlen($sb64) > 120
                        ? esc_html(substr($sb64, 0, 60)) . '…' . esc_html(substr($sb64, -30))
                        : esc_html($sb64);
                    echo "<div style='font-size:10px;color:#787c82;font-family:monospace;word-break:break-all'>";
                    echo esc_html__('Base64:', 'formfabricator') . ' ' . $b64_preview;
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
                           // translators: %1$s: form name, %2$s: form ID, both as recorded in the seal.
                           . "<td>" . sprintf(esc_html__('%1$s (ID: %2$s)', 'formfabricator'), $s_form, $s_id) . "</td></tr>";
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
            echo "<span id='{$annot_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>" . esc_html__('PASS', 'formfabricator') . "</span>";
                    // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
            // Cursor and marker set, as in the comparison loop above.
            $field_keys        = is_array($fields) ? array_keys($fields) : [];
            $field_cursor      = 0;
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

                    if (!isset($processed_markers[$current_marker])) {
                        // Next unprocessed payload field
                        $payload_index = $field_keys[$field_cursor] ?? null;

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

                            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
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
                            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
                            echo "</div></div>\n"; // fabricator-pdf-cmp-body + fabricator-pdf-cmp-row

                            $field_cursor++;
                        } else {
                            echo "<div class='fabricator-pdf-cmp-row fabricator-pdf-cmp-row--fail'>"
                               . "<div class='fabricator-pdf-cmp-header'>"
                               . "<span class='fabricator-pdf-cmp-label'>" . esc_html__('Unknown marker', 'formfabricator') . "</span>"
                               . "<span class='fabricator-pdf-cmp-marker'>" . esc_html($current_marker) . "</span>"
                               . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>" . esc_html__('NOT IN SEAL', 'formfabricator') . "</span>"
                               . "</div></div>\n";
                        }

                        $processed_markers[$current_marker] = true;
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
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- noticeHtml() runs $message through wp_kses_post() internally.
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see note atop handleUpload().
                echo self::noticeHtml($msg, 'error');
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
                // Same (header, body) pairs as the former /(\d+\s+\d+)\s+obj([\s\S]*?)endobj/s match, built linearly: that
                // lazy regex re-scanned to the end of the file from every header once "endobj" was missing, quadratic on a
                // crafted PDF. See scanObjectBodies() for why the result is identical.
                if ($pdf_raw !== false) {
                    foreach (self::scanObjectBodies((string) $pdf_raw) as [$objId, $rawDict]) {
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
                    // Object ids already listed, as a set: comparing every reference with the whole list was quadratic.
                    $known_annot_ids = [];
                    foreach ($annotations as $a) {
                        if (is_string($a['objId'] ?? null)) {
                            $known_annot_ids[$a['objId']] = true;
                        }
                    }
                    // One /Annots array at a time, rather than preg_match_all()'s copy of every array in the file. Each id
                    // listed here becomes an entry below, and ids need no object behind them, so they share the object ceiling.
                    $annots_at = 0;
                    while (preg_match('/\/Annots\s*\[((?:\d+\s+\d+\s+R\s*)+)\]/', $pdf_raw, $annotRef, PREG_OFFSET_CAPTURE, $annots_at) === 1) {
                        $annots_at = $annotRef[0][1] + strlen($annotRef[0][0]);
                        $refs      = $annotRef[1][0];
                        if (preg_match_all('/(\d+\s+\d+)\s+R/', $refs, $objMatches)) {
                            foreach ($objMatches[1] as $objId) {
                                if (!isset($known_annot_ids[$objId])) {
                                    if (count($known_annot_ids) >= PdfUtils::MAX_OBJECTS) {
                                        throw new \LengthException('Too many objects: annotation references.');
                                    }
                                    $known_annot_ids[$objId] = true;
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

                self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'object/annotation extraction');

                // --- 3) Foldable unified annotation report ---
                $all_annots_id = sanitize_html_class($uid_prefix . '-all-annots');
                echo "<div class='fabricator-pdf-subsection'>";
                $annot_btn_target = esc_attr($all_annots_id);
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
                echo "<button type='button' class='fabricator-pdf-subtoggle fabricator-pdf-toggle'"
                   . " data-target='{$annot_btn_target}'>"
                   . "<span class='fabricator-pdf-subtoggle__icon'>&#9656;</span> " . esc_html__('Annotation List', 'formfabricator')
                   . "</button>";
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
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

                                // Exact, not substring: this plugin's PDFs render no links (no <a> in any allowed tag list), so every
                                // annotation is foreign, and a substring match let one explain itself against any longer sealed
                                // value, such as a shortened URL against the sealed one.
                                if ($field_value === $content_to_match) {
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

                                    // Same exact comparison as above for values that spanned chunks.
                                    if (trim((string) $seal_text) === $content_to_match) {
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
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
                        echo "<div class='fabricator-pdf-cmp-row fabricator-pdf-cmp-row--{$row_state}'>";
                        echo "<div class='fabricator-pdf-cmp-header'>"
                           . "<span class='fabricator-pdf-cmp-label'>{$ann_label}</span>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--{$pill_state}'>{$pill_text}</span>"
                           . "</div>";
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
                // Counted, not collected: preg_match_all() kept a match for every page marker in the file just to count them.
                $object_page_count = 0;
                $page_at           = 0;
                while (preg_match('/\/Type\s*\/Page\b/', $pdf_raw, $page_match, PREG_OFFSET_CAPTURE, $page_at) === 1) {
                    $object_page_count++;
                    $page_at = $page_match[0][1] + strlen($page_match[0][0]);
                }
                $expected_pages = $rebuilt_payload['expected_pages'] ?? $object_page_count; // fallback

                if ($object_page_count !== $expected_pages) {
                    $pagecount_mismatch = true;
                    $unexpected_detected = true;
                }

                self::setProgress(__('Checking page count…', 'formfabricator'), 68);

                $page_box_id    = 'fabricator-pdf-content-pgcount-' . $uid_prefix;
                $pgcount_sec_id = 'fabricator-pdf-section-pgcount-' . esc_attr($uid_prefix);
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
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

                // Every image XObject must match a sealed exact stream hash.
                $exact_image_hashes = $rebuilt_payload['image_hashes'] ?? [];

                // IMAGE CHECK
                self::setProgress(__('Checking images…', 'formfabricator'), 75);

                $image_section_id  = 'fabricator-pdf-content-images-' . $uid_prefix;
                $image_section_sec = 'fabricator-pdf-section-images-' . esc_attr($uid_prefix);
                echo "<div class='fabricator-pdf-detail-section' id='{$image_section_sec}'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($image_section_id) . "'>" . esc_html__('Image Hashes', 'formfabricator') . "</button>";
                $img_badge_id = 'fabricator-pdf-badge-images-' . esc_attr($uid_prefix);
                echo "<span id='{$img_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>" . esc_html__('PASS', 'formfabricator') . "</span>";
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
                echo "</div>";
                echo "<div id='" . esc_attr($image_section_id) . "'"
                   . " class='fabricator-pdf-hidden fabricator-pdf-detail-content'"
                   . " style='background:#f4f4f4; padding:10px; border:1px solid #ddd;'>";

                if (str_contains($pdf_raw, '/XObject')) {
                    // Pre-collect SMask object numbers so they're skipped as standalone images (they're alpha channels).
                    // Collected as a set in one forward pass, not as preg_match_all()'s match for every reference. A reference
                    // costs ten bytes and needs no object behind it, so the set is held to PdfUtils::MAX_OBJECTS as well.
                    $smask_obj_nums = [];
                    $smask_at       = 0;
                    while (preg_match('/\/SMask\s+(\d+)\s+\d+\s+R/', $pdf_raw, $_sm, PREG_OFFSET_CAPTURE, $smask_at) === 1) {
                        $smask_at = $_sm[0][1] + strlen($_sm[0][0]);
                        $smask_obj_nums[$_sm[1][0]] = true;
                        if (count($smask_obj_nums) > PdfUtils::MAX_OBJECTS) {
                            throw new \LengthException('Too many objects: soft-mask references.');
                        }
                    }
                    unset($_sm);

                    // Ensure image output directory exists (HTTP-blocked); hoisted out of the recursive
                    // $scanXObjects closure to avoid repeating the same stat/write calls per image.
                    $upload_dir = wp_upload_dir();
                    $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';
                    $ver_dir    = $safe_dir . '/verimages';
                    // Per-request part of each image file name: named by the image hash alone, the file was predictable to
                    // whoever made the PDF, and fetchable on servers that ignore .htaccess. Within this request the hash
                    // still lets a repeated image reuse its file.
                    $verimage_prefix = bin2hex(random_bytes(12));

                            \FabricatorForms\Utils\SecureDir::harden($safe_dir, [$safe_dir, $ver_dir]);

                    // Bytes the nested scans have read so far, across the whole walk. A nested Form XObject is read from its
                    // own bytes, and $visited only stops a cycle along one path, so a file that names the same nested object
                    // many times over, layer after layer, multiplied the work with every layer.
                    $xobject_nested_bytes = 0;
                    $xobject_nested_limit = max(64 * 1024 * 1024, 4 * strlen((string) $pdf_raw));
                    $scanXObjects = function ($pdf_raw, $parentName = null, array $visited = [], ?array $index = null)
 use (&$scanXObjects, &$xobject_nested_bytes, $xobject_nested_limit, $rebuilt_payload, $smask_obj_nums, $exact_image_hashes, $safe_dir, $ver_dir, $verimage_prefix, $fabricator_parse_start, $fabricator_parse_max_seconds) {
                        $offset = 0;
                        $found  = false;
                        // One forward cursor per needle for this walk (PdfUtils::nextAt()): a plain strpos() per XObject
                        // searched to the end of the file whenever the needle was missing, which was quadratic.
                        $walk_cache = [];

                        while (($pos = strpos($pdf_raw, '/XObject', $offset)) !== false) {
                            $found = true;
                            /* Per-XObject checkpoint: this is the most expensive region (GD decode, per-pixel loops), so without it many under-cap images would only be bounded by the 900 s hard ceiling. */
                            self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'image XObject scan');

                            // A negative offset searches backwards from $pos in place; strrpos(substr()) copied the whole prefix
                            // for every XObject, quadratic in file size. Same result, including 0 when there is no newline.
                            $obj_start_line = $pos > 0 ? (strrpos($pdf_raw, "\n", $pos - strlen($pdf_raw) - 1) ?: 0) : 0;
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

                                    $obj_end = PdfUtils::nextAt($pdf_raw, 'endobj', $obj_start, $walk_cache);
                            if ($obj_end === false) {
                                $offset = $pos + 10;
                                continue;
                            }

                                    // The dictionary only, up to the "stream" keyword: every key read below lives there,
                                    // and the stream bytes are taken separately further down. Reading keys from the whole
                                    // object also read them out of image data — a large image filled with "/Filter /A"
                                    // gave hundreds of thousands of "filters" and a match list several times its size.
                                    $stream_pos = PdfUtils::nextAt($pdf_raw, 'stream', $obj_start, $walk_cache);
                                    $dict_end   = ($stream_pos !== false && $stream_pos < $obj_end) ? $stream_pos : $obj_end + 6;
                                    $objDict    = substr($pdf_raw, $obj_start, $dict_end - $obj_start);
                                    $isImage    = str_contains($objDict, '/Subtype /Image');

                                    // Extract filters
                                    preg_match_all('/\/Filter\s*\/([A-Za-z0-9]+)/i', $objDict, $fMatches);
                                    $filters = $fMatches[1] ?: [];

                                    // Extract width & height
                                    $width = $height = null;

                                    // Direct integer or float
                            if (preg_match('/\/Width\s+([0-9.]+)/', $objDict, $m)) {
                                $width = (int)round($m[1]);
                            }
                            if (preg_match('/\/Height\s+([0-9.]+)/', $objDict, $m)) {
                                $height = (int)round($m[1]);
                            }

                                    // Indirect reference (e.g. /Width 12 0 R)
                            $wh_pat = '/\/(Width|Height)\s+(\d+)\s+0\s+R/';
                            if ((!$width || !$height)
                                && preg_match_all($wh_pat, $objDict, $refs, PREG_SET_ORDER)
                            ) {
                                foreach ($refs as $r) {
                                    // Last definition, digit-bounded: object 12 must not resolve to an earlier "112 0 obj".
                                    $refObj = self::definitionLookup($index, $pdf_raw, $r[2], '0');
                                    if ($refObj !== null) {
                                        if ($r[1] === 'Width') {
                                            $width  = (int)trim($refObj['body']);
                                        }
                                        if ($r[1] === 'Height') {
                                            $height = (int)trim($refObj['body']);
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
                                    // $stream_pos was found above, where the dictionary ends.
                                    // Offset 0 when there is no "stream", exactly as strpos() read a false offset.
                                    $endstream_pos = PdfUtils::nextAt($pdf_raw, 'endstream', (int) $stream_pos, $walk_cache);
                                    $stream_data = '';
                                    $decoded = null;

                            if ($stream_pos !== false && $endstream_pos !== false) {
                                $stream_data = substr($pdf_raw, $stream_pos + 6, $endstream_pos - ($stream_pos + 6));
                                $stream_data = ltrim($stream_data, "\r\n");

                                $decoded = $stream_data;
                                if (in_array('FlateDecode', $filters, true)) {
                                    // Bounded while inflating, against a decompression-bomb stream.
                                    $try = PdfUtils::inflateWithin($stream_data);
                                    if (is_string($try)) {
                                        $decoded = $try;
                                    }
                                }
                            }

                                    $bytes    = strlen($decoded ?? '');
                                    $channels = 0;

                            if ($isImage) {
                                // Determine metadata
                                preg_match('/\/BitsPerComponent\s+(\d+)/', $objDict, $bpcMatch);
                                $bpc = (int)($bpcMatch[1] ?? 8);

                                preg_match('/\/ColorSpace\s*(\/[A-Za-z0-9]+|\[.+?\])/s', $objDict, $csMatch);
                                $csRaw = $csMatch[1] ?? '';
                                $colorspace = 'unknown';
                                $channels = 0;
                                $palette = null;

                                $isImageMask = str_contains($objDict, '/ImageMask true');

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
                                        $palObj = self::definitionLookup($index, $pdf_raw, (string) $paletteObjNum, '0');
                                        if ($palObj !== null) {
                                            // Extract palette filters
                                            preg_match_all('/\/Filter\s*\/([A-Za-z0-9]+)/', $palObj['body'], $pf);
                                            $palFilters = $pf[1] ?? [];

                                            if (preg_match('/stream\s*(.*?)\s*endstream/s', $palObj['body'], $palStream)) {
                                                $lookup = ltrim($palStream[1], "\r\n");

                                                // Decode palette stream — bounded against a decompression bomb.
                                                if (in_array('FlateDecode', $palFilters, true)) {
                                                    $try = PdfUtils::inflateWithin($lookup);
                                                    if (is_string($try)) {
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
                                    $failureReasons[] = __('The image stream could not be decoded.', 'formfabricator');
                                }
                                if ($dims_over_cap) {
                                    $failureReasons[] = sprintf(
                                        // translators: %d: verification size limit in megapixels.
                                        __('The image exceeds the %d-megapixel verification size limit for this image type and was skipped. This is a size cap, not evidence of tampering.', 'formfabricator'),
                                        (int) ($pixel_cap / 1_000_000)
                                    );
                                } elseif (!$width || !$height) {
                                    // translators: %1$s: image width, %2$s: image height, as read from the PDF.
                                    $failureReasons[] = sprintf(__('Invalid dimensions (%1$s×%2$s)', 'formfabricator'), (string) $width, (string) $height);
                                }
                                if ($isImageMask) {
                                    $failureReasons[] = __('The image is a mask.', 'formfabricator');
                                }
                                if ($channels <= 0) {
                                    // translators: %s: PDF colour space name, e.g. DeviceN.
                                    $failureReasons[] = sprintf(__('Unsupported colour space: %s', 'formfabricator'), (string) $colorspace);
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
                                    echo '<b>' . esc_html__('The image could not be recreated from its stream and counts as a mismatch.', 'formfabricator') . '</b><br>';
                                    echo "<ul style='margin:5px 0; padding-left:18px;'>";
                                    foreach ($failureReasons as $r) {
                                        echo "<li>" . esc_html($r) . "</li>";
                                    }
                                    echo "</ul>";

                                    echo "<div style='font-size:11px; color:#333;'>";
                                    echo '<b>' . esc_html__('Image metadata:', 'formfabricator') . '</b><br>';
                                    echo '• ' . esc_html__('Filters:', 'formfabricator') . ' ' . esc_html(implode(', ', $filters)) . "<br>";
                                    echo '• ' . esc_html__('Width x Height:', 'formfabricator') . ' ' . (int)$width . " × " . (int)$height . "<br>";
                                    echo '• ' . esc_html__('BitsPerComponent:', 'formfabricator') . ' ' . (int)$bpc . "<br>";
                                    echo '• ' . esc_html__('ColorSpace:', 'formfabricator') . ' ' . esc_html($colorspace) . "<br>";
                                    echo '• ' . esc_html__('Channels:', 'formfabricator') . ' ' . (int)$channels . "<br>";
                                    echo '• ' . esc_html__('ImageMask:', 'formfabricator') . ' ' . ($isImageMask ? 'true' : 'false') . "<br>";
                                    $dec_size = $decoded !== null
                                        // translators: %d: decoded image size in bytes.
                                        ? sprintf(_n('%d byte', '%d bytes', strlen($decoded), 'formfabricator'), strlen($decoded))
                                        : __('n/a', 'formfabricator');
                                    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see handleUpload() note.
                                    echo '• ' . esc_html__('Decoded size:', 'formfabricator') . ' ' . esc_html($dec_size) . '<br>';
                                    // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
                                    echo "</div></div>";

                                    $offset = $obj_end + 6;
                                    continue;
                                }
                            }

                            if ($isImage && $decoded !== null && $width && $height) {
                                // --- Image metadata ---
                                preg_match('/\/BitsPerComponent\s+(\d+)/', $objDict, $bpcMatch);
                                $bpc = (int)($bpcMatch[1] ?? 8);

                                // $palette is NOT reset here: the colour-space pass above already decoded it. Resetting it to []
                                // meant no indexed image was ever expanded, and the renderer below read three bytes per pixel from
                                // a one-byte-per-pixel buffer (a warning per pixel, and an authentic PDF could hit the time budget).
                                $colorspace = 'DeviceRGB'; // default
                                if (preg_match('/\/ColorSpace\s*\[\s*\/Indexed\s*\/([A-Za-z0-9]+)/', $objDict, $m)) {
                                    $colorspace = 'IndexedRGB';
                                    $baseSpace  = $m[1]; // usually DeviceRGB
                                    if ($baseSpace !== 'DeviceRGB') {
                                        // The palette decoder below always reads 3-byte RGB triples, so this image can't be
                                        // recreated. Reported here: the block that renders $failureReasons has already run.
                                        self::reportUnreadableImage(
                                            // translators: %s: PDF colour space name the palette is based on, e.g. DeviceCMYK.
                                            [sprintf(__('Unsupported base colour space for an indexed image: %s', 'formfabricator'), (string) $baseSpace)],
                                            $colorspace,
                                            $width,
                                            $height
                                        );
                                        $offset = $obj_end + 6;
                                        continue;
                                    }
                                } elseif (preg_match('/\/ColorSpace\s*\/([A-Za-z0-9]+)/', $objDict, $m)) {
                                    $colorspace = $m[1];
                                }

                                $isImageMask = str_contains($objDict, '/ImageMask true');

                                preg_match('/\/Decode\s*\[(.*?)\]/', $objDict, $decodeMatch);
                                $invert = isset($decodeMatch[1]) && trim($decodeMatch[1]) === '1 0';

                                preg_match('/\/DecodeParms\s*<<(.+?)>>/s', $objDict, $dpMatch);
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
                                    // translators: %s: PDF colour space name, e.g. DeviceN.
                                    $unsupported_space = sprintf(__('Unsupported colour space: %s', 'formfabricator'), (string) $colorspace);
                                    self::reportUnreadableImage(
                                        [$unsupported_space],
                                        $colorspace,
                                        $width,
                                        $height
                                    );
                                    $offset = $obj_end + 6;
                                    continue;
                                }

                                if ($width === null || $height === null) {
                                    self::reportUnreadableImage(
                                        [__('Implausible or missing image dimensions', 'formfabricator')],
                                        $colorspace,
                                        $width,
                                        $height
                                    );
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
                                        // Per-row checkpoint: one oversized image otherwise ran unbounded between the per-XObject checks.
                                        if (($y & 255) === 0) {
                                            self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'PNG predictor');
                                        }
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
                                        if (($y & 255) === 0) {
                                            self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'palette expansion');
                                        }
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

                                // Exact XObject hash: raw compressed bytes, identical across both generation passes.
                                $check_hash        = hash('sha256', $stream_data);
                                $hash_method_label = 'Exact XObject stream (sha256)';
                                $img_id            = $check_hash;

                                        $uid = 'img_' . uniqid();

                                        // Determine file extension
                                        $ext = in_array('DCTDecode', $filters, true) ? 'jpg' : 'png';
                                        $imgFile = $ver_dir . "/xobject_{$verimage_prefix}_{$check_hash}.{$ext}";

                                        // --- Emit empty slot FIRST (no logic) ---
                                // --- Prepare slot metadata ---
                                $is_allowed = in_array($check_hash, $exact_image_hashes, true);

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
                                    // Kept so the sealed list can be compared as a MULTISET below,
                                    // not merely as "is this one known?".
                                    'hash' => $check_hash,
                                    'colorspace' => $colorspace,
                                    'width' => $width,
                                    'height' => $height,
                                    'isBackground' => false,
                                ];

                                // --- Decode SMask (alpha channel) for this image, if any ---
                                // Detect directly from the image's own dictionary (avoids the
                                // expensive full-PDF obj scan that caused catastrophic backtracking).
                                $smask_decoded = null;
                                $sm_width      = $width;
                                $sm_height     = $height;
                                $_sm_ref_num   = null;
                                if (preg_match('/\/SMask\s+(\d+)\s+\d+\s+R/', $objDict, $_sm_ref)) {
                                    $_sm_ref_num = $_sm_ref[1];
                                    unset($_sm_ref);
                                }
                                if ($_sm_ref_num !== null) {
                                    $sm_num = $_sm_ref_num;
                                    // Last definition, like every other object lookup here; the old pattern took the first.
                                    $_sm_obj = self::definitionLookup($index, $pdf_raw, (string) $sm_num);
                                    if ($_sm_obj !== null) {
                                        $sm_body = $_sm_obj['body'];
                                        preg_match_all('/\/Filter\s*\/([A-Za-z0-9]+)/', $sm_body, $_sm_f);
                                        $sm_filters = $_sm_f[1] ?? [];
                                        /* The mask carries its own size, which is often smaller than the image it belongs to.
                                           Undoing the predictor with the image's width read every row at the wrong offset. */
                                        $sm_width  = preg_match('/\/Width\s+(\d+)/', $sm_body, $_sm_w) ? (int) $_sm_w[1] : $width;
                                        $sm_height = preg_match('/\/Height\s+(\d+)/', $sm_body, $_sm_h) ? (int) $_sm_h[1] : $height;
                                        $sm_width  = max(1, $sm_width);
                                        $sm_height = max(1, $sm_height);
                                        if (preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $sm_body, $_sm_s)) {
                                            $sm_raw  = $_sm_s[1];
                                            $sm_data = $sm_raw;
                                            if (in_array('FlateDecode', $sm_filters, true)) {
                                                $sm_try = PdfUtils::inflateWithin($sm_raw);
                                                if (is_string($sm_try)) {
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
                                                $sm_prev = str_repeat("\0", $sm_width);
                                                $sm_i    = 0;
                                                for ($sy = 0; $sy < $sm_height; $sy++) {
                                                    // Per-row checkpoint, like the image's own predictor loop above.
                                                    if (($sy & 255) === 0) {
                                                        self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'SMask predictor');
                                                    }
                                                    if ($sm_i >= strlen($sm_data)) {
                                                        break;
                                                    }
                                                    $sm_ftype = ord($sm_data[$sm_i++]);
                                                    $sm_row   = str_pad(
                                                        substr($sm_data, $sm_i, $sm_width),
                                                        $sm_width,
                                                        "\0"
                                                    );
                                                    $sm_i += $sm_width;
                                                    for ($sx = 0; $sx < $sm_width; $sx++) {
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
                                        \FabricatorForms\Utils\SecureDir::putFile($imgFile, $decoded);
                                    } else {
                                        $im = imagecreatetruecolor($width, $height);
                                        // Enable alpha so the SMask can be written as transparency.
                                        imagealphablending($im, false);
                                        imagesavealpha($im, true);
                                        $idx = 0;

                                        for ($y = 0; $y < $height; $y++) {
                                            if (($y & 255) === 0) {
                                                self::checkParseTimeBudget($fabricator_parse_start, $fabricator_parse_max_seconds, 'image render');
                                            }
                                            for ($x = 0; $x < $width; $x++) {
                                                // An indexed image is RGB only once its palette expanded it to three channels; if the palette
                                                // was unusable, its one index byte per pixel is drawn as grey instead of read out of range.
                                                if ($colorspace === 'DeviceRGB' || ($colorspace === 'IndexedRGB' && $channels === 3)) {
                                                    $r = ord($decoded[$idx++]);
                                                    $g = ord($decoded[$idx++]);
                                                    $b = ord($decoded[$idx++]);
                                                } elseif ($colorspace === 'DeviceGray' || $colorspace === 'IndexedRGB') {
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
                                                    // Sampled with the mask's own size: a mask smaller than its image is
                                                    // normal, and reading it at the image's stride shifted every row.
                                                    $sm_sx    = $sm_width === $width ? $x : intdiv($x * $sm_width, max(1, $width));
                                                    $sm_sy    = $sm_height === $height ? $y : intdiv($y * $sm_height, max(1, $height));
                                                    $sm_byte  = ord($smask_decoded[$sm_sy * $sm_width + $sm_sx] ?? "\xff");
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
                                        // unset(), not imagedestroy(): a no-op since PHP 8.0 and deprecated in 8.5.
                                        unset($im);
                                    }
                                }

                                // Display only, bounded read: full-res embedding cost 5-6x memory and OOM'd; verdict hashing uses PdfUtils::thumbnailHash() on full-res separately, unaffected.
                                $data_uri = self::displayDataUri(
                                    $imgFile,
                                    $ext === 'jpg' ? 'image/jpeg' : 'image/png'
                                );

                                // --- Build HTML content ---
                                // Image is embedded as a data URI — the verimages/ directory
                                // is HTTP-blocked by .htaccess so no public URL is used.
                                $html  = "<div style='margin:10px 0; padding:8px; border:1px solid #ccc'>";

                                $html .= "<div style='font-size:10px;color:#666;margin-bottom:4px'>";
                                $html .= "{$colorspace} | {$width}×{$height} | {$channels}ch";
                                $html .= "</div>";

                                // --- SUSPECT FOUND LABEL (RESTORED) ---
                                $html .= "<div style='margin-bottom:4px'>";
                                $html .= '<b>' . esc_html__('Suspect found:', 'formfabricator') . '</b> ' . esc_html((string)$check_hash);
                                $html .= "</div>";

                                // --- STATUS AREA ---
                                $html .= "<div class='img-status' style='margin-bottom:6px'>";
                                if ($is_allowed) {
                                    $html .= "<span style='color:green;font-weight:bold'>"
                                           . esc_html__('Suspect is determined as usual.', 'formfabricator') . '</span>';
                                } else {
                                    $html .= '<span style=' . "'" . 'color:red;font-weight:bold' . "'" . '>' . esc_html__('Visual mismatch detected', 'formfabricator') . '</span>';
                                    $html .= "<div style='margin-top:6px;padding:6px;"
                                           . "background:#fff0f0;border:1px solid #f99;"
                                           . "font-size:11px;font-family:monospace'>";
                                    $html .= '<b>' . esc_html__('Why flagged:', 'formfabricator') . '</b><br>';
                                    $html .= esc_html__('Hash method:', 'formfabricator') . ' ' . esc_html($hash_method_label ?? '') . "<br>";
                                    $html .= esc_html__('Computed hash:', 'formfabricator') . ' <b>' . $check_hash . '</b><br>';
                                    $pool  = $exact_image_hashes;
                                    $html .= esc_html__('Allowed hashes in seal', 'formfabricator') . ' (' . count($pool) . "):<br>";
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
                                    $html .= '<p style=' . "'" . 'color:orange' . "'" . '>' . esc_html__('[Image could not be rendered]', 'formfabricator') . '</p>';
                                }

                                // --- IMAGE INFO (now BELOW image) ---
                                $html .= "<div style='margin:5px 0; padding:5px;"
                                       . " border:1px solid #666; background:#f9f9f9; font-size:10px'>";
                                $html .= esc_html__('Image ID:', 'formfabricator') . ' <b>' . $img_id . '</b><br>';
                                $html .= esc_html__('Colorspace:', 'formfabricator') . ' ' . $colorspace . '<br>';
                                $html .= esc_html__('Width × Height:', 'formfabricator') . " {$width}×{$height}<br>";
                                $html .= esc_html__('Decoded length:', 'formfabricator') . ' ' . strlen($decoded) . " bytes";
                                $html .= "</div>";

                                $html .= "</div>";

                                // --- Fill slot ---
                                self::fillImageSlot($uid, $html);
                            }

                                    // --- Recurse if Form XObject ---
                            $xObjDict     = [];
                            $is_form_xobj = str_contains($objDict, '/Subtype /Form')
                                && preg_match('/\/XObject\s*<<(.+?)>>/is', $objDict, $xObjDict);
                            if ($is_form_xobj) {
                                preg_match_all('/\/[A-Za-z0-9]+\s+(\d+\s+0\s+R)/', $xObjDict[1], $refs);
                                if (!empty($refs[1])) {
                                    foreach ($refs[1] as $ref) {
                                        $objRefNum = preg_replace('/\D.*/s', '', $ref); // leading digits of "N 0 R"
                                        // Guard against circular /XObject references (A -> B -> A) exhausting the stack.
                                        if (isset($visited[$objRefNum])) {
                                            continue;
                                        }
                                        $refObj = self::definitionLookup($index, $pdf_raw, $objRefNum, '0');
                                        if ($refObj !== null) {
                                            // Both bounds refuse rather than stop quietly: a nested scan left out could
                                            // hide exactly the image this check exists to find. Neither is reached by a
                                            // document from this plugin, which nests no Form XObjects at all.
                                            if (count($visited) >= self::MAX_XOBJECT_DEPTH) {
                                                throw new \LengthException('Too many objects: Form XObjects nest too deep.');
                                            }
                                            $nested_bytes = $refObj['header'] . $refObj['body'] . 'endobj';
                                            $xobject_nested_bytes += strlen($nested_bytes);
                                            if ($xobject_nested_bytes > $xobject_nested_limit) {
                                                throw new \LengthException('Too many objects: nested Form XObjects repeat too often.');
                                            }
                                            // With its own index: resolving each reference by searching the nested bytes cost
                                            // the number of references times their size, with no time check in between.
                                            $scanXObjects(
                                                $nested_bytes,
                                                $objRefNum,
                                                $visited + [$objRefNum => true],
                                                PdfUtils::objectDefinitionIndex($nested_bytes)
                                            );
                                        }
                                    }
                                }
                            }

                                    $offset = $obj_end + 6;
                        }

                        if (!$found && !$parentName) {
                            echo esc_html__('[XObject Scan] No XObjects found.', 'formfabricator') . "\n";
                        }
                    };

                    $scanXObjects($pdf_raw, null, [], $object_index);

                    // --- Final visual classification ---
                    $contains_background_images = false; // retained for downstream verdict compat
                    $image_missmatch            = false;

                    foreach (self::$image_slots as $slot) {
                        if ((int)$slot['allowed'] !== 1) {
                            $image_missmatch = true;
                        }
                    }

                    // Compares counts, not just presence, so swapping one sealed image for another still-known image is caught.
                    $observed_counts = [];
                    foreach (self::$image_slots as $slot) {
                        $slot_hash = (string) ($slot['hash'] ?? '');
                        if ($slot_hash === '') {
                            continue;
                        }
                        $observed_counts[$slot_hash] = ($observed_counts[$slot_hash] ?? 0) + 1;
                    }
                    $sealed_counts = array_count_values(array_map('strval', $exact_image_hashes));

                    $missing_images   = 0;
                    $duplicate_images = 0;
                    foreach ($sealed_counts as $sealed_hash => $sealed_n) {
                        $seen = $observed_counts[$sealed_hash] ?? 0;
                        if ($seen < $sealed_n) {
                            $missing_images += $sealed_n - $seen;
                        } elseif ($seen > $sealed_n) {
                            $duplicate_images += $seen - $sealed_n;
                        }
                    }

                    if ($missing_images > 0 || $duplicate_images > 0) {
                        $image_missmatch = true;
                        echo "<p style='margin-top:8px;'>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>"
                           . esc_html(
                               sprintf(
                                   /* translators: 1: number of sealed images no longer present, 2: number of images appearing more often than sealed. */
                                   __('Image inventory does not match the seal — %1$d missing, %2$d duplicated', 'formfabricator'),
                                   $missing_images,
                                   $duplicate_images
                               )
                           )
                           . "</span></p>";
                    }
                }

                echo "</div>"; // close image section foldable content
                echo "</div>"; // close image section wrapper


                /* ─────────────────────── CONTENT STREAM INTEGRITY ─────────────────────── */

                $allowed_content_hashes = $rebuilt_payload['content_streams'] ?? [];
                $content_stream_mismatch = false;
                $seal_page_text_live     = null;

                self::setProgress(__('Checking content streams…', 'formfabricator'), 87);

                $cs_section_id  = 'fabricator-pdf-content-streams-' . $uid_prefix;
                $cs_section_sec = 'fabricator-pdf-section-streams-' . esc_attr($uid_prefix);
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
                echo "<div class='fabricator-pdf-detail-section' id='{$cs_section_sec}'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($cs_section_id) . "'>" . esc_html__('Content Streams', 'formfabricator') . "</button>";
                $cs_badge_id = 'fabricator-pdf-badge-streams-' . esc_attr($uid_prefix);
                echo "<span id='{$cs_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>" . esc_html__('PASS', 'formfabricator') . "</span>";
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
                echo "</div>";
                echo "<div id='" . esc_attr($cs_section_id) . "' class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";

                if (empty($allowed_content_hashes)) {
                    echo "<p class='fabricator-pdf-empty-state'>"
                       . esc_html__('No content stream hashes in seal (PDF generated before this feature was added).', 'formfabricator')
                       . "</p>";
                } else {
                    // Structural check (PdfUtils::verifyContentStreams): only the last page's own seal stream is exempt, it is
                    // rebuilt into its PASS-1 form, and the live streams must equal the sealed hashes as a multiset. The walk
                    // this replaces exempted ANY stream holding the seal marker, trusted a first-bytes heuristic for what counts
                    // as content, and never noticed a sealed stream that had disappeared, so overlaid, covered or swapped page
                    // content could still verify as authentic.
                    $cs_result = \FabricatorForms\PDF\PdfUtils::verifyContentStreams(
                        (string) $pdf_raw,
                        array_map('strval', (array) $allowed_content_hashes),
                        '---BEGIN-SEAL---' . preg_replace('/\s+/', '', (string) $seal_base64) . '---END-SEAL---'
                    );
                    if ($cs_result['mismatch']) {
                        $content_stream_mismatch = true;
                    }
                    if ($cs_result['seal_obj'] !== null) {
                        $seal_page_text_live = \FabricatorForms\PDF\PdfUtils::sealPageTextFingerprint((string) $pdf_raw);
                    }

                    $n_seal = count($allowed_content_hashes);
                    $n_pdf  = count($cs_result['rows']);
                    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see handleUpload() note.
                    echo "<p class='fabricator-pdf-hash-summary'>"
                       . esc_html(
                           sprintf(
                               /* translators: %1$d: content streams recorded in the seal, %2$d: content streams found in the PDF */
                               __('%1$d stream(s) in seal · %2$d verifiable in PDF', 'formfabricator'),
                               $n_seal,
                               $n_pdf
                           )
                       ) . "</p>";
                    echo "<div class='fabricator-pdf-hash-list'>";
                    foreach ($cs_result['rows'] as $cs_row) {
                        $short = esc_html(substr((string) $cs_row['hash'], 0, 20));
                        if ($cs_row['status'] === 'unrecognised') {
                            echo "<div class='fabricator-pdf-hash-row fabricator-pdf-hash-row--fail'>"
                               . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>" . esc_html__('UNRECOGNISED', 'formfabricator') . "</span>"
                               . "<code>{$short}…</code>"
                               . "<span style='font-size:11px;color:#721c24;'>" . esc_html__('not in seal', 'formfabricator') . "</span></div>";
                            continue;
                        }
                        $cs_pill = $cs_row['status'] === 'seal-page' ? esc_html__('SEAL PAGE', 'formfabricator') : esc_html__('MATCH', 'formfabricator');
                        echo "<div class='fabricator-pdf-hash-row fabricator-pdf-hash-row--pass'>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>{$cs_pill}</span>"
                           . "<code>{$short}…</code></div>";
                    }
                    foreach ($cs_result['problems'] as $cs_problem) {
                        $cs_obj   = (int) ($cs_problem['obj'] ?? 0);
                        $cs_count = (int) ($cs_problem['count'] ?? 0);
                        $cs_msg   = match ((string) $cs_problem['code']) {
                            // translators: %d: PDF object number of the stream.
                            'oversized' => sprintf(__('Content stream %d is too large to verify.', 'formfabricator'), $cs_obj),
                            // translators: %d: PDF object number of the stream.
                            'marker_outside_seal_page' => sprintf(__('Stream %d carries a seal marker outside the seal page: injected content.', 'formfabricator'), $cs_obj),
                            'no_seal_stream' => __('No content stream on the last page carries the seal.', 'formfabricator'),
                            'seal_page_mismatch' => __('The seal page contains content that is not in the seal.', 'formfabricator'),
                            // translators: %d: number of sealed content streams missing from the PDF.
                            'sealed_missing' => sprintf(_n('%d sealed content stream is missing from the PDF.', '%d sealed content streams are missing from the PDF.', $cs_count, 'formfabricator'), $cs_count),
                            default => __('Content streams do not match the seal.', 'formfabricator'),
                        };
                        echo "<div class='fabricator-pdf-hash-row fabricator-pdf-hash-row--fail'>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>" . esc_html($cs_msg) . "</span></div>";
                    }
                    echo "</div>";

                    /* Seal-page text check. */
                    $seal_page_text_sealed = (string) ($rebuilt_payload['seal_page_text'] ?? '');
                    if ($seal_page_text_live === null) {
                        echo "<p style='margin-top:8px;'>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>"
                           . esc_html__('Seal-page content stream not found', 'formfabricator')
                           . "</span></p>";
                        $content_stream_mismatch = true;
                    } elseif (!hash_equals($seal_page_text_sealed, $seal_page_text_live)) {
                        echo "<p style='margin-top:8px;'>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>"
                           . esc_html__('Text on the seal page does not match the seal', 'formfabricator')
                           . "</span></p>";
                        $content_stream_mismatch = true;
                    } else {
                        echo "<p style='margin-top:8px;'>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>"
                           . esc_html__('Seal-page text matches the seal', 'formfabricator')
                           . "</span></p>";
                    }

                    if (!$content_stream_mismatch) {
                        echo "<p style='margin-top:8px;'>"
                           . "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>" . esc_html__('All streams accounted for', 'formfabricator') . "</span></p>";
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
                   . " data-target='" . esc_attr($fonts_section_id) . "'>" . esc_html__('Fonts', 'formfabricator') . "</button>";
                $fonts_badge_id = 'fabricator-pdf-badge-fonts-' . esc_attr($uid_prefix);
                echo "<span id='{$fonts_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>" . esc_html__('PASS', 'formfabricator') . "</span>";
                    // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
                echo "</div>";
                echo "<div id='" . esc_attr($fonts_section_id) . "'"
                   . " class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";

                // Both sections below only ask which /Type names occur — see distinctTypeNames().
                $matches = self::distinctTypeNames((string) $pdf_raw);

                if ($matches !== []) {
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
                            // The blocks preg_match_all('/\/Font\s*<<([\s\S]*?)>>/i') captured, found linearly: once no ">>"
                            // followed, that regex rescanned the rest of the file from every later "/Font <<".
                            $font_refs    = [];
                            $font_scan_at = 0;
                            while (preg_match('/\/Font\s*<</i', $pdf_raw, $font_match, PREG_OFFSET_CAPTURE, $font_scan_at)) {
                                $font_block_start = $font_match[0][1] + strlen($font_match[0][0]);
                                $font_block_end   = strpos($pdf_raw, '>>', $font_block_start);
                                if ($font_block_end === false) {
                                    break;
                                }
                                $block        = substr($pdf_raw, $font_block_start, $font_block_end - $font_block_start);
                                $font_scan_at = $font_block_end + 2;
                                if (preg_match_all('/\/\w+\s+(\d+\s+\d+)\s+R/', $block, $m)) {
                                    foreach ($m[1] as $ref) {
                                        $font_refs[$ref] = true;
                                    }
                                }
                            }

                            // --- 1b. Resolve font objects & extract BaseFont ---
                            foreach (array_keys($font_refs) as $ref) {
                                // Last definition of exactly this object and generation; the old pattern had no boundary before N.
                                [$font_ref_num, $font_ref_gen] = preg_split('/\s+/', (string) $ref);
                                $font_ref_obj = self::definitionLookup($object_index, (string) $pdf_raw, $font_ref_num, $font_ref_gen);
                                if ($font_ref_obj === null || !preg_match('/\A\s*<<(.*?)>>/s', $font_ref_obj['body'], $obj)) {
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
                                    $pill    = "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>" . esc_html__('OK', 'formfabricator') . "</span>";
                                } elseif (!$in_seal && $in_pdf) {
                                    $row_cls = 'fabricator-pdf-hash-row--fail';
                                    $pill    = "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>" . esc_html__('UNDECLARED', 'formfabricator') . "</span>";
                                } else {
                                    $row_cls = 'fabricator-pdf-hash-row--warn';
                                    $pill    = "<span class='fabricator-pdf-pill fabricator-pdf-pill--warn'>" . esc_html__('UNUSED', 'formfabricator') . "</span>";
                                }
                                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
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
                    // Restore aggregate flag cleared by the re-init block before ANNOTATION PROCESSING; $all_stream_mismatch won't catch this since it only flags ADDED streams, not a stripped font program.
                    $font_missmatch = true;
                    echo "<p style='margin-top:8px;'>"
                       . "<span class='fabricator-pdf-pill fabricator-pdf-pill--fail'>"
                       . esc_html__('Font binary programs do not match the seal', 'formfabricator')
                       . "</span></p>";
                }

                if (!$font_missmatch) {
                    echo "<p style='margin-top:8px;'>"
                       . "<span class='fabricator-pdf-pill fabricator-pdf-pill--pass'>"
                       . esc_html__('All fonts match the seal', 'formfabricator')
                       . "</span></p>";
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
                   . " data-target='" . esc_attr($objects_section_id) . "'>" . esc_html__('PDF Objects', 'formfabricator') . "</button>";
                $objects_badge_id = 'fabricator-pdf-badge-objects-' . esc_attr($uid_prefix);
                echo "<span id='{$objects_badge_id}' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>" . esc_html__('PASS', 'formfabricator') . "</span>";
                                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
                    // Whether the file declares any image at all: asked once, not once per XObject match over the whole file.
                    $raw_has_image_subtype = null;
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
                            $raw_has_image_subtype ??= preg_match('/\/Subtype\s*\/Image/i', $pdf_raw) === 1;
                            // Every PDF draws the background grid, an SVG mPDF writes as a Form XObject, so a document
                            // without images still has an XObject. The content-stream check above holds every Form
                            // XObject stream to the seal (changed, added or duplicated ones are "not in seal"), so one
                            // it passed is sealed. Only when the seal lists content streams, or nothing was compared.
                            $form_xobjects_sealed = !empty($allowed_content_hashes) && $content_stream_mismatch === false;
                            if ($raw_has_image_subtype || $form_xobjects_sealed) {
                                continue;
                            }
                        }
                        $unexpected_types[] = $type;
                        $unexpected_detected = true;
                    }
                }

                // Streams the reader left packed: never packed like this by FormFabricator, or beyond the memory reserved for
                // this check. Either way the file can't be confirmed.
                if ($refused_streams !== []) {
                    $unexpected_detected = true;
                }

                if ($unexpected_detected) {
                    echo "<div class='fabricator-pdf-tag-list'>";
                    foreach (array_unique($unexpected_types) as $utype) {
                        echo "<span class='fabricator-pdf-tag'>" . esc_html($utype) . "</span>";
                    }
                    echo "</div>";
                    self::renderRefusedStreams($refused_streams, $refused_unlisted);
                } else {
                    echo "<p class='fabricator-pdf-empty-state'>" . esc_html__('No unexpected PDF objects detected.', 'formfabricator') . "</p>";
                }

                echo "</div>"; // close objects foldable content
                echo "</div>"; // close objects section wrapper
            }

            // --- Collect inner detail HTML ---
            $inner_html = ob_get_clean();

            // --- Post-process: update badge classes based on computed booleans ---
            // The labels as they were printed, i.e. translated: matching the English words left every failed section's badge
            // green on a translated site ("BESTANDEN" never contains "PASS").
            $bdg_pass = "' class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>" . esc_html__('PASS', 'formfabricator');
            $bdg_fail = "' class='fabricator-pdf-detail-badge fabricator-pdf-badge-fail'>" . esc_html__('FAIL', 'formfabricator');
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
                // The LAST /Info reference and the LAST definition of that object, as a viewer reads an incrementally updated
                // file: each update appends a new trailer (pageContents() takes the last /Root the same way).
                $pdf_meta_found = ['title' => '', 'author' => '', 'creator' => ''];
                // Only the last /Info reference counts, so only the last is kept: preg_match_all() held a match for every
                // one in the file, and a file can repeat "/Info 1 0 R" as often as it likes.
                $info_ref = null;
                $info_at  = 0;
                $info_raw = (string) $pdf_raw;
                while (preg_match('/\/Info\s+(\d+)\s+(\d+)\s+R/', $info_raw, $info_match, PREG_OFFSET_CAPTURE, $info_at) === 1) {
                    $info_ref = [$info_match[0][0], $info_match[1][0], $info_match[2][0]];
                    $info_at  = $info_match[0][1] + strlen($info_match[0][0]);
                }
                unset($info_raw);
                if ($info_ref !== null) {
                    $info_obj = self::definitionLookup($object_index, (string) $pdf_raw, $info_ref[1], $info_ref[2]);
                    if ($info_obj !== null) {
                        foreach (['Title' => 'title', 'Author' => 'author', 'Creator' => 'creator'] as $key => $slot) {
                            // A real string parser: mPDF escapes "(", ")" and "\" inside these UTF-16BE strings, and reading
                            // up to the first ")" garbled such a value, so a genuine PDF came out as "Modified".
                            $pdf_meta_found[$slot] = PdfUtils::dictTextEntry($info_obj['body'], $key) ?? '';
                        }
                    }
                }

                ob_start();
                echo "<div class='fabricator-pdf-detail-section' id='fabricator-pdf-section-meta-" . esc_attr($uid_prefix) . "'>";
                echo "<div class='fabricator-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small fabricator-pdf-toggle'"
                   . " data-target='" . esc_attr($meta_section_id) . "'>" . esc_html__('PDF Metadata', 'formfabricator') . "</button>";
                echo "<span id='fabricator-pdf-badge-meta-" . esc_attr($uid_prefix) . "'"
                   . " class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>" . esc_html__('PASS', 'formfabricator') . "</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($meta_section_id) . "' class='fabricator-pdf-hidden fabricator-pdf-detail-content'>";
                echo "<div class='fabricator-pdf-hash-list'>";

                $meta_labels = [
                    'title'   => __('Title', 'formfabricator'),
                    'author'  => __('Author', 'formfabricator'),
                    'creator' => __('Creator', 'formfabricator'),
                ];
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
                    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
                    echo "<div class='fabricator-pdf-hash-row {$row_cls}'>"
                       . "<span class='fabricator-pdf-pill {$pill_cls}'>{$pill_text}</span>"
                       . "<code>" . esc_html($label) . "</code>"
                       . "<span style='color:#50575e;font-size:11px;margin-left:4px;'>"
                       . esc_html($actual !== '' ? $actual : __('(empty)', 'formfabricator'))
                       . ($match ? '' : ' <em style="color:#d63638;">' . esc_html__('expected:', 'formfabricator') . ' ' . esc_html($expected) . '</em>')
                       . "</span>"
                       . "</div>";
                    // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }

                echo "</div>"; // fabricator-pdf-hash-list
                echo "</div>"; // meta section content
                echo "</div>"; // meta section wrapper
                $meta_html = ob_get_clean();
                $inner_html = ($meta_html ?: '') . ($inner_html ?? '');
            }

            if ($meta_mismatch) {
                $meta_badge_pass = "id='fabricator-pdf-badge-meta-{$uid_prefix}'"
                    . " class='fabricator-pdf-detail-badge fabricator-pdf-badge-pass'>" . esc_html__('PASS', 'formfabricator');
                $meta_badge_fail = "id='fabricator-pdf-badge-meta-{$uid_prefix}'"
                    . " class='fabricator-pdf-detail-badge fabricator-pdf-badge-fail'>" . esc_html__('FAIL', 'formfabricator');
                $inner_html = str_replace($meta_badge_pass, $meta_badge_fail, $inner_html ?? '');
            }

            // All-stream fingerprint check (Gap B catch-all): flag a live stream with no matching sealed
            // hash (injected content); a sealed hash missing from the live PDF is not flagged (mere removal).
            $all_stream_mismatch = false;
            if ($pdf_raw !== false) {
                $sealed_as_index = array_flip(
                    array_map('strval', (array) ($seal_data['all_stream_hashes'] ?? []))
                );
                foreach (PdfUtils::hashAllCompressedStreams((string) $pdf_raw) as $h) {
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
            // Kept apart from $document_modified: key_id lives in the uploader-controlled seal blob, so rewriting it to an unknown UUID must never mask tamper signals established independently of the key.
            $structural_tamper = $visual_modified || $any_pdf_issue;
            $document_modified = !$seal_matches || $structural_tamper;

            // --- Summary panel ---
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see note atop handleUpload().
            echo self::renderSummaryPanel(
                [
                'seal_matches'               => $seal_matches,
                'seal_key_status'            => $seal_key_status  ?? 'active',
                'seal_compromised'           => $seal_compromised ?? false,
                'visual_modified'            => $visual_modified,
                'structural_tamper'          => $structural_tamper,
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
            // Map technical exception messages to user-friendly, translatable ones.
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
                // The same sentence as the early refusal before parsing, reached here only through indexObjects()'s backstop.
                str_contains($raw_msg, 'Too many objects')
                    // translators: %s: uploaded file name.
                    => sprintf(__('%s has far more parts than any document this plugin creates and was not read.', 'formfabricator'), $fn),
                default
                    => $fn . ': ' . __('The document could not be processed. See server log for details.', 'formfabricator'),
            };
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() + hardcoded strings; noticeHtml() also wp_kses_post()'s it.
            echo self::noticeHtml($friendly_msg, 'error');
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
        } finally {
            $pdf_content = ob_get_clean();
        }

        $segment_id      = sanitize_html_class($uid_prefix . '-segment');
        $legacy_statuses = ['rotated-legacy', 'compromised-legacy'];
        $is_legacy       = in_array($seal_key_status ?? '', $legacy_statuses, true);
        $is_compromised  = ($seal_compromised ?? false)
            || ($seal_key_status ?? '') === 'compromised-legacy';

        // Checked ahead of Modified on purpose: without the key nothing is disproved, so "Modified" would wrongly accuse an unverifiable document.
        $key_unavailable = in_array($seal_key_status ?? '', ['unknown-key', 'undecryptable-key'], true)
            && !$structural_tamper;

        if ($document_modified === null) {
            $verdict_icon  = '';
            $verdict_label = __('Error', 'formfabricator');
            $border_color  = '#b32d2e';
            $badge_bg      = '#b32d2e';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        } elseif ($key_unavailable) {
            // A key id this site never had is exactly what a forged seal carries, so it reads as a failure, not as amber
            // "undetermined". A key the site holds but can't decrypt is a local configuration problem and stays amber.
            $unknown_key   = ($seal_key_status ?? '') !== 'undecryptable-key';
            $verdict_icon  = $unknown_key ? 'fa-solid fa-circle-xmark' : 'fa-solid fa-key';
            $verdict_label = $unknown_key
                ? __('Not Verifiable', 'formfabricator')
                : __('Key Unreadable', 'formfabricator');
            $border_color  = $unknown_key ? '#b32d2e' : '#8c6d1f';
            $badge_bg      = $unknown_key ? '#b32d2e' : '#8c6d1f';
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
                . $badge_base_style . '">' . esc_html__('Legacy', 'formfabricator') . '</span>';
            $hdr_right = '<span style="grid-column:3;justify-self:end;display:flex;gap:6px;'
                . 'align-items:center;">' . $verdict_badge . $legacy_badge . '</span>';
        } else {
            $hdr_right = '<span style="grid-column:3;justify-self:end;flex-shrink:0;">'
                . $verdict_badge . '</span>';
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-escaped at assignment; see the note at the top of handleUpload().
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
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
        echo "</div>";
        echo "</div>";
    }


    /**
     * Scans raw PDF bytes for the FF seal marker without loading the full object graph.
     *
     * @param string $path Absolute filesystem path to the PDF file.
     * @return bool True if the seal marker is found, false otherwise.
     */
    private static function rawPdfHasSeal(string $path): bool
    {
        // Must read the FULL file — large embedded images can push page content streams well outside any 2MB tail window.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read of a plugin-generated file in wp-content/uploads; wp_remote_get() (the sniff's suggestion) is for remote URLs, not this.
        $raw = is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            \FabricatorForms\fabricator_log('FabricatorForms rawPdfHasSeal: file_get_contents() failed for ' . basename($path) . '.');
            return false;
        }

        // Find every stream…endstream block (a linear scan; see PdfUtils::rawStreamBlocks()).
        $blocks = PdfUtils::rawStreamBlocks($raw);

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

            // Refuse streams that are, or would inflate to, more than the cap: a crafted FlateDecode bomb could expand
            // 50 MB of compressed data to GBs. The cap goes into the inflate call itself, so a bomb stops there.
            if (strlen($stream) > PdfUtils::INFLATE_STREAM_CAP) {
                $skipped_oversized_streams++;
                continue;
            }
            $dec = PdfUtils::inflateEither($stream, 0, false);
            if (!is_string($dec) || strlen($dec) < 32) {
                $skipped_failed_decompress++;
                continue;
            }

            // Try even and odd byte alignments of the 2-byte Unicode pairs, keeping the low byte of every pair whose high
            // byte is zero. One regex pass each: the former per-byte PHP loop took minutes on a crafted 64 MB stream.
            for ($start = 0; $start <= 1; $start++) {
                $pairs = substr($dec, $start, (strlen($dec) - $start) & ~1);
                $ascii = preg_replace('/\x00(.)|../s', '$1', $pairs);
                if ($ascii === null) {
                    \FabricatorForms\fabricator_log('FabricatorForms rawPdfHasSeal: PCRE error ' . preg_last_error() . ' while reading a stream of ' . basename($path) . '.');
                    continue;
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

        // No key means the HMAC was never computed, so this row shows "not checked" (warn, not fail), same rule as the header verdict.
        $key_unavailable = in_array(
            (string) ($d['seal_key_status'] ?? ''),
            ['unknown-key', 'undecryptable-key'],
            true
        ) && empty($d['structural_tamper']);

        if ($key_unavailable && !$d['seal_matches']) {
            // Unknown key id = what a forged seal carries: shown as a failure. An undecryptable key of ours stays a warning.
            $unknown_key = (string) ($d['seal_key_status'] ?? '') !== 'undecryptable-key';
            $key_msg     = $unknown_key
                ? __('NOT VERIFIABLE — no key on this site matches this seal; do not treat the document as authentic', 'formfabricator')
                : __('NOT CHECKED — the signing key exists but could not be decrypted', 'formfabricator');
            $key_icon    = $unknown_key ? $fail : $warn;
            $key_class   = $unknown_key ? 'fabricator-pdf-row-fail' : 'fabricator-pdf-row-warn';
            $rows .= "<tr class='fabricator-pdf-toggle fabricator-pdf-summary-row' data-target='"
                . esc_attr($uid . '-raw-content') . "' title='" . esc_attr__('Show details', 'formfabricator') . "'>"
                . "<td>{$key_icon}</td>"
                . '<td>' . esc_html__('Cryptographic seal (HMAC)', 'formfabricator') . '</td>'
                . "<td class='fabricator-pdf-row-detail'><span class='{$key_class}'>"
                . esc_html($key_msg) . "</span></td>"
                . "<td class='fabricator-pdf-row-caret-cell'>{$caret}</td>"
                . "</tr>
";
        } else {
            $seal_detail = __('MISMATCH — document may have been tampered with', 'formfabricator');
            $rows .= $row($d['seal_matches'], __('Cryptographic seal (HMAC)', 'formfabricator'), $seal_detail, $uid . '-raw-content');
        }

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

        if ($key_unavailable && !$d['seal_matches']) {
            if ((string) ($d['seal_key_status'] ?? '') === 'undecryptable-key') {
                // Undetermined, not failed: the key is this site's own but unreadable, a local configuration problem.
                $verdict_class = 'fabricator-pdf-verdict-warn';
                $verdict_text  = '&#9888; ' . esc_html($d['file_name']) . ' — '
                    . esc_html__('NOT VERIFIABLE — signing key could not be decrypted', 'formfabricator');
            } else {
                // A key id this site never had is what a forged seal looks like; never present it as merely undetermined.
                $verdict_class = 'fabricator-pdf-verdict-fail';
                $verdict_text  = '&#10007; ' . esc_html($d['file_name']) . ' — '
                    . esc_html__('NOT VERIFIABLE — unknown signing key, do not treat as authentic', 'formfabricator');
            }
        } else {
            $verdict_class = $d['document_modified'] ? 'fabricator-pdf-verdict-fail' : 'fabricator-pdf-verdict-pass';
            $verdict_text  = $d['document_modified']
                ? '&#10007; ' . esc_html($d['file_name']) . ' — ' . esc_html__('MODIFIED or INVALID', 'formfabricator')
                : '&#10003; ' . esc_html($d['file_name']) . ' — ' . esc_html__('Authentic', 'formfabricator');
        }

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
        // Key order must exactly match Generator::$seal_data.
        $rebuilt = [
            'generated'        => (string) ($seal_data['generated'] ?? ''),
            'key_id'           => (string) ($seal_data['key_id'] ?? ''),
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
            'seal_page_text'    => (string) ($seal_data['seal_page_text'] ?? ''),
        ];

        foreach ((array) ($seal_data['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            // Verbatim, not re-canonicalized: html_entity_decode()/wp_strip_all_tags() aren't idempotent, so re-applying them here caused false MODIFIED verdicts; normalize on the seal side only.
            $rebuilt['fields'][] = [
                'label' => (string) ($field['label'] ?? ''),
                'value' => (string) ($field['value'] ?? ''),
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

    /* hashAllCompressedStreams() and hashFontProgramStreams() moved to PdfUtils, shared with Generator: the verifier must hash exactly what was sealed. */

    // normalizeValue() removed deliberately: verifier has no business canonicalizing; seal values are already normalized once at seal time and must be re-hashed exactly as stored (see rebuildPayload()).

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
                // translators: %s: key path inside the seal payload, e.g. fields.3.value.
                $diffs[] = sprintf(__('Missing in the seal: %s', 'formfabricator'), $currentPath);
                continue;
            }

            if (!array_key_exists($key, $b)) {
                // translators: %s: key path inside the seal payload, e.g. fields.3.value.
                $diffs[] = sprintf(__('Missing in the rebuilt data: %s', 'formfabricator'), $currentPath);
                continue;
            }

            if (is_array($a[$key]) && is_array($b[$key])) {
                $diffs = array_merge($diffs, self::diffArrays($a[$key], $b[$key], $currentPath));
            } elseif (is_array($a[$key]) || is_array($b[$key])) {
                // Type mismatch between the two payloads — report without casting an array to string.
                // translators: %s: key path inside the seal payload, e.g. fields.3.value.
                $diffs[] = sprintf(__('Type mismatch at %s', 'formfabricator'), $currentPath) . "\n"
                    . __('Seal:', 'formfabricator') . ' ' . wp_json_encode($a[$key]) . "\n"
                    . __('Rebuilt:', 'formfabricator') . ' ' . wp_json_encode($b[$key]);
            } else {
                if ((string)$a[$key] !== (string)$b[$key]) {
                    // translators: %s: key path inside the seal payload, e.g. fields.3.value.
                    $diffs[] = sprintf(__('Mismatch at %s', 'formfabricator'), $currentPath) . "\n"
                        . __('Seal:', 'formfabricator') . ' ' . wp_json_encode($a[$key]) . "\n"
                        . __('Rebuilt:', 'formfabricator') . ' ' . wp_json_encode($b[$key]);
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
     * Reverses PNG predictor filtering on an indexed image, byte-by-byte — mixed filter rows are normal at /Predictor 15, so treating them as None yields a wrong hash and false mismatch.
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
        // PNG's bpp for Sub/Average/Paeth: bytes per complete sample, minimum 1. Indexed data has a
        // single component, so this is 1 for every legal indexed depth (1/2/4/8 bpc).
        $bpp = max(1, (int)ceil($bpc / 8));
        $out = '';
        $prev = str_repeat("\0", $rowBytes);
        $i = 0;

        while ($i < strlen($data)) {
            $filter = ord($data[$i++]);
            $row = substr($data, $i, $rowBytes);
            $i += $rowBytes;
            $row = str_pad($row, $rowBytes, "\0");

            for ($j = 0; $j < $rowBytes; $j++) {
                $cur  = ord($row[$j]);
                $up   = ord($prev[$j]);
                $left = $j >= $bpp ? ord($row[$j - $bpp]) : 0;

                switch ($filter) {
                    case 0: // None
                        break;
                    case 1: // Sub
                        $cur = ($cur + $left) & 0xFF;
                        break;
                    case 2: // Up
                        $cur = ($cur + $up) & 0xFF;
                        break;
                    case 3: // Average
                        $cur = ($cur + intdiv($left + $up, 2)) & 0xFF;
                        break;
                    case 4: // Paeth
                        $upleft = $j >= $bpp ? ord($prev[$j - $bpp]) : 0;
                        $p      = $left + $up - $upleft;
                        $pa     = abs($p - $left);
                        $pb     = abs($p - $up);
                        $pc     = abs($p - $upleft);
                        $pred   = ($pa <= $pb && $pa <= $pc) ? $left : (($pb <= $pc) ? $up : $upleft);
                        $cur    = ($cur + $pred) & 0xFF;
                        break;
                    default:
                        // Undefined filter byte: leave the sample as-is rather than guess.
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

        // Deleted with the checked copy when this request ends: the image reaches the browser as a data URI inside the
        // result, never as a file. Only paths inside the protected folder are taken.
        $image_file = (string) ($meta['file_path'] ?? '');
        if (self::relativeTempPath($image_file) !== '') {
            self::$files_to_delete[] = $image_file;
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
     * Reports an image that can't be recreated from its stream, and records it as a mismatch.
     *
     * The image loop collects its reasons into $failureReasons and renders them in one block; anything found after
     * that block has already been rendered, so a reason added there was never shown. Those sites call this instead.
     *
     * @param string[] $reasons    Why the image could not be recreated.
     * @param string   $colorspace Colour space as read from the object.
     * @param int|null $width      Image width, when known.
     * @param int|null $height     Image height, when known.
     * @return void
     */
    private static function reportUnreadableImage(array $reasons, string $colorspace, ?int $width, ?int $height): void
    {
        // Recorded as a mismatch so an image that can't be recreated doesn't slip through by being unreadable.
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

        echo "<div style='margin:10px; padding:8px; border:2px dashed #c00; background:#fff6f6;'>";
        echo '<b>' . esc_html__('The image could not be recreated from its stream and counts as a mismatch.', 'formfabricator') . '</b><br>';
        echo "<ul style='margin:5px 0; padding-left:18px;'>";
        foreach ($reasons as $reason) {
            echo '<li>' . esc_html((string) $reason) . '</li>';
        }
        echo '</ul>';
        echo "<div style='font-size:11px; color:#333;'>";
        echo '• ' . esc_html__('ColorSpace:', 'formfabricator') . ' ' . esc_html($colorspace) . '<br>';
        echo '• ' . esc_html__('Width x Height:', 'formfabricator') . ' ' . (int) $width . ' × ' . (int) $height;
        echo '</div></div>';
    }

    /**
     * Lists the streams the PDF reader left packed, naming each object, and each image with its size.
     *
     * @param array $refused  From GuardedPdfParser::refusedStreams().
     * @param int   $unlisted How many more were left packed.
     * @return void
     */
    private static function renderRefusedStreams(array $refused, int $unlisted): void
    {
        $notes = [
            'filter' => __('These parts are compressed in a way FormFabricator never uses, so they were not unpacked. The file was not created by this site or was changed afterwards.', 'formfabricator'),
            'size'   => __('These parts are too large to unpack within the memory reserved for this check, so they were not read.', 'formfabricator'),
        ];
        foreach ($notes as $reason => $note) {
            $items = array_filter($refused, static fn(array $item): bool => $item['reason'] === $reason);
            if ($items === []) {
                continue;
            }
            echo '<p>' . esc_html($note) . '</p><ul>';
            foreach ($items as $item) {
                echo '<li>' . esc_html(self::refusedStreamLabel($item)) . '</li>';
            }
            echo '</ul>';
        }
        if ($unlisted > 0) {
            // translators: %d: number of further parts that were not unpacked.
            echo '<p>' . esc_html(sprintf(_n('… and %d more part.', '… and %d more parts.', $unlisted, 'formfabricator'), $unlisted)) . '</p>';
        }
    }

    /**
     * Names a stream the PDF reader left packed, followed by its compression when that was the reason.
     *
     * @param array $item One entry of GuardedPdfParser::refusedStreams().
     * @return string
     */
    private static function refusedStreamLabel(array $item): string
    {
        if ($item['subtype'] === 'Image') {
            $label = $item['width'] > 0 && $item['height'] > 0
                // translators: 1: PDF object number, 2: image width in pixels, 3: image height in pixels.
                ? sprintf(__('Image in object %1$s (%2$d × %3$d pixels)', 'formfabricator'), $item['object'], $item['width'], $item['height'])
                // translators: %s: PDF object number.
                : sprintf(__('Image in object %s', 'formfabricator'), $item['object']);
        } else {
            $kind  = trim($item['type'] . ' ' . $item['subtype']);
            $label = $kind !== ''
                // translators: 1: PDF object number, 2: the object's PDF type, e.g. "XObject Form".
                ? sprintf(__('Object %1$s (%2$s)', 'formfabricator'), $item['object'], $kind)
                // translators: %s: PDF object number.
                : sprintf(__('Object %s', 'formfabricator'), $item['object']);
        }
        return $item['reason'] === 'filter' ? $label . ': ' . implode(', ', $item['filters']) : $label;
    }

    /**
     * Deletes a checked copy, its serving token and the images extracted from it. Runs when the checking request ends,
     * whatever the result, since nothing reads them afterwards.
     *
     * @param string $path  Absolute path of the stored copy, already confined to verfiles/ by the caller.
     * @param string $token Its serving token.
     * @return void
     */
    public static function discardCheckedCopy(string $path, string $token): void
    {
        // The response is complete; deleting must not hold it up.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        delete_transient('fabricator_pdf_' . $token);
        foreach (array_merge([$path], self::$files_to_delete) as $file) {
            if (!is_file($file)) {
                continue;
            }
            wp_delete_file($file);
            if (file_exists($file)) {
                \FabricatorForms\fabricator_log('FabricatorForms Verificationpage: could not remove ' . basename($file) . ' after its check; the unused-copy cleanup retries.');
            }
        }
        self::$files_to_delete = [];
    }

    /**
     * Longest edge, in pixels, of an image in the verification page's card — beyond this is wasted download/decode cost since the card caps display at max-width:100%.
     */
    private const DISPLAY_MAX_EDGE = 1400;

    /** Below this, re-encoding costs more memory and time than it saves; embed the file as-is. */
    private const DISPLAY_PASSTHROUGH_BYTES = 262144;

    /**
     * Builds a size-bounded data URI (not a URL — verimages/ is .htaccess-blocked) for a cached image; downscaling here is cosmetic only since verification hashes the full-resolution data elsewhere.
     *
     * @param string $file Absolute path to the cached image.
     * @param string $mime Mime type of that file ('image/jpeg' or 'image/png').
     * @return string Data URI, or '' when the image cannot be embedded within the memory left.
     */
    private static function displayDataUri(string $file, string $mime): string
    {
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $file is the plugin-generated verimages/ cache path built in handleUpload(), never user-supplied.
        if (!is_readable($file)) {
            return '';
        }
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $file is the plugin-generated verimages/ cache path built in handleUpload(), never user-supplied.
        $size = (int) filesize($file);
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- see above; reads image dimensions from that same cache path.
        $info = getimagesize($file);
        if ($size <= 0 || !is_array($info) || empty($info[0]) || empty($info[1])) {
            return '';
        }
        $width  = (int) $info[0];
        $height = (int) $info[1];

        // Budgets against actual headroom, not a fixed ceiling; halved since GD's real cost runs ~1.6x the w*h*4 estimate and the caller still has HTML left to build.
        $limit     = \FabricatorForms\Utils\MemoryBudget::phpMemoryLimitBytes();
        $available = $limit > 0 ? max(0, $limit - memory_get_usage(true)) : PHP_INT_MAX;
        $headroom  = intdiv($available, 2);

        $fits = static fn(int $bytes): bool => $bytes < $headroom;

        // Both edge and size checks needed: a highly compressible 200KB file can still be 4000x3000, whose decode cost is set by w*h*4, not file size.
        $within_bounds = max($width, $height) <= self::DISPLAY_MAX_EDGE;
        if ($within_bounds && $size <= self::DISPLAY_PASSTHROUGH_BYTES && $fits($size * 6)) {
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- $file is the plugin-generated verimages/ cache path, never user-supplied; local read, not remote.
            $raw = file_get_contents($file);
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- builds a data: URI for inline display; the alternative is writing an HTTP-reachable file. Not obfuscation.
            return $raw === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($raw);
        }

        $scale = min(1.0, self::DISPLAY_MAX_EDGE / max($width, $height));
        if (!function_exists('imagecreatefromstring') || $scale >= 1.0) {
            // No GD, or already within bounds but simply heavy (a dense photo): embed only if it fits.
            if (!$fits($size * 6)) {
                return '';
            }
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- $file is the plugin-generated verimages/ cache path, never user-supplied; local read, not remote.
            $raw = file_get_contents($file);
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- builds a data: URI for inline display; the alternative is writing an HTTP-reachable file. Not obfuscation.
            return $raw === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($raw);
        }

        $dst_w = max(1, (int) round($width * $scale));
        $dst_h = max(1, (int) round($height * $scale));

        // Checked before decoding: exceeding the limit inside imagecreatefromstring() is an uncatchable fatal, which this check exists to avoid.
        if (!$fits(($width * $height * 4) + ($dst_w * $dst_h * 4) + $size)) {
            return '';
        }

        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- $file is the plugin-generated verimages/ cache path built in handleUpload(), never user-supplied; local read, not remote.
        $raw = file_get_contents($file);
        if ($raw === false) {
            return '';
        }
        $src = imagecreatefromstring($raw);
        unset($raw);
        if ($src === false) {
            return '';
        }

        $dst = imagecreatetruecolor($dst_w, $dst_h);
        if ($mime === 'image/png') {
            // Carry transparency through the resample; a signature on a white block is unreadable.
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dst_w, $dst_h, $width, $height);
        unset($src); // not imagedestroy(): a no-op since PHP 8.0 and deprecated in 8.5

        ob_start();
        if ($mime === 'image/png') {
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- null filename: writes to the output buffer opened above, no file is touched.
            imagepng($dst, null, 6);
        } else {
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- null filename: writes to the output buffer opened above, no file is touched.
            imagejpeg($dst, null, 82);
        }
        $scaled = (string) ob_get_clean();
        unset($dst);

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- builds a data: URI for inline display; the alternative is writing an HTTP-reachable file. Not obfuscation.
        return $scaled === '' ? '' : 'data:' . $mime . ';base64,' . base64_encode($scaled);
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
