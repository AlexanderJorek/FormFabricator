<?php

/**
 * A character's Unicode code point, and back.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.1.2
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
 * What mb_ord() and mb_chr() do, through UTF-32. Those two count, for WordPress.org's Plugin Check, as functions of
 * WordPress 7.1, which brings a fallback for them, though the mbstring extension the plugin requires has them; the
 * plugin supports older WordPress releases.
 */
final class CodePoint
{
    /**
     * The code point of the first character of $text, as mb_ord() gives it; 0 for an empty string.
     *
     * @param string $text Valid UTF-8.
     * @return int
     */
    public static function of(string $text): int
    {
        $utf32 = (string) mb_convert_encoding($text, 'UTF-32BE', 'UTF-8');
        if (strlen($utf32) < 4) {
            return 0;
        }
        $code = unpack('N', $utf32);
        return is_array($code) ? (int) $code[1] : 0;
    }

    /**
     * The character with code point $code, as mb_chr() gives it; null where mb_chr() gives false (a surrogate, or no
     * code point).
     *
     * @param int $code Code point.
     * @return string|null
     */
    public static function char(int $code): ?string
    {
        if ($code < 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
            return null;
        }
        return (string) mb_convert_encoding(pack('N', $code), 'UTF-8', 'UTF-32BE');
    }
}
