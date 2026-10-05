<?php

/**
 * Example/template field showing the minimal field implementation.
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
 * ════════════════════════════════════════════════════════════
 *  HOW TO ADD A NEW FIELD
 * ════════════════════════════════════════════════════════════
 *
 * This file is a teaching document and never loads: Plugin::load() loads only the classes FieldRegistry::FIELD_MAP
 * names, and the file name doesn't match its class for the autoloader either. build.ps1 leaves it out of the package.
 * CONTRIBUTING.md ("Fields", "Coding rules", "JS suite") holds the rules referred to below.
 *
 * QUICK START
 * ───────────
 *   1. Create includes/Fields/MyField.php with class MyField extends BaseField. The file name must equal the class
 *      name: classes load through Composer's PSR-4 map.
 *   2. Implement getType(), getLabel(), getIcon() and render(), as in THE MINIMUM VIABLE FIELD below.
 *   3. Add 'MyField' => 'group:my-field' to FieldRegistry::FIELD_MAP (group: input, choice, personal, advanced,
 *      layout or system). The entry makes the class load and places it in the palette; the slug after the colon
 *      only documents getType(), which is authoritative (a mismatch is logged).
 *   4. Run php languages/make-pot.php for the new strings, and add tests (CONTRIBUTING.md, "Tests").
 *
 * THE MINIMUM VIABLE FIELD
 * ────────────────────────
 *   class MyField extends BaseField
 *   {
 *       public function getType(): string { return 'my-field'; }
 *       public function getLabel(): string { return __('My field', 'formfabricator'); }
 *       public function getIcon(): string { return 'fa-solid fa-star'; } // Font Awesome classes
 *
 *       public function render(array $config, string $field_id, mixed $value = null): string
 *       {
 *           return $this->wrap($field_id, $config, '<input' . $this->inputAttrs($config, $field_id) . '>');
 *       }
 *   }
 *
 *   BaseField already provides the rest: the required check, extraction with sanitize_text_field(), map() to a
 *   string, the email row and a plain-text PDF cell. Everything below is optional.
 *
 * RULES EVERY FIELD FOLLOWS
 * ─────────────────────────
 *   • Field classes never call each other; a helper several fields need goes in includes/Utils.
 *   • No HEREDOC/NOWDOC. CSS and JS go in their own files (assets/css/fields/, assets/js/fields/, named after the
 *     class, e.g. MyField.css or MyField.my-rule.js) and are read with self::readFieldAsset().
 *   • Prefer a BaseField hook over checking ($field['type'] === '...') elsewhere in the plugin.
 *   • $config is what was saved or imported, not merged with getDefaultConfig(): read every key with a default.
 *   • Strings are English and translated: __('…', 'formfabricator'), with a "// translators:" comment directly above
 *     any call that has placeholders.
 *   • Show/hide rules must read the field the same way in front.js and on the server (see CONDITIONS below).
 *
 * WHAT TO OVERRIDE, BY NEED
 * ─────────────────────────
 *   Format validation             validate() + getClientValidation()
 *   Interactive widget            getClientInit()
 *   Field-specific CSS            getStyles()
 *   Custom blank check            getClientEmptyCheck()
 *   Settings in the builder       getDefaultConfig(), getGeneralSchema(), getAdvancedSchema()
 *   Label-like settings           plainTextConfigKeys() (anything else is sanitized as HTML)
 *   Several inputs or $_FILES     extractValue(), map(), labelsOwnControl(), conditionValue()
 *   Checkboxes named after the id hiddenConditionValue() → []
 *   Several output rows or files  mapNormalized()
 *   Image or HTML in the PDF      pdfData()
 *   Flags                         see FLAGS below
 *
 * FIELD LIFECYCLE
 * ───────────────
 *   Builder               getDefaultConfig(), getGeneralSchema(), getAdvancedSchema(), sanitizeConfigValue()
 *   Page with a form      getStyles(), getClientInit(), getClientEmptyCheck(), getClientValidation() (every field
 *                         type's, inlined by Assets::frontFieldAssets()); enqueueFrontScripts(); render()
 *   Submission            extractValue() → conditionValue() → validate() → mapNormalized() (→ map()) → pdfData()
 *
 * WHERE TO LOOK
 * ─────────────
 *   UploadField      $_FILES: extractValue(), validate(), mapNormalized(), pdfData(), needsMultipartEncoding()
 *   AddressField     sub-inputs named id[key]: extractValue(), labelsOwnControl(), conditionValue()
 *   CheckboxField    array POST capped with capRawArray(), hiddenConditionValue(), the "Other" option
 *   RadioField       "Other" option: extractValue() returns ['value' => …, '__other_text__' => …]
 *   SignatureField   data URI capped in validate(), mapNormalized(), pdfData(), includeValueInSeal()
 *   DirectDebitField several output rows and a box in the PDF (PdfDescriptor::opensFrame())
 *   CaptchaField     defersValidation(), enqueueFrontScripts() left empty on purpose
 *   GroupField       isGroupContainer(), openTag(), closeTag()
 *   PageBreakField   isPageBreak(), renderBreak(), skipValidation()
 *   HtmlField        skipValidation(), rawEmailHtml(), mapNormalized() gated by "Show in mail/PDF"
 *   PostDataField    values re-derived from a signed hidden {id}[_source_post_id] / [_source_sig] pair, since
 *                    global $post is unset during admin-ajax.php
 *
 * READING THE REQUEST
 * ───────────────────
 *   Every extractValue() starts with self::assertRequestNonceVerified(). WPCS can't see that guard, so each line
 *   that reads $_POST/$_FILES still needs a "// phpcs:ignore WordPress.Security.NonceVerification.Missing --
 *   verified above via assertRequestNonceVerified()." directly above it.
 *
 *   Bound what the visitor controls: cap an array with self::capRawArray() before sanitizing it, keep only string
 *   leaves (self::scalarSubfieldMap()), and cap a data URI's length in validate(), required or not.
 */
