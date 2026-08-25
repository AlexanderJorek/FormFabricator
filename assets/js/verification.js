/*!
 * FormFabricator — PDF Verification Page
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */

/* pdf.js 6.x ships ES modules only, so this loads as a <script type="module"> and imports pdf.mjs directly. */
import * as pdfjsLib from '../../vendor/pdfjs/pdf.mjs';

/* Strips <script>, on*="" handlers and javascript:/vbscript: URIs from a
   server-rendered HTML fragment before it's assigned to innerHTML. Defense
   in depth — the fragment is already escaped/kses'd server-side in
   Verificationpage.php. */
function _forgeSanitizeFragment(html) {
    html = String(html || '').replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
    html = html.replace(/[\s\/]+on[a-z]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '');
    html = html.replace(/\b(href|src)\s*=\s*(["'])\s*(?:javascript|vbscript)\s*:[^"']*\2/gi, '$1=$2#$2');
    return html;
}

/* ── Particle canvas on the PHP-rendered canvas element ── */
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('forge-particle-canvas');
    if (!canvas) { return; }

    var ctx   = canvas.getContext('2d');
    var mouse = { x: -9999, y: -9999 };
    var _ah   = getComputedStyle(document.documentElement).getPropertyValue('--forge-admin-accent').trim() || '#2271b1';
    var _rgb  = function (h) { return parseInt(h.slice(1,3),16)+','+parseInt(h.slice(3,5),16)+','+parseInt(h.slice(5,7),16); };
    var COLOR = _rgb(_ah);
    var LINK  = 150, SPEED = 1.0;
    var particles = [], paused = false, FRAME_MS = 1000 / 30;

    function resize() {
        canvas.width  = canvas.offsetWidth  || window.innerWidth;
        canvas.height = canvas.offsetHeight || window.innerHeight;
        var DOTS = Math.min(120, Math.max(40, Math.round(canvas.width * canvas.height / 26000)));
        particles = [];
        for (var i = 0; i < DOTS; i++) {
            particles.push({
                x: Math.random() * canvas.width,  y: Math.random() * canvas.height,
                vx: (Math.random() - 0.5) * SPEED * 2, vy: (Math.random() - 0.5) * SPEED * 2,
                r: 2 + Math.random() * 1.5
            });
        }
    }

    function draw() {
        if (paused) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        for (var i = 0; i < particles.length; i++) {
            var p = particles[i];
            p.x += p.vx; p.y += p.vy;
            if (p.x < 0 || p.x > canvas.width)  { p.vx *= -1; }
            if (p.y < 0 || p.y > canvas.height) { p.vy *= -1; }
        }
        ctx.lineWidth = 1;
        for (var i = 0; i < particles.length; i++) {
            for (var j = i + 1; j < particles.length; j++) {
                var dx = particles[i].x - particles[j].x, dy = particles[i].y - particles[j].y;
                var d  = Math.sqrt(dx * dx + dy * dy);
                if (d < LINK) {
                    ctx.beginPath();
                    ctx.moveTo(particles[i].x, particles[i].y);
                    ctx.lineTo(particles[j].x, particles[j].y);
                    ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - d / LINK) * 0.3 + ')';
                    ctx.stroke();
                }
            }
            var mdx = particles[i].x - mouse.x, mdy = particles[i].y - mouse.y;
            var md  = Math.sqrt(mdx * mdx + mdy * mdy);
            if (md < LINK) {
                ctx.beginPath();
                ctx.moveTo(particles[i].x, particles[i].y);
                ctx.lineTo(mouse.x, mouse.y);
                ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - md / LINK) * 0.55 + ')';
                ctx.stroke();
            }
        }
        ctx.fillStyle = 'rgba(' + COLOR + ', 0.5)';
        for (var i = 0; i < particles.length; i++) {
            ctx.beginPath();
            ctx.arc(particles[i].x, particles[i].y, particles[i].r, 0, Math.PI * 2);
            ctx.fill();
        }
        setTimeout(function () { requestAnimationFrame(draw); }, FRAME_MS - 2);
    }

    canvas.addEventListener('mousemove', function (e) {
        var rect = canvas.getBoundingClientRect();
        mouse.x = e.clientX - rect.left;
        mouse.y = e.clientY - rect.top;
    });
    document.addEventListener('visibilitychange', function () {
        paused = document.hidden;
        if (!paused) requestAnimationFrame(draw);
    });
    window.addEventListener('resize', resize);

    resize();
    requestAnimationFrame(draw);
});

