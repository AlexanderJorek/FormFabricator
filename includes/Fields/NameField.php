<?php

/**
 * Full name input field (first and last name).
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.4
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
 * Name input field.
 */
class NameField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'name';
    }

    public function getLabel(): string
    {
        return __('Name', 'formfabricator');
    }

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.fabricator-name-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-start;
}
.fabricator-name-sub {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 130px;
}
.fabricator-name-sub--prefix {
    flex: 0 0 auto;
    min-width: 0;
    width: 110px;
}
.fabricator-name-sub--prefix .fabricator-input { width: 100%; }
@media (max-width: 600px) {
    .fabricator-name-group { flex-direction: column; }
}
CSS;
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-user';
    }

    private const SUBFIELDS = [
        ['key' => 'prefix', 'optional' => true, 'label' => 'Salutation',  'is_select' => true],
        ['key' => 'fname',  'optional' => true, 'label' => 'First name'],
        ['key' => 'mname',  'optional' => true, 'label' => 'Middle name'],
        ['key' => 'lname',  'optional' => true, 'label' => 'Last name'],
    ];

    /**
     * Translated default label for a sub-field, keyed by its literal SUBFIELDS label.
     *
     * @param string $default_label One of the literal 'label' values from SUBFIELDS.
     * @return string Translated label.
     */
    private static function subfieldLabel(string $default_label): string
    {
        return match ($default_label) {
            'Salutation'  => __('Salutation', 'formfabricator'),
            'First name'  => __('First name', 'formfabricator'),
            'Middle name' => __('Middle name', 'formfabricator'),
            'Last name'   => __('Last name', 'formfabricator'),
            default      => $default_label,
        };
    }

    /**
     * Returns the fixed salutation/prefix option list. Rendered as <select> options in render() and used by
     * validate() as the server-side allowlist for the submitted prefix sub-value — can't be a class const
     * because it contains __() translation calls, which aren't compile-time constant expressions.
     *
     * @return array<int, string>
     */
    private static function prefixOptions(): array
    {
        return ['', __('Mr.', 'formfabricator'), __('Ms.', 'formfabricator'), __('Diverse', 'formfabricator'), 'Dr.', 'Prof.', 'Dipl.', 'Ing.'];
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
            $safe_value = is_array($value) ? '' : ($value ?? '');
            $attrs = $this->inputAttrs($config, $field_id, 'text', ['value' => esc_attr((string)$safe_value)]);
            return $this->wrap($field_id, $config, '<input' . $attrs . '>');
        }

        $val   = is_array($value) ? $value : [];
        $inner = '<div class="fabricator-name-group">';

        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if (empty($config[$k . '_enabled'])) {
                continue;
            }
            $label_raw = $config[$k . '_label'] ?? self::subfieldLabel($sf['label']);
            $label = esc_html($label_raw);
            $ph    = esc_attr($config[$k . '_placeholder'] ?? '');
            $req   = !empty($config[$k . '_required']) ? ' required aria-required="true"' : '';
            $ac    = esc_attr($this->autocompleteToken($k));

            $req_star = !empty($config[$k . '_required']) ? ' <span class="fabricator-required" aria-hidden="true">*</span>' : '';
            $sub_class = !empty($sf['is_select']) ? ' fabricator-name-sub--prefix' : '';
            $inner .= '<div class="fabricator-name-sub' . $sub_class . '">'
                . '<label class="fabricator-sub-label">' . $label . $req_star . '</label>';
            if (!empty($sf['is_select'])) {
                $cur    = esc_attr((string)($val[$k] ?? ''));
                $inner .= '<select name="' . esc_attr($field_id) . '[' . $k . ']"'
                    . ' class="fabricator-input fabricator-name-prefix" aria-label="' . esc_attr($label_raw) . '"'
                    . ' autocomplete="' . $ac . '"' . $req . '>';
                foreach (self::prefixOptions() as $opt) {
                    $inner .= '<option value="' . esc_attr($opt) . '"' . selected($cur, $opt, false) . '>'
                        . ($opt === '' ? '—' : esc_html($opt)) . '</option>';
                }
                $inner .= '</select>';
            } else {
                $inner .= '<input type="text" name="' . esc_attr($field_id) . '[' . $k . ']"'
                    . ' class="fabricator-input" placeholder="' . $ph . '"'
                    . ' value="' . esc_attr((string)($val[$k] ?? '')) . '"'
                    . ' autocomplete="' . $ac . '"' . $req . '>';
            }
            $inner .= '<div class="fabricator-field-error fabricator-sub-error"></div>';
            $inner .= '</div>';
        }

        $inner .= '</div>';
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
            'fname'  => 'given-name',
            'mname'  => 'additional-name',
            'lname'  => 'family-name',
            'prefix' => 'honorific-prefix',
            default  => 'name',
        };
    }

    /**
     * Returns the sanitized composite subfield array submitted as $field_id[key].
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified once in FormProcessor::handle() before field extraction runs; value is unslashed and sanitize_text_field()'d via map_deep()/capRawArray(), WPCS doesn't recognize sanitization via the string-callback form.
        $raw = isset($_POST[$field_id]) ? map_deep(self::capRawArray(wp_unslash($_POST[$field_id])), 'sanitize_text_field') : '';
        if (is_array($raw)) {
            return $raw;
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
                $label = $config['label'] ?? __('Name', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s: Required field.', 'formfabricator'), esc_html($label));
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
            if (!empty($sf['is_select'])) {
                // select always has a value; "—" is a valid no-preference answer,
                // so required is intentionally not enforced here — but a direct
                // POST can still submit an arbitrary string outside the <select>'s
                // option list, so allowlist whatever was submitted.
                $submitted = trim((string)(is_array($value) ? ($value[$k] ?? '') : ''));
                if ($submitted !== '' && !in_array($submitted, self::prefixOptions(), true)) {
                    $errors[] = $config[$k . '_label'] ?? self::subfieldLabel($sf['label']);
                }
                continue;
            }
            $sub = trim((string)(is_array($value) ? ($value[$k] ?? '') : ''));
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
     * @return string Formatted name string.
     */
    public function map(mixed $value, array $config): string
    {
        if (empty($config['expanded'])) {
            return trim((string)($value ?? '')) ?: __('[No entry]', 'formfabricator');
        }
        if (!is_array($value)) {
            return __('[No entry]', 'formfabricator');
        }
        $parts = [];
        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if (empty($config[$k . '_enabled'])) {
                continue;
            }
            $v = trim((string)($value[$k] ?? ''));
            if ($v !== '') {
                $parts[] = $v;
            }
        }
        return $parts ? implode(' ', $parts) : __('[No entry]', 'formfabricator');
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
            'expanded'           => false,
            'prefix_enabled'     => false,
            'prefix_label'       => __('Salutation', 'formfabricator'),
            'prefix_placeholder' => '',
            'prefix_required'    => false,
            'fname_enabled'      => true,
            'fname_label'        => __('First name', 'formfabricator'),
            'fname_placeholder'  => '',
            'fname_required'     => true,
            'mname_enabled'      => false,
            'mname_label'        => __('Middle name', 'formfabricator'),
            'mname_placeholder'  => '',
            'mname_required'     => false,
            'lname_enabled'      => true,
            'lname_label'        => __('Last name', 'formfabricator'),
            'lname_placeholder'  => '',
            'lname_required'     => true,
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
