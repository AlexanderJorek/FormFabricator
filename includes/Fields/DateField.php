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
 * @version   1.0.9
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
     * Supported date formats: key => pattern capturing three parts, and the order of those parts (d/m/y).
     * Mirrored in DateField.js and DateField.date-format.js.
     *
     * @var array<string, array{re: string, order: string}>
     */
    private const FORMATS = [
        'dmy' => ['re' => '/^(\d{2})\.(\d{2})\.(\d{4})$/', 'order' => 'dmy'],
        'mdy' => ['re' => '#^(\d{2})/(\d{2})/(\d{4})$#', 'order' => 'mdy'],
        'ymd' => ['re' => '/^(\d{4})-(\d{2})-(\d{2})$/', 'order' => 'ymd'],
    ];

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
        return [['rule' => 'date-format', 'fn' => self::readFieldAsset('assets/js/fields/DateField.date-format.js')]];
    }

    /**
     * The field's format key; DD.MM.YYYY for a configuration without one.
     *
     * @param array $config Field configuration.
     * @return string One of the FORMATS keys.
     */
    private static function formatKey(array $config): string
    {
        $key = (string) ($config['date_format'] ?? 'dmy');
        return isset(self::FORMATS[$key]) ? $key : 'dmy';
    }

    /**
     * Translated, human-readable pattern for a format key ("TT.MM.JJJJ" in German).
     *
     * @param string $key One of the FORMATS keys.
     * @return string
     */
    private static function formatLabel(string $key): string
    {
        return match ($key) {
            'mdy'   => __('MM/DD/YYYY', 'formfabricator'),
            'ymd'   => __('YYYY-MM-DD', 'formfabricator'),
            default => __('DD.MM.YYYY', 'formfabricator'),
        };
    }

    /**
     * Default format for new fields, read from the site's own date format (Settings > General): whichever of day,
     * month and year appears first decides the order, so "j. F Y" gives DD.MM.YYYY and "F j, Y" gives MM/DD/YYYY.
     *
     * @return string One of the FORMATS keys.
     */
    private static function siteDefaultFormat(): string
    {
        // Backslash-escaped characters are literals in a PHP date format, not placeholders.
        $pattern = (string) preg_replace('/\\\\./', '', (string) get_option('date_format', 'd.m.Y'));
        $day     = strcspn($pattern, 'dj');
        $month   = strcspn($pattern, 'mnFM');
        $year    = strcspn($pattern, 'Yy');
        if ($year < $month && $year < $day) {
            return 'ymd';
        }
        return $month < $day ? 'mdy' : 'dmy';
    }

    /**
     * Parses a date written in the given format.
     *
     * @param string $value Trimmed input.
     * @param string $key   One of the FORMATS keys.
     * @return array{0: int, 1: int, 2: int}|null [year, month, day] when well-formed, else null.
     */
    private static function parseDate(string $value, string $key): ?array
    {
        $format = self::FORMATS[$key];
        if (!preg_match($format['re'], $value, $m)) {
            return null;
        }
        $parts = array_combine(str_split($format['order']), [(int) $m[1], (int) $m[2], (int) $m[3]]);
        return [$parts['y'], $parts['m'], $parts['d']];
    }

    /**
     * Parses a stored min/max date, in the field's own format or in any of the others.
     *
     * Bounds keep the format they were entered in, so a format change must not drop them.
     *
     * @param string $value Trimmed bound, as stored.
     * @param string $key   The field's current format, tried first.
     * @return array{0: int, 1: int, 2: int}|null [year, month, day] when well-formed, else null.
     */
    private static function parseBound(string $value, string $key): ?array
    {
        foreach (array_merge([$key], array_keys(self::FORMATS)) as $candidate) {
            $parts = self::parseDate($value, $candidate);
            if ($parts !== null) {
                return $parts;
            }
        }
        return null;
    }

    /**
     * Writes a parsed date back in the given format, so a message names the bound the way the field reads dates.
     *
     * @param array{0: int, 1: int, 2: int} $ymd [year, month, day].
     * @param string                        $key One of the FORMATS keys.
     * @return string
     */
    private static function formatDate(array $ymd, string $key): string
    {
        [$y, $m, $d] = $ymd;
        return match ($key) {
            'mdy'   => sprintf('%02d/%02d/%04d', $m, $d, $y),
            'ymd'   => sprintf('%04d-%02d-%02d', $y, $m, $d),
            default => sprintf('%02d.%02d.%04d', $d, $m, $y),
        };
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
        $key      = self::formatKey($config);
        $label    = self::formatLabel($key);

        $inner = '<div class="fabricator-date-wrap">'
            . '<input type="text" id="' . esc_attr($field_id) . '"'
            . ' name="' . esc_attr($field_id) . '"'
            . ' class="fabricator-input fabricator-date-text"'
            . ' placeholder="' . esc_attr($label) . '"'
            . ' data-date-format="' . esc_attr($key) . '"'
            . ' data-date-label="' . esc_attr($label) . '"'
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
                return sprintf(__('%s: Required field.', 'formfabricator'), $label);
            }
            return true;
        }
        $key   = self::formatKey($config);
        $parts = self::parseDate(trim((string)$value), $key);
        if ($parts === null) {
            // translators: %s: expected date pattern, e.g. DD.MM.YYYY.
            return sprintf(__('Please enter a date in %s format.', 'formfabricator'), self::formatLabel($key));
        }
        [$y, $m, $d] = $parts;
        if (!checkdate($m, $d, $y)) {
            return __('Please enter a valid date.', 'formfabricator');
        }
        // Compare as YYYYMMDD strings so chronological order matches string order
        $ymd = sprintf('%04d%02d%02d', $y, $m, $d);

        // The bounds are written in whatever format the field had when they were entered; parseBound() reads them all.
        $minDate = trim((string)($config['min_date'] ?? ''));
        $min     = $minDate !== '' ? self::parseBound($minDate, $key) : null;
        if ($min !== null && $ymd < sprintf('%04d%02d%02d', $min[0], $min[1], $min[2])) {
            // translators: %s: minimum allowed date.
            return sprintf(__('Please enter a date on or after %s.', 'formfabricator'), self::formatDate($min, $key));
        }

        $maxDate = trim((string)($config['max_date'] ?? ''));
        $max     = $maxDate !== '' ? self::parseBound($maxDate, $key) : null;
        if ($max !== null && $ymd > sprintf('%04d%02d%02d', $max[0], $max[1], $max[2])) {
            // translators: %s: maximum allowed date.
            return sprintf(__('Please enter a date on or before %s.', 'formfabricator'), self::formatDate($max, $key));
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
            'date_format'   => self::siteDefaultFormat(),
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
                'key'     => 'date_format',
                'type'    => 'select',
                'label'   => __('Date format', 'formfabricator'),
                // What formatKey() reads for a configuration without a format.
                'default' => 'dmy',
                'options' => [
                    ['value' => 'dmy', 'label' => self::formatLabel('dmy')],
                    ['value' => 'mdy', 'label' => self::formatLabel('mdy')],
                    ['value' => 'ymd', 'label' => self::formatLabel('ymd')],
                ],
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
                'label' => __('Earliest allowed date (in the date format above)', 'formfabricator'),
            ],
            [
                'key'   => 'max_date',
                'type'  => 'text',
                'label' => __('Latest allowed date (in the date format above)', 'formfabricator'),
            ],
        ];
    }
}
