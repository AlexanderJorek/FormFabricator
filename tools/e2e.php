<?php

/**
 * Runs the E2E suite (tests/e2e/) outside a release build, with the same test database the build uses: the server named
 * by WP_TESTS_DB_HOST, or the portable MariaDB (tools/testdb.php), started for the run. Dev tooling, never shipped.
 *
 * Usage: php tools/e2e.php [Playwright options], e.g. `php tools/e2e.php --project=webkit builder`.
 * FABRICATOR_E2E_PLUGIN names another plugin folder to test (the release build passes the plugin unpacked from its zip).
 */

require __DIR__ . '/build-lib.php';
require __DIR__ . '/testdb.php';

$fabe2eRoot = fabbuildSlashes(dirname(__DIR__));
$fabe2eCli  = $fabe2eRoot . '/node_modules/@playwright/test/cli.js';
if (!is_file($fabe2eCli)) {
    fwrite(STDERR, 'Playwright is not installed: run `npm install`, then `node tests/e2e/support/browsers.js --install`.' . PHP_EOL);
    exit(1);
}
$fabe2eCode = 1;
fabbuildWithTestDb(false, 'E2E suite', static function (array $env, string $where) use ($fabe2eRoot, $fabe2eCli, $argv, &$fabe2eCode): void {
    $command    = fabbuildTool('node', [$fabe2eCli, 'test', '-c', $fabe2eRoot . '/tests/e2e', ...array_slice($argv, 1)]);
    $fabe2eCode = fabbuildRun($command, $fabe2eRoot, $env + ['FABRICATOR_E2E_PHP' => PHP_BINARY], false)['code'];
});
exit($fabe2eCode === 0 ? 0 : 1);
