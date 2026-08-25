<?php

/**
 * Page break marker for multi-page form navigation.
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
 * Page-break field that splits multi-page forms.
 */
class PageBreakField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-form-page { display: none; }
.forge-form-page.forge-page-active { display: block; }
.forge-page-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: var(--forge-gap);
    padding: 14px 0 0;
    border-top: 1px solid var(--forge-border);
    gap: 10px;
}
.forge-page-nav--top {
    display: flex;
    align-items: center;
    border-top: none;
    padding: 0;
    margin: 0 0 var(--forge-gap);
}
.forge-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    border-radius: var(--forge-radius);
    border: 1px solid var(--forge-border-input);
    background: var(--forge-bg);
    color: var(--forge-text-muted);
    font-size: 14px;
    font-family: var(--forge-font);
    cursor: pointer;
    transition: background .1s, border-color .1s;
}
.forge-btn:hover {
    background: var(--forge-bg-subtle);
    border-color: var(--forge-text-subtle);
}
.forge-btn-next {
    margin-left: auto;
    background: var(--forge-accent);
    border-color: var(--forge-accent);
    color: #fff;
    font-weight: 600;
}
.forge-btn-next:hover,
.forge-btn-next:focus,
.forge-btn-next:focus-visible {
    background: var(--forge-accent-dark);
    border-color: var(--forge-accent-dark);
    color: #fff;
    outline: none;
}
.forge-page-nav--top .forge-btn-prev {
    border-color: transparent;
    background: transparent;
    color: var(--forge-accent);
    padding-left: 0; padding-right: 0;
    font-size: 13px;
}
.forge-page-nav--top .forge-btn-prev:hover {
    background: transparent;
    border-color: transparent;
    color: var(--forge-accent-dark);
    text-decoration: underline;
}
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'pagebreak';
    }

    public function getLabel(): string
    {
        return __('Page break', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-file';
    }

    /**
     * Returns true: this field acts as a page-break marker, not a regular input.
     *
     * @return bool
     */
    public function isPageBreak(): bool
    {
        return true;
    }

    /**
     * Returns false because page-break fields have no settings panel in the builder.
     *
     * @return bool
     */
    public function hasSettingsPanel(): bool
    {
        return false;
    }

    /**
     * Returns false because page-break fields have no required-toggle in the editor.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return false;
    }

    /**
     * Returns true if validation should be skipped for this field type.
     *
     * @return bool
     */
    public function skipValidation(): bool
    {
        return true;
    }

    /**
     * Page-break entries are excluded from the {all_fields} email summary.
     *
     * @return bool
     */
    public function includeInEmailSummary(): bool
    {
        return false;
    }

    /**
     * Renders the page-transition HTML emitted at this break point. Closes the current page <div>, emits
     * bottom nav (prev + next), opens the next page <div>, and adds a top-of-page prev button. The $page
     * argument is the 1-based index of the page being opened (already incremented by FormRenderer).
     *
     * @param array $config Field configuration.
     * @param int   $page   Index of the page being opened (1 = first page after a break).
     * @return string HTML for the page transition.
     */
    public function renderBreak(array $config, int $page): string
    {
        $prev_label = esc_html($config['prev_btn'] ?? __('← Back', 'formfabricator'));
        $next_label = esc_html($config['next_btn'] ?? __('Next →', 'formfabricator'));
        $prev_btn   = $page > 1
            ? '<button type="button" class="forge-btn forge-btn-prev">' . $prev_label . '</button>'
            : '<span></span>';
        $next_btn   = '<button type="button" class="forge-btn forge-btn-next">' . $next_label . '</button>';
        return '<div class="forge-page-nav">' . $prev_btn . $next_btn . '</div>'
            . '</div>'
            . '<div class="forge-form-page" data-page="' . $page . '">'
            . '<div class="forge-page-nav forge-page-nav--top">'
            . '<button type="button" class="forge-btn forge-btn-prev">' . $prev_label . '</button>'
            . '</div>';
    }

    /**
     * Renders the field HTML. Not called during normal form rendering — FormRenderer calls renderBreak()
     * instead. Provided as a fallback for contexts that call render() generically.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        return '';
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
            'label'       => __('Page break', 'formfabricator'),
            'prev_btn'    => __('← Back', 'formfabricator'),
            'next_btn'    => __('Next →', 'formfabricator'),
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
                'key'   => 'prev_btn',
                'type'  => 'text',
                'label' => __('Back button', 'formfabricator'),
            ],
            [
                'key'   => 'next_btn',
                'type'  => 'text',
                'label' => __('Next button', 'formfabricator'),
            ],
        ];
    }
}
