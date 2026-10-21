/*!
 * FormFabricator
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
/*
 * FormFabricator - Admin Builder
 */
(function () {
'use strict';

/* Falls back to English literals if localization fails to load. */
var _i18n = (window.FabricatorBuilderI18n && window.FabricatorBuilderI18n.i18n) || {};

/* ES5 for-in, not Object.assign, to match this file's style. */
function mergeI18nMap(fallback, localized) {
    var out = {};
    var k;
    for (k in fallback) { if (Object.prototype.hasOwnProperty.call(fallback, k)) out[k] = fallback[k]; }
    for (k in localized) { if (Object.prototype.hasOwnProperty.call(localized, k) && localized[k]) out[k] = localized[k]; }
    return out;
}

/* No nested groups; page-flow fields must stay top-level siblings of the pages they act on. */
var NO_GROUP_TYPES = ['group', 'pagebreak', 'page-header'];

function makeSortable(list, handleSel, rowSel, onReorder) {
    /* Listeners are on document, not list/item: item goes position:fixed and can move anywhere during drag. */
    list.addEventListener('pointerdown', function (e) {
        var handle = e.target.closest(handleSel);
        if (!handle) { return; }
        var item = handle.closest(rowSel);
        if (!item || !list.contains(item)) { return; }

        e.preventDefault();
        var rect = item.getBoundingClientRect();
        var offY = e.clientY - rect.top;

        var ph = document.createElement('div');
        ph.style.cssText = 'height:' + rect.height + 'px;margin-bottom:4px;' +
            'border:2px dashed #c3c4c7;border-radius:6px;box-sizing:border-box;flex-shrink:0;';
        item.after(ph);

        item.style.cssText = 'position:fixed;left:' + rect.left + 'px;' +
            'top:' + (e.clientY - offY) + 'px;width:' + rect.width + 'px;' +
            'z-index:200000;opacity:.9;pointer-events:none;' +
            'box-shadow:0 4px 16px rgba(0,0,0,.2);';
        document.body.appendChild(item);

        /* Snapshot rows and their midpoints once at drag start — avoids
           querySelectorAll + getBoundingClientRect on every pointermove. */
        var sortRows = Array.from(list.querySelectorAll(rowSel)).filter(function (r) { return r !== item; });
        var sortMids = sortRows.map(function (r) {
            var b = r.getBoundingClientRect();
            return b.top + b.height / 2;
        });

        function onMove(ev) {
            item.style.top = (ev.clientY - offY) + 'px';
            var before = null;
            for (var i = 0; i < sortMids.length; i++) {
                if (ev.clientY < sortMids[i]) { before = sortRows[i]; break; }
            }
            try {
                if (before && list.contains(before)) { list.insertBefore(ph, before); }
                else { list.appendChild(ph); }
            } catch (err) { /* stale node ref — skip this frame */ }
        }

        function onUp() {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            item.style.cssText = '';
            try {
                ph.replaceWith(item);
            } catch (err) {
                /* ph got detached mid-drag — fall back to appending directly */
                if (list.contains(ph)) { list.removeChild(ph); }
                list.appendChild(item);
            }
            if (onReorder) { onReorder(Array.from(list.querySelectorAll(rowSel))); }
        }

        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
    });
}

var AJAX_URL       = ''; /* set from data-ajax-url (admin_url('admin-ajax.php')) before any request fires */
var NONCE          = '';
var FORM_ID        = 0;
var FORM_SNAPSHOT   = ''; /* optimistic-concurrency token echoed back on save */
var PALETTE_GROUPS  = [];
var TYPE_COLOR_MAP  = {};
var _lastRenderedFieldsJson = null; /* dirty-check cache for renderFieldList */

/* Must stay in sync with FormEditor.php's schema — PHP doesn't validate deeply, so drift here silently corrupts saved forms. */
var state = {
    fields: [], notifications: [], formName: _i18n.defaultFormName || 'New Form',
    settings: {
        submit_label:      _i18n.submitLabel   || 'Submit',
        submit_working:    _i18n.submitWorking || 'Sending…',
        success_message:   _i18n.successMessage || 'Thank you for your submission!',
        submit_conditions: { match: 'all', rules: [] },
    },
};
var _dirty = false;
var _bypassUnload = false;
window.fabricatorBypassUnload  = function () { _bypassUnload = true; };
window.fabricatorGuardedReload = function () {
    if (!_dirty) { location.reload(); return; }
    showUnsavedDialog(function () { _bypassUnload = true; location.reload(); });
};
function markDirty() { _dirty = true; }
function markClean() { _dirty = false; }

window.addEventListener('beforeunload', function (e) {
    if (!_dirty || _bypassUnload) { return; }
    e.preventDefault();
    /* Non-empty string required for legacy browsers; modern browsers show their own text */
    e.returnValue = 'unsaved';
    return 'unsaved';
});

/* ── Unsaved-changes guard: custom modal for in-page navigations ── */
function showUnsavedDialog(onDiscard) {
    var backdrop = document.createElement('div');
    backdrop.className = 'fabricator-modal-backdrop';
    backdrop.style.cssText = 'display:flex;z-index:1000000;';

    var box = document.createElement('div');
    box.className = 'fabricator-modal-box';
    box.style.cssText = 'max-width:400px;padding:28px 28px 22px;';
    box.innerHTML =
        '<h2 style="margin:0 0 10px;font-size:16px;display:flex;align-items:center;gap:8px;">' +
            '<i class="fa-solid fa-triangle-exclamation" style="color:#d63638;"></i> ' + escHtml(_i18n.unsavedTitle || 'Unsaved changes') +
        '</h2>' +
        '<p style="margin:0 0 20px;color:#50575e;font-size:13px;">' +
            escHtml(_i18n.unsavedBody || 'This form has unsaved changes. Leave anyway?') +
        '</p>' +
        '<div style="display:flex;gap:8px;justify-content:space-between;">' +
            '<button class="button fabricator-guard-stay">' + escHtml(_i18n.stay || 'Stay') + '</button>' +
            '<button class="button fabricator-guard-discard" style="color:#d63638;border-color:#d63638;">' + escHtml(_i18n.leave || 'Leave') + '</button>' +
        '</div>';

    backdrop.appendChild(box);
    document.body.appendChild(backdrop);

    box.querySelector('.fabricator-guard-stay').addEventListener('click', function () {
        if (backdrop.parentNode) { backdrop.parentNode.removeChild(backdrop); }
    });
    box.querySelector('.fabricator-guard-discard').addEventListener('click', function () {
        _bypassUnload = true;
        if (backdrop.parentNode) { backdrop.parentNode.removeChild(backdrop); }
        onDiscard();
    });
}

/* F5 / Ctrl+R: browsers suppress beforeunload for reloads when no prior interaction exists
   (carry-dirty pages have no interaction yet) — intercept the key directly instead. */
document.addEventListener('keydown', function (e) {
    if (!_dirty) { return; }
    if (e.key !== 'F5' && !((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'r')) { return; }
    e.preventDefault();
    showUnsavedDialog(function () { _bypassUnload = true; location.reload(); });
}, true);

/* Intercept WP admin link clicks when dirty — skip links inside the editor itself */
document.addEventListener('click', function (e) {
    if (!_dirty) { return; }
    var a = e.target.closest('a[href]');
    if (!a) { return; }
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || a.target === '_blank') { return; }
    /* Skip links inside the fabricator editor wrap (canvas, modals, etc.) */
    if (a.closest('.fabricator-editor-wrap, .fabricator-modal-backdrop')) { return; }
    e.preventDefault();
    showUnsavedDialog(function () { window.location.href = href; });
}, true);

/* Module-level, not closure state: multiple independent DOM listeners (dragstart/dragover/dragend) must agree on drag state. */
var dragSrcIdx           = null;
var dragBrokenPartnerIdx = null; /* partner idx whose cols we set to 12 on dragstart */
var dropSideMode         = null; /* 'left' | 'right' | null */
var dropSideTargetIdx    = null;
var dropLineTarget       = null; /* { beforeEl } or { atEnd: true } */
var dropLineEl           = null;
var settingsCtx            = null; /* { groupIdx, childIdx } when editing a group child, else null */
var fieldPickerTargetGroup = null; /* { groupIdx, onAdd } when adding a child to a group */
var dragChildSrc           = null; /* { groupIdx, childIdx } | null — set when dragging a group child */
var childDropSideMode      = null; /* 'left' | 'right' | null — current side-drop mode for child rows */
var childDropTgtIdx        = null; /* child index being side-dropped onto */
var childGroupDropTarget   = null; /* { beforeEl } | { atEnd: true } — drop-line position within a group */

var VALIDATION_OPTIONS = {
    number: [
        ['', _i18n.validationNone || 'None'],
        ['integer', _i18n.validationIntegersOnly || 'Integers only'],
        ['positive', _i18n.validationPositiveNumbers || 'Positive numbers'],
        ['positive_int', _i18n.validationPositiveIntegers || 'Positive integers'],
    ],
};

var OPERATORS = [
    ['equals',      _i18n.opEquals      || 'is equal to'],
    ['not_equals',  _i18n.opNotEquals   || 'is not equal to'],
    ['contains',    _i18n.opContains    || 'contains'],
    ['not_contains',_i18n.opNotContains || 'does not contain'],
    ['empty',       _i18n.opEmpty       || 'is empty'],
    ['not_empty',   _i18n.opNotEmpty    || 'is not empty'],
    ['greater',     _i18n.opGreater     || 'is greater than'],
    ['less',        _i18n.opLess        || 'is less than'],
];

var fieldPickerModal           = null;
var fieldPickerCachedBody      = null;  /* DOM fragment for the empty-query render */
var fieldPickerCacheIsGroupCtx = false; /* context the cache was built for */
var settingsModal         = null;
var settingsModalIdx      = null;

var cachedMidpoints = [];
var rafPending      = false;
var pendingY        = 0;

/* Bootstrap */
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('fabricator-editor');
    if (el) {
        try {
            var fd  = JSON.parse(el.dataset.form    || '{}');
            var pal = JSON.parse(el.dataset.palette || '[]');

            state.fields        = fd.fields        || [];
            state.notifications = fd.notifications || [];
            state.formName      = fd.title         || (_i18n.defaultFormName || 'New Form');
            var s = fd.settings;
            if (s) {
                if (s.submit_label    !== undefined) state.settings.submit_label    = s.submit_label;
                if (s.submit_working  !== undefined) state.settings.submit_working  = s.submit_working;
                if (s.success_message !== undefined) state.settings.success_message = s.success_message;
                if (s.submit_conditions) state.settings.submit_conditions = s.submit_conditions;
            }
            AJAX_URL = el.dataset.ajaxUrl || AJAX_URL;
            NONCE    = el.dataset.nonce   || NONCE;
            FORM_ID  = fd.id              || 0;
            FORM_SNAPSHOT = fd.snapshot   || '';
            for (var i = 0; i < pal.length; i++) PALETTE_GROUPS.push(pal[i]);
            /* Build type→color lookup from palette groups */
            for (var g = 0; g < pal.length; g++) {
                var gc = pal[g].color || '';
                if (!gc) continue;
                for (var gi = 0; gi < pal[g].items.length; gi++) {
                    TYPE_COLOR_MAP[pal[g].items[gi].type] = gc;
                }
            }
        } catch (e) { /* ignore */ }
    }

    /* One <style> block drives border-left-color via [data-type], avoiding a per-row inline style. */
    (function () {
        var rules = '';
        for (var t in TYPE_COLOR_MAP) {
            if (Object.prototype.hasOwnProperty.call(TYPE_COLOR_MAP, t)) {
                rules += '.fabricator-field-row[data-type="' + t + '"],'
                       + '.fabricator-child-row[data-type="' + t + '"]{border-left-color:'
                       + TYPE_COLOR_MAP[t] + ';}';
            }
        }
        if (rules) {
            var s = document.createElement('style');
            s.textContent = rules;
            document.head.appendChild(s);
        }
    }());

    createFieldPickerModal();
    renderFieldList();
    initDragDrop();
    bindAddFieldButton();
    bindFormName();
    bindSave();
    bindPreview();
    bindCanvasTabs();
    renderNotifications();
    renderSubmitPreview();
    /* Defer heavy modal DOM creation until after first paint */
    setTimeout(function () {
        createSettingsModal();
        createNotifModal();
        createSubmitModal();
        /* Pre-warm the field picker cache so first open is instant */
        renderFieldPickerGroups('');
    }, 0);
});

/* Field List */
function renderFieldList() {
    var list = document.getElementById('fabricator-field-list');
    if (!list) return;
    var json = JSON.stringify(state.fields);
    if (json === _lastRenderedFieldsJson) return;
    /* Doubles as the dirty-flag setter, except the very first (bootstrap) call. */
    if (_lastRenderedFieldsJson !== null) { markDirty(); } /* skip initial render */
    _lastRenderedFieldsJson = json;
    list.innerHTML = '';

    if (state.fields.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'fabricator-empty-canvas';
        empty.innerHTML =
            '<div class="fabricator-empty-icon"><i class="fa-solid fa-layer-group"></i></div>' +
            '<h2 style="font-size:16px;font-weight:700;color:#1d2327;margin:0;">' + escHtml(_i18n.noFieldsYetTitle || 'No fields yet') + '</h2>' +
            '<p>' + withBoldName(_i18n.addFirstFieldHtml || 'Add your first field via %s', _i18n.addFieldTitle || 'Add field') + '</p>';
        list.appendChild(empty);
    } else {
        for (var i = 0; i < state.fields.length; i++) {
            list.appendChild(buildFieldRow(state.fields[i], i));
        }
    }
}

/* For dragend: forces the rebuild, so the row dragstart hid comes back even when nothing moved (null also skips
   markDirty()). */
