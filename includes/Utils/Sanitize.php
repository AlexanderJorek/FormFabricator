<?php

/**
 * Shared input-shape guard for strictly-typed sanitizer calls.
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

namespace ForgeForms\Utils;

defined('ABSPATH') || exit;

/**
 * Guards scalar values pulled from untrusted arrays ($_POST, decoded JSON)
 * before they reach a strictly-typed WP sanitizer.
 */
class Sanitize
{
    /**
     * Returns $value if it's a string, or $default otherwise. $_POST keys can be turned into arrays by an
     * attacker via bracketed field names (e.g. "title[]=x"), and decoded client JSON can put any type under a
     * key that's normally a string. Passing that straight into a strictly-typed WP sanitizer
     * (sanitize_text_field, sanitize_email, sanitize_key, sanitize_hex_color, wp_kses_post, etc.) throws an
     * uncaught TypeError instead of failing gracefully. Every scalar pulled from untrusted input into a
     * sanitizer call should go through this first.
     *
     * @param mixed  $value   Raw value that is expected to be a string.
     * @param string $default Fallback when $value isn't actually a string.
     */
    public static function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
