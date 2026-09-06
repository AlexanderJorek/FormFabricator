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
 * @version   1.0.6
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
    private const ICON_RESET = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
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
     * Renders the field HTML.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $req       = !empty($config['required']) ? ' data-required="true"' : '';
        $canvas_id = $field_id . '-canvas';
        $height    = (int)($config['canvas_height'] ?? 160);
        $stroke    = (float)($config['stroke_width'] ?? 2);
        $fmt       = $config['export_format'] ?? 'png';

        $inner = '<div class="fabricator-signature-wrap"' . $req
            . ' data-field-id="' . esc_attr($field_id) . '"'
            . ' data-stroke="' . esc_attr((string)$stroke) . '"'
            . ' data-format="' . esc_attr($fmt) . '">'
            . '<canvas id="' . esc_attr($canvas_id) . '" class="fabricator-signature-canvas"'
            . ' width="500" height="' . $height . '"'
            . ' style="height:' . $height . 'px"'
            . ' tabindex="0"'
            . ' aria-label="' . esc_attr($config['label'] ?? __('Signature', 'formfabricator')) . '"></canvas>'
            . '<div class="fabricator-signature-toolbar">'
            . '<button type="button" class="fabricator-signature-clear"'
            . ' data-canvas="' . esc_attr($canvas_id) . '" title="' . esc_attr__('Clear', 'formfabricator') . '" aria-label="' . esc_attr__('Clear signature', 'formfabricator') . '">'
            . self::ICON_RESET . '</button>'
            . '<span class="fabricator-signature-hint">' . esc_html__('Sign here', 'formfabricator') . '</span>'
            . '</div>'
            . '<input type="hidden" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '-data"'
            . ' value="' . esc_attr((string)($value ?? '')) . '">'
            . '</div>';

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the sanitized base64 data-URI submitted by the signature canvas. Returns null when the hidden input is absent (isEmpty() treats null as empty).
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via assertRequestNonceVerified().
        return isset($_POST[$field_id]) ? sanitize_text_field(wp_unslash($_POST[$field_id])) : null;
    }

    /**
     * Signature values are data URIs — excluded from the HMAC seal text.
     *
     * @return bool
     */
    public function includeValueInSeal(): bool
    {
        return false;
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
        if (!empty($config['required'])) {
            $format = ($config['export_format'] ?? 'png') === 'jpeg' ? 'jpeg' : 'png';
            if (empty($value) || !self::isSignatureDataUri((string)$value, $format)) {
                $label = $config['label'] ?? __('Signature', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s is a required field.', 'formfabricator'), $label);
            }
        }
        // A real canvas signature is a few KB; cap well above that so a
        // crafted oversized data URI can't inflate memory/CPU use per
        // submission (extractValue() has no upper bound of its own).
        if (!empty($value) && strlen((string)$value) > 2 * 1024 * 1024) {
            return __('Signature data is too large.', 'formfabricator');
        }
        return true;
    }

    /**
     * Maps the field value to a human-readable string for email and PDF output.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return string Human-readable representation.
     */
    public function map(mixed $value, array $config): string
    {
        return empty($value) ? __('[No entry]', 'formfabricator') : '';
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
        $materialized = self::materializeSignature($value);
        return [$field_id => [
            'label'              => $label,
            'type'               => 'signature',
            'value'              => $materialized ? __('[Signature present – see attachment]', 'formfabricator') : __('[No entry]', 'formfabricator'),
            'materialized_files' => $materialized,
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
        // Clear the mail-summary text pdf() seeds from $field['value']; only the image below should show.
        $desc = $this->pdf($field)->text('');

        foreach ($field['materialized_files'] ?? [] as $file) {
            $mime   = $file['mime'] ?? '';
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
            'export_format' => 'png',
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
        return [];
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
        ];
    }
}
