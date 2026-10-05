'use strict';

/* front.js initConditions(): operators, show/hide, any/all, multi-value controls and hidden ancestors. */

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadPage } = require('./support/page');

const page = loadPage();
const { document } = page;
const initConditions = page.hooks.initConditions;

/**
 * Builds a form with the given controls and one conditioned target, lets initConditions() run, applies $act to the
 * controls, fires a change event, and reports whether the target is visible.
 */
function visibleAfter(controlsHtml, conditions, act) {
    const wrap = document.createElement('div');
    wrap.innerHTML = '<form class="fabricator-form">' + controlsHtml
        + '<div class="fabricator-field" id="target"><span>target</span></div></form>';
    wrap.querySelector('#target').dataset.conditions = JSON.stringify(conditions);
    document.body.appendChild(wrap);
    try {
        initConditions(wrap);
        const changed = act(wrap);
        changed.dispatchEvent(new page.window.Event('change', { bubbles: true }));
        return wrap.querySelector('#target').style.display !== 'none';
    } finally {
        wrap.remove();
    }
}

function textRule(value, operator, ruleValue, action = 'show') {
    return visibleAfter(
        '<input name="ctrl" type="text">',
        { action, match: 'all', rules: [{ field_id: 'ctrl', operator, value: ruleValue }] },
        (wrap) => {
            const ctrl = wrap.querySelector('[name="ctrl"]');
            ctrl.value = value;
            return ctrl;
        }
    );
}

const OPERATOR_CASES = [
    ['equals', 'hello', 'hello', true], ['equals', 'world', 'hello', false],
    ['not_equals', 'world', 'hello', true], ['not_equals', 'hello', 'hello', false],
    ['contains', 'hello world', 'hello', true], ['contains', 'goodbye', 'hello', false],
    ['not_contains', 'goodbye', 'hello', true], ['not_contains', 'hello world', 'hello', false],
    ['empty', '', '', true], ['empty', 'text', '', false],
    ['not_empty', 'text', '', true], ['not_empty', '', '', false],
    ['greater', '10', '5', true], ['greater', '3', '5', false],
    ['less', '3', '5', true], ['less', '10', '5', false],
];

for (const [op, value, ruleValue, visible] of OPERATOR_CASES) {
    test(op + ': "' + value + '" vs "' + ruleValue + '" → ' + (visible ? 'shown' : 'hidden'), () => {
        assert.equal(textRule(value, op, ruleValue), visible);
    });
}

test('action=hide hides the target while the rule matches', () => {
    assert.equal(textRule('hello', 'equals', 'hello', 'hide'), false);
});

test('match=any needs one rule, match=all needs every rule', () => {
    const rules = [{ field_id: 'ctrl', operator: 'equals', value: 'nomatch' }, { field_id: 'ctrl', operator: 'equals', value: 'hello' }];
    const setHello = (wrap) => {
        const ctrl = wrap.querySelector('[name="ctrl"]');
        ctrl.value = 'hello';
        return ctrl;
    };
    assert.equal(visibleAfter('<input name="ctrl" type="text">', { action: 'show', match: 'any', rules }, setHello), true);
    assert.equal(visibleAfter('<input name="ctrl" type="text">', { action: 'show', match: 'all', rules }, setHello), false);
});

test('a checkbox group is a list: equals and contains test each ticked box, none ticked is empty', () => {
    const boxes = (a, b) => '<input name="ctrl" type="checkbox" value="' + a + '"><input name="ctrl" type="checkbox" value="' + b + '">';
    const tickSecond = (wrap) => {
        const box = wrap.querySelectorAll('[name="ctrl"]')[1];
        box.checked = true;
        return box;
    };
    const rule = (operator, value) => ({ action: 'show', match: 'all', rules: [{ field_id: 'ctrl', operator, value }] });
    assert.equal(visibleAfter(boxes('a', 'b'), rule('equals', 'b'), tickSecond), true);
    assert.equal(visibleAfter(boxes('a', 'ab'), rule('contains', 'b'), tickSecond), true);
    assert.equal(visibleAfter(boxes('a', 'b'), rule('empty', ''), (wrap) => wrap.querySelector('[name="ctrl"]')), true);
});

test('CheckboxField names its boxes "id[]", and the condition still finds them', () => {
    const html = '<input name="ctrl[]" type="checkbox" value="a"><input name="ctrl[]" type="checkbox" value="b">';
    const tick = (wrap) => {
        const box = wrap.querySelectorAll('[name="ctrl[]"]')[1];
        box.checked = true;
        return box;
    };
    assert.equal(visibleAfter(html, { action: 'show', match: 'all', rules: [{ field_id: 'ctrl', operator: 'equals', value: 'b' }] }, tick), true);
});

test('a multiple select is a list of the selected options', () => {
    const html = '<select name="ctrl" multiple><option value="x">X</option><option value="y">Y</option></select>';
    const pick = (wrap) => {
        const sel = wrap.querySelector('[name="ctrl"]');
        sel.options[1].selected = true;
        return sel;
    };
    assert.equal(visibleAfter(html, { action: 'show', match: 'all', rules: [{ field_id: 'ctrl', operator: 'equals', value: 'y' }] }, pick), true);
});

test('an input inside a hidden conditional ancestor counts as empty', () => {
    const inner = JSON.stringify({ action: 'show', match: 'all', rules: [{ field_id: 'gate', operator: 'equals', value: 'impossible' }] });
    const html = '<input name="gate" type="text" value="">'
        + '<div id="inner" data-conditions=\'' + inner + '\'><input name="ctrl" type="text" value="hello"></div>';
    const visible = visibleAfter(
        html,
        { action: 'show', match: 'all', rules: [{ field_id: 'ctrl', operator: 'not_empty', value: '' }] },
        (wrap) => {
            assert.equal(wrap.querySelector('#inner').style.display, 'none', 'setup: the inner wrapper is hidden by its own rule');
            return wrap.querySelector('[name="gate"]');
        }
    );
    assert.equal(visible, false);
});