function renderFieldListAfterDrag() {
    if (JSON.stringify(state.fields) === _lastRenderedFieldsJson) {
        _lastRenderedFieldsJson = null;
    }
    renderFieldList();
}

/* The "has conditions" icon after a row's label: top-level rows, a group's header and the rows inside a group. */
function condBadgeHtml(field) {
    return (field.conditions && field.conditions.rules && field.conditions.rules.length)
        ? ' <i class="fa-solid fa-code-branch fabricator-cond-badge" title="' + escHtml(_i18n.conditionsActive || 'Conditions active') + '"></i>' : '';
}

function buildFieldRow(field, idx) {
    if (field.type === 'group') { return buildGroupRow(field, idx); }
    var row      = document.createElement('div');
    row.className    = 'fabricator-field-row';
    row.draggable    = true;
    row.dataset.idx  = idx;
    row.dataset.type = field.type || '';
    row.dataset.cols = field.cols || 12;

    var pal       = findPaletteItem(field.type);
    var icon      = pal ? pal.icon  : 'fa-solid fa-square';
    var typeLabel = pal ? pal.label : field.type;
    var condBadge = condBadgeHtml(field);
    row.innerHTML =
        '<div class="fabricator-row-handle"><i class="fa-solid fa-grip-vertical"></i></div>' +
        '<div class="fabricator-row-icon"><i class="' + escHtml(icon) + '"></i></div>' +
        '<div class="fabricator-row-info">' +
            '<div class="fabricator-row-label">' + escHtml(field.label || (_i18n.noLabel || '(no label)')) + condBadge + '</div>' +
            '<div class="fabricator-row-type">' + escHtml(typeLabel) + '</div>' +
        '</div>' +
        '<span class="fabricator-row-id">{' + escHtml(field.id || '') + '}</span>' +
        '<div class="fabricator-row-actions">' +
            '<button class="fabricator-row-btn fabricator-row-edit"      title="' + escHtml(_i18n.edit || 'Edit') + '"><i class="fa-solid fa-pen-to-square"></i></button>' +
            '<button class="fabricator-row-btn fabricator-row-duplicate" title="' + escHtml(_i18n.duplicate || 'Duplicate') + '"><i class="fa-solid fa-copy"></i></button>' +
            '<button class="fabricator-row-btn fabricator-row-delete"    title="' + escHtml(_i18n.remove || 'Remove') + '"><i class="fa-solid fa-trash"></i></button>' +
        '</div>';

    row.addEventListener('click', function (e) {
        if (e.target.closest('.fabricator-row-delete') ||
            e.target.closest('.fabricator-row-duplicate')) return;
        openSettingsModal(parseInt(row.dataset.idx, 10));
    });

    var rowIdBadge = row.querySelector('.fabricator-row-id');
    if (rowIdBadge) {
        rowIdBadge.addEventListener('click', function (e) {
            e.stopPropagation();
            var id = '{' + state.fields[parseInt(row.dataset.idx, 10)].id + '}';
            navigator.clipboard.writeText(id).then(function () {
                var prev = rowIdBadge.innerHTML;
                rowIdBadge.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> ' + escHtml(_i18n.copied || 'copied');
                setTimeout(function () { rowIdBadge.innerHTML = prev; }, 1500);
            });
        });
    }

    row.querySelector('.fabricator-row-duplicate').addEventListener('click', function (e) {
        e.stopPropagation();
        var i  = parseInt(row.dataset.idx, 10);
        var cl = JSON.parse(JSON.stringify(state.fields[i]));
        cl.id  = generateId(cl.type);
        state.fields.splice(i + 1, 0, cl);
        renderFieldList();
    });

    row.querySelector('.fabricator-row-delete').addEventListener('click', function (e) {
        e.stopPropagation();
        var di = parseInt(row.dataset.idx, 10);
        var df = state.fields[di];
        if (df && df.cols === 6) {
            var dp = state.fields[di - 1];
            var dn = state.fields[di + 1];
            if (dp && dp.cols === 6) { dp.cols = 12; }
            if (dn && dn.cols === 6) { dn.cols = 12; }
        }
        state.fields.splice(di, 1);
        renderFieldList();
    });

    /* Per-row dragover: detect left/right quarter for side-by-side pairing */
    row.addEventListener('dragover', function (e) {
        if (dragSrcIdx === null) return;
        /* Groups can't participate in side-by-side pairs */
        var srcF2 = state.fields[dragSrcIdx];
        if (!srcF2 || srcF2.type === 'group') return;

        /* Already paired — no side-dropping allowed */
        if (field.cols === 6) {
            if (dropSideMode !== null) {
                clearDropIndicators();
                dropSideMode      = null;
                dropSideTargetIdx = null;
            }
            return;
        }

        var rect = row.getBoundingClientRect();
        var pct  = (e.clientX - rect.left) / rect.width;
        var tIdx = parseInt(row.dataset.idx, 10);

        if (pct < 0.25) {
            if (dropSideMode !== 'left' || dropSideTargetIdx !== tIdx) {
                clearDropIndicators();
                row.classList.add('fabricator-drop-left');
                activeDropIndicatorEl = row;
                dropSideMode      = 'left';
                dropSideTargetIdx = tIdx;
                hideDropLine();
            }
        } else if (pct > 0.75) {
            if (dropSideMode !== 'right' || dropSideTargetIdx !== tIdx) {
                clearDropIndicators();
                row.classList.add('fabricator-drop-right');
                activeDropIndicatorEl = row;
                dropSideMode      = 'right';
                dropSideTargetIdx = tIdx;
                hideDropLine();
            }
        } else {
            if (dropSideMode !== null) {
                clearDropIndicators();
                dropSideMode      = null;
                dropSideTargetIdx = null;
            }
        }
    });

    row.addEventListener('dragleave', function (e) {
        if (row.contains(e.relatedTarget)) return;
        if (dropSideTargetIdx === parseInt(row.dataset.idx, 10)) {
            clearDropIndicators();
            dropSideMode      = null;
            dropSideTargetIdx = null;
        }
    });

    row.addEventListener('drop', function (e) {
        if (dragSrcIdx === null || dropSideMode === null) return;
        /* Groups cannot participate in 2-col pairs */
        var srcField = state.fields[dragSrcIdx];
        if (srcField && srcField.type === 'group') {
            dragSrcIdx = null; dragBrokenPartnerIdx = null;
            dropSideMode = null; dropSideTargetIdx = null;
            cachedMidpoints = []; rafPending = false;
            clearDropIndicators(); hideDropLine();
            var lEl = document.getElementById('fabricator-field-list');
            if (lEl) lEl.classList.remove('fabricator-drag-active');
            renderFieldList(); return;
        }
        e.preventDefault();
        e.stopPropagation();

        var mode      = dropSideMode;
        var targetIdx = dropSideTargetIdx;
        var srcIdx    = dragSrcIdx;

        dragSrcIdx           = null;
        dragBrokenPartnerIdx = null; /* drop handled */
        dropSideMode         = null;
        dropSideTargetIdx    = null;
        cachedMidpoints      = [];
        rafPending           = false;

        clearDropIndicators();
        hideDropLine();
        var listEl = document.getElementById('fabricator-field-list');
        if (listEl) { listEl.classList.remove('fabricator-drag-active'); }

        if (srcIdx === targetIdx) { renderFieldList(); return; }

        var item = state.fields.splice(srcIdx, 1)[0];
        if (srcIdx < targetIdx) { targetIdx--; }

        item.cols = 6;
        state.fields[targetIdx].cols = 6;

        if (mode === 'left') {
            state.fields.splice(targetIdx, 0, item);
        } else {
            state.fields.splice(targetIdx + 1, 0, item);
        }
        repairPairs();
        renderFieldList();
    });

    row.addEventListener('dragstart', function (e) {
        dragSrcIdx = parseInt(row.dataset.idx, 10);
        var f = state.fields[dragSrcIdx];
        dragBrokenPartnerIdx = null;
        if (f && f.cols === 6) {
            /* Must resolve partnerIdx before mutating cols below — flipping cols first would corrupt isSecondInPair's parity check. */
            var partnerIdx = null;
            if (isSecondInPair(dragSrcIdx)) {
                partnerIdx = dragSrcIdx - 1;
            } else {
                var nx = state.fields[dragSrcIdx + 1];
                if (nx && nx.cols === 6 && isSecondInPair(dragSrcIdx + 1)) {
                    partnerIdx = dragSrcIdx + 1;
                }
            }
            if (partnerIdx !== null) {
                state.fields[partnerIdx].cols = 12;
                dragBrokenPartnerIdx = partnerIdx;
            }
            /* Set dragged field to cols:12 now so it can't corrupt isSecondInPair during drag */
            f.cols = 12;
        }
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(dragSrcIdx));
        /* setTimeout(0): hiding synchronously would blank the native drag image in Firefox/Chromium. */
        setTimeout(function () {
            /* Hide from grid flow AND mark class so cacheMidpoints excludes it */
            row.style.display = 'none';
            row.classList.add('fabricator-row-dragging');
            var listEl = document.getElementById('fabricator-field-list');
            if (listEl) {
                if (dragBrokenPartnerIdx !== null) {
                    var pEl = listEl.querySelector('.fabricator-field-row[data-idx="' + dragBrokenPartnerIdx + '"]');
                    if (pEl) pEl.dataset.cols = 12;
                }
                listEl.classList.add('fabricator-drag-active');
            }
            cacheMidpoints();
        }, 0);
    });
    row.addEventListener('dragend', function () {
        /* If no drop handler fired (cancelled drag), restore state */
        if (dragSrcIdx !== null) {
            /* Dragged field had cols set to 12 in dragstart — restore if it was paired */
            if (dragBrokenPartnerIdx !== null) {
                if (state.fields[dragSrcIdx])           { state.fields[dragSrcIdx].cols = 6; }
                if (state.fields[dragBrokenPartnerIdx]) { state.fields[dragBrokenPartnerIdx].cols = 6; }
            }
        }
        repairPairs();
        dragSrcIdx           = null;
        dragBrokenPartnerIdx = null;
        dropSideMode         = null;
        dropSideTargetIdx    = null;
        dropLineTarget       = null;
        cachedMidpoints      = [];
        rafPending           = false;
        clearDropIndicators();
        hideDropLine();
        var listEl = document.getElementById('fabricator-field-list');
        if (listEl) listEl.classList.remove('fabricator-drag-active');
        renderFieldListAfterDrag();
    });

    return row;
}

