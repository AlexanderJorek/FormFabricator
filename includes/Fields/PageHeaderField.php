<?php

/**
 * Multi-page step navigation header (clickable page indicator bar).
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

// Structural field: step count/current state are resolved client-side (front.js); this only emits the container.
class PageHeaderField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/PageHeaderField.css');
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'page-header';
    }

    public function getLabel(): string
    {
        return __('Page step bar', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-list-ol';
    }

    /**
     * Returns false because this field has no required-toggle in the editor.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return false;
    }

    /**
     * Returns true: purely presentational, carries no submitted value.
     *
     * @return bool
     */
    public function skipValidation(): bool
    {
        return true;
    }

    /**
     * Excluded from the {all_fields} email summary — no user-submitted value.
     *
     * @return bool
     */
    public function includeInEmailSummary(): bool
    {
        return false;
    }

    /**
     * Renders the step-nav container. The actual step buttons are built client-side by front.js once the real
     * page count is known.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Unused.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $show_names = !empty($config['show_names']);
        $names      = [];
        if ($show_names && is_array($config['page_names'] ?? null)) {
            // wp_strip_all_tags() here is defense-in-depth, not the sole sanitizer:
            // FormEditor::sanitizeArrayValue() already wp_kses_post()'s every string element of
            // an array-valued config key like this one at save time.
            $names = array_slice(
                array_values(array_map(
                    static fn($n) => wp_strip_all_tags(trim((string)$n)),
                    $config['page_names']
                )),
                0,
                50
            );
        }

        return '<div class="fabricator-page-header"'
            . ' data-show-names="' . ($show_names ? '1' : '0') . '"'
            . ' data-names="' . esc_attr((string)wp_json_encode($names)) . '"'
            . '></div>';
    }

    // Builds the step buttons and wires them to the shared page-nav infra in front.js (data-fabricator-goto-page
    // click delegation + the fabricator:page-change event dispatched by initPageBreaks()).
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/PageHeaderField.js');
    }

    /**
     * Layout-only: produces no output in the submission mapping.
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
        return [];
    }

    /**
     * Maps the field value to a human-readable string for email and PDF output.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     */
    public function map(mixed $value, array $config): string
    {
        return '';
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'       => __('Page step bar', 'formfabricator'),
            'show_names'  => false,
            'page_names'  => [],
            'required'    => false,
            'description' => '',
        ];
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
                'type'  => 'notice',
                'level' => 'info',
                'text'  => __('Shows one step for every page break after this field.', 'formfabricator'),
            ],
            [
                'key'         => 'show_names',
                'type'        => 'bool_seg',
                'label'       => __('Step labels', 'formfabricator'),
                'false_label' => __('Numbers only', 'formfabricator'),
                'true_label'  => __('Numbers + names', 'formfabricator'),
                'rebuild'     => true,
            ],
            [
                'key'        => 'page_names',
                'type'       => 'page_names_list',
                'label'      => __('Page names', 'formfabricator'),
                'hint'       => __('One field per page, in order. Leave a name blank to show just the number for that page.', 'formfabricator'),
                'depends_on' => ['show_names' => true],
            ],
        ];
    }
}
