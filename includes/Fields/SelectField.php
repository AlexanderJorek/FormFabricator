<?php

/**
 * Dropdown select field.
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
 * Dropdown select input field.
 */
class SelectField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/SelectField.css');
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'select';
    }

    public function getLabel(): string
    {
        return __('Dropdown', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-chevron-down';
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/SelectField.js');
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
        $options = $config['options'] ?? [];

        /* With "Other" text, extractValue() returns ['value' => …, '__other_text__' => …]: unwrap the selection. */
        $other_text = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
        if (is_array($value)) {
            $value = $value['value'] ?? '';
        }

        /* When no submitted value, fall back to the option marked as default */
        if ($value === null) {
            foreach ($options as $opt) {
                if (!empty($opt['default'])) {
                    $value = $opt['value'] ?? '';
                    break;
                }
            }
        }

        $inner = '<select id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id)
            . '" class="fabricator-input fabricator-select" autocomplete="off"' . $req . '>';
        $inner .= '<option value="">' . esc_html__('— Please select —', 'formfabricator') . '</option>';
        foreach ($options as $opt) {
            $opt_val   = $opt['value'] ?? '';
            $opt_label = $opt['label'] ?? $opt_val;
            $selected  = selected((string)($value ?? ''), (string)$opt_val, false);
            $inner .= '<option value="' . esc_attr((string)$opt_val) . '"'
                . $selected . '>'
                . esc_html((string)$opt_label)
                . '</option>';
        }
        if (!empty($config['other_option'])) {
            // $value was already unwrapped from its ['value' => ...] array form above.
            $inner .= '<option value="__other__"' . selected((string)($value ?? ''), '__other__', false) . '>' . esc_html__('Other…', 'formfabricator') . '</option>';
        }
        $inner .= '</select>';
        if (!empty($config['other_option'])) {
            $show  = (string)($value ?? '') === '__other__' ? '' : ' style="display:none"';
            $inner .= '<input type="text" name="' . esc_attr($field_id) . '_other"'
                . ' class="fabricator-input fabricator-other-input" value="' . esc_attr($other_text) . '"'
                . ' placeholder="' . esc_attr__('Please specify', 'formfabricator') . '"' . $show
                . self::otherInputAttrs($config) . '>';
        }

        return $this->wrap($field_id, $config, $inner);
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
        $other_text = '';
        if (is_array($value)) {
            $other_text = trim((string)($value['__other_text__'] ?? ''));
            $value      = $value['value'] ?? '';
        }
        if ($value === '' || $value === null) {
            return __('[No entry]', 'formfabricator');
        }
        if ((string)$value === '__other__') {
            return $other_text !== ''
                ? sprintf('%s (%s)', __('Other', 'formfabricator'), $other_text)
                : __('[Other]', 'formfabricator');
        }
        foreach ($config['options'] ?? [] as $opt) {
            $opt_val = $opt['value'] ?? '';
            if ((string)$opt_val === (string)$value) {
                return (string)($opt['label'] ?? $opt_val);
            }
        }
        return (string)$value;
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
        $base = parent::validate($value, $config);
        if ($base !== true) {
            return $base;
        }
        $selected = is_array($value) ? ($value['value'] ?? '') : $value;
        if ($selected === '' || $selected === null) {
            return true;
        }
        if ((string)$selected === '__other__') {
            if (empty($config['other_option'])) {
                return __('Please select a valid option.', 'formfabricator');
            }
            // "Other" selected with a blank companion text field is effectively no
            // answer — don't let it satisfy a required field.
            $other = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
            if (!empty($config['required']) && $other === '') {
                $label = $config['label'] ?? __('Field', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s is a required field.', 'formfabricator'), $label);
            }
            return self::validateOtherText($other, $config);
        }
        $allowed = array_map(
            static fn($o) => (string)($o['value'] ?? ''),
            $config['options'] ?? []
        );
        if (!in_array((string)$selected, $allowed, true)) {
            return __('Please select a valid option.', 'formfabricator');
        }
        return true;
    }

    /**
     * Returns the selected option, plus the typed "Other" text when present.
     *
     * @param string $field_id The field element ID.
     * @return mixed String selection, or ['value' => ..., 'other_text' => ...] when "Other" was
     *               selected and a companion text field was submitted.
     */
    public function extractValue(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via assertRequestNonceVerified().
        $selected = isset($_POST[$field_id]) ? sanitize_text_field(wp_unslash($_POST[$field_id])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        if ($selected === '__other__' && isset($_POST[$field_id . '_other'])) {
            return [
                'value'           => $selected,
                // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified in FormProcessor::handle(); capOtherText() sanitizes/unslashes, WPCS misses the helper form.
                '__other_text__'  => self::capOtherText($_POST[$field_id . '_other']),
            ];
        }
        return $selected;
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
            'other_option'      => false,
            'other_max_type'    => 'chars',
            'other_max_length'  => '',
            'options'      => [
                ['value' => 'option-1', 'label' => 'Option 1', 'default' => false],
                ['value' => 'option-2', 'label' => 'Option 2', 'default' => false],
            ],
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
                'key'   => 'description',
                'type'  => 'text',
                'label' => __('Description', 'formfabricator'),
            ],
            [
                'key'      => 'other_option',
                'type'     => 'checkbox',
                'label'    => __('Show "Other" option', 'formfabricator'),
                'rebuild'  => true,
            ],
            [
                'key'         => 'other_max_type',
                'type'        => 'limit_row',
                'label'       => __('"Other" text limit', 'formfabricator'),
                'count_key'   => 'other_max_length',
                'depends_on'  => ['other_option' => true],
            ],
            [
                'key'   => 'options',
                'type'  => 'options_list',
                'label' => __('Options', 'formfabricator'),
            ],
        ];
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [self::otherTextClientRule()];
    }
}
