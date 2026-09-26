<?php

/**
 * Email address input field with format validation.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.7
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
 * Email address input field with validation.
 */
class EmailField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'email';
    }

    public function getLabel(): string
    {
        return __('Email', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-envelope';
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [
            [
                'rule' => 'email',
                'fn'   => self::readFieldAsset('assets/js/fields/EmailField.email.js'),
            ],
        ];
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
        $attrs = $this->inputAttrs($config, $field_id, 'email', ['value' => esc_attr((string)($value ?? ''))]);
        // filter_mode/filter_patterns stay out of markup (may encode partner/competitor domains) — validate() below is the sole enforcement.
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
        if ($value === null || $value === '') {
            return true;
        }
        $hard = self::validateTextHardCap((string) $value);
        if ($hard !== true) {
            return $hard;
        }
        $v = strtolower(trim((string)$value));
        if ($config['validate_format'] ?? true) {
            if (!is_email($v)) {
                return __('Please enter a valid email address.', 'formfabricator');
            }
        }
        $mode     = $config['filter_mode']     ?? '';
        $patterns = trim((string)($config['filter_patterns'] ?? ''));
        if ($mode !== '' && $patterns !== '') {
            $list    = array_filter(array_map('trim', preg_split('/[\r\n;]+/', $patterns)));
            $matched = false;
            foreach ($list as $pat) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote(strtolower($pat), '/')) . '$/';
                $hit   = preg_match($regex, $v);
                // preg_match() returning false is an engine failure, not "no match". Reject in both modes: counting it
                // as a match failed open for an allow-list, counting it as a miss would fail open for a block-list.
                if ($hit === false) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms EmailField: filter pattern failed to evaluate (preg error '
                        . preg_last_error_msg() . '), rejecting the address so the filter fails closed.'
                    );
                    return __('This email address is not allowed.', 'formfabricator');
                }
                if ($hit === 1) {
                    $matched = true;
                    break;
                }
            }
            if ($mode === 'allow' && !$matched) {
                return __('This email address is not allowed.', 'formfabricator');
            }
            if ($mode === 'block' && $matched) {
                return __('This email address is not allowed.', 'formfabricator');
            }
        }
        return true;
    }

    /**
     * Email fields expose a plain-text value suitable for PDF preview tokens.
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
            'validate_format' => true,
            'filter_mode'     => '',
            'filter_patterns' => '',
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
                'key'   => 'validate_format',
                'type'  => 'checkbox',
                'label' => __('Validate email format', 'formfabricator'),
            ],
            ]
        );
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
                'key'    => 'filter_mode',
                'type'   => 'pill3',
                'label'  => __('Email filter', 'formfabricator'),
                'values' => ['', 'allow', 'block'],
                'labels' => [__('Off', 'formfabricator'), __('Allowed', 'formfabricator'), __('Blocked', 'formfabricator')],
            ],
            [
                'key'        => 'filter_patterns',
                'type'       => 'textarea',
                'label'      => __('Pattern (one per line or separated by ;)', 'formfabricator'),
                'hint'       => '*no-reply* · *.outlook.com · user@example.com',
                'depends_on' => ['key' => 'filter_mode', 'not' => ''],
            ],
        ];
    }
}
