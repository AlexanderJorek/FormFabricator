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
 * ════════════════════════════════════════════════════════════
 *  HOW TO ADD A NEW FIELD
 * ════════════════════════════════════════════════════════════
 *
 * This file is a teaching document — its leading underscore causes Plugin.php
 * to skip it during auto-discovery, so it has zero runtime effect.
 *
 * First time? Read top to bottom once. After that, use the DECISION TREE
 * below and jump straight to the section you need.
 *
 * QUICK START (5 min)
 * ───────────────────
 *   1. Copy this file, rename the class (remove the leading _).
 *   2. Implement getType(), getLabel(), getIcon(), render() — see MINIMUM VIABLE FIELD below.
 *   3. Drop the file in includes/Fields/.
 *   4. Add one line to FieldRegistry::FIELD_MAP: 'MyField' => 'group:my-field'
 *      (group = input/choice/personal/advanced/layout/system). This both
 *      allowlists the file for loading and sets its palette placement.
 *   5. Done. Everything past MINIMUM VIABLE FIELD is optional reference material.
 *
 * Every field extends BaseField, which provides sensible defaults.
 * Override only when your field needs different behavior. Field classes are
 * fully self-contained: no file outside includes/Fields/ branches on type slugs.
 * If you find yourself writing if ($field['type'] === '...') elsewhere,
 * add a method to BaseField and override it in the field class instead.
 *
 * THE MINIMUM VIABLE FIELD — everything else in this file is optional
 * ──────────────────────────────────────────────────────────────────
 *   class MyField extends BaseField
 *   {
 *       public function getType(): string { return 'my-field'; }
 *       public function getLabel(): string { return __('My field', 'formfabricator'); }
 *       public function getIcon(): string { return 'fa-solid fa-star'; }
 *
 *       public function render(array $config, string $field_id, mixed $value = null): string
 *       {
 *           $attrs = $this->inputAttrs($config, $field_id, 'text', [
 *               'value' => esc_attr((string)($value ?? '')),
 *           ]);
 *           return $this->wrap($field_id, $config, '<input' . $attrs . '>');
 *       }
 *   }
 *
 *   Drop the file in includes/Fields/ — Plugin.php auto-discovers it via getType().
 *   That's a complete, working field. Treat inputAttrs() and wrap() as black
 *   boxes for now — they generate the standard input attributes and the
 *   outer .fabricator-field wrapper (label, description, error placeholder).
 *   Every simple text-like field follows this exact pattern. BaseField
 *   already handles the required check, the default map() → string, and a
 *   plain-text PDF row — so the four methods above are all a simple field needs.
 *
 * ✓ STOP HERE if that's all your field needs.
 *   Everything below is optional reference for the pieces you reach for only
 *   when a field needs more: format validation, custom CSS/JS, non-text
 *   values, composite sub-inputs, or PDF images. Jump to the section
 *   matching your field.
 *
 * WHEN TO OVERRIDE — grouped by how often fields need it
 * ─────────────────────────────────────────────────────
 * Every field (always)
 *   getType(), getLabel(), getIcon(), render()
 *
 * Most fields (~80%)
 *   validate() + getClientValidation()   format rules
 *   map()                                composite or formatted value → string
 *   getStyles()                          field-specific CSS
 *   getClientInit()                      interactive JS widget
 *   getClientEmptyCheck()                custom blank check (non-standard widgets)
 *   getDefaultConfig()                   config keys the field uses
 *   getGeneralSchema()                   General settings tab controls
 *
 * Some fields (~15%)
 *   extractValue()                       non-standard $_POST / $_FILES shape
 *   extractFromRaw()                     same field can appear inside a group
 *   extractFromRawWithOther()            group copy + a "{child_id}_other" sibling value
 *                                         (Checkbox/Radio/Select "Other" free-text option)
 *   mapNormalized()                      file uploads or multiple output entries
 *   pdfData()                            image embed or raw HTML in PDF
 *   hasTextPreview() → true              include in PDF token-picker preview
 *
 * Rare (~5%, framework features)
 *   skipValidation() → true              purely presentational, no user input
 *   includeInEmailSummary() → false      exclude from {all_fields} email block
 *   includeValueInSeal() → false         exclude value from HMAC integrity seal
 *   rawEmailHtml() → true                value is trusted HTML, output unescaped in mail — see HtmlField
 *   needsMultipartEncoding() → true      field uses a file input
 *   enqueueFrontScripts()                third-party script required (e.g. reCAPTCHA)
 *   isPageBreak() + renderBreak()        structural page-nav field
 *   isGroupContainer() + openTag/closeTag structural section container
 *
 * DECISION TREE — "what do I need to touch?"
 * ────────────────────────────────────────────
 *   Simple text-like input?        → render()
 *   Needs format validation?       → validate() + getClientValidation()
 *   Needs interactive JS?          → getClientInit()
 *   Needs field-specific CSS?      → getStyles()
 *   Reads $_FILES or a custom
 *     $_POST shape?                → extractValue() (+ extractFromRaw() for groups)
 *   Value is composite/array?      → map()
 *   Multiple outputs or files?     → mapNormalized()
 *   Embeds an image / raw HTML
 *     in the PDF?                  → pdfData()
 *
 * FIELD LIFECYCLE — when each method is called
 * ─────────────────────────────────────────────
 *   Builder  →  Render  →  Submit  →  Output
 *   getDefaultConfig()      enqueueFrontScripts()   extractValue()   map()
 *   getGeneralSchema()      getStyles() · render()  validate()       ↓ mapNormalized()
 *   getAdvancedSchema()     getClientInit()                          ↓ pdfData()
 *                           getClientEmptyCheck()
 *                           getClientValidation()
 *
 * REAL-WORLD EXAMPLES — where to look
 * ─────────────────────────────────────
 * UploadField      render(), extractValue(), validate(), mapNormalized(), pdfData(),
 *                  needsMultipartEncoding()
 * TextareaField    extractValue(), extractFromRaw()
 * SignatureField   mapNormalized(), pdfData(), includeValueInSeal()
 * CheckboxField    extractValue(), extractFromRaw(), extractFromRawWithOther(),
 *                  element-count capping (see NONCE / RESOURCE LIMITS note below)
 * RadioField       extractValue() returning ['value' => ..., '__other_text__' => ...]
 * SepaField        extractValue(), mapNormalized(), size cap on signature data URI
 * CaptchaField     enqueueFrontScripts(), validate()
 * GroupField       isGroupContainer(), openTag(), closeTag()
 * PageBreakField   isPageBreak(), renderBreak(), skipValidation(), includeInEmailSummary()
 * HtmlField        skipValidation(), includeInEmailSummary(), rawEmailHtml(), mapNormalized()
 *                  (config-toggle-gated output — "Show in mail/PDF" checkbox)
 * PostDataField    extractValue() resolving via a hidden companion input rather than global
 *                  state — global $post is unavailable during admin-ajax.php requests, so
 *                  render() emits a hidden {field_id}[_source_post_id] input and extractValue()
 *                  re-resolves via get_post() instead of relying on the (absent) Loop context
 * TextField        hasTextPreview()
 *
 * NONCE / RESOURCE-LIMIT CONVENTIONS — apply to every extractValue()/extractFromRaw() override
 * ────────────────────────────────────────────────────────────────────────────────────────────
 * Every direct $_POST/$_FILES read in extractValue()/extractFromRaw() needs:
 *   // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified
 *   // once in FormProcessor::handle() before field extraction runs.
 * immediately above the line touching the superglobal — the nonce check already
 * happened once upstream, this silences the (correct) per-line sniff warning.
 *
 * If your field accepts an array-valued POST field (checkboxes, repeatable groups),
 * cap the element count with array_slice() BEFORE sanitizing/validating each entry —
 * an unbounded array otherwise costs unbounded O(n) sanitize calls and O(n*m)
 * validation work per submission (see CheckboxField::extractValue()). If your field
 * accepts a data-URI/binary value (signature, upload), cap its byte length in
 * validate() the same way regardless of whether the field is required (see
 * SepaField::validate() / SignatureField::validate()).
 *
 * Every sprintf()/__() pairing that has a %s/%d placeholder needs a `// translators:`
 * comment on the line directly above explaining what each placeholder is.
 *
 * Template/example field demonstrating the full field implementation pattern.
 */
