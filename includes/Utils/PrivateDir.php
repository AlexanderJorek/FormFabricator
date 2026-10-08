<?php

/**
 * The plugin's private folder in the uploads directory, and the per-submission folders inside it.
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
 * Every file the plugin writes lives in uploads/formfabricator/, a folder only the site writes to and whose server
 * rules deny web access (SecureDir):
 *
 *     pdf/<submission>/    mPDF's temp dir for the submission: its first pass, the final PDF, image copies
 *     mail/<submission>/   the uploads as mail attachments, one subfolder per file
 *     mpdf-cache/          mPDF's font metrics, shared (nothing in it comes from a submission)
 *     verfiles/, verimages/  the verification page's copies (VerifierCleanup)
 *
 * A submission's folders share one random name per request. Each removes itself at the end of the request, and the
 * hourly sweep (sweep()) removes what a request killed before its end left behind. Nothing goes to the system temp
 * dir, which other accounts on the server may share.
 */
final class PrivateDir
{
    /**
     * The folder's name in the uploads directory.
     *
     * @var string
     */
    public const NAME = 'formfabricator';

    /**
     * The kinds of per-submission folder.
     *
     * @var string[]
     */
    public const SUBMISSION_TYPES = ['pdf', 'mail'];

    /**
     * The shared mPDF temp dir for documents without submission data: mPDF keeps its font cache in
     * <tempDir>/mpdf/ttfontdata, and it can't be configured apart from the temp dir.
     *
     * @var string
     */
    private const MPDF_CACHE = 'mpdf-cache';

    /**
     * This request's submission folder name.
     *
     * @var string
     */
    private static string $submission = '';

    /**
     * The folder's path. Not created; see prepare().
     *
     * @return string
     */
    public static function base(): string
    {
        return wp_upload_dir()['basedir'] . '/' . self::NAME;
    }

    /**
     * The folder's URL, for the check that the server really refuses it.
     *
     * @return string
     */
    public static function url(): string
    {
        return rtrim((string) (wp_upload_dir()['baseurl'] ?? ''), '/') . '/' . self::NAME;
    }

    /**
     * Creates and hardens the folder and its shared subfolders when new or removed.
     *
     * @return string The folder's path.
     */
    public static function prepare(): string
    {
        $base = self::base();
        $dirs = [$base, $base . '/' . self::MPDF_CACHE];
        foreach (self::SUBMISSION_TYPES as $type) {
            $dirs[] = $base . '/' . $type;
        }
        // Every folder, so one removed on its own is made and hardened again at once rather than when the transient
        // expires: without mail/, attachments would be left out of every notification meanwhile.
        $missing = array_filter($dirs, static fn(string $dir): bool => !is_dir($dir));
        if (!get_transient('fabricator_private_dir_ready') || $missing !== []) {
            SecureDir::harden($base, $dirs);
            set_transient('fabricator_private_dir_ready', true, DAY_IN_SECONDS);
        }
        return $base;
    }

    /**
     * This request's folder of one kind, created on first use (0700). Its removal at the end of the request is
     * registered before anything is written into it; a shutdown function also runs after a memory or time fatal.
     *
     * @param string $type One of SUBMISSION_TYPES.
     * @return string Its path, or '' when it could not be created.
     */
    public static function forSubmission(string $type): string
    {
        if (!in_array($type, self::SUBMISSION_TYPES, true)) {
            return '';
        }
        if (self::$submission === '') {
            // random_bytes(), not wp_generate_uuid4() (mt_rand()): the name can't be predicted and fetched or planted.
            self::$submission = bin2hex(random_bytes(16));
        }
        $dir = self::prepare() . '/' . $type . '/' . self::$submission;
        if (!is_dir($dir) && !self::makeDir($dir)) {
            \FabricatorForms\fabricator_log('FabricatorForms PrivateDir: could not create ' . $dir);
            return '';
        }
        register_shutdown_function([self::class, 'removeTree'], $dir);
        return $dir;
    }

    /**
     * Creates a folder only PHP's user may enter.
     *
     * @param string $dir Folder to create; its parent must exist.
     * @return bool
     */
    public static function makeDir(string $dir): bool
    {
        // mkdir() with 0700, not wp_mkdir_p(), which copies the parent's mode.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- runs in front-end submissions without WP_Filesystem credentials; $dir is built from the uploads dir and random_bytes(), never request input.
        return (bool) Cast::withoutWarnings(static fn() => mkdir($dir, 0700));
    }

