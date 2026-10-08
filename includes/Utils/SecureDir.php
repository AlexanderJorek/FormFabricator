<?php

/**
 * Shared content for locking down plugin-private upload subdirectories.
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
 * .htaccess only blocks Apache/LiteSpeed; this adds the IIS web.config — Nginx/Caddy still need their own deny rule.
 */
class SecureDir
{

    // Written into every directory this plugin creates, so a misconfigured host with directory indexes on serves this instead of a listing.
    // Explicit, so the literal never depends on this file's own line endings.
    private const NL = "\n";

    private const SILENCE = '<?php // Silence is golden ?>';

    // Apache/LiteSpeed deny rule, in one place for every folder harden() guards.
    private const HTACCESS = "Options -Indexes" . self::NL
        . '<IfModule mod_authz_core.c>' . self::NL
        . 'Require all denied' . self::NL
        . '</IfModule>' . self::NL
        . '<IfModule !mod_authz_core.c>' . self::NL
        . 'Deny from all' . self::NL
        . '</IfModule>' . self::NL;

    /**
     * Creates the given directories and writes every hardening file.
     *
     * @param string   $base Directory receiving .htaccess and web.config (the tree root).
     * @param string[] $dirs Absolute directories to create and silence; defaults to [$base].
     * @return void
     */
    public static function harden(string $base, array $dirs = []): void
    {
        if ($dirs === []) {
            $dirs = [$base];
        }
        $fs = self::filesystem();

        // Explicit modes, never umask(), which is process-wide under a threaded SAPI.
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                // A folder that can't be made (uploads not writable, a full disk, a file in its place) is reported by
                // its caller's next step; setting its mode or writing into it would only add PHP warnings.
                if (!wp_mkdir_p($dir)) {
                    \FabricatorForms\fabricator_log('FabricatorForms SecureDir: could not create ' . $dir . '.');
                    continue;
                }
                self::chmodPath($fs, $dir, 0750);
            }

            // Keyed off the silence file, not directory existence, so a failed silence write self-heals on the next call.
            $silence = $dir . '/index.php';
            if (!file_exists($silence)) {
                self::chmodPath($fs, $dir, 0750);
                self::write($fs, $silence, self::SILENCE);
                self::chmodPath($fs, $silence, 0640);
            }
        }

        if (!is_dir($base)) {
            return;
        }
        // Compared by size, not just existence: a truncated guard file still passes file_exists() while denying nothing.
        self::ensureGuardFile($fs, $base . '/.htaccess', self::HTACCESS);
        // .htaccess only covers Apache/LiteSpeed; this is the IIS-equivalent deny rule.
        self::ensureGuardFile($fs, $base . '/web.config', self::WEB_CONFIG);
    }

    /**
     * Writes a deny-rule file when it is absent or the wrong length.
     *
     * @param \WP_Filesystem_Base|null $fs       Filesystem API, or null when unavailable.
     * @param string                   $path     Absolute path to the guard file.
     * @param string                   $contents Expected contents.
     * @return void
     */
    private static function ensureGuardFile(?\WP_Filesystem_Base $fs, string $path, string $contents): void
    {
        // Length, not full content comparison, so an operator's deliberately extended rules aren't reverted.
        if (file_exists($path) && filesize($path) >= strlen($contents)) {
            return;
        }
        self::write($fs, $path, $contents);
        self::chmodPath($fs, $path, 0640);
    }

    /**
     * Returns WP_Filesystem only for the 'direct' transport — a non-direct method (ftp/ssh) would need credentials that can't be requested mid-AJAX.
     *
     * @return \WP_Filesystem_Base|null
     */
    private static function filesystem(): ?\WP_Filesystem_Base
    {
        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            // wp-admin/includes/file.php is not loaded on front-end requests.
            if (!is_admin() && !wp_doing_cron()) {
                return null;
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (get_filesystem_method() !== 'direct') {
            return null;
        }
        if (!$wp_filesystem instanceof \WP_Filesystem_Base) {
            WP_Filesystem();
        }
        return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
    }

    /**
     * Writes a plugin-owned file, through WP_Filesystem when that is direct, as harden() does.
     *
     * @param string $path     Absolute path to write.
     * @param string $contents File contents.
     * @param int    $mode     Octal permission bits for the created file.
     * @return bool True on success.
     */
    public static function putFile(string $path, string $contents, int $mode = 0640): bool
    {
        return self::write(self::filesystem(), $path, $contents, $mode);
    }

    /**
     * Writes a file through WP_Filesystem when available, else directly.
     *
     * @param \WP_Filesystem_Base|null $fs       Filesystem API, or null when unavailable.
     * @param string                   $path     Absolute path to write.
     * @param string                   $contents File contents.
     * @param int                      $mode     Octal permission bits for the created file.
     * @return bool True on success.
     */
    private static function write(?\WP_Filesystem_Base $fs, string $path, string $contents, int $mode = 0640): bool
    {
        if ($fs !== null) {
            return (bool) $fs->put_contents($path, $contents, $mode);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fallback when WP_Filesystem needs credentials unavailable mid-request; path is always plugin-owned under wp-content/uploads.
        if (file_put_contents($path, $contents) === false) {
            return false;
        }
        // The WP_Filesystem branch applies $mode itself; the direct write must too, or callers asking for 0600 get the umask default.
        self::chmodPath(null, $path, $mode);
        return true;
    }

    /**
     * Applies permissions through WP_Filesystem when available, else directly.
     *
     * @param \WP_Filesystem_Base|null $fs   Filesystem API, or null when unavailable.
     * @param string                   $path Absolute path.
     * @param int                      $mode Octal permission bits.
     * @return void
     */
    private static function chmodPath(?\WP_Filesystem_Base $fs, string $path, int $mode): void
    {
        $ok = $fs !== null
            ? (bool) $fs->chmod($path, $mode)
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same rationale as write() above; defense-in-depth on plugin-owned paths.
            : (bool) Cast::withoutWarnings(static fn() => chmod($path, $mode));
        if (!$ok) {
            // Not fatal (the deny rules still apply), but a directory left group/world-readable should be visible to whoever debugs the host.
            \FabricatorForms\fabricator_log('FabricatorForms SecureDir: could not set mode ' . decoct($mode) . ' on ' . $path . '.');
        }
    }

    // IIS deny rule via Request Filtering, which every IIS 7+ has (URL Authorization is optional): every extension,
    // plus extensionless and directory URLs. Concatenated strings: WordPress.org rejects HEREDOC/NOWDOC.
    public const WEB_CONFIG = '<?xml version="1.0" encoding="UTF-8"?>'
        . "\n" .
        '<configuration>'
        . "\n" .
        '    <system.webServer>'
        . "\n" .
        '        <security>'
        . "\n" .
        '            <requestFiltering>'
        . "\n" .
        '                <fileExtensions allowUnlisted="false" />'
        . "\n" .
        '                <hiddenSegments>'
        . "\n" .
        '                    <add segment="formfabricator" />'
        . "\n" .
        '                </hiddenSegments>'
        . "\n" .
        '            </requestFiltering>'
        . "\n" .
        '        </security>'
        . "\n" .
        '    </system.webServer>'
        . "\n" .
        '</configuration>';
}
