<?php

/**
 * Currency amount input field.
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
 * Currency amount input field with locale-aware formatting.
 */
class CurrencyField extends BaseField
{
    private const CURRENCIES = [
        'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'Fr.',
        'JPY' => '¥', 'CAD' => 'CA$', 'AUD' => 'A$', 'SEK' => 'kr',
        'NOK' => 'kr', 'DKK' => 'kr', 'PLN' => 'zł', 'CZK' => 'Kč',
    ];

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'currency';
    }

    public function getLabel(): string
    {
        return __('Currency', 'formfabricator');
    }

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/CurrencyField.css');
    }
    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-euro-sign';
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
        $symbol  = self::CURRENCIES[$config['currency'] ?? 'EUR'] ?? '€';
        $req     = !empty($config['required']) ? ' required aria-required="true"' : '';
        $min_attr = ($config['min_value'] ?? '') !== '' ? ' min="' . esc_attr((string)$config['min_value']) . '"' : '';
        $max_attr = ($config['max_value'] ?? '') !== '' ? ' max="' . esc_attr((string)$config['max_value']) . '"' : '';
        $inner   = '<div class="fabricator-currency-wrap">'
            . '<span class="fabricator-currency-symbol">' . esc_html($symbol) . '</span>'
            . '<input type="number" step="0.01" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '"'
            . ' class="fabricator-input fabricator-currency-input" placeholder="0,00"'
            . ' value="' . esc_attr((string)($value ?? '')) . '"'
            . $min_attr . $max_attr . $req . '>'
            . '</div>';
        return $this->wrap($field_id, $config, $inner);
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
            return __('Please enter a valid amount.', 'formfabricator');
        }
        $num = (float)$value;
        if (($config['min_value'] ?? '') !== '' && $num < (float)$config['min_value']) {
            // translators: %s: minimum allowed value.
            return sprintf(__('Minimum value: %s', 'formfabricator'), $config['min_value']);
        }
        if (($config['max_value'] ?? '') !== '' && $num > (float)$config['max_value']) {
            // translators: %s: maximum allowed value.
            return sprintf(__('Maximum value: %s', 'formfabricator'), $config['max_value']);
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
        return [['rule' => 'currency-range', 'fn' => self::readFieldAsset('assets/js/fields/CurrencyField.currency-range.js')]];
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
        if ($value === '' || $value === null) {
            return __('[No entry]', 'formfabricator');
        }
        $symbol = self::CURRENCIES[$config['currency'] ?? 'EUR'] ?? '€';
        $number = is_numeric($value) ? number_format((float)$value, 2, ',', '.') : (string)$value;
        return trim($number . ' ' . $symbol);
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
            'currency'  => 'EUR',
            'min_value' => '',
            'max_value' => '',
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
        $currencyOptions = [];
        foreach (self::CURRENCIES as $code => $sym) {
            $currencyOptions[] = ['value' => $code, 'label' => $code . ' ' . $sym];
        }
        return array_merge(
            $this->baseGeneralEntries(),
            [
            [
                'key'     => 'currency',
                'type'    => 'select',
                'label'   => __('Currency', 'formfabricator'),
                'options' => $currencyOptions,
            ],
            [
                'key'   => 'min_value',
                'type'  => 'number',
                'label' => __('Minimum value', 'formfabricator'),
                'hint'  => __('Empty = no minimum', 'formfabricator'),
            ],
            [
                'key'   => 'max_value',
                'type'  => 'number',
                'label' => __('Maximum value', 'formfabricator'),
                'hint'  => __('Empty = no maximum', 'formfabricator'),
            ],
            ]
        );
    }
}
