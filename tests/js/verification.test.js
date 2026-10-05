'use strict';

/*
 * The verifier page's batch handling (assets/js/verification.js), TESTING.md §5: several PDFs checked at once keep to
 * the client's limits (three downloads and three checks in flight, checks spaced out), and a server that answers
 * "busy" or rate-limits a check is waited out and asked again, with the countdown on the file's card. pdf.js is a
 * stand-in returning one line of text; its real parsing is the browser's. The server's side is VerifierPageTest's.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { adminWindow, script } = require('./support/admin-page');

/* pdf.js as the page imports it (FabricatorVerifier.pdfJsModule): one page holding one line of text. */
const FAKE_PDFJS = 'data:text/javascript,' + encodeURIComponent(
    'export const GlobalWorkerOptions = {};'
    + 'export function getDocument() {'
    + '  const doc = { numPages: 1, destroy() {}, getPage: async () => ({ getTextContent: async () => ('
    + '    { items: [{ str: "Ada Lovelace", transform: [1, 0, 0, 1, 10, 700] }] }) }) };'
    + '  return { promise: Promise.resolve(doc), destroy() {} };'
    + '}'
);

/** A Response-like object for the verifier's fetch() calls. */
function response(page, status, json) {
    const text = JSON.stringify(json);
    const self = {
        ok: status >= 200 && status < 300,
        status,
        headers: { get: () => null },
        body: null,
        arrayBuffer: () => Promise.resolve(new Uint8Array([37, 80, 68, 70]).buffer),
        json: () => Promise.resolve(JSON.parse(text)),
        text: () => Promise.resolve(text),
        clone: () => self,
    };
    return self;
}

/**
 * The verifier page with the stand-in pdf.js, a push-slot gap of `gapMs`, and a server: `check(n)` answers the n-th
 * check request ([status, json]); every request takes `delayMs`. Tracks how many downloads and checks run at once.
 */
function loadVerifier({ check, gapMs = 20, delayMs = 25 }) {
    const page = adminWindow('<div id="fabricator-pdf-verification-results"></div>');
    const w = page.window;
    const stats = { downloads: 0, maxDownloads: 0, checks: 0, maxChecks: 0, checkStarts: [], checkCount: 0 };
    w.FabricatorVerifier = {
        ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'n', i18n: {},
        pdfJsModule: FAKE_PDFJS, pdfJsWorker: 'pdf.worker.js',
    };
    w.FabricatorVerifierQueueData = [];
    w.fetch = (url, opts) => {
        const body = opts.body;
        const action = typeof body.get === 'function' ? body.get('action') : '';
        const delayed = (kind, result) => {
            stats[kind]++;
            stats['max' + kind[0].toUpperCase() + kind.slice(1)] = Math.max(stats['max' + kind[0].toUpperCase() + kind.slice(1)], stats[kind]);
            return new Promise((resolve) => w.setTimeout(() => {
                stats[kind]--;
                resolve(result());
            }, delayMs));
        };
        if (action === 'fabricator_serve_pdf') {
            return delayed('downloads', () => response(page, 200, {}));
        }
        if (action === 'fabricator_verify_push_lines') {
            stats.checkStarts.push(Date.now());
            const n = ++stats.checkCount;
            return delayed('checks', () => {
                const [status, json] = check(n, body.get('pdf_token'));
                return response(page, status, json);
            });
        }
        return Promise.resolve(response(page, 200, { success: true, data: { step: '', pct: 0 } }));
    };
    w.eval(script('verification.js'));
    w.eval('_fabricatorPushSlotGapMs = ' + gapMs + ';');
    const verify = (i) => w.FABRICATOR_VERIFICATION_PROCESS_PDF({ url: w.FabricatorVerifier.ajaxUrl, token: 't' + i, nonce: 'n', name: 'order-' + i + '.pdf' });
    return { page, stats, verify };
}