/* Group field row (always full-width, expandable child list) */
function buildGroupRow(field, idx) {
    var row      = document.createElement('div');
    row.className    = 'fabricator-field-row fabricator-field-row--group';
    row.draggable    = true;
    row.dataset.idx  = idx;
    row.dataset.type = 'group';
    row.dataset.cols = 12;


    /* ---- Compact header bar ---- */
    var hdr = document.createElement('div');
    hdr.className = 'fabricator-group-row-hdr';
    hdr.innerHTML =
        '<div class="fabricator-row-handle"><i class="fa-solid fa-grip-vertical"></i></div>' +
        '<div class="fabricator-row-icon"><i class="fa-solid fa-layer-group"></i></div>' +
        '<div class="fabricator-row-info">' +
            '<div class="fabricator-row-label">' + escHtml(field.label || (_i18n.noLabel || '(no label)')) + condBadgeHtml(field) + '</div>' +
            '<div class="fabricator-row-type">' + escHtml(_i18n.fieldGroupType || 'Field group') + '</div>' +
        '</div>' +
        '<div class="fabricator-row-actions">' +
            '<button class="fabricator-row-btn fabricator-row-edit"   title="' + escHtml(_i18n.settingsLabel || 'Settings') + '"><i class="fa-solid fa-pen-to-square"></i></button>' +
            '<button class="fabricator-row-btn fabricator-row-delete" title="' + escHtml(_i18n.remove || 'Remove') + '"><i class="fa-solid fa-trash"></i></button>' +
        '</div>';
    row.appendChild(hdr);

    /* Click anywhere on the header (except delete) opens settings */
    hdr.addEventListener('click', function (e) {
        if (e.target.closest('.fabricator-row-delete')) return;
        openSettingsModal(parseInt(row.dataset.idx, 10));
    });

    /* ---- Always-open children drop zone ---- */
    var zone = document.createElement('div');
    zone.className = 'fabricator-group-zone';

    var childList = document.createElement('div');
    childList.className = 'fabricator-group-children-list';
    zone.appendChild(childList);

    var addChildBtn = document.createElement('button');
    addChildBtn.type      = 'button';
    addChildBtn.className = 'fabricator-group-add-child-btn';
    addChildBtn.innerHTML = '<i class="fa-solid fa-plus"></i> ' + escHtml(_i18n.addFieldsToGroup || 'Add fields to group');
    zone.appendChild(addChildBtn);
    row.appendChild(zone);

    /* Helpers scoped to this group's childList closure */
    function childBeforeY(y) {
        var rows = childList.querySelectorAll('.fabricator-child-row');
        var best = null, bestDist = Infinity;
        for (var i = 0; i < rows.length; i++) {
            var box = rows[i].getBoundingClientRect();
            var mid = box.top + box.height / 2;
            if (y < mid && mid - y < bestDist) { bestDist = mid - y; best = rows[i]; }
        }
        return best;
    }
    function childInsertIdx(gi, target) {
        var ch = (state.fields[gi] && state.fields[gi].children) || [];
        if (!target || target.atEnd) return ch.length;
        var rows = childList.querySelectorAll('.fabricator-child-row');
        for (var k = 0; k < rows.length; k++) { if (rows[k] === target.beforeEl) return k; }
        return ch.length;
    }

    function renderChildList() {
        childList.innerHTML = '';
        var currentIdx   = parseInt(row.dataset.idx, 10);
        var currentField = state.fields[currentIdx];
        if (!currentField) return;
        var children = currentField.children || [];
        zone.classList.toggle('fabricator-group-zone--empty', children.length === 0);
        children.forEach(function (child, ci) {
            var childRow = buildChildRow(child, ci, currentIdx, renderChildList);
            childRow.dataset.cols = child.cols || 12;
            childRow.draggable = true;

            childRow.addEventListener('dragstart', function (e) {
                e.stopPropagation(); /* prevent group row's own dragstart from hiding the zone */
                var gi = parseInt(row.dataset.idx, 10);
                dragChildSrc = { groupIdx: gi, childIdx: ci };
                var ch = state.fields[gi] && state.fields[gi].children || [];
                if (ch[ci] && ch[ci].cols === 6) {
                    if (isSecondInGroupPair(gi, ci)) { if (ch[ci - 1]) ch[ci - 1].cols = 12; }
                    else { if (ch[ci + 1] && ch[ci + 1].cols === 6) ch[ci + 1].cols = 12; }
                    ch[ci].cols = 12;
                }
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', 'child');
                setTimeout(function () {
                    childRow.style.display = 'none';
                    /* Sync data-cols on siblings without rebuilding — preserves drag ghost */
                    var stCh = (state.fields[gi] && state.fields[gi].children) || [];
                    var domRows = childList.querySelectorAll('.fabricator-child-row');
                    domRows.forEach(function (cr, k) {
                        if (stCh[k]) cr.dataset.cols = stCh[k].cols || 12;
                    });
                    var listEl = document.getElementById('fabricator-field-list');
                    if (listEl) listEl.classList.add('fabricator-drag-active');
                    cacheMidpoints();
                }, 0);
            });

            childRow.addEventListener('dragend', function () {
                if (dragChildSrc !== null) {
                    repairGroupPairs(dragChildSrc.groupIdx);
                    dragChildSrc = null;
                }
                childDropSideMode = null; childDropTgtIdx = null; childGroupDropTarget = null;
                clearGroupDropIndicators();
                var listEl = document.getElementById('fabricator-field-list');
                if (listEl) listEl.classList.remove('fabricator-drag-active');
                hideDropLine(); cachedMidpoints = []; rafPending = false;
                renderFieldListAfterDrag();
            });

            childRow.addEventListener('dragover', function (e) {
                var gi           = parseInt(row.dataset.idx, 10);
                var isDragCanvas = dragSrcIdx !== null &&
                                   state.fields[dragSrcIdx] &&
                                   state.fields[dragSrcIdx].type !== 'group';
                var isDragChild  = dragChildSrc !== null &&
                                   dragChildSrc.groupIdx === gi &&
                                   dragChildSrc.childIdx !== ci;
                if (!isDragCanvas && !isDragChild) return;

                var ch   = (state.fields[gi] && state.fields[gi].children) || [];
                var rect = childRow.getBoundingClientRect();
                var pct  = (e.clientX - rect.left) / rect.width;

                /* Target is already in a 2-col pair — block side drop, let center bubble */
                if (ch[ci] && ch[ci].cols === 6) {
                    if (pct < 0.25 || pct > 0.75) {
                        e.preventDefault(); e.stopPropagation();
                        clearGroupDropIndicators();
                        childDropSideMode = null; childDropTgtIdx = null;
                    }
                    return;
                }

                if (pct < 0.25) {
                    e.preventDefault(); e.stopPropagation();
                    clearGroupDropIndicators();
                    childRow.classList.add('fabricator-drop-left');
                    activeGroupDropIndicatorEl = childRow;
                    childDropSideMode = 'left'; childDropTgtIdx = ci;
                    childGroupDropTarget = null;
                    hideDropLine();
                    zone.classList.remove('fabricator-group-zone--hover');
                } else if (pct > 0.75) {
                    e.preventDefault(); e.stopPropagation();
                    clearGroupDropIndicators();
                    childRow.classList.add('fabricator-drop-right');
                    activeGroupDropIndicatorEl = childRow;
                    childDropSideMode = 'right'; childDropTgtIdx = ci;
                    childGroupDropTarget = null;
                    hideDropLine();
                    zone.classList.remove('fabricator-group-zone--hover');
                } else {
                    /* Center: clear side indicators, let event bubble to zone for drop line */
                    if (childDropTgtIdx === ci) {
                        clearGroupDropIndicators();
                        childDropSideMode = null; childDropTgtIdx = null;
                    }
                }
            });

            childRow.addEventListener('dragleave', function (e) {
                if (!childRow.contains(e.relatedTarget) && childDropTgtIdx === ci) {
                    clearGroupDropIndicators();
                    childDropSideMode = null; childDropTgtIdx = null;
                }
            });

            childRow.addEventListener('drop', function (e) {
                var gi = parseInt(row.dataset.idx, 10);

                /* Side-drop: main canvas field → group child as 2-col pair */
                if (dragSrcIdx !== null && childDropSideMode !== null && childDropTgtIdx === ci) {
                    var srcF = state.fields[dragSrcIdx];
                    if (!srcF || NO_GROUP_TYPES.indexOf(srcF.type) !== -1) return;
                    e.preventDefault(); e.stopPropagation();
                    hideDropLine(); zone.classList.remove('fabricator-group-zone--hover');
                    var from  = dragSrcIdx;
                    var mode  = childDropSideMode;
                    var tci   = childDropTgtIdx;
                    dragSrcIdx = null; dragBrokenPartnerIdx = null;
                    childDropSideMode = null; childDropTgtIdx = null; childGroupDropTarget = null;
                    clearGroupDropIndicators(); clearDropIndicators();
                    var moved   = state.fields.splice(from, 1)[0];
                    moved.cols  = 6;
                    var actualGi = from < gi ? gi - 1 : gi;
                    var chArr   = state.fields[actualGi].children;
                    chArr[tci].cols = 6;
                    if (mode === 'left') { chArr.splice(tci, 0, moved); }
                    else                 { chArr.splice(tci + 1, 0, moved); }
                    repairGroupPairs(actualGi);
                    repairPairs();
                    dropSideMode = null; dropSideTargetIdx = null;
                    dropLineTarget = null; cachedMidpoints = []; rafPending = false;
                    var lEl = document.getElementById('fabricator-field-list');
                    if (lEl) lEl.classList.remove('fabricator-drag-active');
                    renderFieldList();
                    return;
                }

                /* Side-drop: child within group as 2-col pair */
                if (dragChildSrc !== null && childDropSideMode !== null && childDropTgtIdx === ci) {
                    if (dragChildSrc.groupIdx !== gi) return;
                    e.preventDefault(); e.stopPropagation();
                    var src  = dragChildSrc;
                    var mode2 = childDropSideMode;
                    var tci2  = childDropTgtIdx;
                    dragChildSrc = null; childDropSideMode = null;
                    childDropTgtIdx = null; childGroupDropTarget = null;
                    clearGroupDropIndicators();
                    var chArr2 = state.fields[gi].children;
                    var item   = chArr2.splice(src.childIdx, 1)[0];
                    if (src.childIdx < tci2) tci2--;
                    item.cols = 6; chArr2[tci2].cols = 6;
                    if (mode2 === 'left') { chArr2.splice(tci2, 0, item); }
                    else                  { chArr2.splice(tci2 + 1, 0, item); }
                    repairGroupPairs(gi);
                    var listEl2 = document.getElementById('fabricator-field-list');
                    if (listEl2) listEl2.classList.remove('fabricator-drag-active');
                    hideDropLine(); cachedMidpoints = []; rafPending = false;
                    renderFieldList();
                }
            });

            childList.appendChild(childRow);
        });
    }
    renderChildList();

    hdr.querySelector('.fabricator-row-edit').addEventListener('click', function (e) {
        e.stopPropagation();
        openSettingsModal(parseInt(row.dataset.idx, 10), null);
    });
    hdr.querySelector('.fabricator-row-delete').addEventListener('click', function (e) {
        e.stopPropagation();
        state.fields.splice(parseInt(row.dataset.idx, 10), 1);
        renderFieldList();
    });
    addChildBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        fieldPickerTargetGroup = { groupIdx: parseInt(row.dataset.idx, 10), onAdd: renderChildList };
        openFieldPickerModal();
    });
    /* Clicking the empty zone itself also opens picker */
    zone.addEventListener('click', function (e) {
        if (e.target === zone || e.target === childList) {
            addChildBtn.click();
        }
    });

    /* ---- Zone: accepts drops from main canvas AND handles child reorder within group ---- */
    zone.addEventListener('dragover', function (e) {
        if (dragSrcIdx !== null) {
            var srcField = state.fields[dragSrcIdx];
            if (!srcField || NO_GROUP_TYPES.indexOf(srcField.type) !== -1) return;
            e.preventDefault(); e.stopPropagation();
            dropLineTarget = null; /* prevent applyDropLine from overriding group line */
            zone.classList.add('fabricator-group-zone--hover');
            var bef = childBeforeY(e.clientY);
            childGroupDropTarget = bef ? { beforeEl: bef } : { atEnd: true };
            showGroupDropLine(childList, bef);
        } else if (dragChildSrc !== null) {
            e.preventDefault(); e.stopPropagation();
            var bef2 = childBeforeY(e.clientY);
            childGroupDropTarget = bef2 ? { beforeEl: bef2 } : { atEnd: true };
            showGroupDropLine(childList, bef2);
        }
    });

    zone.addEventListener('dragleave', function (e) {
        if (!zone.contains(e.relatedTarget)) {
            zone.classList.remove('fabricator-group-zone--hover');
            childGroupDropTarget = null;
            hideDropLine();
        }
    });

    zone.addEventListener('drop', function (e) {
        var currentGi = parseInt(row.dataset.idx, 10);
        zone.classList.remove('fabricator-group-zone--hover');
        clearGroupDropIndicators();
        hideDropLine();

        if (dragSrcIdx !== null) {
            var srcField = state.fields[dragSrcIdx];
            if (!srcField || NO_GROUP_TYPES.indexOf(srcField.type) !== -1) return;
            /* Side drops on child rows are already handled by child drop (stopPropagation) */
            e.preventDefault(); e.stopPropagation();
            var from     = dragSrcIdx;
            var moved    = state.fields.splice(from, 1)[0];
            moved.cols   = 12;
            var actualGi = from < currentGi ? currentGi - 1 : currentGi;
            if (!state.fields[actualGi].children) state.fields[actualGi].children = [];
            var ins = childInsertIdx(actualGi, childGroupDropTarget);
            state.fields[actualGi].children.splice(ins, 0, moved);
            childGroupDropTarget = null;
            dragSrcIdx = null; dragBrokenPartnerIdx = null;
            dropSideMode = null; dropSideTargetIdx = null;
            dropLineTarget = null; cachedMidpoints = []; rafPending = false;
            clearDropIndicators();
            var lEl = document.getElementById('fabricator-field-list');
            if (lEl) lEl.classList.remove('fabricator-drag-active');
            repairPairs();
            renderFieldList();
            return;
        }

        if (dragChildSrc !== null) {
            e.preventDefault(); e.stopPropagation();
            var src = dragChildSrc;
            dragChildSrc = null; childDropSideMode = null; childDropTgtIdx = null;
            if (src.groupIdx === currentGi) {
                var chArr = state.fields[currentGi].children;
                var itm   = chArr.splice(src.childIdx, 1)[0];
                itm.cols  = 12;
                var ins2  = childInsertIdx(currentGi, childGroupDropTarget);
                if (src.childIdx < ins2) ins2--;
                chArr.splice(ins2, 0, itm);
                repairGroupPairs(currentGi);
            }
            childGroupDropTarget = null;
            var lEl2 = document.getElementById('fabricator-field-list');
            if (lEl2) lEl2.classList.remove('fabricator-drag-active');
            cachedMidpoints = []; rafPending = false;
            renderFieldList();
            return;
        }
    });

    /* ---- Drag events (full-width only, no side-drop) ---- */
    row.addEventListener('dragover', function (e) {
        if (dragSrcIdx === null) return;
        if (dropSideMode !== null) {
            clearDropIndicators();
            dropSideMode = null;
            dropSideTargetIdx = null;
        }
    });
    row.addEventListener('dragstart', function (e) {
        dragSrcIdx           = parseInt(row.dataset.idx, 10);
        dragBrokenPartnerIdx = null;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(dragSrcIdx));
        setTimeout(function () {
            row.style.display = 'none';
            row.classList.add('fabricator-row-dragging');
            var listEl = document.getElementById('fabricator-field-list');
            if (listEl) listEl.classList.add('fabricator-drag-active');
            cacheMidpoints();
        }, 0);
    });
    row.addEventListener('dragend', function () {
        repairPairs();
        dragSrcIdx = null; dragBrokenPartnerIdx = null;
        dropSideMode = null; dropSideTargetIdx = null;
        dropLineTarget = null; cachedMidpoints = []; rafPending = false;
        clearDropIndicators(); hideDropLine();
        var listEl = document.getElementById('fabricator-field-list');
        if (listEl) listEl.classList.remove('fabricator-drag-active');
        renderFieldListAfterDrag();
    });

    return row;
}