class ExampleField extends BaseField
{
    // ═══════════════════════════════════════════════════════
    //  MANDATORY — getType(), getLabel(), and getIcon() are trivial one-liners.
    //  render() is the only method every field must meaningfully
    //  implement because every field has unique HTML. Most fields are
    //  under 100 lines.
    // ═══════════════════════════════════════════════════════

    /**
     * Returns the unique type slug used by FieldRegistry auto-discovery.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'example';
    }

    /**
     * Returns the label shown in the field palette and builder panel header.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return __('Example field', 'formfabricator');
    }

    /**
     * Returns the Font Awesome 6 class shown as the palette tile icon.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-star';
    }


    // ═══════════════════════════════════════════════════════
    //  RENDER — build the frontend HTML
    // ═══════════════════════════════════════════════════════

    /**
     * Renders the field HTML for frontend display.
     *
     * @param array  $config   Field config merged with getDefaultConfig() defaults.
     * @param string $field_id Unique element id/name, e.g. "field-3".
     * @param mixed  $value    Pre-filled value when re-displaying after a server error.
     *
     * @return string
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        // ── Simple single input ──────────────────────────────────────────
        // inputAttrs() builds: id, name, class="fabricator-input", placeholder,
        // required + aria-required. Pass extra HTML attributes as 4th array.
        $attrs = $this->inputAttrs(
            $config,
            $field_id,
            'text',
            [
                'value'     => esc_attr((string)($value ?? '')),
                'maxlength' => (int)($config['maxlength'] ?? 0) ?: false,
            ]
        );

        // wrap() adds the outer .fabricator-field div, label, description, error placeholder,
        // required class/asterisk, and data-validate attribute automatically.
        // Almost every field should call wrap() — do not reproduce those pieces manually.
        return $this->wrap($field_id, $config, '<input' . $attrs . '>');
    }

    // ✓ Simple field complete — everything below in this file is optional reference.

    /**
     * EXAMPLE (unused) — composite field with multiple independent sub-inputs,
     * each with its own required flag (like Address / Name in expanded mode).
     * Body of render() would return this instead of the single <input> above.
     *
     * Rules:
     *  • Put `required` HTML attr on each individual <input>/<select>
     *  • Add a .fabricator-field-error.fabricator-sub-error div after each input
     *    (front.js will write the error message there)
     *  • Add $req_star so the label shows a red *
     *  • Pass $wrapper_config with required=false to wrap() so the
     *    global * on the field label is suppressed
     */
    private function exampleRenderComposite(array $config, string $field_id): string
    {
        $inner = '<div class="fabricator-example-group">';
        foreach (['part_a', 'part_b'] as $k) {
            $label = esc_html($config[$k . '_label'] ?? $k);

            $req      = '';
            $req_star = '';
            if (!empty($config[$k . '_required'])) {
                $req      = ' required aria-required="true"';
                $req_star = ' <span class="fabricator-required" aria-hidden="true">*</span>';
            }

            $inner .= '<div class="fabricator-example-sub">';
            $inner .= '<label class="fabricator-sub-label">' . $label . $req_star . '</label>';
            $inner .= '<input type="text" name="' . esc_attr($field_id) . '[' . $k . ']" class="fabricator-input"' . $req . '>';
            $inner .= '<div class="fabricator-field-error fabricator-sub-error"></div>';
            $inner .= '</div>';
        }
        $inner .= '</div>';
        $wrapper_config             = $config;
        $wrapper_config['required'] = false;
        return $this->wrap($field_id, $wrapper_config, $inner);
    }


