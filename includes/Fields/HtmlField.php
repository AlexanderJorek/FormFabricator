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
 * @version   1.0.5
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
     * Already passed through self::kses() at save/render time, so MailSender injects it as-is.
     *
     * @return bool
     */
    public function rawEmailHtml(): bool
    {
        return true;
    }

    /**
     * Renders the field HTML.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     * @return string Rendered HTML.
     */
    public function sanitizeConfigValue(string $key, string $value): string
    {
        return $key === 'html_content' ? self::kses($value) : \wp_kses_post($value);
    }

    /**
     * wp_kses_post()'s allowlist plus form-elements and inline SVG; single source of truth with Generator.php.
     *
     * @return array wp_kses()-compatible allowed-tags array.
     */
    private static function allowedTags(): array
    {
        $base  = \wp_kses_allowed_html('post');
        $extra = [
            'input'    => ['type'=>true,'name'=>true,'id'=>true,'value'=>true,
                           'placeholder'=>true,'checked'=>true,'disabled'=>true,
                           'readonly'=>true,'required'=>true,'min'=>true,'max'=>true,
                           'step'=>true,'pattern'=>true,'autocomplete'=>true,
                           'accept'=>true,'multiple'=>true,'class'=>true],
            'select'   => ['name'=>true,'id'=>true,'multiple'=>true,'disabled'=>true,
                           'required'=>true,'class'=>true],
            'option'   => ['value'=>true,'selected'=>true,'disabled'=>true],
            'optgroup' => ['label'=>true,'disabled'=>true],
            'source'   => ['src'=>true,'type'=>true,'media'=>true,'srcset'=>true,'sizes'=>true],
            'track'    => ['kind'=>true,'src'=>true,'srclang'=>true,'label'=>true,'default'=>true],
            'canvas'   => ['id'=>true,'width'=>true,'height'=>true,'class'=>true],
            'svg'      => ['xmlns'=>true,'width'=>true,'height'=>true,'viewbox'=>true,
                           'class'=>true,'fill'=>true,'stroke'=>true,
                           'stroke-width'=>true,'aria-hidden'=>true],
            'circle'   => ['cx'=>true,'cy'=>true,'r'=>true,'fill'=>true,'stroke'=>true,
                           'stroke-width'=>true,'class'=>true],
            'rect'     => ['x'=>true,'y'=>true,'width'=>true,'height'=>true,'rx'=>true,'ry'=>true,
                           'fill'=>true,'stroke'=>true,'stroke-width'=>true,'class'=>true],
            'path'     => ['d'=>true,'fill'=>true,'stroke'=>true,'stroke-width'=>true,
                           'fill-rule'=>true,'clip-rule'=>true,'class'=>true],
            'line'     => ['x1'=>true,'y1'=>true,'x2'=>true,'y2'=>true,'stroke'=>true,
                           'stroke-width'=>true,'class'=>true],
            'polyline' => ['points'=>true,'fill'=>true,'stroke'=>true,'stroke-width'=>true,'class'=>true],
            'polygon'  => ['points'=>true,'fill'=>true,'stroke'=>true,'stroke-width'=>true,'class'=>true],
            'ellipse'  => ['cx'=>true,'cy'=>true,'rx'=>true,'ry'=>true,'fill'=>true,
                           'stroke'=>true,'class'=>true],
            'g'        => ['fill'=>true,'stroke'=>true,'transform'=>true,'class'=>true,
                           'opacity'=>true,'fill-opacity'=>true,'stroke-opacity'=>true],
            'defs'     => [],
            'use'      => ['href'=>true,'xlink:href'=>true,'x'=>true,'y'=>true,'width'=>true,'height'=>true],
            'text'     => ['x'=>true,'y'=>true,'fill'=>true,'font-size'=>true,'text-anchor'=>true,
                           'class'=>true,'transform'=>true],
        ];
        return array_merge($base, $extra);
    }

    /**
     * Public accessor for self::kses()'s allowlist, so Generator.php's PDF pass matches what already ran here.
     *
     * @return array wp_kses()-compatible allowed-tags array.
     */
    public static function trustedPdfAllowedTags(): array
    {
        return self::allowedTags();
    }

    /**
     * Strips only <script> tags; allows all other HTML elements and attributes.
     * Applied on both save and render so the stored value round-trips cleanly.
     */
    private static function kses(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*+>[\s\S]*?</script>#i', '', $html);

        $html = \wp_kses($html, self::allowedTags());
        // <use href> may only reference an in-document fragment; anything else is stripped.
        $html = preg_replace_callback(
            '/<use\b[^>]*>/i',
            static function ($m) {
                return preg_replace('/\s(?:xlink:)?href\s*=\s*(["\'])(?!#)[^"\']*\1/i', '', $m[0]);
            },
            $html
        );
        // <source>/<track> may point at remote media, but only http(s)/relative — not javascript:/data:/file:.
        $html = preg_replace_callback(
            '/<(source|track)\b[^>]*>/i',
            static function ($m) {
                return preg_replace_callback(
                    '/\ssrc\s*=\s*(["\'])([^"\']*)\1/i',
                    static function ($sm) {
                        $val = html_entity_decode($sm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if (preg_match('#^\s*(?:javascript|vbscript|data|file)\s*:#i', $val)) {
                            return '';
                        }
                        return $sm[0];
                    },
                    $m[0]
                );
            },
            $html
        );
        return $html;
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $html = self::kses($config['html_content'] ?? '');
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
        $html = self::kses($config['html_content'] ?? '');
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
     * @param string $html Already wp_kses()-sanitized HTML (self::kses() output).
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
            // Relative/local paths (no scheme, no leading //) resolve on this
            // server's filesystem via mPDF's local-path handling, not a remote fetch.
            if (!preg_match('#^([a-z][a-z0-9+.\-]*:)?//#i', $url) && !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
                return true;
            }
            $host = wp_parse_url($url, PHP_URL_HOST);
            return $host !== null && $home_host !== '' && strcasecmp($host, $home_host) === 0;
        };

        $html = preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function ($m) use ($is_allowed) {
                return preg_replace_callback(
                    '/\ssrc\s*=\s*(["\'])([^"\']*)\1/i',
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
                $style = preg_replace_callback(
                    '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
                    static function ($um) use ($is_allowed) {
                        return $is_allowed($um[2]) ? $um[0] : 'none';
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
