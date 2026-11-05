/*!
 * FormFabricator — Performance Debugger
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 *
 * Enqueued with $in_footer=false so it lands in <head> and runs before
 * admin-builder.js — the timer-interception patch below must wrap the
 * native timer functions before any other script schedules a callback,
 * or early timers would be invisible to this profiler.
 */
(function () {
'use strict';

var STORAGE_KEY = 'fabricator_perf_active';
/* localStorage throws when storage is disabled, in some privacy modes and in sandboxed frames; a debug toggle must never
   take the editor down with it. */
function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
}
function storageSet(key, value) {
    try {
        if (value === null) { window.localStorage.removeItem(key); } else { window.localStorage.setItem(key, value); }
    } catch (e) { /* the toggle just doesn't persist */ }
}
var _active = storageGet(STORAGE_KEY) === '1';

/* ── Baseline ────────────────────────────────────────────────────────────── */
var T0       = performance.now();
var _log      = [];          /* boot phase entries: { label, t, delta, dur, note } */
var _renders  = [];          /* render entries: { t, nodes, dur } */
var _longTasks = [];         /* longtask entries: { t, dur } — separate from _log */
var _prevMark = T0;

function mark(label, note, dur) {
    var now   = performance.now();
    var delta = now - _prevMark;
    _log.push({ label: label, t: now - T0, delta: delta, note: note || '', dur: dur || null });
    _prevMark = now;
    console.log('[FabricatorPerfDebug] +' + delta.toFixed(1) + 'ms  ' + label + (note ? '  (' + note + ')' : ''));
}

function fmt(ms) {
    if (ms == null) return '';
    if (ms < 1000) return ms.toFixed(1) + ' ms';
    return (ms / 1000).toFixed(2) + ' s';
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* ── Timer interception: log intervals and long one-shots ────────────────── */
/* Runs before any other script only if this file is loaded in <head> with no defer */
var _timers  = []; /* { id, ms, label, repeating } */
var _origSt  = window.setTimeout.bind(window);   /* pre-patch reference for internal use */
var _origSi  = window.setInterval.bind(window);
/* Only patch global timers when the overlay is active — avoids overhead on every page load. */
if (_active) {
    (function () {
        var _si = window.setInterval;
        var _st = window.setTimeout;
        var _ci = window.clearInterval;
        var _ct = window.clearTimeout;

        window.setInterval = function (fn, ms) {
            var id = _si.apply(this, arguments);
            var label = (typeof fn === 'function' ? (fn.name || fn.toString().slice(0, 60)) : String(fn));
            _timers.push({ id: id, ms: ms, label: label, repeating: true });
            return id;
        };
        window.setTimeout = function (fn, ms) {
            var id = _st.apply(this, arguments);
            /* Only track timeouts > 800ms — short ones are noise */
            if (ms >= 800) {
                var label = (typeof fn === 'function' ? (fn.name || fn.toString().slice(0, 60)) : String(fn));
                _timers.push({ id: id, ms: ms, label: label, repeating: false });
            }
            return id;
        };
        window.clearInterval = function (id) {
            _timers = _timers.filter(function (t) { return t.id !== id; });
            return _ci.apply(this, arguments);
        };
        window.clearTimeout = function (id) {
            _timers = _timers.filter(function (t) { return t.id !== id; });
            return _ct.apply(this, arguments);
        };
    }());
}

/* ── FPS monitor ─────────────────────────────────────────────────────────── */
var _fps = 60, _frameTimes = [], _longFrames = 0;
var _rafRunning = false, _lastRaf = performance.now();
var _lastPanelUpdate = 0;

function startFpsMonitor() {
    if (_rafRunning) return;
    _rafRunning = true;
    (function tick(now) {
        var dt = now - _lastRaf;
        _lastRaf = now;
        _frameTimes.push(dt);
        if (_frameTimes.length > 60) _frameTimes.shift();
        if (dt > 50) _longFrames++;
        var sum = 0;
        for (var i = 0; i < _frameTimes.length; i++) sum += _frameTimes[i];
        _fps = _frameTimes.length ? 1000 / (sum / _frameTimes.length) : 60;
        if (now - _lastPanelUpdate >= 200) { _lastPanelUpdate = now; updatePanel(); }
        requestAnimationFrame(tick);
    }(performance.now()));
}

/* ── Field-list render timing ────────────────────────────────────────────── */
/* Shadow the innerHTML setter on this one element to capture clear time — mutation records alone can't separate it from the appendChilds. */

var _renderClearT = 0;

function watchFieldList() {
    var list = document.getElementById('fabricator-field-list');
    if (!list) return;

    /* Shadow innerHTML on the element instance */
    var _proto = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
    Object.defineProperty(list, 'innerHTML', {
        get: _proto.get,
        set: function (val) {
            if (val === '' || val === null) _renderClearT = performance.now();
            _proto.set.call(this, val);
        },
        configurable: true,
    });

    var obs = new MutationObserver(function (mutations) {
        var added = 0;
        for (var i = 0; i < mutations.length; i++) added += mutations[i].addedNodes.length;
        if (added === 0) return;

        var endT  = performance.now();
        var dur   = _renderClearT > 0 ? endT - _renderClearT : null;
        var nodes = list.querySelectorAll('*').length;
        _renders.push({ t: endT - T0, nodes: nodes, dur: dur });
        _renderClearT = 0;
        updatePanel();
    });
    obs.observe(list, { childList: true });
}

/* ── Panel UI ────────────────────────────────────────────────────────────── */
var _body = null, _open = true, _scrollPaused = false, _scrollTimer = null;

function buildPanel() {
    var wrap = document.createElement('div');
    wrap.id = 'fabricator-perf-panel';
    wrap.style.cssText = [
        'position:fixed','bottom:16px','right:16px','z-index:999999',
        'background:#1d2327','color:#e2e4e7','border-radius:8px',
        'box-shadow:0 4px 24px rgba(0,0,0,.5)','font:11px/1.4 monospace',
        'width:360px','height:480px',
        'display:flex','flex-direction:column','overflow:hidden',
    ].join(';');

    /* ── Header: click=toggle, drag=move ── */
    var head = document.createElement('div');
    head.style.cssText = 'display:flex;align-items:center;justify-content:space-between;' +
        'padding:8px 12px;background:#2c3338;border-radius:8px 8px 0 0;' +
        'cursor:move;user-select:none;flex-shrink:0;';
    head.innerHTML = '<span style="font-weight:700;color:#a7aaad;"><i class="fa-solid fa-bolt" aria-hidden="true"></i> FormFabricator Perf</span>' +
        '<span id="fabricator-perf-toggle" style="color:#72aee6;cursor:pointer;"><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></span>';

    _body = document.createElement('div');
    _body.style.cssText = 'overflow-y:auto;overflow-x:hidden;padding:8px 10px;flex:1 1 0;min-height:0;';
    _body.addEventListener('scroll', function () {
        _scrollPaused = true;
        clearTimeout(_scrollTimer);
        _scrollTimer = _origSt(function () { _scrollPaused = false; }, 500);
    }, { passive: true });

    /* ── Resize handle (bottom-left corner) ── */
    var resizeHandle = document.createElement('div');
    resizeHandle.style.cssText = [
        'position:absolute','bottom:0','left:0',
        'width:16px','height:16px','cursor:sw-resize',
        'display:flex','align-items:flex-end','justify-content:flex-start',
        'padding:3px',
    ].join(';');
    resizeHandle.innerHTML = '<svg width="8" height="8" viewBox="0 0 8 8" fill="none" style="transform:scaleX(-1)">' +
        '<path d="M1 7L7 1M4 7L7 4" stroke="#72aee6" stroke-width="1.5" stroke-linecap="round"/></svg>';

    /* ── Toggle click (separate from drag) ── */
    var _savedHeight = '';
    var toggle = head.querySelector('#fabricator-perf-toggle');
    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        _open = !_open;
        if (_open) {
            _body.style.display = '';
            resizeHandle.style.display = '';
            wrap.style.height = _savedHeight || '480px';
        } else {
            _savedHeight = wrap.style.height || wrap.offsetHeight + 'px';
            _body.style.display = 'none';
            resizeHandle.style.display = 'none';
            wrap.style.height = 'auto';
        }
        toggle.innerHTML = _open
            ? '<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>'
            : '<i class="fa-solid fa-chevron-up" aria-hidden="true"></i>';
    });

    /* ── Drag / resize logic ── */
    var _dragging = false, _resizing = false;
    var _sx, _sy, _sl, _st, _sw, _sh;

    function toAbsolute() {
        if (wrap.style.left) return; /* already converted */
        var r = wrap.getBoundingClientRect();
        wrap.style.right  = 'auto';
        wrap.style.bottom = 'auto';
        wrap.style.left   = r.left + 'px';
        wrap.style.top    = r.top  + 'px';
    }

    head.addEventListener('mousedown', function (e) {
        if (e.target === toggle) return;
        toAbsolute();
        _dragging = true;
        _sx = e.clientX; _sy = e.clientY;
        _sl = parseFloat(wrap.style.left); _st = parseFloat(wrap.style.top);
        e.preventDefault();
    });

    resizeHandle.addEventListener('mousedown', function (e) {
        toAbsolute();
        _resizing = true;
        _sx = e.clientX; _sy = e.clientY;
        _sl = parseFloat(wrap.style.left);
        _sw = wrap.offsetWidth; _sh = wrap.offsetHeight;
        e.preventDefault();
        e.stopPropagation();
    });

    document.addEventListener('mousemove', function (e) {
        if (_dragging) {
            var dx = e.clientX - _sx, dy = e.clientY - _sy;
            wrap.style.left = Math.max(0, _sl + dx) + 'px';
            wrap.style.top  = Math.max(0, _st + dy) + 'px';
        } else if (_resizing) {
            /* bottom-left handle: drag left → wider, drag right → narrower */
            var dx = e.clientX - _sx, dy = e.clientY - _sy;
            var newW = Math.max(260, _sw - dx);
            var newH = Math.max(120, _sh + dy);
            wrap.style.width  = newW + 'px';
            wrap.style.height = newH + 'px';
            wrap.style.left   = (_sl + _sw - newW) + 'px';
        }
    }, { passive: true });

    document.addEventListener('mouseup', function () { _dragging = false; _resizing = false; });

    wrap.appendChild(head);
    wrap.appendChild(_body);
    wrap.appendChild(resizeHandle);
    document.body.appendChild(wrap);
}

