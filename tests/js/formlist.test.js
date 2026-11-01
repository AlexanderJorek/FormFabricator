'use strict';

/*
 * The form list (assets/js/admin-formlist.js) on the page FormList::render() prints for four saved forms, with the
 * object it localizes (fixture.admin): search, "Select all" and bulk delete, and the two-step import that shows where
 * an imported form would send submissions. TESTING.md §3.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture } = require('./support/page');
const { adminWindow, settle, script, type } = require('./support/admin-page');

function loadList(server) {
    const page = adminWindow(fixture.admin.formList, server);
    page.window.FabricatorFormListPage = JSON.parse(JSON.stringify(fixture.admin.formListPage));
    page.window.ajaxurl = 'https://example.test/wp-admin/admin-ajax.php';
    page.alerts = [];
    page.confirms = [];
    page.window.alert = (m) => page.alerts.push(String(m));
    page.window.confirm = (m) => {
        page.confirms.push(String(m));
        return true;
    };
    page.window.eval(script('admin-formlist.js'));
    return page;
}

const rowTitles = (page, onlyVisible) => Array.from(page.document.querySelectorAll('.fabricator-form-row'))
    .filter((r) => !onlyVisible || !r.hidden)
    .map((r) => r.dataset.title);

test('search, "Select all", bulk delete: only the rows still visible are deleted', async () => {
    const page = loadList((url, body) => ({ success: true, data: { deleted: JSON.parse(body.ids) } }));
    const doc = page.document;
    assert.deepEqual(page.errors, []);
    assert.equal(rowTitles(page).length, 4);

    type(page, doc.getElementById('fabricator-form-search'), 'contact');
    assert.deepEqual(rowTitles(page, true), ['contact', 'contact (archive)']);

    const selectAll = doc.getElementById('fabricator-select-all');
    selectAll.checked = true;
    selectAll.dispatchEvent(new page.window.Event('change'));
    assert.equal(doc.getElementById('fabricator-bulk-count').textContent, '2 selected');

    doc.getElementById('fabricator-bulk-action-btn').click();
    doc.querySelector('#fabricator-bulk-action-dd [data-action="delete"]').click();
    doc.getElementById('fabricator-bulk-apply').click();
    doc.getElementById('fabricator-modal-confirm').click();
    await settle(page);

    const request = page.requests.find((r) => r.body && r.body.action === 'fabricator_forms_bulk_delete');
    assert.ok(request, 'the bulk delete was sent');
    assert.ok(request.body.bulk_nonce, "with the list's bulk nonce, which the server checks first");
    const ids = JSON.parse(request.body.ids);
    const idsOfVisible = Array.from(doc.querySelectorAll('.fabricator-form-row'))
        .filter((r) => ['contact', 'contact (archive)'].includes(r.dataset.title))
        .map((r) => r.querySelector('.fabricator-row-check').value);
    assert.deepEqual(ids.sort(), idsOfVisible.sort());
    await new Promise((resolve) => page.window.setTimeout(resolve, 300));
    assert.deepEqual(rowTitles(page).sort(), ['callback request', 'newsletter']);
});

test('a row the search hides is unticked, so a later bulk action cannot reach it', () => {
    const page = loadList();
    const doc = page.document;
    doc.querySelectorAll('.fabricator-row-check').forEach((cb) => {
        cb.checked = true;
        cb.dispatchEvent(new page.window.Event('change'));
    });
    assert.equal(doc.getElementById('fabricator-bulk-count').textContent, '4 selected');

    type(page, doc.getElementById('fabricator-form-search'), 'news');

    assert.equal(doc.getElementById('fabricator-bulk-count').textContent, '1 selected');
});

const I18N = fixture.admin.formListPage.i18n;

/** The preview the server sends: a plain notification, a switched-off one, and one routed by rules. */
const NOTIFICATIONS = [
    {
        name: 'Admin', enabled: true, routing: false, reply_to: ['the address entered in "Email"'],
        groups: [{ when: '', to: ['owner@example.org'], cc: ['cc@example.org'], bcc: [] }],
    },
    { name: '', enabled: false, routing: false, reply_to: [], groups: [{ when: '', to: [], cc: [], bcc: [] }] },
    {
        name: 'Routed <b>x</b>', enabled: true, routing: true, reply_to: [],
        groups: [
            { when: 'If "Department" is equal to "sales"', to: ['sales@example.org'], cc: [], bcc: ['audit@example.org'] },
            { when: 'If no rule applies', to: ['office@example.org'], cc: [], bcc: [] },
        ],
    },
];

