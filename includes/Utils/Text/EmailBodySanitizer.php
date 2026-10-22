<?php

/**
 * Sanitizes the HTML body of a notification email.
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

namespace FabricatorForms\Utils\Text;

defined('ABSPATH') || exit;

/**
 * The HTML a notification's message may hold: what email may carry, without scripts, embedded content, event handlers,
 * remote stylesheets or active CSS. Its own rule set, not HtmlSanitizer's: an email body is a whole document with
 * <style> blocks, and is read by mail programs, not the site's pages.
 */
class EmailBodySanitizer
{
    /**
     * Sanitizes an HTML email body in two layers: regex passes reach <style> contents wp_kses skips, then wp_kses() as defense-in-depth.
     *
     * @param string    $html    Raw HTML body from the notification editor.
     * @param bool|null $removed Set to whether content was removed, for the save warning. Normalizing (an added
     *                           rel="noopener", content after </html> moved before </body>) removes nothing.
     * @return string Sanitized HTML.
     */
    public static function sanitize(string $html, ?bool &$removed = null): string
    {
        $before = $html;

        // Embeddable content has no place in an email body, so whole elements go, by a linear scan.
        $html    = self::stripElements($html, ['iframe', 'object']);
        $removed = $html !== $before;

        // No regex below scans unboundedly from a point that may never match: each anchors on a fixed token.
        $passes = [
            // Removing every opening and closing tag neutralizes a script; a whole-element pass after these had nothing left to match.
            'script-open'   => '/<script\b[^>]*>/i',
            'script-close'  => '/<\/script\s*>/i',
            'embed-tags'    => '/<embed\b[^>]*\/?>/i',
            // Meta refresh is a classic open-redirect/auto-navigate vector in
            // rendered HTML mail and has no legitimate use in a notification body.
            'meta-refresh'  => '/<meta\b[^>]*http-equiv\s*=\s*["\']?\s*refresh[^>]*>/i',
            // "/" is an attribute boundary too (<img/onerror=...>). A lookbehind, so long whitespace runs don't backtrack.
            'event-handlers' =>
                '/(?<=[\s\/])on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            'js-uris'
                => '/\b(href|src|action)\s*=\s*(["\'])\s*'
                . '(?:javascript|vbscript)\s*:[^"\']*\2/i',
            // Unquoted attribute values are valid HTML (<a href=javascript:...>)
            // and aren't matched by the quoted pattern above at all.
            'js-uris-unquoted'
                => '/\b(href|src|action)\s*=\s*(?!["\'])'
                . '(?:javascript|vbscript)\s*:[^\s>]*/i',
            // style="...url('javascript:...')..." is handled by the decoded-attribute pass below, which also covers
            // entity-encoded and comment-split forms; a separate regex here backtracked quadratically on long styles.
        ];

        $replacements = [
            'script-open'       => '',
            'script-close'      => '',
            'embed-tags'        => '',
            'meta-refresh'      => '',
            'event-handlers'    => '',
            'js-uris-unquoted'  => '$1=""',
        ];
        $replacements['js-uris'] = '$1=$2#$2';

        foreach ($passes as $label => $pattern) {
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceDyn -- $pattern/$replacements come from the hardcoded arrays above, not attacker input.
            $after = preg_replace($pattern, $replacements[$label], $html) ?? $html;
            if ($after !== $html) {
                $removed = true;
                // Count-only, no stripped content logged; fabricator_log() is WP_DEBUG-gated, a debug aid not an audit trail.
                preg_match_all($pattern, $html, $m);
                \FabricatorForms\fabricator_log(
                    'FabricatorForms sanitizeEmailBody [' . $label . '] removed '
                    . count($m[0] ?? []) . ' match(es)'
                );
            }
            $html = $after;
        }

        /* wp_kses() only filters tags/attributes, never <style> element contents — scrutinize its CSS here. */
        $neutralized = false;
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $html = preg_replace_callback(
            '#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
            static function (array $m) use (&$neutralized): string {
                $css = self::sanitizeStyleBlockCss($m[2], $block_neutralized);
                $neutralized = $neutralized || $block_neutralized;
                return $m[1] . $css . $m[3];
            },
            $html
        ) ?? $html;
        if ($neutralized) {
            $removed = true;
            \FabricatorForms\fabricator_log(
                'FabricatorForms sanitizeEmailBody [style-block-css] neutralized active CSS constructs'
            );
        }

        // Guards against entity-encoded or CSS-comment-split "javascript:" evading the passes above.
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $html = preg_replace_callback(
            '/\b(href|src|action|style)\s*=\s*(?:(["\'])((?:(?!\2).)*)\2|([^\s>]+))/is',
            static function (array $m) use (&$removed): string {
                $attr    = $m[1];
                $quoted  = $m[2] !== '';
                $q       = $quoted ? $m[2] : '';
                $val     = $quoted ? $m[3] : ($m[4] ?? '');
                $decoded = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceWeird -- hardcoded literal pattern stripping CSS comments from the decoded value; no /e modifier.
                $decoded = preg_replace('#/\*.*?\*/#s', '', $decoded);
                // Browsers ignore tab/CR/LF in a URL scheme ("jav\tascript:").
                $decoded = str_replace(["\t", "\n", "\r"], '', (string) $decoded);
                if (preg_match('/javascript\s*:|vbscript\s*:/i', $decoded)) {
                    $removed = true;
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms sanitizeEmailBody [encoded-js-uri] stripped '
                        . $attr . ' value: ' . substr($val, 0, 200)
                    );
                    return $quoted ? ($attr . '=' . $q . $q) : ($attr . '=""');
                }
                return $m[0];
            },
            $html
        ) ?? $html;