class ExampleField extends BaseField
{
    // ═══════════════════════════════════════════════════════
    //  MANDATORY
    // ═══════════════════════════════════════════════════════

    /**
     * The type slug: the field's authoritative name, stored in every form.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'example';
    }

    /**
     * The label in the palette and the settings panel header.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return __('Example field', 'formfabricator');
    }

    /**
     * The Font Awesome classes of the palette icon.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-star';
    }


    // ═══════════════════════════════════════════════════════
    //  RENDER
    // ═══════════════════════════════════════════════════════
    //
    //  inputAttrs() builds type, id, name, class, placeholder, required/aria-required and autocomplete, and escapes
    //  every value itself: pass extras raw. wrap() adds the .fabricator-field wrapper (class fabricator-field--<type>,
    //  which the client checks are keyed by), the label, description, error slot and data-validate.

    /**
     * Renders the field.
     *
     * @param array  $config   The saved field configuration.
     * @param string $field_id Element id and input name.
     * @param mixed  $value    Pre-filled value; the form renderer passes none.
     * @return string
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $attrs = $this->inputAttrs(
            $config,
            $field_id,
            'text',
            [
                'value'     => (string) ($value ?? ''),
                'maxlength' => (int) ($config['maxlength'] ?? 0) ?: false,
            ]
        );
        return $this->wrap($field_id, $config, '<input' . $attrs . '>');
    }

    /**
     * EXAMPLE (unused) — a composite field with sub-inputs posted as "id[key]", each with its own required flag.
     *
     * Such a field also needs:
     *  • extractValue() reading the array (exampleExtractComposite()): the default reads a string, and '' for an array;
     *  • labelsOwnControl() → false, so wrap() names the group instead of pointing <label for> at nothing;
     *  • conditionValue() → self::joinedSubValues($raw, [keys in render order]), as front.js reads it, plus a case in
     *    tests/js/build-fixture.php's $compositeCases;
     *  • validate() checking each required sub-input, which front.js checks through their required attributes.
     * Each sub-input gets a .fabricator-sub-error slot, and wrap() a config with required off, so the field label
     * shows no asterisk of its own.
     *
     * @param array  $config   The saved field configuration.
     * @param string $field_id Element id and input name.
     * @return string
     */
    private function exampleRenderComposite(array $config, string $field_id): string
    {
        $inner = '<div class="fabricator-example-group">';
        foreach (['part_a', 'part_b'] as $k) {
            $sub_id   = $field_id . '-' . $k;
            $required = !empty($config[$k . '_required']);
            $inner   .= '<div class="fabricator-example-sub">'
                . '<label class="fabricator-sub-label" for="' . esc_attr($sub_id) . '">' . esc_html($config[$k . '_label'] ?? $k)
                . ($required ? ' <span class="fabricator-required" aria-hidden="true">*</span>' : '') . '</label>'
                . '<input type="text" id="' . esc_attr($sub_id) . '" name="' . esc_attr($field_id) . '[' . $k . ']" class="fabricator-input"'
                . ($required ? ' required aria-required="true"' : '') . '>'
                . '<div class="fabricator-field-error fabricator-sub-error"></div>'
                . '</div>';
        }
        $inner .= '</div>';
        $wrapper_config             = $config;
        $wrapper_config['required'] = false;
        return $this->wrap($field_id, $wrapper_config, $inner);
    }


