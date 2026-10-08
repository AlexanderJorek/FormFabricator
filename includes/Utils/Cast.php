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
 * @version   1.0.9
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
 * Shape guards, not sanitizers: keep a POSTed array from reaching a strictly-typed sanitizer. Never a replacement for one.
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
     * Runs $fn with PHP warnings suppressed, for calls whose failure is an expected return value. Unlike @, scoped to
     * exactly this call.
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

    /**
     * JSON for an HTML attribute: pass the result through esc_attr(), as usual.
     *
     * esc_attr() doesn't double-encode, so an entity in the JSON would be decoded by the browser. With &, <, >, ' and "
     * as \u escapes, nothing in the JSON is decoded.
     *
     * @param mixed $value Value to encode.
     * @return string JSON, or '' when it cannot be encoded.
     */
    public static function jsonForAttribute(mixed $value): string
    {
        return (string) wp_json_encode($value, JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * Whether a $_FILES entry has the shapes the form's own inputs produce: every key a scalar (name="f") or a list
     * of scalars (name="f[]"), never nested deeper.
     *
     * @param mixed $file One $_FILES entry.
     * @return bool
     */
    public static function isFlatFilesEntry(mixed $file): bool
    {
        if (!is_array($file)) {
            return false;
        }
        foreach ($file as $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (!is_scalar($item) && $item !== null) {
                        return false;
                    }
                }
            } elseif (!is_scalar($value) && $value !== null) {
                return false;
            }
        }
        return true;
    }
}
