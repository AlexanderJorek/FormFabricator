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
     * The IPv6 /48 an address belongs to, or null for IPv4 (and IPv4-mapped IPv6).
     *
     * A free tunnel broker hands out a /48 (65,536 /64s), so FormProcessor rate-limits it before the /64.
     *
     * @param string $ip Address from resolve().
     * @return string|null
     */
    public static function prefix48(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16 || strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            return null;
        }
        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /**
     * Reduces an address to the unit one client controls: the /64 for IPv6, the address itself for IPv4.
     *
     * An IPv6 subscriber routinely gets a whole /64 to rotate through.
     *
     * @param string $ip Address from resolve().
     * @return string Rate-limit bucket identifier.
     */
    public static function bucket(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        // IPv4-mapped (::ffff:a.b.c.d): its /64 is shared by every IPv4 client, so key on the embedded IPv4.
        if (strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            return (string) inet_ntop(substr($bin, 12, 4));
        }
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * Trusted proxy addresses and CIDR ranges: the FABRICATOR_TRUSTED_PROXIES constant (wp-config.php) and the list an
     * administrator enters under FormFabricator → Settings. Both apply, so a constant already in place keeps working.
     *
     * @return string[]
     */
    public static function trustedProxyEntries(): array
    {
        $sources = [(string) get_option('fabricator_forms_trusted_proxies', '')];
        if (defined('FABRICATOR_TRUSTED_PROXIES')) {
            $sources[] = (string) FABRICATOR_TRUSTED_PROXIES;
        }
        $entries = preg_split('/[\s,]+/', implode(',', $sources)) ?: [];
        return array_values(array_filter($entries, static fn(string $e): bool => $e !== ''));
    }

    /**
     * The trusted proxy entries wide enough to trust much of the internet (IPv4 /7, IPv6 /15 or wider), whose visitors
     * could claim any address. The settings page warns about them; they still apply.
     *
     * @return string[]
     */
    public static function wideTrustedEntries(): array
    {
        return array_values(array_filter(
            self::trustedProxyEntries(),
            static function (string $entry): bool {
                if (strpos($entry, '/') === false) {
                    return false;
                }
                [$net, $bits] = explode('/', $entry, 2);
                if (!ctype_digit($bits)) {
                    return false;
                }
                $is_v4 = filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
                return (int) $bits < ($is_v4 ? 8 : 16);
            }
        ));
    }

    /**
     * Cleans an administrator-entered proxy list: IP addresses or CIDR ranges, separated by commas, spaces or line breaks.
     *
     * @param string $input Raw list.
     * @return array{0: string, 1: string[]} The valid entries as one comma-separated list, and the entries that were not valid.
     */
    public static function normalizeProxyList(string $input): array
    {
        $valid   = [];
        $invalid = [];
        foreach (preg_split('/[\s,]+/', trim($input)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            if (self::isValidProxyEntry($entry)) {
                $valid[] = $entry;
            } else {
                $invalid[] = $entry;
            }
        }
        return [implode(', ', array_values(array_unique($valid))), $invalid];
    }

    /**
     * True for an IPv4/IPv6 address, or such an address with a prefix length that fits it.
     *
     * @param string $entry One list entry.
     * @return bool
     */
    private static function isValidProxyEntry(string $entry): bool
    {
        if (strpos($entry, '/') === false) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }
        [$net, $bits] = explode('/', $entry, 2);
        if ($bits === '' || !ctype_digit($bits) || filter_var($net, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $max = filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
        return (int) $bits <= $max;
    }

    /**
     * Checks whether an address is a trusted proxy (see trustedProxyEntries()).
     *
     * @param string $ip Address to check.
     * @return bool True when the site has explicitly marked $ip as a trusted proxy.
     */
    private static function isTrustedProxy(string $ip): bool
    {
        foreach (self::trustedProxyEntries() as $entry) {
            if (strpos($entry, '/') !== false) {
                if (self::ipInCidr($ip, $entry)) {
                    return true;
                }
            } elseif (self::sameAddress($entry, $ip)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether two addresses are the same address, whichever way each one is written.
     *
     * An IPv6 address has many spellings ("2001:db8::1" = "2001:0db8:0:0:0:0:0:1").
     *
     * @param string $a First address.
     * @param string $b Second address.
     * @return bool
     */
    private static function sameAddress(string $a, string $b): bool
    {
        // Validated first rather than silencing inet_pton()'s warning, the same way ipInCidr() below does it: an
        // entry that isn't an address at all falls back to comparing the text it was written as.
        if (filter_var($a, FILTER_VALIDATE_IP) === false || filter_var($b, FILTER_VALIDATE_IP) === false) {
            return hash_equals($a, $b);
        }
        $packed_a = inet_pton($a);
        $packed_b = inet_pton($b);
        if ($packed_a === false || $packed_b === false) {
            return hash_equals($a, $b);
        }
        return strlen($packed_a) === strlen($packed_b) && hash_equals($packed_a, $packed_b);
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

        // Validated first: inet_pton() warns on malformed input, and a typo in FABRICATOR_TRUSTED_PROXIES
        // should read as "no match", not as a silenced warning on every request.
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) {
            return false;
        }
        $ipBin     = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
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
