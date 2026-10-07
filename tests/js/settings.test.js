'use strict';

/*
 * FormFabricator → Settings (assets/js/admin-settings.js) on the page FormSettings::renderSettingsPage() prints, with
 * the object it localizes (fixture.admin): each save carries the snapshot its page holds, and a successful save makes
 * the server's new snapshot the page's baseline, so saving twice without a reload works (TESTING.md §3). The
 * server's refusal of a stale snapshot is SettingsSaveTest's.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture } = require('./support/page');
const { adminWindow, settle, until, script } = require('./support/admin-page');

/**
 * The little of jQuery the script uses: the ready callback, $(...).wpColorPicker() (inside a try), and $.post(), which
 * answers through the same stub server as fetch().
 */
function jqueryStub(page, server) {
    const chain = new Proxy(function () {}, { get: () => () => chain });
    const $ = function (arg) {
        if (typeof arg === 'function') {
            arg($);
        }
        return chain;
    };
    $.post = (url, data, done) => {
        page.requests.push({ url, body: data });
        const answer = server(url, data);
        const failures = [];
        page.window.setTimeout(() => (answer.status === 409 ? failures.forEach((f) => f({ responseJSON: answer })) : done(answer)), 0);
        return { fail: (f) => failures.push(f) };
    };
    return $;
}

function loadSettings(server) {
    const page = adminWindow(fixture.admin.settings, server);
    page.window.FabricatorSettingsPage = JSON.parse(JSON.stringify(fixture.admin.settingsPage));
    page.window.ajaxurl = 'https://example.test/wp-admin/admin-ajax.php';
    page.window.jQuery = jqueryStub(page, server);
    page.window.eval(script('admin-settings.js'));
    return page;
}

test('general settings: a second save without reloading carries the snapshot the first one returned', async () => {
    let round = 0;
    const page = loadSettings(() => ({ success: true, data: { message: 'Settings saved.', snapshot: 'after-save-' + (++round) } }));
    const form = page.document.getElementById('fabricator-settings-form');
    const loaded = form.querySelector('[name="fabricator_settings_snapshot"]').value;
    assert.deepEqual(page.errors, []);
    assert.match(loaded, /^[0-9a-f]{32}$/, 'the page carries the snapshot it was rendered with');

    // The script sends after two animation frames; wait until this save was sent and answered (the button is back).
    const sentSaves = () => page.requests.filter((r) => r.body && r.body.action === 'fabricator_save_general_settings').length;
    const save = async () => {
        const before = sentSaves();
        form.dispatchEvent(new page.window.Event('submit', { cancelable: true }));
        await until(page, () => sentSaves() > before && !form.querySelector('button[type="submit"]').disabled);
    };
    await save();
    await save();

    const sent = page.requests.filter((r) => r.body && r.body.action === 'fabricator_save_general_settings').map((r) => r.body.fabricator_settings_snapshot);
    assert.deepEqual(sent, [loaded, 'after-save-1']);
    assert.match(page.document.querySelector('.fabricator-settings-notice').textContent, /Settings saved\./);
});

test('general settings: a save refused as changed elsewhere says so and keeps the old baseline', async () => {
    const page = loadSettings(() => ({ success: false, data: { message: 'Settings were changed elsewhere since this page loaded. Please reload and try again.' } }));
    const form = page.document.getElementById('fabricator-settings-form');
    const loaded = form.querySelector('[name="fabricator_settings_snapshot"]').value;

    form.dispatchEvent(new page.window.Event('submit', { cancelable: true }));
    // The page has an empty error notice of its own from the server; wait for the one this save puts up.
    const shown = () => Array.from(page.document.querySelectorAll('.fabricator-settings-notice--error')).find((n) => /\S/.test(n.textContent));
    await until(page, () => shown() !== undefined);

    assert.match(shown().textContent, /changed elsewhere/);
    assert.equal(form.querySelector('[name="fabricator_settings_snapshot"]').value, loaded);
});

test('access matrix: a second save in the same tab carries the snapshot the first one returned', async () => {
    let round = 0;
    const page = loadSettings((url, body) => (body.action === 'fabricator_save_access_settings'
        ? { success: true, data: { snapshot: 'access-' + (++round) } }
        : { success: true, data: {} }));
    const doc = page.document;
    const loaded = page.window.FabricatorSettingsPage.data.accessSnapshot;

    for (let i = 0; i < 2; i++) {
        doc.getElementById('fabricator-access-tile-btn').click();
        doc.getElementById('fabricator-access-save').click();
        await settle(page);
        assert.equal(doc.getElementById('fabricator-access-overlay').hidden, true, 'saved and closed');
    }

    const sent = page.requests.filter((r) => r.body && r.body.action === 'fabricator_save_access_settings').map((r) => r.body.snapshot);
    assert.deepEqual(sent, [loaded || '', 'access-1']);
});

test('access matrix: a save refused as changed elsewhere shows the server\'s message', async () => {
    const message = 'Settings were changed elsewhere since this page loaded. Please reload and try again.';
    const page = loadSettings(() => ({ status: 409, success: false, data: { message } }));
    const doc = page.document;

    doc.getElementById('fabricator-access-tile-btn').click();
    doc.getElementById('fabricator-access-save').click();
    await settle(page);

    assert.equal(doc.getElementById('fabricator-access-error').textContent, message);
    assert.equal(doc.getElementById('fabricator-access-overlay').hidden, false, 'left open to reload from');
});

test('rotating the key: "The old master key is lost" is sent only when ticked', async () => {
    // The checkbox as FormSettings prints it while the configured master key does not open the stored keys
    // (SealKeyCardTest checks the server prints it then, and what it accepts with and without it).
    const rotations = [];
    const page = loadSettings((url, body) => {
        if (body && body.action === 'fabricator_forms_rotate_key') {
            rotations.push(body);
        }
        return { success: false, data: { message: 'Nothing was changed.' } };
    });
    const doc = page.document;
    doc.querySelector('.fabricator-key-rotate-fields').insertAdjacentHTML('beforeend',
        '<label class="fabricator-reset-check-wrap"><input type="checkbox" id="fabricator_key_master_lost" value="1">'
        + '<span>The old master key is lost</span></label>');

    doc.getElementById('fabricator-rotate-key-trigger').click();
    doc.getElementById('fabricator-key-confirm').click();
    await until(page, () => rotations.length === 1);
    assert.equal(rotations[0].master_key_lost, undefined, 'unticked: the server refuses to bury the key');
    assert.equal(rotations[0].key_compromised, '0');

    doc.getElementById('fabricator_key_master_lost').checked = true;
    await until(page, () => !doc.getElementById('fabricator-key-confirm').disabled);
    doc.getElementById('fabricator-key-confirm').click();
    await until(page, () => rotations.length === 2);
    assert.equal(rotations[1].master_key_lost, '1');
    assert.deepEqual(page.errors, []);
});
