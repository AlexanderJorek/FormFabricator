<?php

/**
 * Canvas-based signature capture field.
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
 * Canvas-based signature capture field.
 */
class SignatureField extends BaseField
{
    private const ICON_RESET = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
        . ' aria-hidden="true" focusable="false">'
        . '<path d="M125.7 160H176a16 16 0 0 1 0 32H48a16 16 0 0 1-16-16V48a16 16 0 0 1 32 0v68.7'
        . 'C115.3 45.1 191.6 0 278 0c141.4 0 256 114.6 256 256S419.4 512 278 512'
        . 'C167.7 512 74.4 443.5 38 346a16 16 0 1 1 30-11c31.4 83.7 111.5 141 210 141'
        . ' 123.7 0 224-100.3 224-224S401.7 32 278 32c-78.1 0-145.8 39.4-185.3 99.3z"/>'
        . '</svg>';

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-signature-wrap {
    border: 1px solid var(--forge-border-input);
    border-radius: var(--forge-radius);
    overflow: hidden;
    background: #ffffff;
}
.forge-signature-wrap:has(.forge-signature-canvas:focus) {
    border-color: var(--forge-accent);
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 15%, transparent);
}
.forge-signature-canvas {
    display: block;
    width: 100%;
    cursor: crosshair;
    touch-action: none;
    background: #ffffff !important;
    color-scheme: light;
    forced-color-adjust: none;
    -webkit-touch-callout: none;
    -webkit-user-select: none;
    user-select: none;
}
.forge-signature-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 10px;
    background: var(--forge-bg-subtle);
    border-top: 1px solid var(--forge-border);
}
.forge-signature-clear {
    -webkit-appearance: none;
    appearance: none;
    background: transparent !important;
    border: 1px solid var(--forge-border-input);
    border-radius: var(--forge-radius-sm);
    padding: 5px 6px;
    cursor: pointer;
    color: var(--forge-text-muted);
    line-height: 1;
    transition: border-color .1s;
}
.forge-signature-clear:hover,
.forge-signature-clear:active,
.forge-signature-clear:focus {
    background: transparent !important;
    outline: none !important;
    box-shadow: none !important;
}
.forge-signature-clear:hover {
    border-color: var(--forge-text-muted);
    color: var(--forge-text);
}
.forge-signature-clear svg {
    display: block;
    width: 14px; height: 14px;
    fill: currentColor;
    overflow: visible;
}
.forge-signature-hint { font-size: 12px; color: var(--forge-text-subtle); }
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'signature';
    }

    public function getLabel(): string
    {
        return __('Signature', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-signature';
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
            function initSignature(wrap) {
                if (wrap._forgeCanvasInited) return;
                wrap._forgeCanvasInited = true;
                var canvas   = wrap.querySelector('.forge-signature-canvas');
                var input    = wrap.querySelector('input[type="hidden"]');
                var clearBtn = wrap.querySelector('.forge-signature-clear');
                if (!canvas || !input) return;
                var ctx     = canvas.getContext('2d');
                var stroke  = parseFloat(wrap.dataset.stroke || '2');
                var fmt     = wrap.dataset.format || 'png';
                var drawing  = false;
                var leftArea = false;
                var lastW = 0;
                function resize() {
                    var rect  = canvas.getBoundingClientRect();
                    var ratio = window.devicePixelRatio || 1;
                    var cssW  = rect.width  || canvas.offsetWidth;
                    var fallH = parseFloat(canvas.getAttribute('height') || '160');
                    var cssH  = rect.height || canvas.offsetHeight || fallH;
                    if (!cssW || !cssH) { return; } // still hidden — ResizeObserver will retry
                    var snap  = lastW ? canvas.toDataURL() : null;
                    lastW = cssW;
                    canvas.width  = Math.round(cssW * ratio);
                    canvas.height = Math.round(cssH * ratio);
                    ctx.scale(ratio, ratio);
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, cssW, cssH);
                    ctx.strokeStyle = '#1d2327';
                    ctx.lineWidth   = stroke;
                    ctx.lineCap     = 'round';
                    ctx.lineJoin    = 'round';
                    if (snap && snap !== 'data:,') {
                        var img = new Image();
                        img.onload = function () { ctx.drawImage(img, 0, 0, cssW, cssH); };
                        img.src = snap;
                    }
                }
                function pos(e) {
                    var rect = canvas.getBoundingClientRect();
                    var src  = e.touches ? e.touches[0] : e;
                    return { x: src.clientX - rect.left, y: src.clientY - rect.top };
                }
                function start(e) {
                    e.preventDefault();
                    drawing = true;
                    var p = pos(e);
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                }
                function move(e) {
                    if (!drawing) return;
                    e.preventDefault();
                    var p = pos(e);
                    if (leftArea) {
                        ctx.beginPath(); ctx.moveTo(p.x, p.y); leftArea = false;
                    } else {
                        ctx.lineTo(p.x, p.y); ctx.stroke();
                    }
                }
                function end() {
                    if (!drawing) return;
                    drawing = false;
                    input.value = canvas.toDataURL(fmt === 'jpeg' ? 'image/jpeg' : 'image/png');
                }
                canvas.addEventListener('mousedown',  start, { passive: false });
                canvas.addEventListener('mousemove',  move,  { passive: false });
                canvas.addEventListener('mouseleave', function () { leftArea = true; });
                document.addEventListener('mouseup',  end);
                canvas.addEventListener('touchstart', start, { passive: false });
                canvas.addEventListener('touchmove',  move,  { passive: false });
                canvas.addEventListener('touchend',   end);
                if (clearBtn) {
                    clearBtn.addEventListener('click', function () {
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        input.value = '';
                    });
                }
                var ownerForm = canvas.closest('form');
                if (ownerForm) {
                    ownerForm.addEventListener('reset', function () {
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        input.value = '';
                    });
                }
                resize();
                window.addEventListener('resize', resize);
                if (typeof ResizeObserver !== 'undefined') {
                    new ResizeObserver(function (entries) {
                        if (entries[0].contentRect.width > 0) { resize(); }
                    }).observe(canvas);
                }
            }
            (root || document).querySelectorAll('.forge-signature-wrap').forEach(initSignature);
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
        $req       = !empty($config['required']) ? ' data-required="true"' : '';
        $canvas_id = $field_id . '-canvas';
        $height    = (int)($config['canvas_height'] ?? 160);
        $stroke    = (float)($config['stroke_width'] ?? 2);
        $fmt       = $config['export_format'] ?? 'png';

        $inner = '<div class="forge-signature-wrap"' . $req
            . ' data-field-id="' . esc_attr($field_id) . '"'
            . ' data-stroke="' . esc_attr((string)$stroke) . '"'
            . ' data-format="' . esc_attr($fmt) . '">'
            . '<canvas id="' . esc_attr($canvas_id) . '" class="forge-signature-canvas"'
            . ' width="500" height="' . $height . '"'
            . ' style="height:' . $height . 'px"'
            . ' tabindex="0"'
            . ' aria-label="' . esc_attr($config['label'] ?? __('Signature', 'formfabricator')) . '"></canvas>'
            . '<div class="forge-signature-toolbar">'
            . '<button type="button" class="forge-signature-clear"'
            . ' data-canvas="' . esc_attr($canvas_id) . '" title="' . esc_attr__('Clear', 'formfabricator') . '" aria-label="' . esc_attr__('Clear signature', 'formfabricator') . '">'
            . self::ICON_RESET . '</button>'
            . '<span class="forge-signature-hint">' . esc_html__('Sign here', 'formfabricator') . '</span>'
            . '</div>'
            . '<input type="hidden" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '-data"'
            . ' value="' . esc_attr((string)($value ?? '')) . '">'
            . '</div>';

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the sanitized base64 data-URI submitted by the signature canvas. Returns null when the hidden input is absent (isEmpty() treats null as empty).
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        return isset($_POST[$field_id]) ? sanitize_text_field(wp_unslash($_POST[$field_id])) : null;
    }

    /**
     * Signature values are data URIs â€” excluded from the HMAC seal text.
     *
     * @return bool
     */
    public function includeValueInSeal(): bool
    {
        return false;
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
            $format = ($config['export_format'] ?? 'png') === 'jpeg' ? 'jpeg' : 'png';
            if (empty($value) || !self::isSignatureDataUri((string)$value, $format)) {
                $label = $config['label'] ?? __('Signature', 'formfabricator');
                // translators: %s: field label.
                return sprintf(__('%s is a required field.', 'formfabricator'), esc_html($label));
            }
        }
        // A real canvas signature is a few KB; cap well above that so a
        // crafted oversized data URI can't inflate memory/CPU use per
        // submission (extractValue() has no upper bound of its own).
        if (!empty($value) && strlen((string)$value) > 2 * 1024 * 1024) {
            return __('Signature data is too large.', 'formfabricator');
        }
        return true;
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
        return empty($value) ? __('[No entry]', 'formfabricator') : '';
    }

    /**
     * Returns a normalized entry with the materialized signature image.
     *
     * @param string $field_id Field identifier.
     * @param string $label    Field label.
     * @param mixed  $value    Raw submitted value.
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
        $materialized = self::materializeSignature($value);
        return [$field_id => [
            'label'              => $label,
            'type'               => 'signature',
            'value'              => $materialized ? __('[Signature present – see attachment]', 'formfabricator') : __('[No entry]', 'formfabricator'),
            'materialized_files' => $materialized,
        ]];
    }

    /**
     * Override: show only the drawn image, not the placeholder text. The image goes in the media section below the text fields.
     *
     * @param array $field Normalized entry from FieldRegistry::mapSubmission().
     * @return array PDF render descriptor.
     */
    public function pdfData(array $field): array
    {
        $desc = $this->pdf($field);

        foreach ($field['materialized_files'] ?? [] as $file) {
            $mime   = $file['mime'] ?? '';
            $binary = !empty($file['base64']) ? base64_decode($file['base64'], true) : false;
            if ($binary === false || !str_starts_with($mime, 'image/')) {
                continue;
            }
            $desc->attachImage($binary, (string)($file['name'] ?? 'signature.png'), $mime);
        }

        return $desc->build();
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
            'canvas_height' => 200,
            'stroke_width'  => 2,
            'export_format' => 'png',
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
        return [];
    }

    /**
     * Returns the advanced settings schema for the field editor.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        return [
            [
                'key'   => 'canvas_height',
                'type'  => 'number',
                'label' => __('Height (px)', 'formfabricator'),
            ],
            [
                'key'   => 'stroke_width',
                'type'  => 'number',
                'label' => __('Stroke width', 'formfabricator'),
            ],
            [
                'key'    => 'export_format',
                'type'   => 'pill3',
                'label'  => __('File format', 'formfabricator'),
                'values' => ['png', 'jpeg'],
                'labels' => ['PNG', 'JPEG'],
            ],
        ];
    }
}