    // ═══════════════════════════════════════════════════════
    //  STYLES — field-specific CSS injected on the page
    // ═══════════════════════════════════════════════════════
    //
    //  Return a raw CSS string (no <style> tags). Assets::enqueueFront() collects all non-empty getStyles() returns
    //  and emits them as a single wp_add_inline_style call after front.css loads, so CSS variables and the .fabricator-input base rules are already defined and available here.
    //
    //  Use a nowdoc (<<<'CSS' ... CSS) so the content is literal — no PHP
    //  variable expansion, no accidental escaping of $ or \.
    //
    //  Include @media blocks inside the same string when needed.
    //  Return '' (default from BaseField) when no custom CSS is required.

    /**
     * Returns field-specific CSS injected inline on pages that load this form.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return ''; // no custom CSS needed for this field
    }

    /**
     * EXAMPLE (unused) — custom wrapper with responsive behaviour.
     * getStyles() would return this instead of ''.
     */
    private function exampleStylesComposite(): string
    {
        return <<<'CSS'
        .fabricator-example-group {
            display: flex;
            gap: 10px;
        }
        .fabricator-example-sub {
            flex: 1;
            min-width: 100px;
        }
        @media (max-width: 600px) {
            .fabricator-example-group { flex-direction: column; }
        }
        CSS;
    }


