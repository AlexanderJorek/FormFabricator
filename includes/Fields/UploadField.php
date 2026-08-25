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
 * @version   1.0.4
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

    // Second line of defense: real MIME type (via finfo) is checked against this list too,
    // so a blocked file can't slip through by using an allowed extension with mismatched content.
    // image/svg+xml is explicitly denied even though it reports as "image/*" — SVG is XML and can
    // carry <script>/XXE payloads that mPDF's SVG renderer would parse when embedding the file.
    private const BLOCKED_MIME_TYPES = [
        'text/html', 'application/x-httpd-php', 'application/x-php', 'text/x-php',
        'application/x-sh', 'application/x-msdownload', 'application/x-executable',
        'text/x-shellscript', 'text/x-python', 'text/x-perl',
        'text/javascript', 'application/javascript', 'application/java-archive',
        'image/svg+xml', 'application/xml', 'text/xml',
        'application/zip', 'application/x-tar', 'application/gzip', 'application/x-7z-compressed',
    ];

    // Hard ceiling on the admin-configurable max_size_mb, independent of whatever
    // value the admin leaves configured. mapNormalized() calls file_get_contents()
    // on the full upload and base64-encodes it in memory, so memory cost scales
    // directly with whatever byte limit is actually enforced here — mirrors
    // BaseField's TEXT_FIELD_HARD_CAP/OTHER_TEXT_HARD_CAP backstop pattern.
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
        return <<<'CSS'