function buildChildRow(child, childIdx, groupIdx, onRerender) {
    var row = document.createElement('div');
    row.className    = 'fabricator-child-row';
    row.dataset.type = child.type || '';

    var pal       = findPaletteItem(child.type);
    var icon      = pal ? pal.icon  : 'fa-solid fa-square';
    var typeLabel = pal ? pal.label : child.type;
    row.innerHTML =
        '<div class="fabricator-child-row-icon"><i class="' + escHtml(icon) + '"></i></div>' +
        '<div class="fabricator-child-row-info">' +
            '<div class="fabricator-child-row-label">' + escHtml(child.label || (_i18n.noLabel || '(no label)')) + condBadgeHtml(child) + '</div>' +
            '<div class="fabricator-child-row-type">'  + escHtml(typeLabel) + '</div>' +
        '</div>' +
        '<div class="fabricator-row-actions">' +
            '<button class="fabricator-row-btn fabricator-row-edit"   title="' + escHtml(_i18n.edit || 'Edit') + '"><i class="fa-solid fa-pen-to-square"></i></button>' +
            '<button class="fabricator-row-btn fabricator-row-delete" title="' + escHtml(_i18n.remove || 'Remove') + '"><i class="fa-solid fa-trash"></i></button>' +
        '</div>';

    row.addEventListener('click', function (e) {
        if (e.target.closest('.fabricator-row-delete')) return;
        openSettingsModal(groupIdx, { groupIdx: groupIdx, childIdx: childIdx });
    });
    row.querySelector('.fabricator-row-edit').addEventListener('click', function (e) {
        e.stopPropagation();
        openSettingsModal(groupIdx, { groupIdx: groupIdx, childIdx: childIdx });
    });
    row.querySelector('.fabricator-row-delete').addEventListener('click', function (e) {
        e.stopPropagation();
        state.fields[groupIdx].children.splice(childIdx, 1);
        if (onRerender) onRerender();
    });

    return row;
}

/* Drag & Drop */
function initDragDrop() {
    var list = document.getElementById('fabricator-field-list');
    if (!list) return;

    /* A drop target cancels dragenter as well as dragover, or WebKit never delivers the drop. Covers the group zones
       and child lists inside the list too. */
    list.addEventListener('dragenter', function (e) {
        if (dragSrcIdx === null && dragChildSrc === null) return;
        e.preventDefault();
    });

    list.addEventListener('dragover', function (e) {
        if (dragSrcIdx === null && dragChildSrc === null) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        /* dragover can fire many times per frame; stash the latest Y and let one pending rAF flush it. */
        pendingY = e.clientY;
        if (!rafPending) {
            rafPending = true;
            requestAnimationFrame(applyDropLine);
        }
    });

    list.addEventListener('dragleave', function (e) {
        if (!list.contains(e.relatedTarget)) {
            hideDropLine();
            clearDropIndicators();
            dropSideMode      = null;
            dropSideTargetIdx = null;
            dropLineTarget    = null;
        }
    });

    list.addEventListener('drop', function (e) {
        e.preventDefault();
        rafPending = false;
        hideDropLine();
        list.classList.remove('fabricator-drag-active');

        /* dragChildSrc set here means a group child was dropped on the top-level list — extract it. */
        if (dragChildSrc !== null) {
            clearGroupDropIndicators();
            if (!dropLineTarget) { dragChildSrc = null; cachedMidpoints = []; return; }
            var csrc = dragChildSrc;
            dragChildSrc = null;
            childDropSideMode = null; childDropTgtIdx = null;
            var cgf = state.fields[csrc.groupIdx];
            if (!cgf || !cgf.children) { dropLineTarget = null; cachedMidpoints = []; renderFieldList(); return; }
            var childField = cgf.children.splice(csrc.childIdx, 1)[0];
            if (!childField) { dropLineTarget = null; cachedMidpoints = []; renderFieldList(); return; }
            childField.cols = 12;
            var cInsert;
            if (dropLineTarget.atEnd) {
                cInsert = state.fields.length;
            } else {
                cInsert = parseInt(dropLineTarget.beforeEl.dataset.idx, 10);
            }
            dropLineTarget = null; cachedMidpoints = [];
            state.fields.splice(cInsert, 0, childField);
            repairGroupPairs(csrc.groupIdx);
            repairPairs();
            renderFieldList();
            return;
        }

        if (dragSrcIdx === null || !dropLineTarget) {
            dragSrcIdx     = null;
            dropLineTarget = null;
            cachedMidpoints = [];
            return;
        }

        var from = dragSrcIdx;
        dragSrcIdx           = null;
        dragBrokenPartnerIdx = null; /* drop handled — don't restore pair in dragend */
        cachedMidpoints      = [];

        var insertIdx;
        if (dropLineTarget.atEnd) {
            insertIdx = state.fields.length;
        } else {
            insertIdx = parseInt(dropLineTarget.beforeEl.dataset.idx, 10);
        }

        /* insertIdx was read pre-splice; decrement if target was after the spliced-out source. */
        if (from < insertIdx) { insertIdx--; }
        dropLineTarget = null;
        if (insertIdx === from) { renderFieldList(); return; }

        var item = state.fields.splice(from, 1)[0];
        item.cols = 12; /* reset to full-width on regular drop */
        state.fields.splice(insertIdx, 0, item);
        repairPairs();
        renderFieldList();
    });
}

/* Must run after every state.fields mutation to reset any cols===6 field left without its adjacent pair partner. */
/* Reset any cols=6 field that no longer has a consecutive partner */
function repairPairs() {
    var i = 0;
    while (i < state.fields.length) {
        if (state.fields[i].cols === 6) {
            if (i + 1 < state.fields.length && state.fields[i + 1].cols === 6) {
                i += 2; /* valid pair — skip both */
            } else {
                state.fields[i].cols = 12; /* orphan — reset */
                i++;
            }
        } else {
            i++;
        }
    }
}

function repairGroupPairs(groupIdx) {
    var children = (state.fields[groupIdx] && state.fields[groupIdx].children) || [];
    var i = 0;
    while (i < children.length) {
        if (children[i].cols === 6) {
            if (i + 1 < children.length && children[i + 1].cols === 6) { i += 2; }
            else { children[i].cols = 12; i++; }
        } else { i++; }
    }
}

var activeDropIndicatorEl      = null;
var activeGroupDropIndicatorEl = null;

function clearDropIndicators() {
    if (activeDropIndicatorEl) {
        activeDropIndicatorEl.classList.remove('fabricator-drop-left', 'fabricator-drop-right');
        activeDropIndicatorEl = null;
    }
}

function clearGroupDropIndicators() {
    if (activeGroupDropIndicatorEl) {
        activeGroupDropIndicatorEl.classList.remove('fabricator-drop-left', 'fabricator-drop-right');
        activeGroupDropIndicatorEl = null;
    }
}

function applyDropLine() {
    rafPending = false;
    if ((dragSrcIdx === null && dragChildSrc === null) || dropSideMode !== null) return;
    var list = document.getElementById('fabricator-field-list');
    if (!list) return;
    /* The cache is relative to the list's content, which scrolls (the list's own or the page's) during the drag. */
    var beforeEl = getAfterFromCache(pendingY - list.getBoundingClientRect().top + list.scrollTop);
    dropLineTarget = beforeEl ? { beforeEl: beforeEl } : { atEnd: true };
    showDropLine(list, beforeEl);
}

/* y: relative to the top of the field list's content, as cacheMidpoints() stores the midpoints. */
function getAfterFromCache(y) {
    var closest = null, closestDist = Infinity;
    for (var i = 0; i < cachedMidpoints.length; i++) {
        var entry = cachedMidpoints[i];
        if (y < entry.midY) {
            var dist = entry.midY - y;
            if (dist < closestDist) { closestDist = dist; closest = entry.el; }
        }
    }
    return closest;
}

function showDropLine(list, beforeEl) {
    if (!dropLineEl) {
        dropLineEl = document.createElement('div');
        dropLineEl.className = 'fabricator-drop-line';
        document.body.appendChild(dropLineEl);
    }
    var listRect = list.getBoundingClientRect();
    var y;
    if (beforeEl) {
        y = beforeEl.getBoundingClientRect().top; /* CSS translateY(-50%) centres it in the gap */
    } else {
        var rows = list.querySelectorAll('.fabricator-field-row:not(.fabricator-row-dragging)');
        y = rows.length
            ? rows[rows.length - 1].getBoundingClientRect().bottom + 2
            : listRect.top;
    }
    dropLineEl.style.top     = y + 'px';
    dropLineEl.style.left    = listRect.left + 'px';
    dropLineEl.style.width   = listRect.width + 'px';
    dropLineEl.style.display = 'block';
}

function hideDropLine() {
    if (dropLineEl) { dropLineEl.style.display = 'none'; }
}

function showGroupDropLine(childListEl, beforeEl) {
    if (!dropLineEl) {
        dropLineEl = document.createElement('div');
        dropLineEl.className = 'fabricator-drop-line';
        document.body.appendChild(dropLineEl);
    }
    var listRect = childListEl.getBoundingClientRect();
    var y;
    if (beforeEl) {
        y = beforeEl.getBoundingClientRect().top;
    } else {
        var rows = childListEl.querySelectorAll('.fabricator-child-row');
        y = rows.length
            ? rows[rows.length - 1].getBoundingClientRect().bottom + 2
            : listRect.bottom;
    }
    dropLineEl.style.top     = y + 'px';
    dropLineEl.style.left    = listRect.left + 'px';
    dropLineEl.style.width   = listRect.width + 'px';
    dropLineEl.style.display = 'block';
}

function isSecondInGroupPair(groupIdx, childIdx) {
    var children = (state.fields[groupIdx] && state.fields[groupIdx].children) || [];
    if (!children[childIdx] || children[childIdx].cols !== 6) return false;
    var count = 0;
    for (var j = childIdx - 1; j >= 0 && children[j] && children[j].cols === 6; j--) count++;
    return count % 2 === 1;
}

function isSecondInPair(idx) {
    if (!state.fields[idx] || state.fields[idx].cols !== 6) return false;
    var count = 0;
    for (var j = idx - 1; j >= 0 && state.fields[j] && state.fields[j].cols === 6; j--) count++;
    return count % 2 === 1;
}

function cacheMidpoints() {
    var list = document.getElementById('fabricator-field-list');
    if (!list) return;
    cachedMidpoints = [];
    /* Relative to the list's content, not the viewport: a drag near the list's edge scrolls it (or the page), and
       positions taken in the viewport at drag start would then put every row that scrolled into view where it no
       longer is. */
    var listTop = list.getBoundingClientRect().top - list.scrollTop;
    var rows = list.querySelectorAll('.fabricator-field-row:not(.fabricator-row-dragging)');
    for (var i = 0; i < rows.length; i++) {
        var r   = rows[i];
        var idx = parseInt(r.dataset.idx, 10);
        if (isSecondInPair(idx)) continue;
        var box = r.getBoundingClientRect();
        cachedMidpoints.push({ el: r, midY: box.top + box.height / 2 - listTop });
    }
}

/* Add-field button */
function bindAddFieldButton() {
    var btn = document.getElementById('fabricator-add-field-btn');
    if (btn) btn.addEventListener('click', openFieldPickerModal);
}

/* Field Picker Modal */
function createFieldPickerModal() {
    fieldPickerModal           = document.createElement('div');
    fieldPickerModal.id        = 'fabricator-field-modal';
    fieldPickerModal.className = 'fabricator-modal-backdrop';
    fieldPickerModal.hidden    = true;
    fieldPickerModal.innerHTML =
        '<div class="fabricator-modal" role="dialog" aria-modal="true">' +
            '<div class="fabricator-modal-header">' +
                '<h2 class="fabricator-modal-title">' + escHtml(_i18n.addFieldTitle || 'Add field') + '</h2>' +
                '<button class="fabricator-modal-close" type="button">&#x2715;</button>' +
            '</div>' +
            '<div class="fabricator-modal-search">' +
                '<input id="fabricator-field-search" type="text" placeholder="' + escHtml(_i18n.searchFieldType || 'Search field type…') + '" autocomplete="off" />' +
            '</div>' +
            '<div class="fabricator-modal-body" id="fabricator-field-modal-body"></div>' +
        '</div>';
    document.body.appendChild(fieldPickerModal);

    fieldPickerModal.querySelector('.fabricator-modal-close').addEventListener('click', closeFieldPickerModal);
    fieldPickerModal.addEventListener('click', function (e) { if (e.target === fieldPickerModal) closeFieldPickerModal(); });
    fieldPickerModal.querySelector('#fabricator-field-search').addEventListener('input', function () {
        renderFieldPickerGroups(this.value);
    });

    /* Single delegated click listener on the body — no per-card listeners. */
    var pickerBody = fieldPickerModal.querySelector('#fabricator-field-modal-body');
    pickerBody.addEventListener('click', function (e) {
        var card = e.target.closest('.fabricator-modal-card');
        if (!card) return;
        var type = card.dataset.type;
        if (!type) return;
        var tgt = fieldPickerTargetGroup;
        closeFieldPickerModal();
        addField(type, tgt);
    });
}

function buildPickerFragment(groups, filterFn) {
    var frag = document.createDocumentFragment();
    var hasItems = false;
    groups.forEach(function (group) {
        var items = group.items.filter(filterFn);
        if (!items.length) return;
        hasItems = true;
        var hdr = document.createElement('div');
        hdr.className = 'fabricator-modal-group-label';
        if (group.color) {
            hdr.innerHTML = '<span class="fabricator-palette-dot" style="background:' + escHtml(group.color) + '"></span>' + escHtml(group.label);
        } else {
            hdr.textContent = group.label;
        }
        frag.appendChild(hdr);
        var grid = document.createElement('div');
        grid.className = 'fabricator-modal-grid';
        items.forEach(function (item) {
            var card = document.createElement('button');
            card.type          = 'button';
            card.className     = 'fabricator-modal-card';
            card.dataset.type  = item.type;
            card.innerHTML =
                '<span class="fabricator-modal-card-icon"><i class="' + escHtml(item.icon) + '"></i></span>' +
                '<span class="fabricator-modal-card-label">' + escHtml(item.label) + '</span>';
            grid.appendChild(card);
        });
        frag.appendChild(grid);
    });
    return hasItems ? frag : null;
}

