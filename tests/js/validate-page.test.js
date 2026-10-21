'use strict';

/* front.js validatePage(): required checks, validation rules, and the fields it must skip. */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture, loadPage, fragment } = require('./support/page');

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

/* What an answer may hold: front.js refuses what the server's Utils\AnswerText refuses, before anything is sent. */
test('an answer is refused in the browser exactly where the server refuses it', () => {
    const disagreements = [];
    for (const [typed, oneLineOk, multiLineOk] of fixture.answerText) {
        const textarea = fragment(page, field('textarea', {}, '<textarea></textarea>'));
        textarea.querySelector('textarea').value = typed;
        if (validatePage(textarea).valid !== multiLineOk) {
            disagreements.push('textarea ' + JSON.stringify(typed) + (multiLineOk ? ' passes' : ' is refused') + ' on the server');
        }
        // A text input can't hold a line break: the browser strips it before anything reads the value.
        if (!/[\r\n]/.test(typed)) {
            const input = fragment(page, field('text', {}, '<input type="text">'));
            input.querySelector('input').value = typed;
            if (validatePage(input).valid !== oneLineOk) {
                disagreements.push('input ' + JSON.stringify(typed) + (oneLineOk ? ' passes' : ' is refused') + ' on the server');
            }
        }
    }
    assert.deepEqual(disagreements, []);
});

test('a refused answer names the character at its field', () => {
    const scope = fragment(page, field('text', {}, '<input type="text">'));
    scope.querySelector('input').value = 'Ada​Lovelace';
    const r = validatePage(scope);
    assert.equal(r.hasInvalid, true);
    assert.match(scope.querySelector('.fabricator-field-error').textContent, /U\+200B/);
});

test('a field whose answer is not typed text is left to its own checks', () => {
    // An upload, a CAPTCHA, post data: BaseField::typedAnswer() is false (FabricatorUntypedAnswers).
    const scope = fragment(page, field('postdata', {}, '<input type="text">'));
    scope.querySelector('input').value = 'a​b';
    assert.equal(validatePage(scope).valid, true);
});
