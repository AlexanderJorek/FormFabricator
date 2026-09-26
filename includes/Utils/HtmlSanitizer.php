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
 * @version   1.0.7
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
        // No form controls, not even those wp_kses_post() allows: nothing in the plugin reads them, so their only use in a
        // rich text is a fake form (a password field posting elsewhere) on the public page. Removing every element that
        // can submit a value also retires the old renaming of inputs named like the form's own POST keys.
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
     * Applied on both save and render so the stored value round-trips cleanly, and so a value saved before form controls
     * were removed is cleaned when it is shown.
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
                // (?:(?!\1).)*, not [^"']*: an href="…'…" matched neither quote style and stayed unchecked.
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
}
