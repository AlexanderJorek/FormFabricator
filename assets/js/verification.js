/*!
 * FormFabricator — PDF Verification Page
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */

/* The particle background is drawn by admin-editor-canvas.js, as on every other admin page. */

/* ── Per-PDF inline progress cards ── */

function _fabricatorCreateProgressCard(name) {
    var card = document.createElement('div');
    card.className = 'fabricator-vpc';
    var i18n = (window.FabricatorVerifier && window.FabricatorVerifier.i18n) || {};
    card.innerHTML =
        '<div class="fabricator-vpc__header">' +
            '<span class="fabricator-vpc__icon"><span class="dashicons dashicons-pdf"></span></span>' +
            '<span class="fabricator-vpc__name"></span>' +
        '</div>' +
        '<div class="fabricator-vpc__step"></div>' +
        '<div class="fabricator-vpc__bar-wrap">' +
            '<div class="fabricator-vpc__bar" style="width:0%"></div>' +
        '</div>' +
        '<div class="fabricator-vpc__foot">' +
            '<span class="fabricator-vpc__pct">0 %</span>' +
            '<span class="fabricator-vpc__elapsed"></span>' +
        '</div>';
    /* Translated strings come from a .mo, which can carry markup: text node, never innerHTML. */
    card.querySelector('.fabricator-vpc__step').textContent = i18n.loading || 'Loading…';
    // textContent, independent of any upstream sanitizing.
    var nameEl = card.querySelector('.fabricator-vpc__name');
    if (nameEl) nameEl.textContent = name;
    return card;
}

/* The card of a file that could not be checked: the same markup as Verificationpage::problemCardHtml(), so a failure
   reads alike whether the server or this script met it. Text nodes only. */
function _fabricatorProblemCard(name, message) {
    var i18n = (window.FabricatorVerifier && window.FabricatorVerifier.i18n) || {};
    var card = document.createElement('div');
    card.className = 'fabricator-pdf-problem fabricator-pdf-problem--error';
    card.innerHTML =
        '<div class="fabricator-pdf-problem__hdr">' +
            '<span class="fabricator-pdf-problem__name"></span>' +
            '<span class="fabricator-pdf-problem__pill"></span>' +
        '</div>' +
        '<div class="fabricator-pdf-problem__body">' +
            '<span class="dashicons dashicons-warning"></span>' +
            '<span class="fabricator-pdf-problem__text"></span>' +
        '</div>';
    card.querySelector('.fabricator-pdf-problem__name').textContent = name;
    card.querySelector('.fabricator-pdf-problem__pill').textContent = i18n.error_pill || 'Error';
    card.querySelector('.fabricator-pdf-problem__text').textContent = message;
    return card;
}

/* Replaces a progress card with the problem card for the same file. */
function _fabricatorFailCard(card, name, message) {
    if (card.parentNode) {
        card.parentNode.replaceChild(_fabricatorProblemCard(name, message), card);
    }
}

/* Bar/pct is clamped to a high-water mark so it never visually moves backward; step text always updates. */
function _fabricatorUpdateCard(card, step, pct) {
    var s = card.querySelector('.fabricator-vpc__step');
    var b = card.querySelector('.fabricator-vpc__bar');
    var p = card.querySelector('.fabricator-vpc__pct');
    var shown = Math.max(pct, parseFloat(card.dataset.maxPct || '0'));
    card.dataset.maxPct = String(shown);
    if (s) s.textContent = step;
    if (b) b.style.width = Math.round(shown) + '%';
    if (p) p.textContent = Math.round(shown) + ' %';
}

/* ── Queue of PDFs to verify, localized once by Verificationpage.php's upload handler. ── */
window.FABRICATOR_VERIFICATION_QUEUE = window.FabricatorVerifierQueueData || [];

/* Staggers server calls to match the 1-per-5s rate limit; slots reserved by start time, not chained after responses. */
var _fabricatorNextPushSlotAt = 0; // epoch ms
var _fabricatorPushSlotGapMs  = 5200; // grows on an actual 429 — see _fabricatorWidenPushSlotGap() below

/* Caps concurrent verify requests client-side too — the server's own cap can't stop the client
   from optimistically showing "analyzing" the instant a request is sent, before it's accepted. */
var FABRICATOR_MAX_CONCURRENT_VERIFIES = 3;
var _fabricatorActiveVerifies = 0;
var _fabricatorVerifyQueue    = [];

function _fabricatorAcquireVerifySlot() {
    return new Promise(function (resolve) {
        function tryAcquire() {
            if (_fabricatorActiveVerifies < FABRICATOR_MAX_CONCURRENT_VERIFIES) {
                _fabricatorActiveVerifies++;
                resolve();
            } else {
                _fabricatorVerifyQueue.push(tryAcquire);
            }
        }
        tryAcquire();
    });
}

function _fabricatorReleaseVerifySlot() {
    _fabricatorActiveVerifies--;
    var next = _fabricatorVerifyQueue.shift();
    if (next) next();
}

