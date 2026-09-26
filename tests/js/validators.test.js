'use strict';

/*
 * Each window.FabricatorValidators rule on its own: null for a valid (or empty) value, the field's localized message
 * otherwise. The rules mirror each field's PHP validate(); the PHP side is in tests/Unit.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadPage, fragment } = require('./support/page');

const page = loadPage();
const i18n = page.window.FabricatorForms.i18n;

function check(rule, html, setup) {
    const el = fragment(page, html);
    if (setup) {
        setup(el);
    }
    return page.window.FabricatorValidators[rule](el);
}

function phone(value, data) {
    return check('phone', '<input type="tel">', (el) => {
        const inp = el.querySelector('input');
        inp.value = value;
        Object.assign(inp.dataset, data);
    });
}

test('email', () => {
    assert.equal(check('email', '<input type="email" value="test@example.com">'), null);
    assert.equal(check('email', '<input type="email" value="not-an-email">'), i18n.email_invalid);
});

test('iban reads the flags its input handler sets', () => {
    const valid = (el) => { el.querySelector('input')._fabricatorIbanValid = true; };
    const invalid = (el) => { el.querySelector('input')._fabricatorIbanInvalid = true; };
    assert.equal(check('iban', '<input class="fabricator-sepa-iban" value="DE89370400440532013000">', valid), null);
    assert.equal(check('iban', '<input class="fabricator-sepa-iban" value="DE00000000000000000000">', invalid), i18n.sepa_iban_invalid);
    assert.equal(check('iban', '<input class="fabricator-sepa-iban" value="DE123">'), i18n.sepa_iban_incomplete, 'no flag yet: incomplete');
});

test('phone', () => {
    assert.equal(phone('', { phoneMode: 'any' }), null, 'empty');
    assert.equal(phone('abc123', {}), null, 'no mode: not validated');
    assert.equal(phone('+4915123456789', { phoneMode: 'any' }), null);
    assert.equal(phone('123', { phoneMode: 'any' }), i18n.phone_invalid, 'too short');
    const germanOnly = { phoneMode: 'countries', phoneCountryMode: 'allow', phoneCountryList: '["49"]' };
    assert.equal(phone('015123456789', germanOnly), i18n.phone_intl_required, 'no + prefix');
    assert.equal(phone('+4915123456789', germanOnly), null, 'DE in the allow list');
    assert.equal(phone('+33123456789', germanOnly), i18n.phone_country_blocked, 'FR not in the allow list');
});

test('number-range: bounds', () => {
    const n = (v) => check('number-range', '<input type="number" value="' + v + '" min="1" max="10">');
    assert.equal(n(''), null);
    assert.equal(n('5'), null);
    assert.equal(n('0'), i18n.number_min.replace('%s', '1'));
    assert.equal(n('11'), i18n.number_max.replace('%s', '10'));
});

test('number-range: step 1 refuses decimals (TESTING.md §4, 1.0.7)', () => {
    const n = (v, attrs = 'step="1"') => check('number-range', '<input type="number" value="' + v + '" ' + attrs + '>');
    assert.equal(n('3'), null);
    assert.equal(n('2.5'), i18n.number_step.replace('%s', '1'));
    assert.equal(n('0.3', 'min="0.1" step="0.1"'), null, 'on the grid min + k·step despite float error');
    assert.equal(n('0.35', 'min="0.1" step="0.1"'), i18n.number_step.replace('%s', '0.1'));
});

test('date-format', () => {
    const d = (v) => check('date-format', '<input class="fabricator-date-text" value="' + v + '">');
    assert.equal(d(''), null);
    assert.equal(d('15.06.2024'), null);
    assert.equal(typeof d('2024-06-15'), 'string', 'wrong format');
    assert.equal(d('32.01.2024'), i18n.date_invalid_date);
});

test('currency-range: bounds', () => {
    const c = (v) => check('currency-range', '<input type="number" value="' + v + '" min="10" max="1000">');
    assert.equal(c(''), null);
    assert.equal(c('100'), null);
    assert.equal(c('5'), i18n.currency_min.replace('%s', '10'));
    assert.equal(c('2000'), i18n.currency_max.replace('%s', '1000'));
});

test('currency-range: at most two decimal places (TESTING.md §4, 1.0.7)', () => {
    const c = (v) => check('currency-range', '<input type="number" value="' + v + '">');
    assert.equal(c('12.345'), i18n.currency_decimals);
    assert.equal(c('12.34'), null);
    assert.equal(c('12.5'), null);
});

test('text-word-limit', () => {
    assert.equal(check('text-word-limit', '<input type="text" value="too many words here">'), null, 'no limit set');
    assert.equal(check('text-word-limit', '<input type="text" value="two words" data-word-limit="5">'), null);
    assert.equal(
        check('text-word-limit', '<input type="text" value="one two three four" data-word-limit="3">'),
        i18n.word_limit_exceeded.replace('%1$d', '3').replace('%2$d', '4')
    );
});

test('textarea-word-limit', () => {
    assert.equal(check('textarea-word-limit', '<textarea data-word-limit="5">hello world</textarea>'), null);
    assert.equal(typeof check('textarea-word-limit', '<textarea data-word-limit="2">one two three</textarea>'), 'string');
});

test('website-url', () => {
    assert.equal(check('website-url', '<input type="url" value="not-a-url">'), null, 'not switched on');
    assert.equal(check('website-url', '<input type="url" value="https://example.de" data-validate-url="1">'), null);
    assert.equal(check('website-url', '<input type="url" value="not-a-url" data-validate-url="1">'), i18n.website_invalid_url);
});

test('checkbox-count', () => {
    const group = (min, max, checked) => '<div class="fabricator-checkbox-group" data-min-selections="' + min + '" data-max-selections="' + max + '">'
        + checked.map((c) => '<input type="checkbox"' + (c ? ' checked' : '') + '>').join('') + '</div>';
    assert.equal(check('checkbox-count', '<input type="checkbox" checked>'), null, 'no group');
    assert.equal(check('checkbox-count', '<div class="fabricator-checkbox-group"><input type="checkbox" checked></div>'), null, 'no limits');
    assert.equal(check('checkbox-count', group(2, 0, [true, false])), i18n.checkbox_min.replace('%d', '2'));
    assert.equal(check('checkbox-count', group(2, 0, [true, true])), null);
    assert.equal(check('checkbox-count', group(0, 1, [true, true])), i18n.checkbox_max.replace('%d', '1'));
});

test('slider-range', () => {
    assert.equal(check('slider-range', '<input type="hidden" value="5">'), null, 'no slider');
    assert.equal(check('slider-range', '<div class="fabricator-slider-wrap" data-min="0" data-max="100"></div><input type="hidden" value="50">'), null);
    assert.equal(typeof check('slider-range', '<div class="fabricator-slider-wrap" data-min="10" data-max="100"></div><input type="hidden" value="5">'), 'string');
    const range = (from, to) => '<div class="fabricator-slider-wrap fabricator-slider-wrap--range" data-min="0" data-max="100">'
        + '<input class="fabricator-slider-input-from" value="' + from + '"><input class="fabricator-slider-input-to" value="' + to + '"></div>';
    assert.equal(check('slider-range', range(20, 80)), null);
    assert.equal(typeof check('slider-range', range(-5, 80)), 'string');
});

test('sepa-bic', () => {
    const bic = (v) => check('sepa-bic', '<div><input class="fabricator-sepa-bic" value="' + v + '"><span class="fabricator-field-error"></span></div>');
    assert.equal(bic(''), null);
    assert.equal(bic('COBADEFF'), null);
    assert.equal(bic('COBADEFFXXX'), null);
});

test('sepa-bic: a wrong BIC is flagged at the BIC input itself', () => {
    // The SEPA rules write their message into the sub-field's own error slot and return a zero-width space: the field
    // counts as invalid without a second, field-level message.
    let el;
    const r = check('sepa-bic', '<div><input class="fabricator-sepa-bic" value="INVALID"><span class="fabricator-field-error"></span></div>', (e) => { el = e; });
    assert.equal(r, '​');
    assert.equal(el.querySelector('.fabricator-field-error').textContent, i18n.sepa_bic_invalid);
});

test('sepa-required', () => {
    const sepa = (iban, bic, holder) => '<div><input class="fabricator-sepa-iban" value="' + iban + '"><span class="fabricator-field-error"></span></div>'
        + '<div><input class="fabricator-sepa-bic" value="' + bic + '"><span class="fabricator-field-error"></span></div>'
        + '<div><input class="fabricator-sepa-holder" value="' + holder + '"><span class="fabricator-field-error"></span></div>';
    const required = (el) => { el.dataset.required = 'true'; };
    assert.equal(check('sepa-required', '<input class="fabricator-sepa-iban" value="">'), null, 'not required');
    assert.equal(typeof check('sepa-required', sepa('', 'COBADEFF', 'Max'), required), 'string', 'IBAN missing');
    assert.equal(check('sepa-required', sepa('DE89370400440532013000', 'COBADEFF', 'Max Muster'), required), null);
});
