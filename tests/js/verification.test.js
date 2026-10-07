'use strict';

/*
 * The verifier page's batch handling (assets/js/verification.js), TESTING.md §5: several PDFs checked at once keep to
 * the client's limits (three checks in flight, spaced out), and a server that answers "busy" or rate-limits a check is
 * waited out and asked again, with the countdown on the file's card. The page only asks for each check by its token;
 * the server reads the file itself (VerifierPageTest).
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { adminWindow, script } = require('./support/admin-page');

/** A Response-like object for the verifier's fetch() calls; `raw`, when given, is the body instead of `json`. */
function response(page, status, json, raw) {
    const text = raw === undefined ? JSON.stringify(json) : raw;
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
 * The verifier page with a push-slot gap of `gapMs` and a server: `check(n)` answers the n-th check request
 * ([status, json] or [status, null, raw body], or throws for a network failure); every check takes `delayMs`.
 * Tracks how many checks run at once, and every request the page sends.
 */
function loadVerifier({ check, gapMs = 20, delayMs = 25 }) {
    const page = adminWindow('<div id="fabricator-pdf-verification-results"></div>');
    const w = page.window;
    const stats = { checks: 0, maxChecks: 0, checkStarts: [], checkCount: 0, actions: [], checkBodies: [] };
    w.FabricatorVerifier = { ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'n', i18n: {} };
    w.FabricatorVerifierQueueData = [];
    w.fetch = (url, opts) => {
        const body = opts.body;
        const action = typeof body.get === 'function' ? body.get('action') : '';
        stats.actions.push(action);
        const delayed = (kind, result) => {
            stats[kind]++;
            stats['max' + kind[0].toUpperCase() + kind.slice(1)] = Math.max(stats['max' + kind[0].toUpperCase() + kind.slice(1)], stats[kind]);
            return new Promise((resolve, reject) => w.setTimeout(() => {
                stats[kind]--;
                try {
                    resolve(result());
                } catch (e) {
                    reject(e);
                }
            }, delayMs));
        };
        if (action === 'fabricator_verify_push_lines') {
            stats.checkStarts.push(Date.now());
            stats.checkBodies.push(Object.fromEntries(body.entries()));
            const n = ++stats.checkCount;
            return delayed('checks', () => {
                const [status, json, raw] = check(n, body.get('pdf_token'));
                return response(page, status, json, raw);
            });
        }
        return Promise.resolve(response(page, 200, { success: true, data: { step: '', pct: 0 } }));
    };
    w.eval(script('verification.js'));
    w.eval('_fabricatorPushSlotGapMs = ' + gapMs + ';');
    const verify = (i) => w.FABRICATOR_VERIFICATION_PROCESS_PDF({ token: 't' + i, name: 'order-' + i + '.pdf' });
    return { page, stats, verify };
}

const result = (token) => [200, { success: true, data: { html: '<div class="fabricator-pdf-result" data-token="' + token + '">checked</div>' } }];

test('five PDFs at once: at most three checks in flight, every file reported', async () => {
    const { page, stats, verify } = loadVerifier({ check: (n, token) => result(token), gapMs: 0, delayMs: 60 });

    await Promise.all([1, 2, 3, 4, 5].map(verify));

    assert.deepEqual(page.errors, []);
    assert.ok(stats.maxChecks <= 3, 'checks at once: ' + stats.maxChecks);
    assert.equal(stats.maxChecks, 3, 'and they do run side by side');
    assert.equal(stats.checkCount, 5);
    const shown = Array.from(page.document.querySelectorAll('.fabricator-pdf-result')).map((r) => r.dataset.token).sort();
    assert.deepEqual(shown, ['t1', 't2', 't3', 't4', 't5']);
    assert.equal(page.document.querySelectorAll('.fabricator-vpc').length, 0, 'every progress card was replaced by its result');
});

test('a check is asked for by its token alone: no download, no text from the browser', async () => {
    const { stats, verify } = loadVerifier({ check: (n, token) => result(token) });

    await verify(1);

    assert.deepEqual(stats.checkBodies, [{ action: 'fabricator_verify_push_lines', pdf_token: 't1', nonce: 'n' }]);
    assert.ok(stats.actions.every((a) => a === 'fabricator_verify_push_lines' || a === 'fabricator_verify_progress'), stats.actions.join(', '));
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

/** The one problem card on the page, read as the server's problemCardHtml() writes it. */
function problemCard(page) {
    const cards = page.document.querySelectorAll('.fabricator-pdf-problem');
    assert.equal(cards.length, 1, 'one problem card');
    assert.equal(page.document.querySelectorAll('.fabricator-vpc').length, 0, 'it replaced the progress card');
    const card = cards[0];
    return {
        error: card.classList.contains('fabricator-pdf-problem--error'),
        name: card.querySelector('.fabricator-pdf-problem__name').textContent,
        pill: card.querySelector('.fabricator-pdf-problem__pill').textContent,
        text: card.querySelector('.fabricator-pdf-problem__text').textContent,
    };
}

test('a refusal becomes the problem card, with the file name and the server wording', async () => {
    const { page, verify } = loadVerifier({
        check: () => [400, { success: false, data: { message: 'File is not a valid PDF' } }],
    });

    await verify(1);

    assert.deepEqual(problemCard(page), { error: true, name: 'order-1.pdf', pill: 'Error', text: 'File is not a valid PDF' });
});

test('an answer that is no JSON becomes the problem card with the HTTP status', async () => {
    const { page, verify } = loadVerifier({ check: () => [500, null, '<p>Fatal error</p>'] });

    await verify(1);

    assert.deepEqual(problemCard(page), { error: true, name: 'order-1.pdf', pill: 'Error', text: 'Server error (HTTP 500)' });
});

test('a network failure becomes the problem card', async () => {
    const { page, verify } = loadVerifier({ check: () => { throw new TypeError('Failed to fetch'); } });

    await verify(1);

    assert.deepEqual(problemCard(page), { error: true, name: 'order-1.pdf', pill: 'Error', text: 'Network error' });
});

test('a file too large for the memory budget is not asked again', async () => {
    const message = "This PDF needs about 900 MB to verify, more than this site's 512 MB budget.";
    const { page, stats, verify } = loadVerifier({
        check: () => [429, { success: false, data: { message, code: 'too_large', retry_after: 0 } }],
    });

    await verify(1);

    assert.equal(stats.checkCount, 1);
    assert.equal(problemCard(page).text, message);
});

test('markup in a file name or message stays text', async () => {
    const { page } = loadVerifier({ check: () => [400, { success: false, data: { message: '<img src=x onerror=alert(1)>' } }] });

    await page.window.FABRICATOR_VERIFICATION_PROCESS_PDF({ token: 't1', name: '<b>x</b>.pdf' });

    assert.equal(page.document.querySelectorAll('.fabricator-pdf-problem img, .fabricator-pdf-problem b').length, 0);
    assert.deepEqual(problemCard(page), { error: true, name: '<b>x</b>.pdf', pill: 'Error', text: '<img src=x onerror=alert(1)>' });
});
