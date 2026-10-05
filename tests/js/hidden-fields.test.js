'use strict';

/*
 * A field hidden by a condition — its own or its group's — is never validated or required-checked, including the
 * specially handled Consent, GDPR and CAPTCHA fields: a visitor can't answer a field they are not shown.
 * Uses real FormRenderer markup from the fixture: each field is shown only when "country" equals DE.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture, loadPage } = require('./support/page');

const page = loadPage();
const { document } = page;
const { validatePage, initConditions } = page.hooks;

function hiddenByAncestor(el) {
    for (; el; el = el.parentElement) {
        if (el.dataset && 'conditions' in el.dataset && el.style.display === 'none') {
            return true;
        }
    }
    return false;
}

function validateWithCountry(type, where, country, fill) {
    const wrap = document.createElement('div');
    wrap.innerHTML = '<form class="fabricator-form">' + fixture.conditionFixtures[type][where] + '</form>';
    document.body.appendChild(wrap);
    try {
        const ctrl = wrap.querySelector('[name="country"]');
        assert.ok(ctrl, 'setup: the country control is rendered');
        ctrl.value = country;
        initConditions(wrap);
        // :not() so the text field under test isn't confused with the "country" text control.
        const target = wrap.querySelector('.fabricator-field--' + type + ':not([data-field-id="country"])');
        assert.ok(target, 'setup: the ' + type + ' field is rendered');
        if (fill) {
            target.querySelectorAll('input, textarea, select').forEach((i) => {
                if (i.type === 'checkbox' || i.type === 'radio') {
                    i.checked = true;
                } else if (i.type !== 'hidden') {
                    i.value = 'x';
                }
            });
        }
        const r = validatePage(wrap.querySelector('.fabricator-form'));
        return { hidden: hiddenByAncestor(target), valid: r.valid, hasRequired: r.hasRequired };
    } finally {
        wrap.remove();
    }
}

test('the fixture covers the specially handled types', () => {
    assert.deepEqual(Object.keys(fixture.conditionFixtures).sort(), ['captcha', 'consent', 'gdpr', 'text']);
});

for (const type of Object.keys(fixture.conditionFixtures)) {
    for (const where of ['direct', 'group']) {
        test(type + ', ' + where + ' condition unmet: hidden and never checked', () => {
            const s = validateWithCountry(type, where, 'FR', false);
            assert.equal(s.hidden, true, 'setup: hidden by its ' + where + ' condition');
            assert.equal(s.valid, true);
        });
        // The CAPTCHA token only exists once Google's widget has loaded; the server rejects a missing one, so there
        // is no client-side required check to exercise.
        if (type === 'captcha') {
            continue;
        }
        test(type + ', ' + where + ' condition met, left empty: required is enforced', () => {
            const s = validateWithCountry(type, where, 'DE', false);
            assert.equal(s.hidden, false, 'setup: visible once its condition is met');
            assert.equal(s.valid, false);
            assert.equal(s.hasRequired, true);
        });
        test(type + ', ' + where + ' condition met, filled in: passes', () => {
            const s = validateWithCountry(type, where, 'DE', true);
            assert.equal(s.hidden, false);
            assert.equal(s.valid, true);
        });
    }
}