    // ═══════════════════════════════════════════════════════
    //  EXTRACT VALUE — assemble the raw value from $_POST / $_FILES
    // ═══════════════════════════════════════════════════════
    //
    //  These two methods solve the same problem in two different contexts:
    //
    //    extractValue(string $field_id): mixed
    //      → called for normal top-level fields
    //      → reads directly from $_POST / $_FILES by field_id
    //      → called by FormProcessor once per field before validate()
    //      → the returned value is what validate(), map(), and mapNormalized() receive
    //
    //    extractFromRaw(mixed $raw): mixed
    //      → only called when this field lives inside a repeatable Group field
    //      → Repeatable group children submit as $_POST[$group_id][$copy_idx][$child_id].
    //        FormProcessor slices out the child's value and passes it here.
    //      → Non-repeatable group children submit with their own flat names and are read
    //        via extractValue($child_id) — extractFromRaw() is never called for them.
    //      → Should sanitize $raw the same way extractValue() sanitizes $_POST.
    //
    //  BaseField default for extractValue(): reads $_POST[$field_id] with sanitize_text_field().
    //  This is correct for every plain text field — override only when your field's value
    //  lives somewhere else or has a different shape:
    //
    //      • $_FILES          → UploadField
    //      • composite array  → SepaField (iban/bic/holder + separate -sig key)
    //      • parallel arrays  → a gallery field: files[] + desc[] as separate keys
    //      • sanitize_textarea_field instead of sanitize_text_field → TextareaField
    //
    //  See "NONCE / RESOURCE-LIMIT CONVENTIONS" at the top of this file for the
    //  phpcs:ignore comment and array element-count capping this method needs.
    //
    //  If you override extractValue(), check whether your field can appear inside a
    //  group and override extractFromRaw() as well so group copies sanitize correctly.

