'use strict';

/*
 * Client and server must agree on every condition. If front.js shows a field the server thinks is hidden, the server
 * drops what the visitor typed; the other way round, it demands a field the visitor never saw.
 *
 * tests/js/build-fixture.php records FormProcessor::evalConditionRule()'s answer for each value × rule value ×
 * operator; this runs the same cases through the real front.js initConditions() and compares. Case folding across
 * scripts, whitespace and the number shape are where the two sides have disagreed before.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture, loadPage } = require('./support/page');

const page = loadPage();
const { document } = page;
const { initConditions } = page.hooks;

/**
 * One form per submitted value: the control(s), then one target per case, each conditioned on a single rule.
 * Returns front.js's verdict (target visible) for each case, in order.
 */
function frontJsVerdicts(submitted, cases) {
    const form = document.createElement('form');
    form.className = 'fabricator-form';
    if (Array.isArray(submitted)) {
        // Ticked checkboxes named as CheckboxField names them; one unticked box stands for "nothing ticked".
        const values = submitted.length ? submitted : ['unticked'];
        for (const value of values) {
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.name = 'f[]';
            box.value = value;
            box.checked = submitted.length > 0;
            form.appendChild(box);
        }
    } else {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'f';
        input.value = submitted;
        form.appendChild(input);
    }
    const targets = cases.map(([, ruleValue, op]) => {
        const div = document.createElement('div');
        div.dataset.conditions = JSON.stringify({ action: 'show', match: 'all', rules: [{ field_id: 'f', operator: op, value: ruleValue }] });
        form.appendChild(div);
        return div;
    });
    const wrap = document.createElement('div');
    wrap.appendChild(form);
    document.body.appendChild(wrap);
    try {
        initConditions(wrap);
        return targets.map((div) => div.style.display !== 'none');
    } finally {
        wrap.remove();
    }
}

function compare(kind) {
    const cases = fixture.parity[kind];
    assert.ok(cases.length > 0, 'the fixture holds ' + kind + ' cases');
    const bySubmitted = new Map();
    for (const c of cases) {
        const key = JSON.stringify(c[0]);
        if (!bySubmitted.has(key)) {
            bySubmitted.set(key, []);
        }
        bySubmitted.get(key).push(c);
    }
    const disagreements = [];
    for (const group of bySubmitted.values()) {
        const verdicts = frontJsVerdicts(group[0][0], group);
        group.forEach(([submitted, ruleValue, op, server], i) => {
            if (verdicts[i] !== server) {
                disagreements.push(op + ' ' + JSON.stringify(submitted) + ' vs ' + JSON.stringify(ruleValue)
                    + ': server ' + server + ', front.js ' + verdicts[i]);
            }
        });
    }
    assert.deepEqual(disagreements, [], disagreements.length + ' of ' + cases.length + ' cases disagree');
}

test('text values: front.js decides every case as the server does', () => {
    compare('scalar');
});

test('checkbox lists: front.js decides every case as the server does', () => {
    compare('array');
});

/*
 * Chained conditions: a rule that tests a field which is itself hidden. front.js reads a hidden field as empty, so the
 * server must too, or a field the visitor saw and filled in is dropped (or one they never saw is demanded).
 * build-fixture.php records FormProcessor::resolveVisibility()'s hidden ids for each case's posted values.
 */
function frontJsHidden(cascade) {
    const wrap = document.createElement('div');
    wrap.innerHTML = '<form class="fabricator-form">' + cascade.html + '</form>';
    const form = wrap.querySelector('form');
    for (const [id, value] of Object.entries(cascade.values)) {
        const inputs = form.querySelectorAll('[name="' + id + '"], [name="' + id + '[]"]');
        assert.ok(inputs.length > 0, cascade.name + ': the markup has an input for ' + id);
        for (const input of inputs) {
            if (input.type === 'checkbox') {
                input.checked = Array.isArray(value) ? value.includes(input.value) : value !== '';
            } else {
                input.value = value;
            }
        }
    }
    document.body.appendChild(wrap);
    try {
        initConditions(wrap);
        const hidden = [];
        for (const id of Object.keys(cascade.values)) {
            let el = form.querySelector('[name="' + id + '"], [name="' + id + '[]"]');
            while (el && el !== form && el.style.display !== 'none') {
                el = el.parentElement;
            }
            if (el && el !== form) {
                hidden.push(id);
            }
        }
        return hidden;
    } finally {
        wrap.remove();
    }
}

