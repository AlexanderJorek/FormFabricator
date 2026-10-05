'use strict';

/*
 * The Direct Debit Mandate's browser rules decide as DirectDebitField::validate() does. build-fixture.php renders each
 * scheme's real markup and records the server's verdict on one typed account detail in an otherwise complete, signed
 * mandate (fixture.debit); here the same mandate is filled into the same inputs and the scheme's rules from
 * data-validate run on it.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture, loadPage, fragment } = require('./support/page');

const page = loadPage();
const i18n = page.window.FabricatorForms.i18n;

function mandate(html) {
    const field = fragment(page, html).querySelector('[data-validate]');
    assert.ok(field, 'the mandate declares its rules');
    return field;
}

function browserAccepts(field) {
    return JSON.parse(field.dataset.validate).every((rule) => page.window.FabricatorValidators[rule](field) === null);
}

/* The scheme's mandate, filled in completely and signed as build-fixture.php filled it for the server. */
function completeMandate(scheme) {
    const field = mandate(scheme.html);
    for (const [part, value] of Object.entries(scheme.complete)) {
        field.querySelector('[data-debit-part="' + part + '"]').value = value;
    }
    field.querySelector('.fabricator-debit-sig-data').value = scheme.sig;
    const iban = field.querySelector('.fabricator-debit-iban');
    if (iban) {
        iban._fabricatorIbanValid = true; // what the input handler sets for a complete, allowed IBAN
    }
    return field;
}

function scheme(name) {
    return fixture.debit.find((d) => d.scheme === name);
}

for (const debit of fixture.debit) {
    test('direct debit, ' + debit.scheme + ': the browser accepts exactly what the server accepts', () => {
        assert.equal(browserAccepts(completeMandate(debit)), true, 'the complete mandate itself');
        for (const [part, typed, server] of debit.cases) {
            const field = completeMandate(debit);
            const input = field.querySelector('[data-debit-part="' + part + '"]');
            assert.ok(input, debit.scheme + ' renders a ' + part + ' input');
            input.value = typed;
            assert.equal(browserAccepts(field), server, part + ' "' + typed + '"');
        }
    });
}

for (const debit of fixture.debit) {
    test('direct debit, ' + debit.scheme + ': the browser tells an untouched optional mandate from a started one as the server does', () => {
        for (const [part, typed, server] of debit.untouched) {
            const field = mandate(debit.html);
            field.querySelector('[data-debit-part="' + part + '"]').value = typed;
            assert.equal(browserAccepts(field), server, part + ' ' + JSON.stringify(typed));
        }
    });
}

test('direct debit: an optional mandate is given completely or not at all', () => {
    const untouched = mandate(scheme('bacs').html);
    assert.equal(browserAccepts(untouched), true);

    // As DirectDebitField::validate(): a holder alone, or a signature alone, asks for the rest.
    const holderOnly = mandate(scheme('bacs').html);
    holderOnly.querySelector('[data-debit-part="holder"]').value = 'Ada';
    assert.equal(browserAccepts(holderOnly), false);
    const signedOnly = mandate(scheme('bacs').html);
    signedOnly.querySelector('.fabricator-debit-sig-data').value = scheme('bacs').sig;
    assert.equal(browserAccepts(signedOnly), false);
    assert.equal(signedOnly.querySelector('[data-debit-part="sort_code"]').parentNode.querySelector('.fabricator-field-error').textContent,
        i18n.debit_sort_code_required);
});

test('direct debit, SEPA: an IBAN from outside SEPA is refused as the visitor types it', () => {
    const sepa = loadPage(scheme('sepa').html);
    sepa.window.FabricatorFieldInits.directdebit(sepa.document);
    const input = sepa.document.querySelector('.fabricator-debit-iban');
    const type = (iban) => {
        input.value = iban;
        input.dispatchEvent(new sepa.window.Event('input'));
        return input.parentNode.querySelector('.fabricator-field-error').textContent;
    };
    // Valid IBANs from the registry's examples, outside SEPA (DirectDebitField::SEPA_COUNTRIES).
    assert.equal(type('BR1800360305000010009795493C1'), i18n.debit_country_blocked);
    assert.equal(input._fabricatorIbanValid, false);
    assert.equal(type('FO6264600001631634'), i18n.debit_country_blocked);
    assert.equal(type('CH9300762011623852957'), '');
    assert.equal(input._fabricatorIbanValid, true);
});

