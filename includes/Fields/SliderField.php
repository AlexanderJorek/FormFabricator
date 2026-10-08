<?php

/**
 * Range slider input field.
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
 * Range slider input field.
 */
class SliderField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/SliderField.css');
    }

    /**
     * A range: both ends, "from to" (BaseField::joinedSubValues()), as front.js reads the "id[from]"/"id[to]" inputs. A
     * single slider posts one input named after the field, read as it is.
     *
     * @param mixed $raw    What extractValue() returned.
     * @param array $config Field configuration.
     * @return mixed
     */
    public function conditionValue(mixed $raw, array $config): mixed
    {
        if (!empty($config['ranged'])) {
            return self::joinedSubValues($raw, ['from', 'to']);
        }
        return is_array($raw) ? '' : $raw;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'slider';
    }

    public function getLabel(): string
    {
        return __('Slider', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-sliders';
    }

    /**
     * Returns the client-side empty-check function for the required validator.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return ['fn' => self::readFieldAsset('assets/js/fields/SliderField.emptycheck.js')];
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/SliderField.js');
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
        [$min, $max, $step] = self::bounds($config);
        $ranged = !empty($config['ranged']);

        if ($ranged) {
            $val_from = (float)(is_array($value) ? ($value['from'] ?? $min) : $min);
            $val_to   = (float)(is_array($value) ? ($value['to']   ?? $max) : $max);
            $inner = '<div class="fabricator-slider-wrap fabricator-slider-wrap--range"'
                . ' data-min="' . $min . '" data-max="' . $max . '" data-step="' . $step . '"'
                . ' data-from="' . $val_from . '" data-to="' . $val_to . '">'
                . '<div class="fabricator-slider-custom fabricator-slider-custom--range" role="group">'
                . '<div class="fabricator-slider-track">'
                . '<div class="fabricator-slider-fill"></div>'
                . '<div class="fabricator-slider-thumb fabricator-slider-thumb--from" tabindex="0" role="slider"'
                . ' aria-valuemin="' . $min . '" aria-valuemax="' . $max . '" aria-valuenow="' . $val_from . '"></div>'
                . '<div class="fabricator-slider-thumb fabricator-slider-thumb--to" tabindex="0" role="slider"'
                . ' aria-valuemin="' . $min . '" aria-valuemax="' . $max . '" aria-valuenow="' . $val_to . '"></div>'
                . '</div></div>'
                . '<input type="hidden" name="' . esc_attr($field_id)
                . '[from]" class="fabricator-slider-input-from" value="">'
                . '<input type="hidden" name="' . esc_attr($field_id)
                . '[to]"   class="fabricator-slider-input-to"   value="">'
                . '<span class="fabricator-slider-value">'
                . '<span class="fabricator-slider-from-display">' . $val_from . '</span>'
                . ' – '
                . '<span class="fabricator-slider-to-display">' . $val_to . '</span>'
                . '</span>'
                . '</div>';
        } else {
            $val   = (float)($value ?? $min);
            $inner = '<div class="fabricator-slider-wrap"'
                . ' data-min="' . $min . '" data-max="' . $max . '" data-step="' . $step . '"'
                . ' data-value="' . $val . '">'
                . '<div class="fabricator-slider-custom" role="slider" tabindex="0"'
                . ' aria-valuemin="' . $min . '" aria-valuemax="' . $max . '" aria-valuenow="' . $val . '">'
                . '<div class="fabricator-slider-track">'
                . '<div class="fabricator-slider-fill"></div>'
                . '<div class="fabricator-slider-thumb"></div>'
                . '</div></div>'
                . '<input type="hidden" name="' . esc_attr($field_id)
                . '" id="' . esc_attr($field_id) . '" value="">'
                . '<span class="fabricator-slider-value">' . $val . '</span>'
                . '</div>';
        }

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the sanitized slider value. Ranged mode submits `$field_id[from]` and `$field_id[to]` as an array; single mode submits a scalar.
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; map_deep()/capRawArray() sanitizes, WPCS misses the callback form.
        $raw = isset($_POST[$field_id]) ? map_deep(self::capRawArray(wp_unslash($_POST[$field_id])), 'sanitize_text_field') : null;
        if (is_array($raw)) {
            // scalarSubfieldMap() avoids stringifying a nested from[]/to[] array to "Array".
            $pair = self::scalarSubfieldMap($raw);
            return [
                'from' => sanitize_text_field($pair['from'] ?? ''),
                'to'   => sanitize_text_field($pair['to']   ?? ''),
            ];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        return isset($_POST[$field_id]) ? sanitize_text_field((string)$raw) : '';
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
        [$min, $max, $step] = self::bounds($config);
        if (!empty($config['ranged']) && is_array($value)) {
            foreach (['from' => $value['from'] ?? null, 'to' => $value['to'] ?? null] as $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $hard = self::validateTextHardCap((string) $v);
                if ($hard !== true) {
                    return $hard;
                }
                // is_finite(): is_numeric() accepts "1e999", which casts to INF.
                if (!is_numeric($v) || !is_finite((float)$v)) {
                    return __('Please enter a valid value.', 'formfabricator');
                }
                $n = (float)$v;
                if ($n < $min) {
                    // translators: %s: minimum allowed value.
                    return sprintf(__('Minimum value: %s', 'formfabricator'), $min);
                }
                if ($n > $max) {
                    // translators: %s: maximum allowed value.
                    return sprintf(__('Maximum value: %s', 'formfabricator'), $max);
                }
                if (self::offStep($n, $min, $step)) {
                    // translators: %s: step size.
                    return sprintf(__('Please enter a value in steps of %s.', 'formfabricator'), $step);
                }
            }
            $from = $value['from'] ?? null;
            $to   = $value['to']   ?? null;
            if (is_numeric($from) && is_numeric($to) && (float)$from > (float)$to) {
                return __('The "from" value must not be greater than the "to" value.', 'formfabricator');
            }
        } elseif (is_array($value)) {
            // A direct POST can send an array to a single-value slider, which (string) turned into a PHP warning.
            return __('Please enter a valid value.', 'formfabricator');
        } elseif ($value !== '' && $value !== null) {
            $hard = self::validateTextHardCap((string) $value);
            if ($hard !== true) {
                return $hard;
            }
            if (!is_numeric($value) || !is_finite((float)$value)) {
                return __('Please enter a valid value.', 'formfabricator');
            }
            $n = (float)$value;
            if ($n < $min) {
                // translators: %s: minimum allowed value.
                return sprintf(__('Minimum value: %s', 'formfabricator'), $min);
            }
            if ($n > $max) {
                // translators: %s: maximum allowed value.
                return sprintf(__('Maximum value: %s', 'formfabricator'), $max);
            }
            if (self::offStep($n, $min, $step)) {
                // translators: %s: step size.
                return sprintf(__('Please enter a value in steps of %s.', 'formfabricator'), $step);
            }
        }
        return true;
    }

    /**
     * The slider's effective [min, max, step], shared by render() and validate() so both see the same range. A step of
     * zero or less, or max not above min, made SliderField.js divide by zero (NaN positions, a frozen thumb).
     *
     * @param array $config Field configuration.
     * @return array{0: float, 1: float, 2: float}
     */
    private static function bounds(array $config): array
    {
        $min  = (float)($config['min']  ?? 0);
        $max  = (float)($config['max']  ?? 100);
        $step = (float)($config['step'] ?? 1);
        if (!is_finite($step) || $step <= 0) {
            $step = 1.0;
        }
        if (!is_finite($min)) {
            $min = 0.0;
        }
        if (!is_finite($max) || $max <= $min) {
            $max = $min + $step;
        }
        return [$min, $max, $step];
    }

    /**
     * Whether $n lies off the grid min + k·step; mirrored in SliderField.slider-range.js.
     *
     * @param float $n    Submitted value.
     * @param float $min  Effective minimum.
     * @param float $step Effective step (> 0).
     * @return bool
     */
    private static function offStep(float $n, float $min, float $step): bool
    {
        $k = ($n - $min) / $step;
        // Relative tolerance: floating point puts 0.3 at 2.9999999999999996 steps of 0.1.
        return abs($k - round($k)) > 1e-9 * max(1.0, abs($k));
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'slider-range', 'fn' => self::readFieldAsset('assets/js/fields/SliderField.slider-range.js')]];
    }

    /**
     * Maps the field value to a human-readable string for email and PDF output.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return string Human-readable representation.
     */
    public function map(mixed $value, array $config): string
    {
        if (!empty($config['ranged']) && is_array($value)) {
            $from = is_scalar($value['from'] ?? null) ? trim((string) $value['from']) : '';
            $to   = is_scalar($value['to'] ?? null) ? trim((string) $value['to']) : '';
            // An optional range left untouched posts neither end: "[No entry]", not a bare " – ".
            if ($from === '' && $to === '') {
                return __('[No entry]', 'formfabricator');
            }
            return $from . ' – ' . $to;
        }
        if (is_array($value)) {
            return __('[No entry]', 'formfabricator');
        }
        return $value !== null && $value !== '' ? (string)$value : __('[No entry]', 'formfabricator');
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
            'min'    => 0,
            'max'    => 100,
            'step'   => 1,
            'ranged' => false,
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
        return array_merge(
            $this->baseGeneralEntries(),
            [
            [
                'key'         => 'ranged',
                'type'        => 'bool_seg',
                'label'       => __('Mode', 'formfabricator'),
                'false_label' => __('Single', 'formfabricator'),
                'true_label'  => __('Range', 'formfabricator'),
            ],
            [
                'key'   => 'min',
                'type'  => 'number',
                'label' => __('Minimum value', 'formfabricator'),
            ],
            [
                'key'   => 'max',
                'type'  => 'number',
                'label' => __('Maximum value', 'formfabricator'),
            ],
            [
                'key'   => 'step',
                'type'  => 'number',
                'label' => __('Step size', 'formfabricator'),
            ],
            ]
        );
    }
}
