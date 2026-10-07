'use strict';

/*
 * The verifier page's upload overlay (assets/js/admin-verification.js) on the page Verificationpage::render() prints
 * before any upload, TESTING.md §5: while the PDFs upload, it covers the content area beside the admin menu, from the
 * area's top edge (or the window's, once scrolled past it) to the bottom of the window, above everything on the page.
 * jsdom has no layout, so #wpcontent's box is set by the test.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture } = require('./support/page');
const { adminWindow, script } = require('./support/admin-page');

test('upload overlay: covers the content area beside the menu, follows scrolling, and sits above the page', () => {
    const page = adminWindow('<div id="adminmenuwrap"></div><div id="wpcontent">' + fixture.admin.verifier + '</div>');
    const w = page.window;
    const doc = page.document;
    let box = { top: 32, left: 160, width: 1100, height: 500 };
    doc.getElementById('wpcontent').getBoundingClientRect = () => Object.assign({ right: box.left + box.width, bottom: box.top + box.height }, box);
    w.eval(script('admin-verification.js'));

    const overlay = doc.getElementById('fabricator-pdf-upload-overlay');
    const at = () => [overlay.style.top, overlay.style.left, overlay.style.width, overlay.style.height];
    assert.deepEqual(at(), ['32px', '160px', '1100px', (w.innerHeight - 32) + 'px'], 'below the admin bar, beside the menu, to the window bottom');
    assert.equal(overlay.classList.contains('fabricator-pdf-open'), false, 'hidden until an upload starts');

    // Scrolled past the content area's top edge: from the top of the window.
    box = { top: -400, left: 160, width: 1100, height: 2000 };
    w.dispatchEvent(new w.Event('scroll'));
    assert.deepEqual(at(), ['0px', '160px', '1100px', w.innerHeight + 'px']);

    doc.getElementById('pdf-upload-form').dispatchEvent(new w.Event('submit', { cancelable: true }));
    assert.equal(overlay.classList.contains('fabricator-pdf-open'), true, 'shown while the PDFs upload');
    // Not inside .fabricator-verification-wrap, a stacking context of its own, or the page's content would cover it.
    assert.equal(overlay.parentNode, doc.body);
    assert.equal(overlay.closest('.fabricator-verification-wrap'), null);
    assert.equal(doc.getElementById('fabricator-pdf-idle-state').style.display, 'none');
    assert.deepEqual(page.errors, []);
});
