'use strict';

/*
 * What the E2E specs share: the CLI helper that sets up data in the site (tests/e2e/site/wp.php), the mail outbox the
 * test mu-plugin writes, the logins saved by auth.setup.js, and a few defaults.
 */

const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { expect } = require('@playwright/test');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const STATE = process.env.FABRICATOR_E2E_STATE;
const OUTBOX = path.join(process.env.FABRICATOR_E2E_CONTENT || '', 'e2e-outbox');

/** Logins saved by auth.setup.js: the two administrators of the site. */
const auth = {
    admin: path.join(STATE || '', 'auth-admin.json'),
    admin2: path.join(STATE || '', 'auth-admin2.json'),
};

/** Runs one tests/e2e/site/wp.php command and returns its JSON answer. */
function wp(command) {
    const out = execFileSync(process.env.FABRICATOR_E2E_PHP || 'php', [path.join(ROOT, 'tests', 'e2e', 'site', 'wp.php'), JSON.stringify(command)], {
        cwd: ROOT,
        env: process.env,
    });
    return JSON.parse(String(out));
}

let unique = 0;
/** A name no other spec, browser or run uses, for subjects and titles the spec then looks for. */
function uniqueName(prefix) {
    unique++;
    return prefix + ' ' + process.pid + '-' + Date.now().toString(36) + '-' + unique;
}

/** One notification with sensible defaults; `overrides` replaces any key. */
function notification(overrides = {}) {
    return Object.assign({
        slug: 'admin', name: 'Admin', enabled: true, recipient_mode: 'single', to: 'owner@example.org',
        subject: 'New submission', body: '{all_fields}', attach_pdf: false, attach_uploads: false,
    }, overrides);
}

/** A form and a published page that shows it; returns {id, url}. */
function formOnPage({ title = uniqueName('E2E form'), fields, notifications, settings = {} }) {
    const { id } = wp({ do: 'form', title, fields, notifications: notifications || [notification()], settings });
    const page = wp({ do: 'page', title, content: '[fabricator_form id="' + id + '"]' });
    return { id, url: page.url, title };
}

/** The mails written to the outbox whose subject is `subject`, oldest first. */
function mailsWithSubject(subject) {
    if (!fs.existsSync(OUTBOX)) {
        return [];
    }
    return fs.readdirSync(OUTBOX).sort()
        .map((f) => JSON.parse(fs.readFileSync(path.join(OUTBOX, f), 'utf8')))
        .filter((m) => m.subject === subject);
}

/** Waits until the outbox holds a mail with `subject` and returns it. */
async function mailWithSubject(subject) {
    await expect.poll(() => mailsWithSubject(subject).length, { message: 'a mail with the subject "' + subject + '"' }).toBeGreaterThan(0);
    return mailsWithSubject(subject)[0];
}

/** A page with a form, once front.js has made its submit button usable. */
async function openForm(page, url) {
    await page.goto(url);
    await expect(page.locator('form.fabricator-form .fabricator-submit-btn').first()).toBeEnabled();
}

module.exports = { auth, wp, uniqueName, notification, formOnPage, mailsWithSubject, mailWithSubject, openForm };
