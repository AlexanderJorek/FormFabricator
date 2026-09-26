'use strict';

/*
 * A fresh jsdom page running the real assets/js/front.js, with exactly the globals a WordPress page gets:
 * window.FabricatorForms from Assets::frontLocalization() and the window.Fabricator* field globals from
 * Assets::frontFieldAssets(), read from the fixture tests/js/build-fixture.php writes (`npm test` runs it first).
 *
 * window.__FABRICATOR_TEST__ makes front.js export its internals as window.FabricatorTestHooks.
 */

const fs = require('node:fs');
const path = require('node:path');
const { JSDOM, VirtualConsole } = require('jsdom');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const FIXTURE_PATH = path.join(__dirname, '..', '.generated', 'fixture.json');

if (!fs.existsSync(FIXTURE_PATH)) {
    throw new Error('Missing ' + FIXTURE_PATH + ' — run `npm test`, or `php tests/js/build-fixture.php` first.');
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE_PATH, 'utf8'));
const frontJs = fs.readFileSync(path.join(ROOT, 'assets', 'js', 'front.js'), 'utf8');

/* CSSOM "serialize an identifier" — every browser has CSS.escape(), jsdom does not, and front.js uses it to look up
   a field by name. https://drafts.csswg.org/cssom/#serialize-an-identifier */
function cssEscape(value) {
    const string = String(value);
    let result = '';
    for (let i = 0; i < string.length; i++) {
        const code = string.charCodeAt(i);
        const ch = string.charAt(i);
        if (code === 0) {
            result += '�';
        } else if ((code >= 0x1 && code <= 0x1F) || code === 0x7F
            || (i === 0 && code >= 0x30 && code <= 0x39)
            || (i === 1 && code >= 0x30 && code <= 0x39 && string.charCodeAt(0) === 0x2D)) {
            result += '\\' + code.toString(16) + ' ';
        } else if (i === 0 && string.length === 1 && code === 0x2D) {
            result += '\\' + ch;
        } else if (code >= 0x80 || code === 0x2D || code === 0x5F
            || (code >= 0x30 && code <= 0x39) || (code >= 0x41 && code <= 0x5A) || (code >= 0x61 && code <= 0x7A)) {
            result += ch;
        } else {
            result += '\\' + ch;
        }
    }
    return result;
}

/**
 * @param {string} [bodyHtml] Markup to place in <body> before front.js boots.
 * @returns {{window: Window, document: Document, hooks: object, errors: string[]}}
 */
function loadPage(bodyHtml = '') {
    const errors = [];
    const virtualConsole = new VirtualConsole();
    // A script error inside front.js or a field init surfaces as a test failure, not as console noise.
    virtualConsole.on('jsdomError', (e) => errors.push(e.message));
    const dom = new JSDOM('<!DOCTYPE html><html><body>' + bodyHtml + '</body></html>', {
        runScripts: 'outside-only',
        pretendToBeVisual: true,
        url: 'https://example.test/',
        virtualConsole,
    });
    const w = dom.window;
    if (!w.CSS) {
        w.CSS = {};
    }
    if (typeof w.CSS.escape !== 'function') {
        w.CSS.escape = cssEscape;
    }
    w.__FABRICATOR_TEST__ = true;
    w.FabricatorForms = JSON.parse(JSON.stringify(fixture.localization));
    for (const [name, literal] of Object.entries(fixture.globals)) {
        w.eval('window.' + name + '=' + literal + ';');
    }
    w.eval(frontJs);
    return { window: w, document: w.document, hooks: w.FabricatorTestHooks || {}, errors };
}

/**
 * A wrapper <div> holding $html, as the old browser harness built its cases.
 */
function fragment(page, html) {
    const el = page.document.createElement('div');
    el.innerHTML = html;
    return el;
}

module.exports = { fixture, loadPage, fragment, cssEscape };
