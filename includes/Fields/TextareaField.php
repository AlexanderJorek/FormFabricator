<?php

/**
 * Multi-line text area input field.
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
 * Multi-line textarea input field.
 */
class TextareaField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'textarea';
    }

    public function getLabel(): string
    {
        return __('Text area', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-align-left';
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
        $req        = !empty($config['required']) ? ' required aria-required="true"' : '';
        $ph         = esc_attr($config['placeholder'] ?? '');
        $rows       = (int)($config['rows'] ?? 5);
        $configured = (int)($config['limit_max'] ?? 0);
        $wlim       = ($config['limit_type'] ?? 'chars') === 'words' && $configured > 0 ? ' data-word-limit="' . $configured . '"' : '';
        $clim       = ($config['limit_type'] ?? 'chars') === 'chars' ? ' maxlength="' . self::clampTextMax($configured) . '"' : '';
        $inner = '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" '
            . 'class="forge-input forge-textarea" rows="' . $rows . '" placeholder="' . $ph . '"'
            . $clim . $wlim . $req . '>'
            . esc_textarea((string)($value ?? ''))
            . '</textarea>';
        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the submitted textarea content sanitized with sanitize_textarea_field() to preserve intentional newlines.
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        return isset($_POST[$field_id]) ? sanitize_textarea_field(wp_unslash($_POST[$field_id])) : '';
    }

    /**
     * Sanitizes a raw textarea value from a group copy array, preserving newlines.
     *
     * @param mixed $raw The raw value from the group copy array.
     */
    public function extractFromRaw(mixed $raw): mixed
    {
        return sanitize_textarea_field(wp_unslash((string)$raw));
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
        // Absolute hard-cap backstop, checked first — applies even under a "words"
        // limit (a single "word" isn't bounded in length) or when no limit_max is
        // configured at all. Runs before any per-value processing below so
        // worst-case cost is bounded up front (defense-in-depth ordering).
        if ($value !== null && $value !== '') {
            $hard = self::validateTextHardCap((string)$value);
            if ($hard !== true) {
                return $hard;
            }
        }
        $max  = (int)($config['limit_max'] ?? 0);
        $type = $config['limit_type'] ?? 'chars';
        if ($max > 0 && $type === 'words' && $value !== null && $value !== '') {
            $count = count(preg_split('/\s+/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY));
            if ($count > $max) {
                // translators: %1$d: maximum word count allowed, %2$d: current word count.
                return sprintf(__('Please enter at most %1$d words (currently: %2$d).', 'formfabricator'), $max, $count);
            }
        }
        // Server-side backstop for the char limit — render() only enforces it
        // via the client-side maxlength attribute, which a direct POST bypasses.
        if ($max > 0 && $type === 'chars' && $value !== null && $value !== '') {
            $length = function_exists('mb_strlen') ? mb_strlen((string)$value) : strlen((string)$value);
            if ($length > $max) {
                // translators: %1$d: maximum character count allowed, %2$d: current character count.
                return sprintf(__('Please enter at most %1$d characters (currently: %2$d).', 'formfabricator'), $max, $length);
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
        return [['rule' => 'textarea-word-limit', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('[data-word-limit]');
                if (!inp || !inp.value.trim()) return null;
                var limit = parseInt(inp.dataset.wordLimit, 10);
                if (!limit) return null;
                var count = inp.value.trim().split(/\s+/).filter(Boolean).length;
                if (count <= limit) return null;
                var _i18n = window.ForgeForms && window.ForgeForms.i18n;
                return ((_i18n && _i18n.word_limit_exceeded) || 'Please enter at most %1$d words (currently: %2$d).')
                    .replace('%1$d', limit).replace('%2$d', count);
            }
            JS]];
    }

    /**
     * Textarea fields expose a plain-text value suitable for PDF preview tokens.
     *
     * @return bool
     */
    public function hasTextPreview(): bool
    {
        return true;
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
            'rows'       => 5,
            'limit_type' => 'chars',
            'limit_max'  => '',
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
                'key'   => 'rows',
                'type'  => 'number',
                'label' => __('Rows', 'formfabricator'),
                'hint'  => __('Visible height of the field (number of text rows)', 'formfabricator'),
            ],
            [
                'key'       => 'limit_type',
                'type'      => 'limit_row',
                'label'     => __('Limit', 'formfabricator'),
                'count_key' => 'limit_max',
            ],
            ]
        );
    }
}
