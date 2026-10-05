'use strict';

/*
 * After a successful submission front.js resets the form (form.reset() plus a "fabricator:reset" event), and the page
 * can be used for a second entry. Fields that draw their own controls must follow the reset, or the second entry shows
 * one thing and sends another.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadPage } = require('./support/page');

function reset(page, form) {
    form.reset();
    form.dispatchEvent(new page.window.Event('fabricator:reset'));
}

test('dropdown: after the reset the custom display shows what the select will send again', () => {
    const page = loadPage(
        '<form><select class="fabricator-select" name="s">'
        + '<option value="">— Please select —</option><option value="a">Alpha</option><option value="b">Beta</option>'
        + '</select></form>'
    );
    const doc = page.document;
    page.window.FabricatorFieldInits.select(doc);
    const native = doc.querySelector('select');
    const display = doc.querySelector('.fabricator-select-display');

    native.value = 'b';
    native.dispatchEvent(new page.window.Event('change', { bubbles: true }));
    assert.equal(display.textContent, 'Beta');

    reset(page, doc.querySelector('form'));
    assert.equal(native.value, '');
    assert.equal(display.textContent, '— Please select —', 'the display kept "Beta" while the select would send nothing');
});

test('time: after the reset a "prefill now" field is filled again and its 12-hour hint follows', () => {
    const page = loadPage(
        '<form><div class="fabricator-field fabricator-field--time">'
        + '<input type="time" name="t" data-prefill-now="true" data-time-format="12h"></div></form>'
    );
    const doc = page.document;
    page.window.FabricatorFieldInits.time(doc);
    const input = doc.querySelector('input');
    const hint = doc.querySelector('.fabricator-time-12h-hint');
    assert.match(input.value, /^\d\d:\d\d$/, 'prefilled on load');

    input.value = '23:45';
    input.dispatchEvent(new page.window.Event('input', { bubbles: true }));
    assert.equal(hint.textContent.startsWith('11:45'), true);

    reset(page, doc.querySelector('form'));
    assert.match(input.value, /^\d\d:\d\d$/, 'prefilled again after the reset');
    assert.notEqual(hint.textContent, '', 'the hint shows the new time');
    assert.equal(hint.textContent.startsWith('11:45'), input.value === '23:45', 'not the old one');
});

test('back/forward cache: a restored page resets the dropdown display along with the select', () => {
    // The restore called form.reset() alone: no change, no fabricator:reset, so the custom display kept the old choice.
    const page = loadPage(
        '<form class="fabricator-form"><select class="fabricator-select" name="s">'
        + '<option value="">— Please select —</option><option value="a">Alpha</option></select></form>'
    );
    const doc = page.document;
    page.window.FabricatorFieldInits.select(doc);
    const native = doc.querySelector('select');
    native.value = 'a';
    native.dispatchEvent(new page.window.Event('change', { bubbles: true }));
    assert.equal(doc.querySelector('.fabricator-select-display').textContent, 'Alpha');

    page.hooks.resetFormsOnBfcacheRestore();
    assert.equal(native.value, '');
    assert.equal(doc.querySelector('.fabricator-select-display').textContent, '— Please select —');
});
