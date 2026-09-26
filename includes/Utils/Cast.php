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
 * Shape guards, not sanitizers — prevents a POSTed array from TypeErroring a strictly-typed WP sanitizer; must sit closest to the superglobal, never replace one.
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

    /**
     * Runs $fn with PHP warnings captured instead of emitted, for calls whose failure is an expected return
     * value (gzuncompress() on a stream that isn't zlib, an image probe on a non-image). Unlike @, the scope
     * is exactly this one call and the caller still has to handle the false it gets back.
     *
     * @param callable $fn Zero-argument callable.
     * @return mixed Whatever $fn returns.
     */
    public static function withoutWarnings(callable $fn): mixed
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- not debug code: captures the warnings of exactly one call and is restored in the finally below.
        set_error_handler(static fn(): bool => true, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
