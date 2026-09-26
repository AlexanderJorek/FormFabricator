'use strict';

/* The globals Assets::frontFieldAssets() hands front.js, and the test hooks front.js exports. */

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadPage } = require('./support/page');

test('front.js boots on an empty page without a script error', () => {
    const page = loadPage();
    assert.deepEqual(page.errors, []);
});

test('layout-only field types skip validation', () => {
    const skip = loadPage().window.FabricatorSkipValidation;
    assert.ok(Array.isArray(skip));
    for (const type of ['html', 'pagebreak', 'page-header']) {
        assert.ok(skip.includes(type), type + ' is not in FabricatorSkipValidation');
    }
});

test('every validation rule a field declares is a function', () => {
    const validators = loadPage().window.FabricatorValidators;
    const expected = [
        'email', 'iban', 'phone', 'number-range', 'date-format', 'currency-range', 'text-word-limit',
        'textarea-word-limit', 'website-url', 'checkbox-count', 'slider-range', 'sepa-bic', 'sepa-required',
    ];
    for (const rule of expected) {
        assert.equal(typeof validators[rule], 'function', rule);
    }
});

test('every field with client behaviour registers an init function', () => {
    const inits = loadPage().window.FabricatorFieldInits;
    for (const type of ['slider', 'rating', 'date', 'select', 'radio', 'upload', 'signature', 'sepa']) {
        assert.equal(typeof inits[type], 'function', type);
    }
});

test('front.js exports its test hooks under __FABRICATOR_TEST__', () => {
    const { hooks } = loadPage();
    for (const name of ['validatePage', 'initConditions', 'showCaptchaBlockedNotice', 'resetFormsOnBfcacheRestore']) {
        assert.equal(typeof hooks[name], 'function', name);
    }
});
