'use strict';

/*
 * The form builder in a real browser, TESTING.md §2: a field of every palette group added through the "Add field"
 * dialog; rows reordered and paired side by side by dragging; a field deleted; each change saved, reloaded and
 * checked on the page the form is shown on. What the builder sends is jsdom's (tests/js/builder.test.js); dragging
 * and layout need a browser.
 */

const { test, expect } = require('@playwright/test');
const { auth, wp, uniqueName, notification, mailWithSubject } = require('../support/site');

test.use({ storageState: auth.admin });

const TEXT = (id, extra = {}) => Object.assign({ id, type: 'text', label: id.toUpperCase(), cols: 12 }, extra);

function newForm(fields) {
    const title = uniqueName('Builder');
    const { id } = wp({ do: 'form', title, fields, notifications: [notification()] });
    return id;
}

async function openEditor(page, id) {
    await page.goto('/wp-admin/admin.php?page=fabricator-forms-editor&form_id=' + id);
    await expect(page.locator('#fabricator-field-list')).toBeVisible();
}

const rows = (page) => page.locator('#fabricator-field-list > .fabricator-field-row');

async function save(page) {
    const saved = page.waitForResponse((r) => r.url().includes('admin-ajax.php')
        && (r.request().postData() || '').includes('fabricator_forms_save_form'));
    await page.locator('#fabricator-save-btn').click();
    const answer = await (await saved).json();
    expect(answer.success, JSON.stringify(answer)).toBe(true);
}

/** The ids of the form's top-level fields as stored, and their widths. */
function stored(id) {
    return wp({ do: 'get-form', id }).fields.map((f) => f.id + ':' + (f.cols || 12));
}

/**
 * Drags a row by its handle onto `target`, at `where` of the target's box (fractions of its width and height), the way
 * a hand does: in steps, then resting at the spot with the slight movement of a hand before letting go.
 *
 * - The builder places the drop line on the animation frame after a dragover, so a drop in the same frame as the first
 *   dragover (what a single-step drag sends) would land nowhere.
 * - Playwright's WebKit reports drag positions that trail the pointer by some steps; the rest lets them catch up, as a
 *   browser's repeated dragover events do while a hand holds still.
 */
async function dragRow(page, from, target, where) {
    const start = await from.locator('.fabricator-row-handle').boundingBox();
    const box = await target.boundingBox();
    const x = box.x + box.width * where.x;
    const y = box.y + box.height * where.y;
    await page.mouse.move(start.x + start.width / 2, start.y + start.height / 2);
    await page.mouse.down();
    await page.mouse.move(x, y, { steps: 12 });
    for (let i = 0; i < 6; i++) {
        await page.mouse.move(x + (i % 2), y);
        await page.waitForTimeout(60);
    }
    await page.mouse.up();
}

test('a field of every palette group, added through the "Add field" dialog, is saved and still there after a reload', async ({ page }) => {
    const id = newForm([TEXT('first')]);
    await openEditor(page, id);
    const groups = await page.evaluate(() => JSON.parse(document.getElementById('fabricator-editor').dataset.palette).map((g) => g.items[0].type));
    expect(groups.length).toBeGreaterThanOrEqual(6);

    for (const type of groups) {
        await page.locator('#fabricator-add-field-btn').click();
        await page.locator('#fabricator-field-modal-body .fabricator-modal-card[data-type="' + type + '"]').click();
        // Adding opens the new field's settings; closed again, as a person would.
        await page.locator('#fabricator-settings-modal .fabricator-modal-close').click();
    }
    await expect(rows(page)).toHaveCount(1 + groups.length);
    await save(page);

    await page.reload();
    await expect(rows(page)).toHaveCount(1 + groups.length);
    const types = await rows(page).evaluateAll((els) => els.map((el) => el.dataset.type));
    expect(types).toEqual(['text', ...groups]);
});

test('rows reordered by dragging keep their order after save and reload, in the builder and on the page', async ({ page }) => {
    const id = newForm([TEXT('alpha'), TEXT('bravo'), TEXT('charlie')]);
    await openEditor(page, id);

    // "charlie" onto the upper part of "alpha": it goes first.
    await dragRow(page, rows(page).nth(2), rows(page).nth(0), { x: 0.3, y: 0.2 });
    await expect(rows(page).locator('.fabricator-row-label')).toHaveText(['CHARLIE', 'ALPHA', 'BRAVO']);
    await save(page);
    expect(stored(id)).toEqual(['charlie:12', 'alpha:12', 'bravo:12']);

    await page.reload();
    await expect(rows(page).locator('.fabricator-row-label')).toHaveText(['CHARLIE', 'ALPHA', 'BRAVO']);
});

test('a row dropped on the right half of another pairs them side by side, on the page too', async ({ page }) => {
    const id = newForm([TEXT('left'), TEXT('right'), TEXT('below')]);
    await openEditor(page, id);

    await dragRow(page, rows(page).nth(1), rows(page).nth(0), { x: 0.85, y: 0.5 });
    await save(page);
    expect(stored(id)).toEqual(['left:6', 'right:6', 'below:12']);

    const shown = wp({ do: 'page', title: uniqueName('Pair'), content: '[fabricator_form id="' + id + '"]' });
    await page.goto(shown.url);
    const left = await page.locator('[data-field-id="left"]').boundingBox();
    const right = await page.locator('[data-field-id="right"]').boundingBox();
    const below = await page.locator('[data-field-id="below"]').boundingBox();
    expect(Math.abs(left.y - right.y)).toBeLessThan(2);
    expect(right.x).toBeGreaterThan(left.x + left.width - 1);
    expect(below.y).toBeGreaterThan(left.y + left.height - 1);
});