const Y_THRESHOLD = 3;

/* Set PDF.js worker — local file only, no CDN. window.ForgeVerifier is populated by a classic
   inline script (wp_localize_script has no module equivalent); safe to read here since module
   top-level code always runs after classic scripts. */
const workerSrc = window.ForgeVerifier && window.ForgeVerifier.pdfJsWorker;
if (!workerSrc) {
    console.error('[FormFabricator] pdfJsWorker is not set. PDF.js worker may be missing.');
} else {
    pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;
}

/* ── Per-PDF inline progress cards ── */

function _forgeCardPdfName(url) {
    try { return decodeURIComponent(url.split('/').pop().split('?')[0]); } catch (_) { return url; }
}

function _forgeCreateProgressCard(name) {
    var card = document.createElement('div');
    card.className = 'forge-vpc';
    var i18n = (window.ForgeVerifier && window.ForgeVerifier.i18n) || {};
    card.innerHTML =
        '<div class="forge-vpc__header">' +
            '<span class="forge-vpc__icon"><span class="dashicons dashicons-pdf"></span></span>' +
            '<span class="forge-vpc__name"></span>' +
        '</div>' +
        '<div class="forge-vpc__step">' + (i18n.loading || 'Loading…') + '</div>' +
        '<div class="forge-vpc__bar-wrap">' +
            '<div class="forge-vpc__bar" style="width:0%"></div>' +
        '</div>' +
        '<div class="forge-vpc__foot">' +
            '<span class="forge-vpc__pct">0 %</span>' +
            '<span class="forge-vpc__elapsed"></span>' +
        '</div>';
    // Set via textContent rather than interpolating into the innerHTML string
    // above — doesn't depend on Verificationpage.php's sanitize_file_name()
    // upstream remaining the only source of this value forever.
    var nameEl = card.querySelector('.forge-vpc__name');
    if (nameEl) nameEl.textContent = name;
    return card;
}

/* The bar/pct is a safety-netted high-water mark, not a direct mirror of
   whatever pct a caller passes: several independent progress sources feed
   this one card (client-side PDF.js extraction, the server's own 0-100
   verification-step scale, retry/queue states) and a caller passing a lower
   number than what's already shown — even a legitimate one from a different
   phase — must never visually move the bar backward. The step *text* always
   updates; only the bar/percentage is clamped. */
function _forgeUpdateCard(card, step, pct) {
    var s = card.querySelector('.forge-vpc__step');
    var b = card.querySelector('.forge-vpc__bar');
    var p = card.querySelector('.forge-vpc__pct');
    var shown = Math.max(pct, parseFloat(card.dataset.maxPct || '0'));
    card.dataset.maxPct = String(shown);
    if (s) s.textContent = step;
    if (b) b.style.width = Math.round(shown) + '%';
    if (p) p.textContent = Math.round(shown) + ' %';
}

/* ── Queue of PDFs to verify, localized once by Verificationpage.php's upload handler
   (ForgeVerifierQueueData) rather than pushed via per-file inline <script> tags. ── */
window.FORGE_VERIFICATION_QUEUE = window.ForgeVerifierQueueData || [];

/* Server-side rate-limits forge_verify_push_lines to 1 call per 5 seconds per
   user (see Admin/Verificationpage.php). Batch-scanning several PDFs kicks
   off processPdf() for each one back-to-back, with no natural stagger
   between their resulting server calls, so without this gate most of a
   batch used to get silently 429-rejected. Only the server call is
   throttled — concurrent client-side PDF.js extraction is unaffected.
   Slots are reserved by *start* time, computed synchronously when requested
   — not chained after the previous response arrives, which (given slow
   requests) would compound into "request duration + 5s" per file instead
   of a flat 5s. */
