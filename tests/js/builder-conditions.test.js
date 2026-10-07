'use strict';

/*
 * Conditions set up in the builder's Conditions tab reach the front end as the admin set them, TESTING.md §2: a direct
 * "show if" on a field, a "hide if" on a group, and a rule on a choice field's option. The builder saves the form
 * (admin-builder.js, driven through its controls); render-form.php runs the save's sanitizing and FormRenderer on it,
 * and says what FormProcessor hides for the same answers; front.js then runs on that markup. Each side's evaluation on
 * its own is condition-parity.test.js's.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { loadPage } = require('./support/page');
const { loadBuilder, saveAndRead, type } = require('./support/admin-page');

const TEXT = (id) => ({ id, type: 'text', label: id.toUpperCase() });

/** Opens a top-level row's settings (a group's from its header) on the Conditions tab. */
function openField(page, idx) {
    const row = page.document.querySelectorAll('#fabricator-field-list > .fabricator-field-row')[idx];
    (row.querySelector(':scope > .fabricator-group-row-hdr') || row).click();
    page.document.querySelector('#fabricator-settings-modal [data-stab="conditions"]').click();
    return page.document.getElementById('fabricator-stab-conditions');
}

/** Adds a rule in the open Conditions tab: "<action> this field when <field> <operator> <value>". */
function addRule(page, panel, { action, field, operator = 'equals', value, option }) {
    panel.querySelector('.fabricator-cond-sentence .fabricator-seg-btn[data-value="' + action + '"]').click();
    panel.querySelector('.fabricator-cond-add').click();
    const row = panel.querySelector('.fabricator-cond-rule:last-child');
    const [fieldSel, opSel] = row.querySelectorAll('.fabricator-cond-rule-top select');
    if (fieldSel.value !== field) {
        fieldSel.value = field;
        fieldSel.dispatchEvent(new page.window.Event('change'));
    }
    opSel.value = operator;
    opSel.dispatchEvent(new page.window.Event('change'));
    if (option !== undefined) {
        const optSel = row.querySelector('.fabricator-cond-opt-sel');
        optSel.value = option;
        optSel.dispatchEvent(new page.window.Event('change'));
    } else {
        type(page, row.querySelector('.fabricator-cond-val'), value);
    }
}

/** The saved form as the server renders it, and the ids FormProcessor hides for each set of answers. */
function serverSide(fields, posted) {
    const out = execFileSync('php', [path.join(__dirname, 'render-form.php')], { input: JSON.stringify({ fields, posted }) });
    return JSON.parse(String(out));
}

/** front.js on the rendered form with `answers` filled in: the ids of `watched` it hides. */
async function frontJsHides(html, answers, watched) {
    const page = loadPage(html);
    if (page.document.readyState === 'loading') {
        await new Promise((resolve) => page.document.addEventListener('DOMContentLoaded', () => page.window.setTimeout(resolve, 0)));
    }
    assert.equal(page.window.__fabricatorFrontInited, true, 'front.js booted');
    const form = page.document.querySelector('form');
    for (const [id, value] of Object.entries(answers)) {
        for (const input of form.querySelectorAll('[name="' + id + '"]')) {
            if (input.type === 'radio') {
                input.checked = input.value === value;
            } else {
                input.value = value;
            }
            input.dispatchEvent(new page.window.Event(input.type === 'radio' ? 'change' : 'input', { bubbles: true }));
        }
    }
    assert.deepEqual(page.errors, []);
    return watched.filter((id) => {
        let el = form.querySelector('[data-field-id="' + id + '"]');
        assert.ok(el, 'the page has field ' + id);
        while (el && el !== form && el.style.display !== 'none' && !el.hidden) {
            el = el.parentElement;
        }
        return el !== null && el !== form;
    });
}

test('a "show if" on a field, a "hide if" on a group and a rule on an option, set up in the builder, are what the page does', async () => {
    const builder = await loadBuilder({ fields: [
        TEXT('country'),
        TEXT('vat'),
        { id: 'company', type: 'group', label: 'Company', children: [TEXT('reg')] },
        { id: 'pref', type: 'radio', label: 'Contact by', options: [{ label: 'Phone', value: 'phone' }, { label: 'Email', value: 'email' }] },
        TEXT('tel'),
    ] });
    addRule(builder, openField(builder, 1), { action: 'show', field: 'country', value: 'DE' });
    addRule(builder, openField(builder, 2), { action: 'hide', field: 'country', value: 'DE' });
    addRule(builder, openField(builder, 4), { action: 'show', field: 'pref', option: 'phone' });
    const saved = await saveAndRead(builder);
    assert.deepEqual(builder.errors, []);

    assert.deepEqual(saved.fields[1].conditions, { action: 'show', match: 'all', rules: [{ field_id: 'country', operator: 'equals', value: 'DE', use_option: false }] });
    assert.equal(saved.fields[2].conditions.action, 'hide');
    assert.deepEqual(saved.fields[4].conditions.rules.map((r) => [r.field_id, r.operator, r.value]), [['pref', 'equals', 'phone']]);

    const answers = [
        { country: 'DE', pref: 'email', vat: '', reg: '', tel: '' },
        { country: 'FR', pref: 'phone', vat: '', reg: '', tel: '' },
    ];
    const expected = [['company', 'tel'], ['vat']];
    const watched = ['vat', 'company', 'tel'];
    const { html, hidden } = serverSide(saved.fields, answers);
    for (const [i, given] of answers.entries()) {
        const server = hidden[i].filter((id) => watched.includes(id)).sort();
        assert.deepEqual(server, expected[i], 'the server, for ' + JSON.stringify(given));
        assert.deepEqual((await frontJsHides(html, given, watched)).sort(), expected[i], 'front.js, for ' + JSON.stringify(given));
    }
});