    // ═══════════════════════════════════════════════════════
    //  STYLES
    // ═══════════════════════════════════════════════════════
    //
    //  Raw CSS, no <style> tags, from its own file. Every field type's CSS is inlined after front.css on any page
    //  with a form, so front.css's variables and .fabricator-input rules apply. '' (the default) for none.

    /**
     * Field-specific CSS.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return '';
    }

    /**
     * EXAMPLE (unused) — the CSS of the composite layout above.
     *
     * @return string
     */
    private function exampleStylesComposite(): string
    {
        return self::readFieldAsset('assets/css/fields/ExampleField.stylesComposite.css');
    }


    // ═══════════════════════════════════════════════════════
    //  EXTRACT VALUE
    // ═══════════════════════════════════════════════════════
    //
    //  Called once per field (a group's children included) before validate(); what it returns is what validate(),
    //  conditionValue(), map() and mapNormalized() receive. The default reads $_POST[$field_id] through
    //  sanitize_text_field(). Override for another shape: TextareaField (keeps line breaks), AddressField (id[key]),
    //  UploadField ($_FILES). See READING THE REQUEST above.

    /**
     * EXAMPLE (unused) — sub-inputs posted as "id[key]", as AddressField reads them.
     *
     * @param string $field_id Input name.
     * @return mixed
     */
    private function exampleExtractComposite(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; map_deep()/capRawArray() sanitizes, WPCS misses the callback form.
        $raw = isset($_POST[$field_id]) ? map_deep(self::capRawArray(wp_unslash($_POST[$field_id])), 'sanitize_text_field') : [];
        return self::scalarSubfieldMap($raw);
    }