function updatePanel() {
    if (!_body || _scrollPaused) return;

    var fps     = _fps.toFixed(0);
    var fpsCol  = _fps >= 55 ? '#68de7c' : _fps >= 30 ? '#f0c33c' : '#f86368';
    var lastR   = _renders.length ? _renders[_renders.length - 1] : null;
    var lastLT  = _longTasks.length ? _longTasks[_longTasks.length - 1] : null;
    var mem     = performance.memory;
    var memStr  = mem ? (mem.usedJSHeapSize / 1048576).toFixed(0) + ' MB' : '—';
    var ivals   = _timers.filter(function (t) { return t.repeating; });

    var R = '<tr style="border-top:1px solid #3c434a;">';
    var html = '';

    /* ── Stat row ── */
    html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px;padding:6px 0 8px;border-bottom:1px solid #3c434a;margin-bottom:6px;">';
    html += chip('FPS',         fps,                       fpsCol);
    html += chip('Long tasks',  _longTasks.length,         _longTasks.length > 5 ? '#f86368' : _longTasks.length > 0 ? '#f0c33c' : '#68de7c');
    html += chip('Renders',     _renders.length,           '#72aee6');
    html += chip('Heap',        memStr,                    '#a7aaad');
    html += '</div>';

    /* ── Boot timeline ── */
    html += section('Boot');
    html += '<table style="width:100%;border-collapse:collapse;table-layout:fixed;">';
    for (var i = 0; i < _log.length; i++) {
        var e    = _log[i];
        var dCol = e.delta > 200 ? '#f86368' : e.delta > 50 ? '#f0c33c' : '#68de7c';
        html += R +
            '<td style="padding:2px 3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:44%">' + escH(e.label) + '</td>' +
            '<td style="padding:2px 3px;text-align:right;color:#a7aaad;width:22%">' + fmt(e.t) + '</td>' +
            '<td style="padding:2px 3px;text-align:right;color:' + dCol + ';width:16%">' + fmt(e.delta) + '</td>' +
            '<td style="padding:2px 3px;color:#72aee6;font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:18%">' + escH(e.note) + '</td>' +
        '</tr>';
    }
    html += '</table>';

    /* ── Renders (last 5) ── */
    if (_renders.length) {
        html += section('Renders (last 5)');
        html += '<table style="width:100%;border-collapse:collapse;table-layout:fixed;">';
        var show   = _renders.slice(-5);
        var rOff   = _renders.length - show.length;
        for (var j = 0; j < show.length; j++) {
            var r    = show[j];
            var rCol = r.dur == null ? '#a7aaad' : r.dur > 200 ? '#f86368' : r.dur > 50 ? '#f0c33c' : '#68de7c';
            html += R +
                '<td style="padding:2px 3px;color:#a7aaad;width:12%">#' + (rOff + j + 1) + '</td>' +
                '<td style="padding:2px 3px;text-align:right;color:#a7aaad;width:28%">' + fmt(r.t) + '</td>' +
                '<td style="padding:2px 3px;text-align:right;width:26%">' + r.nodes + ' nodes</td>' +
                '<td style="padding:2px 3px;text-align:right;color:' + rCol + ';width:34%">' + (r.dur != null ? fmt(r.dur) : '—') + '</td>' +
            '</tr>';
        }
        html += '</table>';
    }

    /* ── Long tasks (last 5) ── */
    if (_longTasks.length) {
        html += section('Long tasks (' + _longTasks.length + ')');
        html += '<table style="width:100%;border-collapse:collapse;table-layout:fixed;">';
        var ltShow = _longTasks.slice(-5);
        var ltOff  = _longTasks.length - ltShow.length;
        for (var k = 0; k < ltShow.length; k++) {
            var lt    = ltShow[k];
            var ltCol = lt.dur > 300 ? '#f86368' : lt.dur > 100 ? '#f0c33c' : '#e2e4e7';
            html += R +
                '<td style="padding:2px 3px;color:#a7aaad;width:16%">#' + (ltOff + k + 1) + '</td>' +
                '<td style="padding:2px 3px;text-align:right;color:#a7aaad;width:40%">' + fmt(lt.t) + '</td>' +
                '<td style="padding:2px 3px;text-align:right;color:' + ltCol + ';width:44%">' + fmt(lt.dur) + '</td>' +
            '</tr>';
        }
        html += '</table>';
    }

    /* ── Active intervals ── */
    if (ivals.length) {
        html += section('Intervals (' + ivals.length + ')');
        html += '<table style="width:100%;border-collapse:collapse;table-layout:fixed;">';
        for (var m = 0; m < ivals.length; m++) {
            var ti    = ivals[m];
            var tiCol = ti.ms < 2000 ? '#f0c33c' : '#a7aaad';
            html += R +
                '<td style="padding:2px 3px;text-align:right;color:' + tiCol + ';width:22%;white-space:nowrap;">' + ti.ms + ' ms</td>' +
                '<td style="padding:2px 3px;color:#72aee6;font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escH(ti.label.slice(0, 60)) + '</td>' +
            '</tr>';
        }
        html += '</table>';
    }

    _body.innerHTML = html;
}

