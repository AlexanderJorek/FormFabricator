'use strict';

/*
 * A visitor fills in a form on a page and sends it; the notification reaches the outbox with the answers. The smoke
 * path every other spec builds on, in each browser.
 */

const { test, expect } = require('@playwright/test');
const { formOnPage, notification, uniqueName, mailWithSubject, openForm } = require('../support/site');

test('a visitor sends a form, and the notification carries the answers', async ({ page }) => {
    const subject = uniqueName('Contact');
    const form = formOnPage({
        fields: [
            { id: 'name', type: 'text', label: 'Name', required: true },
            { id: 'email', type: 'email', label: 'Email', required: true },
            { id: 'message', type: 'textarea', label: 'Message' },
        ],
        notifications: [notification({ subject, to: 'owner@example.org, second@example.org' })],
    });

    await openForm(page, form.url);
    await page.getByLabel('Name').fill('Ada Lovelace');
    await page.getByLabel('Email').fill('ada@example.net');
    await page.getByLabel('Message').fill('Line one\nLine two');
    await page.locator('.fabricator-submit-btn').click();

    await expect(page.locator('.fabricator-form-messages.success')).toBeVisible();
    const mail = await mailWithSubject(subject);
    expect(mail.to.join(',').split(',').map((a) => a.trim())).toEqual(['owner@example.org', 'second@example.org']);
    expect(mail.message).toContain('Ada Lovelace');
    expect(mail.message).toContain('ada@example.net');
});

test('a required field left empty stops the form in the browser, and nothing is sent', async ({ page }) => {
    const subject = uniqueName('Required');
    const form = formOnPage({
        fields: [{ id: 'name', type: 'text', label: 'Name', required: true }],
        notifications: [notification({ subject })],
    });

    await openForm(page, form.url);
    await page.locator('.fabricator-submit-btn').click();

    await expect(page.locator('[data-field-id="name"] .fabricator-field-error')).not.toBeEmpty();
    await expect(page.locator('.fabricator-form-messages.success')).toHaveCount(0);
});
