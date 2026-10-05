'use strict';

/*
 * The particle background of the admin pages (assets/js/admin-editor-canvas.js), run on a page with the CSS variables
 * Assets::addAdminCssVars() writes. jsdom draws nothing, so the canvas's 2D context is a recorder: what matters is
 * whether the script draws, how often, and whether it follows the mouse.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const SCRIPT = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'admin-editor-canvas.js'), 'utf8');

/**
 * Loads the script on a page with the given setting and reduced-motion answer, and returns the recorded drawing.
 */
function run({ particles = 'on', reduceMotion = false } = {}) {
    const dom = new JSDOM(
        '<!doctype html><html><head><style>:root{--fabricator-admin-accent:#2271b1;--fabricator-particles:' + particles + '}</style></head>'
            + '<body><canvas id="fabricator-particle-canvas"></canvas></body></html>',
        { runScripts: 'outside-only', pretendToBeVisual: true }
    );
    const w = dom.window;
    const calls = { frames: 0, mouseLines: 0, contexts: 0 };
    // Every particle starts at 30 % of the page, near the mouse below, so a line to it is certain, not likely.
    w.Math.random = () => 0.3;
    w.HTMLCanvasElement.prototype.getContext = function () {
        calls.contexts++;
        const noop = () => {};
        return {
            clearRect: () => { calls.frames++; },
            beginPath: noop, moveTo: noop, stroke: noop, arc: noop, fill: noop,
            lineTo: (x, y) => { if (x === 300 && y === 200) calls.mouseLines++; },
        };
    };
    const listeners = [];
    w.matchMedia = (query) => ({
        matches: query.includes('prefers-reduced-motion') && reduceMotion,
        addEventListener: (type, fn) => listeners.push(fn),
    });
    w.eval(SCRIPT);
    return { w, calls, listeners };
}

const ticks = (w, ms) => new Promise((resolve) => w.setTimeout(resolve, ms));

test('particles on: the background moves, frame after frame, and follows the mouse', async () => {
    const { w, calls } = run();
    w.document.dispatchEvent(new w.MouseEvent('mousemove', { clientX: 300, clientY: 200 }));
    await ticks(w, 300);
    assert.ok(calls.frames > 3, 'frames drawn: ' + calls.frames);
    assert.ok(calls.mouseLines > 0, 'lines to the mouse');
    w.close();
});

test('particles on, reduced motion asked for: one still picture, redrawn on resize, never following the mouse', async () => {
    const { w, calls } = run({ reduceMotion: true });
    w.document.dispatchEvent(new w.MouseEvent('mousemove', { clientX: 300, clientY: 200 }));
    await ticks(w, 300);
    assert.equal(calls.frames, 1, 'drawn once');
    assert.equal(calls.mouseLines, 0);
    assert.notEqual(w.document.getElementById('fabricator-particle-canvas').style.display, 'none', 'still shown');

    w.dispatchEvent(new w.Event('resize'));
    await ticks(w, 300);
    assert.equal(calls.frames, 2, 'drawn again for the new size, and still');
    w.close();
});

test('particles on: a change of the system setting while the page is open is followed', async () => {
    const { w, calls, listeners } = run({ reduceMotion: true });
    await ticks(w, 100);
    assert.equal(calls.frames, 1);
    listeners.forEach((fn) => fn({ matches: false }));
    await ticks(w, 300);
    assert.ok(calls.frames > 3, 'moving again: ' + calls.frames);
    w.close();
});

test('particles off: nothing is drawn, and the canvas stays as the page background', async () => {
    const { w, calls } = run({ particles: 'off' });
    await ticks(w, 300);
    assert.equal(calls.contexts, 0);
    assert.equal(calls.frames, 0);
    assert.notEqual(w.document.getElementById('fabricator-particle-canvas').style.display, 'none');
    w.close();
});
