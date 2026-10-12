<?php

/**
 * Runs WordPress.org's Plugin Check on the plugin, as the release build does on the plugin unpacked from its zip: the
 * same checks WordPress.org's review runs, static and runtime, in a real WordPress site. Any error or warning fails;
 * each is listed with its file, line and code. Dev tooling, never shipped.
 *
 * The site is the E2E suite's (tests/e2e/site/install.php) with tables and a content folder of its own, Plugin Check
 * linked in beside the plugin, on the test database the build uses: the server named by WP_TESTS_DB_HOST, or the
 * portable MariaDB, started for the run. Plugin Check and WP-CLI come from tools/plugincheck-lib.php.
 *
 * Usage: php tools/plugincheck.php [plugin folder]   (default: build/formfabricator, the last release build's copy)
 * The release build sets FABRICATOR_E2E_PLUGIN, FABRICATOR_E2E_CONTENT and FABRICATOR_E2E_TABLE_PREFIX instead.
 */

// A dev-only command-line tool: file and process access is its job, without WordPress's APIs. The security linter's
// warning-only sniffs for that are off here; its error-level checks still apply.
// phpcs:disable PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
// phpcs:disable WordPress.WP.AlternativeFunctions

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/build-lib.php';
require __DIR__ . '/testdb.php';
require __DIR__ . '/plugincheck-lib.php';

$fabpcRoot   = fabbuildSlashes(dirname(__DIR__));
$fabpcPlugin = fabbuildSlashes((string) ($argv[1] ?? (getenv('FABRICATOR_E2E_PLUGIN') ?: $fabpcRoot . '/build/formfabricator')));
if (!is_file($fabpcPlugin . '/formfabricator.php')) {
    fwrite(STDERR, 'No plugin at ' . $fabpcPlugin . ': run the release build first, or name a folder holding formfabricator.php.' . PHP_EOL);
    exit(1);
}
if (!fabpcReady()) {
    if (!extension_loaded('zip')) {
        fwrite(STDERR, 'Plugin Check is not downloaded yet: run `php tools/build.php --setup` once (it needs PHP\'s zip extension, which the build loads itself).' . PHP_EOL);
        exit(1);
    }
    fabpcInstall(false);
}
$fabpcContent = fabbuildSlashes((string) (getenv('FABRICATOR_E2E_CONTENT') ?: sys_get_temp_dir() . '/formfabricator-plugincheck/wp-content'));
$fabpcPrefix  = (string) (getenv('FABRICATOR_E2E_TABLE_PREFIX') ?: 'pc_');
// Plugin Check skips every file under a folder named .git, matching the whole path: a site in there would show it an
// empty plugin, and the check would pass on nothing.
if (preg_match('~(^|/)\.git(/|$)~i', $fabpcContent)) {
    fwrite(STDERR, 'The Plugin Check site must not lie under a .git folder: ' . $fabpcContent . PHP_EOL);
    exit(1);
}

$fabpcCode = 1;
try {
    fabbuildWithTestDb(false, 'Plugin Check', static function (array $env, string $where) use ($fabpcRoot, $fabpcPlugin, $fabpcContent, $fabpcPrefix, &$fabpcCode): void {
        $env += [
            'FABRICATOR_E2E_PLUGIN'       => $fabpcPlugin,
            'FABRICATOR_E2E_CONTENT'      => $fabpcContent,
            'FABRICATOR_E2E_TABLE_PREFIX' => $fabpcPrefix,
            // Nothing is served; the site still needs an address.
            'FABRICATOR_E2E_URL'          => 'http://127.0.0.1:8897',
        ];
        $install = fabbuildRun([PHP_BINARY, $fabpcRoot . '/tests/e2e/site/install.php'], $fabpcRoot, $env);
        if ($install['code'] !== 0) {
            fabbuildSay(rtrim($install['output']), 'red');
            fabbuildFail('The Plugin Check site could not be installed.');
        }
        fabbuildLink(fabpcPluginCheck(), $fabpcContent . '/plugins/plugin-check');

        // WP-CLI's own phar raises deprecation notices on newer PHP; they are no finding of the plugin's.
        $wp = [PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', fabpcWpCli(), '--path=' . $fabpcRoot . '/vendor/roots/wordpress-no-content'];
        $activate = fabbuildRun([...$wp, 'plugin', 'activate', 'plugin-check'], $fabpcRoot, $env);
        if ($activate['code'] !== 0) {
            fabbuildSay(rtrim($activate['output']), 'red');
            fabbuildFail('Plugin Check could not be activated.');
        }
        // --require loads Plugin Check's runtime checks (they need a hook early in WordPress's start).
        $check = fabbuildRun(
            [...$wp, '--require=' . fabpcPluginCheck() . '/cli.php', 'plugin', 'check', 'formfabricator', '--format=json'],
            $fabpcRoot,
            $env
        );
        $fabpcCode = fabpcReport($check['code'], $check['output'], $fabpcPlugin);
    });
} catch (RuntimeException $e) {
    // fabbuildFail() has printed the reason already; anything else is shown here.
    if ($e->getCode() !== FABBUILD_FAILED) {
        fwrite(STDERR, (string) $e . PHP_EOL);
    }
    exit(1);
}
exit($fabpcCode === 0 ? 0 : 1);
