'use strict';

/*
 * What every FormFabricator screen carries, on the markup each one prints (fixture.admin), TESTING.md §1 and §2:
 * exactly one notice dock, since WordPress's common.js moves the page's notices to the first .wp-header-end and copies
 * them to every other one, and the canvas the particle background is drawn on. Where the docked notices then show is
 * the stylesheet's, and left to a person.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture } = require('./support/page');
const { adminWindow } = require('./support/admin-page');

const screens = {
    'form list': fixture.admin.formList,
    'form editor': fixture.admin.editor,
    'form selections': fixture.admin.formSelect,
    'settings': fixture.admin.settings,
    'PDF layout': fixture.admin.pdfLayout,
    'PDF verification': fixture.admin.verifier,
};

for (const [name, html] of Object.entries(screens)) {
    test(name + ': one notice dock, and the particle background\'s canvas', () => {
        const doc = adminWindow(html).document;
        const ends = doc.querySelectorAll('.wp-header-end');
        assert.equal(ends.length, 1, 'exactly one .wp-header-end');
        assert.ok(ends[0].closest('.fabricator-notice-dock'), 'inside the notice dock');
        assert.equal(doc.querySelectorAll('#fabricator-particle-canvas').length, 1);
    });
}
