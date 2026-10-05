'use strict';

/*
 * The signature pad's typed-name alternative (SignatureField.js), on the markup SignatureField::render() and
 * DirectDebitField::render() print: anyone who cannot draw with a mouse, finger or pen signs by typing their name
 * (WCAG 2.1.1). The name goes into the same hidden input a drawing does, and the server takes it as the server says
 * (fixture.signature, fixture.debitExtras).
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture, loadPage } = require('./support/page');

/* jsdom draws nothing: the pad's 2D context is a stand-in that accepts the calls clearing it makes. */
function padPage(html) {
    const page = loadPage(html);
    page.window.HTMLCanvasElement.prototype.getContext = () => ({
        fillRect() {}, scale() {}, beginPath() {}, moveTo() {}, lineTo() {}, stroke() {}, drawImage() {},
    });
    page.window.FabricatorFieldInits.signature(page.document);
    return page;
}

function typeName(page, wrap, name) {
    const input = wrap.querySelector('.fabricator-signature-typed');
    input.value = name;
    input.dispatchEvent(new page.window.Event('input'));
}

test('signature: the visitor can type their name instead of drawing, and back again', () => {
    const page = padPage(fixture.signature.html);
    const wrap = page.document.querySelector('.fabricator-signature-wrap');
    const mode = wrap.querySelector('.fabricator-signature-mode');
    const hidden = wrap.querySelector('input[type="hidden"]');
    const field = page.document.querySelector('[data-field-id="sg"]');
    const empty = page.window.FabricatorEmptyChecks.signature;

    assert.equal(mode.tagName, 'BUTTON', 'a button, reachable with the keyboard');
    assert.equal(wrap.querySelector('.fabricator-signature-typed-row').hidden, true, 'drawing first');
    assert.equal(empty(field), true);

    mode.click();
    assert.equal(wrap.querySelector('.fabricator-signature-canvas').hidden, true);
    assert.equal(wrap.querySelector('.fabricator-signature-typed-row').hidden, false);
    assert.equal(mode.getAttribute('aria-pressed'), 'true');
    assert.equal(mode.textContent, mode.dataset.drawLabel);
    assert.equal(page.document.activeElement, wrap.querySelector('.fabricator-signature-typed'), 'the name input has the focus');

    typeName(page, wrap, '  Ada Lovelace  ');
    assert.equal(hidden.value, 'Ada Lovelace', 'trimmed as the server trims');
    assert.equal(empty(field), false, 'a required signature is given');
    assert.equal(fixture.signature.typed_ok, true, 'and the server takes it');

    typeName(page, wrap, '   ');
    assert.equal(empty(field), true, 'spaces are no signature, as on the server');
    assert.equal(fixture.signature.spaces_ok, false);

    typeName(page, wrap, 'Ada Lovelace');
    mode.click();
    assert.equal(hidden.value, '', 'back to drawing: the typed name is dropped');
    assert.equal(wrap.querySelector('.fabricator-signature-canvas').hidden, false);
    assert.equal(mode.textContent, mode.dataset.typeLabel);
    assert.deepEqual(page.errors, []);
});

test('direct debit: a mandate signed by typing the name is complete, in the browser as on the server', () => {
    const extras = fixture.debitExtras;
    const page = padPage(extras.html);
    const field = page.document.querySelector('[data-validate]');
    for (const [part, value] of Object.entries(extras.complete)) {
        field.querySelector('[data-debit-part="' + part + '"]').value = value;
    }
    field.querySelector('.fabricator-debit-iban')._fabricatorIbanValid = true;
    const wrap = field.querySelector('.fabricator-signature-wrap');
    wrap.querySelector('.fabricator-signature-mode').click();
    typeName(page, wrap, 'Ada Lovelace');

    assert.equal(field.querySelector('.fabricator-debit-sig-data').value, 'Ada Lovelace');
    const accepts = JSON.parse(field.dataset.validate).every((rule) => page.window.FabricatorValidators[rule](field) === null);
    assert.equal(accepts, extras.typed_sig_ok);
    assert.equal(extras.typed_sig_ok, true);
});
