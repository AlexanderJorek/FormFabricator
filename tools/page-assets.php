<?php

/**
 * Makes the WordPress.org plugin page's images into .wordpress-org/ (icon, banner, screenshots), from the real plugin
 * on the E2E suite's site: tests/e2e/page-assets/page-assets.spec.js, run as a Playwright project of its own that no
 * test run includes. tools/svn-release.php publishes the folder as the SVN repository's assets/. Dev tooling, never
 * shipped.
 *
 * Usage: php tools/page-assets.php
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

$fabpaRoot = fabbuildSlashes(dirname(__DIR__));
$fabpaCli  = $fabpaRoot . '/node_modules/@playwright/test/cli.js';
if (!is_file($fabpaCli)) {
    fwrite(STDERR, 'Playwright is not installed: run `npm install`, then `node tests/e2e/support/browsers.js --install`.' . PHP_EOL);
    exit(1);
}
$fabpaOut = $fabpaRoot . '/.wordpress-org';
if (!is_dir($fabpaOut)) {
    mkdir($fabpaOut, 0777, true);
}
$fabpaCode = 1;
fabbuildWithTestDb(false, 'Page images', static function (array $env, string $where) use ($fabpaRoot, $fabpaCli, $fabpaOut, &$fabpaCode): void {
    $command   = fabbuildTool('node', [$fabpaCli, 'test', '-c', $fabpaRoot . '/tests/e2e', '--project=page-assets']);
    $fabpaCode = fabbuildRun(
        $command,
        $fabpaRoot,
        $env + ['FABRICATOR_E2E_PHP' => PHP_BINARY, 'FABRICATOR_PAGE_ASSETS' => $fabpaOut],
        false
    )['code'];
});
if ($fabpaCode === 0) {
    fabbuildSay('Page images written to ' . $fabpaOut . ': look at them, commit them, and the next release publishes them.', 'green');
}
exit($fabpaCode === 0 ? 0 : 1);