test('direct debit: each scheme renders only its own account details', () => {
    const parts = (s) => Array.from(mandate(scheme(s).html).querySelectorAll('[data-debit-part]'))
        .map((el) => el.getAttribute('data-debit-part'));
    assert.deepEqual(parts('sepa'), ['iban', 'bic', 'holder']);
    assert.deepEqual(parts('bacs'), ['sort_code', 'account', 'holder']);
    assert.deepEqual(parts('ach'), ['routing', 'account', 'account_type', 'holder']);
});

test('direct debit: a required mandate names each missing detail at its own input', () => {
    const field = mandate(scheme('ach').html);
    field.dataset.required = 'true';
    field.querySelector('[data-debit-part="routing"]').value = '011000015';

    assert.equal(page.window.FabricatorValidators['debit-required'](field), '​');
    const message = (part) => field.querySelector('[data-debit-part="' + part + '"]').parentNode.querySelector('.fabricator-field-error').textContent;
    assert.equal(message('routing'), '', 'filled in');
    assert.equal(message('account'), i18n.debit_account_required);
    assert.equal(message('account_type'), i18n.debit_account_type_required);
    assert.equal(message('holder'), i18n.debit_holder_required);
    assert.equal(field.querySelector('.fabricator-debit-sig-error').textContent, i18n.debit_sig_required);
});

test('direct debit: the debtor details switched on are part of the mandate, in the browser as on the server', () => {
    const extras = fixture.debitExtras;
    const filled = () => {
        const field = completeMandate(extras);
        field.querySelector('.fabricator-debit-iban')._fabricatorIbanValid = true;
        return field;
    };
    assert.equal(browserAccepts(filled()), extras.complete_ok, 'the complete mandate');
    for (const [part, serverMessage] of extras.missing) {
        const field = filled();
        const input = field.querySelector('[data-debit-part="' + part + '"]');
        input.value = '';
        assert.equal(browserAccepts(field), serverMessage === null, part);
        assert.equal(input.parentNode.querySelector('.fabricator-field-error').textContent, serverMessage, part + ': the server\'s words');
    }
});

test('direct debit, SEPA: the IBAN typed is valid in the browser exactly when the server takes it (check digits 02-98)', () => {
    for (const [iban, serverOk] of fixture.debitExtras.iban) {
        const sepa = loadPage(scheme('sepa').html);
        sepa.window.FabricatorFieldInits.directdebit(sepa.document);
        const input = sepa.document.querySelector('.fabricator-debit-iban');
        input.value = iban;
        input.dispatchEvent(new sepa.window.Event('input'));
        assert.equal(input._fabricatorIbanValid, serverOk, iban);
    }
    assert.deepEqual(fixture.debitExtras.iban.map(([, ok]) => ok), [true, false, false, false], 'the server refuses 01, 00 and 99');
});

test('direct debit, SEPA: the BIC is marked required as soon as the IBAN typed needs one', () => {
    const sepa = loadPage(scheme('sepa').html);
    const field = sepa.document.querySelector('[data-validate]');
    field.dataset.required = 'true';
    // A required mandate renders the mark hidden; the fixture's optional one has none, so it is added as render() does.
    sepa.document.querySelector('label[for$="-bic"]').insertAdjacentHTML('beforeend',
        ' <span class="fabricator-required fabricator-debit-bic-mark" aria-hidden="true" style="display:none">*</span>');
    sepa.window.FabricatorFieldInits.directdebit(sepa.document);
    const iban = sepa.document.querySelector('.fabricator-debit-iban');
    const mark = sepa.document.querySelector('.fabricator-debit-bic-mark');
    const bic = sepa.document.querySelector('.fabricator-debit-bic');
    const typeIban = (value) => { iban.value = value; iban.dispatchEvent(new sepa.window.Event('input')); };

    assert.equal(mark.style.display, 'none');
    typeIban('CH93');
    assert.equal(mark.style.display, '', 'a Swiss IBAN needs a BIC');
    assert.equal(bic.getAttribute('aria-required'), 'true');
    typeIban('DE89');
    assert.equal(mark.style.display, 'none', 'a German one does not');
    assert.equal(bic.getAttribute('aria-required'), 'false');
});

