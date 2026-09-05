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
 * Centralizes trusted-proxy-aware client IP resolution so every rate-limit call site
 * behaves consistently instead of reading $_SERVER['REMOTE_ADDR'] directly.
 */
class ClientIp
{
    /**
     * Returns the IP to key rate limiting on; X-Forwarded-For is only trusted from an allowlisted proxy.
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

        // Walk from the right, skipping our own trusted proxies, instead of trusting a spoofable left-most entry.
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
     * Checks whether an address is listed in FABRICATOR_TRUSTED_PROXIES.
     *
     * @param string $ip Address to check.
     * @return bool True when the site has explicitly marked $ip as a trusted proxy.
     */
    private static function isTrustedProxy(string $ip): bool
    {
        if (!defined('FABRICATOR_TRUSTED_PROXIES') || (string) FABRICATOR_TRUSTED_PROXIES === '') {
            return false;
        }
        foreach (array_map('trim', explode(',', (string) FABRICATOR_TRUSTED_PROXIES)) as $entry) {
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
