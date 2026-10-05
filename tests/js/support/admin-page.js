'use strict';

/*
 * Fresh jsdom pages running the real admin scripts, with what WordPress hands them: the form builder gets
 * FormEditor::render()'s markup and FormEditor::builderI18n() from the fixture (tests/js/build-fixture.php), with the
 * form under test put into #fabricator-editor's data-form as the page would carry it.
 */

const fs = require('node:fs');
const path = require('node:path');
const { JSDOM, VirtualConsole } = require('jsdom');
const { fixture, cssEscape } = require('./page');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const script = (name) => fs.readFileSync(path.join(ROOT, 'assets', 'js', name), 'utf8');

function attr(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * A jsdom window with `bodyHtml`, a stubbed clipboard, fetch() answered by `server(url, body)` (default: success), and
 * every request recorded. Script errors become test failures through `errors`.
 */
function adminWindow(bodyHtml, server) {
    const errors = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on('jsdomError', (e) => errors.push(e.message));
    const dom = new JSDOM('<!DOCTYPE html><html><head></head><body>' + bodyHtml + '</body></html>', {
        runScripts: 'outside-only',
        pretendToBeVisual: true,
        url: 'https://example.test/wp-admin/admin.php',
        virtualConsole,
    });
    const w = dom.window;
    if (!w.CSS) {
        w.CSS = {};
    }
    if (typeof w.CSS.escape !== 'function') {
        w.CSS.escape = cssEscape;
    }
    // Layout APIs jsdom does not have: scrolling does nothing here.
    w.Element.prototype.scrollIntoView = function () {};
    Object.defineProperty(w.navigator, 'clipboard', { value: { writeText: () => Promise.resolve() }, configurable: true });
    const requests = [];
    w.fetch = (url, opts = {}) => {
        const body = opts.body;
        const fields = body && typeof body.entries === 'function' ? Object.fromEntries(Array.from(body.entries())) : body;
        requests.push({ url: String(url), body: fields });
        const answer = server ? server(String(url), fields) : { success: true, data: {} };
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(answer), text: () => Promise.resolve(JSON.stringify(answer)) });
    };
    return { window: w, document: w.document, errors, requests };
}

/** Lets DOMContentLoaded, timers and promise chains run. */
async function settle(page, rounds = 5) {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => page.window.setTimeout(resolve, 0));
    }
}

/**
 * Waits until `condition()` holds, for work that runs on animation frames, which jsdom paces at display rate: a fixed
 * number of settle() rounds sometimes ended before them. Fails after `timeoutMs`.
 */
async function until(page, condition, timeoutMs = 2000) {
    const started = Date.now();
    while (!condition()) {
        if (Date.now() - started > timeoutMs) {
            throw new Error('until(): the condition did not hold within ' + timeoutMs + ' ms');
        }
        await new Promise((resolve) => page.window.setTimeout(resolve, 5));
    }
    await settle(page);
}

/**
 * The form builder with `form` ({fields, notifications, settings, title, id}) loaded, booted and its modals created.
 */
async function loadBuilder(form = {}, server) {
    const data = Object.assign({ id: 7, title: 'Test form', fields: [], notifications: [], settings: {}, snapshot: 's1' }, form);
    const html = fixture.admin.editor.replace(/data-form='[^']*'/, "data-form='" + attr(JSON.stringify(data)) + "'");
    const page = adminWindow(html, server);
    page.window.FabricatorBuilderI18n = JSON.parse(JSON.stringify(fixture.admin.builderI18n));
    page.window.eval(script('admin-builder.js'));
    await settle(page);
    return page;
}

/** Whether leaving the page now would bring up the browser's unsaved-changes prompt. */
function wouldWarnOnLeave(page) {
    const e = new page.window.Event('beforeunload', { cancelable: true });
    page.window.dispatchEvent(e);
    return e.defaultPrevented;
}

/** Clicks Save and returns the form the builder sent (form_data, base64 JSON as FormEditor::ajaxSave() decodes it). */
async function saveAndRead(page) {
    const before = page.requests.length;
    page.document.getElementById('fabricator-save-btn').click();
    await settle(page);
    const save = page.requests.slice(before).find((r) => r.body && r.body.action === 'fabricator_forms_save_form');
    if (!save) {
        throw new Error('no save request was sent');
    }
    return JSON.parse(decodeURIComponent(escape(page.window.atob(save.body.form_data))));
}

function type(page, input, value) {
    input.value = value;
    input.dispatchEvent(new page.window.Event('input', { bubbles: true }));
}

module.exports = { adminWindow, loadBuilder, settle, until, wouldWarnOnLeave, saveAndRead, type, script, attr };
