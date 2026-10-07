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

/* jsdom draws nothing: the pad's 2D context is a stand-in that accepts the calls clearing it makes, and the picture a
   finished stroke hands over is a fixed PNG data URL. */
function padPage(html, init = 'signature') {
    const page = loadPage(html);
    page.window.HTMLCanvasElement.prototype.getContext = () => ({
        fillRect() {}, scale() {}, beginPath() {}, moveTo() {}, lineTo() {}, stroke() {}, drawImage() {},
    });
    page.window.HTMLCanvasElement.prototype.toDataURL = () => 'data:image/png;base64,iVBORw0KGgo=';
    page.window.FabricatorFieldInits[init](page.document);
    return page;
}

/* A pointer on the pad: a mouse button, or a finger, with the coordinates the pad reads from the event. */
function press(page, canvas, kind, moves) {
    const at = { clientX: 20, clientY: 30 };
    const fire = (target, type) => {
        const e = new page.window.Event(type, { bubbles: true, cancelable: true });
        Object.assign(e, kind === 'touch' ? { touches: [at] } : at);
        target.dispatchEvent(e);
    };
    fire(canvas, kind === 'touch' ? 'touchstart' : 'mousedown');
    for (let i = 0; i < moves; i++) {
        at.clientX += 5;
        fire(canvas, kind === 'touch' ? 'touchmove' : 'mousemove');
    }
    fire(kind === 'touch' ? canvas : page.document, kind === 'touch' ? 'touchend' : 'mouseup');
}

/* A tap is no signature, with a mouse or a finger; a stroke is. */
function assertTapIsNoSignature(page, wrap) {
    const canvas = wrap.querySelector('.fabricator-signature-canvas');
    const hidden = wrap.querySelector('input[type="hidden"]');
    for (const kind of ['mouse', 'touch']) {
        press(page, canvas, kind, 0);
        assert.equal(hidden.value, '', kind + ': a single tap leaves the signature empty');
        press(page, canvas, kind, 3);
        assert.match(hidden.value, /^data:image\/png;base64,/, kind + ': a stroke is the signature');
        wrap.querySelector('.fabricator-signature-clear').click();
        assert.equal(hidden.value, '');
    }
}

test('signature: a single tap on the pad, without drawing, is no signature', () => {
    const page = padPage(fixture.signature.html);
    const field = page.document.querySelector('[data-field-id="sg"]');
    assertTapIsNoSignature(page, page.document.querySelector('.fabricator-signature-wrap'));
    page.document.querySelector('.fabricator-signature-canvas').dispatchEvent(new page.window.Event('mousedown', { cancelable: true }));
    page.document.dispatchEvent(new page.window.Event('mouseup'));
    assert.equal(page.window.FabricatorEmptyChecks.signature(field), true, 'a required signature is still missing');
    assert.deepEqual(page.errors, []);
});

test('direct debit: a single tap on the mandate pad is no signature, whichever field script set the pad up', () => {
    // Both scripts take the mandate pad (the first to run marks it as set up), so both must refuse a tap.
    for (const init of ['directdebit', 'signature']) {
        const page = padPage(fixture.debitExtras.html, init);
        assertTapIsNoSignature(page, page.document.querySelector('.fabricator-debit-mandate .fabricator-signature-wrap'));
        assert.deepEqual(page.errors, [], init);
    }
});

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