    /**
     * EXAMPLE (unused) — parallel file + caption arrays. Would replace the
     * inherited BaseField::extractValue() as a real override.
     */
    private function exampleExtractParallelArrays(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified once in FormProcessor::handle() before field extraction runs; 'name' is sanitized below, other keys (tmp_name/size/error) are PHP-generated, not attacker text.
        $files = isset($_FILES[$field_id]) ? wp_unslash($_FILES[$field_id]) : [];
        if (is_array($files) && isset($files['name'])) {
            $files['name'] = is_array($files['name'])
                ? map_deep($files['name'], 'sanitize_file_name')
                : sanitize_file_name($files['name']);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        $desc = isset($_POST[$field_id . '_desc'])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
            ? map_deep(wp_unslash($_POST[$field_id . '_desc']), 'sanitize_text_field')
            : [];
        return [
            'files' => $files,
            'desc'  => $desc,
        ];
    }

    // ═══════════════════════════════════════════════════════
    //  VALIDATE — server-side validation, runs after form submission
    // ═══════════════════════════════════════════════════════
    //
    //  Default (BaseField): handles the generic required check automatically.
    //  Override only to add format rules on top.
    //  Return true = OK, return string = user-facing error message.
    //
    //  $config['field_id'] — FormProcessor injects this before calling validate(),
    //  setting it to the same value as $config['id'] (the HTML element name, e.g. "field-3").
    //  Use it to look up $_FILES[$config['field_id']] for file-bearing fields.
    //
    //  The $value received here is whatever extractValue() returned — shape it
    //  there, validate and sanitize it here.

    /**
     * Validates the submitted value (required check + five-digit format).
     *
     * @param mixed $value  The submitted value.
     * @param array $config Field configuration array.
     *
     * @return bool|string
     */
    public function validate(mixed $value, array $config): bool|string
    {
        $base = parent::validate($value, $config); // required check
        if ($base !== true) {
            return $base;
        }
        if ($this->isEmpty($value)) {
            return true; // optional + empty → skip format check
        }

        if (!preg_match('/^\d{5}$/', (string)$value)) {
            return __('Please enter a five-digit number.', 'formfabricator');
        }
        return true;
    }

    /**
     * EXAMPLE (unused) — an error message with a %s/%d placeholder always
     * needs a translators comment directly above the sprintf()/__() pairing.
     */
    private function exampleValidateRequiredMessage(string $label): string
    {
        // translators: %s: field label.
        return sprintf(__('%s is a required field.', 'formfabricator'), esc_html($label));
    }


    // ═══════════════════════════════════════════════════════
    //  OUTPUT PIPELINE — map() → mapNormalized() → pdfData(), plus the
    //  includeInEmailSummary() / includeValueInSeal() flags below, are all
    //  facets of the same question: "how does this field's value leave the
    //  system?" map() covers 99% of fields. Only reach for mapNormalized()
    //  or pdfData() when map()'s plain string isn't enough (files, images,
    //  multiple output rows) — see each section for exact triggers.
    // ═══════════════════════════════════════════════════════
    //
    //  MAP — value → human-readable string for email / PDF
    //  99% of fields → map() only. 1% → also mapNormalized(). 0.1% → also pdfData().
    //
    //  map(mixed $value, array $config): string
    //    Returns a plain human-readable string. The BaseField default casts the
    //    value to string and returns __('[No entry]', 'formfabricator') when empty.
    //    Override for composite (array) values or custom formatting (e.g. IBAN
    //    spacing, date format).
    //
    //  WELL-KNOWN SENTINEL RETURNS — use these exact strings so the translation
    //  system picks them up consistently across all fields:
    //    __('[No entry]', 'formfabricator')                 — field was left blank
    //    __('[Other]', 'formfabricator')                    — user chose the free-text "other" option
    //    __('[Signature present – see attachment]', 'formfabricator') — binary excluded from text output
    //    __('Yes', 'formfabricator') / __('No', 'formfabricator')        — boolean consent
    //    __('Privacy accepted', 'formfabricator') / __('Privacy not accepted', 'formfabricator')
    //
    //  Do NOT return raw German strings — always wrap in __('English source', 'formfabricator').
    //  JS strings inside heredocs cannot use __() directly; add them to Assets::enqueueFront()
    //  under FabricatorForms.i18n and read them as:
    //    (window.FabricatorForms && window.FabricatorForms.i18n && window.FabricatorForms.i18n.MY_KEY) || 'English fallback'
    //
    //  Override mapNormalized() only when your field needs any of:
    //    • Multiple output entries  (SepaField expands to IBAN + BIC + Kontoinhaber + sig)
    //    • File materialization     (UploadField reads $_FILES; SignatureField decodes base64)
    //    • Custom output keys       (default key is $field_id)
    //    • Return []                to emit nothing (PageBreakField, empty HtmlField)
    //
    //  BaseField default for mapNormalized() wraps map() in a single [$field_id => entry].
    //
    //  mapNormalized() signature: (field_id, label, value, config, context): array<key, entry>
    //  $context: ['files' => $_FILES subset, 'raw_values' => all raw POST values]
    //  Entry shape: ['label' => ..., 'type' => ..., 'value' => ..., 'materialized_files' => []]
    //
    //  isEmpty() — The default checks array_filter($v, fn => $v !== '') for arrays.
    //  This works for flat scalar arrays (checkboxes, multi-select) but will misfire
    //  for structured values (e.g. [{file, desc}, ...]). Override isEmpty() whenever
    //  your value shape is not a flat array of strings.
    //
    //  Size note — mapNormalized() base64-encodes the full binary of every materialized
    //  file into the returned array. For fields with many or large files this array can
    //  be very large. There is no built-in cap — be mindful of memory when materializing
    //  more than a handful of images (see UploadField for the per-file pattern to follow).
    //
    //  Parallel arrays (repeatable user-added groups, e.g. N images each with a caption):
    //  Use a naming convention such as field_id[files][] and field_id[desc][] in your
    //  render() HTML. Override extractValue() in your field class to collect both arrays
    //  and return them as a single value — that value then reaches map() and mapNormalized()
    //  with the full shape intact. There is no built-in repeatable-group mechanism — the
    //  'group' schema type is builder-admin children only, not user-driven runtime rows.

    /**
     * Maps the submitted value to a human-readable string for email/PDF.
     *
     * @param mixed $value  The submitted value.
     * @param array $config Field configuration array.
     *
     * @return string
     */
    public function map(mixed $value, array $config): string
    {
        if ($this->isEmpty($value)) {
            return __('[No entry]', 'formfabricator');
        }
        if (is_array($value)) {
            return implode(', ', array_filter(array_map('trim', $value)));
        }
        return (string)$value;
    }


    // ═══════════════════════════════════════════════════════
    //  CLIENT-SIDE INIT — interaction logic for this field type
    // ═══════════════════════════════════════════════════════
    //
    //  Return a JS function string: function(root) { ... }
    //  Assets::enqueueFront() collects these into window.FabricatorFieldInits keyed by field type. front.js calls each with the container root element — no field-specific knowledge lives in front.js.
    //
    //  Use querySelectorAll / addEventListener directly. The on() helper in front.js is scoped to its IIFE and is NOT available here.
    //
    //  Return '' (default) when no client-side init is needed.
    //
    //  fabricator:upload-overflow — front.js dispatches this custom event on the <form> element
    //  during pre-submit validation when the sum of data-fabricator-file-count attributes across
    //  all file-upload zones exceeds PHP's max_file_uploads limit. The event detail carries
    //  { total, max }. If your field reports a file count via data-fabricator-file-count it will
    //  be included in this sum — listen for the event to show a user-visible error.
    //  See UploadField::getClientInit() for a full usage example.

    /**
     * Returns the client-side initialisation script for this field type.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return ''; // no client-side interaction needed
    }

    /**
     * EXAMPLE (unused) — attach a click handler to every widget instance.
     * getClientInit() would return this instead of ''.
     */
    private function exampleClientInitClickHandler(): string
    {
        return <<<'JS'
        function (root) {
            root.querySelectorAll('.fabricator-example-widget').forEach(function (widget) {
                widget.addEventListener('click', function () {
                    // ... interaction logic
                });
            });
        }
        JS;
    }


    // ═══════════════════════════════════════════════════════
    //  CLIENT-SIDE VALIDATION — two separate concerns
    // ═══════════════════════════════════════════════════════
    //
    //  getClientEmptyCheck()  →  IS THE FIELD BLANK?
    //    Used by the required check. Only override when "blank" is not "first visible
    //    input has no value" — e.g. a checkbox group is blank when no checkbox is
    //    checked, not when the input value is ''. Return [] to use the generic fallback.
    //
    //  getClientValidation()  →  IS THE VALUE IN THE RIGHT FORMAT?
    //    Only runs when the field already HAS content. May return zero, one, or multiple
    //    validation rules — each is an array with a 'rule' key (globally unique string)
    //    and a 'fn' key (JS function string). Return [] if no format check is needed.
    //
    //  Both are collected by Assets::enqueueFront() and injected as
    //  window.FabricatorEmptyChecks / window.FabricatorValidators before front.js loads.
    //  front.js is a pure runner — zero field-specific logic lives there.

    /**
     * Returns the client-side empty-check function for this field type.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return []; // generic fallback: first visible input non-empty
    }

    /**
     * EXAMPLE (unused) — field is empty when no checkbox is checked.
     * getClientEmptyCheck() would return this instead of [].
     */
    private function exampleClientEmptyCheckCheckboxGroup(): array
    {
        return ['fn' => "function(f){ return !f.querySelector('input[type=\"checkbox\"]:checked'); }"];
    }

    /**
     * Returns client-side format validation rules for this field type.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return []; // no format validation needed
    }

    /**
     * EXAMPLE (unused) — five-digit number format check. getClientValidation()
     * would return this instead of [].
     *
     * Rule keys must be globally unique — prefix with the field type if unsure.
     * The function receives the outer .fabricator-field wrapper element, not the input.
     * Return null = valid, return string = error message shown below the field.
     *
     * Note the i18n lookup below — per the "Do NOT return raw German strings" rule
     * in the MAP section above, JS-side error text must read from
     * window.FabricatorForms.i18n (registered in Assets::enqueueFront()) with an
     * English literal as the fallback, not a bare hardcoded string.
     */
    private function exampleClientValidationZip(): array
    {
        return [['rule' => 'example-zip', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('input');
                if (!inp || !inp.value.trim()) return null;
                if (/^\d{5}$/.test(inp.value.trim())) return null;
                var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
                return (_i18n && _i18n.example_zip_invalid) || 'Please enter a five-digit number.';
            }
            JS]];
    }


