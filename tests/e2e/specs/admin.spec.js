'use strict';

/*
 * Admin screens in a real browser, TESTING.md §1, §2, §3 and §5: other plugins' and core notices in the notice dock,
 * the particle background still under reduced motion and when set to Static, WordPress's own "Copy suggested policy
 * text" button, and the PDF verifier's upload: its overlay on top of everything while the PDFs upload, and no
 * resubmission when the visitor reloads or goes back afterwards.
 */

const { test, expect } = require('@playwright/test');
const { auth, wp } = require('../support/site');

test.use({ storageState: auth.admin });

const SCREENS = {
    'form list': '/wp-admin/admin.php?page=fabricator-forms',
    'form selections': '/wp-admin/admin.php?page=fabricator-forms-select',
    'settings': '/wp-admin/admin.php?page=fabricator-forms-settings',
    'PDF layout': '/wp-admin/admin.php?page=fabricator-forms-pdf-layout',
    'PDF verification': '/wp-admin/admin.php?page=fabricator-pdf-verification',
};

for (const [name, url] of Object.entries(SCREENS)) {
    test(name + ': a core or plugin notice shows, in the notice dock', async ({ page }) => {
        await page.goto(url);
        const notice = page.locator('#e2e-sample-notice');
        await expect(notice).toBeVisible();
        await expect(page.locator('.fabricator-notice-dock #e2e-sample-notice')).toHaveCount(1);
    });
}

test('form editor: a core or plugin notice floats at the bottom right, above the builder', async ({ page }) => {
    const { id } = wp({ do: 'form', title: 'Notice', fields: [{ id: 'name', type: 'text', label: 'Name' }] });
    await page.goto('/wp-admin/admin.php?page=fabricator-forms-editor&form_id=' + id);
    const notice = page.locator('.fabricator-notice-dock #e2e-sample-notice');
    await expect(notice).toBeVisible();
    const box = await notice.boundingBox();
    const view = page.viewportSize();
    expect(box.x + box.width).toBeGreaterThan(view.width - 40);
    expect(box.y + box.height).toBeGreaterThan(view.height - 40);
    const onTop = await notice.evaluate((el) => {
        const r = el.getBoundingClientRect();
        return el.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
    });
    expect(onTop).toBe(true);
});

/** Two pictures of the particle canvas `ms` apart: equal when the background stands still. */
async function canvasMoves(page, ms = 700) {
    const canvas = page.locator('#fabricator-particle-canvas');
    await expect(canvas).toBeVisible();
    await page.waitForTimeout(300);
    const first = await canvas.evaluate((c) => c.toDataURL());
    await page.waitForTimeout(ms);
    const second = await canvas.evaluate((c) => c.toDataURL());
    return first !== second;
}

test.describe('the particle background', () => {
    test.afterEach(() => wp({ do: 'option', name: 'fabricator_forms_particles', value: null }));

    test('moves; stands still when the system asks for reduced motion, and when set to Static', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.goto(SCREENS.settings);
        expect(await canvasMoves(page), 'moving by default').toBe(true);

        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.reload();
        expect(await canvasMoves(page), 'still under reduced motion').toBe(false);

        wp({ do: 'option', name: 'fabricator_forms_particles', value: 'static' });
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.reload();
        expect(await canvasMoves(page), 'still when set to Static').toBe(false);
    });
});

test('Privacy Policy Guide: "Copy suggested policy text" copies FormFabricator\'s paragraphs, not only the headings', async ({ page }) => {
    await page.goto('/wp-admin/options-privacy.php?tab=policyguide');
    await page.evaluate(() => {
        document.addEventListener('copy', () => { window.__copied = String(window.getSelection()); });
    });
    const trigger = page.locator('.privacy-settings-accordion-trigger', { hasText: 'FormFabricator' });
    await trigger.click();
    const panel = page.locator('#' + await trigger.getAttribute('aria-controls'));
    // One paragraph, and a second one only while reCAPTCHA is configured; each under its heading.
    const paragraphs = await panel.locator('.privacy-text-copy-content p').allTextContents();
    expect(paragraphs.length).toBeGreaterThan(0);

    await panel.locator('button.privacy-text-copy').click();
    const copied = await page.evaluate(() => window.__copied || '');
    const flat = (s) => s.replace(/\s+/g, ' ').trim();
    for (const p of paragraphs) {
        expect(flat(copied)).toContain(flat(p));
    }
});

