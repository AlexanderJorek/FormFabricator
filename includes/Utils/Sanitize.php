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
 * @version   1.0.5
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
 * Guards scalar values pulled from untrusted arrays ($_POST, decoded JSON)
 * before they reach a strictly-typed WP sanitizer.
 */
class Sanitize
{
    /**
     * Returns $value if it's a string, or $default otherwise, so a non-string can't TypeError a WP sanitizer.
     *
     * @param mixed  $value   Raw value that is expected to be a string.
     * @param string $default Fallback when $value isn't actually a string.
     */
    public static function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