    // ═══════════════════════════════════════════════════════
    //  PDF DATA — pdfData() — how this field appears in the generated PDF
    // ═══════════════════════════════════════════════════════
    //
    //  BaseField already generates a standard PDF row (label + escaped value text)
    //  automatically. Override pdfData() only when the field should appear differently
    //  in the PDF than it does in the email — specifically:
    //    1. The value is raw HTML, not plain text  →  see HtmlField
    //    2. The field embeds an image in the PDF   →  see SignatureField / UploadField
    //       (non-image uploads appear as filename text only; only attachImage() exists — there is no attachPdf())
    //
    //  $this->pdf($field) returns a PdfDescriptor. Chain methods, then build():
    //
    //  ->text(string $escaped)
    //    Replace the default cell text (pre-escaped value) with something else.
    //    Pass '' to suppress it entirely (e.g. signature — image speaks for itself).
    //
    //  ->rawHtml(string $html)
    //    Use when the value itself IS trusted HTML (HtmlField).
    //
    //  ->unlabeled()
    //    Suppress the label row — only for HTML blocks without a heading.
    //
    //  ->attachImage(string $binary, string $filename, string $mime = 'image/png')
    //    Embeds an image and records its perceptual hash in the HMAC seal. TIFF is auto-converted to PNG. PdfUtils::thumbnailHash($binary) is used internally — no manual call needed.
    //    Chain multiple times to attach several images — each call appends one image after the cell text, in attachment order.
    //    All images appear together after the cell text; there is no mechanism to interleave per-image captions between images.
    //    For per-image captions, build the caption list into ->rawHtml() and accept that images are grouped below it.
    //
    //  ->build()
    //    Returns the array Generator consumes. Always call last.