    /**
     * mPDF's temp dir for documents without submission data. Its font cache is the one linkFontCache() shares.
     *
     * @return string
     */
    public static function sharedMpdfTemp(): string
    {
        return self::prepare() . '/' . self::MPDF_CACHE;
    }

    /**
     * Gives a submission's mPDF temp dir the shared font cache before mPDF starts: each file hard-linked, or copied
     * where links fail. mPDF replaces a cache file by renaming a new one over it, so it never writes through a link
     * into the shared copy.
     *
     * @param string $temp_dir A forSubmission('pdf') folder.
     * @return void
     */
    public static function linkFontCache(string $temp_dir): void
    {
        $own = $temp_dir . '/mpdf/ttfontdata';
        if (!wp_mkdir_p($own)) {
            return;
        }
        foreach (glob(self::sharedFontCache() . '/*') ?: [] as $file) {
            $target = $own . '/' . basename($file);
            if (is_file($file) && !is_link($file) && !file_exists($target)
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy,PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- mPDF's own cache files between two folders of this plugin.
                && !Cast::withoutWarnings(static fn() => link($file, $target)) && !Cast::withoutWarnings(static fn() => copy($file, $target))
            ) {
                return;
            }
        }
    }

    /**
     * Moves the font metrics mPDF made during a submission into the shared cache, so the next one finds them. Each
     * file depends on its font alone, never on a submission's text. rename() replaces atomically, so a request
     * reading the shared file meanwhile sees the old or the new one, never half of one.
     *
     * @param string $temp_dir A forSubmission('pdf') folder.
     * @return void
     */
    public static function shareFontCache(string $temp_dir): void
    {
        $shared = self::sharedFontCache();
        if (!wp_mkdir_p($shared)) {
            return;
        }
        foreach (glob($temp_dir . '/mpdf/ttfontdata/*') ?: [] as $file) {
            $name = basename($file);
            // mPDF's own half-written temp files (Cache::write()) stay behind.
            if (str_starts_with($name, 'cache_tmp_') || !is_file($file) || is_link($file) || file_exists($shared . '/' . $name)) {
                continue;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- an atomic move between two folders of this plugin; WP_Filesystem's move() copies and deletes.
            Cast::withoutWarnings(static fn() => rename($file, $shared . '/' . $name));
        }
    }

    /**
     * Where mPDF keeps the shared font metrics.
     *
     * @return string
     */
    private static function sharedFontCache(): string
    {
        return self::sharedMpdfTemp() . '/mpdf/ttfontdata';
    }

    /**
     * Removes a folder and everything in it. A link is removed itself, never followed.
     *
     * @param string $dir Folder to remove.
     * @return void
     */
    public static function removeTree(string $dir): void
    {
        $dir = rtrim($dir, '/\\');
        if ($dir === '' || is_link($dir) || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
                continue;
            }
            wp_delete_file($path);
            if (file_exists($path) || is_link($path)) {
                // The file's own name may be a visitor's, so it is logged only as fabricator_log_file() records it.
                \FabricatorForms\fabricator_log('FabricatorForms PrivateDir: failed to remove ' . $dir . '/' . \FabricatorForms\fabricator_log_file($name));
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- an emptied folder of this plugin, removed at shutdown or by WP-Cron, where no WP_Filesystem credentials can be asked for.
        if (!Cast::withoutWarnings(static fn() => rmdir($dir))) {
            \FabricatorForms\fabricator_log('FabricatorForms PrivateDir: failed to remove folder ' . $dir);
        }
    }

    /**
     * Removes the submission folders unchanged for more than $max_age seconds: what a request killed before its end
     * (a crash, or the process stopped from outside) left behind.
     *
     * @param int $max_age Seconds since the last change; younger ones may belong to a request still running.
     * @return void
     */
    public static function sweep(int $max_age): void
    {
        $base = self::base();
        $now  = time();
        foreach (self::SUBMISSION_TYPES as $type) {
            foreach (glob($base . '/' . $type . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                if (preg_match('/^[0-9a-f]{32}$/D', basename($dir)) !== 1) {
                    continue;
                }
                // A concurrent request may remove the folder meanwhile; false is then the right answer.
                $mtime = Cast::withoutWarnings(static fn() => filemtime($dir));
                if ($mtime !== false && ($now - $mtime) > $max_age) {
                    self::removeTree($dir);
                }
            }
        }
    }
}
