<?php

/**
 * Runs the integration suite (tests/Integration/) outside a release build, with the same test database the build uses:
 * the server named by WP_TESTS_DB_HOST, or the portable MariaDB (tools/testdb.php), started for the run. Dev tooling,
 * never shipped.
 *
 * Usage: php tools/integration.php [PHPUnit options], e.g. `php tools/integration.php --filter EditLockTest`.
 * WP_MULTISITE=1 runs it as a network.
 */

// A dev-only command-line tool: file and process access is its job, without WordPress's APIs. The security linter's
// warning-only sniffs for that are off here; its error-level checks still apply.
// phpcs:disable PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
// phpcs:disable WordPress.WP.AlternativeFunctions

require __DIR__ . '/build-lib.php';
require __DIR__ . '/testdb.php';

$fabintRoot = fabbuildSlashes(dirname(__DIR__));
$fabintCode = 1;
fabbuildWithTestDb(false, 'integration suite', static function (array $env) use ($fabintRoot, $argv, &$fabintCode): void {
    $command    = fabbuildTool(PHP_BINARY, [
        $fabintRoot . '/vendor/bin/phpunit',
        '-c',
        $fabintRoot . '/tests/phpunit-integration.xml.dist',
        ...array_slice($argv, 1),
    ]);
    $fabintCode = fabbuildRun($command, $fabintRoot, $env, false)['code'];
});
exit($fabintCode === 0 ? 0 : 1);