test('direct debit, SEPA: the BIC is asked for only under an IBAN from outside the EEA, as the server asks', () => {
    for (const [iban, serverMessage] of fixture.debitExtras.bic) {
        const field = completeMandate(scheme('sepa'));
        field.dataset.required = 'true';
        field.querySelector('[data-debit-part="iban"]').value = iban;
        const bic = field.querySelector('[data-debit-part="bic"]');
        bic.value = '';
        assert.equal(browserAccepts(field), serverMessage === null, iban);
        assert.equal(bic.parentNode.querySelector('.fabricator-field-error').textContent, serverMessage ?? '', iban);
    }
});

test('direct debit: sort code, routing and account numbers take digits only as they are typed', () => {
    const bacs = loadPage(scheme('bacs').html);
    bacs.window.FabricatorFieldInits.directdebit(bacs.document);
    const type = (doc, part, value) => {
        const input = doc.querySelector('[data-debit-part="' + part + '"]');
        input.value = value;
        input.dispatchEvent(new doc.defaultView.Event('input'));
        return input.value;
    };
    assert.equal(type(bacs.document, 'sort_code', '123456'), '12-34-56');
    assert.equal(type(bacs.document, 'sort_code', '12 34 567'), '12-34-56', 'cut at six digits');
    assert.equal(type(bacs.document, 'account', '1234-5678-9'), '12345678', 'Bacs: eight digits');

    const ach = loadPage(scheme('ach').html);
    ach.window.FabricatorFieldInits.directdebit(ach.document);
    assert.equal(type(ach.document, 'routing', '0110-0001-5x'), '011000015');
    assert.equal(type(ach.document, 'account', '1'.repeat(20)), '1'.repeat(17), 'ACH: up to 17 digits');
    // jsdom has no canvas drawing (the signature pad's part, E2E territory); any other script error fails the test.
    assert.deepEqual([...bacs.errors, ...ach.errors].filter((e) => !e.includes('getContext()')), []);
});

test('direct debit, SEPA: the IBAN input masks as it is typed', () => {
    // TESTING.md §4: groups of four, capitals, nothing past the country's length, the placeholder following the country.
    const sepa = loadPage(scheme('sepa').html);
    sepa.window.FabricatorFieldInits.directdebit(sepa.document);
    const input = sepa.document.querySelector('.fabricator-debit-iban');
    const type = (typed) => {
        input.value = typed;
        input.dispatchEvent(new sepa.window.Event('input'));
        return input.value;
    };

    assert.equal(type('de'), 'DE');
    assert.equal(type('de8937'), 'DE89 37');
    assert.equal(type('de89-3704.0044 0532/0130 00'), 'DE89 3704 0044 0532 0130 00', 'separators typed or pasted are replaced');
    assert.equal(input._fabricatorIbanValid, true, 'complete and correct');
    assert.equal(type('DE89 3704 0044 0532 0130 0099'), 'DE89 3704 0044 0532 0130 00', 'cut at the 22 characters of a German IBAN');
    assert.equal(type('at61'), 'AT61');
    assert.equal(input.placeholder.replace(/\s/g, '').length, 20, 'an Austrian IBAN has 20 characters');
    assert.equal(type('DE89 3704 0044 0532 0130 01'), 'DE89 3704 0044 0532 0130 01');
    assert.equal(input._fabricatorIbanInvalid, true, 'a wrong check digit is flagged');
});

test('direct debit: sort code, routing and account numbers open the number keypad on a phone', () => {
    // TESTING.md §4: inputmode="numeric" is what makes a phone show the keypad; how it looks there stays manual.
    const numeric = { bacs: ['sort_code', 'account'], ach: ['routing', 'account'] };
    for (const [name, parts] of Object.entries(numeric)) {
        const field = mandate(scheme(name).html);
        for (const part of parts) {
            assert.equal(field.querySelector('[data-debit-part="' + part + '"]').getAttribute('inputmode'), 'numeric', name + ' ' + part);
        }
    }
});
