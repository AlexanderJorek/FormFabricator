'use strict';

/*
 * Which browsers the E2E suite runs in, shared by playwright.config.js and the release build:
 *
 * - Chromium: the installed Google Chrome where there is one (channel "chrome", nothing to download), Playwright's own
 *   Chromium otherwise, or always with FABRICATOR_E2E_CHROMIUM=bundled.
 * - Firefox and WebKit (Safari's engine): Playwright's own builds; an installed Firefox cannot be driven.
 *
 * `node tests/e2e/support/browsers.js --install` downloads the ones missing (tools/build.php runs it).
 */

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const CHROME_PATHS = {
    win32: [path.join(process.env.PROGRAMFILES || 'C:\\Program Files', 'Google', 'Chrome', 'Application', 'chrome.exe'),
        path.join(process.env['PROGRAMFILES(X86)'] || 'C:\\Program Files (x86)', 'Google', 'Chrome', 'Application', 'chrome.exe'),
        path.join(process.env.LOCALAPPDATA || '', 'Google', 'Chrome', 'Application', 'chrome.exe')],
    darwin: ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'],
    linux: ['/opt/google/chrome/chrome', '/usr/bin/google-chrome'],
}[process.platform] || [];

const chromeChannel = process.env.FABRICATOR_E2E_CHROMIUM !== 'bundled' && CHROME_PATHS.some((p) => p && fs.existsSync(p)) ? 'chrome' : undefined;

/** The Playwright browser builds the suite needs downloaded. */
const downloads = [...(chromeChannel ? [] : ['chromium']), 'firefox', 'webkit'];

module.exports = { chromeChannel, downloads };

if (require.main === module && process.argv.includes('--install')) {
    const cli = require.resolve('@playwright/test/cli');
    const result = spawnSync(process.execPath, [cli, 'install', ...downloads], { stdio: 'inherit' });
    process.exit(result.status === null ? 1 : result.status);
}
