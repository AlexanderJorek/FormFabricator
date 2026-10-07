'use strict';

/*
 * The form builder (assets/js/admin-builder.js) on the page FormEditor::render() prints, with the palette and strings
 * the server hands it (fixture.admin). Driven through its own controls: clicking rows, typing into the settings panel,
 * the notification and submit-button dialogs, and Save, whose request carries the form as FormEditor::ajaxSave() reads
 * it. TESTING.md §2, §3 and §4 name what each test took over; dragging, layout and what it looks like stay in a browser.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBuilder, settle, wouldWarnOnLeave, saveAndRead, type } = require('./support/admin-page');

const TEXT = (id, extra = {}) => Object.assign({ id, type: 'text', label: id.toUpperCase() }, extra);
const NOTIFICATION = {
    slug: 'admin', name: 'Admin', enabled: true, recipient_mode: 'single',
    to: 'owner@example.org', subject: 'New entry', body: '<p>{all_fields}</p>', attach_pdf: false, attach_uploads: false,
};

function openField(page, idx) {
    page.document.querySelectorAll('#fabricator-field-list > .fabricator-field-row, #fabricator-field-list > .fabricator-group-row')[idx].click();
}

function panelLabels(page, tab) {
    return Array.from(page.document.getElementById('fabricator-stab-' + tab).querySelectorAll('.fabricator-sp-label'))
        .map((l) => l.textContent.trim());
}

function openNotification(page) {
    page.document.querySelector('[data-tab="fabricator-notifications-panel"]').click();
    page.document.querySelector('#fabricator-notif-list .fabricator-field-row').click();
}

test('a fresh builder does not warn on leaving', async () => {
    const page = await loadBuilder({ fields: [TEXT('name')], notifications: [NOTIFICATION] });
    assert.deepEqual(page.errors, []);
    assert.equal(wouldWarnOnLeave(page), false);
});

test('page break: its panel has no Conditions tab, and its Back and Next labels are saved', async () => {
    const page = await loadBuilder({ fields: [TEXT('a'), { id: 'pb', type: 'pagebreak', label: 'Page break' }, TEXT('b')] });

    openField(page, 1);

    const conditionsTab = page.document.querySelector('#fabricator-settings-modal [data-stab="conditions"]');
    assert.equal(conditionsTab.hidden, true, 'a page break cannot be shown conditionally');
    assert.equal(page.document.getElementById('fabricator-settings-modal').hidden, false, 'the panel opened');
    type(page, page.document.getElementById('fabricator-sp-prev_btn'), 'Zurück');
    type(page, page.document.getElementById('fabricator-sp-next_btn'), 'Weiter');
    const saved = await saveAndRead(page);
    assert.equal(saved.fields[1].prev_btn, 'Zurück');
    assert.equal(saved.fields[1].next_btn, 'Weiter');

    openField(page, 0);
    assert.equal(conditionsTab.hidden, false, 'other fields keep the tab');
});

test('a page break, a page header or another group cannot be added inside a group', async () => {
    const page = await loadBuilder({ fields: [{ id: 'g', type: 'group', label: 'Group', children: [] }] });
    const offered = () => Array.from(page.document.querySelectorAll('#fabricator-field-modal-body [data-type]')).map((c) => c.dataset.type);

    page.document.getElementById('fabricator-add-field-btn').click();
    const topLevel = offered();
    page.document.querySelector('#fabricator-field-modal .fabricator-modal-close').click();
    page.document.querySelector('.fabricator-group-add-child-btn').click();
    const inGroup = offered();

    assert.ok(topLevel.includes('pagebreak') && topLevel.includes('group'), 'offered at the top level');
    for (const type of ['pagebreak', 'page-header', 'group']) {
        assert.ok(!inGroup.includes(type), type + ' is not offered inside a group');
    }
    assert.ok(inGroup.includes('text'));
});

test('a group child: a setting that reveals others follows the child, not the group', async () => {
    // The group carries the opposite value under the same key, as the bug read it.
    for (const expanded of [true, false]) {
        const page = await loadBuilder({ fields: [{
            id: 'g', type: 'group', label: 'Group', expanded: !expanded,
            children: [{ id: 'addr', type: 'address', label: 'Address', expanded }],
        }] });
        page.document.querySelector('.fabricator-child-row').click();

        const text = page.document.getElementById('fabricator-stab-general').textContent;
        assert.equal(text.includes('Street and house number'), expanded, 'sub-field settings shown: ' + expanded);
    }
});

async function warnsAfterNotificationEdit(edit) {
    const page = await loadBuilder({ fields: [TEXT('name')], notifications: [NOTIFICATION] });
    openNotification(page);
    await settle(page);
    assert.equal(wouldWarnOnLeave(page), false, 'not before the edit');
    edit(page);
    return wouldWarnOnLeave(page);
}

test("the unsaved-changes warning follows a notification's recipients, subject and attachments", async () => {
    const edits = {
        recipients: (page) => type(page, page.document.getElementById('fabricator-sp-notif-cc'), 'cc@example.org'),
        subject: (page) => type(page, page.document.getElementById('fabricator-sp-notif-subject'), 'Changed'),
        attachments: (page) => page.document.getElementById('fabricator-sp-notif-pdf').click(),
    };
    for (const [what, edit] of Object.entries(edits)) {
        assert.equal(await warnsAfterNotificationEdit(edit), true, what + ': warns after the edit');
    }
});

test("the unsaved-changes warning follows a notification's body typed in the rich-text editor", async () => {
    // The body is an iframe, whose input events never reached the dialog's markDirty listener.
    const warns = await warnsAfterNotificationEdit((page) => {
        const doc = page.document.querySelector('#fabricator-notif-modal iframe').contentDocument;
        doc.body.innerHTML = '<p>New wording</p>';
        doc.dispatchEvent(new page.window.Event('input'));
    });
    assert.equal(warns, true);
});

test('the notification body is saved without the editor\'s own styling', async () => {
    // TESTING.md §4: the editor's display scaffolding leaked into the sent email.
    const page = await loadBuilder({ fields: [TEXT('name')], notifications: [NOTIFICATION] });
    openNotification(page);
    await settle(page);
    const doc = page.document.querySelector('#fabricator-notif-modal iframe').contentDocument;
    assert.ok(doc.getElementById('fabricator-editor-preview-style'), 'the editor styles its own view');

    doc.body.innerHTML = '<p>Hello <b>{name}</b></p>';
    doc.dispatchEvent(new page.window.Event('input'));
    const saved = await saveAndRead(page);

    const body = saved.notifications[0].body;
    assert.match(body, /Hello <b>\{name\}<\/b>/);
    assert.doesNotMatch(body, /fabricator-editor-preview-style|overflow-x|font-family:-apple-system/);
});

test('editing the submit button condition warns on leaving', async () => {
    const page = await loadBuilder({ fields: [TEXT('code')], notifications: [NOTIFICATION] });
    page.document.getElementById('fabricator-submit-preview-bar').querySelector('button, [class*="edit"], div').click();
    await settle(page);
    const modal = page.document.getElementById('fabricator-submit-modal');
    assert.equal(modal.hidden, false, 'the submit button dialog opened');
    assert.equal(wouldWarnOnLeave(page), false);

    const input = modal.querySelector('input[type="text"], input:not([type])');
    type(page, input, input.value + ' now');

    assert.equal(wouldWarnOnLeave(page), true);
});

test('renaming a field id carries its conditions, routing rules and submit condition along', async () => {
    const rule = (value) => ({ field_id: 'email', operator: 'equals', value });
    const page = await loadBuilder({
        fields: [
            { id: 'email', type: 'email', label: 'Email' },
            TEXT('shown', { conditions: { action: 'show', match: 'all', rules: [rule('x')] } }),
            { id: 'g', type: 'group', label: 'Group', children: [TEXT('inner', { conditions: { action: 'show', match: 'all', rules: [rule('y')] } })] },
        ],
        notifications: [Object.assign({}, NOTIFICATION, { recipient_mode: 'routing', routing_rules: [Object.assign(rule('z'), { email: 'z@example.org' })] })],
        settings: { submit_conditions: { match: 'all', rules: [rule('w')] } },
    });

    openField(page, 0);
    type(page, page.document.getElementById('fabricator-sp-field-id'), 'contact-email');
    const saved = await saveAndRead(page);

    assert.equal(saved.fields[0].id, 'contact-email');
    assert.equal(saved.fields[1].conditions.rules[0].field_id, 'contact-email');
    assert.equal(saved.fields[2].children[0].conditions.rules[0].field_id, 'contact-email');
    assert.equal(saved.notifications[0].routing_rules[0].field_id, 'contact-email');
    assert.equal(saved.settings.submit_conditions.rules[0].field_id, 'contact-email');
});

test('direct debit scheme pill: only the chosen scheme\'s settings, typed text kept across switches, country filter for SEPA only', async () => {
    const page = await loadBuilder({ fields: [{ id: 'dd', type: 'directdebit', label: 'Direct debit', scheme: 'sepa', mandate_text: '<p>SEPA wording</p>' }] });
    openField(page, 0);
    const pill = (label) => Array.from(page.document.querySelectorAll('#fabricator-stab-general .fabricator-seg-btn')).find((b) => b.textContent.startsWith(label));
    const general = () => page.document.getElementById('fabricator-stab-general').textContent;

    assert.ok(panelLabels(page, 'advanced').includes('IBAN label'));
    assert.ok(panelLabels(page, 'advanced').includes('Country filter'));

    pill('Bacs').click();
    assert.ok(!panelLabels(page, 'advanced').includes('IBAN label'), 'no SEPA settings for Bacs');
    assert.ok(panelLabels(page, 'advanced').some((l) => /sort code/i.test(l)), 'Bacs labels: ' + panelLabels(page, 'advanced').join(', '));
    assert.match(general(), /ships no wording for Bacs/, 'the notice that no wording ships');
    assert.ok(!panelLabels(page, 'advanced').includes('Country filter'), 'the country filter is SEPA-only');

    pill('ACH').click();
    assert.ok(panelLabels(page, 'advanced').some((l) => /routing/i.test(l)));
    pill('SEPA').click();

    const saved = await saveAndRead(page);
    assert.equal(saved.fields[0].scheme, 'sepa');
    assert.equal(saved.fields[0].mandate_text, '<p>SEPA wording</p>', 'the SEPA text survives the trip through the other schemes');
});

test('direct debit: General holds what the mandate says, every label is in Advanced and follows the switches', async () => {
    const page = await loadBuilder({ fields: [{ id: 'dd', type: 'directdebit', label: 'Direct debit', scheme: 'sepa' }] });
    openField(page, 0);
    const titles = (tab) => Array.from(page.document.getElementById('fabricator-stab-' + tab).querySelectorAll('.fabricator-sp-section-title'))
        .map((t) => t.textContent);

    assert.deepEqual(titles('general').filter((t) => ['Mandate', 'Creditor', 'Debtor details'].includes(t)), ['Mandate', 'Creditor', 'Debtor details']);
    assert.ok(!panelLabels(page, 'general').some((l) => /label|Signature hint|Clear button/.test(l)), 'no labels in General: ' + panelLabels(page, 'general').join(', '));
    assert.ok(titles('advanced').includes('Labels'));
    assert.ok(!panelLabels(page, 'advanced').includes('Street label'), 'hidden while the address is not asked for');

    const toggle = page.document.getElementById('fabricator-sp-debtor_address');
    toggle.checked = true;
    toggle.dispatchEvent(new page.window.Event('change'));
    assert.ok(panelLabels(page, 'advanced').includes('Street label'), 'shown once it is');

    const saved = await saveAndRead(page);
    assert.equal(saved.fields[0].debtor_address, true);
});

test('direct debit reference pill: own text such as "Your membership number", or a generated reference', async () => {
    // Generated references alone left no way to write a reference like "Your membership number".
    const page = await loadBuilder({ fields: [{ id: 'dd', type: 'directdebit', label: 'Direct debit', scheme: 'sepa' }] });
    openField(page, 0);
    const pill = (label) => Array.from(page.document.querySelectorAll('#fabricator-stab-general .fabricator-seg-btn')).find((b) => b.textContent === label);

    assert.ok(panelLabels(page, 'general').includes('Reference text'), 'own text by default');
    assert.ok(!panelLabels(page, 'general').includes('Mandate reference prefix'));
    type(page, page.document.getElementById('fabricator-sp-mandate_ref'), 'Your membership number');

    pill('Generated').click();
    assert.ok(panelLabels(page, 'general').includes('Mandate reference prefix'));
    assert.ok(!panelLabels(page, 'general').includes('Reference text'));
    pill('Own text').click();

    const saved = await saveAndRead(page);
    assert.equal(saved.fields[0].ref_mode, 'text');
    assert.equal(saved.fields[0].mandate_ref, 'Your membership number');
});

test('the save status shows the server\'s warning, e.g. a mandate without wording', async () => {
    const warning = 'A Direct Debit Mandate has no mandate text, so visitors cannot give it.';
    const page = await loadBuilder(
        { fields: [{ id: 'dd', type: 'directdebit', label: 'Direct debit', scheme: 'bacs', bacs_text: '' }] },
        () => ({ success: true, data: { form_id: 7, snapshot: 's2', warning } })
    );

    await saveAndRead(page);

    const status = page.document.getElementById('fabricator-save-status');
    assert.match(status.textContent, /no mandate text/);
    assert.equal(wouldWarnOnLeave(page), false, 'saved: nothing unsaved');

    // It stays until the user dismisses it.
    const dismiss = status.querySelector('.fabricator-ss-close');
    assert.equal(dismiss.getAttribute('aria-label'), 'Dismiss');
    dismiss.click();
    assert.equal(status.textContent, '');
    assert.equal(status.className, '');
});

test('a field placeholder typed as the sender address shows the warning while typing', async () => {
    const page = await loadBuilder({ fields: [TEXT('name')], notifications: [NOTIFICATION] });
    openNotification(page);
    const input = page.document.getElementById('fabricator-sp-notif-from_email');
    const warning = input.parentNode.querySelector('.fabricator-sp-notice--warning');

    for (const [value, shown] of [['{admin_email}', false], ['{email}', true], ['shop@example.org', false], ['{ email }', true], ['', false]]) {
        type(page, input, value);
        assert.equal(!warning.hidden, shown, JSON.stringify(value));
    }
});
