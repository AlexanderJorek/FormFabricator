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
 * @version   1.1.1
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
     * Expanded: the enabled sub-fields' values in render order (BaseField::joinedSubValues()), as front.js reads them.
     * Otherwise the single input's text; an array there is a crafted POST, which the browser never sends.
     *
     * @param mixed $raw    What extractValue() returned.
     * @param array $config Field configuration.
     * @return mixed
     */
    public function conditionValue(mixed $raw, array $config): mixed
    {
        if (empty($config['expanded'])) {
            return is_array($raw) ? '' : $raw;
        }
        $keys = [];
        foreach (self::SUBFIELDS as $sf) {
            if (!empty($config[$sf['key'] . '_enabled'])) {
                $keys[] = $sf['key'];
            }
        }
        return self::joinedSubValues($raw, $keys);
    }

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
        ['key' => 'street',  'label' => 'Street and house number'],
        ['key' => 'street2', 'label' => 'Address supplement'],
        ['key' => 'city',    'label' => 'City'],
        ['key' => 'state',   'label' => 'State / Canton'],
        ['key' => 'zip',     'label' => 'Postal code'],
        ['key' => 'country', 'label' => 'Country'],
    ];

    /**
     * Sub-field labels render via esc_html(), never raw HTML — avoids wp_kses_post()+esc_html() double-encoding "&".
     *
     * @return string[]
     */
    protected function plainTextConfigKeys(): array
    {
        return array_merge(
            parent::plainTextConfigKeys(),
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
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
     * True only in the simple form, which renders one text input carrying the field id. With the sub-fields switched
     * on there are several inputs instead, each with its own label, so the question text names the set (BaseField::wrap()).
     *
     * @param array $config Field configuration.
     * @return bool
     */
    public function labelsOwnControl(array $config): bool
    {
        return empty($config['expanded']);
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
            $attrs = $this->inputAttrs($config, $field_id, 'text', ['value' => (string)(is_array($value) ? '' : ($value ?? ''))]);
            return $this->wrap($field_id, $config, '<input' . $attrs . '>');
        }

        $val        = is_array($value) ? $value : [];
        $inner_html = '<div class="fabricator-address-group">';

        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if (empty($config[$k . '_enabled'])) {
                continue;
            }
            $label    = (string) ($config[$k . '_label'] ?? self::subfieldLabel($sf['label']));
            $required = !empty($config[$k . '_required']);

            // Every sub-input carries its own id and its label a matching for="", or assistive technology announces
            // six unlabelled text boxes. FormRenderer::uniqueIds() rewrites both together on a repeated form.
            $sub_id      = $field_id . '-' . $k;
            $inner_html .= '<div class="fabricator-address-sub">';
            $inner_html .= '<label class="fabricator-sub-label" for="' . esc_attr($sub_id) . '">' . esc_html($label)
                . ($required ? ' <span class="fabricator-required" aria-hidden="true">*</span>' : '') . '</label>';
            $inner_html .= '<input type="text" id="' . esc_attr($sub_id) . '"'
                . ' name="' . esc_attr($field_id) . '[' . esc_attr($k) . ']"'
                . ' class="fabricator-input" placeholder="' . esc_attr((string) ($config[$k . '_placeholder'] ?? '')) . '"'
                . ' value="' . esc_attr((string)($val[$k] ?? '')) . '"'
                . ' autocomplete="' . esc_attr($this->autocompleteToken($k)) . '"' . ($required ? ' required aria-required="true"' : '') . '>';
            $inner_html .= '<div class="fabricator-field-error fabricator-sub-error"></div>';
            $inner_html .= '</div>';
        }

        $inner_html .= '</div>';
        /* In expanded mode each sub-input carries its own required — the wrapper
         * should not show a global * or fabricator-required-field class. */
        $wrapper_config             = $config;
        $wrapper_config['required'] = false;
        return $this->wrap($field_id, $wrapper_config, $inner_html);
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
     * @param array  $post     The posted answers (BaseField::extractValue()).
     * @param array  $files    The uploaded files' entries (BaseField::extractValue()).
     */
    public function extractValue(string $field_id, array $post, array $files = []): mixed
    {
        $raw = isset($post[$field_id]) ? self::answers(self::capRawArray($post[$field_id])) : '';
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
            if (empty($config[$k . '_enabled'])) {
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
            // Expanded mode posts sub-fields only. A plain string there is a direct POST that validate() read as empty
            // sub-fields, past every per-sub-field cap: it is no address.
            if (!empty($config['expanded'])) {
                return __('[No entry]', 'formfabricator');
            }
            // Simple mode posts one text input, so the address arrives as a plain string. Returning "[No entry]" for
            // everything non-array dropped it from the email, the PDF and the seal, and nothing here is stored.
            $scalar = trim((string) ($value ?? ''));
            return $scalar !== '' ? $scalar : __('[No entry]', 'formfabricator');
        }
        // Lines as an address is written: street and supplement, postcode and city, state, country.
        $lines = [];
        $line  = [];
        foreach (self::shownParts($value, $config) as $i => $part) {
            if ($i > 0 && $part['sep'] === ', ' && $line) {
                $lines[] = implode(' ', $line);
                $line    = [];
            }
            if ($part['value'] !== '') {
                $line[] = $part['value'];
            }
        }
        if ($line) {
            $lines[] = implode(' ', $line);
        }
        return $lines ? implode(', ', $lines) : __('[No entry]', 'formfabricator');
    }

    /**
     * The entry map() makes, with each sub-value as a part for a mail with automation markers
     * (BaseField::withMarkerParts()).
     *
     * @param string $field_id Field identifier.
     * @param string $label    Field label.
     * @param mixed  $value    Submitted value.
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
        return self::withMarkerParts(
            parent::mapNormalized($field_id, $label, $value, $config, $context),
            $field_id,
            self::shownParts($value, $config)
        );
    }

    /**
     * The enabled sub-fields in map()'s order, each with its separator (", " where a new address line starts). What
     * map() joins and a marked mail marks one by one; none for a single input.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return array<int, array{key: string, value: string, sep: string}>
     */
    private static function shownParts(mixed $value, array $config): array
    {
        if (empty($config['expanded']) || !is_array($value)) {
            return [];
        }
        $parts = [];
        foreach ([['street', 'street2'], ['zip', 'city'], ['state'], ['country']] as $group) {
            $first = true;
            foreach ($group as $k) {
                if (empty($config[$k . '_enabled'])) {
                    continue;
                }
                $v       = is_scalar($value[$k] ?? null) ? trim((string) $value[$k]) : '';
                $parts[] = ['key' => $k, 'value' => $v, 'sep' => $first ? ', ' : ' '];
                $first   = false;
            }
        }
        return $parts;
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
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
                'items'      => array_map(
                    static fn(array $sf): array => [...$sf, 'label' => self::subfieldLabel($sf['label'])],
                    self::SUBFIELDS
                ),
            ],
        ];
    }
}
