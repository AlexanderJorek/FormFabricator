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
 * @version   1.0.8
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
 * Abstract base class for all FormFabricator field types.
 */
abstract class BaseField
{
    // In-memory cache so a field asset file (JS/CSS) is only ever read from disk once per
    // request, even though multiple fields/requests call getStyles()/getClientInit() etc.
    private static array $assetCache = [];

    // Reads a field's own JS/CSS asset file instead of embedding it as a PHP string: WordPress.org rejects plugins
    // that use HEREDOC/NOWDOC, whose contents its sniffers can't check for escaping.
    protected static function readFieldAsset(string $relativePath): string
    {
        if (!isset(self::$assetCache[$relativePath])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystemFunctions -- $relativePath is always a hardcoded literal at each call site, never request input.
            $path = \FABRICATOR_FORMS_PATH . $relativePath;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read, not a remote URL; wp_remote_get() would be wrong here.
            $contents = is_readable($path) ? file_get_contents($path) : false;
            self::$assetCache[$relativePath] = $contents !== false ? rtrim($contents, "\r\n") : '';
        }
        return self::$assetCache[$relativePath];
    }

    /**
     * Caps an array-valued raw POST value before sanitizing. Must run BEFORE any recursive-sanitize pass.
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

    /**
     * Drops non-scalar leaves so a nested POST (e.g. name[first][0]=x) can't stringify to "Array".
     *
     * @param mixed $raw Sanitized value straight out of map_deep()/capRawArray().
     * @return array<string,string> Subfield map with string leaves only.
     */
    protected static function scalarSubfieldMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $out[$key] = is_scalar($value) ? (string) $value : '';
        }
        return $out;
    }

    // Always >= other_max_length so truncation never pre-empts the "too long" error.
    private const OTHER_TEXT_HARD_CAP = 5000;

    // Shared cap so the limit can't drift between fields' implementations.
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
        $length = mb_strlen($other);
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

    // Hard ceiling for Text/Textarea content; bounds worst-case PDF generation cost.
    private const TEXT_FIELD_HARD_CAP = 100000;

    // Clamps limit_max so the render() hint and validate() backstop can't disagree.
    protected static function clampTextMax(int $configured): int
    {
        return $configured > 0 ? min($configured, self::TEXT_FIELD_HARD_CAP) : self::TEXT_FIELD_HARD_CAP;
    }

    // Server-side char-count backstop, enforced regardless of configured
    // limit_type/limit_max — a "words" limit doesn't bound character length.
    protected static function validateTextHardCap(string $value): bool|string
    {
        $length = mb_strlen($value);
        if ($length > self::TEXT_FIELD_HARD_CAP) {
            // translators: %1$d: absolute maximum character count allowed, %2$d: current character count.
            return sprintf(__('Please enter at most %1$d characters (currently: %2$d).', 'formfabricator'), self::TEXT_FIELD_HARD_CAP, $length);
        }
        return true;
    }

    // Cheap prefix check only; real magic-byte verification is in materializeSignature().
    protected static function isSignatureDataUri(string $value, string $expected_format = ''): bool
    {
        if ($expected_format !== '') {
            return str_starts_with($value, 'data:image/' . $expected_format . ';base64,');
        }
        return str_starts_with($value, 'data:image/');
    }

    /**
     * Whether a submitted signature is a typed name rather than a drawing (an image data URI); both arrive in the pad's
     * one hidden input. Every pad offers typing for anyone who can't draw (WCAG 2.1.1 Keyboard).
     *
     * @param mixed $value Submitted signature value.
     * @return bool
     */
    protected static function isTypedSignature(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && !str_starts_with($value, 'data:');
    }

    /**
     * A typed signature as the mail, the PDF and the seal record it: the name, marked as typed.
     *
     * @param string $name The name as typed.
     * @return string
     */
    protected static function typedSignatureRecord(string $name): string
    {
        // translators: %s: the name the visitor typed as their signature.
        return sprintf(__('%s (signed by typing the name)', 'formfabricator'), trim($name));
    }

    /**
     * The button in a signature pad's toolbar that switches between drawing and typing the name (SignatureField.js).
     *
     * @param string $type_label Its text while the pad draws.
     * @param string $draw_label Its text while the name is typed.
     * @return string
     */
    protected static function signatureModeButton(string $type_label, string $draw_label): string
    {
        return '<button type="button" class="fabricator-signature-mode" aria-pressed="false"'
            . ' data-type-label="' . esc_attr($type_label) . '" data-draw-label="' . esc_attr($draw_label) . '">'
            . esc_html($type_label) . '</button>';
    }

    /**
     * The name input a signature pad shows in place of the drawing area. Unnamed: SignatureField.js copies it into the
     * pad's hidden input.
     *
     * @param string $input_id    Id of the input.
     * @param string $input_label Its label.
     * @return string
     */
    protected static function typedSignatureInput(string $input_id, string $input_label): string
    {
        return '<div class="fabricator-signature-typed-row" hidden>'
            . '<label class="fabricator-label" for="' . esc_attr($input_id) . '">' . esc_html($input_label) . '</label>'
            . '<input type="text" id="' . esc_attr($input_id) . '" class="fabricator-input fabricator-signature-typed"'
            . ' autocomplete="name" maxlength="200">'
            . '</div>';
    }

    /**
     * Full signature check for validate(): the same decode and magic-byte test materializeSignature() applies, so
     * whatever passes is kept rather than sent out as "[No entry]".
     *
     * @param string $value           Submitted data URI.
     * @param string $expected_format 'png' or 'jpeg' to require that type, '' for either.
     * @return bool
     */
    protected static function isValidSignatureImage(string $value, string $expected_format = ''): bool
    {
        if (!self::isSignatureDataUri($value, $expected_format)) {
            return false;
        }
        $file = self::materializeSignature($value);
        if ($file === []) {
            return false;
        }
        if ($expected_format !== '' && $file[0]['mime'] !== 'image/' . $expected_format) {
            return false;
        }
        // Its declared size too: a signature is decoded outside the upload budget. A real pad's canvas stays far below
        // this and fits the base reservation (MemoryBudget::estimateBytes()).
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the image materializeSignature() just encoded. Not obfuscation.
        $binary = (string) base64_decode($file[0]['base64'], true);
        $size   = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => getimagesizefromstring($binary));
        return is_array($size)
            && \FabricatorForms\PDF\PdfUtils::precheckDimensions($binary)
            && (int) $size[0] * (int) $size[1] <= self::SIGNATURE_MAX_PIXELS;
    }

    // The largest signature image accepted, in pixels: well above any signature pad's canvas (8000 × 1000 at a pixel
    // ratio of 4), and about 32 MB to decode.
    private const SIGNATURE_MAX_PIXELS = 8000000;

    /**
     * Returns the shared client-side validation rule enforcing the "Other" text
     * word limit (the char limit is covered by the native maxlength attribute).
     *
     * @return array
     */
    protected static function otherTextClientRule(): array
    {
        return ['rule' => 'other-text-word-limit', 'fn' => self::readFieldAsset('assets/js/fields/BaseField.otherTextClientRule.js')];
    }

    // Field type slug. Constructors must stay side-effect free (registerDefaults() instantiates all).
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
     * Whether this field acts as a page-break marker (only PageBreakField returns true).
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
     * The value show/hide rules (and routing rules) read for this field, from what extractValue() returned.
     *
     * Must equal what front.js's getFieldValue() reads from the markup: the value itself for inputs named "id" or
     * "id[]"; joinedSubValues() for "id[key]" inputs.
     *
     * @param mixed $raw    What extractValue() returned.
     * @param array $config Field configuration.
     * @return mixed
     */
    public function conditionValue(mixed $raw, array $config): mixed
    {
        return $raw;
    }

    /**
     * front.js's reading of a field's "id[key]" inputs: the non-empty values of $keys, trimmed, in this order (the order
     * the field renders them), joined by one space. Keys starting with "_" are internal and never read.
     *
     * @param mixed    $raw  Sub-values by key, as extractValue() returned them.
     * @param string[] $keys The keys the field renders inputs for, in render order.
     * @return string
     */
    protected static function joinedSubValues(mixed $raw, array $keys): string
    {
        if (!is_array($raw)) {
            return '';
        }
        $parts = [];
        foreach ($keys as $key) {
            $value = is_scalar($raw[$key] ?? null) ? trim((string) $raw[$key]) : '';
            if ($value !== '' && !str_starts_with((string) $key, '_')) {
                $parts[] = $value;
            }
        }
        return implode(' ', $parts);
    }

    /**
     * The value a rule on this field reads while the field is hidden by its own or a group's conditions.
     *
     * As front.js reads it: [] for fields that render checkboxes under their own id, '' otherwise.
     *
     * @return array|string
     */
    public function hiddenConditionValue(): array|string
    {
        return '';
    }

    /**
     * Whether this field is a group container whose children render inline (only GroupField returns true).
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
     * Whether this field requires multipart/form-data encoding (only UploadField returns true).
     *
     * @return bool
     */
    public function needsMultipartEncoding(): bool
    {
        return false;
    }

    /**
     * Bytes of text this field puts into the PDF from its own configuration rather than from the answer, such as an
     * HTML block's content. A submission's memory estimate counts them: mPDF's layout costs many times their size.
     *
     * @param array $config Field configuration.
     * @return int
     */
    public function configTextBytes(array $config): int
    {
        return 0;
    }

    /**
     * Enqueues any front-end scripts required by this field type; override for third-party libraries.
     *
     * @return void
     */
    public function enqueueFrontScripts(): void
    {
    }

    /**
     * Whether this field's entry is included in the {all_fields} email summary block.
     * Override to false for layout-only fields with no user-submitted value.
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
     * Whether the "Required" checkbox is shown in the settings panel.
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
     * @param mixed  $value    Pre-filled value; the form renderer passes none.
     */
    abstract public function render(array $config, string $field_id, mixed $value = null): string;

    // Guards against extractValue() reading $_POST/$_FILES without a verified nonce; throws loudly
    // instead of silently accepting unauthenticated input if a future override forgets to check.
    final protected static function assertRequestNonceVerified(): void
    {
        if (!\FabricatorForms\Form\FormProcessor::nonceVerified()) {
            throw new \RuntimeException('Field value extraction attempted without a verified request nonce.');
        }
    }

    // Extracts the submitted value from $_POST/$_FILES; override for a different value shape.
    public function extractValue(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via assertRequestNonceVerified(), which reads FormProcessor's own record of its wp_verify_nonce() result for this request.
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
            return sprintf(__('%s is a required field.', 'formfabricator'), $label);
        }
        return true;
    }

    // Maps the value to a human-readable string for PDF/email; files go through mapNormalized().
    public function map(mixed $value, array $config): string
    {
        if ($this->isEmpty($value)) {
            return __('[No entry]', 'formfabricator');
        }
        return (string) $value;
    }

    // Client-side empty-check function; [] uses the generic fallback (first
    // visible input non-empty). Collected into window.FabricatorEmptyChecks.
    public function getClientEmptyCheck(): array
    {
        return [];
    }

    // Client-side validation rules, collected into window.FabricatorValidators.
    // Required/empty is handled implicitly — only declare FORMAT rules here.
    public function getClientValidation(): array
    {
        return [];
    }

    /**
     * Returns the client-side initialisation script for this field type.
     *
     * Return a JS function string: function(root) { ... }
     * Collected by Assets::frontFieldAssets() into window.FabricatorFieldInits.
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
     * Whether this field renders one control that carries $field_id, so the label can point at it with for="".
     *
     * False for a set of controls (checkboxes, radios, stars, sub-fields); wrap() then names the whole set.
     *
     * @param array $config Field configuration, since some fields render either way depending on it.
     * @return bool
     */
    public function labelsOwnControl(array $config): bool
    {
        unset($config);
        return true;
    }

    /**
     * Whether validate() should run only after every other field has passed.
     *
     * True where validating can't be undone, such as spending a single-use CAPTCHA token.
     *
     * @return bool
     */
    public function defersValidation(): bool
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
            'label'           => '',
            'required'        => false,
            'hide_label'      => false,
            'placeholder'     => '',
            'description'     => '',
            'autocomplete_on' => true,
            'autocomplete'    => '',
            'custom_class'    => '',
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

    // Keys rendered as plain text; anything else is HTML (Utils\HtmlSanitizer::sanitize()). Extend via plainTextConfigKeys().
    private const PLAIN_TEXT_CONFIG_KEYS = [
        'label', 'placeholder', 'description', 'custom_class', 'autocomplete', 'validation',
    ];

    /**
     * Config keys this field treats as plain text rather than HTML; override to add label-like keys.
     *
     * @return string[]
     */
    protected function plainTextConfigKeys(): array
    {
        return self::PLAIN_TEXT_CONFIG_KEYS;
    }

    /**
     * Public counterpart to plainTextConfigKeys(), for FormEditor's array-valued config.
     *
     * @param string $key Config key to test.
     * @return bool
     */
    public function isPlainTextConfigKey(string $key): bool
    {
        return in_array($key, $this->plainTextConfigKeys(), true);
    }

    // Sanitizes a single string config value. Every HTML value follows one rule set (Utils\HtmlSanitizer).
    public function sanitizeConfigValue(string $key, string $value): string
    {
        if (in_array($key, $this->plainTextConfigKeys(), true)) {
            return \sanitize_text_field($value);
        }
        return \FabricatorForms\Utils\HtmlSanitizer::sanitize($value);
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

    // What the Generator needs to render this field in the PDF; override for raw HTML/attachments.
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
    protected function pdf(array $field): \FabricatorForms\PDF\PdfDescriptor
    {
        return new \FabricatorForms\PDF\PdfDescriptor(
            esc_html((string)($field['value'] ?? ''))
        );
    }

    /**
     * Maps the field's submitted value to normalized output entries; override for multi-entry fields (Direct Debit Mandate).
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
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes binary handed over the array boundary described at the encode site (strict mode). Not obfuscation.
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
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- carries raw binary across a JSON/array boundary between the field handler and the PDF/mail layer. Not obfuscation.
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
        $req_attr    = $required ? ' <span class="fabricator-required" aria-hidden="true">*</span>' : '';
        $req_class   = $required ? ' fabricator-required-field' : '';
        $desc_html   = $description !== '' ? '<p class="fabricator-field-description">' . $description . '</p>' : '';

        // A set of controls has no element carrying $field_id for <label for>; it is named via aria-labelledby instead.
        $owns_control = $this->labelsOwnControl($config);
        $label_id     = $field_id . '-label';
        $group_attr   = '';
        if (!$hide_label && $label !== '') {
            $label_html = $owns_control
                ? '<label class="fabricator-label" for="' . esc_attr($field_id) . '">' . $label . $req_attr . '</label>'
                : '<div class="fabricator-label" id="' . esc_attr($label_id) . '">' . $label . $req_attr . '</div>';
            if (!$owns_control) {
                $group_attr = ' role="group" aria-labelledby="' . esc_attr($label_id) . '"';
            }
        } else {
            $label_html = '';
        }

        $client_rules  = $this->getClientValidation();
        $validate_attr = !empty($client_rules) ? ' data-validate="' . esc_attr(\FabricatorForms\Utils\Cast::jsonForAttribute(array_column($client_rules, 'rule'))) . '"' : '';

        // Builder-configured "CSS class(es)" (Appearance section) — admin-supplied, sanitize_text_field()'d
        // at save time; esc_attr() below is what actually makes embedding it here safe.
        $custom_class = trim((string)($config['custom_class'] ?? ''));
        $custom_class_attr = $custom_class !== '' ? ' ' . esc_attr($custom_class) : '';

        return '<div class="fabricator-field fabricator-field--' . esc_attr($config['type'] ?? 'text')
            . $req_class . ' ' . esc_attr($extra_class) . $custom_class_attr . '" data-field-id="' . esc_attr($field_id) . '"'
            . $validate_attr . $group_attr . '>'
            . $label_html
            . $desc_html
            . $inner
            . '<div class="fabricator-field-error" id="' . esc_attr($field_id)
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
            'class'       => 'fabricator-input',
            ],
            $extra
        );

        if (!empty($config['required'])) {
            $attrs['required'] = true;
            $attrs['aria-required'] = 'true';
        }

        // An explicit admin autocomplete choice overrides a field's own default (e.g. PhoneField's 'tel'); autocomplete_on === false wins over both.
        if (array_key_exists('autocomplete_on', $config) && $config['autocomplete_on'] === false) {
            $attrs['autocomplete'] = 'off';
        } else {
            $autocomplete_val = trim((string)($config['autocomplete'] ?? ''));
            if ($autocomplete_val !== '') {
                $attrs['autocomplete'] = $autocomplete_val;
            }
        }

        $html = '';
        foreach ($attrs as $k => $v) {
            // Only true is a bare attribute. "value equals name" was one too, so a placeholder of "placeholder" (or a
            // value of "value") rendered without its text.
            if ($v === true) {
                $html .= ' ' . esc_attr($k);
            } elseif ($v !== '' && $v !== false) {
                $html .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
            }
        }
        return $html;
    }
}
