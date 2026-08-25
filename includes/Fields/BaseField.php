<?php

/**
 * Abstract base class providing shared behaviour for all field types.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.3
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

/**
 * Abstract base class for all FormFabricator field types.
 */
abstract class BaseField
{
    /**
     * Caps an array-valued raw POST value before sanitizing, so a submitted
     * $field_id[...] array can't force unbounded sanitize calls. Must run
     * BEFORE any map_deep()/recursive-sanitize pass, not after.
     *
     * @return mixed Original scalar, or array truncated to $max_keys entries.
     */
    protected static function capRawArray(mixed $raw, int $max_keys = 32): mixed
    {
        if (!is_array($raw)) {
            return $raw;
        }
        return array_slice($raw, 0, $max_keys, true);
    }

    // Hard ceiling for the "Other" free-text value, always >= the admin-configured
    // other_max_length so capOtherText()'s truncation can never pre-empt
    // validateOtherText()'s "too long" error.
    private const OTHER_TEXT_HARD_CAP = 5000;

    // Shared cap for the "___other___" free-text companion value (Select/Radio/
    // Checkbox), so the limit can't drift between those fields' extractValue()/
    // extractFromRaw() implementations.
    protected static function capOtherText(mixed $raw): string
    {
        return mb_substr(sanitize_text_field(wp_unslash($raw)), 0, self::OTHER_TEXT_HARD_CAP);
    }

    // Client-side hint attribute for the configurable "Other" text limit;
    // validateOtherText() is the server-side backstop.
    protected static function otherInputAttrs(array $config): string
    {
        $max = self::clampOtherMax((int)($config['other_max_length'] ?? 0));
        if ($max <= 0) {
            return '';
        }
        $type = $config['other_max_type'] ?? 'chars';
        return $type === 'words' ? ' data-word-limit="' . $max . '"' : ' maxlength="' . $max . '"';
    }

