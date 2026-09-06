<?php

/**
 * Numeric input field with optional min/max constraints.
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
 * Numeric input field with min/max/step constraints.
 */
class NumberField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/NumberField.css');
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'number';
    }

    public function getLabel(): string
    {
        return __('Number', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-hashtag';
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
        $extra = ['value' => esc_attr((string)($value ?? ''))];
        if (($config['min'] ?? '') !== '') {
            $extra['min'] = $config['min'];
        }
        if (($config['max'] ?? '') !== '') {
            $extra['max'] = $config['max'];
        }
        if (($config['step'] ?? '') !== '') {
            $extra['step'] = $config['step'];
        }
        $validation = $config['validation'] ?? '';
        if (in_array($validation, ['integer', 'positive', 'positive_int'], true)) {
            $extra['data-validation'] = $validation;
        }
        $attrs = $this->inputAttrs($config, $field_id, 'number', $extra);
        return $this->wrap($field_id, $config, '<input' . $attrs . '>');
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
        if ($value === '' || $value === null) {
            return true;
        }
        $hard = self::validateTextHardCap((string) $value);
        if ($hard !== true) {
            return $hard;
        }
        if (!is_numeric($value)) {
            return __('Please enter a valid number.', 'formfabricator');
        }
        $num = (float)$value;
        if (($config['min'] ?? '') !== '' && $num < (float)$config['min']) {
            // translators: %s: minimum allowed value.
            return sprintf(__('Minimum value: %s', 'formfabricator'), $config['min']);
        }
        if (($config['max'] ?? '') !== '' && $num > (float)$config['max']) {
            // translators: %s: maximum allowed value.
            return sprintf(__('Maximum value: %s', 'formfabricator'), $config['max']);
        }
        // Builder-configured "Validation rule" (Validation section).
        $is_int = $num === floor($num);
        switch ($config['validation'] ?? '') {
            case 'integer':
                if (!$is_int) {
                    return __('Please enter a whole number.', 'formfabricator');
                }
                break;
            case 'positive':
                if ($num <= 0) {
                    return __('Please enter a positive number.', 'formfabricator');
                }
                break;
            case 'positive_int':
                if (!$is_int || $num <= 0) {
                    return __('Please enter a positive whole number.', 'formfabricator');
                }
                break;
        }
        return true;
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'number-range', 'fn' => self::readFieldAsset('assets/js/fields/NumberField.number-range.js')]];
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
            'min'  => '',
            'max'  => '',
            'step' => 1,
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
        return array_merge(
            $this->baseGeneralEntries(),
            [
            [
                'key'   => 'min',
                'type'  => 'number',
                'label' => __('Minimum value', 'formfabricator'),
            ],
            [
                'key'   => 'max',
                'type'  => 'number',
                'label' => __('Maximum value', 'formfabricator'),
            ],
            [
                'key'   => 'step',
                'type'  => 'number',
                'label' => __('Step size', 'formfabricator'),
            ],
            ]
        );
    }
}
