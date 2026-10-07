'use strict';

/*
 * The form selection list (assets/js/admin-formselect.js) on the page FormSelectList::render() prints for four saved
 * selections, with the object it localizes (fixture.admin), TESTING.md §3: search, "Select all" and bulk delete reach
 * only the rows still visible, as in the form list (formlist.test.js).
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture } = require('./support/page');
const { adminWindow, until, script, type } = require('./support/admin-page');

function loadSelections(server) {
    const page = adminWindow(fixture.admin.formSelect, server);
    page.window.FabricatorFormSelectPage = JSON.parse(JSON.stringify(fixture.admin.formSelectPage));
    page.confirms = [];
    page.window.confirm = (m) => {
        page.confirms.push(String(m));
        return true;
    };
    page.window.alert = () => {};
    page.window.eval(script('admin-formselect.js'));
    return page;
}

const rowTitles = (page, onlyVisible) => Array.from(page.document.querySelectorAll('#fabricator-fsel-list .fabricator-form-row'))
    .filter((r) => !onlyVisible || !r.hidden)
    .map((r) => r.dataset.title);

test('search, "Select all", bulk delete: only the selections still visible are deleted', async () => {
    const page = loadSelections();
    const doc = page.document;
    assert.deepEqual(page.errors, []);
    assert.equal(rowTitles(page).length, 4);

    type(page, doc.getElementById('fabricator-fsel-form-search'), 'contact');
    assert.deepEqual(rowTitles(page, true), ['contact', 'contact (archive)']);

    const selectAll = doc.getElementById('fabricator-fsel-select-all');
    selectAll.checked = true;
    selectAll.dispatchEvent(new page.window.Event('change'));
    assert.equal(doc.getElementById('fabricator-fsel-bulk-count').textContent, '2 selected');

    doc.getElementById('fabricator-fsel-bulk-action-btn').click();
    doc.querySelector('#fabricator-fsel-bulk-action-dd [data-action="delete"]').click();
    doc.getElementById('fabricator-fsel-bulk-apply').click();
    assert.deepEqual(page.confirms, [fixture.admin.formSelectPage.i18n.bulkDeleteConfirm.replace('%d', '2')]);
    await until(page, () => rowTitles(page).length === 2);

    const deleted = page.requests.filter((r) => r.body && r.body.action === 'fabricator_fsel_delete').map((r) => r.body.id);
    const idsOfVisible = fixture.admin.formSelectPage.fselData
        .filter((s) => ['Contact', 'Contact (archive)'].includes(s.title))
        .map((s) => String(s.id));
    assert.deepEqual(deleted.sort(), idsOfVisible.sort(), 'one delete per visible selection, none for the hidden ones');
    assert.deepEqual(rowTitles(page).sort(), ['callback request', 'newsletter']);
});

test('a selection the search hides is unticked, so a later bulk action cannot reach it', () => {
    const page = loadSelections();
    const doc = page.document;
    doc.querySelectorAll('#fabricator-fsel-list .fabricator-row-check').forEach((cb) => {
        cb.checked = true;
        cb.dispatchEvent(new page.window.Event('change', { bubbles: true }));
    });
    assert.equal(doc.getElementById('fabricator-fsel-bulk-count').textContent, '4 selected');

    type(page, doc.getElementById('fabricator-fsel-form-search'), 'news');

    assert.equal(doc.getElementById('fabricator-fsel-bulk-count').textContent, '1 selected');
});

test('a refused delete keeps its row and says how many failed', async () => {
    const page = loadSelections((url, body) => ({ success: body.action !== 'fabricator_fsel_delete' || body.id !== '2' }));
    const alerts = [];
    page.window.alert = (m) => alerts.push(String(m));
    const doc = page.document;
    const selectAll = doc.getElementById('fabricator-fsel-select-all');
    selectAll.checked = true;
    selectAll.dispatchEvent(new page.window.Event('change'));
    doc.getElementById('fabricator-fsel-bulk-apply').click();

    await until(page, () => alerts.length === 1);
    assert.deepEqual(rowTitles(page), ['callback request']);
    assert.equal(alerts[0], fixture.admin.formSelectPage.i18n.bulkDeleteFailed.replace('%d', '1'));
});