    /**
     * EXAMPLE (unused) — a field that also renders a QR code image of its value.
     * Would replace the inherited BaseField::pdfData() as a real override.
     */
    private function examplePdfDataQrCode(array $field): array
    {
        $binary = $this->generateQrPng((string)($field['value'] ?? ''));
        return $this->pdf($field)
            ->attachImage($binary, 'qr.png')
            ->build();
    }

    /**
     * EXAMPLE (unused) — a field whose value is raw HTML (like HtmlField).
     */
    private function examplePdfDataRawHtml(array $field): array
    {
        return $this->pdf($field)
            ->rawHtml((string)($field['value'] ?? ''))
            ->build();
    }


    // ═══════════════════════════════════════════════════════
    //  SKIP VALIDATION — presentational fields only
    // ═══════════════════════════════════════════════════════
    //
    //  Return true for fields that carry no user input and should never be evaluated by validatePage() — e.g. PageBreakField, HtmlField.
    //  Assets::enqueueFront() collects these into window.FabricatorSkipValidation. Default (BaseField): false. Almost every field should leave this alone.
    //  FormProcessor also checks skipValidation() — returning true skips both server-side validation and value extraction for the field.

    /** EXAMPLE (unused) — would replace the inherited BaseField::skipValidation(). */
    private function exampleSkipValidation(): bool
    {
        return true;
    }


    // ═══════════════════════════════════════════════════════
    //  OUTPUT FLAGS — control how the field appears in email and PDF
    // ═══════════════════════════════════════════════════════
    //
    //  includeInEmailSummary(): bool
    //    Default: true. Return false for fields that carry no user-submitted
    //    value and should be invisible in the {all_fields} email block.
    //    MailSender checks this before building each row. HtmlField and
    //    PageBreakField return false.
    //
    //  includeValueInSeal(): bool
    //    Default: true. Return false when the field value is a data URI,
    //    binary blob, or otherwise non-text content that must be excluded
    //    from the HMAC integrity seal text. Generator::buildSealFields()
    //    sets the value to '' for any field returning false. SignatureField
    //    returns false (the image binary is sealed separately via its hash).
    //
    //  hasTextPreview(): bool
    //    Default: false. Return true when the field produces a short plain-text
    //    string that is meaningful as a sample in the PDF layout token-picker
    //    preview. PDFLayoutEditor filters dummyFields() using this flag.
    //    Only TextField, EmailField, and TextareaField return true.
    //
    //  rawEmailHtml(): bool
    //    Default: false. Return true when the field's value is trusted HTML that should be
    //    output unescaped in {all_fields}/{field_id} mail placeholders instead of the normal
    //    nl2br(esc_html(...)) treatment. Only safe when the value can never come from
    //    end-user input — see HtmlField, which sanitizes html_content via wp_kses() at
    //    config-save time, so marking it rawEmailHtml() here doesn't reopen an XSS path.
    //    MailSender checks this flag per-field before choosing the escaping path.

    /** EXAMPLE (unused) — would replace the inherited BaseField::includeInEmailSummary(). */
    private function exampleIncludeInEmailSummary(): bool
    {
        return false;
    }

    /** EXAMPLE (unused) — would replace the inherited BaseField::includeValueInSeal(). */
    private function exampleIncludeValueInSeal(): bool
    {
        return false;
    }

    /** EXAMPLE (unused) — would replace the inherited BaseField::hasTextPreview(). */
    private function exampleHasTextPreview(): bool
    {
        return true;
    }