    /**
     * EXAMPLE (unused) — files with a caption each, posted as id[] files and id_desc[] texts.
     *
     * @param string $field_id Input name.
     * @return mixed
     */
    private function exampleExtractParallelArrays(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // Not unslashed: $_FILES is never slashed, and wp_unslash() would break Windows tmp paths.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- verified above via assertRequestNonceVerified(); 'name' is sanitized below, other keys (tmp_name/size/error) are PHP-generated, not attacker text.
        $files = isset($_FILES[$field_id]) ? $_FILES[$field_id] : [];
        // A shape the form's own input never posts is no upload of this field.
        if (!\FabricatorForms\Utils\Cast::isFlatFilesEntry($files)) {
            $files = [];
        }
        if (isset($files['name'])) {
            $files['name'] = is_array($files['name'])
                ? map_deep($files['name'], 'sanitize_file_name')
                : sanitize_file_name($files['name']);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via assertRequestNonceVerified().
        $desc = isset($_POST[$field_id . '_desc'])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; map_deep()/capRawArray() sanitizes, WPCS misses the callback form.
            ? map_deep(self::capRawArray(wp_unslash($_POST[$field_id . '_desc']), 20), 'sanitize_text_field')
            : [];
        return [
            'files' => $files,
            'desc'  => is_array($desc) ? array_filter($desc, 'is_string') : [],
        ];
    }


    // ═══════════════════════════════════════════════════════
    //  VALIDATE
    // ═══════════════════════════════════════════════════════
    //
    //  true, or the message for the visitor (not escaped: front.js shows it via textContent). The default handles
    //  the required check; a field hidden by its conditions is never validated. $config['field_id'] holds the
    //  element id. Mirror format rules in getClientValidation() for instant feedback; the server's check decides.

    /**
     * Validates the submitted value: required, then five digits.
     *
     * @param mixed $value  What extractValue() returned.
     * @param array $config The saved field configuration.
     * @return bool|string
     */
    public function validate(mixed $value, array $config): bool|string
    {
        $base = parent::validate($value, $config);
        if ($base !== true || $this->isEmpty($value)) {
            return $base;
        }
        if (!preg_match('/^\d{5}$/', (string) $value)) {
            return __('Please enter a five-digit number.', 'formfabricator');
        }
        return true;
    }


    // ═══════════════════════════════════════════════════════
    //  CONDITIONS
    // ═══════════════════════════════════════════════════════
    //
    //  conditionValue() is what show/hide and routing rules read; it must equal front.js's getFieldValue() on the
    //  rendered markup (condition-parity.test.js checks both). The default fits inputs named "id" or "id[]".
    //  hiddenConditionValue() is what a rule reads while the field is hidden: '' by default, [] for checkboxes
    //  named after the field.


    // ═══════════════════════════════════════════════════════
    //  OUTPUT — map() → mapNormalized() → pdfData()
    // ═══════════════════════════════════════════════════════
    //
    //  map() turns the value into the text the email and the PDF show. The default returns the string, or
    //  __('[No entry]', 'formfabricator') when empty (isEmpty(): null, '' or an array of '' only; override it for
    //  other shapes). Shared wording: '[No entry]', '[Other]', '[Signature present]'. Consent-like fields record what
    //  was agreed and when (ConsentField::map()).
    //
    //  mapNormalized($field_id, $label, $value, $config, $context) returns the field's output rows, keyed by row id.
    //  The default is one row, [$field_id => ['label', 'type', 'value' => map()]]. Override for several rows
    //  (DirectDebitField), files ('materialized_files', UploadField, SignatureField) or none ([], PageBreakField).
    //  $context holds 'files', 'raw_values' and 'skip_ids'. Every materialized file is held base64-encoded in
    //  memory; the submission's memory budget counts uploads, so read files the way UploadField does.

    /**
     * Turns the value into text for the email and the PDF.
     *
     * @param mixed $value  What extractValue() returned.
     * @param array $config The saved field configuration.
     * @return string
     */
    public function map(mixed $value, array $config): string
    {
        if ($this->isEmpty($value)) {
            return __('[No entry]', 'formfabricator');
        }
        if (is_array($value)) {
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- literal callback ('trim'), not request input.
            return implode(', ', array_filter(array_map('trim', $value)));
        }
        return (string) $value;
    }


    // ═══════════════════════════════════════════════════════
    //  CLIENT-SIDE INIT
    // ═══════════════════════════════════════════════════════
    //
    //  A JS function expression, function (root) { … }, from its own file, collected into
    //  window.FabricatorFieldInits by field type. front.js calls it with each form's root, and again after every
    //  successful send, so it must be idempotent: mark each element once and skip it next time. front.js's own
    //  helpers are not available. Text comes from window.FabricatorForms.i18n (Assets::frontLocalization()), with an
    //  English fallback. '' for none.
    //
    //  An upload-like field that sets data-fabricator-file-count counts towards PHP's max_file_uploads; front.js then
    //  dispatches fabricator:upload-overflow ({ total, max }) on the <form> (see UploadField.js).

    /**
     * The client-side init function.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return '';
    }

    /**
     * EXAMPLE (unused) — a click handler on every widget, attached once.
     *
     * @return string
     */
    private function exampleClientInitClickHandler(): string
    {
        return self::readFieldAsset('assets/js/fields/ExampleField.clientInitClickHandler.js');
    }


    // ═══════════════════════════════════════════════════════
    //  CLIENT-SIDE CHECKS
    // ═══════════════════════════════════════════════════════
    //
    //  getClientEmptyCheck(): ['fn' => 'function (fieldEl) { return isBlank; }'], for the required check when
    //  "blank" isn't "the first visible input is empty". [] uses that fallback.
    //
    //  getClientValidation(): a list of ['rule' => name, 'fn' => function (fieldEl)], run only when the field has
    //  content; fn returns null or the error text. Rule names are global, and the first field to use a name wins,
    //  so prefix them with the type. wrap() lists them in data-validate.

    /**
     * The client-side blank check; [] for the generic one.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return [];
    }

    /**
     * EXAMPLE (unused) — blank while no checkbox is ticked. A one-line function may stay inline.
     *
     * @return array
     */
    private function exampleClientEmptyCheckCheckboxGroup(): array
    {
        return ['fn' => "function(f){ return !f.querySelector('input[type=\"checkbox\"]:checked'); }"];
    }

    /**
     * The client-side mirror of validate()'s format rule.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [[
            'rule' => 'example-zip',
            'fn'   => self::readFieldAsset('assets/js/fields/ExampleField.clientValidationZip.js'),
        ]];
    }


    // ═══════════════════════════════════════════════════════
    //  PDF DATA
    // ═══════════════════════════════════════════════════════
    //
    //  The default is a labelled cell with the escaped map() text. A cell holds exactly the field's sealed value,
    //  between the markers the verifier compares; titles and boxes go outside it (PdfDescriptor::opensFrame() and
    //  closesFrame()). $this->pdf($field) returns a PdfDescriptor; chain, then ->build():
    //
    //    ->text($escaped)           replace the cell text ('' for none, as a signature does)
    //    ->rawHtml($html, $trusted) HTML through wp_kses_post(); the PDF keeps only <br>, <strong> and <em> unless
    //                               $trusted, for HTML the field has sanitized itself (HtmlField)
    //    ->unlabeled()              no label row
    //    ->attachImage($binary, $filename, $mime)
    //                               embed an image after the cell text and record it in the seal; only types
    //                               PdfUtils::embeddableImageMime() accepts and sizes within the pixel limit
    //    ->opensFrame($title) / ->closesFrame()
    //                               a titled box around this field and the ones after it

    /**
     * EXAMPLE (unused) — an image of the value in the PDF.
     *
     * @param array $field Entry from mapNormalized().
     * @return array
     */
    private function examplePdfDataImage(array $field): array
    {
        return $this->pdf($field)
            ->attachImage($this->exampleRenderPng((string) ($field['value'] ?? '')), 'value.png')
            ->build();
    }

    /**
     * EXAMPLE (unused) — stands in for an image encoder the field would bring: a 1×1 transparent PNG.
     *
     * @param string $value The value to draw.
     * @return string
     */
    private function exampleRenderPng(string $value): string
    {
        unset($value);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a literal 1x1 PNG for the example, not obfuscation.
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }

    /**
     * EXAMPLE (unused) — HTML the field has already sanitized, as HtmlField does.
     *
     * @param array $field Entry from mapNormalized().
     * @return array
     */
    private function examplePdfDataRawHtml(array $field): array
    {
        return $this->pdf($field)
            ->rawHtml(\FabricatorForms\Utils\HtmlSanitizer::sanitize((string) ($field['value'] ?? '')), true)
            ->build();
    }


    // ═══════════════════════════════════════════════════════
    //  FLAGS
    // ═══════════════════════════════════════════════════════
    //
    //  skipValidation() → true          no input at all (HtmlField, PageBreakField, PageHeaderField): never extracted
    //                                   or validated, on either side
    //  defersValidation() → true        validate() spends something single-use (CaptchaField): it runs only once
    //                                   every other field has passed
    //  includeInEmailSummary() → false  left out of {all_fields} (GroupField, PageBreakField, PageHeaderField)
    //  includeValueInSeal() → false     value sealed as '' (SignatureField; its image is sealed by hash)
    //  rawEmailHtml() → true            value goes into the email unescaped; only for HTML the field sanitizes itself
    //                                   (HtmlField)
    //  hasTextPreview() → true          used as sample text in the PDF layout preview (Text, Email, Textarea)
    //  hasRequired() → false            no "Required" checkbox in the settings panel
    //  hasSettingsPanel() → false       clicking the tile opens no settings panel
    //  needsMultipartEncoding() → true  file input: the form gets enctype="multipart/form-data", and the submission's
    //                                   memory budget counts its $_FILES entry (UploadField)
    //  enqueueFrontScripts()            runs once per type among a form's top-level fields; load a third-party script
    //                                   only after a click instead, as CaptchaField does for reCAPTCHA
    //  isPageBreak() + renderBreak()    page navigation (PageBreakField)
    //  isGroupContainer() + openTag()/closeTag()
    //                                   renders $config['children'] inside (GroupField)

    /**
     * EXAMPLE (unused) — would replace BaseField::skipValidation().
     *
     * @return bool
     */
    private function exampleSkipValidation(): bool
    {
        return true;
    }


    // ═══════════════════════════════════════════════════════
    //  SETTINGS
    // ═══════════════════════════════════════════════════════
    //
    //  getDefaultConfig() is what a field dropped onto the canvas starts with; merge the parent's (label, required,
    //  hide_label, placeholder, description, autocomplete, custom_class). On save, a value of the wrong kind (list vs.
    //  single) is replaced with its default, and strings are sanitized by sanitizeConfigValue(): as HTML, unless
    //  plainTextConfigKeys() names the key.
    //
    //  The schemas list the settings panel's controls, in order; label, required and hide_label are built in. Types:
    //    General and Advanced  text, email, url, number, textarea, checkbox, select (options), pill3 (values, labels),
    //                          media_upload, section_title, notice (level, text)
    //    General only          bool_seg (false_label, true_label), pill_multi, options_list, page_names_list,
    //                          subfields (items), limit_row (count_key), icon_row, time_row, country_tags,
    //                          html_editor, rating_preview
    //  Optional keys: hint; rebuild => true (re-renders the tab, so dependent controls appear); depends_on:
    //    ['other_key' => true]                    shown while other_key is truthy (false: falsy)
    //    ['key' => 'other_key', 'is' => 'x']      shown while other_key equals 'x'
    //    ['key' => 'other_key', 'not' => 'x']     shown while it doesn't; 'default' stands in for a missing value

    /**
     * The settings a new field starts with.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(
            parent::getDefaultConfig(),
            [
                'maxlength' => '',
                'my_toggle' => false,
                'my_select' => 'option-a',
                'pattern'   => '',
            ]
        );
    }

    /**
     * The General tab's controls.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return array_merge(
            $this->baseGeneralEntries(), // placeholder and description
            [
                [
                    'key'         => 'my_toggle',
                    'type'        => 'bool_seg',
                    'label'       => __('Mode', 'formfabricator'),
                    'false_label' => __('Simple', 'formfabricator'),
                    'true_label'  => __('Extended', 'formfabricator'),
                    'rebuild'     => true,
                ],
                [
                    'key'        => 'maxlength',
                    'type'       => 'number',
                    'label'      => __('Character limit', 'formfabricator'),
                    'hint'       => __('Empty = no limit', 'formfabricator'),
                    'depends_on' => ['my_toggle' => true],
                ],
                [
                    'key'    => 'my_select',
                    'type'   => 'pill3',
                    'label'  => __('Filter type', 'formfabricator'),
                    'values' => ['option-a', 'option-b', 'option-c'],
                    'labels' => [__('Off', 'formfabricator'), __('Allowed', 'formfabricator'), __('Blocked', 'formfabricator')],
                ],
            ]
        );
    }

    /**
     * The Advanced tab's controls.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        return [
            [
                'key'        => 'pattern',
                'type'       => 'textarea',
                'label'      => __('Pattern', 'formfabricator'),
                'hint'       => __('One per line', 'formfabricator'),
                'depends_on' => ['key' => 'my_select', 'not' => 'option-a'],
            ],
        ];
    }
}
