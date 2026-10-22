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
 * @version   1.1.1
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
     * The group's only entries of its own are its box's start and end (show as box), which the mail draws as the box;
     * its children list under their own types.
     *
     * @return bool
     */
    public function includeInEmailSummary(): bool
    {
        return true;
    }

    /**
     * Whether the group is shown as a box: on the page, and around its children in the PDF and the mail.
     *
     * @param array $config Field configuration.
     * @return bool
     */
    private static function boxed(array $config): bool
    {
        return !empty($config['boxed']);
    }

    /**
     * The box's title: the group's label, unless it is hidden.
     *
     * @param array $config Field configuration.
     * @return string
     */
    private static function boxTitle(array $config): string
    {
        return empty($config['hide_label']) ? trim((string) ($config['label'] ?? '')) : '';
    }

    /**
     * A boxed group's start entry opens its box, its end entry closes it.
     *
     * @param array $entry The entry (mapNormalized()).
     * @return string
     */
    public function framePart(array $entry): string
    {
        return match ($entry['group_part'] ?? '') {
            'begin' => 'open',
            'end'   => 'close',
            default => '',
        };
    }

    /**
     * A boxed group's start and end: a titled box around the children, with empty cells, as nothing was answered.
     *
     * @param array $field Normalized entry from mapNormalized().
     * @return array PDF render descriptor.
     */
    public function pdfData(array $field): array
    {
        if ($this->framePart($field) === 'open') {
            $title = is_string($field['label'] ?? null) ? $field['label'] : '';
            return $this->pdf($field)->text('')->unlabeled()->opensFrame($title)->build();
        }
        return $this->pdf($field)->text('')->unlabeled()->closesFrame()->build();
    }

    /**
     * Expands a group's child values into flat, individually labeled entries for the normalized submission map.
     *
     * @param string $field_id Group field id (unused; children get their own keys).
     * @param string $label    Group label (unused; children use their own labels).
     * @param mixed  $value    Child values keyed by child id, as FormProcessor reads them.
     * @param array  $config   Group field configuration (includes 'children').
     * @param array  $context  Normalization context passed through to child handlers.
     * @return array Flat map of child_id => ['label'=>, 'value'=>].
     */
    public function mapNormalized(
        string $field_id,
        string $label,
        mixed $value,
        array $config,
        array $context
    ): array {
        // One set of children per group. An empty list means display children only, which still map for mail/PDF.
        if (!is_array($value)) {
            return [];
        }

        $mapped = [];
        foreach (($config['children'] ?? []) as $child_cfg) {
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

            /* A child hidden by its own conditional logic never ran validate(), so skip it
               before its handler materializes anything (see FieldRegistry::mapSubmission()). */
            if (isset($context['skip_ids'][$child_id])) {
                continue;
            }

            $entries = $handler->mapNormalized($child_id, $child_label, $value[$child_id] ?? null, $child_cfg, $context);
            foreach (\FabricatorForms\Fields\FieldRegistry::withHiddenLabel($entries, $child_cfg) as $key => $entry) {
                $mapped[$key] = $entry;
            }
        }

        // Shown as a box: its children between a start and an end, which mail and PDF draw as a titled box. No box
        // around nothing, when every child was hidden.
        if (!self::boxed($config) || $mapped === []) {
            return $mapped;
        }
        $title = self::boxTitle($config);
        return [
            $field_id . '_begin' => [
                'label'      => $title,
                'type'       => 'group',
                'value'      => '',
                'group_part' => 'begin',
            ],
        ] + $mapped + [
            // Labelled, so the verification page can name where the group ends; mail and PDF close the box instead.
            $field_id . '_end' => [
                // translators: %s: the group's label.
                'label'      => sprintf(__('End of %s', 'formfabricator'), $title !== '' ? $title : $label),
                'type'       => 'group',
                'value'      => '',
                'group_part' => 'end',
            ],
        ];
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
    public function openTagHtml(array $config, string $field_id): string
    {
        $desc = $config['description'] ?? '';
        $desc_html = $desc !== ''
            ? '<p class="fabricator-field-description">' . esc_html($desc) . '</p>'
            : '';
        if (!self::boxed($config)) {
            return '<div class="fabricator-field-group" data-field-id="' . esc_attr($field_id) . '">'
                . $desc_html;
        }
        // Boxed like the direct debit mandate, headed by the label as the mandate is by its title.
        $title = self::boxTitle($config);
        return '<div class="fabricator-field-group fabricator-field-group--boxed" data-field-id="' . esc_attr($field_id) . '">'
            . ($title !== '' ? '<h3 class="fabricator-group-title">' . esc_html($title) . '</h3>' : '')
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
        return ' data-conditions="' . esc_attr(\FabricatorForms\Utils\Cast::jsonForAttribute($config['conditions'])) . '"';
    }

    /**
     * Returns the closing wrapper tag for this group.
     *
     * @return string Closing HTML tag.
     */
    public function closeTagHtml(): string
    {
        return '</div>';
    }

    /**
     * Fallback only: FormRenderer uses openTagHtml() and closeTagHtml() to place the children.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        return $this->openTagHtml($config, $field_id) . $this->closeTagHtml();
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
            'boxed'       => false,
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
            [
                'key'   => 'boxed',
                'type'  => 'checkbox',
                'label' => __('Show as box', 'formfabricator'),
                'hint'  => __('A box around the group\'s fields, headed by its label, on the page, in the PDF and in the email, like the direct debit mandate.', 'formfabricator'),
            ],
        ];
    }
}
