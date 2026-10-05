<?php

/**
 * Phone number input field.
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
 * Phone number input field.
 */
class PhoneField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'phone';
    }

    public function getLabel(): string
    {
        return __('Phone', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-phone';
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'phone', 'fn' => self::readFieldAsset('assets/js/fields/PhoneField.phone.js')]];
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
        $mode  = $config['phone_mode'] ?? '';
        $attrs = $this->inputAttrs($config, $field_id, 'tel', ['value' => (string)($value ?? ''), 'autocomplete' => 'tel']);
        if ($mode !== '') {
            $attrs .= ' data-phone-mode="' . esc_attr($mode) . '"';
        }
        if ($mode === 'countries') {
            $cmode = $config['phone_country_mode'] ?? 'allow';
            $list  = array_values((array)($config['phone_country_list'] ?? []));
            $attrs .= ' data-phone-country-mode="' . esc_attr($cmode) . '"';
            if (!empty($list)) {
                $attrs .= " data-phone-country-list='" . esc_attr(\FabricatorForms\Utils\Cast::jsonForAttribute($list)) . "'";
            }
        }
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
        if ($value === null || $value === '') {
            return true;
        }

        // Length cap first, which also bounds the "Off" format mode.
        $hard = self::validateTextHardCap((string)$value);
        if ($hard !== true) {
            return $hard;
        }

        $v    = preg_replace('/[\s\-\(\)\/]/', '', (string)$value);
        $mode = $config['phone_mode'] ?? '';

        switch ($mode) {
            case 'any':
                if (!preg_match('/^\+?[0-9]{7,15}$/', $v)) {
                    return __('Please enter a valid phone number.', 'formfabricator');
                }
                break;

            case 'countries':
                if (!str_starts_with($v, '+')) {
                    return __('Please enter the number with international prefix (+...).', 'formfabricator');
                }
                // The rest must be a number, as in 'any' mode: an allow-list otherwise accepted "+49" followed by anything.
                if (!preg_match('/^\+[0-9]{7,15}$/', $v)) {
                    return __('Please enter a valid phone number.', 'formfabricator');
                }
                $digits = substr($v, 1);
                $list   = (array)($config['phone_country_list'] ?? []); /* e.g. ['+49', '+43'] */
                $cmode  = $config['phone_country_mode'] ?? 'allow';
                $in     = false;
                /* Sort by code length descending so longer codes (e.g. +358) match before shorter (+35) */
                $sorted = $list;
                usort(
                    $sorted,
                    static function ($a, $b) {
                        return strlen($b) - strlen($a);
                    }
                );
                foreach ($sorted as $entry) {
                    $code = ltrim((string)$entry, '+');
                    // An empty or non-numeric entry is a prefix of every number (or of none): skip it.
                    if ($code === '' || !ctype_digit($code)) {
                        continue;
                    }
                    if (str_starts_with($digits, $code)) {
                        $in = true;
                        break;
                    }
                }
                if ($cmode === 'allow' && !$in) {
                    return __('This phone number is not allowed for your country.', 'formfabricator');
                }
                if ($cmode === 'disallow' && $in) {
                    return __('This phone number is not allowed for your country.', 'formfabricator');
                }
                break;
        }

        return true;
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
            'phone_mode'         => '',
            'phone_country_mode' => 'allow',
            'phone_country_list' => [],
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
        return $this->baseGeneralEntries();
    }
}