function renderFieldPickerGroups(query) {
    var body = document.getElementById('fabricator-field-modal-body');
    if (!body) return;
    body.innerHTML = '';
    var q = query.toLowerCase().trim();

    if (!q) {
        /* Keyed by context so a group-picker open doesn't reuse the top-level cached DOM (which has the "Feldgruppe" card). */
        var isGroupCtx = fieldPickerTargetGroup !== null;
        if (!fieldPickerCachedBody || fieldPickerCacheIsGroupCtx !== isGroupCtx) {
            fieldPickerCachedBody = buildPickerFragment(PALETTE_GROUPS, function (item) {
                return !(isGroupCtx && NO_GROUP_TYPES.indexOf(item.type) !== -1);
            });
            fieldPickerCacheIsGroupCtx = isGroupCtx;
        }
        if (fieldPickerCachedBody) {
            body.appendChild(fieldPickerCachedBody.cloneNode(true));
        }
    } else {
        var frag = buildPickerFragment(PALETTE_GROUPS, function (item) {
            if (fieldPickerTargetGroup !== null && NO_GROUP_TYPES.indexOf(item.type) !== -1) return false;
            return item.label.toLowerCase().indexOf(q) !== -1 || item.type.toLowerCase().indexOf(q) !== -1;
        });
        if (frag) {
            body.appendChild(frag);
        }
    }

    if (!body.children.length) {
        var msg = document.createElement('p');
        msg.className   = 'fabricator-modal-empty';
        msg.textContent = _i18n.noFieldsFound || 'No fields found.';
        body.appendChild(msg);
    }
}

function openFieldPickerModal() {
    if (!fieldPickerModal) return;
    renderFieldPickerGroups('');
    fieldPickerModal.hidden = false;
    document.body.style.overflow = 'hidden';
    var inp = fieldPickerModal.querySelector('#fabricator-field-search');
    if (inp) { inp.value = ''; setTimeout(function () { inp.focus(); }, 50); }
    document.addEventListener('keydown', onFieldPickerEsc);
}
function closeFieldPickerModal() {
    if (!fieldPickerModal) return;
    fieldPickerModal.hidden = true;
    document.body.style.overflow = '';
    fieldPickerTargetGroup = null;
    document.removeEventListener('keydown', onFieldPickerEsc);
}
function onFieldPickerEsc(e) { if (e.key === 'Escape') closeFieldPickerModal(); }

function addField(type, targetGroup) {
    var pal = findPaletteItem(type);
    /* Deep-clone: pal.defaults is reused per field type, so nested values must not be shared by reference. */
    var field = pal && pal.defaults ? JSON.parse(JSON.stringify(pal.defaults)) : {};
    field.id   = generateId(type);
    field.type = type;
    if (!field.label) field.label = pal ? pal.label : type;

    if (targetGroup !== null && targetGroup !== undefined) {
        /* Adding a child field into a group */
        var ctx = targetGroup;
        var gf = state.fields[ctx.groupIdx];
        if (!gf) return;
        if (!gf.children) gf.children = [];
        /* A page break separates pages, so it cannot sit inside a group. */
        if (pal && pal.noNesting) return;
        var childIdx = gf.children.length;
        gf.children.push(field);
        renderFieldList();
        openSettingsModal(ctx.groupIdx, { groupIdx: ctx.groupIdx, childIdx: childIdx });
    } else {
        /* Regular top-level add */
        if (type === 'group' && !field.children) { field.children = []; }
        state.fields.push(field);
        renderFieldList();
        openSettingsModal(state.fields.length - 1, null);
    }
}

/* Settings Modal */
function createSettingsModal() {
    settingsModal           = document.createElement('div');
    settingsModal.id        = 'fabricator-settings-modal';
    settingsModal.className = 'fabricator-modal-backdrop';
    settingsModal.hidden    = true;
    settingsModal.innerHTML =
        '<div class="fabricator-modal fabricator-modal--settings" role="dialog" aria-modal="true">' +
            '<div class="fabricator-modal-header">' +
                '<div class="fabricator-settings-titlerow">' +
                    '<span class="fabricator-settings-field-icon"></span>' +
                    '<h2 class="fabricator-modal-title" id="fabricator-sp-title">' + escHtml(_i18n.fieldSettingsTitle || 'Field settings') + '</h2>' +
                '</div>' +
                '<button class="fabricator-field-id-chip" id="fabricator-sp-id-chip" type="button" title="' + escHtml(_i18n.copyFieldId || 'Copy field ID') + '"></button>' +
                '<button class="fabricator-modal-close" type="button">&#x2715;</button>' +
            '</div>' +
            '<div class="fabricator-stab-bar">' +
                '<button class="fabricator-stab fabricator-stab-active" data-stab="general">' + escHtml(_i18n.tabGeneral || 'General') + '</button>' +
                '<button class="fabricator-stab" data-stab="advanced">' + escHtml(_i18n.tabAdvanced || 'Advanced') + '</button>' +
                '<button class="fabricator-stab" data-stab="conditions">' + escHtml(_i18n.tabConditions || 'Conditions') + '</button>' +
            '</div>' +
            '<div class="fabricator-modal-body fabricator-settings-body">' +
                '<div id="fabricator-stab-general"    class="fabricator-stab-panel fabricator-stab-active"></div>' +
                '<div id="fabricator-stab-advanced"   class="fabricator-stab-panel"></div>' +
                '<div id="fabricator-stab-conditions" class="fabricator-stab-panel"></div>' +
            '</div>' +
            '<div class="fabricator-settings-footer">' +
                '<button class="fabricator-btn-primary" id="fabricator-sp-done">' + escHtml(_i18n.done || 'Done') + '</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(settingsModal);

    settingsModal.querySelector('.fabricator-modal-close').addEventListener('click', closeSettingsModal);
    settingsModal.querySelector('#fabricator-sp-done').addEventListener('click', closeSettingsModal);
    settingsModal.addEventListener('click', function (e) { if (e.target === settingsModal) closeSettingsModal(); });

    /* Cache tab buttons and panels once — they never change after creation. */
    var stabBtns   = Array.from(settingsModal.querySelectorAll('.fabricator-stab'));
    var stabPanels = Array.from(settingsModal.querySelectorAll('.fabricator-stab-panel'));

    stabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            stabBtns.forEach(function (b)   { b.classList.remove('fabricator-stab-active'); });
            stabPanels.forEach(function (p) { p.classList.remove('fabricator-stab-active'); });
            btn.classList.add('fabricator-stab-active');
            var panel = document.getElementById('fabricator-stab-' + btn.dataset.stab);
            if (panel) panel.classList.add('fabricator-stab-active');
        });
    });
    settingsModal._stabBtns   = stabBtns;
    settingsModal._stabPanels = stabPanels;
}

function openSettingsModal(idx, ctx) {
    if (!settingsModal) createSettingsModal();
    if (!settingsModal || !state.fields[idx]) return;
    settingsCtx      = ctx || null;
    settingsModalIdx = idx;

    var field = ctx
        ? (state.fields[ctx.groupIdx] && state.fields[ctx.groupIdx].children
            ? state.fields[ctx.groupIdx].children[ctx.childIdx] : null)
        : state.fields[idx];
    if (!field) return;

    var pal = findPaletteItem(field.type);
    if (pal && pal.noPanel) return;

    settingsModal._stabBtns.forEach(function (b)   { b.classList.remove('fabricator-stab-active'); });
    settingsModal._stabPanels.forEach(function (p)  { p.classList.remove('fabricator-stab-active'); });
    settingsModal._stabBtns[0].classList.add('fabricator-stab-active');   /* "Allgemein" is always first */
    settingsModal._stabPanels[0].classList.add('fabricator-stab-active');

    /* A tab whose settings the renderer would ignore stays out of reach, rather than storing choices nothing reads. */
    settingsModal._stabBtns.forEach(function (b) {
        if (b.dataset.stab === 'conditions') {
            b.hidden = !!(pal && pal.noConditions);
        }
    });

    var idChip = document.getElementById('fabricator-sp-id-chip');
    if (idChip) {
        idChip.textContent = '{' + field.id + '}';
        idChip.onclick = function () {
            var currentId = '{' + (ctx ? state.fields[ctx.groupIdx].children[ctx.childIdx] : state.fields[idx]).id + '}';
            navigator.clipboard.writeText(currentId).then(function () {
                var prev = idChip.innerHTML;
                idChip.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> ' + escHtml(_i18n.copied || 'copied');
                setTimeout(function () { idChip.innerHTML = prev; }, 1500);
            });
        };
    }

    buildSettingsTabs(idx, field);
    settingsModal.hidden = false;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onSettingsEsc);
}

function closeSettingsModal() {
    if (!settingsModal) return;
    settingsModal.hidden = true;
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onSettingsEsc);
    settingsCtx      = null;
    settingsModalIdx = null;
    renderFieldList();
}
function onSettingsEsc(e) { if (e.key === 'Escape') closeSettingsModal(); }

function buildSettingsTabs(idx, overrideField) {
    var field = overrideField || state.fields[idx];
    var pal   = findPaletteItem(field.type);

    document.getElementById('fabricator-sp-title').textContent =
        (pal ? pal.label : field.type) + ' ' + (_i18n.editFieldSuffix || 'Edit');
    settingsModal.querySelector('.fabricator-settings-field-icon').innerHTML =
        '<i class="' + escHtml(pal ? pal.icon : 'fa-solid fa-square') + '"></i>';

    buildGeneralTab(idx, field, pal);
    buildAdvancedTab(idx, field);
    buildConditionsTab(idx, field);
}

/* Whether a schema entry's depends_on holds for fld:
   {key, is: v}  — shown when the setting equals v;
   {key, not: v} — shown when it differs from v;
   {someKey: b}  — shown when the setting's truthiness equals b.
   'default' stands in for a setting the field was saved without (a field older than the setting). */
function dependsOnMet(dep, fld) {
    if (!dep) return true;
    if (dep.is !== undefined || dep.not !== undefined) {
        var v = fld[dep.key];
        if ((v === undefined || v === '') && dep['default'] !== undefined) v = dep['default'];
        return dep.is !== undefined ? v === dep.is : v !== dep.not;
    }
    var depKey = Object.keys(dep)[0];
    return !!fld[depKey] === !!dep[depKey];
}

