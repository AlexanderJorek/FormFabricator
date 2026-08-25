<?php

/**
 * Multi-value checkbox (checkboxes) field.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.3
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

/**
 * Multi-value checkbox group field.
 */
class CheckboxField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        // phpcs:disable Generic.Files.LineLength -- the checkbox-checkmark background-image is an
        // inline SVG data URI; splitting it across lines risks corrupting the encoded markup.
        return <<<'CSS'
.forge-checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.forge-checkbox-group--horizontal {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 16px;
}
.forge-checkbox-label {
    display: flex;
    align-items: center;
    gap: 9px;
    cursor: pointer;
    font-size: 14px;
    color: var(--forge-text);
    line-height: 1.4;
    user-select: none;
}
.forge-checkbox-label input[type="checkbox"] {
    appearance: none;
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    border: 2px solid var(--forge-border-input);
    border-radius: var(--forge-radius-sm);
    background: var(--forge-bg);
    cursor: pointer;
    transition: border-color .15s, background .15s;
    position: relative;
}
.forge-checkbox-label input[type="checkbox"]:checked {
    border-color: var(--forge-accent);
    background-color: var(--forge-accent);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 10'%3E%3Cpolyline points='1,5 4.5,8.5 11,1' stroke='%23ffffff' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 11px 9px;
}
.forge-checkbox-label input[type="checkbox"]:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 25%, transparent);
}
.forge-checkbox-label:hover input[type="checkbox"]:not(:checked) {
    border-color: var(--forge-accent);
}
CSS;
        // phpcs:enable Generic.Files.LineLength
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'checkbox';
    }

    public function getLabel(): string
    {
        return __('Checkboxes', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-square-check';
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'checkbox-count', 'fn' => <<<'JS'
            function (fieldEl) {
                var group = fieldEl.querySelector('.forge-checkbox-group');
                if (!group) return null;
                var min = parseInt(group.dataset.minSelections || '0', 10);
                var max = parseInt(group.dataset.maxSelections || '0', 10);
                if (!min && !max) return null;
                var cnt = fieldEl.querySelectorAll('input[type="checkbox"]:checked').length;
                var _i18n = window.ForgeForms && window.ForgeForms.i18n;
                if (min > 0 && cnt < min) return (_i18n && _i18n.checkbox_min ? _i18n.checkbox_min.replace('%d', min) : 'Please select at least ' + min + ' option(s).');
                if (max > 0 && cnt > max) return (_i18n && _i18n.checkbox_max ? _i18n.checkbox_max.replace('%d', max) : 'Please select at most ' + max + ' option(s).');
                return null;
            }
            JS], self::otherTextClientRule()];
    }

    /**
     * Returns the client-side empty-check function for required validation.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return ['fn' => "function(f){return !f.querySelector('input[type=\"checkbox\"]:checked');}"];
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
        $options  = $config['options'] ?? [];

        /* When no submitted value, pre-check options marked as default */
        if ($value === null) {
            $defaults = [];
            foreach ($options as $opt) {
                if (is_array($opt) && !empty($opt['default'])) {
                    $defaults[] = $opt['value'] ?? '';
                }
            }
            $value = $defaults ?: null;
        }

        $other_text = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
        $selected   = is_array($value) ? $value : (array)$value;
        unset($selected['__other_text__']);
        $layout   = !empty($config['layout']) ? ' forge-checkbox-group--horizontal' : '';

        $min_sel   = (int)($config['min_selections'] ?? 0);
        $max_sel   = (int)($config['max_selections'] ?? 0);
        $sel_attrs = '';
        if ($min_sel > 0) {
            $sel_attrs .= ' data-min-selections="' . $min_sel . '"';
        }
        if ($max_sel > 0) {
            $sel_attrs .= ' data-max-selections="' . $max_sel . '"';
        }

        $inner = '<div class="forge-checkbox-group' . $layout . '" role="group"' . $sel_attrs . '>';
        foreach ($options as $i => $opt) {
            $opt_val   = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            $opt_label = is_array($opt) ? ($opt['label'] ?? $opt_val) : $opt;
            $id_i      = $field_id . '-' . $i;
            $checked   = in_array((string)$opt_val, array_map('strval', $selected), true) ? ' checked' : '';
            $inner .= '<label class="forge-checkbox-label">'
                . '<input type="checkbox" id="' . esc_attr($id_i)
                . '" name="' . esc_attr($field_id) . '[]"'
                . ' value="' . esc_attr((string)$opt_val) . '" autocomplete="off"' . $checked . '> '
                . esc_html((string)$opt_label) . '</label>';
        }
        if (!empty($config['other_option'])) {
            $other_id  = $field_id . '-other';
            $other_chk = in_array('__other__', array_map('strval', $selected), true) ? ' checked' : '';
            $inner .= '<label class="forge-checkbox-label">'
                . '<input type="checkbox" id="' . esc_attr($other_id) . '" name="' . esc_attr($field_id) . '[]"'
                . ' value="__other__"' . $other_chk . '> ' . esc_html__('Other…', 'formfabricator') . '</label>';
            $show  = $other_chk ? '' : ' style="display:none"';
            $inner .= '<input type="text" name="' . esc_attr($field_id) . '_other"'
                . ' class="forge-input forge-other-input" value="' . esc_attr($other_text) . '"'
                . ' placeholder="' . esc_attr__('Please specify', 'formfabricator') . '"' . $show
                . self::otherInputAttrs($config) . '>';
        }
        $inner .= '</div>';

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the sanitized array of checked values submitted as $field_id[].
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        // Cap the number of submitted values BEFORE map_deep() walks them — a crafted
        // array-valued POST otherwise costs unbounded O(n) sanitize calls (map_deep
        // itself has no limit; slicing only after it ran would be too late).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        $vals = isset($_POST[$field_id])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified once in FormProcessor::handle() before field extraction runs; value is unslashed and sanitize_text_field()'d via map_deep()/capRawArray() below, WPCS doesn't recognize sanitization via the string-callback form.
            ? map_deep(self::capRawArray(wp_unslash($_POST[$field_id]), 200), 'sanitize_text_field')
            : [];
        $out  = is_array($vals) ? array_values($vals) : [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        if (in_array('__other__', $out, true) && isset($_POST[$field_id . '_other'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce is verified once in FormProcessor::handle() before field extraction runs; capOtherText() unslashes and sanitize_text_field()s the value, WPCS doesn't recognize sanitization/unslashing via the helper method.
            $out['__other_text__'] = self::capOtherText($_POST[$field_id . '_other']);
        }
        return $out;
    }

    /**
     * Sanitizes a raw checkbox value from a group copy array. Always returns an array — an absent or scalar
     * value becomes an empty array.
     *
     * @param mixed $raw The raw value from the group copy array.
     */
    public function extractFromRaw(mixed $raw): mixed
    {
        // Same element-count cap as extractValue() — this is the group-copy path
        // (up to 100 copies per GroupField), so an uncapped array here multiplies
        // the same unbounded-sanitize-cost issue across every copy.
        return is_array($raw)
            ? array_map(static fn($v) => sanitize_text_field(wp_unslash($v)), array_slice(array_values($raw), 0, 200))
            : [];
    }

    /**
     * Like extractFromRaw(), but also captures the group copy's sibling "{child_id}_other" free-text value,
     * mirroring extractValue().
     *
     * @param mixed $raw       The raw value from the group copy array.
     * @param mixed $other_raw The raw "{child_id}_other" value from the same copy, if any.
     */
    public function extractFromRawWithOther(mixed $raw, mixed $other_raw): mixed
    {
        $out = $this->extractFromRaw($raw);
        if (in_array('__other__', $out, true) && $other_raw !== null) {
            $out['__other_text__'] = self::capOtherText($other_raw);
        }
        return $out;
    }

    /**
     * Returns the actually-selected option values from an extractValue() array, excluding the internal
     * __other_text__ companion entry so it never counts toward min/max selection limits or required checks.
     *
     * @param array $value Raw value array from extractValue().
     * @return array Filtered, selected option values.
     */
    private static function selectedOptions(array $value): array
    {
        unset($value['__other_text__']);
        return array_filter($value, static fn($v) => $v !== '' && $v !== null);
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
        if (!empty($config['required'])) {
            $vals = is_array($value) ? self::selectedOptions($value) : [];
            // A selection consisting only of "__other__" with a blank companion text
            // field is effectively no answer at all — don't let it satisfy "required".
            $other_blank = in_array('__other__', $vals, true)
                && trim((string)($value['__other_text__'] ?? '')) === '';
            if (empty($vals) || ($other_blank && count($vals) === 1)) {
                $label = $config['label'] ?? __('Field', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s: Please select at least one option.', 'formfabricator'), esc_html($label));
            }
        }
        $selected = is_array($value) ? self::selectedOptions($value) : [];
        $cnt      = count($selected);
        $min      = (int)($config['min_selections'] ?? 0);
        $max      = (int)($config['max_selections'] ?? 0);
        if ($min > 0 && $cnt < $min) {
            // translators: %d: minimum number of options that must be selected.
            return sprintf(__('Please select at least %d option(s).', 'formfabricator'), $min);
        }
        if ($max > 0 && $cnt > $max) {
            // translators: %d: maximum number of options that may be selected.
            return sprintf(__('Please select at most %d option(s).', 'formfabricator'), $max);
        }
        $allowed = array_map(
            static fn($o) => (string)(is_array($o) ? ($o['value'] ?? '') : $o),
            $config['options'] ?? []
        );
        foreach ($selected as $v) {
            if ((string)$v === '__other__') {
                if (empty($config['other_option'])) {
                    return __('Please select a valid option.', 'formfabricator');
                }
                $other = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
                $check = self::validateOtherText($other, $config);
                if ($check !== true) {
                    return $check;
                }
                continue;
            }
            if (!in_array((string)$v, $allowed, true)) {
                return __('Please select a valid option.', 'formfabricator');
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
        $selected = is_array($value) ? self::selectedOptions($value) : [];
        if (empty($selected)) {
            return __('[No entry]', 'formfabricator');
        }
        $other_text = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
        $labels = [];
        foreach ($selected as $v) {
            if ((string)$v === '__other__') {
                $labels[] = $other_text !== ''
                    ? sprintf('%s (%s)', __('Other', 'formfabricator'), $other_text)
                    : __('[Other]', 'formfabricator');
                continue;
            }
            $found = false;
            foreach ($config['options'] ?? [] as $opt) {
                $opt_val = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                if ((string)$opt_val === (string)$v) {
                    $labels[] = is_array($opt) ? ($opt['label'] ?? (string)$opt_val) : (string)$opt;
                    $found    = true;
                    break;
                }
            }
            if (!$found) {
                $labels[] = (string)$v;
            }
        }
        return implode(', ', $labels);
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
            'layout'           => true,
            'other_option'     => false,
            'other_max_type'   => 'chars',
            'other_max_length' => '',
            'min_selections' => '',
            'max_selections' => '',
            'options'        => [
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
                'key'         => 'layout',
                'type'        => 'bool_seg',
                'label'       => __('Layout', 'formfabricator'),
                'false_label' => __('Vertical', 'formfabricator'),
                'true_label'  => __('Horizontal', 'formfabricator'),
                'swap'        => true,
            ],
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
            [
                'key'   => 'min_selections',
                'type'  => 'number',
                'label' => __('Min. selection', 'formfabricator'),
                'hint'  => __('Empty = not required', 'formfabricator'),
            ],
            [
                'key'   => 'max_selections',
                'type'  => 'number',
                'label' => __('Max. selection', 'formfabricator'),
                'hint'  => __('Empty = unlimited', 'formfabricator'),
            ],
        ];
    }
}
