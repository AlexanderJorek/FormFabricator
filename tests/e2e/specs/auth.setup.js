'use strict';

/*
 * Logs in the site's two administrators once per run, through wp-login.php as a person would, and saves each login
 * for the specs (support/site.js `auth`).
 */

const { test: setup, expect } = require('@playwright/test');
const { auth } = require('../support/site');

for (const [login, file] of Object.entries(auth)) {
    setup('log in as ' + login, async ({ page }) => {
        await page.goto('/wp-login.php');
        await page.locator('#user_login').fill(login);
        await page.locator('#user_pass').fill('e2e-password');
        await page.locator('#wp-submit').click();
        await expect(page.locator('#wpadminbar')).toBeVisible();
        await page.context().storageState({ path: file });
    });
}