.fabricator-upload-zone {
    position: relative;
    border: 2px dashed var(--fabricator-border-input);
    border-radius: var(--fabricator-radius);
    background: var(--fabricator-bg);
    transition: border-color .15s, background .15s;
    cursor: pointer;
}
.fabricator-upload-zone:hover,
.fabricator-upload-zone.fabricator-upload-zone--drag {
    border-color: var(--fabricator-accent);
    background: var(--fabricator-accent-light);
}
.fabricator-upload-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%; height: 100%;
}
.fabricator-upload-zone-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 28px 16px;
    pointer-events: none;
    text-align: center;
}
.fabricator-upload-icon { font-size: 28px; color: var(--fabricator-text-subtle); line-height: 1; }
.fabricator-upload-prompt { font-size: 14px; color: var(--fabricator-text-muted); }
.fabricator-upload-link { color: var(--fabricator-accent); text-decoration: underline; }
.fabricator-upload-error {
    font-size: 13px;
    color: var(--fabricator-error, #cc1818);
    min-height: 1.2em;
    margin-top: 4px;
}
.fabricator-upload-filelist {
    list-style: none;
    margin: 6px 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.fabricator-upload-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px 3px 10px;
    background: var(--fabricator-accent-light, #e8f0fe);
    border: 1px solid var(--fabricator-accent, #2271b1);
    border-radius: 20px;
    font-size: 12px;
    color: var(--fabricator-text, #1d2327);
    max-width: 240px;
}
.fabricator-upload-chip-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.fabricator-upload-chip-remove {
    flex-shrink: 0;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0 2px;
    line-height: 1;
    font-size: 16px;
    color: var(--fabricator-text-muted, #646970);
}
.fabricator-upload-chip-remove:hover { color: var(--fabricator-error, #cc1818); }
CSS;
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
        return <<<'JS'
        function (root) {
            root.querySelectorAll('.fabricator-upload-zone').forEach(function (zone) {
                var input    = zone.querySelector('.fabricator-upload-input');
                var errEl    = zone.parentNode
                    ? zone.parentNode.querySelector('.fabricator-upload-error') : null;
                var listEl   = zone.parentNode
                    ? zone.parentNode.querySelector('.fabricator-upload-filelist') : null;
                var multiple = zone.dataset.multiple === '1';
                var maxFiles = parseInt(zone.dataset.maxFiles || '0', 10);

                function showError(msg) {
                    if (!errEl) return;
                    errEl.textContent = msg;
                    errEl.style.color = '';
                }
                function showNotice(msg) {
                    if (!errEl) return;
                    errEl.textContent = msg;
                    errEl.style.color = 'var(--fabricator-warning, #996600)';
                }
                function clearError() {
                    if (!errEl) return;
                    errEl.textContent = '';
                    errEl.style.color = '';
                }
                function publishCount(n) {
                    zone.dataset.fabricatorFileCount = String(n);
                }
                function renderChips(files) {
                    if (!listEl) return;
                    listEl.innerHTML = '';
                    clearError();
                    if (!files || !files.length) { publishCount(0); return; }
                    publishCount(files.length);
                    Array.from(files).forEach(function (file, idx) {
                        var li   = document.createElement('li');
                        li.className = 'fabricator-upload-chip';
                        var nm  = document.createElement('span');
                        nm.className   = 'fabricator-upload-chip-name';
                        nm.textContent = file.name;
                        nm.title       = file.name;
                        var btn = document.createElement('button');
                        btn.type      = 'button';
                        btn.className = 'fabricator-upload-chip-remove';
                        var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
                        btn.setAttribute(
                            'aria-label', ((_i18n && _i18n.upload_remove_prefix) || 'Remove: ') + file.name
                        );
                        btn.textContent = '×';
                        btn.addEventListener('click', function () {
                            try {
                                var dt = new DataTransfer();
                                Array.from(input.files).forEach(function (f, i) {
                                    if (i !== idx) dt.items.add(f);
                                });
                                input.files = dt.files;
                                renderChips(input.files);
                            } catch (e) { /* DataTransfer not supported */ }
                        });
                        li.appendChild(nm);
                        li.appendChild(btn);
                        listEl.appendChild(li);
                    });
                }
                function filterAllowed(fileList) {
                    var accept = (input ? input.accept : '') || '';
                    if (!accept) return Array.from(fileList);
                    var parts = accept.split(',').map(function (s) {
                        return s.trim().toLowerCase();
                    });
                    return Array.from(fileList).filter(function (f) {
                        var ext  = '.' + f.name.split('.').pop().toLowerCase();
                        var mime = (f.type || '').toLowerCase();
                        return parts.some(function (p) {
                            if (p.charAt(0) === '.') return p === ext;
                            if (p.slice(-2) === '/*') {
                                return mime.indexOf(p.slice(0, -1)) === 0;
                            }
                            return p === mime;
                        });
                    });
                }
                function checkLimit(files) {
                    if (maxFiles > 0 && files.length > maxFiles) {
                        var _i18nL = window.FabricatorForms && window.FabricatorForms.i18n;
                        showError(
                            (_i18nL && _i18nL.upload_too_many)
                                ? _i18nL.upload_too_many.replace('%d', maxFiles)
                                : 'Too many files. Maximum ' + maxFiles + ' allowed.'
                        );
                        return false;
                    }
                    return true;
                }
                function applyFiles(fileList) {
                    var all     = Array.from(fileList);
                    var allowed = filterAllowed(fileList);
                    if (!multiple && allowed.length > 1) {
                        allowed = [allowed[0]];
                    }
                    if (!allowed.length) {
                        var _i18nA = window.FabricatorForms && window.FabricatorForms.i18n;
                        showError((_i18nA && _i18nA.upload_no_types) || 'No allowed file types in selection.');
                        return;
                    }
                    if (!checkLimit(allowed)) return;
                    try {
                        var dt = new DataTransfer();
                        allowed.forEach(function (f) { dt.items.add(f); });
                        input.files = dt.files;
                        renderChips(input.files);
                        var skipped = all.length - allowed.length;
                        if (skipped > 0) {
                            var _i18nS = window.FabricatorForms && window.FabricatorForms.i18n;
                            var _skippedMsg = skipped === 1
                                ? ((_i18nS && _i18nS.upload_skipped_one) || '1 file was skipped due to file type.')
                                : ((_i18nS && _i18nS.upload_skipped_many) || '%d files were skipped due to file type.').replace('%d', skipped);
                            showNotice(_skippedMsg);
                        }
                    } catch (e) { /* DataTransfer not supported */ }
                }
                publishCount(0);
                if (input) {
                    input.addEventListener('change', function () {
                        applyFiles(this.files);
                    });
                    var form = input.closest('form');
                    if (form) {
                        form.addEventListener('reset', function () {
                            renderChips(null);
                        });
                        if (!form.dataset.fabricatorOverflowBound) {
                            form.dataset.fabricatorOverflowBound = '1';
                            form.addEventListener('fabricator:upload-overflow', function (ev) {
                                var firstField = null;
                                form.querySelectorAll('.fabricator-upload-zone').forEach(
                                    function (z) {
                                        var zi = z.querySelector('.fabricator-upload-input');
                                        var ze = z.parentNode
                                            ? z.parentNode.querySelector(
                                                '.fabricator-upload-error'
                                            ) : null;
                                        if (!ze || !zi || !zi.files || !zi.files.length) {
                                            return;
                                        }
                                        var _i18nO = window.FabricatorForms && window.FabricatorForms.i18n;
                                        ze.textContent = (_i18nO && _i18nO.upload_overflow)
                                            ? _i18nO.upload_overflow.replace('%1$d', ev.detail.total).replace('%2$d', ev.detail.max)
                                            : 'Too many files total (' + ev.detail.total + '). Max. ' + ev.detail.max + ' per submission.';
                                        if (!firstField) {
                                            firstField = z.closest('.fabricator-field') || z;
                                        }
                                    }
                                );
                                if (firstField) {
                                    var top = firstField.getBoundingClientRect().top
                                        + window.pageYOffset - 80;
                                    window.scrollTo(0, Math.max(0, top));
                                    firstField.setAttribute('tabindex', '-1');
                                    firstField.focus({ preventScroll: true });
                                }
                            });
                        }
                    }
                }
                zone.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    zone.classList.add('fabricator-upload-zone--drag');
                });
                zone.addEventListener('dragleave', function () {
                    zone.classList.remove('fabricator-upload-zone--drag');
                });
                zone.addEventListener('drop', function (e) {
                    e.preventDefault();
                    zone.classList.remove('fabricator-upload-zone--drag');
                    if (!input || !e.dataTransfer.files.length) return;
                    applyFiles(e.dataTransfer.files);
                });
            });
        }
        JS;
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
     * Returns the effective max file size in MB, clamping the admin-configured max_size_mb against
     * self::MAX_SIZE_MB_HARD_CAP so the value used to compute the actual byte limit can never exceed the hard
     * ceiling, regardless of what is stored in the field config.
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified once in FormProcessor::handle() before field extraction runs; 'name' is sanitized below, other keys (tmp_name/size/error) are PHP-generated, not attacker text.
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

            // Server-side backstop for the file-count limit — render()'s plain (non-`[]`)
            // input name and front.js's checkLimit() only constrain the browser's file
            // picker; a direct POST can send array-keyed file parts under this field's
            // name regardless of those client-side constraints, so a "single file" field
            // must still be re-checked here. Multiple mode falls back to the same
            // max_file_uploads ceiling used for the client-side hint in render().
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
                // Fail closed: an empty $allowed_exts means no type group (and no
                // custom extension) is configured for this field, so nothing should
                // be accepted — not "no restriction". Only skip the check for
                // legacy/malformed configs is never correct here.
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
                // MIME check only runs when the tmp file actually exists (real HTTP upload).
                // Non-readable paths (e.g. unit-test stubs) skip MIME verification — the
                // extension block above already ran. In production, is_uploaded_file() must
                // pass; a readable file that fails it is rejected to prevent path-traversal.
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