    // Validates the "Other" value against the configured other_max_type/
    // other_max_length limit.
    protected static function validateOtherText(string $other, array $config): bool|string
    {
        $max = self::clampOtherMax((int)($config['other_max_length'] ?? 0));
        if ($max <= 0 || $other === '') {
            return true;
        }
        if (($config['other_max_type'] ?? 'chars') === 'words') {
            $count = count(preg_split('/\s+/', trim($other), -1, PREG_SPLIT_NO_EMPTY));
            if ($count > $max) {
                // translators: %1$d: maximum word count allowed, %2$d: current word count.
                return sprintf(__('Please enter at most %1$d words for "Other" (currently: %2$d).', 'formfabricator'), $max, $count);
            }
            return true;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($other) : strlen($other);
        if ($length > $max) {
            // translators: %1$d: maximum character count allowed, %2$d: current character count.
            return sprintf(__('Please enter at most %1$d characters for "Other" (currently: %2$d).', 'formfabricator'), $max, $length);
        }
        return true;
    }

    // Clamps an admin-configured other_max_length to OTHER_TEXT_HARD_CAP so
    // validateOtherText() can't expect text longer than capOtherText() allows.
    private static function clampOtherMax(int $configured): int
    {
        return $configured > 0 ? min($configured, self::OTHER_TEXT_HARD_CAP) : $configured;
    }

    // Hard ceiling for Text/Textarea content, applied even when limit_max is
    // unset. Bounds worst-case PDF generation/verification cost (see
    // Verificationpage.php), independent of the configured limit.
    private const TEXT_FIELD_HARD_CAP = 100000;

    // Clamps limit_max to TEXT_FIELD_HARD_CAP, substituting the hard cap when
    // unset, so the render() hint and validate() backstop can't disagree.
    protected static function clampTextMax(int $configured): int
    {
        return $configured > 0 ? min($configured, self::TEXT_FIELD_HARD_CAP) : self::TEXT_FIELD_HARD_CAP;
    }

    // Server-side char-count backstop, enforced regardless of configured
    // limit_type/limit_max — a "words" limit doesn't bound character length.
    protected static function validateTextHardCap(string $value): bool|string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length > self::TEXT_FIELD_HARD_CAP) {
            // translators: %1$d: absolute maximum character count allowed, %2$d: current character count.
            return sprintf(__('Please enter at most %1$d characters (currently: %2$d).', 'formfabricator'), self::TEXT_FIELD_HARD_CAP, $length);
        }
        return true;
    }

    // Cheap prefix sanity check for a signature-canvas data URI, shared by
    // SignatureField/SepaField. Not the security boundary — both fields still
    // verify real PNG/JPEG magic bytes via materializeSignature().
    protected static function isSignatureDataUri(string $value, string $expected_format = ''): bool
    {
        if ($expected_format !== '') {
            return str_starts_with($value, 'data:image/' . $expected_format . ';base64,');
        }
        return str_starts_with($value, 'data:image/');
    }

    /**
     * Returns the shared client-side validation rule enforcing the "Other" text
     * word limit (the char limit is covered by the native maxlength attribute).
     *
     * @return array
     */
    protected static function otherTextClientRule(): array
    {
        return ['rule' => 'other-text-word-limit', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('.forge-other-input[data-word-limit]');
                if (!inp || !inp.value.trim()) return null;
                var limit = parseInt(inp.dataset.wordLimit, 10);
                if (!limit) return null;
                var count = inp.value.trim().split(/\s+/).filter(Boolean).length;
                if (count <= limit) return null;
                var _i18n = window.ForgeForms && window.ForgeForms.i18n;
                return ((_i18n && _i18n.other_word_limit_exceeded) || 'Please enter at most %1$d words for "Other" (currently: %2$d).')
                    .replace('%1$d', limit).replace('%2$d', count);
            }
            JS];
    }

    // Field type slug. IMPORTANT: FieldRegistry::registerDefaults() instantiates
    // every subclass just to read this at bootstrap, so field constructors
    // (including any added to BaseField) must stay free of side effects.
    abstract public function getType(): string;

    /**
     * Returns the human-readable field type label.
     *
     * @return string
     */
    abstract public function getLabel(): string;

    /**
     * Returns the icon identifier for the field type tile.
     *
     * @return string
     */
    abstract public function getIcon(): string;

    /**
     * Whether clicking this field tile opens the settings panel.
     *
     * @return bool
     */
    public function hasSettingsPanel(): bool
    {
        return true;
    }

    /**
     * Whether this field acts as a page-break marker in multi-page forms.
     *
     * FormRenderer uses this to emit page-navigation HTML and page <div> wrappers
     * instead of calling render(). Only PageBreakField returns true.
     *
     * @return bool
     */
    public function isPageBreak(): bool
    {
        return false;
    }

    /**
     * Page-navigation markup for a page-break field. Only called when isPageBreak() returns true;
     * PageBreakField overrides this.
     *
     * @param array $config Field configuration.
     * @param int   $page   The page number being closed/opened.
     */
    public function renderBreak(array $config, int $page): string
    {
        return '';
    }

    /**
     * Whether this field is a group container whose children are rendered inline.
     *
     * FormRenderer uses this to call openTag()/closeTag() and recurse into children
     * instead of calling render(). Only GroupField returns true.
     *
     * @return bool
     */
    public function isGroupContainer(): bool
    {
        return false;
    }

    /**
     * Opening wrapper markup for a group container field. Only called when isGroupContainer() returns true;
     * group field classes override this.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Resolved field identifier.
     */
    public function openTag(array $config, string $field_id): string
    {
        return '';
    }

    /**
     * Closing wrapper markup for a group container field. Only called when
     * isGroupContainer() returns true; group field classes override this.
     *
     * @return string
     */
    public function closeTag(): string
    {
        return '';
    }

    /**
     * Whether this field requires multipart/form-data encoding on the form element.
     *
     * FormRenderer checks all fields and sets enctype="multipart/form-data" when any
     * returns true. Only UploadField returns true.
     *
     * @return bool
     */
    public function needsMultipartEncoding(): bool
    {
        return false;
    }

    /**
     * Enqueues any front-end scripts required by this field type.
     *
     * Called once per unique field type present in the form, before rendering.
     * Override to call wp_enqueue_script() for third-party libraries (e.g. reCAPTCHA).
     *
     * @return void
     */
    public function enqueueFrontScripts(): void
    {
    }

    /**
     * Whether this field's entry is included in the {all_fields} email summary block.
     *
     * MailSender skips entries where this returns false. Override in layout-only
     * fields (HtmlField, PageBreakField) that carry no user-submitted value.
     *
     * @return bool
     */
    public function includeInEmailSummary(): bool
    {
        return true;
    }

    /**
     * Whether this field's mapped value is admin-authored HTML to inject verbatim into the email
     * body, instead of MailSender's default nl2br(esc_html(...)) escaping for untrusted values.
     *
     * @return bool
     */
    public function rawEmailHtml(): bool
    {
        return false;
    }

    // Whether this field's value is included in the HMAC integrity seal.
    // Override to false for values that are a data URI or binary blob (e.g. SignatureField).
    public function includeValueInSeal(): bool
    {
        return true;
    }

    // Whether this field's value is a short text string, suitable for the
    // PDFLayoutEditor token-picker preview. Override to true in text-like fields.
    public function hasTextPreview(): bool
    {
        return false;
    }

    /**
     * Whether the "Pflichtfeld" (required) checkbox is shown in the settings panel.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return true;
    }

    /**
     * Renders the field HTML for frontend display.
     *
     * @param array  $config   Field configuration from form definition.
     * @param string $field_id Element ID (e.g. "field-3").
     * @param mixed  $value    Pre-filled value (for re-displaying on error).
     */
    abstract public function render(array $config, string $field_id, mixed $value = null): string;

    /**
     * The extractValue() counterpart for a field inside a repeatable Group.
     * FormProcessor slices the raw per-copy value out of $_POST and passes it
     * here as $raw instead of it being read directly from $_POST[$field_id].
     * Mirror whatever sanitizing extractValue() does so behavior matches
     * whether the field is used standalone or inside a Group.
     *
     * @param mixed $raw Raw value already sliced out of the group copy array.
     */
    public function extractFromRaw(mixed $raw): mixed
    {
        if (is_array($raw)) {
            return array_map(static fn($v) => sanitize_text_field(wp_unslash($v)), $raw);
        }
        return sanitize_text_field(wp_unslash((string)$raw));
    }

    /**
     * extractFromRaw() variant for Checkbox/Radio/Select's "Other" option,
     * whose typed text arrives as a *sibling* POST key (e.g. 'choice_other')
     * that extractFromRaw() alone can't reach. Default ignores $other_raw and
     * delegates to extractFromRaw(); override only if extractValue() also
     * attaches a '__other_text__' key.
     *
     * @param mixed $other_raw The "{child_id}_other" sibling value, if any.
     */
    public function extractFromRawWithOther(mixed $raw, mixed $other_raw): mixed
    {
        return $this->extractFromRaw($raw);
    }

    // Extracts the submitted value from $_POST/$_FILES, called by
    // FormProcessor before validate(). Override for fields whose value shape
    // differs (array POST keys, textarea, $_FILES, composite keys).
    public function extractValue(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        return isset($_POST[$field_id]) ? sanitize_text_field(wp_unslash($_POST[$field_id])) : '';
    }

    /**
     * Validates a submitted value.
     *
     * Returns true on success, or an error message string on failure.
     *
     * @param mixed $value  The submitted value.
     * @param array $config Field configuration array.
     *
     * @return bool|string
     */
    public function validate(mixed $value, array $config): bool|string
    {
        if (!empty($config['required']) && $this->isEmpty($value)) {
            $label = $config['label'] ?? __('Field', 'formfabricator');
            // translators: %s: field label.
            return sprintf(__('%s is a required field.', 'formfabricator'), esc_html($label));
        }
        return true;
    }

    // Maps the value to a human-readable string for PDF/email; may return an
    // array with 'value'/'files' keys for upload/signature fields.
    public function map(mixed $value, array $config): string
    {
        if ($this->isEmpty($value)) {
            return __('[No entry]', 'formfabricator');
        }
        return (string) $value;
    }

    // Client-side empty-check function; [] uses the generic fallback (first
    // visible input non-empty). Collected into window.ForgeEmptyChecks.
    public function getClientEmptyCheck(): array
    {
        return [];
    }

    // Client-side validation rules, collected into window.ForgeValidators.
    // Required/empty is handled implicitly — only declare FORMAT rules here.
    public function getClientValidation(): array
    {
        return [];
    }

    /**
     * Returns the client-side initialisation script for this field type.
     *
     * Return a JS function string: function(root) { ... }
     * Collected by Assets::enqueueFront() into window.ForgeFieldInits.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return '';
    }

    /**
     * Returns field-specific CSS to inject inline on pages that load this form.
     *
     * Return raw CSS (no style tags). Empty string = no output.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return '';
    }

    /**
     * Whether client-side validation should be skipped for this field type.
     *
     * Set true for purely presentational fields (pagebreak, html).
     *
     * @return bool
     */
    public function skipValidation(): bool
    {
        return false;
    }

    /**
     * Returns default config values for the builder.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'       => '',
            'required'    => false,
            'hide_label'  => false,
            'placeholder' => '',
            'description' => '',
        ];
    }

    /**
     * Returns placeholder and description schema entries shared by most fields.
     *
     * @return array
     */
    protected function baseGeneralEntries(): array
    {
        return [
            [
                'key'   => 'placeholder',
                'type'  => 'text',
                'label' => __('Placeholder', 'formfabricator'),
            ],
            [
                'key'   => 'description',
                'type'  => 'text',
                'label' => __('Description', 'formfabricator'),
            ],
        ];
    }

    /**
     * Returns settings schema for the General tab.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return $this->baseGeneralEntries();
    }

    // Config keys always rendered as plain text, never raw/rich HTML. Keep
    // deliberately small — anything not listed here keeps the wp_kses_post()
    // default below (rich-text keys like consent_text, html_content, etc.).
    private const PLAIN_TEXT_CONFIG_KEYS = ['label', 'placeholder', 'description'];

    // Sanitizes a single string config value; override for a different
    // allowlist (e.g. HtmlField).
    public function sanitizeConfigValue(string $key, string $value): string
    {
        if (in_array($key, self::PLAIN_TEXT_CONFIG_KEYS, true)) {
            return \sanitize_text_field($value);
        }
        return \wp_kses_post($value);
    }

    /**
     * Returns settings schema for the Advanced tab.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        return [];
    }

    // What the Generator needs to render this field in the PDF. Default shows
    // the escaped value as a labeled row; override for raw HTML, file
    // attachments, or the media section. Use $this->pdf($field) to build.
    public function pdfData(array $field): array
    {
        return $this->pdf($field)->build();
    }

    /**
     * Creates a PdfDescriptor pre-filled with this field's escaped text value. Chain methods on it, then call
     * ->build() to get the array pdfData() returns.
     *
     * @param array $field Normalized entry from FieldRegistry::mapSubmission().
     */
    protected function pdf(array $field): \ForgeForms\PDF\PdfDescriptor
    {
        return new \ForgeForms\PDF\PdfDescriptor(
            esc_html((string)($field['value'] ?? ''))
        );
    }

    /**
     * Maps the field's submitted value to one or more normalized output entries. Returns array<string, array>
     * keyed by output key → normalized entry. Default wraps map() in a single entry keyed by $field_id.
     * Override for multi-entry fields (SEPA) or fields that materialize files. $context carries: ['files' =>
     * $_FILES subset, 'raw_values' => raw POST values]
     *
     * @param string $field_id Field identifier.
     * @param string $label    Field label.
     * @param mixed  $value    Raw submitted value.
     * @param array  $config   Field configuration.
     * @param array  $context  Submission context.
     * @return array<string, array>
     */
    public function mapNormalized(
        string $field_id,
        string $label,
        mixed $value,
        array $config,
        array $context
    ): array {
        return [$field_id => [
            'label' => $label,
            'type'  => $config['type'] ?? '',
            'value' => $this->map($value, $config),
        ]];
    }

    /**
     * Materializes a base64 data-URI signature into a file descriptor array.
     *
     * @param mixed  $value    Raw signature value (data: URI).
     * @param string $filename Output filename hint.
     * @return array File descriptor array, or empty array if invalid.
     */
    protected static function materializeSignature(
        mixed $value,
        string $filename = 'signature.png'
    ): array {
        if (empty($value) || !str_starts_with((string)$value, 'data:image/')) {
            return [];
        }
        $b64 = preg_replace('#^data:image/[^;]+;base64,#', '', (string)$value);
        $b64 = str_replace(' ', '+', $b64);
        $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $b64);
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $binary = base64_decode($b64, true);
        if ($binary === false) {
            return [];
        }
        if (str_starts_with($binary, "\x89PNG")) {
            $mime = 'image/png';
        } elseif (str_starts_with($binary, "\xff\xd8")) {
            $mime = 'image/jpeg';
        } else {
            return [];
        }
        return [[
            'name'   => $filename,
            'mime'   => $mime,
            'size'   => strlen($binary),
            'sha256' => hash('sha256', $binary),
            'base64' => base64_encode($binary),
        ]];
    }

    /**
     * Checks whether a submitted value is considered empty.
     *
     * @param mixed $value The value to check.
     */
    protected function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_array($value) && empty(array_filter($value, static fn($v) => $v !== ''))) {
            return true;
        }
        return false;
    }

    /**
     * Builds standard wrapper HTML around a field's inner content.
     *
     * @param string $field_id    Element ID for the field.
     * @param array  $config      Field configuration array.
     * @param string $inner       Inner HTML content.
     * @param string $extra_class Additional CSS class(es) for the wrapper.
     */
    protected function wrap(string $field_id, array $config, string $inner, string $extra_class = ''): string
    {
        $label       = esc_html($config['label'] ?? '');
        $required    = !empty($config['required']);
        $hide_label  = !empty($config['hide_label']);
        $description = esc_html($config['description'] ?? '');
        $req_attr    = $required ? ' <span class="forge-required" aria-hidden="true">*</span>' : '';
        $req_class   = $required ? ' forge-required-field' : '';
        $desc_html   = $description !== '' ? '<p class="forge-field-description">' . $description . '</p>' : '';

        $label_html = (!$hide_label && $label !== '') ? '<label class="forge-label" for="' . esc_attr($field_id) . '">' . $label . $req_attr . '</label>' : '';

        $client_rules  = $this->getClientValidation();
        $validate_attr = !empty($client_rules) ? ' data-validate="' . esc_attr(wp_json_encode(array_column($client_rules, 'rule'))) . '"' : '';

        return '<div class="forge-field forge-field--' . esc_attr($config['type'] ?? 'text')
            . $req_class . ' ' . esc_attr($extra_class) . '" data-field-id="' . esc_attr($field_id) . '"'
            . $validate_attr . '>'
            . $label_html
            . $desc_html
            . $inner
            . '<div class="forge-field-error" id="' . esc_attr($field_id)
            . '-error" role="alert" aria-live="polite"></div>'
            . '</div>';
    }

    /**
     * Builds an HTML attribute string for an input element.
     *
     * @param array  $config   Field configuration array.
     * @param string $field_id Element ID for the input.
     * @param string $type     Input type attribute value.
     * @param array  $extra    Additional attributes to merge.
     */
    protected function inputAttrs(array $config, string $field_id, string $type = 'text', array $extra = []): string
    {
        $attrs = array_merge(
            [
            'type'        => $type,
            'id'          => $field_id,
            'name'        => $field_id,
            'placeholder' => $config['placeholder'] ?? '',
            'class'       => 'forge-input',
            ],
            $extra
        );

        if (!empty($config['required'])) {
            $attrs['required'] = 'required';
            $attrs['aria-required'] = 'true';
        }

        $html = '';
        foreach ($attrs as $k => $v) {
            if ($v === true || $v === $k) {
                $html .= ' ' . esc_attr($k);
            } elseif ($v !== '' && $v !== false) {
                $html .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
            }
        }
        return $html;
    }
}
