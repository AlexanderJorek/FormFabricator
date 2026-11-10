'use strict';

/* The field scripts Assets::frontFieldAssets() enqueues before front.js, and the test hooks front.js exports. */

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture, loadPage, ROOT } = require('./support/page');

test('front.js boots on an empty page without a script error', () => {
    const page = loadPage();
    assert.deepEqual(page.errors, []);
});

test('layout-only field types skip validation', () => {
    const skip = loadPage().window.FabricatorForms.skipValidation;
    assert.ok(Array.isArray(skip));
    for (const type of ['html', 'pagebreak', 'page-header']) {
        assert.ok(skip.includes(type), type + ' is not in FabricatorForms.skipValidation');
    }
});

test('each field script registers exactly the function its field type declares', () => {
    assert.ok(fixture.fieldScripts.length > 0);
    for (const { type, global, key, asset } of fixture.fieldScripts) {
        // Alone, as the browser runs it: a file of its own that touches nothing but its own key.
        const window = {};
        vm.runInNewContext(fs.readFileSync(path.join(ROOT, asset), 'utf8'), { window }, { filename: asset });
        assert.deepEqual(Object.keys(window), [global], type + ': ' + asset);
        assert.deepEqual(Object.keys(window[global]), [key], type + ': ' + asset);
        assert.equal(typeof window[global][key], 'function', type + ': ' + asset);
    }
});

test('every field script is enqueued, and only field scripts', () => {
    const declared = new Set(fixture.fieldScripts.map((s) => s.asset));
    const enqueued = new Set(Object.values(fixture.fieldAssets.scripts));
    assert.deepEqual([...enqueued].sort(), [...declared].sort());
    const files = fs.readdirSync(path.join(ROOT, 'assets', 'js', 'fields')).filter((f) => f.endsWith('.js'));
    assert.deepEqual(files.map((f) => 'assets/js/fields/' + f).sort(), [...enqueued].sort(), 'a file no field declares');
});

test('every validation rule a field declares is a function', () => {
    const validators = loadPage().window.FabricatorValidators;
    const expected = [
        'email', 'iban', 'phone', 'number-range', 'date-format', 'currency-range', 'text-word-limit',
        'textarea-word-limit', 'website-url', 'checkbox-count', 'slider-range', 'debit-bic', 'debit-sort-code',
        'debit-routing', 'debit-account', 'debit-required',
    ];
    for (const rule of expected) {
        assert.equal(typeof validators[rule], 'function', rule);
    }
});

test('every field with client behaviour registers an init function', () => {
    const inits = loadPage().window.FabricatorFieldInits;
    for (const type of ['slider', 'rating', 'date', 'select', 'radio', 'checkbox', 'upload', 'signature', 'directdebit']) {
        assert.equal(typeof inits[type], 'function', type);
    }
});

test('front.js exports its test hooks under __FABRICATOR_TEST__', () => {
    const { hooks } = loadPage();
    for (const name of ['validatePage', 'initConditions', 'showCaptchaBlockedNotice', 'resetFormsOnBfcacheRestore']) {
        assert.equal(typeof hooks[name], 'function', name);
    }
});
