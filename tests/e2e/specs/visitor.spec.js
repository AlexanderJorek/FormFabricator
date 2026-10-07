'use strict';

/*
 * A visitor's side in a real browser, TESTING.md §2–§4: labels focusing the answer in their own form when a page shows
 * two, the rating by keyboard and by tap, the slider dragged and tapped, a drawn signature that survives turning the
 * device, an upload refused before it is sent, ALTCHA's real widget (Web Workers, Web Crypto), and the notice a
 * content blocker brings up for reCAPTCHA. A finger dragging the slider on a real touch screen stays manual.
 */

const { test, expect } = require('@playwright/test');
const { wp, formOnPage, notification, uniqueName, mailWithSubject, openForm } = require('../support/site');

test('two copies of a form on one page: a label focuses the answer in its own copy', async ({ page }) => {
    const { id } = wp({ do: 'form', title: uniqueName('Twice'), fields: [{ id: 'name', type: 'text', label: 'Name' }], notifications: [notification()] });
    const shown = wp({ do: 'page', title: uniqueName('Twice'), content: '[fabricator_form id="' + id + '"][fabricator_form id="' + id + '"]' });
    await openForm(page, shown.url);
    const forms = page.locator('form.fabricator-form');
    await expect(forms).toHaveCount(2);

    for (const n of [1, 0]) {
        await forms.nth(n).locator('label', { hasText: 'Name' }).first().click();
        await expect(forms.nth(n).locator('input[name="name"]')).toBeFocused();
    }
});

test('the rating follows the arrow keys', async ({ page }) => {
    const form = formOnPage({ fields: [{ id: 'stars', type: 'rating', label: 'Stars', required: true }] });
    await openForm(page, form.url);
    const radios = page.locator('input[name="stars"]');
    await radios.first().focus();
    await page.keyboard.press('Space');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await expect(radios.nth(2)).toBeChecked();
    await expect(page.locator('.fabricator-rating-star--full')).toHaveCount(3);
});

test('the slider takes a value from a mouse drag along its track', async ({ page }) => {
    const form = formOnPage({ fields: [{ id: 'level', type: 'slider', label: 'Level', min: 0, max: 100, step: 1 }] });
    await openForm(page, form.url);
    const track = await page.locator('[data-field-id="level"] .fabricator-slider-track').boundingBox();
    const thumb = await page.locator('[data-field-id="level"] .fabricator-slider-thumb').boundingBox();
    await page.mouse.move(thumb.x + thumb.width / 2, thumb.y + thumb.height / 2);
    await page.mouse.down();
    await page.mouse.move(track.x + track.width * 0.75, track.y + track.height / 2, { steps: 8 });
    await page.mouse.up();
    const value = Number(await page.locator('[data-field-id="level"] input[type="hidden"]').inputValue());
    expect(value).toBeGreaterThanOrEqual(70);
    expect(value).toBeLessThanOrEqual(80);
});

test.describe('on a touch screen', () => {
    test.skip(({ browserName }) => browserName === 'firefox', 'Playwright emulates no touch screen in Firefox');
    test.use({ hasTouch: true });

    test('a tap picks a star, and a tap on the slider\'s track sets it there', async ({ page }) => {
        const form = formOnPage({ fields: [
            { id: 'stars', type: 'rating', label: 'Stars' },
            { id: 'level', type: 'slider', label: 'Level', min: 0, max: 100, step: 1 },
        ] });
        await openForm(page, form.url);
        await page.locator('[data-field-id="stars"] .fabricator-rating-star[data-star="4"]').tap();
        await expect(page.locator('input[name="stars"]:checked')).toHaveValue('4');

        const track = await page.locator('[data-field-id="level"] .fabricator-slider-track').boundingBox();
        await page.touchscreen.tap(track.x + track.width * 0.25, track.y + track.height / 2);
        const value = Number(await page.locator('[data-field-id="level"] input[type="hidden"]').inputValue());
        expect(value).toBeGreaterThanOrEqual(20);
        expect(value).toBeLessThanOrEqual(30);
    });
});

