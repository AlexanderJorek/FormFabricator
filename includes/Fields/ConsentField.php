<?php

/**
 * Single consent checkbox field.
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
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Fields;

defined('ABSPATH') || exit;

/**
 * Single-checkbox consent field.
 */
class ConsentField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/ConsentField.css');
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'consent';
    }

    public function getLabel(): string
    {
        return __('Consent', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-circle-check';
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
        $req     = !empty($config['required']) ? ' required aria-required="true"' : '';
        $checked = !empty($value) ? ' checked' : '';
        $text    = \FabricatorForms\Utils\HtmlSanitizer::sanitize((string) ($config['consent_text'] ?? __('I agree.', 'formfabricator')));

        $inner = '<label class="fabricator-consent-label">'
            . '<input type="checkbox" id="' . esc_attr($field_id)
            . '" name="' . esc_attr($field_id) . '" value="1"' . $checked . $req . '>'
            . '<span class="fabricator-consent-text">' . $text . '</span>'
            . '</label>';

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Client-side empty check: an unchecked box is empty (the generic fallback reads its value="1" as filled).
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return ['fn' => "function(f){return !f.querySelector('input[type=\"checkbox\"]:checked');}"];
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
        if (!empty($config['required']) && empty($value)) {
            $label = $config['label'] ?? __('Consent', 'formfabricator');
            // translators: %s: field label.
            return sprintf(__('%s is required.', 'formfabricator'), $label);
        }
        return true;
    }

    /**
     * Embeds the actual consent text and a timestamp (not just "Yes") so GDPR Art. 7(1) consent is demonstrable.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return string Human-readable representation.
     */
    public function map(mixed $value, array $config): string
    {
        if (empty($value) || $value === '0') {
            return __('Not agreed', 'formfabricator');
        }
        $consent_text = wp_strip_all_tags((string)($config['consent_text'] ?? __('I agree to the terms.', 'formfabricator')));
        return sprintf(
            // translators: %1$s: consent text shown to the user, %2$s: agreement timestamp.
            __('Agreed to "%1$s" on %2$s', 'formfabricator'),
            $consent_text,
            // UTC with explicit marker so the consent timestamp is unambiguous (GDPR Art. 7(1)).
            gmdate('Y-m-d H:i:s') . ' UTC'
        );
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), ['consent_text' => __('I agree to the terms.', 'formfabricator')]);
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
                'key'         => 'consent_text',
                'type'        => 'textarea',
                'label'       => __('Consent text', 'formfabricator'),
                // GDPR Art. 7(1)/4(11): warn here so a generic unedited placeholder isn't shipped as "consent".
                'description' => __(
                    'Be specific about what the visitor is agreeing to (e.g. name the processing purpose) — a generic phrase like "I agree to the terms" is not valid, informed consent under GDPR.',
                    'formfabricator'
                ),
            ],
        ];
    }
}
