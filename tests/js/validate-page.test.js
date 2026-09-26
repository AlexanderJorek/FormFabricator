'use strict';

/* front.js validatePage(): required checks, validation rules, and the fields it must skip. */

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadPage, fragment } = require('./support/page');

const page = loadPage();
const validatePage = page.hooks.validatePage;

/**
 * A field wrapper as FormRenderer writes it: opts.required adds the required class, opts.validate the rule list.
 */
function field(type, opts, inner) {
    const cls = 'fabricator-field fabricator-field--' + type + (opts.required ? ' fabricator-required-field' : '');
    const rules = opts.validate ? " data-validate='" + JSON.stringify(opts.validate) + "'" : '';
    return '<div class="' + cls + '"' + rules + '>' + inner + '<span class="fabricator-field-error"></span></div>';
}

function validate(html) {
    const r = validatePage(fragment(page, html));
    return { valid: r.valid, hasRequired: r.hasRequired, hasInvalid: r.hasInvalid };
}

test('an empty scope is valid', () => {
    assert.deepEqual(validate(''), { valid: true, hasRequired: false, hasInvalid: false });
});

test('a required text field left empty is reported', () => {
    const r = validate(field('text', { required: true }, '<input type="text" value="">'));
    assert.equal(r.valid, false);
    assert.equal(r.hasRequired, true);
});

test('a required text field filled in passes', () => {
    assert.equal(validate(field('text', { required: true }, '<input type="text" value="hello">')).valid, true);
});

test('a type in FabricatorSkipValidation is never checked', () => {
    assert.equal(validate(field('html', { required: true }, '<input type="text" value="">')).valid, true);
});

test('a field hidden by a condition is never checked', () => {
    const r = validate('<div data-conditions=\'{"rules":[]}\' style="display:none">'
        + field('text', { required: true }, '<input type="text" value="">') + '</div>');
    assert.equal(r.valid, true);
});

test('an invalid email is reported as invalid, not missing', () => {
    const r = validate(field('email', { validate: ['email'] }, '<input type="email" value="not-valid">'));
    assert.equal(r.valid, false);
    assert.equal(r.hasInvalid, true);
});

test('each required sub-input of a composite field is checked', () => {
    assert.equal(validate(field('name', {}, '<input type="text" value="Hans"><input type="text" required value="">')).hasRequired, true);
    assert.equal(validate(field('name', {}, '<input type="text" required value="Hans"><input type="text" required value="Müller">')).valid, true);
});

test('an unregistered rule is ignored and the next rule still runs', () => {
    assert.equal(validate(field('text', { validate: ['not-a-real-rule'] }, '<input type="text" value="anything">')).valid, true);
    const r = validate(field('email', { validate: ['not-a-real-rule', 'email'] }, '<input type="email" value="not-valid">'));
    assert.equal(r.hasInvalid, true);
});

test('the first error message is written into the field', () => {
    const el = fragment(page, field('email', { validate: ['email'] }, '<input type="email" value="not-valid">'));
    validatePage(el);
    assert.equal(el.querySelector('.fabricator-field-error').textContent, page.window.FabricatorForms.i18n.email_invalid);
});