/* Waits out waitMs, calling onTick(remainingMs) about once a second for a live countdown. */
function _fabricatorCountdown(waitMs, onTick) {
    if (waitMs <= 0) { return Promise.resolve(); }
    return new Promise(function (resolve) {
        var target = Date.now() + waitMs;
        onTick(waitMs);
        var iv = setInterval(function () {
            var remaining = target - Date.now();
            if (remaining <= 0) {
                clearInterval(iv);
                resolve();
                return;
            }
            onTick(remaining);
        }, 1000);
    });
}

function _fabricatorThrottledCheck(ajaxUrl, formData, onWaitTick, onRequestStart) {
    var now  = Date.now();
    var wait = Math.max(0, _fabricatorNextPushSlotAt - now);
    _fabricatorNextPushSlotAt = Math.max(_fabricatorNextPushSlotAt, now) + _fabricatorPushSlotGapMs;
    return (wait > 0 && onWaitTick ? _fabricatorCountdown(wait, onWaitTick) : new Promise(function (r) { setTimeout(r, wait); }))
        .then(function () {
            if (onRequestStart) { onRequestStart(); }
            return fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
        });
}

// Server still rate-limited us despite the gate (drift/jitter/queueing); grow the gap and push all waiting files out.
function _fabricatorWidenPushSlotGap() {
    _fabricatorPushSlotGapMs = Math.min(15000, _fabricatorPushSlotGapMs + 2000);
    _fabricatorNextPushSlotAt = Math.max(_fabricatorNextPushSlotAt, Date.now() + _fabricatorPushSlotGapMs);
}

/* The server deletes an uploaded copy 10 minutes after its last use, and a file waiting its turn in a large batch sends
   no request for a long time. While any file is being processed, a request every minute keeps all of this user's copies. */
var _fabricatorBusyFiles     = 0;
var _fabricatorKeepAliveTimer = null;
function _fabricatorKeepAliveStart() {
    if (_fabricatorBusyFiles++ > 0) { return; }
    _fabricatorKeepAliveTimer = setInterval(function () {
        var ajaxUrl = window.FabricatorVerifier && window.FabricatorVerifier.ajaxUrl;
        if (!ajaxUrl) { return; }
        var body = new FormData();
        body.append('action', 'fabricator_verify_progress');
        body.append('nonce',  (window.FabricatorVerifier && window.FabricatorVerifier.nonce) || '');
        fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).catch(function () {});
    }, 60000);
}
function _fabricatorKeepAliveStop() {
    if (--_fabricatorBusyFiles > 0) { return; }
    _fabricatorBusyFiles = 0;
    clearInterval(_fabricatorKeepAliveTimer);
    _fabricatorKeepAliveTimer = null;
}

