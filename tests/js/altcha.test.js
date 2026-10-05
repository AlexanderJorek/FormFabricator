'use strict';

/*
 * The ALTCHA CAPTCHA (CaptchaField with the provider "altcha", Utils\Altcha). The server's challenge is checked against
 * ALTCHA's own solver and verifier from the npm package, which must be the very release vendored in vendor/altcha. The
 * field is checked in a whole form: its widget's settings, its translated texts, the required check and the reset after
 * a failed submission. The widget solves in Web Workers, which jsdom lacks, so a test puts in its answer by hand.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { fixture, loadPage } = require('./support/page');

const repo = path.join(__dirname, '..', '..');

/** A page with `html`; `beforeBoot` runs before front.js boots on DOMContentLoaded. */
async function open(html, beforeBoot) {
    const page = loadPage(html);
    if (beforeBoot) beforeBoot(page.window);
    if (page.document.readyState === 'loading') {
        await new Promise((resolve) => page.document.addEventListener('DOMContentLoaded', () => page.window.setTimeout(resolve, 0)));
    }
    assert.equal(page.window.__fabricatorFrontInited, true, 'front.js booted');
    assert.deepEqual(page.errors, []);
    return page;
}

async function settle(page) {
    for (let i = 0; i < 5; i++) {
        await new Promise((resolve) => page.window.setTimeout(resolve, 0));
    }
}

/** $altcha as the widget module sets it up: an i18n store holding its English texts. */
function altchaGlobal() {
    const data = { en: { label: 'English label', enterCode: 'Enter code' } };
    return { data, i18n: { get: (lang) => data[lang], set: (lang, strings) => { data[lang] = strings; } } };
}

/** What the widget does once solved: its hidden input, named after the field, holds the payload. */
function solve(widget, payload) {
    const input = widget.ownerDocument.createElement('input');
    input.type = 'hidden';
    input.name = widget.getAttribute('name');
    input.value = payload;
    widget.appendChild(input);
}

test('vendor/altcha is the npm release this suite runs ALTCHA\'s own code from', () => {
    const vendored = fs.readFileSync(path.join(repo, 'vendor/altcha/altcha.min.js'));
    const npm = fs.readFileSync(path.join(repo, 'node_modules/altcha/dist/main/altcha.min.js'));
    assert.equal(Buffer.compare(vendored, npm), 0, 'vendor/altcha/altcha.min.js differs from the npm package\'s');
    const version = JSON.parse(fs.readFileSync(path.join(repo, 'node_modules/altcha/package.json'), 'utf8')).version;
    assert.match(fs.readFileSync(path.join(repo, 'vendor/altcha/VERSION'), 'utf8'), new RegExp('^altcha ' + version.replace(/\./g, '\\.') + '$', 'm'));
});

test('ALTCHA\'s own solver finds the answer the server expects, and its own verifier accepts the server\'s challenge', async () => {
    const { solveChallenge, verifySolution, pbkdf2 } = await import('altcha/lib');
    const { challenge, secrets, solution, verdicts } = fixture.altcha;

    const solved = await solveChallenge({ challenge, deriveKey: pbkdf2.deriveKey });
    assert.equal(solved.counter, solution.counter);
    assert.equal(solved.derivedKey, solution.derivedKey);

    const result = await verifySolution({
        challenge,
        solution: solved,
        deriveKey: pbkdf2.deriveKey,
        hmacSignatureSecret: secrets.challenge,
        hmacKeySignatureSecret: secrets.key,
    });
    assert.equal(result.verified, true, JSON.stringify(result));

    // Utils\Altcha::verify() on that answer, posted as the widget posts it: accepted, then refused as used; and refused
    // once a parameter of the challenge is changed.
    assert.deepEqual(verdicts, [true, false, false]);
});

test('ALTCHA field: the widget posts under the field id, starts on the visitor\'s first focus, and records no movements', async () => {
    const page = await open(fixture.forms['with altcha']);
    const widget = page.document.querySelector('altcha-widget');

    assert.equal(widget.getAttribute('name'), 'cap');
    assert.equal(widget.getAttribute('auto'), 'onfocus');
    assert.match(widget.getAttribute('challenge'), /\/admin-ajax\.php\?action=fabricator_altcha_challenge$/);
    const config = JSON.parse(widget.getAttribute('configuration'));
    assert.equal(config.humanInteractionSignature, false);
    assert.equal(config.hideFooter, true);
    assert.equal(page.document.querySelector('.fabricator-captcha-gate'), null, 'no reCAPTCHA gate');
});

test('ALTCHA field: the plugin\'s texts reach the widget\'s language store, over its English ones', async () => {
    const altcha = altchaGlobal();
    await open(fixture.forms['with altcha'], (w) => { w.$altcha = altcha; });

    const strings = altcha.data.fabricator;
    assert.ok(strings, 'registered under the widget\'s language attribute');
    assert.equal(strings.label, 'I\'m not a robot');
    assert.equal(strings.footer, '');
    assert.equal(strings.enterCode, 'Enter code', 'a text the plugin does not set stays English');
});

test('ALTCHA field: a widget module still loading gets the texts once it has defined the element', async () => {
    const altcha = altchaGlobal();
    const page = await open(fixture.forms['with altcha']);
    assert.equal(altcha.data.fabricator, undefined);

    page.window.$altcha = altcha;
    page.window.customElements.define('altcha-widget', class extends page.window.HTMLElement {});
    await settle(page);

    assert.equal(altcha.data.fabricator.label, 'I\'m not a robot');
});

test('ALTCHA field: required until solved, then the submission carries the payload under the field id', async () => {
    const page = await open(fixture.forms['with altcha']);
    const form = page.document.querySelector('form');
    const widget = form.querySelector('altcha-widget');

    assert.equal(page.hooks.validatePage(form).valid, false, 'not solved');
    solve(widget, '');
    assert.equal(page.hooks.validatePage(form).valid, false, 'the widget\'s input is still empty');
    widget.querySelector('input').value = 'cGF5bG9hZA==';
    assert.equal(page.hooks.validatePage(form).valid, true);
});

test('ALTCHA field: a failed submission resets the widget for a new challenge, since each counts once', async () => {
    const page = await open(fixture.forms['with altcha']);
    const form = page.document.querySelector('form');
    const widget = form.querySelector('altcha-widget');
    let resets = 0;
    widget.reset = () => { resets++; };
    solve(widget, 'cGF5bG9hZA==');

    const submissions = [];
    page.window.fetch = (url, opts) => {
        const body = opts.body;
        const isToken = body instanceof page.window.URLSearchParams && body.get('action') === 'fabricator_forms_get_token';
        if (!isToken) submissions.push(body.get('cap'));
        const json = isToken
            ? { success: true, data: { nonce: 'n', token: 't' } }
            : { success: false, data: { message: 'Please confirm the CAPTCHA.', errors: { cap: 'Please confirm the CAPTCHA.' } } };
        return Promise.resolve({ json: () => Promise.resolve(json) });
    };
    form.dispatchEvent(new page.window.Event('submit', { bubbles: true, cancelable: true }));
    await settle(page);

    assert.deepEqual(submissions, ['cGF5bG9hZA==']);
    assert.equal(resets, 1);
});