/* ---- General tab ---- */
function buildGeneralTab(idx, field, pal) {
    var panel  = document.getElementById('fabricator-stab-general');
    panel.innerHTML = '';
    var schema = (pal && pal.generalSchema) ? pal.generalSchema : [];

    function change(key, val) {
        markDirty();
        if (settingsCtx) {
            /* Writing to a group child field */
            var gf = state.fields[settingsCtx.groupIdx];
            if (gf && gf.children) gf.children[settingsCtx.childIdx][key] = val;
        } else {
            state.fields[idx][key] = val;
            if (key === 'label') {
                var rowEl = document.querySelector('.fabricator-field-row[data-idx="' + idx + '"] .fabricator-row-label');
                if (rowEl) {
                    var badge = rowEl.querySelector('.fabricator-cond-badge');
                    rowEl.textContent = val || (_i18n.noLabel || '(no label)');
                    if (badge) rowEl.appendChild(badge);
                }
            }
        }
    }

    spRow(panel, 'label', _i18n.labelField || 'Label', 'text', field.label || '', function (v) { change('label', v); });
    spCheckbox(panel, 'hide_label', _i18n.hideLabel || 'Hide label', !!field.hide_label, function (v) { change('hide_label', v); });

    /* Hide global "Pflichtfeld" for fields that manage required per sub-component instead. */
    var hasActiveSubfields = schema.some(function (s) {
        return s.type === 'subfields' && dependsOnMet(s.depends_on, field);
    });
    var hideRequired = (pal && pal.noRequired) || hasActiveSubfields;
    if (!hideRequired) {
        spCheckbox(panel, 'required', _i18n.requiredField || 'Required field', !!field.required, function (v) { change('required', v); });
    }

    /* The field this panel edits, which for a group child is the child: reading state.fields[idx] took the group's
       own settings, so its depends_on checks and rebuild refreshes wrote group values into the child. */
    function editedField() {
        if (!settingsCtx) return state.fields[idx];
        var gf = state.fields[settingsCtx.groupIdx];
        return (gf && gf.children && gf.children[settingsCtx.childIdx]) || field;
    }
    function refreshGeneralTab() {
        var current = editedField();
        buildGeneralTab(idx, current, findPaletteItem(current.type));
    }

    schema.forEach(function (s) {
        /* depends_on: skip this entry if condition not met */
        if (!dependsOnMet(s.depends_on, editedField())) return;

        var cur = field[s.key] !== undefined ? field[s.key] : (s.default !== undefined ? s.default : '');

        if (s.type === 'section_title') {
            spSectionTitle(panel, s.label);
        } else if (s.type === 'text' || s.type === 'email' || s.type === 'url') {
            (function (schema_entry) {
                spRow(panel, schema_entry.key, schema_entry.label, schema_entry.type, cur, function (v) {
                    change(schema_entry.key, v);
                    if (schema_entry.rebuild) {
                        /* rebuild on blur only — not on every keystroke */
                        var inp = document.getElementById('fabricator-sp-' + schema_entry.key);
                        if (inp && !inp._rebuildBound) {
                            inp._rebuildBound = true;
                            inp.addEventListener('blur', function () {
                                refreshGeneralTab();
                            });
                        }
                    }
                }, schema_entry.hint);
            }(s));
        } else if (s.type === 'textarea') {
            spTextarea(panel, s.key, s.label, cur, function (v) { change(s.key, v); }, s.hint);
        } else if (s.type === 'number') {
            (function (schema_entry) {
                spRow(panel, schema_entry.key, schema_entry.label, 'number', cur,
                    function (v) {
                        change(schema_entry.key, v === '' ? '' : (parseFloat(v) || 0));
                        if (schema_entry.rebuild) {
                            refreshGeneralTab();
                        }
                    }, schema_entry.hint);
            }(s));
        } else if (s.type === 'checkbox') {
            (function (schema_entry) {
                spCheckbox(panel, schema_entry.key, schema_entry.label, !!cur, function (v) {
                    change(schema_entry.key, v);
                    if (schema_entry.rebuild) {
                        refreshGeneralTab();
                    }
                    /* Settings in the Advanced tab can depend on this one (the direct debit mandate's labels). */
                    if (schema_entry.rebuild_advanced) {
                        buildAdvancedTab(idx, editedField());
                    }
                }, schema_entry.disclaimer || '');
            }(s));
        } else if (s.type === 'select') {
            spSelect(panel, s.key, s.label, s.options || [], cur, function (v) { change(s.key, v); });
        } else if (s.type === 'options_list' || s.type === 'options') {
            spOptionsList(panel, s.key, s.label, Array.isArray(cur) ? cur : [], function (v) { change(s.key, v); });
        } else if (s.type === 'page_names_list') {
            spPageNamesList(panel, s.key, s.label, Array.isArray(cur) ? cur : [], function (v) { change(s.key, v); }, s.hint, idx);
        } else if (s.type === 'bool_seg') {
            (function (schema_entry) {
                spBoolSeg(panel, schema_entry.key, schema_entry.label, !!cur,
                    schema_entry.false_label || _i18n.segNo || 'No', schema_entry.true_label || _i18n.segYes || 'Yes',
                    function (v) {
                        change(schema_entry.key, v);
                        if (schema_entry.rebuild) {
                            refreshGeneralTab();
                        }
                    }, !!schema_entry.swap);
            }(s));
        } else if (s.type === 'subfields') {
            spSubfields(panel, s.items || [], field, change);
        } else if (s.type === 'limit_row') {
            (function (schema_entry) {
                var countKey = schema_entry.count_key || (schema_entry.key + '_max');
                spLimitRow(panel, schema_entry.key, schema_entry.label, countKey,
                    field[schema_entry.key] || 'chars',
                    field[countKey] !== undefined ? field[countKey] : '',
                    function (typeVal, countVal) {
                        change(schema_entry.key, typeVal);
                        change(countKey, countVal);
                    });
            }(s));
        } else if (s.type === 'notice') {
            spNotice(panel, s.text || s.label || '', s.level || 'info');
        } else if (s.type === 'html_editor') {
            (function (schema_entry) {
                spHtmlFieldEditor(panel, schema_entry.key, schema_entry.label, cur,
                    function (v) { change(schema_entry.key, v); });
                if (schema_entry.hint) {
                    var htmlHint = document.createElement('p');
                    htmlHint.className   = 'fabricator-sp-hint';
                    htmlHint.textContent = schema_entry.hint;
                    panel.appendChild(htmlHint);
                }
            }(s));
        } else if (s.type === 'icon_row') {
            (function (schema_entry) {
                var halfKey = schema_entry.half_key || 'allow_half';
                var halfCur = !!field[halfKey];
                spIconRow(panel, schema_entry.key, schema_entry.label,
                    schema_entry.options || [], cur, halfKey, halfCur,
                    function (iconVal, halfVal) {
                        change(schema_entry.key, iconVal);
                        change(halfKey, halfVal);
                        if (schema_entry.rebuild) {
                            refreshGeneralTab();
                        }
                    });
            }(s));
        } else if (s.type === 'media_upload') {
            (function (schema_entry) {
                spMediaUpload(panel, schema_entry.key, schema_entry.label, cur, function (v) {
                    change(schema_entry.key, v);
                    if (schema_entry.rebuild) { rebuildPreview(); }
                }, schema_entry.hint || '');
            }(s));
        } else if (s.type === 'rating_preview') {
            spRatingPreview(panel, state.fields[idx]);
        } else if (s.type === 'time_row') {
            (function (schema_entry) {
                spTimeRow(panel,
                    schema_entry.format_key  || 'time_format',
                    schema_entry.prefill_key || 'prefill_now',
                    !!field[schema_entry.format_key  || 'time_format'],
                    !!field[schema_entry.prefill_key || 'prefill_now'],
                    function (fmtVal, prefillVal) {
                        change(schema_entry.format_key  || 'time_format',  fmtVal);
                        change(schema_entry.prefill_key || 'prefill_now', prefillVal);
                    });
            }(s));
        } else if (s.type === 'country_tags') {
            (function (schema_entry) {
                spCountryTags(panel, schema_entry.key, schema_entry.label,
                    Array.isArray(cur) ? cur : [],
                    function (v) { change(schema_entry.key, v); });
            }(s));
        } else if (s.type === 'pill3') {
            (function (schema_entry) {
                var pillRow = document.createElement('div');
                pillRow.className = 'fabricator-sp-row';
                var lbl = document.createElement('div');
                lbl.className   = 'fabricator-sp-label';
                lbl.textContent = schema_entry.label || '';
                pillRow.appendChild(lbl);
                var pill = mkSeg(
                    schema_entry.values || [],
                    schema_entry.labels || schema_entry.values || [],
                    cur,
                    function (v) {
                        change(schema_entry.key, v);
                        if (schema_entry.rebuild) {
                            refreshGeneralTab();
                        }
                        /* The Advanced tab's blocks can depend on this setting too (the direct debit scheme's country filter). */
                        if (schema_entry.rebuild_advanced) {
                            buildAdvancedTab(idx, editedField());
                        }
                    }
                );
                pillRow.appendChild(pill);
                panel.appendChild(pillRow);
            }(s));
        } else if (s.type === 'pill_multi') {
            (function (schema_entry) {
                var pillRow = document.createElement('div');
                pillRow.className = 'fabricator-sp-row';
                var lbl = document.createElement('div');
                lbl.className   = 'fabricator-sp-label';
                lbl.textContent = schema_entry.label || '';
                pillRow.appendChild(lbl);
                var curArr = Array.isArray(cur) ? cur.slice() : (cur ? [cur] : []);
                var pill = mkSegMulti(
                    schema_entry.values || [],
                    schema_entry.labels || schema_entry.values || [],
                    curArr,
                    function (v) { change(schema_entry.key, v); }
                );
                pillRow.appendChild(pill);
                panel.appendChild(pillRow);
            }(s));
        }
    });
}

/* Custom advanced-tab blocks: field-specific UI the schema system can't express; each renders BEFORE its schema-driven entries. */
var FIELD_ADVANCED_BLOCKS = {

    directdebit: function (panel, field, change) {
        /* IBAN countries: SEPA only. A field saved without a scheme is SEPA, as on the server. */
        if ((field.scheme || 'sepa') !== 'sepa') return;
        spSectionTitle(panel, _i18n.countryFilter || 'Country filter');

        var modeRow = document.createElement('div');
        modeRow.className = 'fabricator-sp-row';
        var modeLbl = document.createElement('div');
        modeLbl.className   = 'fabricator-sp-label';
        modeLbl.textContent = _i18n.countryFilter || 'Country filter';
        modeRow.appendChild(modeLbl);

        var listRow = document.createElement('div');

        function updateFilterList() {
            listRow.innerHTML = '';
            if (field['country_filter_mode'] === 'off' || !field['country_filter_mode']) {
                listRow.hidden = true;
                return;
            }
            listRow.hidden = false;
            spCountryTags(listRow, 'country_filter_list', _i18n.countryList || 'Country list',
                Array.isArray(field['country_filter_list']) ? field['country_filter_list'] : [],
                function (v) { change('country_filter_list', v); }
            );
        }

        var modePill = mkSeg(
            ['off', 'allow', 'disallow'],
            [_i18n.off || 'Off', _i18n.allowed || 'Allowed', _i18n.disallowed || 'Disallowed'],
            field['country_filter_mode'] || 'off',
            function (v) { change('country_filter_mode', v); updateFilterList(); }
        );
        modeRow.appendChild(modePill);
        panel.appendChild(modeRow);
        panel.appendChild(listRow);
        updateFilterList();
    },

    phone: function (panel, field, change) {
        spSectionTitle(panel, _i18n.formatValidation || 'Format validation');

        var phoneModeRow = document.createElement('div');
        phoneModeRow.className = 'fabricator-sp-row';

        var phoneExtraSection = document.createElement('div');

        function updatePhoneExtraSection() {
            phoneExtraSection.innerHTML = '';
            var mode = field['phone_mode'] || '';

            if (mode === 'any') {
                var hint = document.createElement('p');
                hint.className   = 'fabricator-sp-hint';
                hint.textContent = _i18n.phoneAnyFormatHint || 'Any valid format: min. 7 digits, optionally with + and country code.';
                phoneExtraSection.appendChild(hint);
                phoneExtraSection.hidden = false;
                return;
            }

            if (mode === 'countries') {
                phoneExtraSection.hidden = false;
                var subRow = document.createElement('div');
                subRow.className = 'fabricator-sp-row';
                var subPill = mkSeg(
                    ['allow', 'disallow'],
                    [_i18n.allowed || 'Allowed', _i18n.disallowed || 'Disallowed'],
                    field['phone_country_mode'] || 'allow',
                    function (v) { change('phone_country_mode', v); }
                );
                subRow.appendChild(subPill);
                phoneExtraSection.appendChild(subRow);
                spCallingCodeTags(phoneExtraSection, 'phone_country_list', _i18n.dialCodes || 'Dial codes',
                    Array.isArray(field['phone_country_list']) ? field['phone_country_list'] : [],
                    function (v) { change('phone_country_list', v); }
                );
                return;
            }

            phoneExtraSection.hidden = true;
        }

        var phonePill = mkSeg(
            ['', 'any', 'countries'],
            [_i18n.off || 'Off', _i18n.any || 'Any', _i18n.countriesMode || 'Countries'],
            field['phone_mode'] || '',
            function (v) { change('phone_mode', v); updatePhoneExtraSection(); }
        );
        phoneModeRow.appendChild(phonePill);
        panel.appendChild(phoneModeRow);
        panel.appendChild(phoneExtraSection);
        updatePhoneExtraSection();
    },

};

