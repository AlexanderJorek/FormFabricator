'use strict';

/*
 * The edit-lock scripts of the form editor, Settings and PDF Layout (assets/js/admin-*-lock.js) on their real pages,
 * with what each page localizes (fixture.admin), TESTING.md §2: WordPress's heartbeat carries the lock, a conflict
 * names the other administrator literally, and closing the tab releases the lock through sendBeacon(), the one request
 * a closing page still gets out. That the server releases only its owner's lock is EditLockTest's.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture } = require('./support/page');
const { adminWindow, script } = require('./support/admin-page');

/* The part of jQuery the scripts use: $(document).on() for WordPress's heartbeat events, which tick() then fires. */
function withHeartbeat(page) {
    const handlers = {};
    const $ = () => ({
        on(type, fn) {
            (handlers[type] = handlers[type] || []).push(fn);
            return this;
        },
    });
    $.fn = {};
    page.window.jQuery = $;
    page.beacons = [];
    page.window.navigator.sendBeacon = (url, body) => {
        page.beacons.push({ url, body: Object.fromEntries(body.entries()) });
        return true;
    };
    page.fire = (type, data) => (handlers[type] || []).forEach((fn) => fn({ type }, data));
    return page;
}

const pages = [
    {
        name: 'form editor',
        html: () => fixture.admin.editor,
        global: 'FabricatorEditorLock',
        data: () => Object.assign({}, fixture.admin.editorLock, { formId: 7 }),
        file: 'admin-editor-lock.js',
        sendKey: 'fabricator_forms_lock',
        sent: 7,
        conflictKey: 'fabricator_forms_lock_conflict',
        unlock: { action: 'fabricator_forms_unlock_form', nonce: 'nonce', form_id: '7' },
    },
    {
        name: 'Settings',
        html: () => fixture.admin.settings,
        global: 'FabricatorSettingsLock',
        data: () => fixture.admin.settingsLock,
        file: 'admin-settings-lock.js',
        sendKey: 'fabricator_settings_lock',
        sent: 1,
        conflictKey: 'fabricator_settings_lock_conflict',
        unlock: { action: 'fabricator_forms_unlock_settings', nonce: 'nonce' },
    },
    {
        name: 'PDF Layout',
        html: () => fixture.admin.pdfLayout,
        global: 'FabricatorPdfLayoutLock',
        data: () => fixture.admin.pdfLayoutLock,
        file: 'admin-pdflayout-lock.js',
        sendKey: 'fabricator_pdf_layout_lock',
        sent: 1,
        conflictKey: 'fabricator_pdf_layout_lock_conflict',
        unlock: { action: 'fabricator_forms_unlock_pdf_layout', nonce: 'nonce' },
    },
];

for (const p of pages) {
    test(p.name + ': the heartbeat carries the lock, a conflict names the other administrator, closing the tab releases it', () => {
        const page = withHeartbeat(adminWindow(p.html()));
        page.window[p.global] = JSON.parse(JSON.stringify(p.data()));
        page.window.eval(script(p.file));

        const outgoing = {};
        page.fire('heartbeat-send', outgoing);
        assert.equal(outgoing[p.sendKey], p.sent);

        const shown = () => {
            const notice = page.document.getElementById('fabricator-lock-notice');
            return notice && notice.style.display !== 'none' ? notice.textContent : null;
        };
        page.fire('heartbeat-tick', {});
        assert.equal(shown(), null, 'no conflict, no notice');

        // A display name with replacement patterns in it is shown as typed.
        page.fire('heartbeat-tick', { [p.conflictKey]: "Ann $& O'Brien" });
        assert.ok((shown() || '').includes("Currently being edited by Ann $& O'Brien."), String(shown()));

        // The other administrator left: the next tick carries no conflict (the server handed this page the lock), and
        // the notice goes, until someone else opens the page again.
        page.fire('heartbeat-tick', {});
        assert.equal(shown(), null, 'the notice clears once the other administrator has left');
        page.fire('heartbeat-tick', { [p.conflictKey]: 'Bob' });
        assert.ok((shown() || '').includes('Currently being edited by Bob.'), String(shown()));

        assert.deepEqual(page.beacons, []);
        page.window.dispatchEvent(new page.window.Event('pagehide'));
        assert.deepEqual(page.beacons, [{ url: p.data().ajaxUrl, body: p.unlock }]);
        assert.deepEqual(page.errors, []);
    });
}

test('form editor: a new form, not saved yet, holds no lock', () => {
    const page = withHeartbeat(adminWindow(fixture.admin.editor));
    page.window.FabricatorEditorLock = JSON.parse(JSON.stringify(fixture.admin.editorLock));
    page.window.eval(script('admin-editor-lock.js'));
    const outgoing = {};
    page.fire('heartbeat-send', outgoing);
    page.window.dispatchEvent(new page.window.Event('pagehide'));
    assert.deepEqual(outgoing, {});
    assert.deepEqual(page.beacons, []);
});
