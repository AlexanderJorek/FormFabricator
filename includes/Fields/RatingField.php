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
 * Star rating input field.
 */
class RatingField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.fabricator-rating-group { display: inline-flex; gap: 2px; }
.fabricator-rating-star {
    position: relative;
    display: inline-block;
    line-height: 1;
    cursor: pointer;
}
.fabricator-rating-bg {
    display: block;
    font-size: 30px;
    color: var(--fabricator-border);
    user-select: none;
    transition: color .1s;
}
.fabricator-rating-fill {
    position: absolute;
    top: 0; left: 0;
    display: block;
    font-size: 30px;
    color: #f0a500;
    pointer-events: none;
    user-select: none;
    white-space: nowrap;
    clip-path: inset(0 100% 0 0);
    transition: clip-path .1s;
}
.fabricator-rating-star--full .fabricator-rating-fill { clip-path: inset(0 0% 0 0); }
.fabricator-rating-star--half .fabricator-rating-fill { clip-path: inset(0 50% 0 0); }
.fabricator-rating-zone {
    position: absolute;
    top: 0;
    height: 100%;
    display: block;
    cursor: pointer;
}
.fabricator-rating-zone input { display: none; }
.fabricator-rating-zone-half { left: 0; width: 50%; }
.fabricator-rating-zone-full { right: 0; width: 50%; }
.fabricator-rating-zone-full--only { left: 0; right: 0; width: 100%; }
CSS;
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
        return <<<'JS'
        function (root) {
            root.querySelectorAll('.fabricator-rating-group').forEach(function (group) {
                var stars = Array.from(group.querySelectorAll('.fabricator-rating-star'));
                function highlight(val) {
                    stars.forEach(function (star) {
                        var n    = parseInt(star.dataset.star, 10);
                        var full = n <= Math.floor(val);
                        var half = !full && val % 1 !== 0 && n === Math.ceil(val);
                        star.classList.toggle('fabricator-rating-star--full', full);
                        star.classList.toggle('fabricator-rating-star--half', half);
                    });
                }
                function restoreChecked() {
                    var checked = group.querySelector('input:checked');
                    highlight(checked ? parseFloat(checked.value) : 0);
                }
                group.addEventListener('mouseover', function (e) {
                    var zone = e.target.closest('.fabricator-rating-zone');
                    if (!zone) return;
                    var radio = zone.querySelector('input[type="radio"]');
                    if (radio) highlight(parseFloat(radio.value));
                });
                group.addEventListener('mouseleave', restoreChecked);
                group.querySelectorAll('.fabricator-rating-zone').forEach(function (zone) {
                    var radio = zone.querySelector('input[type="radio"]');
                    if (!radio) return;
                    zone.addEventListener('click', function () {
                        radio.checked = true;
                        radio.dispatchEvent(new Event('change', { bubbles: true }));
                        restoreChecked();
                    });
                });
                restoreChecked();
            });
        }
        JS;
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
        $max       = (int)($config['max'] ?? 5);
        $val       = (float)($value ?? 0);
        $half      = !empty($config['allow_half']);
        $custom    = !empty($config['icon_source']) && !empty($config['custom_icon_url']);
        $icon_key  = $config['icon_type'] ?? 'star';
        $icons     = self::ICONS[$icon_key] ?? self::ICONS['star'];
        $req       = !empty($config['required']) ? ' data-required="true"' : '';
        $custom_url = $custom ? esc_url($config['custom_icon_url'], ['http', 'https']) : '';

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
                    . 'background:url(' . esc_url($custom_url) . ') center/contain no-repeat;opacity:0.2;';
                $fill_style = 'position:absolute;top:0;left:0;width:30px;height:30px;'
                    . 'background:url(' . esc_url($custom_url) . ') center/contain no-repeat;'
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