        // Content appended after </html> (e.g. {all_fields} tacked on) breaks email clients; move it to just before </body> instead.
        $close_pos = strripos($html, '</html>');
        if ($close_pos !== false) {
            $orphan = trim(substr($html, $close_pos + 7));
            if ($orphan !== '') {
                $html = substr($html, 0, $close_pos + 7);
                $body_close = strripos($html, '</body>');
                if ($body_close !== false) {
                    $html = substr($html, 0, $body_close)
                        . "\n" . $orphan . "\n"
                        . substr($html, $body_close);
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms sanitizeEmailBody: moved orphaned content'
                        . ' from after </html> to before </body>: '
                        . substr($orphan, 0, 100)
                    );
                }
            }
        }

        // Layer 2: wp_kses() allow-list. DOCTYPE is kept by hand, as wp_kses doesn't know it.
        $doctype = '';
        if (preg_match('/^\s*<!DOCTYPE[^>]*>/i', $html, $dm)) {
            $doctype = $dm[0];
            $html    = substr($html, strlen($dm[0]));
        }

        $allowed_email_tags = [
            // Document wrapper — see comment above for why this must stay.
            'html'  => ['lang' => true],
            'head'  => [],
            'body'  => ['style' => true, 'bgcolor' => true],
            'title' => [],
            // No http-equiv (a refresh redirect) and no <link> (a remote stylesheet is a read-tracking beacon).
            'meta'  => ['charset' => true, 'content' => true, 'name' => true],
            'style' => ['type' => true, 'media' => true],

            // Layout & content — HTML-email-safe subset.
            'table'    => ['style' => true, 'width' => true, 'height' => true, 'border' => true,
                           'cellpadding' => true, 'cellspacing' => true, 'align' => true,
                           'bgcolor' => true, 'role' => true, 'class' => true, 'id' => true],
            'caption'  => ['style' => true, 'class' => true],
            'colgroup' => ['style' => true, 'class' => true],
            'col'      => ['style' => true, 'span' => true, 'width' => true],
            'thead'    => ['style' => true, 'class' => true],
            'tbody'    => ['style' => true, 'class' => true],
            'tfoot'    => ['style' => true, 'class' => true],
            'tr'       => ['style' => true, 'align' => true, 'valign' => true, 'bgcolor' => true, 'class' => true, 'id' => true],
            'td'       => ['style' => true, 'align' => true, 'valign' => true, 'width' => true,
                           'height' => true, 'colspan' => true, 'rowspan' => true, 'bgcolor' => true,
                           'class' => true, 'id' => true, 'scope' => true],
            'th'       => ['style' => true, 'align' => true, 'valign' => true, 'width' => true,
                           'height' => true, 'colspan' => true, 'rowspan' => true, 'bgcolor' => true,
                           'class' => true, 'id' => true, 'scope' => true],
            'div'      => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'span'     => ['style' => true, 'class' => true, 'id' => true],
            'p'        => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'a'        => ['style' => true, 'href' => true, 'title' => true, 'target' => true,
                           'rel' => true, 'class' => true, 'id' => true],
            'img'      => ['style' => true, 'src' => true, 'alt' => true, 'title' => true,
                           'width' => true, 'height' => true, 'align' => true, 'border' => true,
                           'class' => true, 'id' => true],
            'br'       => [],
            'hr'       => ['style' => true, 'class' => true],
            'strong'   => ['style' => true],
            'em'       => ['style' => true],
            'b'        => ['style' => true],
            'i'        => ['style' => true],
            'u'        => ['style' => true],
            'mark'     => ['style' => true, 'class' => true],
            'small'    => ['style' => true],
            'del'      => ['style' => true, 'cite' => true],
            'ins'      => ['style' => true, 'cite' => true],
            'sup'      => ['style' => true],
            'sub'      => ['style' => true],
            'ul'       => ['style' => true, 'class' => true, 'id' => true],
            'ol'       => ['style' => true, 'class' => true, 'id' => true],
            'li'       => ['style' => true, 'class' => true, 'id' => true],
            'dl'       => ['style' => true, 'class' => true],
            'dt'       => ['style' => true],
            'dd'       => ['style' => true],
            'h1'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h2'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h3'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h4'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h5'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'h6'       => ['style' => true, 'align' => true, 'class' => true, 'id' => true],
            'blockquote' => ['style' => true, 'class' => true, 'cite' => true],
            'pre'      => ['style' => true, 'class' => true],
            'code'     => ['style' => true, 'class' => true],
            'kbd'      => ['style' => true],
            'samp'     => ['style' => true],
            'var'      => ['style' => true],
            'cite'     => ['style' => true],
            'abbr'     => ['style' => true, 'title' => true],
            'time'     => ['style' => true, 'datetime' => true],
            'q'        => ['style' => true, 'cite' => true],
            'details'  => ['style' => true, 'class' => true, 'open' => true],
            'summary'  => ['style' => true],
            'progress' => ['style' => true, 'value' => true, 'max' => true],
            'meter'    => ['style' => true, 'value' => true, 'min' => true, 'max' => true,
                           'low' => true, 'high' => true, 'optimum' => true],
            'section'  => ['style' => true, 'class' => true, 'id' => true],
            'header'   => ['style' => true, 'class' => true, 'id' => true],
            'footer'   => ['style' => true, 'class' => true, 'id' => true],
            'article'  => ['style' => true, 'class' => true, 'id' => true],
            'aside'    => ['style' => true, 'class' => true, 'id' => true],
            'nav'      => ['style' => true, 'class' => true, 'id' => true],
            'address'  => ['style' => true, 'class' => true],
            // No action/method: a <form> that submits somewhere is a phishing form inside a notification email.
            'form'     => ['style' => true, 'class' => true, 'id' => true],
            'label'    => ['style' => true, 'class' => true, 'for' => true],
            'input'    => ['style' => true, 'class' => true, 'id' => true, 'type' => true, 'name' => true,
                           'value' => true, 'placeholder' => true, 'checked' => true, 'disabled' => true],
            'select'   => ['style' => true, 'class' => true, 'id' => true, 'name' => true, 'multiple' => true],
            'option'   => ['value' => true, 'selected' => true],
            'textarea' => ['style' => true, 'class' => true, 'id' => true, 'name' => true, 'rows' => true, 'cols' => true],
            'button'   => ['style' => true, 'class' => true, 'id' => true, 'type' => true],
            'noscript' => [],
        ];

        // 'safe_style_css' takes the property list; 'safecss_filter_attr_allow_css' would pass a bool.
        $allow_email_css = static function (array $allowed_properties): array {
            return array_unique(
                array_merge(
                    $allowed_properties,
                    [
                        'overflow', 'border-radius', 'display', 'background-color',
                        'padding', 'margin', 'font-family', 'font-size', 'color',
                        'text-align', 'width', 'max-width', 'border', 'border-collapse',
                        'vertical-align', 'line-height', 'background', 'box-sizing',
                        'white-space', 'text-decoration', 'font-weight', 'letter-spacing',
                        'border-top', 'border-right', 'border-bottom', 'border-left',
                        'border-spacing', 'padding-top', 'padding-right', 'padding-bottom',
                        'padding-left', 'margin-top', 'margin-right', 'margin-bottom',
                        'margin-left',
                    ]
                )
            );
        };
        // Removed in finally, so a throw inside wp_kses() can't leave it hooked.
        \add_filter('safe_style_css', $allow_email_css);
        $before_kses = $html;
        try {
            $html = \wp_kses($html, $allowed_email_tags);
        } finally {
            \remove_filter('safe_style_css', $allow_email_css);
        }
        // wp_kses() also normalizes what it keeps (quoting, entities, self-closing slashes), so its output is compared
        // by what it says, not byte for byte.
        if (!$removed && self::markupSignature($html) !== self::markupSignature($before_kses)) {
            $removed = true;
        }

        $html = $doctype . $html;

        // Force rel="noopener noreferrer" on target="_blank" links (reverse-tabnabbing defense-in-depth).
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $html = preg_replace_callback(
            '/<a\b([^>]*\btarget=["\']_blank["\'][^>]*)>/i',
            static function (array $m): string {
                // The tag's attributes, as the sanitizer above left them.
                $attrs = $m[1];
                if (preg_match('/\brel=["\']([^"\']*)["\']/i', $attrs, $rel_match)) {
                    $rel     = $rel_match[1];
                    $needed  = array_diff(['noopener', 'noreferrer'], explode(' ', $rel));
                    if (empty($needed)) {
                        return $m[0];
                    }
                    $new_rel = trim($rel . ' ' . implode(' ', $needed));
                    $attrs   = (string) preg_replace('/\brel=["\'][^"\']*["\']/i', 'rel="' . esc_attr($new_rel) . '"', $attrs);
                    return '<a' . $attrs . '>';
                }
                return '<a' . $attrs . ' rel="noopener noreferrer">';
            },
            $html
        ) ?? $html;

        // Log lengths only (no content) when something was stripped, as a production audit trail.
        if ($html !== $before) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms sanitizeEmailBody: input length '
                . strlen($before) . ' → output length ' . strlen($html)
            );
        }

        return $html;
    }

    /**
     * Resolves CSS identifier escapes (e.g. \65 → 'e') so keyword filters can't be evaded by escaping.
     *
     * @param string $css Raw CSS text.
     * @return string CSS with identifier escapes resolved.
     */
    private static function decodeCssEscapes(string $css): string
    {
        if (!str_contains($css, '\\')) {
            return $css;
        }
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        return (string) preg_replace_callback(
            '/\\\\(?:([0-9A-Fa-f]{1,6})[ \t\r\n\f]?|([^\r\n\f0-9A-Fa-f]))/',
            static function (array $m): string {
                if ($m[1] !== '') {
                    $code = (int) hexdec($m[1]);
                    // Printable ASCII only; NUL and anything non-ASCII is dropped, not emitted.
                    return ($code >= 0x20 && $code <= 0x7E) ? chr($code) : '';
                }
                return $m[2] ?? '';
            },
            $css
        );
    }

    /**
     * What a piece of markup says, for telling whether wp_kses() removed something: each tag with its attributes (a
     * style attribute by its property names, which is what wp_kses() drops), then the text. Spelling it keeps alike
     * (case, quoting, entities, self-closing slashes, whitespace) reads the same. Comments are left out.
     *
     * @param string $html Markup.
     * @return string
     */
    private static function markupSignature(string $html): string
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
        $decode = static fn(string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tags   = [];
        // Possessive quantifiers: an unclosed quote fails at once instead of being retried from every position.
        preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*+)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>#', $html, $found, PREG_SET_ORDER);
        foreach ($found as [, $closing, $name, $attrs]) {
            $parts = [];
            preg_match_all('#([^\s"\'>/=]++)(?:\s*=\s*(?:"([^"]*+)"|\'([^\']*+)\'|([^\s"\'>]++)))?#', $attrs, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $attr  = strtolower($pair[1]);
                $value = $decode(($pair[2] ?? '') . ($pair[3] ?? '') . ($pair[4] ?? ''));
                if ($attr === 'style') {
                    preg_match_all('/(?:^|;)\s*([-a-zA-Z]++)\s*:/', $value, $props);
                    if ($props[1] === []) {
                        // wp_kses() drops a style with no declarations, which held nothing to lose.
                        continue;
                    }
                    $value = strtolower(implode(',', $props[1]));
                }
                $parts[] = $attr . '=' . trim($value);
            }
            $tags[] = $closing . strtolower($name) . '[' . implode('|', $parts) . ']';
        }
        $text = (string) preg_replace('/\s++/u', ' ', $decode(wp_strip_all_tags($html)));
        return implode('', $tags) . "\n" . trim($text);
    }

    /**
     * Neutralizes script-executing and remote-fetching constructs inside a <style> block's CSS.
     *
     * @param string    $css         Raw CSS text from between <style> and </style>.
     * @param bool|null $neutralized Set to whether a construct was neutralized; dropping comments and resolving
     *                               escapes changes nothing the CSS does.
     * @return string CSS with active constructs neutralized.
     */
    private static function sanitizeStyleBlockCss(string $css, ?bool &$neutralized = null): string
    {
        // Comments first: "expr/**/ession(" would otherwise slip past every keyword match below.
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceWeird -- hardcoded literal pattern stripping CSS comments; no /e modifier.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        // CSS escapes first: a browser reads "@\69 mport" as "@import".
        $css = self::decodeCssEscapes($css);
        $plain = $css;

        // @import pulls a remote stylesheet — a tracking/exfiltration vector in mail, and the
        // fetched CSS would never pass through this function at all. Drop the whole at-rule.
        $css = (string) preg_replace('/@\s*import\b[^;{}]*(?:;|(?=[{}])|$)/i', '', $css);

        // expression() is legacy-IE script execution. Renaming the function makes the whole
        // declaration invalid CSS, so browsers drop it — visible in source, inert in effect.
        $css = (string) preg_replace('/\bexpression\s*\(/i', 'expression-blocked(', $css);

        // behavior: (IE HTC) and -moz-binding: (XBL) both attach executable code to an element.
        $css = (string) preg_replace('/(?:-moz-)?\bbinding\s*:[^;}]*/i', '', $css);
        $css = (string) preg_replace('/\bbehavior\s*:[^;}]*/i', '', $css);

        // url() with a script/document scheme; normalized first since browsers strip tab/CR/LF before parsing it. data:image/* is kept.
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $css = (string) preg_replace_callback(
            '/url\s*\(\s*(["\']?)(.*?)\1\s*\)/is',
            static function (array $m): string {
                $url = (string) html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url = str_replace(["\t", "\n", "\r", "\0"], '', $url);
                if (preg_match('#^\s*(?:javascript|vbscript)\s*:#i', $url)) {
                    return 'url()';
                }
                if (preg_match('#^\s*data\s*:#i', $url) && !preg_match('#^\s*data\s*:\s*image/#i', $url)) {
                    return 'url()';
                }
                return $m[0];
            },
            $css
        ) ?? $css;

        $neutralized = $css !== $plain;
        return $css;
    }

    /**
     * Removes each <tag>…</tag> element, or a lone opening tag that never closes, in one forward pass.
     *
     * @param string   $html HTML to clean.
     * @param string[] $tags Lowercase tag names.
     * @return string
     */
    private static function stripElements(string $html, array $tags): string
    {
        foreach ($tags as $tag) {
            $out     = '';
            $offset  = 0;
            $removed = 0;
            // Latched: with no closing tag after one opener there is none after any later one, so the search to the end
            // runs once. Searched again for every unclosed opener, a body of them took seconds (400 KB: 6.5 s).
            $no_close = false;
            while (preg_match('/<' . $tag . '\b/i', $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $start = $m[0][1];
                $out  .= substr($html, $offset, $start - $offset);
                $close = $no_close ? false : stripos($html, '</' . $tag, $start);
                $no_close = $close === false;
                $gt    = strpos($html, '>', $close !== false ? $close : $start);
                $offset = $gt === false ? strlen($html) : $gt + 1;
                $removed++;
            }
            if ($removed > 0) {
                $html = $out . substr($html, $offset);
                \FabricatorForms\fabricator_log('FabricatorForms sanitizeEmailBody [' . $tag . '-tags] removed ' . $removed . ' match(es)');
            }
        }
        return $html;
    }
}