    // ═══════════════════════════════════════════════════════
    //  STRUCTURAL FLAGS — form-level and renderer behavior
    // ═══════════════════════════════════════════════════════
    //
    //  These methods don't affect the field itself — they tell FormRenderer how to
    //  treat the field before rendering begins. Almost no field needs them.
    //
    //  needsMultipartEncoding(): bool
    //    Return true when your field uses a file input. FormRenderer checks all
    //    fields and adds enctype="multipart/form-data" to the <form> tag when any
    //    returns true. Default: false. Only UploadField returns true.
    //
    //  enqueueFrontScripts(): void
    //    Called once per unique field type present in a form before rendering.
    //    Call wp_enqueue_script() here for any third-party library your field
    //    requires (e.g. Google reCAPTCHA). Default: no-op. Only CaptchaField overrides this.
    //
    //  isPageBreak(): bool
    //    FormRenderer calls renderBreak(array $config, int $page): string on the field
    //    instead of render() and emits page-navigation HTML and <div> wrappers around it.
    //    You must also implement renderBreak() when returning true. Default: false.
    //    Only PageBreakField returns true.
    //
    //  isGroupContainer(): bool
    //    FormRenderer calls openTag()/closeTag() on the field and recurses into
    //    $config['children'] to render child fields inline. You must also implement
    //    openTag() and closeTag() when returning true. Default: false.
    //    Only GroupField returns true.

    /** EXAMPLE (unused) — would replace the inherited BaseField::needsMultipartEncoding(). */
    private function exampleNeedsMultipartEncoding(): bool
    {
        return true;
    }

    /** EXAMPLE (unused) — would replace the inherited BaseField::enqueueFrontScripts(). */
    private function exampleEnqueueFrontScripts(): void
    {
        wp_enqueue_script('my-lib', 'https://example.com/lib.js', [], FABRICATOR_FORMS_VERSION, true);
    }


    // ═══════════════════════════════════════════════════════
    //  DEFAULT CONFIG — every config key the field uses
    // ═══════════════════════════════════════════════════════
    // The builder reads this when a new field is dropped onto the canvas.
    // Always merge with parent to inherit: label, required, hide_label,
    // placeholder, description.

    /**
     * Returns default config values for the builder canvas.
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
            ]
        );
    }


    // ═══════════════════════════════════════════════════════
    //  SETTINGS SCHEMA — what the builder's right-hand panel renders
    // ═══════════════════════════════════════════════════════
    //
    //  ┌────────────────┬──────────────────────────────────────────────────────────────┐
    //  │ type           │ renders as                                                   │
    //  ├────────────────┼──────────────────────────────────────────────────────────────┤
    //  │ text           │ single-line text input                                       │
    //  │ textarea       │ multi-line textarea                                          │
    //  │ number         │ numeric input                                                │
    //  │ checkbox       │ single toggle checkbox                                       │
    //  │ bool_seg       │ two-button segmented switch (false_label/true_label)         │
    //  │ pill3          │ three-way pill (values[] + labels[] required)                │
    //  │ options_list   │ editable draggable option list (select/radio/chk)            │
    //  │ icon_row       │ icon picker row (options[] required)                         │
    //  │ limit_row      │ char/word limit row (count_key required)                     │
    //  │ subfields      │ sub-field configurator (items[] required)                    │
    //  │ rating_preview │ live star-rating preview widget (no extra keys)              │
    //  │ html_editor    │ raw HTML textarea (for HtmlField)                            │
    //  │ media_upload   │ WP media library picker + thumbnail preview                  │
    //  │ notice         │ informational banner (level: 'info'|'warning'|'error', text) │
    //  └────────────────┴──────────────────────────────────────────────────────────────┘
    //
    //  Optional keys on any entry:
    //    hint       → small grey hint text shown below the control
    //    rebuild    => true  → re-renders the canvas preview when value changes
    //    depends_on => ['key' => value]   → hide entry unless another key equals value
    //    depends_on => ['key' => 'x', 'not' => 'y']  → hide when key equals 'y'
    //
    //  The builder iterates over these arrays and automatically generates the
    //  settings panel — one control per entry, in order. You never write panel HTML.
    //
    //  IMPORTANT: label / required / hide_label are rendered automatically by
    //  the builder JS and must NOT appear in any schema array.

    /**
     * Returns the settings schema for the General tab.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return array_merge(
            $this->baseGeneralEntries(), // placeholder + description
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
                // Media upload example — stores a URL string:
                // ['key' => 'icon_url', 'type' => 'media_upload', 'label' => __('Icon', 'formfabricator')],
                // Notice (no key — display only, no config value):
                // ['type' => 'notice', 'level' => 'warning', 'text' => __('Note…', 'formfabricator')],
            ]
        );
    }

    /**
     * Returns the settings schema for the Advanced tab.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        return [
            [
                'key'        => 'maxlength',
                'type'       => 'textarea',
                'label'      => __('Pattern', 'formfabricator'),
                'hint'       => __('One per line', 'formfabricator'),
                'depends_on' => ['key' => 'my_select', 'not' => 'option-a'], // visible unless my_select = 'option-a'
            ],
        ];
    }
}
