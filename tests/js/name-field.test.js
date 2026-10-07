'use strict';

/*
 * The Name field's salutation: drawn as the form's own dropdown (NameField.js), like every other dropdown of the form,
 * while the native select underneath keeps carrying the value the server reads.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture, loadPage } = require('./support/page');

async function open(html) {
    const page = loadPage(html);
    if (page.document.readyState === 'loading') {
        await new Promise((resolve) => page.document.addEventListener('DOMContentLoaded', () => page.window.setTimeout(resolve, 0)));
    }
    assert.equal(page.window.__fabricatorFrontInited, true, 'front.js booted');
    assert.deepEqual(page.errors, []);
    return page;
}

test('salutation: a labelled combobox replaces the native list, which stays for the form data', async () => {
    const page = await open(fixture.forms['name with salutation']);
    const form = page.document.querySelector('form');
    const native = form.querySelector('select[name="who[prefix]"]');
    const combo = form.querySelector('.fabricator-name-prefix-custom');

    assert.ok(combo, 'the dropdown is drawn');
    assert.equal(combo.getAttribute('role'), 'combobox');
    assert.equal(combo.tabIndex, 0, 'reachable by keyboard');
    assert.equal(native.tabIndex, -1, 'the native select is no second tab stop');
    assert.equal(native.getAttribute('aria-hidden'), 'true');
    const label = page.document.getElementById(combo.getAttribute('aria-labelledby'));
    assert.equal(label.getAttribute('for'), native.id, 'named by the sub-field label');
    assert.equal(combo.getAttribute('aria-required'), 'true');
    assert.equal(
        Array.from(combo.querySelectorAll('[role="option"]')).map((o) => o.dataset.value).join(','),
        Array.from(native.options).map((o) => o.value).join(','),
        'one option per native option'
    );
});

test('salutation: a click and the arrow keys set the native select; a form reset shows its value again', async () => {
    const page = await open(fixture.forms['name with salutation']);
    const form = page.document.querySelector('form');
    const native = form.querySelector('select[name="who[prefix]"]');
    const combo = form.querySelector('.fabricator-name-prefix-custom');
    const options = combo.querySelectorAll('[role="option"]');

    combo.click();
    assert.equal(combo.getAttribute('aria-expanded'), 'true');
    options[2].click();
    assert.equal(native.value, options[2].dataset.value);
    assert.equal(combo.getAttribute('aria-expanded'), 'false', 'closes on a choice');
    assert.equal(combo.querySelector('.fabricator-name-prefix-display').textContent, options[2].textContent);
    assert.equal(options[2].getAttribute('aria-selected'), 'true');

    combo.dispatchEvent(new page.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    assert.equal(native.value, options[3].dataset.value);

    form.reset();
    form.dispatchEvent(new page.window.Event('fabricator:reset'));
    assert.equal(native.value, '');
    assert.ok(combo.querySelector('.fabricator-name-prefix-display').classList.contains('fabricator-name-prefix-display--placeholder'));
});

test('salutation: still required until one is chosen', async () => {
    const page = await open(fixture.forms['name with salutation']);
    const form = page.document.querySelector('form');
    const fill = (name, value) => { form.querySelector(`[name="${name}"]`).value = value; };
    fill('who[fname]', 'Ada');
    fill('who[lname]', 'Lovelace');

    assert.equal(page.hooks.validatePage(form).valid, false, 'no salutation');
    form.querySelectorAll('.fabricator-name-prefix-custom [role="option"]')[1].click();
    assert.equal(page.hooks.validatePage(form).valid, true);
});
