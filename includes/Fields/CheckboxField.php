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
        return self::readFieldAsset('assets/css/fields/CheckboxField.css');
    }

    /**
     * Returns false: this field renders a tick box per option, each with its own id, so the
     * question text names the set through aria-labelledby rather than pointing at one element (see BaseField::wrap()).
     *
     * @param array $config Field configuration.
     * @return bool
     */
    public function labelsOwnControl(array $config): bool
    {
        unset($config);
        return false;
    }

    /**
     * Its inputs are checkboxes named after the field id, which front.js reads as an empty list while hidden.
     *
     * @return array
     */
    public function hiddenConditionValue(): array
    {
        return [];
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
     * Returns client-side initialization JavaScript function body: the "Other" text input's show/hide.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/CheckboxField.js');
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'checkbox-count', 'fn' => self::readFieldAsset('assets/js/fields/CheckboxField.checkbox-count.js')], self::otherTextClientRule()];
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
                if (!empty($opt['default'])) {
                    $defaults[] = $opt['value'] ?? '';
                }
            }
            $value = $defaults ?: null;
        }

        $other_text = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
        $selected   = is_array($value) ? $value : (array)$value;
        unset($selected['__other_text__']);
        $layout   = !empty($config['layout']) ? ' fabricator-checkbox-group--horizontal' : '';

        $min_sel   = (int)($config['min_selections'] ?? 0);
        $max_sel   = (int)($config['max_selections'] ?? 0);
        $sel_attrs = '';
        // The messages travel with the numbers: the count is known here, so _n() picks the right plural form for every
        // language, which one localized "%d option(s)" string could not.
        if ($min_sel > 0) {
            // translators: %d: minimum number of options that must be selected.
            $min_msg    = sprintf(_n('Please select at least %d option.', 'Please select at least %d options.', $min_sel, 'formfabricator'), $min_sel);
            $sel_attrs .= ' data-min-selections="' . $min_sel . '" data-min-message="' . esc_attr($min_msg) . '"';
        }
        if ($max_sel > 0) {
            // translators: %d: maximum number of options that may be selected.
            $max_msg    = sprintf(_n('Please select at most %d option.', 'Please select at most %d options.', $max_sel, 'formfabricator'), $max_sel);
            $sel_attrs .= ' data-max-selections="' . $max_sel . '" data-max-message="' . esc_attr($max_msg) . '"';
        }

        // No role="group" here: BaseField::wrap() marks the field wrapper as the group and names it from the
        // field's own label, so a second unnamed group inside it would only be announced again with no name.
        $inner = '<div class="fabricator-checkbox-group' . $layout . '"' . $sel_attrs . '>';
        foreach ($options as $i => $opt) {
            $opt_val   = $opt['value'] ?? '';
            $opt_label = $opt['label'] ?? $opt_val;
            $id_i      = $field_id . '-' . $i;
            $checked   = in_array((string)$opt_val, array_map('strval', $selected), true) ? ' checked' : '';
            $inner .= '<label class="fabricator-checkbox-label">'
                . '<input type="checkbox" id="' . esc_attr($id_i)
                . '" name="' . esc_attr($field_id) . '[]"'
                . ' value="' . esc_attr((string)$opt_val) . '" autocomplete="off"' . $checked . '> '
                . esc_html((string)$opt_label) . '</label>';
        }
        if (!empty($config['other_option'])) {
            $other_id  = $field_id . '-other';
            $other_chk = in_array('__other__', array_map('strval', $selected), true) ? ' checked' : '';
            $inner .= '<label class="fabricator-checkbox-label">'
                . '<input type="checkbox" id="' . esc_attr($other_id) . '" name="' . esc_attr($field_id) . '[]"'
                . ' value="__other__"' . $other_chk . '> ' . esc_html__('Other…', 'formfabricator') . '</label>';
            $show  = $other_chk ? '' : ' style="display:none"';
            $inner .= '<input type="text" name="' . esc_attr($field_id) . '_other"'
                . ' class="fabricator-input fabricator-other-input" value="' . esc_attr($other_text) . '"'
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
        self::assertRequestNonceVerified();
        // Cap value count BEFORE map_deep() walks them; map_deep itself has no limit.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via assertRequestNonceVerified().
        $vals = isset($_POST[$field_id])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in FormProcessor::handle(); map_deep()/capRawArray() sanitizes, WPCS misses the callback form.
            ? map_deep(self::capRawArray(wp_unslash($_POST[$field_id]), 200), 'sanitize_text_field')
            : [];
        // Strings only (a nested POST array stays an array), and each value once, so a repeated value can't meet
        // min_selections.
        $out  = is_array($vals) ? array_values(array_unique(array_filter($vals, 'is_string'))) : [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        if (in_array('__other__', $out, true) && isset($_POST[$field_id . '_other'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified in FormProcessor::handle(); capOtherText() sanitizes/unslashes, WPCS misses the helper form.
            $out['__other_text__'] = self::capOtherText($_POST[$field_id . '_other']);
        }
        return $out;
    }

    /**
     * Returns selected option values, excluding __other_text__ so it never counts toward selection limits.
     *
     * @param array $value Raw value array from extractValue().
     * @return array Filtered, selected option values.
     */
    private static function selectedOptions(array $value): array
    {
        unset($value['__other_text__']);
        return array_filter($value, static fn($v) => is_string($v) && $v !== '');
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
                return sprintf(__('%s: Please select at least one option.', 'formfabricator'), $label);
            }
        }
        $selected = is_array($value) ? self::selectedOptions($value) : [];
        $cnt      = count($selected);
        $min      = (int)($config['min_selections'] ?? 0);
        $max      = (int)($config['max_selections'] ?? 0);
        if ($min > 0 && $cnt < $min) {
            // translators: %d: minimum number of options that must be selected.
            return sprintf(_n('Please select at least %d option.', 'Please select at least %d options.', $min, 'formfabricator'), $min);
        }
        if ($max > 0 && $cnt > $max) {
            // translators: %d: maximum number of options that may be selected.
            return sprintf(_n('Please select at most %d option.', 'Please select at most %d options.', $max, 'formfabricator'), $max);
        }
        $allowed = array_map(
            static fn($o) => (string)($o['value'] ?? ''),
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
                $opt_val = $opt['value'] ?? '';
                if ((string)$opt_val === (string)$v) {
                    $labels[] = (string)($opt['label'] ?? $opt_val);
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
