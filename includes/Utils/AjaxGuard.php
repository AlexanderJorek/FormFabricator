<?php

/**
 * Shared capability gate for wp_ajax_* handlers.
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

namespace FabricatorForms\Utils;

defined('ABSPATH') || exit;

/**
 * The capability check every wp_ajax_* handler starts with, before checking its own nonce: a nonce proves where a
 * request came from, not that its sender may make it.
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
            // wp_send_json_error() ends the request, so the handler's nonce check is never reached.
            wp_send_json_error(['message' => $forbidden_message ?? __('Forbidden', 'formfabricator')], 403);
        }
    }
}
