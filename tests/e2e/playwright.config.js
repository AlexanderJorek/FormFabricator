'use strict';

/*
 * The E2E suite (CONTRIBUTING.md, "E2E suite"). The web server step installs the site afresh (site/install.php), then
 * serves it with `php -S`. Run it through `php tools/e2e.php`, or `npx playwright test -c tests/e2e` with a database
 * already running.
 *
 * One worker: every spec shares the one site, and on Windows `php -S` answers one request at a time.
 */

const os = require('node:os');
const path = require('node:path');
const { defineConfig, devices } = require('@playwright/test');
const { chromeChannel } = require('./support/browsers');

const ROOT = path.resolve(__dirname, '..', '..');
const PORT = Number(process.env.FABRICATOR_E2E_PORT || 8899);
const URL = 'http://127.0.0.1:' + PORT;
// Logins, results and the site's content folder. A run started beside others names its own (FABRICATOR_E2E_STATE).
const STATE = process.env.FABRICATOR_E2E_STATE || path.join(os.tmpdir(), 'formfabricator-e2e');
// Shared with the PHP server and the CLI helpers (tests/e2e/support/site.js) through the environment.
process.env.FABRICATOR_E2E_URL = URL;
process.env.FABRICATOR_E2E_CONTENT = process.env.FABRICATOR_E2E_CONTENT || path.join(STATE, 'wp-content');
process.env.FABRICATOR_E2E_STATE = STATE;
const PHP = process.env.FABRICATOR_E2E_PHP || 'php';

const quote = (s) => '"' + s + '"';

module.exports = defineConfig({
    testDir: path.join(__dirname, 'specs'),
    outputDir: path.join(STATE, 'results'),
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: true,
    timeout: 90000,
    expect: { timeout: 15000 },
    reporter: [['list']],
    use: {
        baseURL: URL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: [
            quote(PHP) + ' tests/e2e/site/install.php',
            // The server's request log is noise here; the site's own errors go to its debug.log (WP_DEBUG_LOG).
            quote(PHP) + ' -S 127.0.0.1:' + PORT + ' -t vendor/roots/wordpress-no-content tests/e2e/site/router.php 2>'
                + (process.platform === 'win32' ? 'NUL' : '/dev/null'),
        ].join(' && '),
        cwd: ROOT,
        url: URL + '/wp-login.php',
        reuseExistingServer: false,
        timeout: 180000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.js/, use: { ...devices['Desktop Chrome'], channel: chromeChannel } },
        { name: 'chromium', use: { ...devices['Desktop Chrome'], channel: chromeChannel }, dependencies: ['setup'] },
        { name: 'firefox', use: { ...devices['Desktop Firefox'] }, dependencies: ['setup'] },
        { name: 'webkit', use: { ...devices['Desktop Safari'] }, dependencies: ['setup'] },
    ],
});
