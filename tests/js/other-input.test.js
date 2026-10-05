'use strict';

/*
 * The "Other" text input of a radio set and of a checkbox set: shown only while "Other" is chosen. Each field type
 * carries its own script (CheckboxField.js, RadioField.js); the checkbox one used to live in RadioField's.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadPage } = require('./support/page');

function setUp(type, groupClass) {
    const page = loadPage(
        '<form><div class="' + groupClass + '">'
        + '<input type="' + type + '" name="f" value="a" id="a">'
        + '<input type="' + type + '" name="f" value="__other__" id="o">'
        + '<input type="text" class="fabricator-other-input" style="display:none">'
        + '</div></form>'
    );
    const init = page.window.FabricatorFieldInits[type === 'radio' ? 'radio' : 'checkbox'];
    init(page.document);
    const change = (el) => el.dispatchEvent(new page.window.Event('change', { bubbles: true }));
    return { doc: page.document, change };
}

test('radio: Other shows its text input, and choosing another option hides it again', () => {
    const { doc, change } = setUp('radio', 'fabricator-radio-group');
    const other = doc.querySelector('.fabricator-other-input');
    doc.getElementById('o').checked = true;
    change(doc.getElementById('o'));
    assert.equal(other.style.display, '');
    doc.getElementById('a').checked = true; // a radio change fires only on the newly checked one
    change(doc.getElementById('a'));
    assert.equal(other.style.display, 'none');
});

test('checkbox: its own script shows and hides the Other text input', () => {
    const { doc, change } = setUp('checkbox', 'fabricator-checkbox-group');
    const other = doc.querySelector('.fabricator-other-input');
    doc.getElementById('o').checked = true;
    change(doc.getElementById('o'));
    assert.equal(other.style.display, '');
    doc.getElementById('o').checked = false;
    change(doc.getElementById('o'));
    assert.equal(other.style.display, 'none');
});
