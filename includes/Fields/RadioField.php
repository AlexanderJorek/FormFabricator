<?php

/**
 * Radio button group field for single-choice selection.
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
 * Radio button group field.
 */
class RadioField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.fabricator-radio-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.fabricator-radio-group--horizontal {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 16px;
}
.fabricator-radio-label {
    display: flex;
    align-items: center;
    gap: 9px;
    cursor: pointer;
    font-size: 14px;
    color: var(--fabricator-text);
    line-height: 1.4;
    user-select: none;
}
.fabricator-radio-label input[type="radio"] {
    appearance: none;
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    border: 2px solid var(--fabricator-border-input);
    border-radius: 50%;
    background: var(--fabricator-bg);
    cursor: pointer;
    transition: border-color .15s, background .15s, box-shadow .15s;
    position: relative;
}
.fabricator-radio-label input[type="radio"]:checked {
    border-color: var(--fabricator-accent);
    background: var(--fabricator-accent);
    box-shadow: inset 0 0 0 3px var(--fabricator-bg);
}
.fabricator-radio-label input[type="radio"]:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--fabricator-accent) 25%, transparent);
}
.fabricator-radio-label:hover input[type="radio"]:not(:checked) {
    border-color: var(--fabricator-accent);
}
.fabricator-other-input { margin-top: 4px; }
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'radio';
    }

    public function getLabel(): string
    {
        return __('Radio', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-circle-dot';
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return <<<'JS'
        function (root) {
            root.querySelectorAll('input[value="__other__"]').forEach(function (inp) {
                inp.addEventListener('change', function () {
                    var wrap  = this.closest('.fabricator-radio-group, .fabricator-checkbox-group');
                    var other = wrap && wrap.querySelector('.fabricator-other-input');
                    if (!other) return;
                    other.style.display = this.checked ? '' : 'none';
                });
            });
        }
        JS;
    }

    /**
     * Returns the client-side empty-check function for required validation.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return ['fn' => "function(f){return !f.querySelector('input[type=\"radio\"]:checked');}"];
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
        $options = $config['options'] ?? [];
        $req     = !empty($config['required']) ? ' required' : '';

        /* extractValue() returns ['value' => ..., '__other_text__' => ...] when
           "Other" was selected with typed text — unwrap for every comparison below. */
        $other_text = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
        if (is_array($value)) {
            $value = $value['value'] ?? '';
        }

        if ($value === null) {
            foreach ($options as $opt) {
                if (is_array($opt) && !empty($opt['default'])) {
                    $value = $opt['value'] ?? '';
                    break;
                }
            }
        }

        $layout = !empty($config['layout']) ? ' fabricator-radio-group--horizontal' : '';
        $inner  = '<div class="fabricator-radio-group' . $layout . '" role="group">';
        foreach ($options as $i => $opt) {
            $opt_val   = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            $opt_label = is_array($opt) ? ($opt['label'] ?? $opt_val) : $opt;
            $id_i      = $field_id . '-' . $i;
            $checked   = checked((string)($value ?? ''), (string)$opt_val, false);
            $inner .= '<label class="fabricator-radio-label">'
                . '<input type="radio" id="' . esc_attr($id_i)
                . '" name="' . esc_attr($field_id)
                . '" value="' . esc_attr((string)$opt_val) . '" autocomplete="off"'
                . $checked . $req . '> ' . esc_html((string)$opt_label) . '</label>';
        }
        if (!empty($config['other_option'])) {
            $other_id  = $field_id . '-other';
            $other_chk = checked((string)($value ?? ''), '__other__', false);
            $inner .= '<label class="fabricator-radio-label">'
                . '<input type="radio" id="' . esc_attr($other_id) . '" name="' . esc_attr($field_id) . '"'
                . ' value="__other__" autocomplete="off"' . $other_chk . $req . '> '
                . esc_html__('Other…', 'formfabricator') . '</label>';
        }
        if (!empty($config['other_option'])) {
            $show = (string)($value ?? '') === '__other__' ? '' : ' style="display:none"';
            $inner .= '<input type="text" name="' . esc_attr($field_id) . '_other"'
                . ' class="fabricator-input fabricator-other-input" value="' . esc_attr($other_text) . '"'
                . ' placeholder="' . esc_attr__('Please specify', 'formfabricator') . '"' . $show
                . self::otherInputAttrs($config) . '>';
        }
        $inner .= '</div>';

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the selected option, plus the typed "Other" text when present.
     *
     * @param string $field_id The field element ID.
     * @return mixed String selection, or ['value' => ..., '__other_text__' => ...] when "Other" was
     *               selected and a companion text field was submitted.
     */
    public function extractValue(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        $selected = isset($_POST[$field_id]) ? sanitize_text_field(wp_unslash($_POST[$field_id])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        if ($selected === '__other__' && isset($_POST[$field_id . '_other'])) {
            return [
                'value'          => $selected,
                // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce is verified once in FormProcessor::handle() before field extraction runs; capOtherText() unslashes and sanitize_text_field()s the value, WPCS doesn't recognize sanitization/unslashing via the helper method.
                '__other_text__' => self::capOtherText($_POST[$field_id . '_other']),
            ];
        }
        return $selected;
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
        $selected = is_array($value) ? ($value['value'] ?? '') : $value;
        if ($selected === '' || $selected === null) {
            return true;
        }
        if ((string)$selected === '__other__') {
            if (empty($config['other_option'])) {
                return __('Please select a valid option.', 'formfabricator');
            }
            // "Other" selected with a blank companion text field is effectively no
            // answer — don't let it satisfy a required field.
            $other = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
            if (!empty($config['required']) && $other === '') {
                $label = $config['label'] ?? __('Field', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s is a required field.', 'formfabricator'), esc_html($label));
            }
            return self::validateOtherText($other, $config);
        }
        $allowed = array_map(
            static fn($o) => (string)(is_array($o) ? ($o['value'] ?? '') : $o),
            $config['options'] ?? []
        );
        if (!in_array((string)$selected, $allowed, true)) {
            return __('Please select a valid option.', 'formfabricator');
        }
        return true;
    }

    /**
     * Like extractFromRaw(), but also captures the group copy's sibling "{child_id}_other" free-text value, mirroring extractValue().
     *
     * @param mixed $raw       The raw value from the group copy array.
     * @param mixed $other_raw The raw "{child_id}_other" value from the same copy, if any.
     */
    public function extractFromRawWithOther(mixed $raw, mixed $other_raw): mixed
    {
        $selected = $this->extractFromRaw($raw);
        if ($selected === '__other__' && $other_raw !== null) {
            return [
                'value'          => $selected,
                '__other_text__' => self::capOtherText($other_raw),
            ];
        }
        return $selected;
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
        $other_text = '';
        if (is_array($value)) {
            $other_text = trim((string)($value['__other_text__'] ?? ''));
            $value      = $value['value'] ?? '';
        }
        if ($value === '' || $value === null) {
            return __('[No entry]', 'formfabricator');
        }
        if ((string)$value === '__other__') {
            return $other_text !== ''
                ? sprintf('%s (%s)', __('Other', 'formfabricator'), $other_text)
                : __('[Other]', 'formfabricator');
        }
        foreach ($config['options'] ?? [] as $opt) {
            $opt_val = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            if ((string)$opt_val === (string)$value) {
                return is_array($opt) ? ($opt['label'] ?? (string)$opt_val) : (string)$opt;
            }
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
            'layout'          => true,
            'other_option'    => false,
            'other_max_type'  => 'chars',
            'other_max_length' => '',
            'options'      => [
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
        ];
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [self::otherTextClientRule()];
    }
}
