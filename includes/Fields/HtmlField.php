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
     * Presence in the mapped data (and thus PDF/email) is instead gated upstream by mapNormalized().
     *
     * @return bool
     */
    public function includeInEmailSummary(): bool
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
     * Maps the field value to a human-readable string for email and PDF output.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return string Human-readable representation.
     */
    public function map(mixed $value, array $config): string
    {
        return wp_strip_all_tags($config['html_content'] ?? '');
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
        $html = self::stripRemoteResourcesForPdf((string)($field['value'] ?? ''));
        $desc = $this->pdf($field)->rawHtml($html, true);
        if (empty($field['label'])) {
            $desc->unlabeled();
        }
        return $desc->build();
    }

    /**
     * Strips remote <img src>/CSS url() refs before mPDF fetches them server-side, keeping same-origin/data: only.
     *
     * @param string $html Already wp_kses()-sanitized HTML (\FabricatorForms\Utils\HtmlSanitizer::sanitize() output).
     * @return string HTML with disallowed remote resource references stripped.
     */
    private static function stripRemoteResourcesForPdf(string $html): string
    {
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';

        $is_allowed = static function (string $url) use ($home_host): bool {
            $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url === '' || str_starts_with($url, '#')) {
                return true;
            }
            if (stripos($url, 'data:') === 0) {
                return true;
            }
            // Scheme-less: only relative paths with no ../ traversal or absolute-path escape allowed.
            if (!preg_match('#^([a-z][a-z0-9+.\-]*:)?//#i', $url) && !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
                // A relative reference is made of URL characters and nothing else. Treating whatever could not be
                // parsed as a harmless relative path failed open: wp_kses re-encodes the quotes of url("…") inside a
                // double-quoted style attribute, so "&quot;http://169.254.169.254/&quot;" arrived here looking like a
                // path while mPDF decodes the entities again before fetching it.
                // No quote or bracket characters either: mPDF strips those around a CSS url() value, so a decoded
                // "'http://host/'" would become a remote URL again after this check had passed it as a path.
                if (preg_match('#^[A-Za-z0-9._~!$&*+,;=:@/?%\#-]+$#', $url) !== 1) {
                    return false;
                }
                $decoded_path = rawurldecode($url);
                $is_absolute  = str_starts_with($decoded_path, '/')
                    || str_starts_with($decoded_path, chr(92))
                    || preg_match('#^[A-Za-z]:[\\\\/]#', $decoded_path) === 1;
                $has_traversal = preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $decoded_path) === 1;
                return !$is_absolute && !$has_traversal;
            }
            $host = wp_parse_url($url, PHP_URL_HOST);
            if ($host === null || $home_host === '' || strcasecmp($host, $home_host) !== 0) {
                return false;
            }
            // Host alone let http://own-host:6379/ through, so mPDF could be steered at internal ports on this
            // machine. Only web schemes, and only the site's own port (or the scheme default when it sets none).
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['', 'http', 'https'], true)) {
                return false;
            }
            $port      = wp_parse_url($url, PHP_URL_PORT);
            $home_port = wp_parse_url(home_url(), PHP_URL_PORT);
            if ($port === null || $port === $home_port) {
                return true;
            }
            if ($home_port !== null) {
                return false;
            }
            if ($scheme === 'https') {
                return $port === 443;
            }
            return $scheme === 'http' ? $port === 80 : in_array($port, [80, 443], true);
        };

        $html = preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function ($m) use ($is_allowed) {
                // (?:(?!\1).)*, not [^"']*: a src="…'…" matched neither quote style, so it kept its remote URL.
                return preg_replace_callback(
                    '/\ssrc\s*=\s*(["\'])((?:(?!\1).)*)\1/is',
                    static function ($sm) use ($is_allowed) {
                        return $is_allowed($sm[2]) ? $sm[0] : '';
                    },
                    $m[0]
                );
            },
            $html
        );

        $html = preg_replace_callback(
            '/\bstyle\s*=\s*(["\'])((?:(?!\1).)*)\1/is',
            static function ($m) use ($is_allowed) {
                // One branch per quoting style: the single optional-quote form skipped url("…'…") and kept it.
                // The unquoted branch runs to the closing parenthesis and no sooner — excluding "(" there let
                // url(http://host/x(y) match nothing at all, so it passed through untouched and mPDF fetched it.
                // The parenthesis itself is optional so an unterminated url( is read and refused as well: every
                // url( in the declaration has to come back out either checked or replaced.
                $style = preg_replace_callback(
                    '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^"\')]*))\s*\)?/i',
                    static function ($um) use ($is_allowed) {
                        $url = ($um[1] ?? '') . ($um[2] ?? '') . ($um[3] ?? '');
                        return $is_allowed($url) ? $um[0] : 'none';
                    },
                    $m[2]
                );
                return ' style=' . $m[1] . $style . $m[1];
            },
            $html
        );

        return $html;
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
            ],
        ];
    }
}
