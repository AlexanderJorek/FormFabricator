<?php

/**
 * File upload field.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.9
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Fields;

defined('ABSPATH') || exit;

/**
 * File upload field with MIME type and size validation.
 */
class UploadField extends BaseField
{
    // Always denied regardless of allowed_types config — defense-in-depth against a renamed malicious file.
    private const BLOCKED_TYPES = [
        'htm','html','shtml','phtml','jse','jar','xml','css','asp','aspx',
        'jsp','jspx','sql','hta','dll','bat','com','sh','bash','py','pl','js',
        'php','php3','php4','php5','php7','pht','phar','cgi',
        'svg','swf','dfxp','rar','exe','htaccess','htpasswd','config','ini',
        // Markup and script formats an admin could type into the allowed list themselves; each renders or runs when opened.
        'xhtml','xht','svgz','mhtml','mht','xsl','xslt','shtml','hta','jar','jnlp','lnk',
        'msi','vbs','vbe','ps1','ps1xml','psm1','scr','pif','wsf','wsh','reg','cer',
        'zip','tar','gz','7z',
        // Macro-enabled Office files, more scripts, and disk images (which Windows opens like a folder, carrying any of
        // the above inside): the recipients open what the form forwards.
        'docm','dotm','xlsm','xltm','xlam','pptm','potm','ppsm','ppam','sldm',
        'cmd','php8','phps','cpl','msc','msix','appx',
        'iso','img','vhd','vhdx','dmg',
        // Macro-capable binary workbook, add-ins and query files, OneNote, compiled help and internet shortcuts: each runs
        // code or fetches from elsewhere when opened.
        'xlsb','xll','iqy','slk','one','chm','url',
    ];

    // Second line of defense (real MIME via finfo); image/svg+xml is denied since mPDF would parse its XML/script payloads.
    private const BLOCKED_MIME_TYPES = [
        'text/html', 'application/x-httpd-php', 'application/x-php', 'text/x-php',
        'application/x-sh', 'application/x-msdownload', 'application/x-executable',
        'text/x-shellscript', 'text/x-python', 'text/x-perl',
        'text/javascript', 'application/javascript', 'application/java-archive',
        'image/svg+xml', 'application/xml', 'text/xml',
        'application/zip', 'application/x-tar', 'application/gzip', 'application/x-7z-compressed',
        // Native executables and Windows script hosts, whatever extension they arrive under.
        'application/x-dosexec', 'application/vnd.microsoft.portable-executable', 'application/x-msdos-program',
        'application/x-msi', 'application/x-ms-installer', 'application/x-bat', 'text/x-msdos-batch',
        'application/hta', 'application/x-ms-shortcut', 'application/x-elf', 'application/x-sharedlib',
        'application/x-pie-executable', 'application/x-mach-binary', 'application/x-shockwave-flash',
    ];

    // ZIP-container document formats that a server's type database may report only as application/zip; see validate().
    private const ZIP_CONTAINER_EXTS = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'];