function chip(label, val, col) {
    return '<div style="display:flex;flex-direction:column;align-items:center;padding:4px 2px;background:#2c3338;border-radius:4px;">' +
        '<span style="font-size:15px;font-weight:700;line-height:1;color:' + col + ';">' + val + '</span>' +
        '<span style="font-size:9px;color:#a7aaad;margin-top:2px;">' + label + '</span>' +
    '</div>';
}

/* ── Table helpers ── */
function section(t) {
    return '<div style="font-weight:700;color:#a7aaad;margin:8px 0 4px;">' + t + '</div>';
}
function td(v, col, size) {
    return '<td style="padding:2px 4px;' + (col?'color:'+col+';':'') + (size?'font-size:'+size+';':'') + '">' + v + '</td>';
}

/* ── Long-task observer ──────────────────────────────────────────────────── */
/* Separate capped array, not _log — updatePanel() itself can cause a longtask, which would otherwise trigger another updatePanel(). */
var _ltPanelPending = false;
if (window.PerformanceObserver) {
    try {
        new PerformanceObserver(function (list) {
            list.getEntries().forEach(function (e) {
                if (e.duration > 50) {
                    _longTasks.push({ t: e.startTime - T0, dur: e.duration });
                    if (_longTasks.length > 50) _longTasks.shift(); /* cap at 50 */
                }
            });
            /* Throttle panel update: coalesce bursts into one rAF paint */
            if (!_ltPanelPending) {
                _ltPanelPending = true;
                requestAnimationFrame(function () { _ltPanelPending = false; updatePanel(); });
            }
        }).observe({ entryTypes: ['longtask'] });
    } catch (_) {}
}

