'use strict';

/*
 * Two administrators on the same screen, TESTING.md §2: the second is told who is editing, and once the first closes
 * the tab, the real browser's sendBeacon() releases the lock and the second's notice clears at the next heartbeat.
 * The heartbeat is asked for at once (wp.heartbeat.connectNow()) rather than waited for.
 */

const { test, expect } = require('@playwright/test');
const { auth, wp, uniqueName, notification } = require('../support/site');

// The form editor locks each form as a post lock; Settings and PDF Layout hold one lock each in an option.
const screens = [
    { name: 'form editor', url: (id) => '/wp-admin/admin.php?page=fabricator-forms-editor&form_id=' + id },
    { name: 'Settings', url: () => '/wp-admin/admin.php?page=fabricator-forms-settings', lock: 'fabricator_lock_settings' },
    { name: 'PDF Layout', url: () => '/wp-admin/admin.php?page=fabricator-forms-pdf-layout', lock: 'fabricator_lock_pdf_layout' },
];

for (const screen of screens) {
    test(screen.name + ': the "being edited by" notice clears once the other administrator closes the tab', async ({ browser }) => {
        const { id } = wp({ do: 'form', title: uniqueName('Lock'), fields: [{ id: 'name', type: 'text', label: 'Name' }], notifications: [notification()] });
        // Free, whatever an earlier browser's run of this test left: its second administrator took the lock over.
        if (screen.lock) {
            wp({ do: 'option', name: screen.lock, value: null });
        }
        const first = await browser.newContext({ storageState: auth.admin });
        const second = await browser.newContext({ storageState: auth.admin2 });
        try {
            const ada = await first.newPage();
            await ada.goto(screen.url(id));
            await expect(ada.locator('#fabricator-lock-notice')).toBeHidden();

            const bob = await second.newPage();
            await bob.goto(screen.url(id));
            const notice = bob.locator('#fabricator-lock-notice');
            await expect(notice).toBeVisible();
            await expect(notice).toContainText('Currently being edited by Ada Admin');

            // Closing the tab: its pagehide handler sends the beacon. Without it the lock would stay for its 150-second
            // window, and the notice could not clear in time. Not runBeforeUnload: Playwright's WebKit then leaves
            // the page open, and no browser fires pagehide.
            await ada.close();

            await expect(async () => {
                await bob.evaluate(() => window.wp && window.wp.heartbeat && window.wp.heartbeat.connectNow());
                await expect(notice).toBeHidden({ timeout: 3000 });
            }).toPass({ timeout: 45000 });
        } finally {
            // Each page closed as a tab is, so its beacon releases what it holds; closing a context sends none.
            for (const p of [...first.pages(), ...second.pages()]) {
                await p.close();
            }
            await first.close();
            await second.close();
        }
    });
}
