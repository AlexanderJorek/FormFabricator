<?php

/**
 * Shared capability+nonce gate for wp_ajax_* handlers.
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
 * Enforces the capability-then-nonce order for wp_ajax_* handlers, which is easy to get wrong when each handler does it
 * by hand.
 */
class AjaxGuard
{
    /**
     * Enforces a capability check, ending the request with a 403 JSON error on failure.
     *
     * @param string|callable $capability        Capability name, or a closure returning bool.
     * @param string|null     $forbidden_message Message returned in the JSON error body; null for the
     *                                           translated default (a default argument can't call __()).
     * @return void
     */
    public static function capability($capability, ?string $forbidden_message = null, ?string $log_message = null): void
    {
        $allowed = is_callable($capability) ? (bool) $capability() : \FabricatorForms\Plugin::userCan($capability);
        if (!$allowed) {
            if ($log_message !== null) {
                \FabricatorForms\fabricator_log($log_message);
            }
            // wp_send_json_error() calls wp_die() internally (default $die = true), so this
            // never falls through to the nonce check below.
            wp_send_json_error(['message' => $forbidden_message ?? __('Forbidden', 'formfabricator')], 403);
        }
    }

    /**
     * Enforces capability THEN nonce — capability first, since check_ajax_referer() proves origin, not authorization.
     *
     * @param string|callable $capability        Capability name, or a closure returning bool.
     * @param string          $nonce_action       Nonce action passed to check_ajax_referer().
     * @param string          $nonce_field        POST/GET field name holding the nonce.
     * @param string|null     $forbidden_message  Message returned in the JSON error body on
     *                                            capability failure; null for the translated default.
     * @param string|null     $log_message        Optional message logged (via fabricator_log())
     *                                            before the 403 response, on capability failure only.
     * @return void
     */
    public static function require(
        $capability,
        string $nonce_action,
        string $nonce_field = 'nonce',
        ?string $forbidden_message = null,
        ?string $log_message = null
    ): void {
        self::capability($capability, $forbidden_message, $log_message);
        // check_ajax_referer() dies (wp_die(-1)) on its own when verification fails.
        check_ajax_referer($nonce_action, $nonce_field);
    }
}
