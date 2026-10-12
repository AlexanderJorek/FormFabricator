<?php

/**
 * The tools for the Plugin Check release gate (tools/plugincheck.php): WordPress.org's Plugin Check plugin and WP-CLI,
 * which runs it. Each is downloaded once, checked against the SHA-256 pinned below before anything is used or
 * unpacked, and kept outside the repository, beside the test database (tools/testdb.php). Dev tooling, never shipped.
 *
 * fabpcReport() reads what `wp plugin check` prints.
 *
 * Updating either: change the version and its SHA-256 together. WP-CLI publishes the hash beside each release
 * (wp-cli-<version>.phar.sha256); for Plugin Check, hash the zip from downloads.wordpress.org.
 */

// A dev-only command-line tool: file access is its job, without WordPress's APIs. The security linter's warning-only
// sniffs for that are off here; its error-level checks still apply.
// phpcs:disable PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.WP.AlternativeFunctions

const FABPC_PLUGIN_CHECK_VERSION = '2.1.0';
const FABPC_PLUGIN_CHECK_SHA256  = '6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4';
const FABPC_WP_CLI_VERSION       = '2.12.0';
const FABPC_WP_CLI_SHA256        = 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c';

/**
 * Where the tools are kept: %LOCALAPPDATA%\FormFabricator\plugin-check on Windows, the user's cache folder elsewhere.
 */
function fabpcHome(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return fabbuildSlashes((string) getenv('LOCALAPPDATA')) . '/FormFabricator/plugin-check';
    }
    $cache = (string) (getenv('XDG_CACHE_HOME') ?: rtrim((string) getenv('HOME'), '/') . '/.cache');
    return $cache . '/formfabricator/plugin-check';
}

/**
 * WP-CLI's phar.
 */
function fabpcWpCli(): string
{
    return fabpcHome() . '/wp-cli-' . FABPC_WP_CLI_VERSION . '.phar';
}

/**
 * The unpacked Plugin Check plugin (the folder holding plugin.php and cli.php).
 */
function fabpcPluginCheck(): string
{
    return fabpcHome() . '/plugin-check-' . FABPC_PLUGIN_CHECK_VERSION . '/plugin-check';
}

/**
 * Whether both tools are there, WP-CLI with its pinned hash.
 */
function fabpcReady(): bool
{
    return is_file(fabpcWpCli()) && hash_file('sha256', fabpcWpCli()) === FABPC_WP_CLI_SHA256
        && is_file(fabpcPluginCheck() . '/plugin.php') && is_file(fabpcPluginCheck() . '/cli.php');
}

/**
 * Downloads, verifies and unpacks what is missing. True when both tools are ready; false when one is missing and
 * $offline forbids the download.
 */
function fabpcInstall(bool $offline): bool
{
    if (fabpcReady()) {
        return true;
    }
    if ($offline) {
        return false;
    }
    if (!is_dir(fabpcHome())) {
        mkdir(fabpcHome(), 0777, true);
    }
    if (!is_file(fabpcWpCli()) || hash_file('sha256', fabpcWpCli()) !== FABPC_WP_CLI_SHA256) {
        fabpcDownload(
            'WP-CLI ' . FABPC_WP_CLI_VERSION,
            'https://github.com/wp-cli/wp-cli/releases/download/v' . FABPC_WP_CLI_VERSION . '/wp-cli-' . FABPC_WP_CLI_VERSION . '.phar',
            fabpcWpCli(),
            FABPC_WP_CLI_SHA256
        );
    }
    if (!is_file(fabpcPluginCheck() . '/plugin.php') || !is_file(fabpcPluginCheck() . '/cli.php')) {
        $zip = fabpcHome() . '/plugin-check.' . FABPC_PLUGIN_CHECK_VERSION . '.zip';
        fabpcDownload(
            'Plugin Check ' . FABPC_PLUGIN_CHECK_VERSION,
            'https://downloads.wordpress.org/plugin/plugin-check.' . FABPC_PLUGIN_CHECK_VERSION . '.zip',
            $zip,
            FABPC_PLUGIN_CHECK_SHA256
        );
        // A folder from an interrupted unpack may be missing files; unpack it whole again.
        $target = dirname(fabpcPluginCheck());
        fabbuildRemove($target);
        fabpcExpand($zip, $target);
        unlink($zip);
    }
    return fabpcReady();
}

