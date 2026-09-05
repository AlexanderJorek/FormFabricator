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
 * @version   1.0.5
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
    // Extensions that can execute server-side or run scripts in a browser if opened
    // directly — always denied regardless of allowed_types config (defense-in-depth
    // against a malicious file renamed to a permitted-looking extension)
    private const BLOCKED_TYPES = [
        'htm','html','shtml','phtml','jse','jar','xml','css','asp','aspx',
        'jsp','jspx','sql','hta','dll','bat','com','sh','bash','py','pl','js',
        'php','php3','php4','php5','php7','pht','phar','cgi',
        'svg','swf','dfxp','rar','exe','htaccess','htpasswd','config','ini',
        'msi','vbs','vbe','ps1','ps1xml','psm1','scr','pif','wsf','wsh','reg','cer',
        'zip','tar','gz','7z',
    ];

    // Second line of defense (real MIME via finfo); image/svg+xml is denied since mPDF would parse its XML/script payloads.
    private const BLOCKED_MIME_TYPES = [
        'text/html', 'application/x-httpd-php', 'application/x-php', 'text/x-php',
        'application/x-sh', 'application/x-msdownload', 'application/x-executable',
        'text/x-shellscript', 'text/x-python', 'text/x-perl',
        'text/javascript', 'application/javascript', 'application/java-archive',
        'image/svg+xml', 'application/xml', 'text/xml',
        'application/zip', 'application/x-tar', 'application/gzip', 'application/x-7z-compressed',
    ];

    // Hard ceiling on max_size_mb: mapNormalized() base64-encodes the full upload in memory, so this bounds memory cost.
    private const MAX_SIZE_MB_HARD_CAP = 100;

    private const TYPE_GROUPS = [
        'images'    => ['jpg','jpeg','png','gif','bmp','tiff','webp'],
        'documents' => ['pdf','doc','docx','xls','xlsx','odt','ods','ppt','pptx','txt','rtf'],
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
                . sprintf(__('Allowed file types: %s', 'formfabricator'), esc_html($accept)) . '</p>';
        }
        $inner .= '<p class="fabricator-field-hint">'
            // translators: %s: maximum file size in megabytes.
            . sprintf(__('Maximum file size: %s MB', 'formfabricator'), $max) . '</p>';

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the effective max file size in MB, clamped against self::MAX_SIZE_MB_HARD_CAP.
     *
     * @param array $config Field configuration.
     */
    private static function maxSizeMb(array $config): int
    {
        return min((int)($config['max_size_mb'] ?? 10), self::MAX_SIZE_MB_HARD_CAP);
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above via assertRequestNonceVerified(); 'name' is sanitized below, other keys (tmp_name/size/error) are PHP-generated, not attacker text.
        $file = isset($_FILES[$field_id]) ? wp_unslash($_FILES[$field_id]) : null;
        if (!is_array($file) || !isset($file['name'])) {
            return $file;
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
        $file = is_array($value) ? $value : null;

        if (!empty($config['required'])) {
            if (!$file || !self::hasFileName($file['name'] ?? null)) {
                $label = $config['label'] ?? __('File', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s: Please upload a file.', 'formfabricator'), esc_html($label));
            }
        }

        if ($file && self::hasFileName($file['name'] ?? null)) {
            $names     = is_array($file['name']) ? $file['name'] : [$file['name']];
            $tmp_names = is_array($file['tmp_name'] ?? null) ? $file['tmp_name'] : [$file['tmp_name'] ?? ''];
            $sizes     = is_array($file['size'] ?? null) ? $file['size'] : [$file['size'] ?? 0];

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
            $finfo     = new \finfo(FILEINFO_MIME_TYPE);
            // Enforce the admin-configured allow-list server-side too — the <input accept>
            // attribute built by buildAccept() only constrains the browser's file picker,
            // a direct POST can otherwise submit any type not on the hard-coded deny-list.
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
                    return sprintf(__('File type ".%s" is not allowed for security reasons.', 'formfabricator'), esc_html($ext));
                }
                // Fail closed: an empty $allowed_exts means nothing is configured, so nothing should be accepted.
                if (!in_array($ext, $allowed_exts, true)) {
                    // translators: %s: rejected file extension.
                    return sprintf(__('File type ".%s" is not permitted for this field.', 'formfabricator'), esc_html($ext));
                }
                if ((int)($sizes[$i] ?? 0) > $max_bytes) {
                    return sprintf(
                        // translators: %1$s: file name, %2$d: maximum allowed file size in megabytes.
                        __('"%1$s" exceeds the maximum file size of %2$d MB.', 'formfabricator'),
                        esc_html((string)$name),
                        self::maxSizeMb($config)
                    );
                }
                $tmp = $tmp_names[$i] ?? '';
                // MIME check only runs for a real HTTP upload; unit-test stubs skip it since the tmp path isn't readable.
                if ($tmp && is_readable($tmp)) {
                    if (!is_uploaded_file($tmp)) {
                        return __('File upload could not be verified.', 'formfabricator');
                    }
                    $real_mime = $finfo->file($tmp) ?: '';
                    if (in_array($real_mime, self::BLOCKED_MIME_TYPES, true)) {
                        // translators: %s: rejected file extension.
                        return sprintf(__('File type ".%s" is not allowed for security reasons.', 'formfabricator'), esc_html($ext));
                    }
                }
            }
        }

        return true;
    }

    /**
     * Maps the field value to the normalized submission entry.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return string Normalized field entry.
     */
    public function map(mixed $value, array $config): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return __('[No entry]', 'formfabricator');
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
        $finfo        = new \finfo(FILEINFO_MIME_TYPE);

        foreach ($files_list as $file) {
            $tmp  = $file['tmp_name'] ?? '';
            $name = sanitize_file_name($file['name'] ?? 'unknown');
            $mime = sanitize_mime_type(
                $file['type'] ?? 'application/octet-stream'
            );
            $size = (int)($file['size'] ?? 0);

            $info_parts[] = sprintf(
                '%s (%s, %s KB)',
                $name,
                $mime,
                round($size / 1024, 1)
            );

            if (!$tmp || !is_readable($tmp) || !is_uploaded_file($tmp)) {
                \FabricatorForms\fabricator_log(
                    "FabricatorForms: Upload file not readable: {$name}"
                );
                continue;
            }

            $binary = file_get_contents($tmp);
            if ($binary === false) {
                continue;
            }

            $mime  = sanitize_mime_type(
                $finfo->file($tmp) ?: 'application/octet-stream'
            );

            $materialized[] = [
                'name'   => $name,
                'mime'   => $mime,
                'size'   => strlen($binary),
                'sha256' => hash('sha256', $binary),
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
            $binary = !empty($file['base64']) ? base64_decode($file['base64'], true) : false;
            if ($binary === false || !str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
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
                'key'   => 'allow_images',
                'type'  => 'checkbox',
                'label' => __('Images (jpg, png, gif, bmp, tiff, webp)', 'formfabricator'),
            ],
            [
                'key'        => 'allow_documents',
                'type'       => 'checkbox',
                'label'      => __('Documents (pdf, doc, docx, xls, xlsx, odt, ppt, pptx, txt)', 'formfabricator'),
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
