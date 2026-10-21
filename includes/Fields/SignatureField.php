<?php

/**
 * Canvas-based signature capture field.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.1.1
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
 * Canvas-based signature capture field.
 */
class SignatureField extends BaseField
{
    private const ICON_RESET_HTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
        . ' aria-hidden="true" focusable="false">'
        . '<path d="M125.7 160H176a16 16 0 0 1 0 32H48a16 16 0 0 1-16-16V48a16 16 0 0 1 32 0v68.7'
        . 'C115.3 45.1 191.6 0 278 0c141.4 0 256 114.6 256 256S419.4 512 278 512'
        . 'C167.7 512 74.4 443.5 38 346a16 16 0 1 1 30-11c31.4 83.7 111.5 141 210 141'
        . ' 123.7 0 224-100.3 224-224S401.7 32 278 32c-78.1 0-145.8 39.4-185.3 99.3z"/>'
        . '</svg>';

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/SignatureField.css');
    }

    /**
     * Returns false: this field renders a canvas and a hidden input, neither carrying the field id, so the
     * question text names the set through aria-labelledby rather than pointing at one element (see BaseField::wrap()).
     *
     * @param array $config Field configuration.
     * @return bool
     */
    public function labelsOwnControl(array $config): bool
    {
        unset($config);
        return false;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'signature';
    }

    public function getLabel(): string
    {
        return __('Signature', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-signature';
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/SignatureField.js');
    }

    /**
     * The place of signing, once switched on, is needed with a signature: checked in the browser as validate() does.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'signature-place', 'fn' => self::readFieldAsset('assets/js/fields/SignatureField.place.js')]];
    }

    /**
     * Settings that are plain text: the title above the signature and the labels. The text and its fine print are HTML.
     *
     * @return string[]
     */
    protected function plainTextConfigKeys(): array
    {
        return array_merge(parent::plainTextConfigKeys(), ['signature_title', 'signed_label', 'place_label']);
    }

    /**
     * What the signature posts: the pad's value (a data URI, or the name typed instead) under the field's id, and the
     * place of signing under "<id>-place". A bare string is the pad's value alone.
     *
     * @param mixed $value What extractValue() returned.
     * @return array{sig: string, place: string}
     */
    private static function posted(mixed $value): array
    {
        if (is_array($value)) {
            return [
                'sig'   => is_string($value['sig'] ?? null) ? $value['sig'] : '',
                'place' => is_string($value['place'] ?? null) ? trim($value['place']) : '',
            ];
        }
        return ['sig' => is_string($value) ? $value : '', 'place' => ''];
    }

    /**
     * The title, text and fine print shown above the signature, as the page shows them and the record keeps them,
     * each sanitized. Empty text means none.
     *
     * @param array $config Field configuration.
     * @return array{title: string, text: string, note: string}
     */
    private static function texts(array $config): array
    {
        return [
            'title' => trim((string) ($config['signature_title'] ?? '')),
            'text'  => \FabricatorForms\Utils\Text\HtmlSanitizer::sanitize((string) ($config['signature_text'] ?? '')),
            'note'  => \FabricatorForms\Utils\Text\HtmlSanitizer::sanitize((string) ($config['signature_note'] ?? '')),
        ];
    }

    /**
     * The place-of-signing label as set, or its default.
     *
     * @param array $config Field configuration.
     * @return string
     */
    private static function placeLabel(array $config): string
    {
        $label = trim((string) ($config['place_label'] ?? ''));
        return $label !== '' ? $label : __('Place of signing:', 'formfabricator');
    }

    /**
     * The message for a signature without its place, named by the label the admin set.
     *
     * @param array $config Field configuration.
     * @return string
     */
    private static function placeRequiredMessage(array $config): string
    {
        // translators: %s: field label.
        return sprintf(__('%s is a required field.', 'formfabricator'), rtrim(self::placeLabel($config), ': '));
    }

    /**
     * What a rule on this field reads: the pad's value, as front.js reads the input named after the field.
     *
     * @param mixed $raw    What extractValue() returned.
     * @param array $config Field configuration.
     * @return mixed
     */
    public function conditionValue(mixed $raw, array $config): mixed
    {
        return self::posted($raw)['sig'];
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
        $canvas_id = $field_id . '-canvas';
        // Clamped: an unbounded height produced a huge canvas, and a signature data URI to match.
        $height    = min(600, max(80, (int)($config['canvas_height'] ?? 160)));
        $stroke    = (float)($config['stroke_width'] ?? 2);
        $fmt       = $config['export_format'] ?? 'png';

        $inner_html = '<div class="fabricator-signature-wrap"' . (!empty($config['required']) ? ' data-required="true"' : '')
            . ' data-field-id="' . esc_attr($field_id) . '"'
            . ' data-stroke="' . esc_attr((string)$stroke) . '"'
            . ' data-format="' . esc_attr($fmt) . '">'
            . '<canvas id="' . esc_attr($canvas_id) . '" class="fabricator-signature-canvas"'
            . ' width="500" height="' . esc_attr($height) . '"'
            . ' style="height:' . absint($height) . 'px"'
            . ' tabindex="0"'
            . ' aria-label="' . esc_attr($config['label'] ?? __('Signature', 'formfabricator')) . '"></canvas>'
            . self::typedSignatureInputHtml($field_id . '-typed', __('Your full name', 'formfabricator'))
            . '<div class="fabricator-signature-toolbar">'
            . '<button type="button" class="fabricator-signature-clear"'
            . ' data-canvas="' . esc_attr($canvas_id) . '" title="' . esc_attr__('Clear', 'formfabricator') . '" aria-label="' . esc_attr__('Clear signature', 'formfabricator') . '">'
            . self::ICON_RESET_HTML . '</button>'
            . '<span class="fabricator-signature-hint">' . esc_html__('Sign here', 'formfabricator') . '</span>'
            . self::signatureModeButtonHtml(__('Type your name instead', 'formfabricator'), __('Draw instead', 'formfabricator'))
            . '</div>'
            . '<input type="hidden" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '-data"'
            . ' value="' . esc_attr(self::posted($value)['sig']) . '">'
            . '</div>';

        // The title and text above the signature, as the direct debit mandate shows its own: what mapNormalized() records.
        $texts = self::texts($config);
        if (implode('', $texts) !== '') {
            $inner_html = '<div class="fabricator-signature-texts">'
                . ($texts['title'] !== '' ? '<h3 class="fabricator-signature-title">' . esc_html($texts['title']) . '</h3>' : '')
                . ($texts['text'] !== '' ? '<div class="fabricator-signature-text">' . wp_kses($texts['text'], \FabricatorForms\Utils\Text\HtmlSanitizer::allowedTags()) . '</div>' : '')
                . ($texts['note'] !== '' ? '<p class="fabricator-signature-note">' . wp_kses($texts['note'], \FabricatorForms\Utils\Text\HtmlSanitizer::allowedTags()) . '</p>' : '')
                . '</div>'
                . $inner_html;
        }

        if (!empty($config['signing_place'])) {
            $place_id = $field_id . '-place';
            $inner_html .= '<div class="fabricator-signature-place-row">'
                . '<label class="fabricator-label" for="' . esc_attr($place_id) . '">' . esc_html(self::placeLabel($config))
                . (!empty($config['required']) ? ' <span class="fabricator-required" aria-hidden="true">*</span>' : '') . '</label>'
                . '<input type="text" id="' . esc_attr($place_id) . '" name="' . esc_attr($place_id) . '"'
                . ' class="fabricator-input fabricator-signature-place" autocomplete="address-level2"'
                . ' data-required-message="' . esc_attr(self::placeRequiredMessage($config)) . '"'
                . ' value="' . esc_attr(self::posted($value)['place']) . '">'
                // Own class: front.js fills a field's first "fabricator-field-error", which stays the pad's.
                . '<div class="fabricator-signature-place-error" id="' . esc_attr($place_id) . '-error" role="alert"></div>'
                . '</div>';
        }

        // Boxed like the mandate, as the PDF and the mail draw it.
        return $this->wrap($field_id, $config, $inner_html, 'fabricator-signature--boxed');
    }

    /**
     * Returns the sanitized signature: the canvas's base64 data URI, or the name typed instead (isTypedSignature()).
     * Returns null when the hidden input is absent (isEmpty() treats null as empty).
     *
     * @param string $field_id The field element ID.
     * @param array  $post     The posted answers (BaseField::extractValue()).
     * @param array  $files    The uploaded files' entries (BaseField::extractValue()).
     */
    public function extractValue(string $field_id, array $post, array $files = []): mixed
    {
        if (!isset($post[$field_id])) {
            return null;
        }
        return [
            'sig'   => self::answer($post[$field_id]),
            // The place of signing, under a key of its own as the mandate's signature is: the field's own input stays
            // the pad's, which conditions read.
            'place' => self::answer($post[$field_id . '-place'] ?? null),
        ];
    }

    /**
     * The pad's value is a data URI or a typed name, sealed by the image's hash or as the PDF's text; the text's
     * start and end are sealed as their text, like the mandate's.
     *
     * @param array $entry The entry (mapNormalized()).
     * @return bool
     */
    public function includeValueInSeal(array $entry = []): bool
    {
        return isset($entry['signature_part']);
    }

    /**
     * The text's start entry opens its box, its end entry closes it.
     *
     * @param array $entry The entry (mapNormalized()).
     * @return string
     */
    public function framePart(array $entry): string
    {
        return match ($entry['signature_part'] ?? '') {
            'begin' => 'open',
            'end'   => 'close',
            default => '',
        };
    }

    // front.js's generic empty-check ignores type="hidden" inputs, which is all this field has.
    public function getClientEmptyCheck(): array
    {
        return ['fn' => "function(f){var i=f.querySelector('input[type=\"hidden\"]');return !i||i.value==='';}"];
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
        ['sig' => $sig, 'place' => $place] = self::posted($value);
        // A real signature is a few KB; capped well above that before anything decodes it.
        if (strlen($sig) > 2 * 1024 * 1024) {
            return __('Signature data is too large.', 'formfabricator');
        }
        // Unsigned: an optional signature records nothing, so a place typed without one is left out too.
        if ($sig === '') {
            if (empty($config['required'])) {
                return true;
            }
            $label = $config['label'] ?? __('Signature', 'formfabricator');
            // translators: %s: field label.
            return sprintf(__('%s is a required field.', 'formfabricator'), $label);
        }
        // Once switched on, the place belongs to the signature, as in the direct debit mandate.
        if (!empty($config['signing_place'])) {
            if ($place === '') {
                return self::placeRequiredMessage($config);
            }
            $capped = self::validateTextHardCap($place);
            if ($capped !== true) {
                return $capped;
            }
        }
        if (self::isTypedSignature($sig)) {
            return self::validateTextHardCap($sig);
        }
        // Present but not a readable image: "required field" would tell a visitor who has signed that they haven't, and
        // an optional signature that fails here would be dropped from the mail and PDF without a word.
        $format = ($config['export_format'] ?? 'png') === 'jpeg' ? 'jpeg' : 'png';
        if (!self::isValidSignatureImage($sig, $format)) {
            return __('The signature could not be read. Please sign again.', 'formfabricator');
        }
        return true;
    }

    /**
     * Returns a normalized entry with the materialized signature image.
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
        ['sig' => $sig, 'place' => $place] = self::posted($value);

        // A typed name is text: recorded, shown in the PDF and sealed like any answer (a drawing's image is sealed by hash).
        if (self::isTypedSignature($sig)) {
            $signature = [$field_id => [
                'label' => $label,
                'type'  => 'text',
                'value' => self::typedSignatureRecord($sig),
            ]];
        } else {
            $materialized = self::materializeSignature($sig);
            $signature    = [$field_id => [
                'label'              => $label,
                'type'               => 'signature',
                // Not "see attachment": whether the email carries one is the notification's choice (the builder warns when none).
                'value'              => $materialized ? __('[Signature present]', 'formfabricator') : __('[No entry]', 'formfabricator'),
                'materialized_files' => $materialized,
            ]];
            // An optional signature left empty was not given: no text, time or place around "[No entry]".
            if ($materialized === []) {
                return $signature;
            }
        }

        $entries = $signature + self::signedAt($field_id, $config);
        if (!empty($config['signing_place'])) {
            $entries[$field_id . '_place'] = [
                'label' => self::placeLabel($config),
                'type'  => 'text',
                'value' => $place !== '' ? $place : __('[No entry]', 'formfabricator'),
            ];
        }

        // A titled box, as the mandate's. Title: signature_title, else the label (then left off the entry inside so it
        // isn't named twice); a hidden label is no title.
        $texts = self::texts($config);
        $title = $texts['title'];
        if ($title === '') {
            $title = empty($config['hide_label']) ? $label : '';
            $entries[$field_id]['label_hidden'] = true;
        }
        $body  = \FabricatorForms\Utils\Text\HtmlSanitizer::plainText($texts['text']);
        $note  = \FabricatorForms\Utils\Text\HtmlSanitizer::plainText($texts['note']);
        return [
            $field_id . '_begin' => [
                'label'          => $title,
                'type'           => 'signature',
                'value'          => $note !== '' ? trim($body . "\n\n" . $note) : $body,
                'signature_part' => 'begin',
            ],
        ] + $entries + [
            // Labelled, so the verification page can name where the text ends; mail and PDF close the box instead.
            $field_id . '_end' => [
                // translators: %s: the title above a signature (or the field's label).
                'label'          => sprintf(__('End of %s', 'formfabricator'), $title !== '' ? $title : $label),
                'type'           => 'signature',
                'value'          => '',
                'signature_part' => 'end',
            ],
        ];
    }

    /**
     * When the signature was given, as mail, PDF and seal record it: when the form is sent, since it is signed on the
     * page. An empty optional signature records none.
     *
     * @param string $field_id Field identifier.
     * @param array  $config   Field configuration.
     * @return array<string, array>
     */
    private static function signedAt(string $field_id, array $config): array
    {
        $label = trim((string) ($config['signed_label'] ?? ''));
        return [$field_id . '_signed' => [
            'label' => $label !== '' ? $label : __('Signed on', 'formfabricator'),
            'type'  => 'text',
            'value' => \FabricatorForms\Utils\Text\SigningTime::format(),
        ]];
    }

    /**
     * Override: show only the drawn image, not the placeholder text. The image goes in the media section below the text fields.
     *
     * @param array $field Normalized entry from FieldRegistry::mapSubmission().
     * @return array PDF render descriptor.
     */
    public function pdfData(array $field): array
    {
        // The text above the signature opens a titled box (title as heading, text as cell) that its end closes, as the
        // mandate's does.
        $part = $this->framePart($field);
        if ($part === 'open') {
            $title = is_string($field['label'] ?? null) ? $field['label'] : '';
            $text  = is_string($field['value'] ?? null) ? $field['value'] : '';
            return $this->pdf($field)->text(nl2br(esc_html($text)))->unlabeled()->opensFrame($title)->build();
        }
        if ($part === 'close') {
            return $this->pdf($field)->text('')->unlabeled()->closesFrame()->build();
        }

        // Clear the mail-summary text pdf() seeds from $field['value']; only the image below should show.
        $desc = $this->pdf($field)->text('');

        foreach ($field['materialized_files'] ?? [] as $file) {
            $mime   = $file['mime'] ?? '';
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes binary handed over the array boundary described at the encode site (strict mode). Not obfuscation.
            $binary = !empty($file['base64']) ? base64_decode($file['base64'], true) : false;
            if ($binary === false || !str_starts_with($mime, 'image/')) {
                continue;
            }
            $desc->attachImage($binary, (string)($file['name'] ?? 'signature.png'), $mime);
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
            'canvas_height' => 200,
            'stroke_width'  => 2,
            'export_format'   => 'png',
            'signed_label'    => __('Signed on', 'formfabricator'),
            'signature_title' => '',
            'signature_text'  => '',
            'signature_note'  => '',
            'signing_place'   => false,
            'place_label'     => __('Place of signing:', 'formfabricator'),
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
            ['key' => 'signature_title', 'type' => 'text', 'label' => __('Title above the signature', 'formfabricator')],
            [
                'key'   => 'signature_text',
                'type'  => 'textarea',
                'label' => __('Text above the signature (HTML allowed)', 'formfabricator'),
                'hint'  => __('What the visitor signs: shown above the signature pad and recorded with the signature in the email and the PDF. Empty: none.', 'formfabricator'),
            ],
            ['key' => 'signature_note', 'type' => 'textarea', 'label' => __('Hint text (fine print, HTML allowed)', 'formfabricator')],
            ['key' => 'signing_place', 'type' => 'checkbox', 'label' => __('Ask for the place of signing', 'formfabricator'), 'rebuild_advanced' => true],
        ];
    }

    /**
     * Returns the advanced settings schema for the field editor.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        return [
            [
                'key'   => 'canvas_height',
                'type'  => 'number',
                'label' => __('Height (px)', 'formfabricator'),
            ],
            [
                'key'   => 'stroke_width',
                'type'  => 'number',
                'label' => __('Stroke width', 'formfabricator'),
            ],
            [
                'key'    => 'export_format',
                'type'   => 'pill3',
                'label'  => __('File format', 'formfabricator'),
                'values' => ['png', 'jpeg'],
                'labels' => ['PNG', 'JPEG'],
            ],
            [
                'key'   => 'signed_label',
                'type'  => 'text',
                'label' => __('"Signed on" label', 'formfabricator'),
            ],
            [
                'key'        => 'place_label',
                'type'       => 'text',
                'label'      => __('"Place of signing" label', 'formfabricator'),
                'depends_on' => ['signing_place' => true],
            ],
        ];
    }
}