const result = (token) => [200, { success: true, data: { html: '<div class="fabricator-pdf-result" data-token="' + token + '">checked</div>' } }];

test('five PDFs at once: at most three downloads and three checks in flight, every file reported', async () => {
    const { page, stats, verify } = loadVerifier({ check: (n, token) => result(token), gapMs: 0, delayMs: 60 });

    await Promise.all([1, 2, 3, 4, 5].map(verify));

    assert.deepEqual(page.errors, []);
    assert.ok(stats.maxDownloads <= 3, 'downloads at once: ' + stats.maxDownloads);
    assert.ok(stats.maxChecks <= 3, 'checks at once: ' + stats.maxChecks);
    assert.equal(stats.maxDownloads, 3, 'and they do run side by side');
    assert.equal(stats.checkCount, 5);
    const shown = Array.from(page.document.querySelectorAll('.fabricator-pdf-result')).map((r) => r.dataset.token).sort();
    assert.deepEqual(shown, ['t1', 't2', 't3', 't4', 't5']);
    assert.equal(page.document.querySelectorAll('.fabricator-vpc').length, 0, 'every progress card was replaced by its result');
});

test('checks of a batch start one push slot apart, matching the server rate limit', async () => {
    // The real slot is 5.2 s; 1.1 s keeps the test short and is still longer than the one-second countdown tick.
    const { stats, verify } = loadVerifier({ check: (n, token) => result(token), gapMs: 1100, delayMs: 10 });

    await Promise.all([1, 2, 3].map(verify));

    const gaps = stats.checkStarts.slice(1).map((t, i) => t - stats.checkStarts[i]);
    assert.ok(gaps.every((g) => g >= 1000), 'gaps between checks: ' + gaps.join(', ') + ' ms');
});

test('"Server busy": the card counts down, the check is sent again, and the result replaces the card', async () => {
    const { page, stats, verify } = loadVerifier({
        check: (n, token) => (n === 1
            ? [429, { success: false, data: { message: 'Server busy verifying other PDFs right now.', code: 'busy', retry_after: 1 } }]
            : result(token)),
    });
    const seen = [];
    const observer = new page.window.MutationObserver(() => {
        const step = page.document.querySelector('.fabricator-vpc__step');
        if (step) seen.push(step.textContent);
    });
    observer.observe(page.document.body, { subtree: true, childList: true, characterData: true });

    await verify(1);
    observer.disconnect();

    assert.equal(stats.checkCount, 2, 'asked again');
    assert.ok(seen.some((s) => /^Server busy — retrying in 1s…$/.test(s)), 'the countdown was shown: ' + seen.join(' | '));
    assert.equal(page.document.querySelector('.fabricator-pdf-result').dataset.token, 't1');
});

test('rate limited without "busy": the gap between checks widens and the check is sent again', async () => {
    const { page, stats, verify } = loadVerifier({
        check: (n, token) => (n === 1 ? [429, { success: false, data: { message: 'Please wait before verifying another PDF.' } }] : result(token)),
    });
    const gapBefore = page.window.eval('_fabricatorPushSlotGapMs');

    await verify(1);

    assert.equal(stats.checkCount, 2);
    assert.equal(page.window.eval('_fabricatorPushSlotGapMs'), gapBefore + 2000, 'later checks wait longer');
    assert.equal(page.document.querySelector('.fabricator-pdf-result').dataset.token, 't1');
});

test('a refusal is shown on the card as the server worded it', async () => {
    const { page, verify } = loadVerifier({
        check: () => [400, { success: false, data: { message: 'order-1.pdf has far more parts than any document this plugin creates and was not read.' } }],
    });

    await verify(1);

    const card = page.document.querySelector('.fabricator-vpc');
    assert.ok(card.classList.contains('fabricator-vpc--error'));
    assert.equal(card.querySelector('.fabricator-vpc__step').textContent, 'Error: order-1.pdf has far more parts than any document this plugin creates and was not read.');
});
