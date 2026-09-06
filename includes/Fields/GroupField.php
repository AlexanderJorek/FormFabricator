<?php

/**
 * Section group field used to visually group other fields.
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
 * Section/group container field that wraps child fields.
 */
class GroupField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/GroupField.css');
    }

    /**
     * Excludes the group header from the {all_fields} email block.
     *
     * @return bool
     */
    public function includeInEmailSummary(): bool
    {
        return false;
    }

    /**
     * Expands a group's (possibly repeated) child values into flat, individually labeled entries for the normalized submission map.
     *
     * @param string $field_id Group field id (unused; children get their own keys).
     * @param string $label    Group label (unused; children use their own labels).
     * @param mixed  $value    Per-copy child values, see inline comment below.
     * @param array  $config   Group field configuration (includes 'children').
     * @param array  $context  Normalization context passed through to child handlers.
     * @return array Flat map of child_id (or child_id_copy_N) => ['label'=>, 'value'=>].
     */
    public function mapNormalized(
        string $field_id,
        string $label,
        mixed $value,
        array $config,
        array $context
    ): array {
        /* $value is [ copy_index => [ child_id => sanitized_val, ... ], ... ]
           as assembled by FormProcessor for group fields. */
        if (!is_array($value) || empty($value)) {
            return [];
        }

        $children   = $config['children'] ?? [];
        // Defense-in-depth cap; this method multiplies work by count(children) per copy.
        if (count($value) > 100) {
            $value = array_slice($value, 0, 100, true);
        }
        $copy_count = count($value);
        $mapped     = [];

        foreach ($value as $copy_idx => $copy_data) {
            if (!is_array($copy_data)) {
                continue;
            }
            foreach ($children as $child_cfg) {
                $child_id    = $child_cfg['id']   ?? '';
                $child_type  = $child_cfg['type'] ?? '';
                $child_label = $child_cfg['label'] ?? $child_id;

                if (!$child_id || !$child_type) {
                    continue;
                }

                $handler = \FabricatorForms\Fields\FieldRegistry::get($child_type);
                if (!$handler) {
                    continue;
                }

                $child_value = $copy_data[$child_id] ?? null;

                /* For repeating groups (multiple copies) suffix key and label
                   so each copy's entry has a unique key in $mapped. */
                $map_key   = $copy_count > 1 ? $child_id . '_copy_' . $copy_idx : $child_id;
                /* A child hidden by its own conditional logic never ran validate(), so skip it
                   before its handler materializes anything (see FieldRegistry::mapSubmission()). */
                if (isset($context['skip_ids'][$map_key]) || isset($context['skip_ids'][$child_id])) {
                    continue;
                }
                $map_label = $copy_count > 1 ? $child_label . ' (' . $copy_idx . ')' : $child_label;

                $entries = $handler->mapNormalized(
                    $map_key,
                    $map_label,
                    $child_value,
                    $child_cfg,
                    $context
                );
                foreach ($entries as $key => $entry) {
                    $mapped[$key] = $entry;
                }
            }
        }

        return $mapped;
    }

    public function getType(): string
    {
        return 'group';
    }

    public function getLabel(): string
    {
        return __('Field group', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-layer-group';
    }

    /**
     * Returns true: this field is a group container whose children FormRenderer recurses into.
     *
     * @return bool
     */
    public function isGroupContainer(): bool
    {
        return true;
    }

    /**
     * Returns true — group fields DO have a settings panel in the builder
     * (used to configure the child field list and repeat behavior).
     *
     * @return bool
     */
    public function hasSettingsPanel(): bool
    {
        return true;
    }

    /**
     * Returns false because group fields have no required-toggle in the editor.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return false;
    }

    /**
     * Returns the opening wrapper tag for this group. Children are injected by FormRenderer — this just provides the container.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @return string Opening HTML tag.
     */
    public function openTag(array $config, string $field_id): string
    {
        $desc = $config['description'] ?? '';
        $desc_html = $desc !== ''
            ? '<p class="fabricator-field-description">' . esc_html($desc) . '</p>'
            : '';
        return '<div class="fabricator-field-group" data-field-id="' . esc_attr($field_id) . '">'
            . $desc_html;
    }

    /**
     * Returns the data-conditions attribute string for the group's outer row wrapper.
     *
     * @param array $config Field configuration.
     */
    public function rowCondAttr(array $config): string
    {
        if (empty($config['conditions']['rules'])) {
            return '';
        }
        return ' data-conditions="' . esc_attr(wp_json_encode($config['conditions'])) . '"';
    }

    /**
     * Returns the closing wrapper tag for this group.
     *
     * @return string Closing HTML tag.
     */
    public function closeTag(): string
    {
        return '</div>';
    }

    /**
     * Renders the field HTML. Fallback only — not called during normal rendering; FormRenderer uses openTag() and closeTag() directly to inject child fields.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        return $this->openTag($config, $field_id) . $this->closeTag();
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
            'label'       => __('Field group', 'formfabricator'),
            'description' => '',
            'required'    => false,
            'children'    => [],
            'conditions'  => ['action' => 'show', 'match' => 'all', 'rules' => []],
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
                'key'   => 'description',
                'type'  => 'text',
                'label' => __('Description', 'formfabricator'),
            ],
        ];
    }
}
