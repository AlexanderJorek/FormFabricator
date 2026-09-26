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
