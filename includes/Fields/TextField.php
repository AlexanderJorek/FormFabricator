<?php

/**
 * Single-line text input field.
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
 * Single-line text input field.
 */
class TextField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'text';
    }

    public function getLabel(): string
    {
        return __('Text', 'formfabricator');
    }
    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-font';
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
        $attrs      = $this->inputAttrs($config, $field_id, 'text', ['value' => (string)($value ?? '')]);
        $configured = (int)($config['limit_max'] ?? 0);
        if (($config['limit_type'] ?? 'chars') === 'chars') {
            $attrs .= ' maxlength="' . self::clampTextMax($configured) . '"';
        }
        if ($configured > 0 && ($config['limit_type'] ?? 'chars') === 'words') {
            $attrs .= ' data-word-limit="' . $configured . '"';
        }
        return $this->wrap($field_id, $config, '<input' . $attrs . '>');
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
        // Hard-cap backstop, checked first since a "words" limit doesn't bound single-word length.
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
        // Server-side backstop for the char limit — render() only sets a client-side
        // maxlength attribute, which a direct POST can bypass
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
        return [['rule' => 'text-word-limit', 'fn' => self::readFieldAsset('assets/js/fields/TextField.text-word-limit.js')]];
    }

    /**
     * Text fields expose a plain-text value suitable for PDF preview tokens.
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
                'key'       => 'limit_type',
                'type'      => 'limit_row',
                'label'     => __('Limit', 'formfabricator'),
                'count_key' => 'limit_max',
            ],
            ]
        );
    }
}
