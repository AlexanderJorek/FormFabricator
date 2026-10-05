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
     * Its inputs are checkboxes named after the field id, which front.js reads as an empty list while hidden.
     *
     * @return array
     */
    public function hiddenConditionValue(): array
    {
        return [];
    }

    /**
     * A list, as front.js reads a checkbox named after the field: ["1"] when ticked, [] when not. Read as the scalar
     * "1", "greater" and "less" rules compared a number on the server and a list in the browser.
     *
     * @param mixed $raw    What extractValue() returned.
     * @param array $config Field configuration.
     * @return mixed
     */
    public function conditionValue(mixed $raw, array $config): mixed
    {
        return is_scalar($raw) && (string) $raw !== '' ? [(string) $raw] : [];
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
        $text    = self::shownText($config);

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
        // The text as the visitor saw it (render() shows the same shownText()), so the record never names another one.
        $consent_text = wp_strip_all_tags(self::shownText($config));
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
        return array_merge(parent::getDefaultConfig(), ['consent_text' => self::defaultText()]);
    }

    /**
     * The consent text as the page shows it. render() and map() both use it: a missing consent_text (a hand-built or
     * imported config) showed "I agree." on the page while the record said the visitor agreed to another text.
     *
     * @param array $config Field configuration.
     * @return string Sanitized HTML.
     */
    private static function shownText(array $config): string
    {
        $text = $config['consent_text'] ?? null;
        return \FabricatorForms\Utils\HtmlSanitizer::sanitize(is_string($text) ? $text : self::defaultText());
    }

    /**
     * Whether the field still shows the placeholder a new field starts with, which names no purpose and no way to
     * withdraw, for the builder's save warning.
     *
     * @param array $config Field configuration.
     * @return bool
     */
    public static function usesPlaceholderText(array $config): bool
    {
        return in_array(trim(wp_strip_all_tags(self::shownText($config))), self::placeholderTexts(), true);
    }

    /**
     * The placeholder in English and every installed language, since the builder fills it in the creating admin's
     * language. Built once per request.
     *
     * @return string[]
     */
    private static function placeholderTexts(): array
    {
        static $texts = null;
        if ($texts !== null) {
            return $texts;
        }
        // The msgid itself: __() returns the translation, and a field created before a language was set is in English.
        $texts = ['I agree to the terms.', self::defaultText()];
        foreach (get_available_languages() as $locale) {
            if (switch_to_locale($locale)) {
                $texts[] = self::defaultText();
                restore_previous_locale();
            }
        }
        return $texts = array_values(array_unique($texts));
    }

    /**
     * The consent text a new field starts with.
     *
     * @return string
     */
    private static function defaultText(): string
    {
        return __('I agree to the terms.', 'formfabricator');
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
                // GDPR Art. 7(1)/4(11): warn here so a generic unedited placeholder isn't shipped as "consent". 'hint', since
                // the builder shows hints and never displays a 'description'.
                'hint'        => __(
                    'Be specific about what the visitor is agreeing to (e.g. name the processing purpose) — a generic phrase like "I agree to the terms" is not valid, informed consent under GDPR.',
                    'formfabricator'
                )
                    . ' '
                    // GDPR Art. 7(4): consent made a condition for something it is not needed for is not freely given.
                    . __(
                        'Make it required only if what the form does cannot work without it; consent demanded for anything else is not freely given.',
                        'formfabricator'
                    )
                    . ' '
                    // GDPR Art. 7(3): the visitor must learn before agreeing that they can withdraw consent at any time.
                    . __(
                        'Also say how the visitor can withdraw their consent, e.g. "You can withdraw it at any time by emailing …".',
                        'formfabricator'
                    ),
            ],
        ];
    }
}