/**
 * Downloads $url to $file, keeping it only when its SHA-256 is $sha256.
 */
function fabpcDownload(string $what, string $url, string $file, string $sha256): void
{
    fabbuildSay('  Downloading ' . $what . ' for the Plugin Check gate (once)...', 'cyan');
    $context = stream_context_create(['http' => ['user_agent' => 'FormFabricator-build', 'follow_location' => 1]]);
    if (!@copy($url, $file, $context)) {
        fabbuildFail('The ' . $what . ' download failed (' . $url . '). PHP needs the openssl extension for it.');
    }
    if (hash_file('sha256', $file) !== $sha256) {
        unlink($file);
        fabbuildFail('The ' . $what . ' download does not match its pinned SHA-256. Nothing was used.');
    }
}

/**
 * Unpacks $zip into $target, refusing an entry that would land outside it.
 */
function fabpcExpand(string $zip, string $target): void
{
    $archive = new ZipArchive();
    if ($archive->open($zip, ZipArchive::RDONLY) !== true) {
        fabbuildFail('Could not open ' . $zip);
    }
    for ($i = 0; $i < $archive->numFiles; $i++) {
        $name = (string) $archive->getNameIndex($i);
        if (preg_match('~(^|/)\.\.(/|$)~', $name) || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('~^[A-Za-z]:~', $name)) {
            $archive->close();
            fabbuildFail('Refused the archive entry ' . $name . ' in ' . $zip);
        }
    }
    if (!$archive->extractTo($target) || !$archive->close()) {
        fabbuildFail('Could not unpack ' . $zip);
    }
}

/**
 * Prints Plugin Check's findings and returns the exit code: 0 for none. `wp plugin check` exits 0 whatever it finds,
 * so its output decides: a "FILE: <path>" line, then that file's findings as one JSON array, per file; or one
 * "Success" line when there are none. Output that is neither fails too.
 */
function fabpcReport(int $exit, string $output, string $plugin): int
{
    // Paths as the plugin's own: Plugin Check names some files by their full path, some relative to the plugin.
    $base = rtrim(fabbuildSlashes($plugin), '/') . '/';
    $lines    = preg_split('/\R/', $output) ?: [];
    $findings = [];
    $success  = false;
    foreach ($lines as $i => $line) {
        if (str_starts_with($line, 'Success: Checks complete.')) {
            $success = true;
        }
        if (!str_starts_with($line, 'FILE: ')) {
            continue;
        }
        $file  = fabbuildSlashes(trim(substr($line, 6)));
        if (stripos($file, $base) === 0) {
            $file = substr($file, strlen($base));
        }
        $items = json_decode((string) ($lines[$i + 1] ?? ''), true);
        if (!is_array($items)) {
            $findings[] = ['type' => 'ERROR', 'file' => $file, 'line' => 0, 'code' => 'unreadable', 'message' => 'Plugin Check reported this file, but its findings could not be read.'];
            continue;
        }
        foreach ($items as $item) {
            $findings[] = [
                'type'    => (string) ($item['type'] ?? 'ERROR'),
                'file'    => $file,
                'line'    => (int) ($item['line'] ?? 0),
                'code'    => (string) ($item['code'] ?? ''),
                'message' => html_entity_decode(strip_tags((string) ($item['message'] ?? '')), ENT_QUOTES | ENT_HTML5),
            ];
        }
    }
    foreach ($findings as $f) {
        fabbuildSay($f['type'] . ' ' . $f['file'] . ($f['line'] ? ':' . $f['line'] : '') . ' [' . $f['code'] . '] ' . $f['message'], $f['type'] === 'ERROR' ? 'red' : 'yellow');
    }
    if ($findings !== []) {
        fabbuildSay(count($findings) . ' Plugin Check finding(s). Errors stop a WordPress.org submission; warnings get asked about in its review.', 'red');
        return 1;
    }
    if ($exit !== 0 || !$success) {
        fabbuildSay(rtrim($output), 'red');
        fabbuildSay('Plugin Check gave no result.', 'red');
        return 1;
    }
    fabbuildSay('Plugin Check: no errors, no warnings.', 'green');
    return 0;
}
