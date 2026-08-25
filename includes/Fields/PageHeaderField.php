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

// Structural field: renders a step/breadcrumb bar for multi-page forms. The number of steps and the
// current/reachable state are resolved client-side (front.js) from the actual .fabricator-form-page count, since
// that isn't known at render time relative to this field's position. This field only emits the container plus
// the admin-configured display mode and optional page names.
class PageHeaderField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.fabricator-page-steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin: 0 0 var(--fabricator-gap);
    flex-wrap: wrap;
}
.fabricator-page-step {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid var(--fabricator-border-input);
    background: var(--fabricator-bg);
    color: var(--fabricator-text-muted);
    border-radius: var(--fabricator-radius);
    padding: 6px 12px;
    font-size: 13px;
    font-family: var(--fabricator-font);
    cursor: pointer;
    transition: background .1s, border-color .1s, color .1s;
}
.fabricator-page-step:disabled {
    cursor: not-allowed;
    opacity: .5;
}
.fabricator-page-step.is-reachable:not(:disabled):hover {
    border-color: var(--fabricator-accent);
    color: var(--fabricator-accent);
}
.fabricator-page-step.is-active {
    background: var(--fabricator-accent);
    border-color: var(--fabricator-accent);
    color: #fff;
    font-weight: 600;
}
.fabricator-page-step-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(0, 0, 0, .08);
    font-size: 11px;
}
.fabricator-page-step.is-active .fabricator-page-step-num {
    background: rgba(255, 255, 255, .25);
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
     * Excluded from the {all_fields} email summary â€” no user-submitted value.
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
            // wp_strip_all_tags() is a server-side backstop, not just belt-and-braces:
            // FormEditor::sanitizeFields() only sanitizes string-valued config keys,
            // so this array-valued one reaches here unsanitized. array_slice caps it
            // to a generous ceiling, consistent with BaseField's other array caps
            // (capRawArray/TEXT_FIELD_HARD_CAP) — a form has no legitimate use for
            // more page names than it has pages, and pages are already bounded.
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
        return <<<'JS'
        function (root) {
            root.querySelectorAll('.fabricator-page-header').forEach(function (headerEl) {
                if (headerEl.dataset.fabricatorStepsInit) return;
                headerEl.dataset.fabricatorStepsInit = '1';

                var form = headerEl.closest('.fabricator-form');
                if (!form) return;

                var pages = Array.from(form.querySelectorAll('.fabricator-form-page'));
                var total = pages.length;
                if (!total) return;

                /* If a page break sits before this field, it renders inside a
                 * later page div than 0. Find that div now, before front.js's
                 * initPageBreaks() relocates this field's row to always sit
                 * before page 0 — this must run first (and does: field inits
                 * run before initPageBreaks()) so headerEl is still in its
                 * original position here. Pages before that native page aren't
                 * this bar's concern at all: they don't get a step, and the bar
                 * stays hidden until the visitor actually reaches its own page.
                 * That lets an admin put a small entry/splash page ahead of the
                 * bar without it being counted as "step 1". */
                var containingPage = headerEl.closest('.fabricator-form-page');
                var nativePage     = containingPage ? pages.indexOf(containingPage) : 0;
                if (nativePage === -1) nativePage = 0;
                var stepCount = Math.max(0, total - nativePage);

                var showNames = headerEl.dataset.showNames === '1';
                var names     = [];
                try { names = JSON.parse(headerEl.dataset.names || '[]'); } catch (e) { names = []; }

                var stepsWrap = document.createElement('div');
                stepsWrap.className = 'fabricator-page-steps';
                for (var i = 0; i < stepCount; i++) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'fabricator-page-step';
                    btn.setAttribute('data-fabricator-goto-page', String(nativePage + i));

                    var numSpan = document.createElement('span');
                    numSpan.className = 'fabricator-page-step-num';
                    numSpan.textContent = String(i + 1);
                    btn.appendChild(numSpan);

                    if (showNames && names[i]) {
                        var nameSpan = document.createElement('span');
                        nameSpan.className = 'fabricator-page-step-name';
                        // textContent, not innerHTML — names[i] is server-sanitized
                        // (wp_strip_all_tags) but this is the real XSS boundary,
                        // not the PHP-side stripping.
                        nameSpan.textContent = String(names[i]);
                        btn.appendChild(nameSpan);
                    }

                    stepsWrap.appendChild(btn);
                }
                headerEl.appendChild(stepsWrap);

                function update(idx, furthest) {
                    headerEl.style.display = idx >= nativePage ? '' : 'none';
                    Array.from(stepsWrap.children).forEach(function (btn, i) {
                        var pageIdx = nativePage + i;
                        btn.classList.toggle('is-active', pageIdx === idx);
                        btn.classList.toggle('is-reachable', pageIdx <= furthest);
                        btn.disabled = pageIdx > furthest;
                    });
                }

                form.addEventListener('fabricator:page-change', function (e) {
                    update(e.detail.index, e.detail.furthest);
                });
                update(0, 0);
            });
        }
        JS;
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