test('a deleted field is gone after save and reload, from the builder and from the page', async ({ page }) => {
    const id = newForm([TEXT('keep'), TEXT('drop'), TEXT('also')]);
    await openEditor(page, id);

    await rows(page).nth(1).locator('.fabricator-row-delete').click();
    await expect(rows(page)).toHaveCount(2);
    await save(page);
    expect(stored(id)).toEqual(['keep:12', 'also:12']);

    await page.reload();
    await expect(rows(page).locator('.fabricator-row-label')).toHaveText(['KEEP', 'ALSO']);
    const shown = wp({ do: 'page', title: uniqueName('Deleted'), content: '[fabricator_form id="' + id + '"]' });
    await page.goto(shown.url);
    await expect(page.locator('[data-field-id="keep"]')).toBeVisible();
    await expect(page.locator('[data-field-id="drop"]')).toHaveCount(0);
});

test('two fields side by side stay side by side once a condition shows them', async ({ page }) => {
    const showIfYes = { action: 'show', match: 'all', rules: [{ field_id: 'gate', operator: 'equals', value: 'yes' }] };
    const id = newForm([TEXT('gate'), TEXT('city', { cols: 6, conditions: showIfYes }), TEXT('zip', { cols: 6, conditions: showIfYes })]);
    const shown = wp({ do: 'page', title: uniqueName('Pair condition'), content: '[fabricator_form id="' + id + '"]' });

    await page.goto(shown.url);
    await expect(page.locator('[data-field-id="city"]')).toBeHidden();
    await page.locator('[data-field-id="gate"] input').fill('yes');
    await expect(page.locator('[data-field-id="city"]')).toBeVisible();
    await expect(page.locator('[data-field-id="zip"]')).toBeVisible();
    const city = await page.locator('[data-field-id="city"]').boundingBox();
    const zip = await page.locator('[data-field-id="zip"]').boundingBox();
    expect(Math.abs(city.y - zip.y)).toBeLessThan(2);
    expect(zip.x).toBeGreaterThan(city.x + city.width - 1);
});

test('the HTML block\'s rich-text editor: typed and bold text saved, kept after reload, in Preview, and in the mail without editor styling', async ({ page, context }) => {
    const subject = uniqueName('HTML block');
    const { id } = wp({
        do: 'form', title: uniqueName('HTML block'),
        fields: [{ id: 'block', type: 'html', label: '', html_content: '<p>Start</p>', show_in_output: true }, TEXT('name')],
        notifications: [notification({ subject })],
    });
    await openEditor(page, id);
    await rows(page).nth(0).click();
    const editor = page.frameLocator('#fabricator-settings-modal iframe.fabricator-sp-richtext-editor');
    await editor.locator('body').click();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Hello visitors ');
    await page.locator('#fabricator-settings-modal .fabricator-sp-richtext-btn:has(i.fa-bold)').click();
    await page.keyboard.type('in bold');
    await page.locator('#fabricator-settings-modal .fabricator-modal-close').click();
    await save(page);

    const stored = wp({ do: 'get-form', id }).fields[0].html_content;
    expect(stored).toContain('Hello visitors');
    expect(stored).toMatch(/<(strong|b)>in bold<\/(strong|b)>/);
    expect(stored).not.toMatch(/<style|fabricator-editor-preview/);

    await page.reload();
    await rows(page).nth(0).click();
    await expect(editor.locator('body')).toContainText('Hello visitors in bold');
    await page.locator('#fabricator-settings-modal .fabricator-modal-close').click();

    const popup = context.waitForEvent('page');
    await page.locator('#fabricator-preview-btn').click();
    const preview = await popup;
    await expect(preview.frameLocator('iframe').locator('body')).toContainText('Hello visitors in bold');
    await preview.close();

    const shown = wp({ do: 'page', title: uniqueName('HTML block page'), content: '[fabricator_form id="' + id + '"]' });
    await page.goto(shown.url);
    await expect(page.locator('[data-field-id="block"]')).toContainText('Hello visitors in bold');
    await page.locator('[data-field-id="name"] input').fill('Ada');
    await page.locator('.fabricator-submit-btn').click();
    await expect(page.locator('.fabricator-form-messages.success')).toBeVisible();
    const mail = await mailWithSubject(subject);
    expect(mail.message).toContain('Hello visitors');
    expect(mail.message).not.toMatch(/<style|fabricator-editor-preview/);
});

test('a notification\'s body typed in its rich-text editor, without touching the toolbar, is saved', async ({ page }) => {
    // WebKit runs no event listener in the editor's sandboxed document: typing alone must still save.
    const { id } = wp({ do: 'form', title: uniqueName('Body'), fields: [TEXT('name')], notifications: [notification()] });
    await openEditor(page, id);
    await page.locator('[data-tab="fabricator-notifications-panel"]').click();
    await page.locator('#fabricator-notif-list .fabricator-field-row').first().click();
    await page.locator('#fabricator-notif-modal [data-nstab="content"]').click();
    const body = page.frameLocator('#fabricator-nstab-content iframe').locator('body');
    await body.click();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Thank you, we will call back.');
    await page.locator('#fabricator-notif-done').click();
    await save(page);

    expect(wp({ do: 'get-form', id }).notifications[0].body).toContain('Thank you, we will call back.');
});