/* ── PHP render time ─────────────────────────────────────────────────────── */
if (_active) {
    if (window.FabricatorPerfData && FabricatorPerfData.phpRenderMs) {
        mark('PHP page render', parseFloat(FabricatorPerfData.phpRenderMs).toFixed(1) + ' ms server-side');
    }
    mark('Debug script parsed');
}

/* ── DOMContentLoaded ────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    /* Wire the toggle button regardless of active state */
    var perfBtn = document.getElementById('fabricator-perf-btn');
    if (perfBtn) {
        var isOn = _active;
        var _pi18n = (window.FabricatorPerfData && FabricatorPerfData.i18n) || {};
        perfBtn.title = isOn
            ? (_pi18n.toggleHide || 'Hide performance overlay')
            : (_pi18n.toggleShow || 'Show performance overlay');
        perfBtn.style.opacity = isOn ? '1' : '0.45';
        perfBtn.addEventListener('click', function () {
            storageSet(STORAGE_KEY, storageGet(STORAGE_KEY) === '1' ? null : '1');
            if (window.fabricatorGuardedReload) { window.fabricatorGuardedReload(); } else { location.reload(); }
        });
    }

    if (!_active) { return; }
    mark('DOMContentLoaded fired');
    buildPanel();
    startFpsMonitor();
    watchFieldList();

    /* ── Synchronous boot phases: watch document.body for specific IDs ── */
    var syncPhases = [
        { id: 'fabricator-field-modal',         label: 'createFieldPickerModal' },
        { id: 'fabricator-notifications-panel', label: 'renderNotifications'    },
        { id: 'fabricator-submit-preview-bar',  label: 'renderSubmitPreview'    },
    ];
    var syncPending = syncPhases.slice();

    var syncObs = new MutationObserver(function (mutations) {
        if (!syncPending.length) { syncObs.disconnect(); return; }
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                syncPending = syncPending.filter(function (p) {
                    var found = node.id === p.id || !!node.querySelector('#' + p.id);
                    if (!found) return true;
                    var el = document.getElementById(p.id);
                    mark(p.label, (el ? el.querySelectorAll('*').length : '?') + ' DOM nodes');
                    updatePanel();
                    return false;
                });
                if (!syncPending.length) syncObs.disconnect();
            });
        });
    });
    syncObs.observe(document.body, { childList: true, subtree: true });

    /* ── Deferred modals: same approach, separate observer, survives setTimeout ── */
    var deferredPhases = [
        { id: 'fabricator-settings-modal', label: 'createSettingsModal (deferred)' },
        { id: 'fabricator-notif-modal',    label: 'createNotifModal (deferred)'    },
        { id: 'fabricator-submit-modal',   label: 'createSubmitModal (deferred)'   },
    ];
    var deferredPending = deferredPhases.slice();

    var deferredObs = new MutationObserver(function (mutations) {
        if (!deferredPending.length) { deferredObs.disconnect(); return; }
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                deferredPending = deferredPending.filter(function (p) {
                    var found = node.id === p.id || !!node.querySelector('#' + p.id);
                    if (!found) return true;
                    var el = document.getElementById(p.id);
                    mark(p.label, (el ? el.querySelectorAll('*').length : '?') + ' DOM nodes');
                    updatePanel();
                    return false;
                });
                if (!deferredPending.length) deferredObs.disconnect();
            });
        });
    });
    deferredObs.observe(document.body, { childList: true, subtree: false });

    /* ── Boot complete ── */
    requestAnimationFrame(function () {
        setTimeout(function () {
            mark('Boot complete');
            updatePanel();
        }, 0);
    });
});

})();
