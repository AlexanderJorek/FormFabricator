<?php

namespace FabricatorForms\Tests\Integration\Support;

/**
 * The release zip, unpacked for the integration suite to load the plugin from when FABRICATOR_TESTS_PACKAGE names it:
 * the "package" group then runs against what ships (trimmed fonts, its vendor/, without the files the build leaves out).
 *
 * The plugin's autoloader registers in front of the runner's, so only the test classes come from the repository. The
 * one change to the unpacked copy: its generated autoloader classes are renamed, as Composer names them after
 * composer.lock and they could clash with the runner's.
 */
final class Package
{
    /**
     * The unpacked plugin's main file, or the repository's when no package is named.
     */
    public static function pluginFile(string $root): string
    {
        $source = (string) getenv('FABRICATOR_TESTS_PACKAGE');
        if ($source === '') {
            return $root . '/formfabricator.php';
        }
        // The zip, or the plugin folder unpacked from it (the build passes one, as test runs need no zip extension).
        $is_zip = is_file($source);
        if (!$is_zip && !is_file($source . '/formfabricator.php')) {
            throw new \RuntimeException('FABRICATOR_TESTS_PACKAGE names neither the release zip nor an unpacked plugin folder: ' . $source);
        }
        // A copy of its own, one folder per source, made again when the source changed and reused by tests that run in
        // a process of their own.
        $dir     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formfabricator-package-' . md5((string) realpath($source));
        $marker  = $dir . DIRECTORY_SEPARATOR . 'source-version';
        $version = $is_zip ? (string) md5_file($source) : self::folderVersion($source);
        if (!is_file($marker) || file_get_contents($marker) !== $version) {
            self::remove($dir);
            $is_zip ? self::unzip($source, $dir) : self::copy($source, $dir . '/formfabricator');
            self::renameClashingAutoloader($dir . '/formfabricator/vendor', $root . '/vendor');
            file_put_contents($marker, $version);
        }
        return $dir . '/formfabricator/formfabricator.php';
    }

    /**
     * Whether the plugin was loaded from a package.
     */
    public static function inUse(): bool
    {
        return (string) getenv('FABRICATOR_TESTS_PACKAGE') !== '';
    }

    private static function unzip(string $zip, string $dir): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Unpacking the release zip needs PHP\'s zip extension; name the unpacked plugin folder instead.');
        }
        $archive = new \ZipArchive();
        if ($archive->open($zip, \ZipArchive::RDONLY) !== true || !$archive->extractTo($dir) || !$archive->close()) {
            throw new \RuntimeException('Could not unpack ' . $zip);
        }
    }

    private static function copy(string $from, string $to): void
    {
        $items = self::walk($from, \RecursiveIteratorIterator::SELF_FIRST);
        mkdir($to, 0777, true);
        foreach ($items as $item) {
            $target = $to . DIRECTORY_SEPARATOR . $items->getSubPathname();
            $item->isDir() ? mkdir($target) : copy($item->getPathname(), $target);
        }
    }

    /**
     * Every file's path, size and time: what a rebuilt package changes.
     */
    private static function folderVersion(string $dir): string
    {
        $items = self::walk($dir, \RecursiveIteratorIterator::LEAVES_ONLY);
        $lines = [];
        foreach ($items as $item) {
            $lines[] = $items->getSubPathname() . ' ' . $item->getSize() . ' ' . $item->getMTime();
        }
        sort($lines);
        return md5(implode("\n", $lines));
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (self::walk($dir, \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private static function walk(string $dir, int $mode): \RecursiveIteratorIterator
    {
        return new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), $mode);
    }

    private static function renameClashingAutoloader(string $packageVendor, string $runnerVendor): void
    {
        $suffix = static fn(string $vendor): string => preg_match('/ComposerAutoloaderInit([0-9A-Za-z_]+)::/', (string) file_get_contents($vendor . '/autoload.php'), $m) ? $m[1] : '';
        $ours   = $suffix($packageVendor);
        if ($ours === '' || $ours !== $suffix($runnerVendor)) {
            return;
        }
        foreach (['autoload.php', 'composer/autoload_real.php', 'composer/autoload_static.php'] as $file) {
            $path = $packageVendor . '/' . $file;
            file_put_contents($path, str_replace($ours, $ours . 'Package', (string) file_get_contents($path)));
        }
    }
}
