<?php

/**
 * The one HTML rule set for every HTML-capable text in a form.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.8
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Utils;

defined('ABSPATH') || exit;

/**
 * Sanitizes form HTML: the HTML block's content, consent and mandate texts, and every other non-plain-text field config
 * value. Lives here, not in a field class, so each field stays self-contained and none depends on another.
 */
class HtmlSanitizer
{
    /**
     * wp_kses_post()'s allowlist without its form controls, plus inline SVG, canvas and media sources; also what
     * Generator's PDF pass allows, so a value sanitized here renders the same in the PDF.
     *
     * @return array wp_kses()-compatible allowed-tags array.
     */
    public static function allowedTags(): array
    {
        $base = \wp_kses_allowed_html('post');
        // No form controls: their only use in rich text is a fake form, or posting under the form's own keys.
        foreach (['form', 'input', 'select', 'option', 'optgroup', 'textarea', 'button', 'datalist'] as $control) {
            unset($base[$control]);
        }
        $extra = [
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
     * Removes whole <script> elements, then applies allowedTags() and narrows <use href> and <source>/<track> src.
     * Applied on save and on render.
     *
     * @param string $html Untrusted HTML.
     * @return string
     */
    public static function sanitize(string $html): string
    {
        // Every regex step checks for PCRE giving up (it returns null at a backtrack or JIT limit). Passing that null on made
        // wp_kses() return '', so a large HTML block was saved empty with nothing but a deprecation notice.
        $without_scripts = preg_replace('#<script\b[^>]*+>[\s\S]*?</script>#i', '', $html);
        if ($without_scripts === null) {
            // wp_kses() below still removes the script tags; only their text stays behind, as harmless plain text.
            self::logPcreFailure('script removal');
            $without_scripts = $html;
        }
        $html = \wp_kses($without_scripts, self::allowedTags());

        // <use href> may only reference an in-document fragment; anything else is stripped.
        $narrowed = preg_replace_callback(
            '/<use\b[^>]*>/i',
            static function ($m) {
                // (?:(?!\1).)*, not [^"']*: an href="…'…" matches neither quote style and would stay unchecked.
                return preg_replace('/\s(?:xlink:)?href\s*=\s*(["\'])(?!#)(?:(?!\1).)*\1/is', '', $m[0]) ?? '';
            },
            $html
        );
        if ($narrowed === null) {
            // The hrefs can't be checked, so <use> goes entirely rather than staying unchecked.
            self::logPcreFailure('<use> narrowing');
            $narrowed = \wp_kses($html, self::allowedTagsWithout(['use']));
        }
        $html = $narrowed;

        // <source>/<track> may point at remote media, but only http(s)/relative — not javascript:/data:/file:.
        $narrowed = preg_replace_callback(
            '/<(source|track)\b[^>]*>/i',
            static function ($m) {
                return preg_replace_callback(
                    '/\ssrc\s*=\s*(["\'])((?:(?!\1).)*)\1/is',
                    static function ($sm) {
                        $val = html_entity_decode($sm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if (preg_match('#^\s*(?:javascript|vbscript|data|file)\s*:#i', $val)) {
                            return '';
                        }
                        return $sm[0];
                    },
                    $m[0]
                ) ?? '';
            },
            $html
        );
        if ($narrowed === null) {
            self::logPcreFailure('<source>/<track> narrowing');
            $narrowed = \wp_kses($html, self::allowedTagsWithout(['source', 'track']));
        }
        return $narrowed;
    }

    /**
     * allowedTags() minus the given elements, for the fallbacks in sanitize().
     *
     * @param string[] $tags Element names to drop.
     * @return array
     */
    private static function allowedTagsWithout(array $tags): array
    {
        return array_diff_key(self::allowedTags(), array_flip($tags));
    }

    /**
     * Logs a PCRE failure with the reason PHP reports.
     *
     * @param string $step Which sanitizing step failed.
     * @return void
     */
    private static function logPcreFailure(string $step): void
    {
        \FabricatorForms\fabricator_log('FabricatorForms HtmlSanitizer: PCRE failed during ' . $step . ' (' . preg_last_error_msg() . '); used the stricter fallback.');
    }

    /**
     * Strips remote <img src>/CSS url() refs from rich text bound for the PDF before mPDF fetches them, keeping
     * same-origin and data: only.
     *
     * @param string $html Already wp_kses()-sanitized HTML (\FabricatorForms\Utils\HtmlSanitizer::sanitize() output).
     * @return string HTML with disallowed remote resource references stripped.
     */
    public static function stripRemoteResourcesForPdf(string $html): string
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
                // A relative reference is URL characters only: no entities ("&quot;http://…&quot;"), quotes or brackets,
                // which mPDF decodes or strips into a remote URL before fetching.
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

        // A PCRE failure (backtrack/JIT limit on a large block) returns null. Fail closed, like sanitize(): the text
        // alone reaches the PDF, with nothing it could fetch — and no TypeError on this method's string return.
        $original = $html;
        $as_text  = static function () use ($original): string {
            self::logPcreFailure('remote resource stripping');
            return nl2br(esc_html(wp_strip_all_tags($original)));
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
        if ($html === null) {
            return $as_text();
        }

        $html = preg_replace_callback(
            '/\bstyle\s*=\s*(["\'])((?:(?!\1).)*)\1/is',
            static function ($m) use ($is_allowed) {
                // One branch per quoting style, so every url( comes out checked or replaced: the unquoted branch runs
                // to the closing parenthesis, through "(" and stray quotes, and an unterminated url( is read too.
                $style = preg_replace_callback(
                    '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))\s*\)?/i',
                    static function ($um) use ($is_allowed) {
                        // PCRE leaves unmatched groups after the matching one out of $um: a "…" URL has no 2 or 3.
                        $url = $um[1] . ($um[2] ?? '') . ($um[3] ?? '');
                        return $is_allowed($url) ? $um[0] : 'none';
                    },
                    $m[2]
                );
                // The match starts at "style", so the whitespace before it is still in place.
                return 'style=' . $m[1] . $style . $m[1];
            },
            $html
        );

        return $html ?? $as_text();
    }
}
