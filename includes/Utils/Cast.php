<?php

/**
 * Type guards for values pulled from untrusted arrays before they reach a strictly-typed sanitizer.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.6
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
 * Shape guards for untrusted values. Deliberately NOT named Sanitize: nothing here sanitizes.
 *
 * A guard only proves a value is the type the following WP sanitizer requires, so that a POSTed
 * array can't TypeError a strictly-typed sanitizer (sanitize_email() -> strlen(array) and
 * sanitize_hex_color() -> preg_match(..., array) both fatal in PHP 8). It must therefore sit
 * INSIDE the real sanitizer, closest to the superglobal — never in place of one.
 */
class Cast
{
    /**
     * Returns $value if it's a string, or $default otherwise, so a non-string can't TypeError a WP sanitizer.
     *
     * @param mixed  $value   Raw value that is expected to be a string.
     * @param string $default Fallback when $value isn't actually a string.
     */
    public static function stringOrDefault(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