    private const TYPE_GROUPS = [
        'images'    => ['jpg','jpeg','png','gif','bmp','tiff','webp'],
        // Not the older .doc, .xls, .ppt and .rtf: they can carry macros too. An admin who needs them lists them per field
        // (allowed_types); the macro-enabled formats themselves are always refused (BLOCKED_TYPES).
        'documents' => ['pdf','docx','xlsx','odt','ods','pptx','txt'],
        'audio'     => ['mp3','ogg','wav','m4a','flac'],
        'video'     => ['mp4','mov','avi','wmv','mkv'],
    ];

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/UploadField.css');
    }

    /**
     * The number of files chosen, "" for none. File names differ between browser and server (sanitize_file_name()
     * rewrites them), a count does not: front.js reads the file input's files the same way.
     *
     * @param mixed $raw    What extractValue() returned.
     * @param array $config Field configuration.
     * @return mixed
     */
    public function conditionValue(mixed $raw, array $config): mixed
    {
        $names = is_array($raw) ? ($raw['name'] ?? null) : null;
        $names = is_array($names) ? $names : [$names];
        $count = count(array_filter($names, static fn($n) => is_scalar($n) && (string) $n !== ''));
        return $count > 0 ? (string) $count : '';
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'upload';
    }

    public function getLabel(): string
    {
        return __('File upload', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-file-arrow-up';
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/UploadField.js');
    }

    /**
     * Renders the field HTML.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $req      = !empty($config['required']) ? ' required aria-required="true"' : '';
        $multiple = !empty($config['multiple']) ? ' multiple' : '';
        $accept   = $this->buildAccept($config);
        $acc_attr = $accept !== '' ? ' accept="' . esc_attr($accept) . '"' : '';

        $max      = self::maxSizeMb($config);
        // Cap client-side hint to the server's actual max_file_uploads ini limit — no point
        // letting the user pick more files than PHP will accept from the multipart request
        $max_files = $multiple ? max(1, (int)(ini_get('max_file_uploads') ?: 20)) : 1;
        $inner  = '<div class="fabricator-upload-zone"'
            . ' data-multiple="' . ($multiple ? '1' : '0') . '"'
            . ' data-max-files="' . $max_files . '"'
            . '>';
        $inner .= '<input type="file" id="' . esc_attr($field_id) . '" name="'
            . esc_attr($field_id) . ($multiple ? '[]' : '') . '"'
            . ' class="fabricator-upload-input"' . $acc_attr . $multiple . $req . '>';
        $inner .= '<div class="fabricator-upload-zone-body" aria-hidden="true">'
            . '<span class="fabricator-upload-icon">↑</span>'
            . '<span class="fabricator-upload-prompt">' . esc_html__('Drop file here or', 'formfabricator') . ' '
            . '<span class="fabricator-upload-link">' . esc_html__('click to select', 'formfabricator') . '</span>'
            . '</span>'
            . '</div>';
        $inner .= '</div>';
        $inner .= '<div class="fabricator-upload-error" role="alert"></div>';
        $inner .= '<ul class="fabricator-upload-filelist" aria-live="polite"></ul>';

        if ($accept !== '') {
            $inner .= '<p class="fabricator-field-hint">'
                // translators: %s: comma-separated list of allowed file extensions.
                . sprintf(esc_html__('Allowed file types: %s', 'formfabricator'), esc_html($accept)) . '</p>';
        }
        $inner .= '<p class="fabricator-field-hint">'
            // translators: %s: maximum file size in megabytes.
            . sprintf(esc_html__('Maximum file size: %s MB', 'formfabricator'), esc_html((string) $max)) . '</p>';

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the effective max file size in MB: the configured size, at most what a submission can hold as its only
     * upload. mapNormalized() base64-encodes the full upload in memory, and the memory budget bounds that cost.
     *
     * @param array $config Field configuration.
     */
    private static function maxSizeMb(array $config): int
    {
        return min(
            (int)($config['max_size_mb'] ?? 10),
            intdiv(\FabricatorForms\Utils\MemoryBudget::largestUploadBytes(), 1024 * 1024)
        );
    }

    /**
     * Builds the accept attribute value from allowed file type groups and custom types.
     *
     * @param array $config Field configuration.
     * @return string Comma-separated list of allowed file extensions.
     */
    private function buildAccept(array $config): string
    {
        $exts = [];
        foreach (array_keys(self::TYPE_GROUPS) as $group) {
            if (!empty($config['allow_' . $group])) {
                foreach (self::TYPE_GROUPS[$group] as $ext) {
                    $exts[] = '.' . $ext;
                }
            }
        }
        $custom = trim($config['allowed_types'] ?? '');
        if ($custom !== '') {
            foreach (preg_split('/[\s,]+/', $custom) as $e) {
                $e = trim($e);
                if ($e !== '') {
                    $exts[] = strpos($e, '.') === 0 ? $e : '.' . $e;
                }
            }
        }
        // Strip blocked extensions even if the admin explicitly listed them in
        // allowed_types — the deny-list always wins over form-builder config
        $blocked = self::BLOCKED_TYPES;
        $exts    = array_unique(
            array_filter(
                $exts,
                static function (string $e) use ($blocked): bool {
                    return !in_array(ltrim($e, '.'), $blocked, true);
                }
            )
        );
        return implode(',', $exts);
    }

    /**
     * Returns true: upload fields require multipart/form-data on the form element.
     *
     * @return bool
     */
    public function needsMultipartEncoding(): bool
    {
        return true;
    }

    /**
     * Returns the raw $_FILES entry for this upload field.
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // No wp_unslash(): wp_magic_quotes() never slashes $_FILES, so unslashing only damages it. It strips the
        // backslashes out of a Windows tmp_name, is_readable() then fails, and validate() would skip the MIME check.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- verified above; 'name' sanitized below, the other keys are PHP-generated and tmp_name is gated by is_uploaded_file().
        $file = isset($_FILES[$field_id]) ? $_FILES[$field_id] : null;
        // A shape this field's input never posts (name="f[a][]") is no upload of this field; see Cast::isFlatFilesEntry().
        if (!\FabricatorForms\Utils\Cast::isFlatFilesEntry($file) || !isset($file['name'])) {
            return null;
        }
        $file['name'] = is_array($file['name'])
            ? map_deep($file['name'], 'sanitize_file_name')
            : sanitize_file_name($file['name']);
        return $file;
    }

    /**
     * Returns true when $_FILES-shaped 'name' (scalar or array, for multi-file inputs) actually contains a
     * filename — a file literally named "0" must not be treated the same as "no file" by empty().
     *
     * @param mixed $name The 'name' value from a $_FILES-shaped array.
     */
    private static function hasFileName(mixed $name): bool
    {
        if (is_array($name)) {
            foreach ($name as $n) {
                if ($n !== '' && $n !== null) {
                    return true;
                }
            }
            return false;
        }
        return $name !== '' && $name !== null;
    }

    /**
     * Validates the submitted value.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return bool|string True on valid, error message string on invalid.
     */
    public function validate(mixed $value, array $config): bool|string
    {
        // Messages below are not escaped: front.js shows them via .textContent.
        $file = is_array($value) ? $value : null;

        if (!empty($config['required'])) {
            if (!$file || !self::hasFileName($file['name'] ?? null)) {
                $label = $config['label'] ?? __('File', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s: Please upload a file.', 'formfabricator'), $label);
            }
        }

        if ($file && self::hasFileName($file['name'] ?? null)) {
            $names     = is_array($file['name']) ? $file['name'] : [$file['name']];
            $tmp_names = is_array($file['tmp_name'] ?? null) ? $file['tmp_name'] : [$file['tmp_name'] ?? ''];
            $sizes     = is_array($file['size'] ?? null) ? $file['size'] : [$file['size'] ?? 0];
            $errors    = is_array($file['error'] ?? null) ? $file['error'] : [$file['error'] ?? UPLOAD_ERR_OK];

            // A failed upload still populates ['name'] while zeroing ['size']/['tmp_name'], so every check below would otherwise pass silently.
            foreach ($errors as $i => $code) {
                $code = (int) $code;
                if ($code === UPLOAD_ERR_OK || $code === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $failed = sanitize_file_name((string) ($names[$i] ?? ''));
                if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                    // translators: %s: uploaded file name.
                    return sprintf(__('"%s" is larger than this server accepts.', 'formfabricator'), $failed);
                }
                if ($code === UPLOAD_ERR_PARTIAL) {
                    // translators: %s: uploaded file name.
                    return sprintf(__('"%s" was only partially uploaded. Please try again.', 'formfabricator'), $failed);
                }
                \FabricatorForms\fabricator_log(
                    'FabricatorForms: upload failed for ' . \FabricatorForms\fabricator_log_file((string) $failed) . ' with PHP error code ' . $code
                );
                // translators: %s: uploaded file name.
                return sprintf(__('"%s" could not be uploaded. Please try again.', 'formfabricator'), $failed);
            }

            // Server-side backstop: a direct POST can send array-keyed files regardless of client-side limits.
            $max_files = empty($config['multiple']) ? 1 : max(1, (int)(ini_get('max_file_uploads') ?: 20));
            if (count($names) > $max_files) {
                return empty($config['multiple'])
                    ? __('Only one file may be uploaded for this field.', 'formfabricator')
                    : sprintf(
                        // translators: %d: maximum number of files allowed.
                        __('Too many files uploaded (maximum: %d).', 'formfabricator'),
                        $max_files
                    );
            }

            $max_bytes = self::maxSizeMb($config) * 1024 * 1024;
            // Null without PHP's fileinfo extension: uploads are then refused below, since no file's real type can be read.
            $finfo     = class_exists('finfo') ? new \finfo(FILEINFO_MIME_TYPE) : null;
            // <input accept> from buildAccept() only constrains the browser picker; enforce the allow-list here too.
            $allowed_exts = array_filter(array_map(
                static function (string $e): string {
                    return ltrim(strtolower(trim($e)), '.');
                },
                explode(',', $this->buildAccept($config))
            ));
            foreach ($names as $i => $name) {
                $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
                if (in_array($ext, self::BLOCKED_TYPES, true)) {
                    // translators: %s: rejected file extension.
                    return sprintf(__('File type ".%s" is not allowed for security reasons.', 'formfabricator'), $ext);
                }
                // Fail closed: an empty $allowed_exts means nothing is configured, so nothing should be accepted.
                if (!in_array($ext, $allowed_exts, true)) {
                    // translators: %s: rejected file extension.
                    return sprintf(__('File type ".%s" is not permitted for this field.', 'formfabricator'), $ext);
                }
                if ((int)($sizes[$i] ?? 0) > $max_bytes) {
                    return sprintf(
                        // translators: %1$s: file name, %2$d: maximum allowed file size in megabytes.
                        __('"%1$s" exceeds the maximum file size of %2$d MB.', 'formfabricator'),
                        (string) $name,
                        self::maxSizeMb($config)
                    );
                }
                $tmp = $tmp_names[$i] ?? '';
                // PHP always gives an accepted upload a temp path. One that is missing or unreadable can't be type-checked
                // or attached, so the visitor learns which file failed and can retry, instead of the form going out without it.
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- $tmp is the temp path PHP itself assigned in $_FILES, not client input; is_uploaded_file() gates it below.
                if (!is_string($tmp) || $tmp === '' || !is_readable($tmp)) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms: uploaded temp file not readable: ' . \FabricatorForms\fabricator_log_file((string) $name)
                    );
                    // translators: %s: uploaded file name.
                    return sprintf(__('"%s" could not be uploaded. Please try again.', 'formfabricator'), (string) $name);
                }
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- this IS the check that the path came from a genuine HTTP upload.
                if (!is_uploaded_file($tmp)) {
                    return __('File upload could not be verified.', 'formfabricator');
                }
                if ($finfo === null) {
                    \FabricatorForms\fabricator_log('FabricatorForms: upload refused, the PHP fileinfo extension is not installed, so the file type cannot be verified.');
                    // translators: %s: uploaded file name.
                    return sprintf(__('"%s" could not be uploaded. Please try again.', 'formfabricator'), (string) $name);
                }
                $real_mime = $finfo->file($tmp) ?: '';
                // Office Open XML and OpenDocument files are ZIP containers, and some hosts' type databases report nothing more
                // specific. For those extensions a ZIP reading is expected, so WordPress's own check below decides instead.
                $zip_container = in_array($ext, self::ZIP_CONTAINER_EXTS, true)
                    && in_array($real_mime, ['application/zip', 'application/x-zip-compressed'], true);
                if (!$zip_container && in_array($real_mime, self::BLOCKED_MIME_TYPES, true)) {
                    // translators: %s: rejected file extension.
                    return sprintf(__('File type ".%s" is not allowed for security reasons.', 'formfabricator'), $ext);
                }
                // Extension and content must agree (an .exe renamed to .pdf). Core decides for extensions it knows;
                // others have only the blocklist.
                if (wp_check_filetype((string) $name)['ext'] !== false) {
                    $checked = wp_check_filetype_and_ext($tmp, (string) $name);
                    if (empty($checked['ext'])) {
                        // translators: %s: uploaded file name.
                        return sprintf(__('The content of "%s" does not match its file type.', 'formfabricator'), (string) $name);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Returns a normalized entry with materialized uploaded files.
     *
     * @param string $field_id Field identifier.
     * @param string $label    Field label.
     * @param mixed  $value    Raw submitted value.
     * @param array  $config   Field configuration.
     * @param array  $context  Submission context (carries 'files').
     * @return array<string, array>
     */
    public function mapNormalized(
        string $field_id,
        string $label,
        mixed $value,
        array $config,
        array $context
    ): array {
        $file_data = ($context['files'] ?? [])[$field_id] ?? null;
        // As extractValue(): a nested shape is no upload of this field.
        if (!\FabricatorForms\Utils\Cast::isFlatFilesEntry($file_data)) {
            $file_data = null;
        }

        if (!$file_data || (is_array($file_data) && !self::hasFileName($file_data['name'] ?? null))) {
            return [$field_id => [
                'label'              => $label,
                'type'               => 'upload',
                'value'              => __('[No entry]', 'formfabricator'),
                'materialized_files' => [],
            ]];
        }

        $files_list = [];
        if (isset($file_data['name']) && is_array($file_data['name'])) {
            foreach ($file_data['name'] as $i => $name) {
                if (($file_data['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $files_list[] = [
                        'name'     => $name,
                        'tmp_name' => $file_data['tmp_name'][$i] ?? '',
                        'type'     => $file_data['type'][$i] ?? '',
                        'size'     => $file_data['size'][$i] ?? 0,
                        'error'    => UPLOAD_ERR_OK,
                    ];
                }
            }
        } elseif (isset($file_data['tmp_name'])
            && $file_data['error'] === UPLOAD_ERR_OK
        ) {
            $files_list[] = $file_data;
        }

        $info_parts   = [];
        $materialized = [];
        // validate() already refused uploads without fileinfo; the guard only keeps this path from dying if called directly.
        $finfo        = class_exists('finfo') ? new \finfo(FILEINFO_MIME_TYPE) : null;

        foreach ($files_list as $file) {
            $tmp  = $file['tmp_name'] ?? '';
            // Without bidi controls, which reorder how the name reads ("invoice<U+202E>fdp.exe" shows as "invoiceexe.pdf").
            $name = \FabricatorForms\PDF\PdfUtils::stripBidiControls(sanitize_file_name($file['name'] ?? 'unknown'));

            $binary = false;
            if (!$tmp || !is_readable($tmp) || !is_uploaded_file($tmp)) {
                \FabricatorForms\fabricator_log(
                    'FabricatorForms: upload not readable: ' . \FabricatorForms\fabricator_log_file((string) $name)
                );
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read of the uploaded $_FILES tmp_name, not a remote URL; wp_remote_get() would be wrong.
                $binary = file_get_contents($tmp);
            }

            if ($binary === false) {
                // Still named, so the recipient can see that a file was submitted and did not arrive.
                $info_parts[] = sprintf(
                    // translators: %s: uploaded file name.
                    __('%s (not attached - unreadable)', 'formfabricator'),
                    $name
                );
                continue;
            }

            $mime = sanitize_mime_type(
                ($finfo !== null ? $finfo->file($tmp) : false) ?: 'application/octet-stream'
            );
            $size = strlen($binary);

            // Type/size as this plugin verified them, not as the browser reported them — matches the materialized_files record below.
            $info_parts[] = sprintf(
                '%s (%s, %s KB)',
                $name,
                $mime,
                round($size / 1024, 1)
            );

            $materialized[] = [
                'name'   => $name,
                'mime'   => $mime,
                'size'   => $size,
                'sha256' => hash('sha256', $binary),
                // False for an image the PDF can't show (unreadable type or too large); pdfData() skips it.
                'pdf_embeddable' => !str_starts_with($mime, 'image/')
                    || (\FabricatorForms\PDF\PdfUtils::embeddableImageMime($mime) && \FabricatorForms\PDF\PdfUtils::precheckDimensions($binary)),
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- carries raw binary across a JSON/array boundary between the field handler and the PDF/mail layer. Not obfuscation.
                'base64' => base64_encode($binary),
            ];
        }

        return [$field_id => [
            'label'              => $label,
            'type'               => 'upload',
            'value'              => $info_parts
                ? implode('; ', $info_parts) : __('[No entry]', 'formfabricator'),
            'materialized_files' => $materialized,
        ]];
    }

    /**
     * Override: show filename as text; embed images inline. Non-image files (PDF, Word, audio, video,
     * archives) show filename only.
     *
     * @param array $field Normalized entry from FieldRegistry::mapSubmission().
     * @return array PDF render descriptor.
     */
    public function pdfData(array $field): array
    {
        $desc = $this->pdf($field);

        foreach ($field['materialized_files'] ?? [] as $file) {
            $mime   = $file['mime'] ?? '';
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes binary handed over the array boundary described at the encode site (strict mode). Not obfuscation.
            $binary = !empty($file['base64']) ? base64_decode($file['base64'], true) : false;
            if ($binary === false || !str_starts_with($mime, 'image/') || $mime === 'image/svg+xml'
                || ($file['pdf_embeddable'] ?? true) === false
            ) {
                continue;
            }
            $desc->attachImage($binary, (string)($file['name'] ?? 'upload'), $mime);
        }

        return $desc->build();
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(
            parent::getDefaultConfig(),
            [
            'allow_images'    => true,
            'allow_documents' => true,
            'allow_audio'     => false,
            'allow_video'     => false,
            'allowed_types'   => '',
            'max_size_mb'     => 10,
            'multiple'        => false,
            ]
        );
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return [
            [
                'key'   => 'max_size_mb',
                'type'  => 'number',
                'label' => __('Max. file size (MB)', 'formfabricator'),
            ],
            [
                'key'   => 'multiple',
                'type'  => 'checkbox',
                'label' => __('Allow multiple files', 'formfabricator'),
            ],
        ];
    }

    /**
     * Returns the advanced settings schema for the field editor.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        $blocked = implode(', .', self::BLOCKED_TYPES);
        $notice  = sprintf(
            // translators: %s: comma-separated list of blocked file extensions.
            __('Blocked for security reasons: .%s. These types cannot be allowed.', 'formfabricator'),
            $blocked
        );
        $nd = __('These files are shown in the PDF as a filename only and cannot be cryptographically verified.', 'formfabricator');
        return [
            [
                'type'  => 'notice',
                'level' => 'warning',
                'text'  => $notice,
            ],
            [
                'key'        => 'allow_images',
                'type'       => 'checkbox',
                'label'      => __('Images (jpg, png, gif, bmp, tiff, webp)', 'formfabricator'),
                'disclaimer' => __('Images the PDF cannot show (TIFF, and WEBP on servers without WEBP support) appear in it as a filename only, are attached next to it like documents, and cannot be cryptographically verified.', 'formfabricator'),
            ],
            [
                'key'        => 'allow_documents',
                'type'       => 'checkbox',
                'label'      => __('Documents (pdf, docx, xlsx, pptx, odt, ods, txt)', 'formfabricator'),
                'disclaimer' => $nd,
            ],
            [
                'key'        => 'allow_audio',
                'type'       => 'checkbox',
                'label'      => __('Audio (mp3, ogg, wav, m4a, flac)', 'formfabricator'),
                'disclaimer' => $nd,
            ],
            [
                'key'        => 'allow_video',
                'type'       => 'checkbox',
                'label'      => __('Video (mp4, mov, avi, wmv, mkv)', 'formfabricator'),
                'disclaimer' => $nd,
            ],
            [
                'key'   => 'allowed_types',
                'type'  => 'text',
                'label' => __('Additional types', 'formfabricator'),
                'hint'  => __('e.g. .pdf,.docx', 'formfabricator'),
            ],
        ];
    }
}
