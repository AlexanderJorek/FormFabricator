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

test('import: the confirmation shows the title literally and every recipient before anything is imported', async () => {
    const title = 'Offer $& $1 $\' $` for you';
    const recipients = [
        { name: 'Admin', enabled: true, recipients: ['owner@example.org', 'cc@example.org', '{email}'] },
        { name: '', enabled: false, recipients: [] },
    ];
    const page = loadList((url, body) => {
        return body.preview === '1'
            ? { success: true, data: { preview: true, title, recipients } }
            : { success: true, data: { new_id: 99, html: '<div class="fabricator-form-row" data-title="offer"></div>' } };
    });
    type(page, page.document.getElementById('fabricator-import-input'), 'eJxLTEoGAAJNASc');

    page.document.getElementById('fabricator-import-submit').click();
    await settle(page);

    assert.equal(page.confirms.length, 1);
    const shown = page.confirms[0];
    assert.ok(shown.includes('"' + title + '"'), 'the title as typed, no $& expanded: ' + shown);
    assert.ok(shown.includes('• Admin: owner@example.org, cc@example.org, {email}'));
    assert.ok(shown.includes('• Unnamed notification: no address'));
    const sent = page.requests.map((r) => r.body.preview);
    assert.deepEqual(sent, ['1', ''], 'previewed first, imported after the confirmation');
    assert.ok(rowTitles(page).includes('offer'), 'the imported form is listed at once');
});

test('import: declining the confirmation imports nothing', async () => {
    const page = loadList(() => ({ success: true, data: { preview: true, title: 'X', recipients: [] } }));
    page.window.confirm = () => false;
    type(page, page.document.getElementById('fabricator-import-input'), 'eJxLTEoGAAJNASc');

    page.document.getElementById('fabricator-import-submit').click();
    await settle(page);

    assert.equal(page.requests.length, 1, 'only the preview');
    assert.equal(rowTitles(page).length, 4);
});
