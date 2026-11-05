<?php

/**
 * HTTP status codes as the admin scripts name them.
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
 * A status code alone ("HTTP 500") tells an admin nothing, so a script that reports one shows its title too
 * ('HTTP 500 "Internal Server Error"'). Browsers can't supply the title: a response over HTTP/2 or HTTP/3 has an empty
 * statusText. The titles an admin is likely to meet are translated; the rest are WordPress's own English ones.
 */
final class HttpStatus
{
    /**
     * Every status code WordPress names, with its title.
     *
     * @return array<int, string>
     */
    public static function titles(): array
    {
        $titles = [];
        for ($code = 100; $code <= 599; $code++) {
            $title = get_status_header_desc($code);
            if ($title !== '') {
                $titles[$code] = $title;
            }
        }
        return self::translated() + $titles;
    }

    /**
     * The localized pieces a script needs to name a status: the titles and how a code and its title are written.
     *
     * @return array{titles: array<int, string>, format: string}
     */
    public static function forScript(): array
    {
        return [
            'titles' => self::titles(),
            // translators: %1$d: HTTP status code, %2$s: its title, e.g. HTTP 500 "Internal Server Error". Use your language's quotation marks.
            'format' => __('HTTP %1$d "%2$s"', 'formfabricator'),
        ];
    }

    /**
     * The titles of the error statuses a request from the admin scripts can end in: the plugin's own refusals and what
     * a server, proxy or security plugin answers in its place.
     *
     * @return array<int, string>
     */
    private static function translated(): array
    {
        return [
            400 => __('Bad Request', 'formfabricator'),
            401 => __('Unauthorized', 'formfabricator'),
            403 => __('Forbidden', 'formfabricator'),
            404 => __('Not Found', 'formfabricator'),
            405 => __('Method Not Allowed', 'formfabricator'),
            408 => __('Request Timeout', 'formfabricator'),
            409 => __('Conflict', 'formfabricator'),
            413 => __('Content Too Large', 'formfabricator'),
            422 => __('Unprocessable Content', 'formfabricator'),
            429 => __('Too Many Requests', 'formfabricator'),
            500 => __('Internal Server Error', 'formfabricator'),
            502 => __('Bad Gateway', 'formfabricator'),
            503 => __('Service Unavailable', 'formfabricator'),
            504 => __('Gateway Timeout', 'formfabricator'),
        ];
    }
}