/** Draws a zigzag on `canvas`, then turns the device to landscape; returns how much ink the pad shows after. */
async function drawAndTurn(page, canvas, hidden) {
    const box = await canvas.boundingBox();
    await page.mouse.move(box.x + box.width * 0.2, box.y + box.height * 0.5);
    await page.mouse.down();
    for (let i = 1; i <= 10; i++) {
        await page.mouse.move(box.x + box.width * (0.2 + i * 0.06), box.y + box.height * (0.5 + (i % 2 ? -0.2 : 0.2)));
    }
    await page.mouse.up();
    await expect(hidden).toHaveValue(/^data:image\//);
    // Turned to landscape: the pad takes its new width and draws the signature again.
    await page.setViewportSize({ width: 1180, height: 820 });
    await expect.poll(() => canvas.evaluate((c) => {
        const data = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
        let dark = 0;
        for (let i = 0; i < data.length; i += 4) {
            dark += data[i] < 128 ? 1 : 0;
        }
        return dark;
    }), { message: 'ink on the pad after turning' }).toBeGreaterThan(20);
}

test('a drawn signature survives turning the device and arrives with the form', async ({ page }) => {
    const subject = uniqueName('Signature');
    const form = formOnPage({
        fields: [{ id: 'sig', type: 'signature', label: 'Signature', required: true }],
        notifications: [notification({ subject })],
    });
    await page.setViewportSize({ width: 820, height: 1180 });
    await openForm(page, form.url);
    await drawAndTurn(page, page.locator('[data-field-id="sig"] .fabricator-signature-canvas'), page.locator('[data-field-id="sig"] input[type="hidden"]'));

    await page.locator('.fabricator-submit-btn').click();
    await expect(page.locator('.fabricator-form-messages.success')).toBeVisible();
    expect((await mailWithSubject(subject)).message).toContain('Signature');
});

test('the direct debit mandate\x27s signature survives turning the device too', async ({ page }) => {
    const form = formOnPage({ fields: [{ id: 'dd', type: 'directdebit', label: 'Direct debit', scheme: 'sepa' }] });
    await page.setViewportSize({ width: 820, height: 1180 });
    await openForm(page, form.url);
    const pad = page.locator('[data-field-id="dd"] .fabricator-debit-mandate .fabricator-signature-wrap');
    await drawAndTurn(page, pad.locator('.fabricator-signature-canvas'), pad.locator('input[type="hidden"]'));
});

test('an upload refused in the browser leaves no file in the form, and none arrives', async ({ page }) => {
    const subject = uniqueName('Upload');
    const form = formOnPage({
        fields: [{ id: 'name', type: 'text', label: 'Name' }, { id: 'doc', type: 'upload', label: 'Document' }],
        notifications: [notification({ subject, attach_uploads: true })],
    });
    await openForm(page, form.url);
    const input = page.locator('[data-field-id="doc"] input[type="file"]');
    await input.setInputFiles({ name: 'tool.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('MZ' + 'x'.repeat(64)) });

    await expect(page.locator('[data-field-id="doc"]')).toContainText(/allowed/i);
    expect(await input.evaluate((el) => el.files.length)).toBe(0);
    await page.getByLabel('Name').fill('Ada');
    await page.locator('.fabricator-submit-btn').click();
    await expect(page.locator('.fabricator-form-messages.success')).toBeVisible();
    expect((await mailWithSubject(subject)).attachments).toEqual([]);
});

test('ALTCHA: focusing the form solves the check in the browser, and the form sends', async ({ page }) => {
    const subject = uniqueName('Altcha');
    const form = formOnPage({
        fields: [{ id: 'name', type: 'text', label: 'Name' }, { id: 'cap', type: 'captcha', label: 'CAPTCHA', provider: 'altcha', required: true }],
        notifications: [notification({ subject })],
    });
    await openForm(page, form.url);
    await page.getByLabel('Name').fill('Ada');
    await expect(page.locator('altcha-widget')).toContainText('Verified', { timeout: 45000 });
    await page.locator('.fabricator-submit-btn').click();
    await expect(page.locator('.fabricator-form-messages.success')).toBeVisible();
    await mailWithSubject(subject);
});

test('reCAPTCHA blocked by a content blocker: the visitor is told, and the button can be pressed again', async ({ page }) => {
    wp({ do: 'option', name: 'fabricator_forms_recaptcha_site_key', value: 'e2e-site-key' });
    const form = formOnPage({
        fields: [{ id: 'name', type: 'text', label: 'Name' }, { id: 'cap', type: 'captcha', label: 'CAPTCHA', provider: 'recaptcha', required: true }],
    });
    await page.route(/https:\/\/www\.(google|gstatic)\.com\/recaptcha\//, (route) => route.abort('blockedbyclient'));
    await openForm(page, form.url);
    const activate = page.locator('.fabricator-captcha-activate');
    await activate.click();
    await expect(page.locator('.fabricator-captcha-gate .fabricator-notice')).toContainText('Could not load CAPTCHA');
    await expect(activate).toBeEnabled();
});