var _forgeNextPushSlotAt = 0; // epoch ms
var _forgePushSlotGapMs  = 5200; // grows on an actual 429 — see forceNextSlotLater() below

/* Caps concurrent forge_serve_pdf downloads (pdf.js issues these internally, so there's no
   response to reject-and-retry the way the verify call has — gated here instead). */
var FORGE_MAX_CONCURRENT_LOADS = 3;
var _forgeActiveLoads = 0;
var _forgeLoadQueue   = [];

function _forgeAcquireLoadSlot() {
    return new Promise(function (resolve) {
        function tryAcquire() {
            if (_forgeActiveLoads < FORGE_MAX_CONCURRENT_LOADS) {
                _forgeActiveLoads++;
                resolve();
            } else {
                _forgeLoadQueue.push(tryAcquire);
            }
        }
        tryAcquire();
    });
}

function _forgeReleaseLoadSlot() {
    _forgeActiveLoads--;
    var next = _forgeLoadQueue.shift();
    if (next) next();
}

/* Caps concurrent verify requests client-side too — the server's own cap can't stop the client
   from optimistically showing "analyzing" the instant a request is sent, before it's accepted. */
var FORGE_MAX_CONCURRENT_VERIFIES = 3;
var _forgeActiveVerifies = 0;
var _forgeVerifyQueue    = [];

function _forgeAcquireVerifySlot() {
    return new Promise(function (resolve) {
        function tryAcquire() {
            if (_forgeActiveVerifies < FORGE_MAX_CONCURRENT_VERIFIES) {
                _forgeActiveVerifies++;
                resolve();
            } else {
                _forgeVerifyQueue.push(tryAcquire);
            }
        }
        tryAcquire();
    });
}

function _forgeReleaseVerifySlot() {
    _forgeActiveVerifies--;
    var next = _forgeVerifyQueue.shift();
    if (next) next();
}

/* Waits out $waitMs, invoking onTick(remainingMs) roughly once a second so the
   UI can show a live countdown instead of a number that's stale the instant
   it's shown. */