test.describe('the PDF verifier\'s upload', () => {
    const PDF = Buffer.from('%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n'
        + '2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n');

    async function upload(page) {
        await page.locator('#pdf-input').setInputFiles({ name: 'check.pdf', mimeType: 'application/pdf', buffer: PDF });
        await expect(page.locator('#fabricator-pdf-verify-btn')).toBeEnabled();
        await page.locator('#fabricator-pdf-verify-btn').click();
    }

    test('while the PDFs upload, the overlay covers the content beside the menu and draws above it, also scrolled', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 520 });
        await page.goto(SCREENS['PDF verification']);
        // The upload is held back, so the overlay can be looked at.
        let release;
        const held = new Promise((r) => { release = r; });
        // The real submission is cancelled after the page's own submit handler has opened the overlay: a page that is
        // navigating away cannot be looked at, and the overlay is what this checks.
        await page.evaluate(() => {
            document.getElementById('pdf-upload-form').addEventListener('submit', (e) => e.preventDefault());
        });
        await page.evaluate(() => { document.body.style.minHeight = '2000px'; });
        await upload(page);
        const overlay = page.locator('#fabricator-pdf-upload-overlay');
        await expect(overlay).toBeVisible();

        const covered = async () => page.evaluate(() => {
            const wpc = document.getElementById('wpcontent').getBoundingClientRect();
            // Below WordPress's admin bar, which stays above everything, the overlay as well.
            const bar = document.getElementById('wpadminbar');
            const top = Math.max(0, wpc.top, bar ? bar.getBoundingClientRect().bottom : 0);
            const points = [[wpc.left + 20, top + 20], [wpc.left + wpc.width / 2, (top + innerHeight) / 2], [wpc.right - 20, innerHeight - 20]];
            // And over the page's own content, the docked notice included, while it is in view.
            for (const el of [document.getElementById('e2e-sample-notice'), document.getElementById('fabricator-pdf-idle-state')]) {
                const r = el ? el.getBoundingClientRect() : null;
                if (r && r.width && r.bottom > top + 5 && r.top < innerHeight - 5) {
                    points.push([r.left + r.width / 2, Math.max(r.top, top) + 3]);
                }
            }
            const menu = document.getElementById('adminmenuwrap');
            const menuFree = !menu || !document.elementFromPoint(menu.getBoundingClientRect().left + 10, innerHeight / 2)
                .closest('#fabricator-pdf-upload-overlay');
            return points.every(([x, y]) => document.elementFromPoint(x, y).closest('#fabricator-pdf-upload-overlay')) && menuFree;
        });
        expect(await covered(), 'covers the content, not the menu').toBe(true);
        await page.mouse.wheel(0, 400);
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
        expect(await covered(), 'still, scrolled').toBe(true);
    });

    test('after a verification, reloading and going back send no upload again', async ({ page }) => {
        await page.goto(SCREENS['PDF verification']);
        await upload(page);
        await page.waitForURL((u) => u.searchParams.get('page') === 'fabricator-pdf-verification');
        await expect(page.locator('.fabricator-pdf-result, .fabricator-pdf-problem, #fabricator-pdf-verification-results > *').first()).toBeVisible();

        const posts = [];
        page.on('request', (r) => {
            if (r.method() === 'POST' && !r.url().includes('admin-ajax.php')) {
                posts.push(r.url());
            }
        });
        page.on('dialog', (d) => { posts.push('dialog: ' + d.message()); d.dismiss(); });
        await page.reload();
        await page.goBack();
        await page.waitForLoadState('load');
        expect(posts).toEqual([]);
    });
});