/* Checks one stored upload: the server reads the file itself, so the page only asks for the check, by its token. */
window.FABRICATOR_VERIFICATION_PROCESS_PDF = async function processPdf(pdfInfo) {
    if (!pdfInfo || !pdfInfo.token) return;

    const pdfToken  = pdfInfo.token;
    const name      = pdfInfo.name || '';
    const ajaxUrl   = window.FabricatorVerifier && window.FabricatorVerifier.ajaxUrl;
    if (!ajaxUrl) return;

    const container = document.getElementById('fabricator-pdf-verification-results') || document.body;
    const card      = _fabricatorCreateProgressCard(name);
    container.appendChild(card);
    var _pollTimer  = null;
    function stopPoll() { if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; } }

    /* Elapsed timer */
    const t0 = Date.now();
    const elapsedEl = card.querySelector('.fabricator-vpc__elapsed');
    const elapsedTimer = setInterval(function () {
        if (elapsedEl) elapsedEl.textContent = ((Date.now() - t0) / 1000).toFixed(1) + ' s';
    }, 100);

    function done() { clearInterval(elapsedTimer); }

    // The server reports 5..94 while it checks; the bar starts at 2 % once the request is out and stops short of 100 %
    // until the answer has arrived.
    function remapServerPct(rawPct) {
        return 2 + Math.round((Math.max(0, Math.min(100, rawPct)) / 100) * 93);
    }

    var i18n = (window.FabricatorVerifier && window.FabricatorVerifier.i18n) || {};

    const formData = new FormData();
    formData.append('action',    'fabricator_verify_push_lines');
    formData.append('pdf_token', pdfToken);
    formData.append('nonce',     (window.FabricatorVerifier && window.FabricatorVerifier.nonce) || '');

    // Poll only once the request goes out (see onRequestStart); lastServerPct resets each (re)start since a 429 retry runs fresh server-side.
    var lastServerPct = 0;
    function startProgressPoll() {
        lastServerPct = 0;
        _pollTimer = setInterval(function () {
            var pf = new FormData();
            pf.append('action', 'fabricator_verify_progress');
            pf.append('token',  pdfToken);
            pf.append('nonce',  (window.FabricatorVerifier && window.FabricatorVerifier.nonce) || '');
            fetch(ajaxUrl, { method: 'POST', body: pf, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success && d.data && d.data.step && d.data.pct > lastServerPct) {
                        lastServerPct = d.data.pct;
                        _fabricatorUpdateCard(card, d.data.step, remapServerPct(d.data.pct));
                    }
                })
                .catch(function () {});
        }, 400);
    }

    _fabricatorKeepAliveStart();
    try {
        function onWaitTick(waitMs) {
            var waitMsg = (i18n.queued || 'Waiting in queue (%1$ds)…')
                .replace('%1$d', Math.ceil(waitMs / 1000));
            _fabricatorUpdateCard(card, waitMsg, 1);
            card.classList.add('fabricator-vpc--queued');
        }
        function onRequestStart() {
            card.classList.remove('fabricator-vpc--queued');
            _fabricatorUpdateCard(card, i18n.analyzing || 'Checking on the server…', 2);
            startProgressPoll();
        }

        var queuedForVerify = _fabricatorActiveVerifies >= FABRICATOR_MAX_CONCURRENT_VERIFIES;
        if (queuedForVerify) {
            _fabricatorUpdateCard(card, i18n.queued_for_verify || 'Waiting for a free verification slot…', 1);
            card.classList.add('fabricator-vpc--queued');
        }
        await _fabricatorAcquireVerifySlot();
        if (queuedForVerify) { card.classList.remove('fabricator-vpc--queued'); }

        // A 429 is retried, and _fabricatorWidenPushSlotGap() widens the gap for the rest of the batch.
        var res;
        try {
            var maxAttempts = 5;
            for (var attempt = 1; attempt <= maxAttempts; attempt++) {
                res = await _fabricatorThrottledCheck(ajaxUrl, formData, onWaitTick, onRequestStart);
                if (res.status !== 429 || attempt === maxAttempts) { break; }
                stopPoll();

                // A 429 with code:"busy" means the server-side concurrency cap, not the generic throttle.
                // code:"too_large" means the file needs more than the whole budget: no retry can succeed.
                var busyRetryAfter = null;
                var neverFits = false;
                try {
                    var busyBody = await res.clone().json();
                    if (busyBody && busyBody.data && busyBody.data.code === 'busy') {
                        busyRetryAfter = Number(busyBody.data.retry_after) || 8;
                    }
                    neverFits = !!(busyBody && busyBody.data && busyBody.data.code === 'too_large');
                } catch (_) { /* not JSON or already consumed — fall through to generic retry */ }
                if (neverFits) { break; }

                if (busyRetryAfter !== null) {
                    card.classList.add('fabricator-vpc--queued');
                    await _fabricatorCountdown(busyRetryAfter * 1000, function (remainingMs) {
                        var busyMsg = (i18n.server_busy_retry || 'Server busy — retrying in %1$ds…')
                            .replace('%1$d', Math.ceil(remainingMs / 1000));
                        _fabricatorUpdateCard(card, busyMsg, 1);
                    });
                } else {
                    _fabricatorWidenPushSlotGap();
                    _fabricatorUpdateCard(card, i18n.rate_limited_retry || 'Rate limited — retrying…', 1);
                    card.classList.add('fabricator-vpc--queued');
                }
            }
        } finally {
            // Rendering the result below is pure client-side work — no need to hold the slot for it.
            _fabricatorReleaseVerifySlot();
        }
        stopPoll();
        _fabricatorUpdateCard(card, i18n.processing || 'Processing response…', 98);
        const rawText = await res.text();

        let json = null;
        try {
            json = JSON.parse(rawText);
        } catch (_) {
            console.error('[FormFabricator] Non-JSON response (HTTP ' + res.status + ') for', name, '\n', rawText);
            done();
            _fabricatorFailCard(card, name, (i18n.server_error || 'Server error (HTTP %d)').replace('%d', res.status));
            return;
        }

        _fabricatorUpdateCard(card, i18n.done || 'Done', 100);
        done();

        if (json.success === true && json.data && typeof json.data.html === 'string') {
            // Escaped server-side (Verificationpage.php).
            const tmp = document.createElement('div');
            tmp.innerHTML = json.data.html;
            card.parentNode.replaceChild(tmp.firstElementChild || tmp, card);
        } else {
            console.error('[FormFabricator] Server returned error:', json);
            var msg = (json && json.data && json.data.message) || (i18n.unknown_error || 'Unknown server error');
            _fabricatorFailCard(card, name, msg);
        }
    } catch (err) {
        stopPoll();
        console.error('[FormFabricator] Fetch error for', name, err);
        done();
        _fabricatorFailCard(card, name, i18n.network_error || 'Network error');
    } finally {
        _fabricatorKeepAliveStop();
    }
};

/* Process any PDFs queued before this script loaded */
if (window.FABRICATOR_VERIFICATION_QUEUE.length) {
    window.FABRICATOR_VERIFICATION_QUEUE.forEach(function (item) {
        Promise.resolve(window.FABRICATOR_VERIFICATION_PROCESS_PDF(item)).catch(function (err) {
            console.error('[FormFabricator] Unhandled error processing queued PDF', item, err);
        });
    });
    window.FABRICATOR_VERIFICATION_QUEUE = [];
}