function _forgeCountdown(waitMs, onTick) {
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

function _forgeThrottledPushLines(ajaxUrl, formData, onWaitTick, onRequestStart) {
    var now  = Date.now();
    var wait = Math.max(0, _forgeNextPushSlotAt - now);
    _forgeNextPushSlotAt = Math.max(_forgeNextPushSlotAt, now) + _forgePushSlotGapMs;
    return (wait > 0 && onWaitTick ? _forgeCountdown(wait, onWaitTick) : new Promise(function (r) { setTimeout(r, wait); }))
        .then(function () {
            if (onRequestStart) { onRequestStart(); }
            return fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
        });
}

/* Called when the server rejects a call as rate-limited despite the gate above
   (clock drift, network jitter, or a queued PHP worker under load can all
   delay arrival past the intended slot). Only grows when there's evidence
   the current gap wasn't enough, and pushes every waiting file out too. */
function _forgeWidenPushSlotGap() {
    _forgePushSlotGapMs = Math.min(15000, _forgePushSlotGapMs + 2000);
    _forgeNextPushSlotAt = Math.max(_forgeNextPushSlotAt, Date.now() + _forgePushSlotGapMs);
}

window.FORGE_VERIFICATION_PROCESS_PDF = async function processPdf(pdfInfo) {
    if (!pdfInfo) return;

    const pdfUrl   = typeof pdfInfo === 'string' ? pdfInfo : pdfInfo.url;
    const pdfToken = typeof pdfInfo === 'string' ? null    : (pdfInfo.token || null);
    const pdfName  = typeof pdfInfo === 'string' ? _forgeCardPdfName(pdfInfo) : (pdfInfo.name || _forgeCardPdfName(pdfInfo.url));
    if (!pdfUrl) return;

    const container = document.getElementById('forge-pdf-verification-results') || document.body;
    const name      = pdfName;
    const card      = _forgeCreateProgressCard(name);
    container.appendChild(card);
    var _pollTimer  = null;
    function stopPoll() { if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; } }

    /* Elapsed timer */
    const t0 = Date.now();
    const elapsedEl = card.querySelector('.forge-vpc__elapsed');
    const elapsedTimer = setInterval(function () {
        if (elapsedEl) elapsedEl.textContent = ((Date.now() - t0) / 1000).toFixed(1) + ' s';
    }, 100);

    function done() { clearInterval(elapsedTimer); }

    /* Overall bar is carved into non-overlapping bands per phase, in the order
       they actually occur, so the number only ever climbs:
         0-2   pdf_loading (start)
         2-12  downloading (real transferred-byte progress via pdf.js's onProgress)
         12-40 page-by-page text extraction (client-side, PDF.js)
         40    queued / rate-limited-retry (before the request has gone out)
         42    request sent, awaiting server ("text extracted — analyzing")
         42-95 server's own verification-step progress, remapped via
               remapServerPct — its raw 5..94 scale would otherwise replay
               from near-zero and look like the bar jumped backward
         98    processing the final response
         100   done / error (terminal) */
    function remapServerPct(rawPct) {
        return 42 + Math.round((Math.max(0, Math.min(100, rawPct)) / 100) * 53);
    }

    var i18n = (window.ForgeVerifier && window.ForgeVerifier.i18n) || {};
    // Destroyed in the finally block below — pdf.js keeps decoded pages/worker state alive until .destroy().
    let pdf;
    let loadingTask;
    try {
        var queuedForLoad = _forgeActiveLoads >= FORGE_MAX_CONCURRENT_LOADS;
        if (queuedForLoad) {
            _forgeUpdateCard(card, i18n.queued_for_download || 'Waiting to download…', 1);
            card.classList.add('forge-vpc--queued');
        }
        await _forgeAcquireLoadSlot();
        if (queuedForLoad) { card.classList.remove('forge-vpc--queued'); }
        _forgeUpdateCard(card, i18n.pdf_loading || 'Loading PDF…', 2);
        try {
            // pdf.js 6.x removed eval()/Function() usage entirely, so CVE-2024-4367's isEvalSupported:false
            // workaround no longer applies (that option no longer exists).
            loadingTask = pdfjsLib.getDocument({
                url: pdfUrl,
                withCredentials: true
            });
            // Real download-progress bytes, into their own 2-12% sub-band.
            loadingTask.onProgress = function (progressData) {
                if (!progressData || !progressData.total) { return; }
                var frac = Math.min(1, progressData.loaded / progressData.total);
                var downloadMsg = (i18n.downloading || 'Downloading… (%1$d%%)').replace('%1$d', Math.round(frac * 100));
                _forgeUpdateCard(card, downloadMsg, 2 + Math.round(frac * 10));
            };
            pdf = await loadingTask.promise;
        } finally {
            // Released once the file transfer settles, not tied to the extraction loop below.
            _forgeReleaseLoadSlot();
        }
        const allLines = [];

        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            var pagePct = 12 + Math.round((pageNum - 1) / pdf.numPages * 28);
            var pageMsg = (i18n.page_reading || 'Reading page %1$d of %2$d…')
                .replace('%1$d', pageNum).replace('%2$d', pdf.numPages);
            _forgeUpdateCard(card, pageMsg, pagePct);
            const page    = await pdf.getPage(pageNum);
            const content = await page.getTextContent();

            const items = content.items.sort(function (a, b) {
                const yDiff = b.transform[5] - a.transform[5];
                if (Math.abs(yDiff) > Y_THRESHOLD) return yDiff;
                return a.transform[4] - b.transform[4];
            });

            const lines = [];
            let current = null;
            for (const item of items) {
                const y = item.transform[5];
                if (!current || Math.abs(current.y - y) > Y_THRESHOLD) {
                    current = { y: y, items: [item] };
                    lines.push(current);
                } else {
                    current.items.push(item);
                }
            }

            allLines.push(...lines.map(function (line) {
                return line.items.map(function (i) { return i.str; }).join('');
            }));
        }

        const ajaxUrl = window.ForgeVerifier && window.ForgeVerifier.ajaxUrl;
        if (!ajaxUrl) { done(); return allLines; }

        const formData = new FormData();
        formData.append('action',      'forge_verify_push_lines');
        formData.append('pdf_token',   pdfToken || '');
        formData.append('visualLines', JSON.stringify(allLines));
        formData.append('nonce',       (window.ForgeVerifier && window.ForgeVerifier.nonce) || '');

        /* Poll server-side progress only once the request actually goes out
           (see onRequestStart) — polling during the throttle wait would just
           spam no-op requests. lastServerPct resets on every (re)start since
           a 429 retry begins a brand-new server-side run from scratch. */
        var lastServerPct = 0;
        function startProgressPoll() {
            if (!pdfToken) { return; }
            lastServerPct = 0;
            _pollTimer = setInterval(function () {
                var pf = new FormData();
                pf.append('action', 'forge_verify_progress');
                pf.append('token',  pdfToken);
                pf.append('nonce',  (window.ForgeVerifier && window.ForgeVerifier.nonce) || '');
                fetch(ajaxUrl, { method: 'POST', body: pf, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.success && d.data && d.data.step && d.data.pct > lastServerPct) {
                            lastServerPct = d.data.pct;
                            _forgeUpdateCard(card, d.data.step, remapServerPct(d.data.pct));
                        }
                    })
                    .catch(function () {});
            }, 400);
        }

        try {
            function onWaitTick(waitMs) {
                var waitMsg = (i18n.queued || 'Waiting in queue (%1$ds)…')
                    .replace('%1$d', Math.ceil(waitMs / 1000));
                _forgeUpdateCard(card, waitMsg, 40);
                card.classList.add('forge-vpc--queued');
            }
            function onRequestStart() {
                card.classList.remove('forge-vpc--queued');
                _forgeUpdateCard(card, i18n.text_extracted || 'Text extracted — server analyzing…', 42);
                startProgressPoll();
            }

            var queuedForVerify = _forgeActiveVerifies >= FORGE_MAX_CONCURRENT_VERIFIES;
            if (queuedForVerify) {
                _forgeUpdateCard(card, i18n.queued_for_verify || 'Waiting for a free verification slot…', 40);
                card.classList.add('forge-vpc--queued');
            }
            await _forgeAcquireVerifySlot();
            if (queuedForVerify) { card.classList.remove('forge-vpc--queued'); }

            // The throttle gate above schedules slots with a margin, but the actual
            // request can still land inside another one's window — client/server
            // clock drift, network jitter, or the PHP worker itself being queued
            // under load from a large batch. Retry a 429 instead of failing the
            // file outright; _forgeWidenPushSlotGap() also grows the gap for every
            // remaining file in the batch so repeat collisions become less likely.
            // Verify slot is held through every retry below, not just the first attempt.
            var res;
            try {
                var maxAttempts = 5;
                for (var attempt = 1; attempt <= maxAttempts; attempt++) {
                    res = await _forgeThrottledPushLines(ajaxUrl, formData, onWaitTick, onRequestStart);
                    if (res.status !== 429 || attempt === maxAttempts) { break; }
                    stopPoll();

                    // A 429 with code:"busy" means the server-side concurrency cap, not the generic throttle.
                    var busyRetryAfter = null;
                    try {
                        var busyBody = await res.clone().json();
                        if (busyBody && busyBody.data && busyBody.data.code === 'busy') {
                            busyRetryAfter = Number(busyBody.data.retry_after) || 8;
                        }
                    } catch (_) { /* not JSON or already consumed — fall through to generic retry */ }

                    if (busyRetryAfter !== null) {
                        card.classList.add('forge-vpc--queued');
                        await _forgeCountdown(busyRetryAfter * 1000, function (remainingMs) {
                            var busyMsg = (i18n.server_busy_retry || 'Server busy — retrying in %1$ds…')
                                .replace('%1$d', Math.ceil(remainingMs / 1000));
                            _forgeUpdateCard(card, busyMsg, 40);
                        });
                    } else {
                        _forgeWidenPushSlotGap();
                        _forgeUpdateCard(card, i18n.rate_limited_retry || 'Rate limited — retrying…', 40);
                        card.classList.add('forge-vpc--queued');
                    }
                }
            } finally {
                // Rendering the result below is pure client-side work — no need to hold the slot for it.
                _forgeReleaseVerifySlot();
            }
            stopPoll();
            _forgeUpdateCard(card, i18n.processing || 'Processing response…', 98);
            const rawText = await res.text();

            let json = null;
            try {
                json = JSON.parse(rawText);
            } catch (_) {
                console.error('[FormFabricator] Non-JSON response (HTTP ' + res.status + ') for', pdfUrl, '\n', rawText);
                _forgeUpdateCard(card, (i18n.server_error || 'Server error (HTTP %d)').replace('%d', res.status), 100);
                card.classList.add('forge-vpc--error');
                done();
                return allLines;
            }

            _forgeUpdateCard(card, i18n.done || 'Done', 100);
            done();

            if (json.success === true && json.data && typeof json.data.html === 'string') {
                // Server-rendered fragment: every dynamic value in it is passed through
                // esc_html()/wp_kses() in Verificationpage.php before reaching here;
                // _forgeSanitizeFragment() is an additional client-side backstop.
                const tmp = document.createElement('div');
                tmp.innerHTML = _forgeSanitizeFragment(json.data.html);
                card.parentNode.replaceChild(tmp.firstElementChild || tmp, card);
            } else {
                console.error('[FormFabricator] Server returned error:', json);
                card.classList.add('forge-vpc--error');
                var msg = (json.data && json.data.message) || (i18n.unknown_error || 'Unknown server error');
                var stepEl = card.querySelector('.forge-vpc__step');
                if (stepEl) stepEl.textContent = (i18n.error_prefix || 'Error: ') + msg;
            }
        } catch (err) {
            stopPoll();
            console.error('[FormFabricator] Fetch error for', pdfUrl, err);
            _forgeUpdateCard(card, i18n.network_error || 'Network error', 100);
            card.classList.add('forge-vpc--error');
            done();
        }

        return allLines;
    } catch (e) {
        stopPoll();
        console.error('[FormFabricator] Error parsing PDF', pdfUrl, e);
        _forgeUpdateCard(card, (i18n.pdf_load_error || 'PDF load error: ') + e.message, 100);
        card.classList.add('forge-vpc--error');
        done();
        return [];
    } finally {
        // typeof-guarded: pdf.destroy() isn't reliably present on the resolved proxy across pdf.js versions.
        if (loadingTask && typeof loadingTask.destroy === 'function') {
            try { loadingTask.destroy(); } catch (destroyErr) { console.error('[FormFabricator] loadingTask.destroy() failed for', pdfUrl, destroyErr); }
        } else if (pdf && typeof pdf.destroy === 'function') {
            try { pdf.destroy(); } catch (destroyErr) { console.error('[FormFabricator] pdf.destroy() failed for', pdfUrl, destroyErr); }
        }
    }
};

/* Process any PDFs queued before this script loaded */
if (window.FORGE_VERIFICATION_QUEUE.length) {
    window.FORGE_VERIFICATION_QUEUE.forEach(function (item) {
        Promise.resolve(window.FORGE_VERIFICATION_PROCESS_PDF(item)).catch(function (err) {
            console.error('[FormFabricator] Unhandled error processing queued PDF', item, err);
        });
    });
    window.FORGE_VERIFICATION_QUEUE = [];
}
