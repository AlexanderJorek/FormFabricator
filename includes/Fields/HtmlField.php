<?php

/**
 * Static HTML content field for layout and display purposes.
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
 * Static HTML content field (non-interactive).
 */
class HtmlField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/HtmlField.css');
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'html';
    }

    public function getLabel(): string
    {
        return __('HTML Block', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-code';
    }

    /**
     * Returns false because HTML fields have no required-toggle in the editor.
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
     * Already passed through Utils\HtmlSanitizer::sanitize() at save/render time, so MailSender injects it as-is.
     *
     * @return bool
     */
    public function rawEmailHtml(): bool
    {
        return true;
    }

    /* No sanitizeConfigValue() override: BaseField's sends every non-plain-text key, html_content included, through
       Utils\HtmlSanitizer::sanitize(), the one rule set every HTML-capable text in a form shares. */

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $html = \FabricatorForms\Utils\HtmlSanitizer::sanitize($config['html_content'] ?? '');
        return '<div class="fabricator-field fabricator-field--html" data-field-id="'
            . esc_attr($field_id) . '">'
            . $html
            . '</div>';
    }

    /**
     * Returns the sanitized HTML content as a single labeled entry.
     *
     * @param string $field_id Field identifier.
     * @param string $label    Field label.
     * @param mixed  $value    Raw submitted value (unused).
     * @param array  $config   Field configuration.
     * @param array  $context  Submission context (unused).
     * @return array<string, array>
     */
    public function mapNormalized(
        string $field_id,
        string $label,
        mixed $value,
        array $config,
        array $context
    ): array {
        // Excluding here skips it from both PDF and email — they both read from $mapped.
        if (!($config['show_in_output'] ?? true)) {
            return [];
        }
        $html = \FabricatorForms\Utils\HtmlSanitizer::sanitize($config['html_content'] ?? '');
        if ($html === '') {
            return [];
        }
        return [$field_id => [
            'label' => $label ?: null,
            'type'  => 'html',
            'value' => $html,
        ]];
    }

    /**
     * The content's bytes, when it goes into the output: mapNormalized() puts it into the PDF.
     *
     * @param array $config Field configuration.
     * @return int
     */
    public function configTextBytes(array $config): int
    {
        $html = $config['html_content'] ?? '';
        return ($config['show_in_output'] ?? true) && is_string($html) ? strlen($html) : 0;
    }

    /**
     * Override: the stored value is already HTML, so it must not be escaped. If the form author left the
     * label blank, skip the label row entirely.
     *
     * @param array $field Normalized entry from FieldRegistry::mapSubmission().
     * @return array PDF render descriptor.
     */
    public function pdfData(array $field): array
    {
        // mPDF fetches any non-local <img src>/CSS url() server-side with no host allow-list of its own —
        // this field's HTML is form-builder-authored and unrestricted, so strip remote refs to avoid SSRF.
        $html = \FabricatorForms\Utils\HtmlSanitizer::stripRemoteResourcesForPdf((string)($field['value'] ?? ''));
        $desc = $this->pdf($field)->rawHtml($html, true);
        if (empty($field['label'])) {
            $desc->unlabeled();
        }
        return $desc->build();
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'          => __('HTML Block', 'formfabricator'),
            'html_content'   => '<p>' . esc_html__('Text here', 'formfabricator') . '</p>',
            'required'       => false,
            'description'    => '',
            'show_in_output' => true,
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
                'key'     => 'show_in_output',
                'type'    => 'checkbox',
                'label'   => __('Show in mail/PDF', 'formfabricator'),
                // Fallback for configs missing this key, to match mapNormalized()'s `?? true`.
                'default' => true,
            ],
            [
                'key'   => 'html_content',
                'type'  => 'html_editor',
                'label' => __('HTML content', 'formfabricator'),
                // GDPR Art. 13, like RatingField's custom icon: remote content is fetched by every visitor's browser.
                'hint'  => __('Images, videos or other content from another site are fetched by every visitor, which hands that site their IP address — use files from your Media Library, or name that site in your privacy policy.', 'formfabricator'),
            ],
        ];
    }
}
