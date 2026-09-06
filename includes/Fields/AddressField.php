<?php

/**
 * Address composite field (street, city, postcode, country).
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
 * Address input field with street, city, postal code, and country subfields.
 */
class AddressField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'address';
    }

    public function getLabel(): string
    {
        return __('Address', 'formfabricator');
    }

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/AddressField.css');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-location-dot';
    }

    private const SUBFIELDS = [
        ['key' => 'street',  'optional' => true, 'label' => 'Street and house number'],
        ['key' => 'street2', 'optional' => true, 'label' => 'Address supplement'],
        ['key' => 'city',    'optional' => true, 'label' => 'City'],
        ['key' => 'state',   'optional' => true, 'label' => 'State / Canton'],
        ['key' => 'zip',     'optional' => true, 'label' => 'Postal code'],
        ['key' => 'country', 'optional' => true, 'label' => 'Country'],
    ];

    /**
     * Each sub-field's "{key}_label" config value is rendered via esc_html(), never as raw
     * HTML — without this, wp_kses_post()'s entity-encoding at save time plus esc_html() at
     * render time double-encodes any "&" in an admin-typed sub-label.
     *
     * @return string[]
     */
    protected function plainTextConfigKeys(): array
    {
        return array_merge(
            parent::plainTextConfigKeys(),
            array_map(static fn($sf) => $sf['key'] . '_label', self::SUBFIELDS)
        );
    }

    /**
     * Translated default label for a sub-field, keyed by its literal SUBFIELDS label.
     *
     * @param string $default_label One of the literal 'label' values from SUBFIELDS.
     * @return string Translated label.
     */
    private static function subfieldLabel(string $default_label): string
    {
        return match ($default_label) {
            'Street and house number' => __('Street and house number', 'formfabricator'),
            'Address supplement'      => __('Address supplement', 'formfabricator'),
            'City'                    => __('City', 'formfabricator'),
            'State / Canton'          => __('State / Canton', 'formfabricator'),
            'Postal code'             => __('Postal code', 'formfabricator'),
            'Country'                 => __('Country', 'formfabricator'),
            default                  => $default_label,
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
        if (empty($config['expanded'])) {
            $attrs = $this->inputAttrs($config, $field_id, 'text', ['value' => esc_attr((string)(is_array($value) ? '' : ($value ?? '')))]);
            return $this->wrap($field_id, $config, '<input' . $attrs . '>');
        }

        $val   = is_array($value) ? $value : [];
        $inner = '<div class="fabricator-address-group">';

        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if ($sf['optional'] && empty($config[$k . '_enabled'])) {
                continue;
            }
            $label = esc_html($config[$k . '_label'] ?? self::subfieldLabel($sf['label']));
            $ph    = esc_attr($config[$k . '_placeholder'] ?? '');
            $req   = !empty($config[$k . '_required']) ? ' required aria-required="true"' : '';
            $ac    = esc_attr($this->autocompleteToken($k));

            $req_star = !empty($config[$k . '_required']) ? ' <span class="fabricator-required" aria-hidden="true">*</span>' : '';
            $inner .= '<div class="fabricator-address-sub">';
            $inner .= '<label class="fabricator-sub-label">' . $label . $req_star . '</label>';
            $inner .= '<input type="text"'
                . ' name="' . esc_attr($field_id) . '[' . esc_attr($k) . ']"'
                . ' class="fabricator-input" placeholder="' . $ph . '"'
                . ' value="' . esc_attr((string)($val[$k] ?? '')) . '"'
                . ' autocomplete="' . $ac . '"' . $req . '>';
            $inner .= '<div class="fabricator-field-error fabricator-sub-error"></div>';
            $inner .= '</div>';
        }

        $inner .= '</div>';
        /* In expanded mode each sub-input carries its own required — the wrapper
         * should not show a global * or fabricator-required-field class. */
        $wrapper_config             = $config;
        $wrapper_config['required'] = false;
        return $this->wrap($field_id, $wrapper_config, $inner);
    }

    /**
     * Returns the HTML autocomplete token for a given subfield key.
     *
     * @param string $key Subfield key.
     * @return string HTML autocomplete attribute value.
     */
    private function autocompleteToken(string $key): string
    {
        return match ($key) {
            'street'  => 'address-line1',
            'street2' => 'address-line2',
            'city'    => 'address-level2',
            'state'   => 'address-level1',
            'zip'     => 'postal-code',
            'country' => 'country-name',
            default   => 'on',
        };
    }

    /**
     * Returns the sanitized composite subfield array submitted as $field_id[key].
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above via assertRequestNonceVerified(); value is unslashed and sanitize_text_field()'d via map_deep()/capRawArray(), WPCS doesn't recognize sanitization via the string-callback form.
        $raw = isset($_POST[$field_id]) ? map_deep(self::capRawArray(wp_unslash($_POST[$field_id])), 'sanitize_text_field') : '';
        if (is_array($raw)) {
            // Flatten nested leaves: a direct POST can send name[first][0]=x, which
            // map_deep() preserves and map() would stringify to the literal "Array".
            return self::scalarSubfieldMap($raw);
        }
        return (string) $raw;
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
        if (empty($config['expanded'])) {
            $scalar = is_array($value) ? '' : trim((string)($value ?? ''));
            if (!empty($config['required']) && $scalar === '') {
                $label = $config['label'] ?? __('Address', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s: Required field.', 'formfabricator'), $label);
            }
            if ($scalar !== '') {
                $hard = self::validateTextHardCap($scalar);
                if ($hard !== true) {
                    return $hard;
                }
            }
            return true;
        }
        $errors = [];
        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if ($sf['optional'] && empty($config[$k . '_enabled'])) {
                continue;
            }
            $sub = trim((string)($value[$k] ?? ''));
            if (!empty($config[$k . '_required']) && $sub === '') {
                $errors[] = $config[$k . '_label'] ?? self::subfieldLabel($sf['label']);
                continue;
            }
            // Server-side hard-cap backstop — a direct POST can submit an
            // unbounded-length sub-value; there is no client-side maxlength here.
            if ($sub !== '') {
                $hard = self::validateTextHardCap($sub);
                if ($hard !== true) {
                    return $hard;
                }
            }
        }
        return $errors
            // translators: %s: comma-separated list of missing sub-field labels.
            ? sprintf(__('%s: Required field.', 'formfabricator'), implode(', ', $errors))
            : true;
    }

    /**
     * Maps the field value to a flat string for display in submissions.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return string Formatted address string.
     */
    public function map(mixed $value, array $config): string
    {
        if (!is_array($value)) {
            return __('[No entry]', 'formfabricator');
        }
        $sfMap = [];
        foreach (self::SUBFIELDS as $sf) {
            $sfMap[$sf['key']] = $sf;
        }
        $lines = [];
        foreach ([['street', 'street2'], ['zip', 'city'], ['state'], ['country']] as $group) {
            $parts = [];
            foreach ($group as $k) {
                if (isset($sfMap[$k]) && $sfMap[$k]['optional'] && empty($config[$k . '_enabled'])) {
                    continue;
                }
                $v = trim((string)($value[$k] ?? ''));
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            if ($parts) {
                $lines[] = implode(' ', $parts);
            }
        }
        return $lines ? implode(', ', $lines) : __('[No entry]', 'formfabricator');
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
            'expanded'            => false,
            'street_enabled'      => true,
            'street_label'        => __('Street and house number', 'formfabricator'),
            'street_placeholder'  => '',
            'street_required'     => true,
            'street2_enabled'     => true,
            'street2_label'       => __('Address supplement', 'formfabricator'),
            'street2_placeholder' => __('Apartment, floor, c/o ...', 'formfabricator'),
            'street2_required'    => false,
            'city_enabled'        => true,
            'city_label'          => __('City', 'formfabricator'),
            'city_placeholder'    => '',
            'city_required'       => true,
            'state_enabled'       => false,
            'state_label'         => __('State / Canton', 'formfabricator'),
            'state_placeholder'   => '',
            'state_required'      => false,
            'zip_enabled'         => true,
            'zip_label'           => __('Postal code', 'formfabricator'),
            'zip_placeholder'     => '',
            'zip_required'        => true,
            'country_enabled'     => false,
            'country_label'       => __('Country', 'formfabricator'),
            'country_placeholder' => '',
            'country_required'    => false,
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
                'key'         => 'expanded',
                'type'        => 'bool_seg',
                'label'       => __('Mode', 'formfabricator'),
                'false_label' => __('Simple', 'formfabricator'),
                'true_label'  => __('Extended', 'formfabricator'),
                'rebuild'     => true,
            ],
            [
                'key'        => 'placeholder',
                'type'       => 'text',
                'label'      => __('Placeholder', 'formfabricator'),
                'depends_on' => ['expanded' => false],
            ],
            [
                'key'        => 'description',
                'type'       => 'text',
                'label'      => __('Hint text', 'formfabricator'),
                'depends_on' => ['expanded' => false],
            ],
            [
                'key'        => 'subfields',
                'type'       => 'subfields',
                'label'      => __('Sub-fields', 'formfabricator'),
                'depends_on' => ['expanded' => true],
                // Translate labels for the builder UI (SUBFIELDS itself can't call __()).
                'items'      => array_map(
                    static fn(array $sf): array => [...$sf, 'label' => self::subfieldLabel($sf['label'])],
                    self::SUBFIELDS
                ),
            ],
        ];
    }
}