/* ---- Advanced tab ---- */
function buildAdvancedTab(idx, field) {
    var panel = document.getElementById('fabricator-stab-advanced');
    panel.innerHTML = '';

    function change(key, val) {
        if (settingsCtx) {
            var gf = state.fields[settingsCtx.groupIdx];
            if (gf && gf.children) gf.children[settingsCtx.childIdx][key] = val;
        } else {
            state.fields[idx][key] = val;
        }
    }

    var idRow = document.createElement('div');
    idRow.className = 'fabricator-sp-row';
    var idLbl = document.createElement('label');
    idLbl.className   = 'fabricator-sp-label';
    idLbl.textContent = _i18n.fieldIdLabel || 'Field ID';
    idLbl.htmlFor     = 'fabricator-sp-field-id';
    idRow.appendChild(idLbl);
    var idInp = document.createElement('input');
    idInp.type       = 'text';
    idInp.id         = 'fabricator-sp-field-id';
    idInp.className  = 'fabricator-sp-input fabricator-sp-field-id';
    idInp.value      = field.id || '';
    idInp.spellcheck = false;
    idInp.addEventListener('input', function () {
        var slug   = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-{2,}/g, '-');
        this.value = slug;
        var target = settingsCtx
            ? state.fields[settingsCtx.groupIdx].children[settingsCtx.childIdx]
            : state.fields[idx];
        var previousId = target.id;
        target.id = slug || generateId(field.type);
        /* Conditions and routing rules keep pointing at the old id otherwise: the editor then shows a different field
           selected, and the server reads the rule's value as empty. */
        renameFieldReferences(previousId, target.id);
        var hintCode = idRow.querySelector('code');
        if (hintCode) hintCode.textContent = '{' + target.id + '}';
        var chip = document.getElementById('fabricator-sp-id-chip');
        if (chip && chip.textContent.trim() !== (_i18n.copied || 'copied')) chip.textContent = '{' + target.id + '}';
        if (!settingsCtx) {
            var rowIdBadge = document.querySelector('.fabricator-field-row[data-idx="' + idx + '"] .fabricator-row-id');
            if (rowIdBadge) rowIdBadge.textContent = '{' + target.id + '}';
        }
    });
    idRow.appendChild(idInp);
    var idHint = document.createElement('p');
    idHint.className = 'fabricator-sp-hint';
    idHint.innerHTML = escHtml(_i18n.useEmailsHint || 'Use in emails:') + ' <code>{' + escHtml(field.id) + '}</code>';
    idRow.appendChild(idHint);
    panel.appendChild(idRow);

    var validOpts = VALIDATION_OPTIONS[field.type];
    if (validOpts) {
        spSectionTitle(panel, _i18n.validationSection || 'Validation');
        spSelect(panel, 'validation', _i18n.validationRule || 'Validation rule',
            validOpts.map(function (o) { return { value: o[0], label: o[1] }; }),
            field.validation || '',
            function (v) { change('validation', v); }
        );
    }

    if (['select','radio','checkbox'].indexOf(field.type) === -1) {
        spSectionTitle(panel, _i18n.autocompleteSection || 'Browser autocomplete');
        spCheckbox(panel, 'autocomplete_on', _i18n.enableBrowserFill || 'Enable browser autofill',
            field.autocomplete_on !== false,
            function (v) { change('autocomplete_on', v); }
        );
        spRow(panel, 'autocomplete', _i18n.autocompleteValue || 'Autocomplete value', 'text',
            field.autocomplete || '',
            function (v) { change('autocomplete', v); },
            _i18n.autocompleteValueHint || 'E.g. "name", "email", "tel". Empty = browser default.'
        );
    }

    spSectionTitle(panel, _i18n.appearanceSection || 'Appearance');
    spRow(panel, 'custom_class', _i18n.cssClasses || 'CSS class(es)', 'text',
        field.custom_class || '',
        function (v) { change('custom_class', v); },
        _i18n.separateClassesHint || 'Separate multiple classes with spaces.'
    );

    /* ---- Schema-driven advanced entries (rendered below Feld-ID; custom blocks render after) ---- */
    var advPal = findPaletteItem(field.type);
    var advSchema = (advPal && advPal.advancedSchema) ? advPal.advancedSchema : [];

    if (advSchema.length) {
        var advField = settingsCtx
            ? (state.fields[settingsCtx.groupIdx].children[settingsCtx.childIdx])
            : state.fields[idx];

        advSchema.forEach(function (s) {
            if (s.type === 'section_title') {
                if (dependsOnMet(s.depends_on, advField)) spSectionTitle(panel, s.label);
                return;
            }
            if (s.type === 'notice') {
                var nEl = document.createElement('div');
                nEl.className = 'fabricator-sp-notice fabricator-sp-notice--' + (s.level || 'info');
                nEl.textContent = s.text || '';
                panel.appendChild(nEl);
                return;
            }

            if (s.type === 'pill3') {
                if (s.label) spSectionTitle(panel, s.label);

                var pillRow = document.createElement('div');
                pillRow.className = 'fabricator-sp-row';
                var curVal = advField[s.key] !== undefined ? advField[s.key] : (s.values ? s.values[0] : '');
                /* Capture s in closure so the onChange sees the right schema entry */
                (function (entry) {
                    var pill = mkSeg(
                        entry.values || [],
                        entry.labels || entry.values || [],
                        curVal,
                        function (v) {
                            change(entry.key, v);
                            rebuildAdvDeps();
                        }
                    );
                    pillRow.appendChild(pill);
                }(s));
                panel.appendChild(pillRow);

                return;
            }

            /* depends_on: skip entry when condition not met */
            if (!dependsOnMet(s.depends_on, advField)) return;

            (function (schema_entry) {
                var cur2 = advField[schema_entry.key] !== undefined ? advField[schema_entry.key] : '';
                if (schema_entry.type === 'text' || schema_entry.type === 'email' || schema_entry.type === 'url') {
                    spRow(panel, schema_entry.key, schema_entry.label, schema_entry.type, cur2,
                        function (v) { change(schema_entry.key, v); }, schema_entry.hint || '');
                } else if (schema_entry.type === 'number') {
                    spRow(panel, schema_entry.key, schema_entry.label, 'number', cur2,
                        function (v) { change(schema_entry.key, v === '' ? '' : (parseFloat(v) || 0)); },
                        schema_entry.hint || '');
                } else if (schema_entry.type === 'select') {
                    spSelect(panel, schema_entry.key, schema_entry.label,
                        (schema_entry.options || []).map(function (o) {
                            return typeof o === 'string' ? { value: o, label: o } : o;
                        }),
                        cur2,
                        function (v) { change(schema_entry.key, v); });
                } else if (schema_entry.type === 'textarea') {
                    spTextarea(panel, schema_entry.key, schema_entry.label, cur2,
                        function (v) { change(schema_entry.key, v); }, schema_entry.hint || '');
                } else if (schema_entry.type === 'checkbox') {
                    spCheckbox(panel, schema_entry.key, schema_entry.label, !!cur2,
                        function (v) { change(schema_entry.key, v); },
                        schema_entry.disclaimer || '');
                } else if (schema_entry.type === 'media_upload') {
                    spMediaUpload(panel, schema_entry.key, schema_entry.label, cur2,
                        function (v) { change(schema_entry.key, v); }, schema_entry.hint || '');
                }
            }(s));
        });

        function rebuildAdvDeps() {
            buildAdvancedTab(idx, settingsCtx
                ? (state.fields[settingsCtx.groupIdx].children[settingsCtx.childIdx])
                : state.fields[idx]);
        }
    }

    /* Placed last so field-specific sections appear below the shared Darstellung section. */
    if (FIELD_ADVANCED_BLOCKS[field.type]) {
        FIELD_ADVANCED_BLOCKS[field.type](panel, field, change);
    }
}

/* ---- Conditions tab ---- */
function buildConditionsTab(idx, field) {
    var panel = document.getElementById('fabricator-stab-conditions');
    panel.innerHTML = '';

    /* Resolve which object holds the conditions — group child or top-level field */
    var condOwner;
    if (settingsCtx) {
        var gf = state.fields[settingsCtx.groupIdx];
        condOwner = gf && gf.children ? gf.children[settingsCtx.childIdx] : null;
    } else {
        condOwner = state.fields[idx];
    }
    if (!condOwner) return;

    if (!condOwner.conditions) {
        condOwner.conditions = { action: 'show', match: 'all', rules: [] };
    }
    var cond = condOwner.conditions;

    var sentenceRow = document.createElement('div');
    sentenceRow.className = 'fabricator-cond-sentence';
    sentenceRow.appendChild(mkSeg(
        ['show', 'hide'],
        [_i18n.condShow || 'Show', _i18n.condHide || 'Hide'],
        cond.action,
        function (v) { cond.action = v; }
    ));
    sentenceRow.appendChild(mkSpan(' ' + (_i18n.condSentenceField || 'this field when') + ' '));
    sentenceRow.appendChild(mkSeg(
        ['all', 'any'],
        [_i18n.condAll || 'all', _i18n.condAny || 'any'],
        cond.match,
        function (v) { cond.match = v; }
    ));
    sentenceRow.appendChild(mkSpan(' ' + (_i18n.condSentenceTail || 'of the following conditions match:')));
    panel.appendChild(sentenceRow);

    var body = document.createElement('div');
    body.className = 'fabricator-cond-body';
    panel.appendChild(body);

    var rulesList = document.createElement('div');
    rulesList.className = 'fabricator-cond-rules';
    body.appendChild(rulesList);

    function rebuildRules() {
        rulesList.innerHTML = '';
        (cond.rules || []).forEach(function (rule, ri) {
            rulesList.appendChild(buildRuleRow(rule, ri));
        });
    }

    function buildRuleRow(rule, ri) {
        var CHOICE_TYPES = ['select', 'radio', 'checkbox', 'multivalue'];

        var row = document.createElement('div');
        row.className = 'fabricator-cond-rule';

        /* Left content: two plain rows */
        var content = document.createElement('div');
        content.className = 'fabricator-cond-rule-content';
        row.appendChild(content);

        /* Collect all referenceable fields: top-level + group children (flattened) */
        var SKIP_TYPES = ['group', 'html', 'pagebreak'];
        var currentId  = settingsCtx
            ? (state.fields[settingsCtx.groupIdx].children[settingsCtx.childIdx] || {}).id
            : (state.fields[idx] || {}).id;
        var others = [];
        state.fields.forEach(function (f, fi) {
            if (SKIP_TYPES.indexOf(f.type) !== -1) {
                /* Descend into group children */
                (f.children || []).forEach(function (child) {
                    if (child.id && child.id !== currentId && SKIP_TYPES.indexOf(child.type) === -1) {
                        others.push(child);
                    }
                });
                return;
            }
            if (f.id !== currentId) {
                others.push(f);
            }
        });

        /* Row 1: field selector + operator selector */
        var topRow = document.createElement('div');
        topRow.className = 'fabricator-cond-rule-top';

        var fieldSel = document.createElement('select');
        fieldSel.className = 'fabricator-cond-sel';
        if (!others.length) {
            var placeholder = document.createElement('option');
            placeholder.textContent = _i18n.noOtherFields || '(no other fields)';
            fieldSel.appendChild(placeholder);
            fieldSel.disabled = true;
        } else {
            others.forEach(function (f) {
                var o = document.createElement('option');
                o.value       = f.id;
                o.textContent = (f.label || f.id) + ' [' + f.id + ']';
                if (f.id === rule.field_id) o.selected = true;
                fieldSel.appendChild(o);
            });
        }
        topRow.appendChild(fieldSel);

        var opSel = mkSel(
            OPERATORS.map(function (o) { return o[0]; }),
            OPERATORS.map(function (o) { return o[1]; }),
            rule.operator || 'equals',
            function (v) { rule.operator = v; rebuildValueCtrl(); }
        );
        topRow.appendChild(opSel);
        content.appendChild(topRow);

        /* Row 2: value area (mode pill + control) */
        var valArea = document.createElement('div');
        valArea.className = 'fabricator-cond-val-area';
        content.appendChild(valArea);

        var OPTION_OPERATORS = [
            ['equals',     _i18n.opEquals    || 'is equal to'],
            ['not_equals', _i18n.opNotEquals || 'is not equal to'],
        ];
        var VALUE_OPERATORS = OPERATORS;

        function getCtrlField() {
            for (var i = 0; i < state.fields.length; i++) {
                if (state.fields[i].id === fieldSel.value) return state.fields[i];
                var children = state.fields[i].children || [];
                for (var j = 0; j < children.length; j++) {
                    if (children[j].id === fieldSel.value) return children[j];
                }
            }
            return null;
        }

        function fieldIsChoice(f) {
            return f && CHOICE_TYPES.indexOf(f.type) !== -1 && (f.options || []).length;
        }

        /* Default use_option based on the initial controller field */
        if (rule.use_option === undefined) {
            rule.use_option = fieldIsChoice(getCtrlField());
        }

        function rebuildOpSel(forOptionMode) {
            var ops = forOptionMode ? OPTION_OPERATORS : VALUE_OPERATORS;
            var cur = opSel.value;
            while (opSel.firstChild) opSel.removeChild(opSel.firstChild);
            ops.forEach(function (pair) {
                var o = document.createElement('option');
                o.value = pair[0]; o.textContent = pair[1];
                if (pair[0] === cur) o.selected = true;
                opSel.appendChild(o);
            });
            /* If old operator no longer valid, reset to first available */
            if (!opSel.value) opSel.selectedIndex = 0;
            rule.operator = opSel.value;
        }

        function rebuildValueCtrl() {
            valArea.innerHTML = '';
            var op = opSel.value;
            if (op === 'empty' || op === 'not_empty') {
                return;
            }

            var ctrlField = getCtrlField();
            var isChoice  = fieldIsChoice(ctrlField);

            if (isChoice) {
                /* Mode toggle: Option | Value */
                var modeToggle = mkSeg(
                    ['option', 'value'],
                    [_i18n.modeOption || 'Option', _i18n.modeValue || 'Value'],
                    rule.use_option ? 'option' : 'value',
                    function (v) {
                        rule.use_option = (v === 'option');
                        rule.value      = '';
                        rebuildOpSel(rule.use_option);
                        rebuildValueCtrl();
                    }
                );
                modeToggle.className += ' fabricator-cond-mode-seg';
                valArea.appendChild(modeToggle);
            }

            if (isChoice && rule.use_option) {
                /* Dropdown of the field's saved options */
                var optSel = document.createElement('select');
                optSel.className = 'fabricator-cond-sel fabricator-cond-opt-sel';
                var blank = document.createElement('option');
                blank.value = ''; blank.textContent = _i18n.chooseOption || 'Choose option';
                if (!rule.value) blank.selected = true;
                optSel.appendChild(blank);
                ctrlField.options.forEach(function (opt) {
                    var val   = (opt && opt.value) || '';
                    var lbl   = (opt && opt.label) || val;
                    var o = document.createElement('option');
                    o.value = val; o.textContent = lbl;
                    if (val === rule.value) o.selected = true;
                    optSel.appendChild(o);
                });
                optSel.addEventListener('change', function () { rule.value = this.value; });
                valArea.appendChild(optSel);
            } else {
                /* Free text / number input */
                var inp = document.createElement('input');
                inp.type        = 'text';
                inp.className   = 'fabricator-cond-val';
                inp.value       = rule.value || '';
                inp.placeholder = _i18n.valueWord || 'Value';
                inp.addEventListener('input', function () { rule.value = this.value; });
                valArea.appendChild(inp);
            }
        }

        /* Sync operator list on initial render */
        rebuildOpSel(rule.use_option && fieldIsChoice(getCtrlField()));

        fieldSel.addEventListener('change', function () {
            rule.field_id   = this.value;
            rule.value      = '';
            rule.use_option = fieldIsChoice(getCtrlField());
            rebuildOpSel(rule.use_option);
            rebuildValueCtrl();
        });

        rebuildValueCtrl();

        /* ---- Delete — right-side trash button ---- */
        var rm = document.createElement('button');
        rm.type      = 'button';
        rm.className = 'fabricator-cond-rm';
        rm.title     = _i18n.removeCondition || 'Remove condition';
        rm.innerHTML = '<i class="fa-solid fa-trash"></i>';
        rm.addEventListener('click', function () { cond.rules.splice(ri, 1); rebuildRules(); });
        row.appendChild(rm);

        return row;
    }

    rebuildRules();

    var addBtn = document.createElement('button');
    addBtn.type      = 'button';
    addBtn.className = 'fabricator-cond-add';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> ' + escHtml(_i18n.addCondition || 'Add condition');
    addBtn.addEventListener('click', function () {
        var SKIP = ['group', 'html', 'pagebreak'];
        /* Excluded by id, as buildRuleRow() does: for a group child, idx is the group's index, so "fi !== idx" excluded
           only the (already skipped) group and let the child default to itself as its own condition field. */
        var selfId = settingsCtx
            ? (state.fields[settingsCtx.groupIdx].children[settingsCtx.childIdx] || {}).id
            : (state.fields[idx] || {}).id;
        var addable = [];
        state.fields.forEach(function (f) {
            if (SKIP.indexOf(f.type) !== -1) {
                (f.children || []).forEach(function (c) {
                    if (c.id && c.id !== selfId && SKIP.indexOf(c.type) === -1) addable.push(c);
                });
            } else if (f.id && f.id !== selfId) {
                addable.push(f);
            }
        });
        cond.rules.push({ field_id: addable.length ? addable[0].id : '', operator: 'equals', value: '' });
        rebuildRules();
    });
    body.appendChild(addBtn);
}