/** The import dialog as the admin reads it: title, then one block per notification, line by line. */
function importDialog(page) {
    const modal = page.document.getElementById('fabricator-import-modal');
    return {
        open: !modal.hidden,
        title: page.document.getElementById('fabricator-import-title').textContent,
        notifications: Array.from(modal.querySelectorAll('.fabricator-import-notif')).map((n) => n.innerText || n.textContent),
        blocks: Array.from(modal.querySelectorAll('.fabricator-import-notif')),
    };
}

async function startImport(page) {
    type(page, page.document.getElementById('fabricator-import-input'), 'eJxLTEoGAAJNASc');
    page.document.getElementById('fabricator-import-submit').click();
    await settle(page);
}

test('import: a dialog of its own shows the title literally and every recipient, conditioned ones under their rule', async () => {
    const title = 'Offer $& $1 $\' $` for you';
    const page = loadList((url, body) => {
        return body.preview === '1'
            ? { success: true, data: { preview: true, title, notifications: NOTIFICATIONS } }
            : { success: true, data: { new_id: 99, html: '<div class="fabricator-form-row" data-title="offer"></div>' } };
    });
    await startImport(page);

    const dialog = importDialog(page);
    assert.equal(dialog.open, true);
    assert.deepEqual(page.confirms, [], 'no browser prompt');
    assert.equal(dialog.title, I18N.importTitle.replace('%s', () => title), 'the title as typed, no $& expanded');
    const [admin, off, routed] = dialog.blocks.map((b) => b.textContent);
    assert.ok(admin.includes(I18N.importTo + ': owner@example.org') && admin.includes(I18N.importCc + ': cc@example.org'), admin);
    assert.ok(admin.includes(I18N.importReplyTo + ': the address entered in "Email"'), admin);
    assert.ok(off.includes(I18N.importUnnamed) && off.includes(I18N.importOff) && off.includes(I18N.importNoAddress), off);
    assert.ok(routed.includes(I18N.importRouting), routed);
    assert.ok(routed.includes('If "Department" is equal to "sales"' + I18N.importTo + ': sales@example.org'), routed);
    assert.ok(routed.includes(I18N.importBcc + ': audit@example.org'), 'a rule\'s Bcc is listed too: ' + routed);
    assert.ok(routed.includes('If no rule applies' + I18N.importTo + ': office@example.org'), routed);
    assert.equal(dialog.blocks[2].querySelector('b'), null, 'a name holding markup stays text');
    assert.equal(page.document.activeElement.id, 'fabricator-import-cancel', 'the cursor starts on Cancel');
    assert.deepEqual(page.requests.map((r) => r.body.preview), ['1'], 'nothing imported while the dialog is open');

    page.document.getElementById('fabricator-import-confirm').click();
    await settle(page);

    assert.equal(importDialog(page).open, false);
    assert.deepEqual(page.requests.map((r) => r.body.preview), ['1', ''], 'imported after the confirmation');
    assert.ok(rowTitles(page).includes('offer'), 'the imported form is listed at once');
});

test('import: Cancel, Escape or a click beside the dialog imports nothing', async () => {
    for (const close of [
        (page) => page.document.getElementById('fabricator-import-cancel').click(),
        (page) => page.document.dispatchEvent(new page.window.KeyboardEvent('keydown', { key: 'Escape' })),
        (page) => page.document.getElementById('fabricator-import-modal').click(),
    ]) {
        const page = loadList(() => ({ success: true, data: { preview: true, title: 'X', notifications: [] } }));
        await startImport(page);
        assert.equal(importDialog(page).open, true);

        close(page);
        await settle(page);

        assert.equal(importDialog(page).open, false);
        assert.equal(page.requests.length, 1, 'only the preview');
        assert.equal(rowTitles(page).length, 4);
    }
});
