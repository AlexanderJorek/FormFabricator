<?php

/**
 * Date picker field.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.5
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
 * Date picker input field.
 */
class DateField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/DateField.css');
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'date';
    }

    public function getLabel(): string
    {
        return __('Date', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-calendar-days';
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/DateField.js');
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'date-format', 'fn' => 'function (fieldEl) {'
            . "\n" .
            '    var inp = fieldEl.querySelector(\'.fabricator-date-text\');'
            . "\n" .
            '    if (!inp || !inp.value.trim()) return null;'
            . "\n" .
            '    var v = inp.value.trim();'
            . "\n" .
            '    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;'
            . "\n" .
            '    if (!/^\\d{2}\\.\\d{2}\\.\\d{4}$/.test(v))'
            . "\n" .
            '        return (_i18n && _i18n.date_invalid_format) || \'Please enter a date in DD.MM.YYYY format.\';'
            . "\n" .
            '    var p  = v.split(\'.\');'
            . "\n" .
            '    var d  = parseInt(p[0], 10);'
            . "\n" .
            '    var m  = parseInt(p[1], 10);'
            . "\n" .
            '    var y  = parseInt(p[2], 10);'
            . "\n" .
            '    var dt = new Date(y, m - 1, d);'
            . "\n" .
            '    if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d)'
            . "\n" .
            '        return (_i18n && _i18n.date_invalid_date) || \'Please enter a valid date.\';'
            . "\n" .
            '    return null;'
            . "\n" .
            '}']];
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
        $req      = !empty($config['required']) ? ' required aria-required="true"' : '';
        $prefill  = !empty($config['prefill_today']) ? ' data-prefill-today="true"' : '';
        $picker   = !empty($config['show_picker']);

        $inner = '<div class="fabricator-date-wrap">'
            . '<input type="text" id="' . esc_attr($field_id) . '"'
            . ' name="' . esc_attr($field_id) . '"'
            . ' class="fabricator-input fabricator-date-text"'
            . ' placeholder="' . esc_attr__('DD.MM.YYYY', 'formfabricator') . '"'
            . ' maxlength="10"'
            // Not always a birthdate; "bday" would wrongly signal browsers to autofill one here.
            . ' autocomplete="off"'
            . ' value="' . esc_attr((string)($value ?? '')) . '"'
            . $prefill . $req . '>';

        if ($picker) {
            $inner .= '<button type="button" class="fabricator-date-cal-btn" data-for="' . esc_attr($field_id) . '"'
                . ' aria-label="' . esc_attr__('Open calendar', 'formfabricator') . '" title="' . esc_attr__('Open calendar', 'formfabricator') . '">'
                . '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"'
                . ' viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
                . ' aria-hidden="true">'
                . '<rect x="3" y="4" width="18" height="18" rx="2"/>'
                . '<line x1="16" y1="2" x2="16" y2="6"/>'
                . '<line x1="8" y1="2" x2="8" y2="6"/>'
                . '<line x1="3" y1="10" x2="21" y2="10"/>'
                . '</svg>'
                . '</button>'
                . '<input type="date" class="fabricator-date-picker-hidden" aria-hidden="true" tabindex="-1"'
                . ' data-for="' . esc_attr($field_id) . '">';
        }

        $inner .= '</div>';
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
        if (empty($value) || trim((string)$value) === '') {
            if (!empty($config['required'])) {
                $label = $config['label'] ?? __('Date', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s: Required field.', 'formfabricator'), esc_html($label));
            }
            return true;
        }
        $v = trim((string)$value);
        if (!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $v)) {
            return __('Please enter a date in DD.MM.YYYY format.', 'formfabricator');
        }
        [$d, $m, $y] = explode('.', $v);
        if (!checkdate((int)$m, (int)$d, (int)$y)) {
            return __('Please enter a valid date.', 'formfabricator');
        }
        // Compare as YYYYMMDD strings so chronological order matches string order
        $ymd = sprintf('%04d%02d%02d', (int)$y, (int)$m, (int)$d);

        $minDate = trim((string)($config['min_date'] ?? ''));
        if ($minDate !== '' && preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $minDate)) {
            [$minD, $minM, $minY] = explode('.', $minDate);
            $minYmd = sprintf('%04d%02d%02d', (int)$minY, (int)$minM, (int)$minD);
            if ($ymd < $minYmd) {
                // translators: %s: minimum allowed date.
                return sprintf(__('Please enter a date on or after %s.', 'formfabricator'), $minDate);
            }
        }

        $maxDate = trim((string)($config['max_date'] ?? ''));
        if ($maxDate !== '' && preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $maxDate)) {
            [$maxD, $maxM, $maxY] = explode('.', $maxDate);
            $maxYmd = sprintf('%04d%02d%02d', (int)$maxY, (int)$maxM, (int)$maxD);
            if ($ymd > $maxYmd) {
                // translators: %s: maximum allowed date.
                return sprintf(__('Please enter a date on or before %s.', 'formfabricator'), $maxDate);
            }
        }

        return true;
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
        if (empty($value)) {
            return __('[No entry]', 'formfabricator');
        }
        return (string)$value;
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
            'show_picker'   => true,
            'prefill_today' => false,
            'min_date'      => '',
            'max_date'      => '',
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
                'key'   => 'show_picker',
                'type'  => 'checkbox',
                'label' => __('Show calendar icon', 'formfabricator'),
            ],
            [
                'key'   => 'prefill_today',
                'type'  => 'checkbox',
                'label' => __('Pre-fill with today', 'formfabricator'),
            ],
            [
                'key'   => 'min_date',
                'type'  => 'text',
                'label' => __('Earliest allowed date (DD.MM.YYYY)', 'formfabricator'),
            ],
            [
                'key'   => 'max_date',
                'type'  => 'text',
                'label' => __('Latest allowed date (DD.MM.YYYY)', 'formfabricator'),
            ],
        ];
    }
}
