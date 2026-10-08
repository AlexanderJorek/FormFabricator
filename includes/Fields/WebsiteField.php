<?php

/**
 * Website URL input field.
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
 * URL/website input field with validation.
 */
class WebsiteField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'website';
    }

    public function getLabel(): string
    {
        return __('Website', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-globe';
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'website-url', 'fn' => self::readFieldAsset('assets/js/fields/WebsiteField.website-url.js')]];
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
        $ph    = $config['placeholder'] ?? 'https://';
        $attrs = $this->inputAttrs($config, $field_id, 'url', ['value' => (string)($value ?? ''), 'placeholder' => $ph]);
        if (!empty($config['validate_url'])) {
            $attrs .= ' data-validate-url="1"';
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
        if ($value !== null && $value !== '') {
            $hard = self::validateTextHardCap((string) $value);
            if ($hard !== true) {
                return $hard;
            }
        }
        if ($value !== null && $value !== '' && !empty($config['validate_url'])) {
            // Invisible characters (bidi overrides, zero-width, non-breaking spaces) and invalid UTF-8 are refused:
            // they would pass as letters below, and have no place in a URL.
            if (preg_match('/[\p{Cc}\p{Cf}\p{Z}]/u', (string) $value) !== 0) {
                return __('Please enter a valid URL (e.g. https://example.com).', 'formfabricator');
            }
            // FILTER_VALIDATE_URL knows ASCII only, so on its own it refuses https://müller.de, which the browser's check
            // accepts. Each non-ASCII character stands in as a plain letter for the check; the value itself stays as typed.
            $ascii = (string) preg_replace('/[^\x00-\x7F]/u', 'a', (string) $value);
            if (!filter_var($ascii, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $ascii)) {
                return __('Please enter a valid URL (e.g. https://example.com).', 'formfabricator');
            }
        }
        return true;
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), ['validate_url' => true]);
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
                'key'   => 'validate_url',
                'type'  => 'checkbox',
                'label' => __('Validate URL format', 'formfabricator'),
            ],
            ]
        );
    }
}
