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
        return <<<'CSS'
.forge-slider-wrap { display: flex; align-items: center; gap: 14px; }
.forge-slider-custom {
    flex: 1;
    padding: 9px 0;
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    touch-action: none;
}
.forge-slider-custom:focus { outline: none; }
.forge-slider-track {
    position: relative;
    height: 4px;
    border-radius: 2px;
    background: var(--forge-border-input);
}
.forge-slider-fill {
    position: absolute;
    left: 0; top: 0;
    height: 100%;
    border-radius: 2px;
    background: var(--forge-accent);
    pointer-events: none;
}
.forge-slider-thumb {
    position: absolute;
    top: 50%;
    width: 14px; height: 14px;
    border-radius: 50%;
    background: var(--forge-accent);
    border: 2px solid #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.3);
    transform: translate(-50%, -50%);
    transition: box-shadow .1s;
    cursor: grab;
}
.forge-slider-thumb:active { cursor: grabbing; box-shadow: 0 2px 6px rgba(0,0,0,.35); }
.forge-slider-custom:focus .forge-slider-thumb,
.forge-slider-thumb:focus {
    outline: none;
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 25%, transparent),
        0 1px 3px rgba(0,0,0,.3);
}
.forge-slider-value {
    font-size: 13px;
    font-weight: 600;
    min-width: 32px;
    text-align: center;
    color: var(--forge-text-muted);
}
CSS;
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
        return ['fn' => "function(f){var i=f.querySelector('input[type=\"hidden\"]');return !i||i.value==='';}"];
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
            root.querySelectorAll('.forge-slider-wrap').forEach(function (wrap) {
                var min  = parseFloat(wrap.dataset.min  || 0);
                var max  = parseFloat(wrap.dataset.max  || 100);
                var step = parseFloat(wrap.dataset.step || 1);
                var isRange = wrap.classList.contains('forge-slider-wrap--range');
                var track = wrap.querySelector('.forge-slider-track');
                var fill  = wrap.querySelector('.forge-slider-fill');
                function snap(raw) {
                    var stepped = Math.round((raw - min) / step) * step + min;
                    return Math.min(max, Math.max(min, parseFloat(stepped.toFixed(10))));
                }
                function pct(val) { return (val - min) / (max - min) * 100; }
                function valFromX(clientX) {
                    var rect  = track.getBoundingClientRect();
                    var ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
                    return snap(min + ratio * (max - min));
                }
                if (!isRange) {
                    var thumb  = wrap.querySelector('.forge-slider-thumb');
                    var input  = wrap.querySelector('input[type="hidden"]');
                    var disp   = wrap.querySelector('.forge-slider-value');
                    var curVal = parseFloat(wrap.dataset.value || min);
                    function setVal(v, writeInput) {
                        curVal = v;
                        var p = pct(v);
                        thumb.style.left = p + '%';
                        fill.style.width = p + '%';
                        if (writeInput && input) input.value = v;
                        if (disp)  disp.textContent = v;
                        wrap.querySelector('.forge-slider-custom').setAttribute('aria-valuenow', v);
                    }
                    /* Visual-only init — hidden input stays '' until user interacts */
                    setVal(curVal, false);
                    function startDrag(clientX) {
                        setVal(valFromX(clientX), true);
                        function onMove(e) {
                            if (e.touches) { e.preventDefault(); }
                            setVal(valFromX(e.touches ? e.touches[0].clientX : e.clientX), true);
                        }
                        function onUp() {
                            document.removeEventListener('mousemove', onMove);
                            document.removeEventListener('mouseup', onUp);
                            document.removeEventListener('touchmove', onMove);
                            document.removeEventListener('touchend', onUp);
                        }
                        document.addEventListener('mousemove', onMove);
                        document.addEventListener('mouseup', onUp);
                        document.addEventListener('touchmove', onMove, { passive: false });
                        document.addEventListener('touchend', onUp);
                    }
                    track.addEventListener('mousedown',  function (e) { e.preventDefault(); startDrag(e.clientX); });
                    track.addEventListener('touchstart', function (e) { e.preventDefault(); startDrag(e.touches[0].clientX); }, { passive: false });
                    var slider = wrap.querySelector('.forge-slider-custom');
                    slider.addEventListener('keydown', function (e) {
                        var delta = 0;
                        if (e.key === 'ArrowRight' || e.key === 'ArrowUp')   delta =  step;
                        if (e.key === 'ArrowLeft'  || e.key === 'ArrowDown') delta = -step;
                        if (e.key === 'Home') { setVal(min, true); return; }
                        if (e.key === 'End')  { setVal(max, true); return; }
                        if (delta) { e.preventDefault(); setVal(snap(curVal + delta), true); }
                    });
                } else {
                    var thumbFrom = wrap.querySelector('.forge-slider-thumb--from');
                    var thumbTo   = wrap.querySelector('.forge-slider-thumb--to');
                    var inputFrom = wrap.querySelector('.forge-slider-input-from');
                    var inputTo   = wrap.querySelector('.forge-slider-input-to');
                    var dispFrom  = wrap.querySelector('.forge-slider-from-display');
                    var dispTo    = wrap.querySelector('.forge-slider-to-display');
                    var from = parseFloat(wrap.dataset.from || min);
                    var to   = parseFloat(wrap.dataset.to   || max);
                    function setRange(writeInput) {
                        var pFrom = pct(from), pTo = pct(to);
                        thumbFrom.style.left = pFrom + '%';
                        thumbTo.style.left   = pTo   + '%';
                        fill.style.left  = pFrom + '%';
                        fill.style.width = (pTo - pFrom) + '%';
                        if (writeInput && inputFrom) inputFrom.value = from;
                        if (writeInput && inputTo)   inputTo.value   = to;
                        if (dispFrom)  dispFrom.textContent = from;
                        if (dispTo)    dispTo.textContent   = to;
                        thumbFrom.setAttribute('aria-valuenow', from);
                        thumbTo.setAttribute('aria-valuenow',   to);
                    }
                    /* Visual-only init */
                    setRange(false);
                    function dragThumb(isFrom, clientX) {
                        var v = valFromX(clientX);
                        if (isFrom) from = Math.min(v, to   - step);
                        else        to   = Math.max(v, from + step);
                        setRange(true);
                        function onMove(e) {
                            if (e.touches) { e.preventDefault(); }
                            var v2 = valFromX(e.touches ? e.touches[0].clientX : e.clientX);
                            if (isFrom) from = Math.min(v2, to   - step);
                            else        to   = Math.max(v2, from + step);
                            setRange(true);
                        }
                        function onUp() {
                            document.removeEventListener('mousemove', onMove);
                            document.removeEventListener('mouseup', onUp);
                            document.removeEventListener('touchmove', onMove);
                            document.removeEventListener('touchend', onUp);
                        }
                        document.addEventListener('mousemove', onMove);
                        document.addEventListener('mouseup', onUp);
                        document.addEventListener('touchmove', onMove, { passive: false });
                        document.addEventListener('touchend', onUp);
                    }
                    thumbFrom.addEventListener('mousedown',  function (e) { e.preventDefault(); dragThumb(true,  e.clientX); });
                    thumbTo.addEventListener('mousedown',    function (e) { e.preventDefault(); dragThumb(false, e.clientX); });
                    thumbFrom.addEventListener('touchstart', function (e) { e.preventDefault(); dragThumb(true,  e.touches[0].clientX); }, { passive: false });
                    thumbTo.addEventListener('touchstart',   function (e) { e.preventDefault(); dragThumb(false, e.touches[0].clientX); }, { passive: false });
                }
            });
        }
        JS;
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
        $min    = (float)($config['min']  ?? 0);
        $max    = (float)($config['max']  ?? 100);
        $step   = (float)($config['step'] ?? 1);
        $ranged = !empty($config['ranged']);

        if ($ranged) {
            $val_from = (float)(is_array($value) ? ($value['from'] ?? $min) : $min);
            $val_to   = (float)(is_array($value) ? ($value['to']   ?? $max) : $max);
            $inner = '<div class="forge-slider-wrap forge-slider-wrap--range"'
                . ' data-min="' . $min . '" data-max="' . $max . '" data-step="' . $step . '"'
                . ' data-from="' . $val_from . '" data-to="' . $val_to . '">'
                . '<div class="forge-slider-custom forge-slider-custom--range" role="group">'
                . '<div class="forge-slider-track">'
                . '<div class="forge-slider-fill"></div>'
                . '<div class="forge-slider-thumb forge-slider-thumb--from" tabindex="0" role="slider"'
                . ' aria-valuemin="' . $min . '" aria-valuemax="' . $max . '" aria-valuenow="' . $val_from . '"></div>'
                . '<div class="forge-slider-thumb forge-slider-thumb--to" tabindex="0" role="slider"'
                . ' aria-valuemin="' . $min . '" aria-valuemax="' . $max . '" aria-valuenow="' . $val_to . '"></div>'
                . '</div></div>'
                . '<input type="hidden" name="' . esc_attr($field_id)
                . '[from]" class="forge-slider-input-from" value="">'
                . '<input type="hidden" name="' . esc_attr($field_id)
                . '[to]"   class="forge-slider-input-to"   value="">'
                . '<span class="forge-slider-value">'
                . '<span class="forge-slider-from-display">' . $val_from . '</span>'
                . ' – '
                . '<span class="forge-slider-to-display">' . $val_to . '</span>'
                . '</span>'
                . '</div>';
        } else {
            $val   = (float)($value ?? $min);
            $inner = '<div class="forge-slider-wrap"'
                . ' data-min="' . $min . '" data-max="' . $max . '" data-step="' . $step . '"'
                . ' data-value="' . $val . '">'
                . '<div class="forge-slider-custom" role="slider" tabindex="0"'
                . ' aria-valuemin="' . $min . '" aria-valuemax="' . $max . '" aria-valuenow="' . $val . '">'
                . '<div class="forge-slider-track">'
                . '<div class="forge-slider-fill"></div>'
                . '<div class="forge-slider-thumb"></div>'
                . '</div></div>'
                . '<input type="hidden" name="' . esc_attr($field_id)
                . '" id="' . esc_attr($field_id) . '" value="">'
                . '<span class="forge-slider-value">' . $val . '</span>'
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified once in FormProcessor::handle() before field extraction runs; value is unslashed and sanitize_text_field()'d via map_deep()/capRawArray(), WPCS doesn't recognize sanitization via the string-callback form.
        $raw = isset($_POST[$field_id]) ? map_deep(self::capRawArray(wp_unslash($_POST[$field_id])), 'sanitize_text_field') : null;
        if (is_array($raw)) {
            return [
                'from' => sanitize_text_field((string)($raw['from'] ?? '')),
                'to'   => sanitize_text_field((string)($raw['to']   ?? '')),
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
        $min = (float)($config['min'] ?? 0);
        $max = (float)($config['max'] ?? 100);
        if (!empty($config['ranged']) && is_array($value)) {
            foreach (['from' => $value['from'] ?? null, 'to' => $value['to'] ?? null] as $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $hard = self::validateTextHardCap((string) $v);
                if ($hard !== true) {
                    return $hard;
                }
                if (!is_numeric($v)) {
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
            }
            $from = $value['from'] ?? null;
            $to   = $value['to']   ?? null;
            if (is_numeric($from) && is_numeric($to) && (float)$from > (float)$to) {
                return __('The "from" value must not be greater than the "to" value.', 'formfabricator');
            }
        } elseif ($value !== '' && $value !== null) {
            $hard = self::validateTextHardCap((string) $value);
            if ($hard !== true) {
                return $hard;
            }
            if (!is_numeric($value)) {
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
        }
        return true;
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'slider-range', 'fn' => <<<'JS'
            function (fieldEl) {
                var wrap = fieldEl.querySelector('.forge-slider-wrap');
                if (!wrap) return null;
                var min = parseFloat(wrap.dataset.min);
                var max = parseFloat(wrap.dataset.max);
                var _i18n = window.ForgeForms && window.ForgeForms.i18n;
                var invalidMsg = (_i18n && _i18n.slider_invalid_value) || 'Please enter a valid value.';
                if (wrap.classList.contains('forge-slider-wrap--range')) {
                    var fromInp = fieldEl.querySelector('.forge-slider-input-from');
                    var toInp   = fieldEl.querySelector('.forge-slider-input-to');
                    if (!fromInp || !toInp) return null;
                    var from = parseFloat(fromInp.value);
                    var to   = parseFloat(toInp.value);
                    if (isNaN(from) || isNaN(to)) return invalidMsg;
                    if (from < min || to > max) {
                        return ((_i18n && _i18n.slider_out_of_range) || 'Value outside the allowed range (%1$s–%2$s).')
                            .replace('%1$s', min).replace('%2$s', max);
                    }
                } else {
                    var inp = fieldEl.querySelector('input[type="hidden"]');
                    if (!inp) return null;
                    var val = parseFloat(inp.value);
                    if (isNaN(val)) return invalidMsg;
                    if (val < min) return ((_i18n && _i18n.slider_min) || 'Minimum value: %s').replace('%s', min);
                    if (val > max) return ((_i18n && _i18n.slider_max) || 'Maximum value: %s').replace('%s', max);
                }
                return null;
            }
            JS]];
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
            return ($value['from'] ?? '') . ' – ' . ($value['to'] ?? '');
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