for (const cascade of fixture.cascades) {
    test('chained conditions, ' + cascade.name + ': front.js hides the fields the server hides', () => {
        const server = cascade.hidden.filter((id) => id in cascade.values).sort();
        assert.deepEqual(frontJsHidden(cascade).sort(), server);
    });
}

test('chained conditions: the reported case keeps the field the visitor filled in', () => {
    const cascade = fixture.cascades.find((c) => c.name === 'shown because the field it tests is hidden');
    assert.deepEqual(cascade.hidden, ['a'], 'the server hides only the gated field, so b reaches the email');
});

/*
 * Rules on fields with no input named after them: an "equals" rule on the server's reading (build-fixture.php) must
 * match in front.js, and "empty" must agree.
 */
function frontJsSeesCompositeAs(composite, operator, value) {
    const wrap = document.createElement('div');
    wrap.innerHTML = '<form class="fabricator-form">' + composite.html + '</form>';
    const form = wrap.querySelector('form');
    for (const [name, value2] of Object.entries(composite.dom)) {
        if (name === 'g-recaptcha-response') {
            // reCAPTCHA writes its token into a textarea inside the widget, within the field.
            const token = document.createElement('textarea');
            token.name = name;
            token.value = value2;
            form.querySelector('[data-field-id="' + composite.id + '"]').appendChild(token);
            continue;
        }
        const el = form.querySelector('[name="' + name + '"]');
        assert.ok(el, composite.name + ': the markup has an input named ' + name);
        if (el.type === 'file') {
            Object.defineProperty(el, 'files', { value: Array.from({ length: value2 }, (_, i) => ({ name: 'f' + i })) });
        } else if (el.type === 'checkbox') {
            el.checked = Boolean(value2);
        } else {
            el.value = value2;
        }
    }
    const target = document.createElement('div');
    target.dataset.conditions = JSON.stringify({ action: 'show', match: 'all', rules: [{ field_id: composite.id, operator, value }] });
    form.appendChild(target);
    document.body.appendChild(wrap);
    try {
        initConditions(wrap);
        return target.style.display !== 'none';
    } finally {
        wrap.remove();
    }
}

for (const composite of fixture.composites) {
    test('rule on a composite field, ' + composite.name + ': front.js decides as the server does', () => {
        for (const [op, ruleValue, server] of composite.decisions) {
            assert.equal(frontJsSeesCompositeAs(composite, op, ruleValue), server, op + ' "' + ruleValue + '"');
        }
    });
}

/*
 * front.js compares a value the way the server receives it: after WordPress's sanitize_text_field() (text inputs) or
 * sanitize_textarea_field() (textareas). build-fixture.php records the real functions' results (tests/js/wp-sanitize.php);
 * an "equals" rule on that result must match what the visitor typed.
 */
function frontJsMatches(tag, typed, expected) {
    const wrap = document.createElement('div');
    wrap.innerHTML = '<form class="fabricator-form">' + (tag === 'textarea' ? '<textarea name="t"></textarea>' : '<input type="text" name="t">')
        + '<div id="target"></div></form>';
    wrap.querySelector('[name="t"]').value = typed;
    wrap.querySelector('#target').dataset.conditions = JSON.stringify({ action: 'show', match: 'all', rules: [{ field_id: 't', operator: 'equals', value: expected }] });
    document.body.appendChild(wrap);
    try {
        initConditions(wrap);
        return wrap.querySelector('#target').style.display !== 'none';
    } finally {
        wrap.remove();
    }
}

test('front.js reads typed text as sanitize_text_field() / sanitize_textarea_field() leave it', () => {
    const disagreements = [];
    for (const [typed, asText, asTextarea] of fixture.sanitize) {
        // A text input cannot hold a line break (the browser strips it before anything reads or posts the value).
        if (!/[\r\n]/.test(typed) && !frontJsMatches('input', typed, asText)) {
            disagreements.push('input ' + JSON.stringify(typed) + ' should read as ' + JSON.stringify(asText));
        }
        if (!frontJsMatches('textarea', typed, asTextarea)) {
            disagreements.push('textarea ' + JSON.stringify(typed) + ' should read as ' + JSON.stringify(asTextarea));
        }
    }
    assert.deepEqual(disagreements, []);
});
