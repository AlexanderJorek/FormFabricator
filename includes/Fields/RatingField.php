<?php

/**
 * Star rating input field.
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
 * Star rating input field.
 */
class RatingField extends BaseField
{
    /**
     * custom_icon_url is esc_url()'d at render, never treated as HTML — without this,
     * wp_kses_post() entity-encodes "&" at save time, so an icon URL with a query string is
     * stored as "…?a=1&amp;b=2", re-encoded to "&#038;" inside the CSS url() string, and
     * resolves to a broken multi-parameter URL.
     *
     * @return string[]
     */
    protected function plainTextConfigKeys(): array
    {
        return array_merge(parent::plainTextConfigKeys(), ['custom_icon_url']);
    }

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/RatingField.css');
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'rating';
    }

    public function getLabel(): string
    {
        return __('Rating', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-star';
    }

    /**
     * Returns the client-side empty-check function for the required validator.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return ['fn' => "function(f){return !f.querySelector('.fabricator-rating-group input:checked');}"];
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/RatingField.js');
    }

    private const ICONS = [
        'star'    => ['filled' => '★', 'empty' => '☆'],
        'heart'   => ['filled' => '♥', 'empty' => '♡'],
        'circle'  => ['filled' => '●', 'empty' => '○'],
        'diamond' => ['filled' => '◆', 'empty' => '◇'],
    ];

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
        // Clamped: an unclamped mistyped "Number of symbols" value would render that many
        // <span> DOM nodes per form view (CWE-1050, excessive iteration).
        $max       = max(1, min(20, (int)($config['max'] ?? 5)));
        $val       = (float)($value ?? 0);
        $half      = !empty($config['allow_half']);
        $custom    = !empty($config['icon_source']) && !empty($config['custom_icon_url']);
        $icon_key  = $config['icon_type'] ?? 'star';
        $icons     = self::ICONS[$icon_key] ?? self::ICONS['star'];
        $req       = !empty($config['required']) ? ' data-required="true"' : '';
        $custom_url = $custom ? esc_url($config['custom_icon_url'], ['http', 'https']) : '';
        // esc_url() is for href/src attribute context — it deliberately keeps CSS-meaningful
        // characters like ';', '(' and ')' as valid URL characters (e.g. in a query string), so
        // interpolating it unquoted into url(...) lets an edit_forms-capable (not
        // unfiltered_html) user close the url() and append arbitrary CSS declarations. Quoting
        // it as a CSS string and escaping backslash/quote closes that off: inside a quoted CSS
        // string, ';', '(', ')' and whitespace are just literal text, not syntax.
        $custom_url_css = $custom_url !== ''
            ? '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $custom_url) . '"'
            : '';

        $inner = '<div class="fabricator-rating-group" role="group"'
            . ' aria-label="' . esc_attr($config['label'] ?? __('Rating', 'formfabricator')) . '"'
            . ' data-half="' . ($half ? '1' : '0') . '"'
            . $req . '>';

        /* One .fabricator-rating-star container per visible star.
         * Each container holds two invisible click zones (left=half, right=full)
         * and a visual background + filled overlay controlled by JS classes. */
        for ($i = 1; $i <= $max; $i++) {
            $v_full         = $i;
            $v_half         = $i - 0.5;
            $checked_full   = ((float)$val === (float)$v_full) ? ' checked' : '';
            $checked_half   = ((float)$val === (float)$v_half) ? ' checked' : '';
            $glyph          = esc_html($icons['filled']);

            $inner .= '<span class="fabricator-rating-star" data-star="' . $i . '">';

            if ($custom_url) {
                /* Custom image: background image tile, filled overlay clips left half */
                $base_style = 'display:block;width:30px;height:30px;'
                    . 'background:url(' . $custom_url_css . ') center/contain no-repeat;opacity:0.2;';
                $fill_style = 'position:absolute;top:0;left:0;width:30px;height:30px;'
                    . 'background:url(' . $custom_url_css . ') center/contain no-repeat;'
                    . 'pointer-events:none;';
                $inner .= '<span class="fabricator-rating-bg" style="' . esc_attr($base_style) . '"></span>';
                $inner .= '<span class="fabricator-rating-fill" style="' . esc_attr($fill_style) . '"></span>';
            } else {
                $inner .= '<span class="fabricator-rating-bg">' . $glyph . '</span>';
                $inner .= '<span class="fabricator-rating-fill">' . $glyph . '</span>';
            }

            if ($half) {
                $inner .= '<label class="fabricator-rating-zone fabricator-rating-zone-half"'
                    . ' title="' . $v_half . '">'
                    . '<input type="radio" name="' . esc_attr($field_id)
                    . '" value="' . $v_half . '"' . $checked_half . '>'
                    . '</label>';
                $inner .= '<label class="fabricator-rating-zone fabricator-rating-zone-full"'
                    . ' title="' . $v_full . '">'
                    . '<input type="radio" name="' . esc_attr($field_id)
                    . '" value="' . $v_full . '"' . $checked_full . '>'
                    . '</label>';
            } else {
                $inner .= '<label class="fabricator-rating-zone fabricator-rating-zone-full fabricator-rating-zone-full--only"'
                    . ' title="' . $v_full . '">'
                    . '<input type="radio" name="' . esc_attr($field_id)
                    . '" value="' . $v_full . '"' . $checked_full . '>'
                    . '</label>';
            }

            $inner .= '</span>'; /* .fabricator-rating-star */
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
        $base = parent::validate($value, $config);
        if ($base !== true) {
            return $base;
        }
        if ($value === null || $value === '') {
            return true;
        }
        $hard = self::validateTextHardCap((string) $value);
        if ($hard !== true) {
            return $hard;
        }
        if (!is_numeric($value)) {
            return __('Please select a valid rating.', 'formfabricator');
        }
        $max = (float)($config['max'] ?? 5);
        if ($max <= 0) {
            // A blank/zero admin-set "Number of symbols" value would otherwise
            // collapse the valid range to n === 0, making the field impossible
            // to satisfy for any real rating.
            $max = 5;
        }
        $max = min(20, $max); // matches the render()-side clamp.
        $half = !empty($config['allow_half']);
        $n    = (float)$value;
        if ($n < 0 || $n > $max) {
            // translators: %s: maximum allowed rating value.
            return sprintf(__('Please select a rating between 0 and %s.', 'formfabricator'), $max);
        }
        // Scale to whole steps (1 per icon, or 2 when half-icons are allowed) and reject
        // anything off-grid, e.g. 2.3 when only whole or half values are selectable
        $steps = $n * ($half ? 2 : 1);
        if (abs($steps - round($steps)) > 0.0001) {
            return __('Please select a valid rating.', 'formfabricator');
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
        if ($value === null || $value === '') {
            return __('[No entry]', 'formfabricator');
        }
        return $value . ' / ' . (int)($config['max'] ?? 5);
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
            'max'             => 5,
            'icon_type'       => 'star',
            'allow_half'      => false,
            'icon_source'     => false,
            'custom_icon_url' => '',
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
                'key'     => 'max',
                'type'    => 'number',
                'label'   => __('Number of symbols', 'formfabricator'),
                'rebuild' => true,
            ],
            [
                'key'      => 'icon_type',
                'type'     => 'icon_row',
                'label'    => __('Symbol & half values', 'formfabricator'),
                'half_key' => 'allow_half',
                'rebuild'  => true,
                'options'  => [
                    ['value' => 'star',    'label' => '★ ' . __('Star', 'formfabricator')],
                    ['value' => 'heart',   'label' => '♥ ' . __('Heart', 'formfabricator')],
                    ['value' => 'circle',  'label' => '● ' . __('Circle', 'formfabricator')],
                    ['value' => 'diamond', 'label' => '◆ ' . __('Diamond', 'formfabricator')],
                ],
            ],
            [
                'key'         => 'icon_source',
                'type'        => 'bool_seg',
                'label'       => __('Icon source', 'formfabricator'),
                'false_label' => __('Preset', 'formfabricator'),
                'true_label'  => __('Custom image', 'formfabricator'),
                'rebuild'     => true,
            ],
            [
                'key'        => 'custom_icon_url',
                'type'       => 'media_upload',
                'label'      => __('Image', 'formfabricator'),
                'rebuild'    => true,
                'hint'       => __('Square image. For half values the left half is used.', 'formfabricator'),
                'depends_on' => ['icon_source' => true],
            ],
            [
                'type' => 'rating_preview',
            ],
        ];
    }
}