/* Canvas tabs / form name / save */
function bindCanvasTabs() {
    var tabs = document.querySelectorAll('.fabricator-tab-btn');
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabs.forEach(function (b) { b.classList.remove('fabricator-tab-active'); });
            btn.classList.add('fabricator-tab-active');
            var target = btn.dataset.tab;
            document.querySelectorAll('.fabricator-tab-panel').forEach(function (p) {
                p.classList.toggle('fabricator-panel-active', p.id === target);
            });
        });
    });
}

function bindFormName() {
    var inp = document.getElementById('fabricator-form-name');
    if (!inp) return;
    inp.value = state.formName;
    inp.addEventListener('input', function () { state.formName = this.value; markDirty(); });
}

function setSaveStatus(status, state, msg) {
    if (!status) return;
    clearTimeout(status._fadeTimer);
    clearTimeout(status._clearTimer);
    status.className = '';
    status.innerHTML = '';
    if (state === 'saving') {
        status.className = 'fabricator-ss--saving';
        status.innerHTML = '<span class="fabricator-spinner"></span> ' + escHtml(_i18n.saving || 'Saving…');
    } else if (state === 'ok') {
        status.className = 'fabricator-ss--ok';
        status.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + escHtml(_i18n.saved || 'Saved');
        status._fadeTimer = setTimeout(function () { status.classList.add('fabricator-ss--fade'); }, 2200);
        status._clearTimer = setTimeout(function () { status.className = ''; status.innerHTML = ''; }, 2600);
    } else if (state === 'warn') {
        /* Saved, but with something the user has to act on: stays until dismissed or the next save, instead of fading. */
        status.className = 'fabricator-ss--warn';
        status.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>'
            + '<span class="fabricator-ss-text">' + escHtml((_i18n.saved || 'Saved') + ' – ' + msg) + '</span>'
            + '<button type="button" class="fabricator-ss-close" aria-label="' + escHtml(_i18n.dismiss || 'Dismiss') + '">'
            + '<i class="fa-solid fa-xmark" aria-hidden="true"></i></button>';
        status.querySelector('.fabricator-ss-close').addEventListener('click', function () {
            status.className = '';
            status.innerHTML = '';
        });
    } else if (state === 'err') {
        status.className = 'fabricator-ss--err';
        status.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + escHtml(msg || (_i18n.errorGeneric || 'Error'));
    }
}

function bindSave() {
    var btn    = document.getElementById('fabricator-save-btn');
    var status = document.getElementById('fabricator-save-status');
    if (!btn) return;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        setSaveStatus(status, 'saving');
        var payload = {
            id: FORM_ID, title: state.formName,
            fields: state.fields, notifications: state.notifications, settings: state.settings,
            snapshot: FORM_SNAPSHOT,
        };
        var fd = new FormData();
        fd.append('action',    'fabricator_forms_save_form');
        fd.append('nonce',     NONCE);
        /* btoa() only accepts Latin1; encodeURIComponent+unescape re-encodes UTF-8 to Latin1-safe bytes first so umlauts survive. */
        fd.append('form_data', b64JsonEncode(payload));
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (data.success && data.data && data.data.form_id) FORM_ID = data.data.form_id;
                if (data.success && data.data && data.data.snapshot !== undefined) FORM_SNAPSHOT = data.data.snapshot;
                if (data.success) {
                    markClean();
                    if (data.data && data.data.warning) {
                        setSaveStatus(status, 'warn', data.data.warning);
                    } else {
                        setSaveStatus(status, 'ok');
                    }
                } else {
                    var msg = (data.data && data.data.message) ? data.data.message : (typeof data.data === 'string' ? data.data : (_i18n.unknownError || 'Unknown error'));
                    setSaveStatus(status, 'err', msg);
                }
            })
            .catch(function () { btn.disabled = false; setSaveStatus(status, 'err', _i18n.serverError || 'Server error'); });
    });
}

/* Form Preview */

/* base64-encoded so sanitize_*_field() on the PHP side doesn't strip HTML tags out of the JSON. */
function b64JsonEncode(value) {
    return btoa(unescape(encodeURIComponent(JSON.stringify(value))));
}

function bindPreview() {
    var btn = document.getElementById('fabricator-preview-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.innerHTML = '<span class="fabricator-spinner"></span> ' + escHtml(_i18n.previewLabel || 'Preview');
        var fd = new FormData();
        fd.append('action',   'fabricator_forms_preview');
        fd.append('nonce',    NONCE);
        fd.append('form_id',  FORM_ID);
        fd.append('fields',   b64JsonEncode(state.fields));
        fd.append('settings', b64JsonEncode(state.settings));
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success && resp.data.html) {
                    /* allow-scripts without allow-same-origin: the blob: URL would otherwise share wp-admin's origin. */
                    var wrapper = '<!doctype html><html><head><meta charset="utf-8"><title>'
                        + escHtml(_i18n.previewLabel || 'Preview')
                        + '</title><style>html,body{margin:0;height:100%}'
                        + 'iframe{border:0;display:block;width:100%;height:100%}</style></head>'
                        + '<body><iframe sandbox="allow-scripts allow-forms" srcdoc="'
                        + escHtml(resp.data.html)
                        + '"></iframe></body></html>';
                    var blob = new Blob([wrapper], { type: 'text/html' });
                    var url  = URL.createObjectURL(blob);
                    window.open(url, '_blank');
                    setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
                } else {
                    alert((resp.data && resp.data.message) || (_i18n.previewFailed || 'Preview failed.'));
                }
            })
            .catch(function () { alert(_i18n.networkError || 'Network error.'); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-eye"></i> ' + escHtml(_i18n.previewLabel || 'Preview');
            });
    });
}

/* Shared utilities */

/* Segmented pill control: all options shown at once, selected one filled. */
/* Follows a renamed field id through everything that points at one by id. Free text such as {id} placeholders in
   subjects and bodies is left alone on purpose: it is the author's own wording, not a stored reference. */
function renameFieldReferences(oldId, newId) {
    if (!oldId || !newId || oldId === newId) return;
    function fixRules(rules) {
        (rules || []).forEach(function (rule) {
            if (rule && rule.field_id === oldId) rule.field_id = newId;
        });
    }
    (state.fields || []).forEach(function (f) {
        if (f.conditions) fixRules(f.conditions.rules);
        (f.children || []).forEach(function (c) {
            if (c.conditions) fixRules(c.conditions.rules);
        });
    });
    (state.notifications || []).forEach(function (n) { fixRules(n.routing_rules); });
    if (state.settings && state.settings.submit_conditions) {
        fixRules(state.settings.submit_conditions.rules);
    }
}

function mkSeg(values, labels, current, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'fabricator-seg';

    values.forEach(function (val, i) {
        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'fabricator-seg-btn' + (val === current ? ' fabricator-seg-active' : '');
        btn.textContent = labels[i];
        btn.dataset.value = val;
        btn.addEventListener('click', function () {
            wrap.querySelectorAll('.fabricator-seg-btn').forEach(function (b) { b.classList.remove('fabricator-seg-active'); });
            btn.classList.add('fabricator-seg-active');
            onChange(val);
        });
        wrap.appendChild(btn);
    });

    return wrap;
}

/* Multi-select pill: each button toggles independently; current is an array. */
function mkSegMulti(values, labels, current, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'fabricator-seg';

    values.forEach(function (val, i) {
        var btn = document.createElement('button');
        btn.type        = 'button';
        btn.className   = 'fabricator-seg-btn' + (current.indexOf(val) !== -1 ? ' fabricator-seg-active' : '');
        btn.textContent = labels[i];
        btn.dataset.value = val;
        btn.addEventListener('click', function () {
            var idx2 = current.indexOf(val);
            if (idx2 === -1) { current.push(val); }
            else             { current.splice(idx2, 1); }
            btn.classList.toggle('fabricator-seg-active', current.indexOf(val) !== -1);
            onChange(current.slice());
        });
        wrap.appendChild(btn);
    });

    return wrap;
}

function mkSel(values, labels, selected, onChange) {
    var sel = document.createElement('select');
    sel.className = 'fabricator-cond-sel';
    values.forEach(function (v, i) {
        var o = document.createElement('option');
        o.value       = v;
        o.textContent = labels[i];
        if (v === selected) o.selected = true;
        sel.appendChild(o);
    });
    sel.addEventListener('change', function () { onChange(this.value); });
    return sel;
}

function mkSpan(t) { var s = document.createElement('span'); s.textContent = t; return s; }

function findPaletteItem(type) {
    for (var i = 0; i < PALETTE_GROUPS.length; i++) {
        var items = PALETTE_GROUPS[i].items;
        for (var j = 0; j < items.length; j++) {
            if (items[j].type === type) return items[j];
        }
    }
    return null;
}

function rebuildPreview() { renderFieldList(); }

/* Generate sequential IDs like "text-1", "email-3" — unique across top-level and all group children */
function generateId(type) {
    if (!type) type = 'field';
    var used = {};
    for (var i = 0; i < state.fields.length; i++) {
        used[state.fields[i].id] = true;
        var children = state.fields[i].children || [];
        for (var j = 0; j < children.length; j++) {
            if (children[j].id) used[children[j].id] = true;
        }
    }
    var n = 1;
    while (used[type + '-' + n]) n++;
    return type + '-' + n;
}

/* Finds a field by id among top-level fields and group children alike. Routing and submit-button rules may target a
   group child, which a top-level-only lookup never found, so those rules never offered the option dropdown. */
function findFieldById(id) {
    for (var i = 0; i < state.fields.length; i++) {
        var f = state.fields[i];
        if (f.id === id) return f;
        var children = f.children || [];
        for (var j = 0; j < children.length; j++) {
            if (children[j].id === id) return children[j];
        }
    }
    return null;
}

/* URL scheme check after removing the characters browsers ignore: a tab inside "javascript:" or a leading control
   character still runs, so a plain /^\s*javascript:/ test let them through. allowData keeps data: URIs (pasted images in an email body). */
/* C0 controls, space, DEL and C1 controls, built from char codes so the source stays plain ASCII. */
var URL_IGNORED_CHARS = new RegExp('[' + String.fromCharCode(0) + '-' + String.fromCharCode(32)
    + String.fromCharCode(127) + '-' + String.fromCharCode(159) + ']', 'g');

function isUnsafeUrl(url, allowData) {
    var scheme = String(url || '').replace(URL_IGNORED_CHARS, '').toLowerCase();
    return allowData ? /^(javascript|vbscript):/.test(scheme) : /^(javascript|vbscript|data):/.test(scheme);
}

/* Slugify a string for use as an option value */
/* An option value from its label: accents transliterated ("Ärzte" -> "arzte"), then ASCII letters, digits and dashes;
   '' when nothing is left, and the caller numbers it, as FormSanitizer::sanitizeFields() does. */
function slugify(str) {
    return String(str).normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
        .replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '').replace(/-{2,}/g, '-').replace(/^-|-$/g, '');
}

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str || '')));
    /* Also encode " and ' (not just &,<,>) so this is safe when spliced into a quoted HTML attribute too. */
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* A translated sentence with %s where a button's name goes, both escaped; the name is set in bold. One sentence, so
   translators can put the name wherever their language needs it. */
function withBoldName(sentence, name) {
    return escHtml(sentence).replace('%s', function () { return '<strong>' + escHtml(name) + '</strong>'; });
}


/* The builder's parts (admin-builder-*.js, loaded before this file): each takes what it needs from B and adds its
   own functions there. Run here, after every shared name is set, and before DOMContentLoaded uses them. */
var B = {
    _i18n: _i18n,
    state: state,
    OPERATORS: OPERATORS,
    mergeI18nMap: mergeI18nMap,
    makeSortable: makeSortable,
    markDirty: markDirty,
    mkSeg: mkSeg,
    mkSel: mkSel,
    mkSpan: mkSpan,
    findFieldById: findFieldById,
    isUnsafeUrl: isUnsafeUrl,
    slugify: slugify,
    escHtml: escHtml,
    withBoldName: withBoldName,
    ajaxUrl: function () { return AJAX_URL; },
    nonce: function () { return NONCE; }
};
(window.FabricatorBuilderParts || []).forEach(function (part) { part(B); });
var spRow = B.spRow,
    spMediaUpload = B.spMediaUpload,
    spTextarea = B.spTextarea,
    spPageNamesList = B.spPageNamesList,
    spCheckbox = B.spCheckbox,
    spSelect = B.spSelect,
    spCountryTags = B.spCountryTags,
    spCallingCodeTags = B.spCallingCodeTags,
    spOptionsList = B.spOptionsList,
    spBoolSeg = B.spBoolSeg,
    spSectionTitle = B.spSectionTitle,
    spLimitRow = B.spLimitRow,
    spNotice = B.spNotice,
    spHtmlFieldEditor = B.spHtmlFieldEditor,
    spIconRow = B.spIconRow,
    spTimeRow = B.spTimeRow,
    spRatingPreview = B.spRatingPreview,
    spSubfields = B.spSubfields,
    renderNotifications = B.renderNotifications,
    createNotifModal = B.createNotifModal,
    createSubmitModal = B.createSubmitModal,
    renderSubmitPreview = B.renderSubmitPreview;

})();
