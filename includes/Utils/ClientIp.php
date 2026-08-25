<?php

/**
 * Resolves the client IP address used for rate limiting.
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
 * Centralizes trusted-proxy-aware client IP resolution so every rate-limit call site
 * behaves consistently instead of reading $_SERVER['REMOTE_ADDR'] directly.
 */
class ClientIp
{
    /**
     * Returns the IP address to key rate limiting on. By default this is REMOTE_ADDR — the only value that
     * can't be spoofed by the client. X-Forwarded-For is only consulted when REMOTE_ADDR is in the site's
     * FORGE_TRUSTED_PROXIES allowlist (a comma-separated constant defined in wp-config.php), since otherwise
     * any client could forge that header to dodge the limit or to frame another visitor's IP.
     *
     * @return string Client IP address, or '' if unavailable.
     */
    public static function resolve(): string
    {
        $remote = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        if ($remote === '' || !self::isTrustedProxy($remote)) {
            return $remote;
        }

        $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))
            : '';
        if ($forwarded === '') {
            return $remote;
        }

        // Trusting the left-most entry outright assumes the proxy chain always
        // *overwrites* X-Forwarded-For rather than appending to whatever the
        // client already sent — if it appends, a client can still prepend
        // arbitrary IPs and have the left-most one trusted. Walk from the
        // right instead: skip any entry that is itself a known trusted proxy
        // (a multi-hop chain of our own proxies) and take the first one that
        // isn't — that's the actual, unspoofable client-facing hop.
        $parts = array_map('trim', explode(',', $forwarded));
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if (!filter_var($parts[$i], FILTER_VALIDATE_IP)) {
                continue;
            }
            if (!self::isTrustedProxy($parts[$i])) {
                return $parts[$i];
            }
        }
        return $remote;
    }

    /**
     * Checks whether an address is listed in FORGE_TRUSTED_PROXIES.
     *
     * @param string $ip Address to check.
     * @return bool True when the site has explicitly marked $ip as a trusted proxy.
     */
    private static function isTrustedProxy(string $ip): bool
    {
        if (!defined('FORGE_TRUSTED_PROXIES') || (string) FORGE_TRUSTED_PROXIES === '') {
            return false;
        }
        foreach (array_map('trim', explode(',', (string) FORGE_TRUSTED_PROXIES)) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (strpos($entry, '/') !== false) {
                if (self::ipInCidr($ip, $entry)) {
                    return true;
                }
            } elseif (hash_equals($entry, $ip)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks whether an IP address falls within a CIDR range. Supports both IPv4 and IPv6
     * via inet_pton()-based bitwise comparison rather than string prefix matching.
     *
     * @param string $ip   Address to check.
     * @param string $cidr CIDR range, e.g. "192.168.0.0/16" or "2001:db8::/32".
     * @return bool True when $ip is within $cidr.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $maskBits] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($subnet === null || $maskBits === null || !is_numeric($maskBits)) {
            return false;
        }
        $maskBits = (int) $maskBits;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes   = strlen($ipBin);
        $maxBits = $bytes * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $fullBytes    = intdiv($maskBits, 8);
        $remainderBits = $maskBits % 8;

        if ($fullBytes > 0 && strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
            return false;
        }

        if ($remainderBits > 0) {
            $mask = 0xFF << (8 - $remainderBits) & 0xFF;
            $ipByte     = ord($ipBin[$fullBytes]);
            $subnetByte = ord($subnetBin[$fullBytes]);
            if (($ipByte & $mask) !== ($subnetByte & $mask)) {
                return false;
            }
        }

        return true;
    }
}
