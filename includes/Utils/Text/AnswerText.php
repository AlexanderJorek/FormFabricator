<?php

/**
 * What an answer may hold.
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
 * An answer is recorded as the visitor typed it, trimmed of the spaces around it, or refused: nothing in it is removed
 * or replaced, since a changed answer (a mandate's account holder) is a different statement. Refused is what can't be
 * read (invalid UTF-8), what nobody sees (control and format characters: direction controls, zero-width characters, the
 * soft hyphen), line breaks outside a multi-line answer, and HTML character references, which a page or email would show
 * as the character they name. Everything else, "<" and "%" included, is kept: every place that shows an answer escapes
 * it there. front.js runs the same check before sending (answerProblem()).
 */
final class AnswerText
{
    /**
     * Characters no answer may hold: control characters, invisible format characters, line and paragraph separators.
     * A multi-line answer may hold line breaks and tabs (checked apart). The zero-width non-joiner and joiner (U+200C,
     * U+200D) are typed: a Persian keyboard writes the non-joiner, and an emoji such as the family is man, U+200D,
     * woman, U+200D, girl. Neither reorders text.
     *
     * @var string
     */
    private const REFUSED = '/[\p{Cc}\p{Zl}\p{Zp}]|(?![\x{200C}\x{200D}])\p{Cf}/u';

    /**
     * Why $text can't be recorded as typed, or null when it can.
     *
     * @param string $text      The answer, trimmed.
     * @param bool   $multiline Whether line breaks and tabs belong in it (a textarea).
     * @return string|null
     */
    public static function problem(string $text, bool $multiline): ?string
    {
        if ($text === '') {
            return null;
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return __('This answer contains characters that can\'t be read. Please type it again.', 'formfabricator');
        }
        $checked = $multiline ? (string) preg_replace('/[\r\n\t]/', '', $text) : $text;
        if (preg_match(self::REFUSED, $checked, $m) === 1) {
            if (!$multiline && preg_match('/[\r\n\t]/', $m[0]) === 1) {
                return __('This answer can\'t hold line breaks or tabs. Please write it on one line.', 'formfabricator');
            }
            return sprintf(
                /* translators: %s: the character's code point, e.g. "U+200B". */
                __('This answer contains an invisible character (%s). Please remove it, or type the answer instead of pasting it.', 'formfabricator'),
                sprintf('U+%04X', mb_ord($m[0], 'UTF-8'))
            );
        }
        // An email or page shows "&amp;" or "&#65;" as the character it names, not as typed: escaping leaves such a
        // reference as it is.
        if (str_contains($text, '&') && html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $text) {
            return __('This answer contains an HTML character reference (such as "&amp;"). Please write the character itself.', 'formfabricator');
        }
        return null;
    }

    /**
     * The first problem among the strings in $value, nested ones included, or null.
     *
     * @param mixed $value     What a field's extractValue() returned.
     * @param bool  $multiline See problem().
     * @return string|null
     */
    public static function problemIn(mixed $value, bool $multiline): ?string
    {
        if (is_string($value)) {
            return self::problem($value, $multiline);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $problem = self::problemIn($item, $multiline);
                if ($problem !== null) {
                    return $problem;
                }
            }
        }
        return null;
    }
}
