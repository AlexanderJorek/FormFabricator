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
        submit_conditions: { enabled: false, match: 'all', rules: [] },
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
            state.notifications = (fd.notifications || []).map(normalizeNotifBodyFields);
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
            '<p>' + escHtml(_i18n.addFirstFieldHtml || 'Add your first field via') + ' <strong>' + escHtml(_i18n.addFieldTitle || 'Add field') + '</strong></p>';
        list.appendChild(empty);
    } else {
        for (var i = 0; i < state.fields.length; i++) {
            list.appendChild(buildFieldRow(state.fields[i], i));
        }
    }
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
    var condBadge = (field.conditions && field.conditions.rules && field.conditions.rules.length)
        ? ' <i class="fa-solid fa-code-branch fabricator-cond-badge" title="' + escHtml(_i18n.conditionsActive || 'Conditions active') + '"></i>' : '';
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
        renderFieldList();
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
            '<div class="fabricator-row-label">' + escHtml(field.label || (_i18n.noLabel || '(no label)')) + '</div>' +
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
                renderFieldList();
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
        renderFieldList();
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
            '<div class="fabricator-child-row-label">' + escHtml(child.label || (_i18n.noLabel || '(no label)')) + '</div>' +
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
    var beforeEl = getAfterFromCache(pendingY);
    dropLineTarget = beforeEl ? { beforeEl: beforeEl } : { atEnd: true };
    showDropLine(list, beforeEl);
}

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
    var rows = list.querySelectorAll('.fabricator-field-row:not(.fabricator-row-dragging)');
    for (var i = 0; i < rows.length; i++) {
        var r   = rows[i];
        var idx = parseInt(r.dataset.idx, 10);
        if (isSecondInPair(idx)) continue;
        var box = r.getBoundingClientRect();
        cachedMidpoints.push({ el: r, midY: box.top + box.height / 2 });
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
        /* Fields with noPanel cannot be nested inside groups */
        if (pal && pal.noPanel) return;
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
        if (s.type !== 'subfields') return false;
        if (!s.depends_on) return true;
        var depKey = Object.keys(s.depends_on)[0];
        return !!field[depKey] === !!s.depends_on[depKey];
    });
    var hideRequired = (pal && pal.noRequired) || hasActiveSubfields;
    if (!hideRequired) {
        spCheckbox(panel, 'required', _i18n.requiredField || 'Required field', !!field.required, function (v) { change('required', v); });
    }

    schema.forEach(function (s) {
        /* depends_on: skip this entry if condition not met */
        if (s.depends_on) {
            var depField = state.fields[idx];
            if (s.depends_on.not !== undefined) {
                /* {key: 'fieldKey', not: 'value'} — show when field !== value */
                if (depField[s.depends_on.key] === s.depends_on.not) return;
            } else {
                var depKey = Object.keys(s.depends_on)[0];
                var depExpected = s.depends_on[depKey];
                if (!!depField[depKey] !== !!depExpected) return;
            }
        }

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
                                buildGeneralTab(idx, state.fields[idx], findPaletteItem(state.fields[idx].type));
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
                            buildGeneralTab(idx, state.fields[idx], findPaletteItem(state.fields[idx].type));
                        }
                    }, schema_entry.hint);
            }(s));
        } else if (s.type === 'checkbox') {
            (function (schema_entry) {
                spCheckbox(panel, schema_entry.key, schema_entry.label, !!cur, function (v) {
                    change(schema_entry.key, v);
                    if (schema_entry.rebuild) {
                        buildGeneralTab(idx, state.fields[idx], findPaletteItem(state.fields[idx].type));
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
                    schema_entry.false_label || 'Nein', schema_entry.true_label || 'Ja',
                    function (v) {
                        change(schema_entry.key, v);
                        if (schema_entry.rebuild) {
                            buildGeneralTab(idx, state.fields[idx], findPaletteItem(state.fields[idx].type));
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
                            buildGeneralTab(idx, state.fields[idx], findPaletteItem(state.fields[idx].type));
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
                            buildGeneralTab(idx, state.fields[idx],
                                findPaletteItem(state.fields[idx].type));
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

    sepa: function (panel, field, change) {
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
        target.id = slug || generateId(field.type);
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
            if (s.depends_on) {
                if (s.depends_on.not !== undefined) {
                    if (advField[s.depends_on.key] === s.depends_on.not) return;
                } else {
                    var depKey2 = Object.keys(s.depends_on)[0];
                    if (!!advField[depKey2] !== !!s.depends_on[depKey2]) return;
                }
            }

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
                    var val   = (opt && typeof opt === 'object') ? (opt.value || '') : String(opt);
                    var lbl   = (opt && typeof opt === 'object') ? (opt.label || val)  : val;
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
        var addable = [];
        state.fields.forEach(function (f, fi) {
            if (SKIP.indexOf(f.type) !== -1) {
                (f.children || []).forEach(function (c) {
                    if (c.id && SKIP.indexOf(c.type) === -1) addable.push(c);
                });
            } else if (fi !== idx && f.id) {
                addable.push(f);
            }
        });
        cond.rules.push({ field_id: addable.length ? addable[0].id : '', operator: 'equals', value: '' });
        rebuildRules();
    });
    body.appendChild(addBtn);
}

/* Settings panel element builders */
function spRow(parent, key, label, type, value, onChange, hint) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('label');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'fabricator-sp-' + key;
    row.appendChild(lbl);
    var inp = document.createElement('input');
    inp.type      = type || 'text';
    inp.id        = 'fabricator-sp-' + key;
    inp.className = 'fabricator-sp-input';
    inp.value     = value !== null && value !== undefined ? String(value) : '';
    inp.addEventListener('input', function () { onChange(this.value); });
    row.appendChild(inp);
    if (hint) {
        var h = document.createElement('p');
        h.className   = 'fabricator-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

function spMediaUpload(parent, key, label, value, onChange, hint) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('label');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'fabricator-sp-' + key;
    row.appendChild(lbl);

    var wrap = document.createElement('div');
    wrap.className = 'fabricator-sp-media-wrap';

    var inp = document.createElement('input');
    inp.type        = 'text';
    inp.id          = 'fabricator-sp-' + key;
    inp.className   = 'fabricator-sp-input fabricator-sp-media-url';
    inp.value       = value || '';
    inp.placeholder = 'https://…';
    inp.addEventListener('input', function () { onChange(this.value); updateThumb(); });
    wrap.appendChild(inp);

    var btn = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'button fabricator-sp-media-btn';
    btn.innerHTML = '<i class="fa-solid fa-photo-film"></i> ' + escHtml(_i18n.mediaLibrary || 'Media library');
    btn.addEventListener('click', function () {
        if (typeof wp === 'undefined' || !wp.media) {
            var u = prompt(_i18n.imageUrlPrompt || 'Image URL:');
            if (u && !/^\s*(javascript|vbscript|data):/i.test(u)) { inp.value = u; onChange(u); updateThumb(); }
            return;
        }
        var frame = wp.media({ title: label, button: { text: _i18n.useButtonLabel || 'Use' }, multiple: false, library: { type: 'image' } });
        frame.on('open', function () {
            var modal   = document.querySelector('.media-modal');
            var overlay = document.querySelector('.media-modal-backdrop');
            if (modal)   modal.style.zIndex   = '200000';
            if (overlay) overlay.style.zIndex = '199999';
        });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            inp.value = att.url;
            onChange(att.url);
            updateThumb();
        });
        frame.open();
    });
    wrap.appendChild(btn);

    var thumb = document.createElement('img');
    thumb.className     = 'fabricator-sp-media-thumb';
    thumb.alt           = '';
    thumb.style.display = value ? 'block' : 'none';
    if (value) { thumb.src = value; }
    wrap.appendChild(thumb);

    function updateThumb() {
        var url = inp.value.trim();
        thumb.src           = url;
        thumb.style.display = url ? 'block' : 'none';
    }

    row.appendChild(wrap);

    if (hint) {
        var h = document.createElement('p');
        h.className   = 'fabricator-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

function spTextarea(parent, key, label, value, onChange, hint) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('label');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'fabricator-sp-' + key;
    row.appendChild(lbl);
    var ta = document.createElement('textarea');
    ta.id        = 'fabricator-sp-' + key;
    ta.className = 'fabricator-sp-input';
    ta.rows      = 4;
    ta.value     = value || '';
    ta.addEventListener('input', function () { onChange(this.value); });
    row.appendChild(ta);
    if (hint) {
        var h = document.createElement('p');
        h.className   = 'fabricator-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

/* A page break before the bar's own position doesn't count, so an entry splash page can precede it unnumbered. */
function spPageNamesList(parent, key, label, values, onChange, hint, fieldIdx) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);

    var pageBreaksFromHere = state.fields
        .slice(fieldIdx)
        .filter(function (f) { return f.type === 'pagebreak'; }).length;
    var pageCount = pageBreaksFromHere + 1;
    var names     = values.slice(0, pageCount);
    while (names.length < pageCount) { names.push(''); }

    var listEl = document.createElement('div');
    listEl.className = 'fabricator-sp-page-names-list';
    for (var i = 0; i < pageCount; i++) {
        (function (idx) {
            var itemRow = document.createElement('div');
            itemRow.className = 'fabricator-sp-page-name-row';

            var numTag = document.createElement('span');
            numTag.className   = 'fabricator-sp-page-name-num';
            numTag.textContent = String(idx + 1);
            itemRow.appendChild(numTag);

            var inp = document.createElement('input');
            inp.type        = 'text';
            inp.className   = 'fabricator-sp-input';
            inp.value       = names[idx] || '';
            inp.placeholder = (_i18n.pagePrefix || 'Page ') + (idx + 1);
            inp.addEventListener('input', function () {
                names[idx] = this.value;
                onChange(names.slice());
            });
            itemRow.appendChild(inp);

            listEl.appendChild(itemRow);
        }(i));
    }
    row.appendChild(listEl);

    if (hint) {
        var h = document.createElement('p');
        h.className   = 'fabricator-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

/* Toggle switch — used for all simple on/off settings fields */
function spInfoIcon(text) {
    var wrap = document.createElement('span');
    wrap.className = 'fabricator-info-icon';
    wrap.setAttribute('aria-label', text);
    wrap.setAttribute('role', 'button');
    wrap.setAttribute('tabindex', '0');
    var icon = document.createElement('i');
    icon.className = 'fa-solid fa-circle-info';
    wrap.appendChild(icon);

    // Appended to <body>, not `wrap` — avoids clipping by the settings modal's overflow:hidden (same fix as fabricator-access-dropdown in FormSettings.php).
    var tip = document.createElement('span');
    tip.className   = 'fabricator-info-tooltip';
    tip.textContent = text;
    document.body.appendChild(tip);

    var open = false;
    function position() {
        var r = wrap.getBoundingClientRect();
        // Render off-screen first so its real height can be measured before placing it.
        tip.style.left = '-9999px';
        tip.style.top  = '-9999px';
        tip.classList.add('fabricator-info-tooltip--visible');
        var tipRect = tip.getBoundingClientRect();
        tip.style.top  = (r.top - tipRect.height - 6) + 'px';
        tip.style.left = (r.right - tipRect.width) + 'px';
    }
    function show() { position(); }
    function hide() { tip.classList.remove('fabricator-info-tooltip--visible'); open = false; }

    wrap.addEventListener('mouseenter', show);
    wrap.addEventListener('mouseleave', hide);
    wrap.addEventListener('focus',      show);
    wrap.addEventListener('blur',       hide);
    wrap.addEventListener('click', function (e) {
        e.preventDefault();
        open = !open;
        open ? show() : hide();
    });
    wrap.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            open = !open;
            open ? show() : hide();
        }
        if (e.key === 'Escape') { hide(); }
    });

    // Tooltip lives on <body>, not inside `wrap` — remove it when the icon is removed or it leaks a detached node.
    var observer = new MutationObserver(function () {
        if (!document.body.contains(wrap)) {
            tip.remove();
            observer.disconnect();
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    return wrap;
}

function spCheckbox(parent, key, label, checked, onChange, disclaimer) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-toggle-row';

    var toggleLbl = document.createElement('label');
    toggleLbl.className = 'fabricator-toggle';
    toggleLbl.htmlFor   = 'fabricator-sp-' + key;

    var inp = document.createElement('input');
    inp.type    = 'checkbox';
    inp.id      = 'fabricator-sp-' + key;
    inp.checked = !!checked;
    inp.addEventListener('change', function () { onChange(this.checked); });
    toggleLbl.appendChild(inp);

    var slider = document.createElement('span');
    slider.className = 'fabricator-toggle-slider';
    toggleLbl.appendChild(slider);

    var textLbl = document.createElement('label');
    textLbl.className   = 'fabricator-toggle-label';
    textLbl.htmlFor     = 'fabricator-sp-' + key;
    textLbl.textContent = label;
    if (disclaimer) {
        textLbl.appendChild(spInfoIcon(disclaimer));
    }

    row.appendChild(toggleLbl);
    row.appendChild(textLbl);
    parent.appendChild(row);
}

function spCheckboxHint(parent, key, label, hint, checked, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'fabricator-sp-toggle-row fabricator-sp-toggle-row--hint';

    var left = document.createElement('div');
    left.className = 'fabricator-sp-toggle-row-text';
    var textLbl = document.createElement('label');
    textLbl.className   = 'fabricator-toggle-label';
    textLbl.htmlFor     = 'fabricator-sp-' + key;
    textLbl.textContent = label;
    left.appendChild(textLbl);
    if (hint) {
        var hintEl = document.createElement('div');
        hintEl.className   = 'fabricator-sp-hint';
        hintEl.textContent = hint;
        left.appendChild(hintEl);
    }

    var toggleLbl = document.createElement('label');
    toggleLbl.className = 'fabricator-toggle';
    toggleLbl.htmlFor   = 'fabricator-sp-' + key;
    var inp = document.createElement('input');
    inp.type    = 'checkbox';
    inp.id      = 'fabricator-sp-' + key;
    inp.checked = !!checked;
    inp.addEventListener('change', function () { onChange(this.checked); });
    toggleLbl.appendChild(inp);
    var slider = document.createElement('span');
    slider.className = 'fabricator-toggle-slider';
    toggleLbl.appendChild(slider);

    wrap.appendChild(left);
    wrap.appendChild(toggleLbl);
    parent.appendChild(wrap);
}

function spSelect(parent, key, label, options, value, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('label');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'fabricator-sp-' + key;
    row.appendChild(lbl);
    var sel = document.createElement('select');
    sel.id        = 'fabricator-sp-' + key;
    sel.className = 'fabricator-sp-input';
    options.forEach(function (opt) {
        var o = document.createElement('option');
        o.value       = opt.value !== undefined ? opt.value : opt;
        o.textContent = opt.label !== undefined ? opt.label : opt;
        if (String(o.value) === String(value)) o.selected = true;
        sel.appendChild(o);
    });
    sel.addEventListener('change', function () { onChange(this.value); });
    row.appendChild(sel);
    parent.appendChild(row);
}

/* English literal fallback; runtime values come from window.FabricatorBuilderI18n.countryNames. */
var COUNTRY_NAMES_EN = {
    AD:'Andorra',AE:'United Arab Emirates',AL:'Albania',AT:'Austria',AZ:'Azerbaijan',
    BA:'Bosnia and Herzegovina',BE:'Belgium',BG:'Bulgaria',BH:'Bahrain',BR:'Brazil',
    CH:'Switzerland',CR:'Costa Rica',CY:'Cyprus',CZ:'Czechia',DE:'Germany',
    DJ:'Djibouti',DK:'Denmark',DO:'Dominican Republic',EE:'Estonia',EG:'Egypt',
    ES:'Spain',FI:'Finland',FR:'France',GB:'United Kingdom',GE:'Georgia',
    GI:'Gibraltar',GL:'Greenland',GR:'Greece',GT:'Guatemala',HR:'Croatia',
    HU:'Hungary',IE:'Ireland',IL:'Israel',IQ:'Iraq',IS:'Iceland',IT:'Italy',
    JO:'Jordan',KW:'Kuwait',KZ:'Kazakhstan',LB:'Lebanon',LC:'St. Lucia',
    LI:'Liechtenstein',LT:'Lithuania',LU:'Luxembourg',LV:'Latvia',LY:'Libya',
    MA:'Morocco',MC:'Monaco',MD:'Moldova',ME:'Montenegro',MK:'North Macedonia',
    MR:'Mauritania',MT:'Malta',MU:'Mauritius',NI:'Nicaragua',NL:'Netherlands',
    NO:'Norway',PK:'Pakistan',PL:'Poland',PT:'Portugal',QA:'Qatar',
    RO:'Romania',RS:'Serbia',SA:'Saudi Arabia',SC:'Seychelles',SE:'Sweden',
    SI:'Slovenia',SK:'Slovakia',SM:'San Marino',SV:'El Salvador',TN:'Tunisia',
    TR:'Turkey',UA:'Ukraine',VA:'Vatican City',VG:'British Virgin Islands',XK:'Kosovo'
};
var COUNTRY_NAMES = mergeI18nMap(COUNTRY_NAMES_EN, (window.FabricatorBuilderI18n && window.FabricatorBuilderI18n.countryNames) || {});

function spCountryTags(parent, key, label, values, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);

    /* Unified box: chips + search input inline; clicking anywhere focuses the search input. */
    var box = document.createElement('div');
    box.className = 'fabricator-ctag-box';

    var search = document.createElement('input');
    search.type        = 'text';
    search.placeholder = _i18n.searchCountry || 'Search and add country…';
    search.className   = 'fabricator-ctag-search';
    search.autocomplete = 'off';

    var dropdown = document.createElement('div');
    dropdown.className = 'fabricator-ctag-dropdown';
    dropdown.hidden    = true;

    /* Clicking the box background focuses the search input */
    box.addEventListener('mousedown', function (e) {
        if (e.target === box) { e.preventDefault(); search.focus(); }
    });

    var current = values.slice();

    function chip(cc) {
        var c = document.createElement('span');
        c.className = 'fabricator-ctag-chip';

        var code = document.createElement('span');
        code.className   = 'fabricator-ctag-chip-code';
        code.textContent = cc;
        c.appendChild(code);

        var name = document.createElement('span');
        name.className   = 'fabricator-ctag-chip-name';
        name.textContent = COUNTRY_NAMES[cc] || '';
        c.appendChild(name);

        var rm = document.createElement('button');
        rm.type      = 'button';
        rm.className = 'fabricator-ctag-rm';
        rm.setAttribute('aria-label', _i18n.removeAriaLabel || 'Remove');
        rm.innerHTML = '&times;';
        rm.addEventListener('mousedown', function (e) {
            e.preventDefault();
            current = current.filter(function (x) { return x !== cc; });
            render();
            onChange(current.slice());
        });
        c.appendChild(rm);
        return c;
    }

    function render() {
        /* Clear everything except search and dropdown */
        Array.from(box.children).forEach(function (el) {
            if (el !== search && el !== dropdown) box.removeChild(el);
        });
        /* Insert chips before search */
        current.forEach(function (cc) {
            box.insertBefore(chip(cc), search);
        });
        search.placeholder = current.length ? '' : (_i18n.searchCountry || 'Search and add country…');
    }

    function positionDropdown() {
        var r          = box.getBoundingClientRect();
        var spaceBelow = window.innerHeight - r.bottom - 8;
        var spaceAbove = r.top - 8;

        dropdown.style.left  = r.left + 'px';
        dropdown.style.width = r.width + 'px';

        if (spaceBelow >= spaceAbove) {
            /* open downward, hard-cap height to available space */
            dropdown.style.top       = (r.bottom + 4) + 'px';
            dropdown.style.bottom    = '';
            dropdown.style.maxHeight = Math.min(220, spaceBelow) + 'px';
        } else {
            /* flip upward, cap to space above */
            dropdown.style.top       = '';
            dropdown.style.bottom    = (window.innerHeight - r.top + 4) + 'px';
            dropdown.style.maxHeight = Math.min(220, spaceAbove) + 'px';
        }
    }

    function renderDropdown(query) {
        dropdown.innerHTML = '';
        var q = query.trim().toUpperCase();
        var matches = Object.keys(COUNTRY_NAMES).filter(function (cc) {
            if (current.indexOf(cc) !== -1) return false;
            if (!q) return true;
            return cc.indexOf(q) !== -1 ||
                COUNTRY_NAMES[cc].toUpperCase().indexOf(q) !== -1;
        }).slice(0, 10);

        if (!matches.length) { dropdown.hidden = true; return; }

        matches.forEach(function (cc) {
            var opt = document.createElement('div');
            opt.className = 'fabricator-ctag-opt';
            var codeSpan = document.createElement('span');
            codeSpan.className   = 'fabricator-ctag-opt-code';
            codeSpan.textContent = cc;
            var nameSpan = document.createElement('span');
            nameSpan.className   = 'fabricator-ctag-opt-name';
            nameSpan.textContent = COUNTRY_NAMES[cc] || '';
            opt.appendChild(codeSpan);
            opt.appendChild(nameSpan);
            opt.addEventListener('mousedown', function (e) {
                e.preventDefault();
                if (current.indexOf(cc) === -1) current.push(cc);
                search.value = '';
                render();
                onChange(current.slice());
                search.focus();
                renderDropdown('');
            });
            dropdown.appendChild(opt);
        });
        positionDropdown();
        dropdown.hidden = false;
    }

    search.addEventListener('input', function () { renderDropdown(this.value); });
    search.addEventListener('focus', function () { renderDropdown(this.value); });
    search.addEventListener('blur',  function () {
        setTimeout(function () {
            dropdown.hidden = true;
        }, 150);
    });

    /* Reposition on scroll/resize; remove dropdown from DOM on row removal */
    function onScroll() { if (!dropdown.hidden) positionDropdown(); }
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);

    var observer = new MutationObserver(function () {
        if (!document.body.contains(box)) {
            dropdown.remove();
            observer.disconnect();
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    box.appendChild(search);
    document.body.appendChild(dropdown); /* fixed-position, outside scroll container */
    render();

    row.appendChild(box);
    parent.appendChild(row);
}

/* Stored value is the code string e.g. '+49'. English literal fallback; runtime values come from window.FabricatorBuilderI18n.phoneCodes. */
var PHONE_CALLING_CODES_EN = {
    '+1':   'USA / Canada',   '+7':   'Russia',         '+20':  'Egypt',
    '+27':  'South Africa',   '+30':  'Greece',         '+31':  'Netherlands',
    '+32':  'Belgium',        '+33':  'France',         '+34':  'Spain',
    '+36':  'Hungary',        '+39':  'Italy',          '+40':  'Romania',
    '+41':  'Switzerland',    '+43':  'Austria',        '+44':  'United Kingdom',
    '+45':  'Denmark',        '+46':  'Sweden',         '+47':  'Norway',
    '+48':  'Poland',         '+49':  'Germany',        '+51':  'Peru',
    '+52':  'Mexico',         '+54':  'Argentina',      '+55':  'Brazil',
    '+56':  'Chile',          '+57':  'Colombia',       '+61':  'Australia',
    '+62':  'Indonesia',      '+63':  'Philippines',    '+64':  'New Zealand',
    '+65':  'Singapore',      '+66':  'Thailand',       '+81':  'Japan',
    '+82':  'South Korea',    '+84':  'Vietnam',        '+86':  'China',
    '+90':  'Turkey',         '+91':  'India',          '+92':  'Pakistan',
    '+94':  'Sri Lanka',      '+98':  'Iran',           '+212': 'Morocco',
    '+213': 'Algeria',        '+216': 'Tunisia',        '+220': 'Gambia',
    '+234': 'Nigeria',        '+254': 'Kenya',          '+351': 'Portugal',
    '+352': 'Luxembourg',     '+353': 'Ireland',        '+354': 'Iceland',
    '+356': 'Malta',          '+357': 'Cyprus',         '+358': 'Finland',
    '+359': 'Bulgaria',       '+370': 'Lithuania',      '+371': 'Latvia',
    '+372': 'Estonia',        '+380': 'Ukraine',        '+385': 'Croatia',
    '+386': 'Slovenia',       '+420': 'Czechia',        '+421': 'Slovakia',
    '+423': 'Liechtenstein'
};
var PHONE_CALLING_CODES = mergeI18nMap(PHONE_CALLING_CODES_EN, (window.FabricatorBuilderI18n && window.FabricatorBuilderI18n.phoneCodes) || {});

function spCallingCodeTags(parent, key, label, values, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label || '';
    row.appendChild(lbl);

    var box = document.createElement('div');
    box.className = 'fabricator-ctag-box';

    var search = document.createElement('input');
    search.type         = 'text';
    search.placeholder  = (_i18n.searchDialCode || 'Search dial code (+49)…');
    search.className    = 'fabricator-ctag-search';
    search.autocomplete = 'off';

    var dropdown = document.createElement('div');
    dropdown.className = 'fabricator-ctag-dropdown';
    dropdown.hidden    = true;

    box.addEventListener('mousedown', function (e) {
        if (e.target === box) { e.preventDefault(); search.focus(); }
    });

    var current = values.slice();

    function chip(code) {
        var c = document.createElement('span');
        c.className = 'fabricator-ctag-chip';

        var codeSpan = document.createElement('span');
        codeSpan.className   = 'fabricator-ctag-chip-code';
        codeSpan.textContent = code;
        c.appendChild(codeSpan);

        var nameSpan = document.createElement('span');
        nameSpan.className   = 'fabricator-ctag-chip-name';
        nameSpan.textContent = PHONE_CALLING_CODES[code] || '';
        c.appendChild(nameSpan);

        var rm = document.createElement('button');
        rm.type      = 'button';
        rm.className = 'fabricator-ctag-rm';
        rm.setAttribute('aria-label', _i18n.removeAriaLabel || 'Remove');
        rm.innerHTML = '&times;';
        rm.addEventListener('mousedown', function (e) {
            e.preventDefault();
            current = current.filter(function (x) { return x !== code; });
            render();
            onChange(current.slice());
        });
        c.appendChild(rm);
        return c;
    }

    function render() {
        Array.from(box.children).forEach(function (el) {
            if (el !== search && el !== dropdown) box.removeChild(el);
        });
        current.forEach(function (code) { box.insertBefore(chip(code), search); });
        search.placeholder = current.length ? '' : (_i18n.searchDialCode || 'Search dial code (+49)…');
    }

    function positionDropdown() {
        var r = box.getBoundingClientRect();
        var spaceBelow = window.innerHeight - r.bottom - 8;
        var spaceAbove = r.top - 8;
        dropdown.style.left  = r.left + 'px';
        dropdown.style.width = r.width + 'px';
        if (spaceBelow >= spaceAbove) {
            dropdown.style.top       = (r.bottom + 4) + 'px';
            dropdown.style.bottom    = '';
            dropdown.style.maxHeight = Math.min(220, spaceBelow) + 'px';
        } else {
            dropdown.style.top       = '';
            dropdown.style.bottom    = (window.innerHeight - r.top + 4) + 'px';
            dropdown.style.maxHeight = Math.min(220, spaceAbove) + 'px';
        }
    }

    function renderDropdown(query) {
        dropdown.innerHTML = '';
        var q = query.trim().toLowerCase();
        var matches = Object.keys(PHONE_CALLING_CODES).filter(function (code) {
            if (current.indexOf(code) !== -1) return false;
            if (!q) return true;
            return code.indexOf(q) !== -1 ||
                PHONE_CALLING_CODES[code].toLowerCase().indexOf(q) !== -1;
        }).slice(0, 10);

        if (!matches.length) { dropdown.hidden = true; return; }

        matches.forEach(function (code) {
            var opt = document.createElement('div');
            opt.className = 'fabricator-ctag-opt';
            var cSpan = document.createElement('span');
            cSpan.className   = 'fabricator-ctag-opt-code';
            cSpan.textContent = code;
            var nSpan = document.createElement('span');
            nSpan.className   = 'fabricator-ctag-opt-name';
            nSpan.textContent = PHONE_CALLING_CODES[code] || '';
            opt.appendChild(cSpan);
            opt.appendChild(nSpan);
            opt.addEventListener('mousedown', function (e) {
                e.preventDefault();
                if (current.indexOf(code) === -1) current.push(code);
                search.value = '';
                render();
                onChange(current.slice());
                search.focus();
                renderDropdown('');
            });
            dropdown.appendChild(opt);
        });
        positionDropdown();
        dropdown.hidden = false;
    }

    search.addEventListener('input', function () { renderDropdown(this.value); });
    search.addEventListener('focus', function () { renderDropdown(this.value); });
    search.addEventListener('blur',  function () {
        setTimeout(function () { dropdown.hidden = true; }, 150);
    });

    function onScroll() { if (!dropdown.hidden) positionDropdown(); }
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);

    var observer = new MutationObserver(function () {
        if (!document.body.contains(box)) {
            dropdown.remove();
            observer.disconnect();
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    box.appendChild(search);
    document.body.appendChild(dropdown);
    render();

    row.appendChild(box);
    parent.appendChild(row);
}


/* Options list editor: accepts plain strings from legacy data and normalises them on first render. */
function spOptionsList(parent, key, label, values, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var titleRow = document.createElement('div');
    titleRow.className = 'fabricator-sp-label';
    titleRow.textContent = label;
    row.appendChild(titleRow);

    /* Normalise: plain strings or {value,label} → {value, label, default} */
    var opts = values.map(function (v) {
        if (v && typeof v === 'object') {
            return { value: v.value || '', label: v.label || '', 'default': !!v['default'] };
        }
        var str = String(v || '');
        return { value: str, label: str, 'default': false };
    });

    var colHeader = document.createElement('div');
    colHeader.className = 'fabricator-sp-option-cols-header';
    colHeader.innerHTML =
        '<span></span>' +
        '<span>' + escHtml(_i18n.optionLabelPlaceholder || 'Label') + '</span>' +
        '<span>' + escHtml(_i18n.valueWord || 'Value') + '</span>' +
        '<span title="' + escHtml(_i18n.preselected || 'Preselected') + '"><i class="fa-regular fa-star"></i></span>' +
        '<span></span>';
    row.appendChild(colHeader);

    var listEl = document.createElement('div');
    listEl.className = 'fabricator-sp-options-list';

    function rebuild() {
        listEl.innerHTML = '';
        opts.forEach(function (opt, i) {
            var optRow = document.createElement('div');
            optRow.className = 'fabricator-sp-option-row';

            /* Drag handle */
            var handle = document.createElement('span');
            handle.className = 'fabricator-sp-opt-handle';
            handle.innerHTML = '<i class="fa-solid fa-grip-vertical"></i>';
            optRow.appendChild(handle);

            var labelInp = document.createElement('input');
            labelInp.type        = 'text';
            labelInp.className   = 'fabricator-sp-opt-label';
            labelInp.value       = opt.label;
            labelInp.placeholder = _i18n.optionLabelPlaceholder || 'Label';
            labelInp.addEventListener('input', function () {
                opts[i].label = this.value;
                /* Only auto-derive value from label while it still matches slugify() of the label pre-keystroke; a hand-edited value breaks the match and stops auto-deriving. */
                if (!opts[i].value || opts[i].value === slugify(this.value.slice(0, -1))) {
                    opts[i].value = slugify(this.value);
                    var vi = optRow.querySelector('.fabricator-sp-opt-value');
                    if (vi) vi.value = opts[i].value;
                }
                onChange(opts.slice());
            });
            optRow.appendChild(labelInp);

            var valueInp = document.createElement('input');
            valueInp.type        = 'text';
            valueInp.className   = 'fabricator-sp-opt-value';
            valueInp.value       = opt.value;
            valueInp.placeholder = _i18n.optionValuePlaceholder || 'value';
            valueInp.addEventListener('input', function () {
                opts[i].value = this.value;
                onChange(opts.slice());
            });
            optRow.appendChild(valueInp);

            /* Default-selected star toggle */
            var starBtn = document.createElement('button');
            starBtn.type      = 'button';
            starBtn.className = 'fabricator-sp-opt-star' + (opt['default'] ? ' fabricator-sp-opt-star--active' : '');
            starBtn.title     = _i18n.preselected || 'Preselected';
            starBtn.innerHTML = opt['default']
                ? '<i class="fa-solid fa-star"></i>'
                : '<i class="fa-regular fa-star"></i>';
            starBtn.addEventListener('click', function () {
                var isNowActive = !opts[i]['default'];
                opts.forEach(function (o) { o['default'] = false; });
                opts[i]['default'] = isNowActive;
                rebuild();
                onChange(opts.slice());
            });
            optRow.appendChild(starBtn);

            var rm = document.createElement('button');
            rm.type      = 'button';
            rm.className = 'fabricator-sp-option-remove';
            rm.innerHTML = '<i class="fa-solid fa-trash"></i>';
            rm.addEventListener('click', function () { opts.splice(i, 1); rebuild(); onChange(opts.slice()); });
            optRow.appendChild(rm);

            optRow.dataset.optIdx = String(i);
            listEl.appendChild(optRow);
        });
    }
    rebuild();
    makeSortable(listEl, '.fabricator-sp-opt-handle', '.fabricator-sp-option-row', function (rows) {
        var reordered = rows.map(function (r) { return opts[parseInt(r.dataset.optIdx, 10)]; });
        opts.length = 0;
        reordered.forEach(function (o) { opts.push(o); });
        rebuild();
        onChange(opts.slice());
    });
    row.appendChild(listEl);

    var addBtn = document.createElement('button');
    addBtn.type      = 'button';
    addBtn.className = 'fabricator-sp-add-option';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> ' + escHtml(_i18n.addOption || 'Add option');
    addBtn.addEventListener('click', function () {
        var n = opts.length + 1;
        opts.push({ value: 'option-' + n, label: 'Option ' + n, 'default': false });
        rebuild();
        onChange(opts.slice());
        var inputs = listEl.querySelectorAll('.fabricator-sp-opt-label');
        if (inputs.length) inputs[inputs.length - 1].focus();
    });
    row.appendChild(addBtn);
    parent.appendChild(row);
}

/* Labeled segmented control for a boolean field value */
function spBoolSeg(parent, key, label, value, falseLabel, trueLabel, onChange, swap) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);
    var vals   = swap ? ['1', '0'] : ['0', '1'];
    var labels = swap ? [trueLabel, falseLabel] : [falseLabel, trueLabel];
    row.appendChild(mkSeg(vals, labels, value ? '1' : '0', function (v) { onChange(v === '1'); }));
    parent.appendChild(row);
}

function spSectionTitle(parent, text) {
    var t = document.createElement('div');
    t.className   = 'fabricator-sp-section-title';
    t.textContent = text;
    parent.appendChild(t);
}

function spLimitRow(parent, typeKey, label, countKey, typeVal, countVal, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);
    var inner = document.createElement('div');
    inner.className = 'fabricator-sp-limit-row';
    var pill = mkSeg(['chars', 'words'], [_i18n.unitChars || 'Characters', _i18n.unitWords || 'Words'], typeVal || 'chars', function (v) {
        onChange(v, inp.value);
    });
    inner.appendChild(pill);
    var inp = document.createElement('input');
    inp.type        = 'number';
    inp.min         = '0';
    /* Must match BaseField::OTHER_TEXT_HARD_CAP — a value configured above this
       is silently clamped server-side, so don't let the admin set one here. */
    inp.max         = '5000';
    inp.className   = 'fabricator-sp-input';
    inp.value       = countVal !== null && countVal !== undefined ? String(countVal) : '';
    inp.placeholder = '∞';
    inp.addEventListener('input', function () {
        var curType = pill.querySelector('.fabricator-seg-btn.fabricator-seg-active');
        onChange(curType ? curType.dataset.value : 'chars', this.value);
    });
    inner.appendChild(inp);
    row.appendChild(inner);
    parent.appendChild(row);
}

function spNotice(parent, text, level) {
    var el = document.createElement('div');
    el.className   = 'fabricator-sp-notice fabricator-sp-notice--' + (level || 'info');
    el.textContent = text;
    parent.appendChild(el);
}

/* HTML/Section editor: textarea, optionally with a Code|Vorschau toggle */
function spHtmlEditor(parent, key, label, value, onChange, opts) {
    opts = opts || {};
    var showToggle = opts.showToggle !== false;

    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var hdr = document.createElement('div');
    hdr.className = 'fabricator-sp-html-hdr';

    if (label) {
        var lbl = document.createElement('div');
        lbl.className   = 'fabricator-sp-label';
        lbl.textContent = label;
        hdr.appendChild(lbl);
    }

    var ta = document.createElement('textarea');
    ta.className  = 'fabricator-sp-input fabricator-sp-html-textarea';
    ta.rows       = 8;
    ta.value      = value || '';
    ta.spellcheck = false;

    var previewDiv;
    if (showToggle) {
        var modeBar = document.createElement('div');
        modeBar.className = 'fabricator-sp-html-modebar';
        var modePill = mkSeg(['code', 'preview'], [_i18n.modeCode || 'Code', _i18n.modePreview || 'Preview'], 'code', switchMode);
        modeBar.appendChild(modePill);
        hdr.appendChild(modeBar);

        // Sandboxed iframe (no allow-scripts), not innerHTML — avoids a stored self-XSS between admin accounts.
        previewDiv = document.createElement('iframe');
        previewDiv.className = 'fabricator-sp-html-preview';
        previewDiv.setAttribute('sandbox', 'allow-same-origin');
        previewDiv.hidden    = true;
        previewDiv.srcdoc    = value || '';
    }

    if (hdr.children.length) row.appendChild(hdr);

    var resizeTimer = null;
    function resizeTa() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            ta.style.height = 'auto';
            ta.style.height = Math.max(ta.scrollHeight, 140) + 'px';
        }, 30);
    }

    ta.addEventListener('input', function () {
        onChange(this.value);
        if (previewDiv && !previewDiv.hidden) previewDiv.srcdoc = this.value;
        resizeTa();
    });
    row.appendChild(ta);
    if (previewDiv) row.appendChild(previewDiv);

    ta.style.overflowY = 'hidden';
    ta.style.resize     = 'none';
    resizeTa();
    if (window.IntersectionObserver) {
        var taIo = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { if (entry.isIntersecting) resizeTa(); });
        });
        taIo.observe(row);
    }

    function switchMode(mode) {
        if (mode === 'preview') {
            ta.hidden = true;
            previewDiv.hidden = false;
            previewDiv.srcdoc = ta.value;
        } else {
            ta.hidden = false;
            previewDiv.hidden = true;
        }
    }

    parent.appendChild(row);
}

/* designMode, not contenteditable — contenteditable strips the <html>/<head>/<body> wrapper. */
/* HTML field editor: Visuell|Code toggle — same pattern as buildNotifContentTab */
function spHtmlFieldEditor(parent, key, label, value, onChange) {
    var currentValue = value || '';
    var currentMode  = 'text'; /* 'text' = visual iframe, 'html' = plain code textarea */

    /* Header row: label + Visuell|Code pill */
    var hdrWrap = document.createElement('div');
    hdrWrap.className = 'fabricator-sp-row';
    var hdr = document.createElement('div');
    hdr.className = 'fabricator-sp-html-hdr';
    if (label) {
        var lbl = document.createElement('div');
        lbl.className   = 'fabricator-sp-label';
        lbl.textContent = label;
        hdr.appendChild(lbl);
    }
    var modeBar = document.createElement('div');
    modeBar.className = 'fabricator-sp-html-modebar';
    modeBar.appendChild(mkSeg(['text', 'html'], [_i18n.modeVisual || 'Visual', _i18n.modeCode || 'Code'], 'text', function (v) {
        currentMode = v;
        renderEditor();
    }));
    hdr.appendChild(modeBar);
    hdrWrap.appendChild(hdr);
    parent.appendChild(hdrWrap);

    /* Swap container — editor re-renders here on mode change */
    var contentWrap = document.createElement('div');
    parent.appendChild(contentWrap);

    function renderEditor() {
        contentWrap.innerHTML = '';
        if (currentMode === 'html') {
            spHtmlEditor(contentWrap, key, '', currentValue, function (v) {
                currentValue = v;
                onChange(v);
            }, { showToggle: false });
        } else {
            // fragmentOnly: stores an HTML fragment, not a full document (unlike the notification-body editor).
            spRichTextEditor(contentWrap, key, '', currentValue, function (v) {
                currentValue = v;
                onChange(v);
            }, { fragmentOnly: true });
        }
    }

    renderEditor();
}

function spRichTextEditor(parent, key, label, value, onChange, opts) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    if (label) {
        var lbl = document.createElement('div');
        lbl.className   = 'fabricator-sp-label';
        lbl.textContent = label;
        row.appendChild(lbl);
    }

    var toolbar = document.createElement('div');
    toolbar.className = 'fabricator-sp-richtext-toolbar';
    var commands = [
        ['bold', _i18n.rtBold || 'Bold', 'fa-bold'],
        ['italic', _i18n.rtItalic || 'Italic', 'fa-italic'],
        ['underline', _i18n.rtUnderline || 'Underline', 'fa-underline'],
        ['insertUnorderedList', _i18n.rtBulletList || 'List', 'fa-list-ul'],
        ['insertOrderedList', _i18n.rtNumberedList || 'Numbered list', 'fa-list-ol'],
        ['createLink', _i18n.rtLink || 'Link', 'fa-link'],
    ];
    commands.forEach(function (cmd) {
        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'fabricator-sp-richtext-btn';
        btn.title     = cmd[1];
        btn.innerHTML = '<i class="fa-solid ' + cmd[2] + '"></i>';
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function () {
            var doc = iframe.contentDocument;
            iframe.contentWindow.focus();
            if (cmd[0] === 'createLink') {
                var url = window.prompt(_i18n.linkUrlPrompt || 'Enter link URL:', 'https://');
                if (!url || /^\s*(javascript|vbscript|data):/i.test(url)) return;
                doc.execCommand('createLink', false, url);
            } else {
                doc.execCommand(cmd[0], false, null);
            }
            emitChange();
        });
        toolbar.appendChild(btn);
    });
    row.appendChild(toolbar);

    var iframe = document.createElement('iframe');
    iframe.className = 'fabricator-sp-input fabricator-sp-richtext-editor';
    iframe.setAttribute('sandbox', 'allow-same-origin');
    iframe.setAttribute('title', label || (_i18n.message || 'Message'));
    row.appendChild(iframe);
    parent.appendChild(row);

    function emitChange() {
        var out = (opts && opts.fragmentOnly)
            ? sanitizeRichDocFragment(iframe.contentDocument)
            : sanitizeRichDoc(iframe.contentDocument);
        onChange(out);
        resize();
    }

    var resizeTimer = null;
    function resize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(doResize, 30);
    }

    function doResize() {
        var doc = iframe.contentDocument;
        if (!doc || !doc.documentElement || !doc.body) return;
        /* scroll metrics are unreliable with overflow:hidden containers, so measure real element bottom edges instead. */
        var max = 0;
        var nodes = doc.body.querySelectorAll('*');
        for (var i = 0; i < nodes.length; i++) {
            var bottom = nodes[i].getBoundingClientRect().bottom;
            if (bottom > max) max = bottom;
        }
        var bodyRect = doc.body.getBoundingClientRect();
        if (bodyRect.bottom > max) max = bodyRect.bottom;

        var fallback = Math.max(
            doc.documentElement.scrollHeight,
            doc.documentElement.offsetHeight,
            doc.body.scrollHeight,
            doc.body.offsetHeight
        );
        var h = Math.max(max, fallback);
        iframe.style.height = Math.max(Math.ceil(h) + 2, 140) + 'px';
    }

    function loadDoc(html) {
        var doc = iframe.contentDocument;
        if (!doc) {
            /* iframe is in a detached subtree — defer until caller inserts the row into the live DOM. */
            setTimeout(function () { loadDoc(html); }, 0);
            return;
        }
        // id="fabricator-editor-preview-style" is display-only scaffolding; cleanRichDoc() strips it so it never leaks into a sent notification email.
        var hasHtmlDoc = /<html[\s>]/i.test(html || '');
        var full = hasHtmlDoc
            ? html
            : '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
                + '<body>' + (html || '') + '</body></html>';
        doc.open();
        doc.write(full);
        doc.close();
        doc.designMode = 'on';
        doc.addEventListener('input', emitChange);

        // Re-inject the scaffold whenever it's missing (e.g. reloading a saved doc, which had it stripped on save) or reload falls back to unstyled defaults.
        if (!doc.getElementById('fabricator-editor-preview-style')) {
            var scaffold = doc.createElement('style');
            scaffold.id = 'fabricator-editor-preview-style';
            scaffold.textContent = 'html,body{margin:0;}'
                + 'body{padding:10px 12px;box-sizing:border-box;'
                + 'font-family:-apple-system,Segoe UI,Arial,sans-serif;'
                + 'font-size:13px;line-height:1.6;color:#1d2327;}'
                /* Collapse only the first child's top margin, else UA default spacing reads as a blank line. */
                + 'body>:first-child{margin-top:0;}';
            if (doc.head) {
                doc.head.appendChild(scaffold);
            } else {
                doc.documentElement.insertBefore(scaffold, doc.body);
            }
        }

        /* A horizontal scrollbar would eat into clientHeight and force unwanted vertical scroll, so clip overflow-x instead. */
        doc.documentElement.style.overflowX = 'hidden';
        doc.body.style.overflowX = 'hidden';

        /* Images load async and can change the height after the fact. */
        doc.querySelectorAll('img').forEach(function (img) {
            img.addEventListener('load', resize);
            img.addEventListener('error', resize);
        });

        resize();
    }

    loadDoc(value);

    /* Catch late layout shifts (fonts, async assets). */
    setTimeout(resize, 300);

    /* Tab starts hidden, so resize() above measures a 0x0 box and falls back to 140px until it's visible. */
    if (window.IntersectionObserver) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) resize();
            });
        });
        io.observe(row);
    }
}

/* Shared cleaning pass used by both serialization modes below — mutates doc in place, returns nothing. */
function cleanRichDoc(doc) {
    // Editor's own display-only scaffold (see loadDoc()) — must not survive into the fragment/full-document output (sent as emails).
    var editorStyle = doc.getElementById('fabricator-editor-preview-style');
    if (editorStyle) { editorStyle.remove(); }
    // loadDoc() also sets overflow-x:hidden inline on <html>/<body> for the editor widget; that's scaffolding too and must not leak into the stored/sent document.
    [doc.documentElement, doc.body].forEach(function (el) {
        if (!el) { return; }
        el.style.removeProperty('overflow-x');
        if (!el.getAttribute('style')) { el.removeAttribute('style'); }
    });
    doc.querySelectorAll('script').forEach(function (el) { el.remove(); });
    doc.querySelectorAll(
        'style.darkreader, link.darkreader, meta[name="darkreader"], .darkreader--fallback'
    ).forEach(function (el) { el.remove(); });
    doc.querySelectorAll('*').forEach(function (el) {
        Array.prototype.slice.call(el.attributes).forEach(function (attr) {
            var name = attr.name.toLowerCase();
            var val  = attr.value || '';
            if (/^on/.test(name) || name.indexOf('data-darkreader') === 0) {
                el.removeAttribute(attr.name);
            } else if ((name === 'href' || name === 'src' || name === 'xlink:href') && /^\s*(javascript|vbscript):/i.test(val)) {
                el.removeAttribute(attr.name);
            }
        });
    });
}

/* Full-document serialization — used only by the notification-body editor, which stores a complete HTML document. */
function sanitizeRichDoc(doc) {
    // Clean a detached clone, not the live doc — stripping the editor's display <style> from the live doc would break the visible editing surface while the admin is typing.
    var clone = doc.cloneNode(true);
    cleanRichDoc(clone);
    return (clone.doctype ? '<!DOCTYPE ' + clone.doctype.name + '>' : '')
        + clone.documentElement.outerHTML;
}

/* Fragment-only serialization: discards the doctype/<html>/<head> wrapper loadDoc() injects purely for iframe display. */
function sanitizeRichDocFragment(doc) {
    // See sanitizeRichDoc()'s comment above — same reason to clean a clone, not the live doc.
    var clone = doc.cloneNode(true);
    cleanRichDoc(clone);
    return clone.body ? clone.body.innerHTML : '';
}

/* Back-compat: migrates legacy body_html_content/body_text_content into the canonical HTML body field. */
function normalizeNotifBodyFields(notif) {
    if (notif.body === undefined) {
        var legacy = notif.body_html ? (notif.body_html_content || '')
            : (notif.body_text_content || '');
        notif.body = /<[a-z][\s\S]*>/i.test(legacy)
            ? legacy
            : (function (text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML.replace(/\n/g, '<br>');
            })(legacy);
    }
    delete notif.body_html_content;
    delete notif.body_text_content;
    return notif;
}

/* Rating icon row: select (icon type) + half-values pill in one line */
function spIconRow(parent, iconKey, label, options, iconVal, halfKey, halfVal, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);

    var inner = document.createElement('div');
    inner.className = 'fabricator-sp-icon-row';

    var sel = document.createElement('select');
    sel.className = 'fabricator-sp-input';
    options.forEach(function (opt) {
        var o = document.createElement('option');
        o.value       = opt.value !== undefined ? opt.value : opt;
        o.textContent = opt.label !== undefined ? opt.label : opt;
        if (String(o.value) === String(iconVal)) o.selected = true;
        sel.appendChild(o);
    });

    var halfPill = mkSeg(
        ['0', '1'], [_i18n.wholeValues || 'Whole values', _i18n.halfValues || 'Half values'],
        halfVal ? '1' : '0',
        function (v) { onChange(sel.value, v === '1'); }
    );

    sel.addEventListener('change', function () {
        var active = halfPill.querySelector('.fabricator-seg-btn.fabricator-seg-active');
        onChange(this.value, active ? active.dataset.value === '1' : false);
    });

    inner.appendChild(sel);
    inner.appendChild(halfPill);
    row.appendChild(inner);
    parent.appendChild(row);
}

/* Time format + prefill in one row */
function spTimeRow(parent, formatKey, prefillKey, formatVal, prefillVal, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-time-row';

    /* Left: Format pill */
    var fmtWrap = document.createElement('div');
    fmtWrap.className = 'fabricator-sp-time-fmt';
    var fmtLbl = document.createElement('div');
    fmtLbl.className   = 'fabricator-sp-label';
    fmtLbl.textContent = _i18n.formatLabel || 'Format';
    fmtWrap.appendChild(fmtLbl);
    var fmtPill = mkSeg(['0', '1'], [_i18n.format24h || '24h', _i18n.format12h || '12h (AM/PM)'], formatVal ? '1' : '0', function (v) {
        onChange(v === '1', prefillChk.checked);
    });
    fmtWrap.appendChild(fmtPill);

    /* Right: Prefill toggle */
    var prefillWrap = document.createElement('div');
    prefillWrap.className = 'fabricator-sp-time-prefill';
    var toggleLbl = document.createElement('label');
    toggleLbl.className = 'fabricator-toggle';
    var prefillChk = document.createElement('input');
    prefillChk.type    = 'checkbox';
    prefillChk.checked = !!prefillVal;
    prefillChk.addEventListener('change', function () {
        var active = fmtPill.querySelector('.fabricator-seg-btn.fabricator-seg-active');
        onChange(active ? active.dataset.value === '1' : false, this.checked);
    });
    toggleLbl.appendChild(prefillChk);
    var slider = document.createElement('span');
    slider.className = 'fabricator-toggle-slider';
    toggleLbl.appendChild(slider);
    var textLbl = document.createElement('label');
    textLbl.className   = 'fabricator-toggle-label';
    textLbl.textContent = _i18n.prefillNow || 'Prefill now';
    prefillWrap.appendChild(toggleLbl);
    prefillWrap.appendChild(textLbl);

    row.appendChild(fmtWrap);
    row.appendChild(prefillWrap);
    parent.appendChild(row);
}

/* Rating live preview — renders current icon/half/max config */
function spRatingPreview(parent, field) {
    /* Filled glyph at low opacity for "empty" state, avoiding the Unicode size mismatch between filled/outline glyph pairs. */
    var ICONS_JS = {
        star:    '★',
        heart:   '♥',
        circle:  '●',
        diamond: '◆',
    };
    var max       = parseInt(field.max || 5, 10);
    var half      = !!field.allow_half;
    var custom    = !!field.icon_source && !!field.custom_icon_url;
    var iconKey   = field.icon_type || 'star';
    var glyph     = ICONS_JS[iconKey] || ICONS_JS.star;
    var customUrl = custom ? field.custom_icon_url : '';

    var wrap = document.createElement('div');
    wrap.className = 'fabricator-sp-rating-preview';

    var filledFull = Math.floor(max * 0.6);
    var filledHalf = half && (filledFull < max) ? 1 : 0;

    for (var i = 1; i <= max; i++) {
        var outer = document.createElement('span');
        outer.className = 'fabricator-sp-rating-star';

        var isFull     = i <= filledFull;
        var isHalfStep = !isFull && half && (i === filledFull + 1) && filledHalf;

        if (customUrl) {
            outer.className += ' fabricator-sp-rating-img-star';
            var baseImg = document.createElement('img');
            baseImg.src    = customUrl;
            baseImg.width  = 24;
            baseImg.height = 24;
            baseImg.style.opacity = isFull ? '1' : '0.25';
            outer.appendChild(baseImg);
            if (isHalfStep) {
                outer.className += ' fabricator-sp-rating-half';
                var clipImg = document.createElement('img');
                clipImg.src       = customUrl;
                clipImg.width     = 24;
                clipImg.height    = 24;
                clipImg.className = 'fabricator-sp-rating-half-fill';
                outer.appendChild(clipImg);
            }
        } else if (isHalfStep) {
            outer.className += ' fabricator-sp-rating-half';
            var baseSpan = document.createElement('span');
            baseSpan.textContent      = glyph;
            baseSpan.style.opacity    = '0.2';
            outer.appendChild(baseSpan);
            var clipSpan = document.createElement('span');
            clipSpan.className   = 'fabricator-sp-rating-half-fill';
            clipSpan.textContent = glyph;
            outer.appendChild(clipSpan);
        } else {
            outer.textContent  = glyph;
            outer.style.opacity = isFull ? '1' : '0.2';
        }
        wrap.appendChild(outer);
    }

    parent.appendChild(wrap);
}

/* Sub-fields editor: enable toggle is independent of the header's expand/collapse click. */
function spSubfields(parent, items, field, change) {
    spSectionTitle(parent, _i18n.subfieldsSection || 'Subfields');

    var wrap = document.createElement('div');
    wrap.className = 'fabricator-sp-subfields';

    items.forEach(function (item) {
        var k = item.key;

        var card = document.createElement('div');
        card.className = 'fabricator-sp-subfield-card';

        var isEnabled = item.optional ? field[k + '_enabled'] !== false : true;
        if (item.optional && !isEnabled) {
            card.classList.add('fabricator-sp-subfield--off');
        }

        /* ---- header: [toggle] [name] [chevron] ---- */
        var header = document.createElement('div');
        header.className = 'fabricator-sp-subfield-hdr';

        /* stop-propagation so the enable toggle doesn't also fire the accordion header handler */
        var togWrap = document.createElement('label');
        togWrap.className = 'fabricator-toggle fabricator-toggle--sm';
        togWrap.title     = _i18n.enableSubfield || 'Enable subfield';
        togWrap.addEventListener('click', function (e) { e.stopPropagation(); });
        var togInp = document.createElement('input');
        togInp.type    = 'checkbox';
        togInp.checked = isEnabled;
        (function (cardEl, sfKey, optional) {
            togInp.addEventListener('change', function () {
                var v = this.checked;
                if (optional) {
                    change(sfKey + '_enabled', v);
                    cardEl.classList.toggle('fabricator-sp-subfield--off', !v);
                }
            });
        }(card, k, item.optional));
        /* Non-optional sub-fields: toggle is always checked and disabled */
        if (!item.optional) {
            togInp.checked  = true;
            togInp.disabled = true;
        }
        togWrap.appendChild(togInp);
        var togSlider = document.createElement('span');
        togSlider.className = 'fabricator-toggle-slider';
        togWrap.appendChild(togSlider);
        header.appendChild(togWrap);

        var nameLbl = document.createElement('span');
        nameLbl.className   = 'fabricator-sp-subfield-name';
        nameLbl.textContent = item.label;
        header.appendChild(nameLbl);

        /* Chevron on the RIGHT */
        var chevron = document.createElement('span');
        chevron.className = 'fabricator-sp-subfield-chevron';
        chevron.innerHTML = '<i class="fa-solid fa-chevron-down"></i>';
        header.appendChild(chevron);

        card.appendChild(header);

        /* ---- body (collapsed by default) ---- */
        var body = document.createElement('div');
        body.className = 'fabricator-sp-subfield-body fabricator-sp-subfield-body--collapsed';

        /* Label */
        var lblRow = document.createElement('div');
        lblRow.className = 'fabricator-sp-subrow';
        var lblLabel = document.createElement('label');
        lblLabel.className   = 'fabricator-sp-sublabel';
        lblLabel.textContent = _i18n.labelField || 'Label';
        var lblInp = document.createElement('input');
        lblInp.type      = 'text';
        lblInp.className = 'fabricator-sp-input';
        lblInp.value     = field[k + '_label'] !== undefined ? field[k + '_label'] : item.label;
        (function (sfKey) {
            lblInp.addEventListener('input', function () { change(sfKey + '_label', this.value); });
        }(k));
        lblRow.appendChild(lblLabel);
        lblRow.appendChild(lblInp);
        body.appendChild(lblRow);

        /* Placeholder (skip for prefix select) */
        if (!item.is_select) {
            var phRow = document.createElement('div');
            phRow.className = 'fabricator-sp-subrow';
            var phLabel = document.createElement('label');
            phLabel.className   = 'fabricator-sp-sublabel';
            phLabel.textContent = _i18n.placeholderLabel || 'Placeholder';
            var phInp = document.createElement('input');
            phInp.type      = 'text';
            phInp.className = 'fabricator-sp-input';
            phInp.value     = field[k + '_placeholder'] || '';
            (function (sfKey) {
                phInp.addEventListener('input', function () { change(sfKey + '_placeholder', this.value); });
            }(k));
            phRow.appendChild(phLabel);
            phRow.appendChild(phInp);
            body.appendChild(phRow);
        }

        /* Required toggle */
        var reqRow = document.createElement('div');
        reqRow.className = 'fabricator-sp-toggle-row fabricator-sp-subrow-toggle';
        var reqTogWrap = document.createElement('label');
        reqTogWrap.className = 'fabricator-toggle';
        var reqInp = document.createElement('input');
        reqInp.type    = 'checkbox';
        reqInp.checked = !!field[k + '_required'];
        (function (sfKey) {
            reqInp.addEventListener('change', function () { change(sfKey + '_required', this.checked); });
        }(k));
        reqTogWrap.appendChild(reqInp);
        var reqSlider = document.createElement('span');
        reqSlider.className = 'fabricator-toggle-slider';
        reqTogWrap.appendChild(reqSlider);
        var reqLabel = document.createElement('label');
        reqLabel.className   = 'fabricator-toggle-label';
        reqLabel.textContent = _i18n.requiredField || 'Required field';
        reqRow.appendChild(reqTogWrap);
        reqRow.appendChild(reqLabel);
        body.appendChild(reqRow);

        card.appendChild(body);

        /* Accordion toggle on header click */
        header.addEventListener('click', function () {
            var collapsed = body.classList.toggle('fabricator-sp-subfield-body--collapsed');
            chevron.classList.toggle('fabricator-sp-subfield-chevron--up', !collapsed);
        });

        wrap.appendChild(card);
    });

    parent.appendChild(wrap);
}

/* Notifications — list + modal pattern (mirrors field list) */
var notifModal    = null;
var notifModalIdx = null;

function renderNotifications() {
    var panel = document.getElementById('fabricator-notifications-panel');
    if (!panel) return;
    panel.innerHTML = '';

    var list = document.createElement('div');
    list.id = 'fabricator-notif-list';
    if (state.notifications.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'fabricator-empty-canvas';
        empty.innerHTML =
            '<div class="fabricator-empty-icon"><i class="fa-solid fa-bell"></i></div>' +
            '<p>' + escHtml(_i18n.noNotificationsHtml || 'No notifications yet. Click') + ' <strong>' + escHtml(_i18n.addNotification || 'Add notification') + '</strong>.</p>';
        list.appendChild(empty);
    } else {
        state.notifications.forEach(function (notif, idx) {
            list.appendChild(buildNotifRow(notif, idx));
        });
    }
    panel.appendChild(list);

    var bar = document.createElement('div');
    bar.id = 'fabricator-add-notif-bar';
    var addBtn = document.createElement('button');
    addBtn.type      = 'button';
    addBtn.id        = 'fabricator-add-notif-btn';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> ' + escHtml(_i18n.addNotification || 'Add notification');
    addBtn.addEventListener('click', function () {
        markDirty();
        var n = state.notifications.length + 1;
        state.notifications.push({
            slug: 'notification-' + n,
            name: 'Benachrichtigung ' + n,
            to: '{admin_email}', subject: 'Formular: {form_title}',
            body: '{all_fields}',
            from_name: '{site_name}',
            from_email: '{admin_email}', attach_pdf: true, enabled: true,
        });
        renderNotifications();
        openNotifModal(state.notifications.length - 1);
    });
    bar.appendChild(addBtn);
    panel.appendChild(bar);
}

function buildNotifRow(notif, idx) {
    var row = document.createElement('div');
    row.className = 'fabricator-field-row';

    var recipientText = notif.recipient_mode === 'routing'
        ? (_i18n.routingActive || 'Routing active')
        : (notif.to || '');

    row.innerHTML =
        '<div class="fabricator-row-handle" style="visibility:hidden"><i class="fa-solid fa-grip-vertical"></i></div>' +
        '<div class="fabricator-row-icon"><i class="fa-solid fa-bell"></i></div>' +
        '<div class="fabricator-row-info">' +
            '<div class="fabricator-row-label">' + escHtml(notif.name || (_i18n.noName || '(no name)')) + '</div>' +
            '<div class="fabricator-row-type">' + escHtml(recipientText) + '</div>' +
        '</div>' +
        '<div class="fabricator-row-actions">' +
            '<button class="fabricator-row-btn fabricator-row-edit"   title="' + escHtml(_i18n.edit || 'Edit') + '"><i class="fa-solid fa-pen-to-square"></i></button>' +
            '<button class="fabricator-row-btn fabricator-row-delete" title="' + escHtml(_i18n.remove || 'Remove') + '"><i class="fa-solid fa-trash"></i></button>' +
        '</div>';

    row.addEventListener('click', function (e) {
        if (e.target.closest('.fabricator-row-delete')) return;
        openNotifModal(idx);
    });
    row.querySelector('.fabricator-row-delete').addEventListener('click', function (e) {
        e.stopPropagation();
        markDirty();
        state.notifications.splice(idx, 1);
        renderNotifications();
    });

    return row;
}

function createNotifModal() {
    notifModal           = document.createElement('div');
    notifModal.id        = 'fabricator-notif-modal';
    notifModal.className = 'fabricator-modal-backdrop';
    notifModal.hidden    = true;
    notifModal.innerHTML =
        '<div class="fabricator-modal fabricator-modal--settings" role="dialog" aria-modal="true">' +
            '<div class="fabricator-modal-header">' +
                '<div class="fabricator-settings-titlerow">' +
                    '<span class="fabricator-settings-field-icon"><i class="fa-solid fa-bell"></i></span>' +
                    '<input type="text" id="fabricator-notif-name-inp" class="fabricator-notif-modal-name" placeholder="' + escHtml(_i18n.notificationPlaceholder || 'Notification') + '">' +
                '</div>' +
                '<button class="fabricator-modal-close" type="button">&#x2715;</button>' +
            '</div>' +
            '<div class="fabricator-stab-bar">' +
                '<button class="fabricator-stab fabricator-stab-active" data-nstab="recipient">' + escHtml(_i18n.tabRecipient || 'Recipient') + '</button>' +
                '<button class="fabricator-stab" data-nstab="content">' + escHtml(_i18n.tabContent || 'Content') + '</button>' +
                '<button class="fabricator-stab" data-nstab="sender">' + escHtml(_i18n.tabSender || 'Sender') + '</button>' +
            '</div>' +
            '<div class="fabricator-modal-body fabricator-settings-body">' +
                '<div id="fabricator-nstab-recipient" class="fabricator-stab-panel fabricator-stab-active"></div>' +
                '<div id="fabricator-nstab-content"   class="fabricator-stab-panel"></div>' +
                '<div id="fabricator-nstab-sender"    class="fabricator-stab-panel"></div>' +
            '</div>' +
            '<div class="fabricator-settings-footer">' +
                '<button class="fabricator-btn-primary" id="fabricator-notif-done">' + escHtml(_i18n.done || 'Done') + '</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(notifModal);
    notifModal.querySelector('.fabricator-modal-close').addEventListener('click', closeNotifModal);
    notifModal.querySelector('#fabricator-notif-done').addEventListener('click', closeNotifModal);
    notifModal.addEventListener('click', function (e) { if (e.target === notifModal) closeNotifModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !notifModal.hidden) closeNotifModal(); });
    notifModal.querySelectorAll('.fabricator-stab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            notifModal.querySelectorAll('.fabricator-stab').forEach(function (b) { b.classList.remove('fabricator-stab-active'); });
            notifModal.querySelectorAll('.fabricator-stab-panel').forEach(function (p) { p.classList.remove('fabricator-stab-active'); });
            btn.classList.add('fabricator-stab-active');
            var p = document.getElementById('fabricator-nstab-' + btn.dataset.nstab);
            if (p) p.classList.add('fabricator-stab-active');
        });
    });
}

function openNotifModal(idx) {
    if (!notifModal) createNotifModal();
    if (!notifModal) return;
    notifModalIdx = idx;
    var notif = state.notifications[idx];

    /* Reset to first tab */
    notifModal.querySelectorAll('.fabricator-stab').forEach(function (b) { b.classList.remove('fabricator-stab-active'); });
    notifModal.querySelectorAll('.fabricator-stab-panel').forEach(function (p) { p.classList.remove('fabricator-stab-active'); });
    notifModal.querySelector('[data-nstab="recipient"]').classList.add('fabricator-stab-active');
    document.getElementById('fabricator-nstab-recipient').classList.add('fabricator-stab-active');

    var nameInp = document.getElementById('fabricator-notif-name-inp');
    nameInp.value  = notif.name || '';
    nameInp.oninput = function () { markDirty(); state.notifications[notifModalIdx].name = this.value; };

    buildNotifRecipientTab(notif);
    buildNotifContentTab(notif);
    buildNotifSenderTab(notif);

    notifModal.hidden = false;
    document.body.style.overflow = 'hidden';
}

function closeNotifModal() {
    if (!notifModal) return;
    notifModal.hidden = true;
    document.body.style.overflow = '';
    notifModalIdx = null;
    renderNotifications();
}

function buildNotifRecipientTab(notif) {
    var panel = document.getElementById('fabricator-nstab-recipient');
    panel.innerHTML = '';

    var mode = notif.recipient_mode || 'single';

    /* Mode pill + enabled toggle in one row */
    var modeRow = document.createElement('div');
    modeRow.className = 'fabricator-sp-row';
    var modeLbl = document.createElement('div');
    modeLbl.className   = 'fabricator-sp-label';
    modeLbl.textContent = _i18n.recipientMode || 'Recipient mode';
    modeRow.appendChild(modeLbl);

    var modeCtrl = document.createElement('div');
    modeCtrl.className = 'fabricator-sp-row-ctrl';
    modeCtrl.appendChild(mkSeg(
        ['single', 'routing'], [_i18n.modeDirect || 'Direct', _i18n.modeRouting || 'Routing'], mode,
        function (v) {
            state.notifications[notifModalIdx].recipient_mode = v;
            buildNotifRecipientTab(state.notifications[notifModalIdx]);
        }
    ));
    var modeCtrlSpacer = document.createElement('div');
    modeCtrlSpacer.style.flex = '1';
    modeCtrl.appendChild(modeCtrlSpacer);

    /* Enabled toggle — inline right */
    var enabledId  = 'fabricator-sp-notif-enabled-' + notifModalIdx;
    var toggleWrap = document.createElement('div');
    toggleWrap.className = 'fabricator-sp-inline-toggle';
    var toggleEl = document.createElement('label');
    toggleEl.className = 'fabricator-toggle';
    toggleEl.htmlFor   = enabledId;
    var toggleInp = document.createElement('input');
    toggleInp.type    = 'checkbox';
    toggleInp.id      = enabledId;
    toggleInp.checked = notif.enabled !== false;
    toggleInp.addEventListener('change', function () {
        state.notifications[notifModalIdx].enabled = this.checked;
    });
    toggleEl.appendChild(toggleInp);
    var toggleSlider = document.createElement('span');
    toggleSlider.className = 'fabricator-toggle-slider';
    toggleEl.appendChild(toggleSlider);
    toggleWrap.appendChild(toggleEl);
    var toggleLabel = document.createElement('label');
    toggleLabel.className   = 'fabricator-sp-inline-toggle-label';
    toggleLabel.htmlFor     = enabledId;
    toggleLabel.textContent = _i18n.activeLabel || 'Active';
    toggleWrap.appendChild(toggleLabel);
    modeCtrl.appendChild(toggleWrap);

    modeRow.appendChild(modeCtrl);
    var modeHint = document.createElement('div');
    modeHint.className   = 'fabricator-sp-hint';
    modeHint.textContent = mode === 'single'
        ? (_i18n.singleModeHint || 'All entries are sent to a fixed address.')
        : (_i18n.routingModeHint || 'The email address is chosen based on field conditions.');
    modeRow.appendChild(modeHint);
    panel.appendChild(modeRow);

    if (mode === 'single') {
        spRow(panel, 'notif-to', _i18n.toEmail || 'To (email)', 'text', notif.to || '', function (v) {
            state.notifications[notifModalIdx].to = v;
        });
    } else {
        /* Routing rules */
        if (!notif.routing_rules) { notif.routing_rules = []; state.notifications[notifModalIdx].routing_rules = []; }
        var rules = notif.routing_rules;

        var rulesWrap = document.createElement('div');
        rulesWrap.className = 'fabricator-notif-routing';
        panel.appendChild(rulesWrap);

        var CHOICE_TYPES  = ['select', 'radio', 'checkbox', 'multivalue'];
        var ALL_OPS       = OPERATORS; /* reuse global OPERATORS array */
        var OPTION_OPS    = [['equals', _i18n.opEquals || 'is equal to'], ['not_equals', _i18n.opNotEquals || 'is not equal to']];

        function rebuildRoutingRules() {
            rulesWrap.innerHTML = '';

            rules.forEach(function (rule, ri) {
                var rr = document.createElement('div');
                rr.className = 'fabricator-cond-rule';

                var content = document.createElement('div');
                content.className = 'fabricator-cond-rule-content';
                rr.appendChild(content);

                /* Row 1: field selector + operator selector */
                var topRow = document.createElement('div');
                topRow.className = 'fabricator-cond-rule-top';

                var fSel = document.createElement('select');
                fSel.className = 'fabricator-cond-sel';
                var SKIP_E = ['group', 'html', 'pagebreak'];
                var eligible = [];
                state.fields.forEach(function (f) {
                    if (SKIP_E.indexOf(f.type) !== -1) {
                        (f.children || []).forEach(function (c) {
                            if (c.id && SKIP_E.indexOf(c.type) === -1) eligible.push(c);
                        });
                    } else if (f.id) {
                        eligible.push(f);
                    }
                });
                if (!eligible.length) {
                    var ph = document.createElement('option');
                    ph.textContent = _i18n.noFields || '(no fields)'; fSel.appendChild(ph); fSel.disabled = true;
                } else {
                    eligible.forEach(function (f) {
                        var o = document.createElement('option');
                        o.value = f.id; o.textContent = (f.label || f.id) + ' [' + f.id + ']';
                        if (f.id === rule.field_id) o.selected = true;
                        fSel.appendChild(o);
                    });
                }
                topRow.appendChild(fSel);

                var opSel = mkSel(
                    ALL_OPS.map(function (o) { return o[0]; }),
                    ALL_OPS.map(function (o) { return o[1]; }),
                    rule.operator || 'equals',
                    function (v) { rule.operator = v; rebuildValCtrl(); }
                );
                topRow.appendChild(opSel);
                content.appendChild(topRow);

                /* Row 2: value area */
                var valArea = document.createElement('div');
                valArea.className = 'fabricator-cond-val-area';
                content.appendChild(valArea);

                function getCtrl() {
                    for (var i = 0; i < state.fields.length; i++) {
                        if (state.fields[i].id === fSel.value) return state.fields[i];
                    }
                    return null;
                }
                function isChoice(f) {
                    return f && CHOICE_TYPES.indexOf(f.type) !== -1 && (f.options || []).length;
                }

                if (rule.use_option === undefined) { rule.use_option = isChoice(getCtrl()); }

                function rebuildOpSel(forOption) {
                    var ops = forOption ? OPTION_OPS : ALL_OPS;
                    var cur = opSel.value;
                    while (opSel.firstChild) opSel.removeChild(opSel.firstChild);
                    ops.forEach(function (pair) {
                        var o = document.createElement('option');
                        o.value = pair[0]; o.textContent = pair[1];
                        if (pair[0] === cur) o.selected = true;
                        opSel.appendChild(o);
                    });
                    if (!opSel.value) opSel.selectedIndex = 0;
                    rule.operator = opSel.value;
                }

                function rebuildValCtrl() {
                    valArea.innerHTML = '';
                    if (opSel.value === 'empty' || opSel.value === 'not_empty') { return; }
                    var cf = getCtrl();
                    var choice = isChoice(cf);
                    if (choice) {
                        var modePill = mkSeg(['option','value'],['Option','Wert'],
                            rule.use_option ? 'option' : 'value',
                            function (v) {
                                rule.use_option = (v === 'option');
                                rule.value = '';
                                rebuildOpSel(rule.use_option);
                                rebuildValCtrl();
                            });
                        modePill.className += ' fabricator-cond-mode-seg';
                        valArea.appendChild(modePill);
                    }
                    if (choice && rule.use_option) {
                        var optSel = document.createElement('select');
                        optSel.className = 'fabricator-cond-sel fabricator-cond-opt-sel';
                        var blank = document.createElement('option');
                        blank.value = ''; blank.textContent = _i18n.chooseOption || 'Choose option';
                        if (!rule.value) blank.selected = true;
                        optSel.appendChild(blank);
                        cf.options.forEach(function (opt) {
                            var val = (opt && typeof opt === 'object') ? (opt.value || '') : String(opt);
                            var lbl = (opt && typeof opt === 'object') ? (opt.label || val) : val;
                            var o = document.createElement('option');
                            o.value = val; o.textContent = lbl;
                            if (val === rule.value) o.selected = true;
                            optSel.appendChild(o);
                        });
                        optSel.addEventListener('change', function () { rule.value = this.value; });
                        valArea.appendChild(optSel);
                    } else {
                        var inp = document.createElement('input');
                        inp.type = 'text'; inp.className = 'fabricator-cond-val';
                        inp.value = rule.value || ''; inp.placeholder = _i18n.valueWord || 'Value';
                        inp.addEventListener('input', function () { rule.value = this.value; });
                        valArea.appendChild(inp);
                    }
                }

                rebuildOpSel(rule.use_option && isChoice(getCtrl()));
                fSel.addEventListener('change', function () {
                    rule.field_id   = this.value;
                    rule.value      = '';
                    rule.use_option = isChoice(getCtrl());
                    rebuildOpSel(rule.use_option);
                    rebuildValCtrl();
                });
                rebuildValCtrl();

                /* Email target */
                var emailRow = document.createElement('div');
                emailRow.className = 'fabricator-notif-routing-email-row';
                var arrowSpan = document.createElement('span');
                arrowSpan.className   = 'fabricator-notif-routing-arrow';
                arrowSpan.textContent = _i18n.arrowTo || '→ To:';
                emailRow.appendChild(arrowSpan);
                var emailInp = document.createElement('input');
                emailInp.type = 'text'; emailInp.className = 'fabricator-cond-val';
                emailInp.value = rule.email || ''; emailInp.placeholder = _i18n.emailPlaceholder || 'Email';
                emailInp.addEventListener('input', function () { rules[ri].email = this.value; });
                emailRow.appendChild(emailInp);
                content.appendChild(emailRow);

                /* Delete */
                var rm = document.createElement('button');
                rm.type = 'button'; rm.className = 'fabricator-cond-rm'; rm.title = _i18n.removeRule || 'Remove rule';
                rm.innerHTML = '<i class="fa-solid fa-trash"></i>';
                rm.addEventListener('click', function () {
                    rules.splice(ri, 1);
                    state.notifications[notifModalIdx].routing_rules = rules;
                    rebuildRoutingRules();
                });
                rr.appendChild(rm);

                rulesWrap.appendChild(rr);
            });

            var addRule = document.createElement('button');
            addRule.type = 'button'; addRule.className = 'fabricator-cond-add';
            addRule.innerHTML = '<i class="fa-solid fa-plus"></i> ' + escHtml(_i18n.addRule || 'Add rule');
            addRule.addEventListener('click', function () {
                var SKIP_R = ['group', 'html', 'pagebreak'];
                var firstEligible = null;
                state.fields.some(function (f) {
                    if (SKIP_R.indexOf(f.type) !== -1) {
                        return (f.children || []).some(function (c) {
                            if (c.id && SKIP_R.indexOf(c.type) === -1) { firstEligible = c; return true; }
                        });
                    }
                    if (f.id) { firstEligible = f; return true; }
                });
                rules.push({ field_id: firstEligible ? firstEligible.id : '', operator: 'equals', value: '', email: '' });
                state.notifications[notifModalIdx].routing_rules = rules;
                rebuildRoutingRules();
            });
            rulesWrap.appendChild(addRule);
        }
        rebuildRoutingRules();

        spRow(panel, 'notif-fallback', _i18n.fallbackEmail || 'Fallback email', 'text', notif.routing_fallback || '',
            function (v) { state.notifications[notifModalIdx].routing_fallback = v; },
            _i18n.fallbackEmailHint || 'Used when no rule matches');
    }

}

function buildNotifContentTab(notif) {
    var panel = document.getElementById('fabricator-nstab-content');
    panel.innerHTML = '';

    spRow(panel, 'notif-subject', _i18n.subject || 'Subject', 'text', notif.subject || '',
        function (v) { state.notifications[notifModalIdx].subject = v; });

    /* body_html is a transient in-memory flag for the active view, never persisted — every fresh load defaults to Visual. */
    var isHtml = !!notif.body_html;
    var bodyWrap = document.createElement('div');
    bodyWrap.className = 'fabricator-sp-row';

    var bodyHdr = document.createElement('div');
    bodyHdr.className = 'fabricator-sp-html-hdr';
    var bodyLbl = document.createElement('div');
    bodyLbl.className = 'fabricator-sp-label'; bodyLbl.textContent = _i18n.message || 'Message';
    bodyHdr.appendChild(bodyLbl);
    var typePill = mkSeg(['text','html'], ['Visuell','Code'], isHtml ? 'html' : 'text', function (v) {
        var notif = state.notifications[notifModalIdx];
        notif.body_html = (v === 'html');
        buildNotifContentTab(notif);
    });
    var modeBar = document.createElement('div');
    modeBar.className = 'fabricator-sp-html-modebar';
    modeBar.appendChild(typePill);
    bodyHdr.appendChild(modeBar);
    bodyWrap.appendChild(bodyHdr);
    panel.appendChild(bodyWrap);

    if (isHtml) {
        spHtmlEditor(panel, 'notif-body', '', notif.body || '',
            function (v) { state.notifications[notifModalIdx].body = v; },
            { showToggle: false });
    } else {
        spRichTextEditor(panel, 'notif-body', '', notif.body || '',
            function (v) { state.notifications[notifModalIdx].body = v; });
    }

    /* Attachments section */
    var attachSep = document.createElement('div');
    attachSep.className   = 'fabricator-sp-section-sep';
    attachSep.textContent = _i18n.attachments || 'Attachments';
    panel.appendChild(attachSep);

    spCheckboxHint(panel, 'notif-pdf', _i18n.attachPdf || 'Attach generated PDF',
        _i18n.attachPdfHint || 'The completed form is attached as a PDF document.',
        !!notif.attach_pdf,
        function (v) { state.notifications[notifModalIdx].attach_pdf = v; });

    spCheckboxHint(panel, 'notif-uploads', _i18n.attachUploads || 'Attach uploaded files',
        _i18n.attachUploadsHint || 'All file uploads from the form are forwarded as attachments.',
        !!notif.attach_uploads,
        function (v) { state.notifications[notifModalIdx].attach_uploads = v; });
}

function buildNotifSenderTab(notif) {
    var panel = document.getElementById('fabricator-nstab-sender');
    panel.innerHTML = '';

    function sr(key, label, hint) {
        spRow(panel, 'notif-' + key, label, 'text', notif[key] || '',
            function (v) { state.notifications[notifModalIdx][key] = v; }, hint);
    }
    sr('from_name',  _i18n.fromName  || 'Sender name',                  _i18n.defaultSiteNameHint  || '{site_name} for default value');
    sr('from_email', _i18n.fromEmail || 'Sender email address',            _i18n.defaultAdminEmailHint || '{admin_email} for default value');
    sr('reply_to',   _i18n.replyTo   || 'Reply-to email',                  _i18n.emptyMeansSenderEmail || 'Empty = sender email');
    sr('cc',         _i18n.ccEmails  || 'CC emails',                       _i18n.separateWithSemicolon || 'Separate multiple with semicolons');
    sr('bcc',        _i18n.bccEmails || 'BCC emails',                      _i18n.separateWithSemicolon || 'Separate multiple with semicolons');
}

/* Submit button preview + settings modal */
var submitModal = null;

function createSubmitModal() {
    submitModal           = document.createElement('div');
    submitModal.id        = 'fabricator-submit-modal';
    submitModal.className = 'fabricator-modal-backdrop';
    submitModal.hidden    = true;
    submitModal.innerHTML =
        '<div class="fabricator-modal fabricator-modal--settings" role="dialog" aria-modal="true">' +
            '<div class="fabricator-modal-header">' +
                '<div class="fabricator-settings-titlerow">' +
                    '<span class="fabricator-settings-field-icon"><i class="fa-solid fa-paper-plane"></i></span>' +
                    '<span class="fabricator-settings-field-label">' + escHtml(_i18n.submitButtonTitle || 'Submit button') + '</span>' +
                '</div>' +
                '<button class="fabricator-modal-close" type="button">&#x2715;</button>' +
            '</div>' +
            '<div class="fabricator-stab-bar">' +
                '<button class="fabricator-stab fabricator-stab-active" data-smtab="labels">' + escHtml(_i18n.tabLabels || 'Labeling') + '</button>' +
                '<button class="fabricator-stab" data-smtab="conditions">' + escHtml(_i18n.tabConditions || 'Conditions') + '</button>' +
            '</div>' +
            '<div class="fabricator-modal-body fabricator-settings-body">' +
                '<div id="fabricator-smtab-labels"     class="fabricator-stab-panel fabricator-stab-active"></div>' +
                '<div id="fabricator-smtab-conditions" class="fabricator-stab-panel"></div>' +
            '</div>' +
            '<div class="fabricator-settings-footer">' +
                '<button class="fabricator-btn-primary" id="fabricator-submit-done">' + escHtml(_i18n.done || 'Done') + '</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(submitModal);
    submitModal.querySelector('.fabricator-modal-close').addEventListener('click', closeSubmitModal);
    submitModal.querySelector('#fabricator-submit-done').addEventListener('click', closeSubmitModal);
    submitModal.addEventListener('click', function (e) { if (e.target === submitModal) closeSubmitModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !submitModal.hidden) closeSubmitModal(); });
    submitModal.querySelectorAll('.fabricator-stab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            submitModal.querySelectorAll('.fabricator-stab').forEach(function (b) { b.classList.remove('fabricator-stab-active'); });
            submitModal.querySelectorAll('.fabricator-stab-panel').forEach(function (p) { p.classList.remove('fabricator-stab-active'); });
            btn.classList.add('fabricator-stab-active');
            document.getElementById('fabricator-smtab-' + btn.dataset.smtab).classList.add('fabricator-stab-active');
        });
    });
}

function openSubmitModal() {
    if (!submitModal) createSubmitModal();
    if (!submitModal) return;

    /* Reset to first tab */
    submitModal.querySelectorAll('.fabricator-stab').forEach(function (b) { b.classList.remove('fabricator-stab-active'); });
    submitModal.querySelectorAll('.fabricator-stab-panel').forEach(function (p) { p.classList.remove('fabricator-stab-active'); });
    submitModal.querySelector('[data-smtab="labels"]').classList.add('fabricator-stab-active');
    document.getElementById('fabricator-smtab-labels').classList.add('fabricator-stab-active');

    buildSubmitLabelsTab();
    buildSubmitConditionsTab();

    submitModal.hidden = false;
    document.body.style.overflow = 'hidden';
}

function closeSubmitModal() {
    if (!submitModal) return;
    submitModal.hidden = true;
    document.body.style.overflow = '';
    renderSubmitPreview();
}

function buildSubmitLabelsTab() {
    var panel = document.getElementById('fabricator-smtab-labels');
    panel.innerHTML = '';
    var s = state.settings;

    spRow(panel, 'smt-label', _i18n.buttonLabel || 'Label', 'text', s.submit_label || (_i18n.submitLabel || 'Submit'),
        function (v) { markDirty(); state.settings.submit_label = v; renderSubmitPreview(); },
        _i18n.buttonLabelHint || 'Visible text of the button');

    spRow(panel, 'smt-working', _i18n.workingLabel || 'Label while sending', 'text', s.submit_working || (_i18n.submitWorking || 'Sending…'),
        function (v) { markDirty(); state.settings.submit_working = v; },
        _i18n.workingLabelHint || 'Shown while the submission is in progress');

    spRow(panel, 'smt-success', _i18n.successMessageLabel || 'Success message', 'textarea', s.success_message || '',
        function (v) { markDirty(); state.settings.success_message = v; },
        _i18n.successMessageHint || 'Message shown after a successful submission');
}

function buildSubmitConditionsTab() {
    var panel = document.getElementById('fabricator-smtab-conditions');
    panel.innerHTML = '';
    var s = state.settings;
    if (!s.submit_conditions) {
        s.submit_conditions = { match: 'all', rules: [] };
    }
    var cond = s.submit_conditions;

    /* Sentence: Schaltfläche anzeigen wenn [all|any] ... */
    var smSentence = document.createElement('div');
    smSentence.className = 'fabricator-cond-sentence';
    smSentence.appendChild(mkSpan((_i18n.showButtonWhen || 'Show button when') + ' '));
    smSentence.appendChild(mkSeg(
        ['all', 'any'], [_i18n.condAll || 'all', _i18n.condAny || 'any'],
        cond.match || 'all',
        function (v) { state.settings.submit_conditions.match = v; }
    ));
    smSentence.appendChild(mkSpan(' ' + (_i18n.conditionsMatchSuffix || 'of the conditions match:')));
    panel.appendChild(smSentence);

    var smBody = document.createElement('div');
    smBody.className = 'fabricator-cond-body';
    panel.appendChild(smBody);

    var smRulesList = document.createElement('div');
    smRulesList.className = 'fabricator-cond-rules';
    smBody.appendChild(smRulesList);

    function smRebuildRules() {
        smRulesList.innerHTML = '';
        (cond.rules || []).forEach(function (rule, ri) {
            smRulesList.appendChild(smBuildRuleRow(rule, ri));
        });
    }

    function smBuildRuleRow(rule, ri) {
        var CHOICE_TYPES = ['select', 'radio', 'checkbox', 'multivalue'];
        var rr = document.createElement('div');
        rr.className = 'fabricator-cond-rule';

        var content = document.createElement('div');
        content.className = 'fabricator-cond-rule-content';
        rr.appendChild(content);

        var SKIP_SC = ['group', 'html', 'pagebreak'];
        var eligible = [];
        state.fields.forEach(function (f) {
            if (SKIP_SC.indexOf(f.type) !== -1) {
                (f.children || []).forEach(function (c) {
                    if (c.id && SKIP_SC.indexOf(c.type) === -1) eligible.push(c);
                });
            } else if (f.id) {
                eligible.push(f);
            }
        });

        var topRow = document.createElement('div');
        topRow.className = 'fabricator-cond-rule-top';

        var fSel = document.createElement('select');
        fSel.className = 'fabricator-cond-sel';
        if (!eligible.length) {
            var ph = document.createElement('option');
            ph.textContent = _i18n.noFields || '(no fields)';
            fSel.appendChild(ph);
            fSel.disabled = true;
        } else {
            eligible.forEach(function (f) {
                var o = document.createElement('option');
                o.value = f.id;
                o.textContent = (f.label || f.id) + ' [' + f.id + ']';
                if (f.id === rule.field_id) o.selected = true;
                fSel.appendChild(o);
            });
        }
        topRow.appendChild(fSel);

        var OPTION_OPERATORS = [['equals', _i18n.opEquals || 'is equal to'], ['not_equals', _i18n.opNotEquals || 'is not equal to']];

        var opSel = mkSel(
            OPERATORS.map(function (o) { return o[0]; }),
            OPERATORS.map(function (o) { return o[1]; }),
            rule.operator || 'equals',
            function (v) { rule.operator = v; smRebuildValCtrl(); }
        );
        topRow.appendChild(opSel);
        content.appendChild(topRow);

        var valArea = document.createElement('div');
        valArea.className = 'fabricator-cond-val-area';
        content.appendChild(valArea);

        function smGetField() {
            for (var i = 0; i < state.fields.length; i++) {
                if (state.fields[i].id === fSel.value) return state.fields[i];
            }
            return null;
        }
        function smIsChoice(f) {
            return f && CHOICE_TYPES.indexOf(f.type) !== -1 && (f.options || []).length;
        }
        if (rule.use_option === undefined) { rule.use_option = smIsChoice(smGetField()); }

        function smRebuildOpSel(forOption) {
            var ops = forOption ? OPTION_OPERATORS : OPERATORS;
            var cur = opSel.value;
            while (opSel.firstChild) opSel.removeChild(opSel.firstChild);
            ops.forEach(function (pair) {
                var o = document.createElement('option');
                o.value = pair[0]; o.textContent = pair[1];
                if (pair[0] === cur) o.selected = true;
                opSel.appendChild(o);
            });
            if (!opSel.value) opSel.selectedIndex = 0;
            rule.operator = opSel.value;
        }

        function smRebuildValCtrl() {
            valArea.innerHTML = '';
            if (opSel.value === 'empty' || opSel.value === 'not_empty') { return; }
            var cf = smGetField();
            var choice = smIsChoice(cf);
            if (choice) {
                var modePill = mkSeg(['option','value'],['Option','Wert'],
                    rule.use_option ? 'option' : 'value',
                    function (v) {
                        rule.use_option = (v === 'option');
                        rule.value = '';
                        smRebuildOpSel(rule.use_option);
                        smRebuildValCtrl();
                    });
                modePill.className += ' fabricator-cond-mode-seg';
                valArea.appendChild(modePill);
            }
            if (choice && rule.use_option) {
                var optSel = document.createElement('select');
                optSel.className = 'fabricator-cond-sel fabricator-cond-opt-sel';
                var blank = document.createElement('option');
                blank.value = ''; blank.textContent = _i18n.chooseOption || 'Choose option';
                if (!rule.value) blank.selected = true;
                optSel.appendChild(blank);
                cf.options.forEach(function (opt) {
                    var val = (opt && typeof opt === 'object') ? (opt.value || '') : String(opt);
                    var lbl = (opt && typeof opt === 'object') ? (opt.label || val)  : val;
                    var o = document.createElement('option');
                    o.value = val; o.textContent = lbl;
                    if (val === rule.value) o.selected = true;
                    optSel.appendChild(o);
                });
                optSel.addEventListener('change', function () { rule.value = this.value; });
                valArea.appendChild(optSel);
            } else {
                var inp = document.createElement('input');
                inp.type = 'text'; inp.className = 'fabricator-cond-val';
                inp.value = rule.value || ''; inp.placeholder = _i18n.valueWord || 'Value';
                inp.addEventListener('input', function () { rule.value = this.value; });
                valArea.appendChild(inp);
            }
        }

        smRebuildOpSel(rule.use_option && smIsChoice(smGetField()));
        fSel.addEventListener('change', function () {
            rule.field_id   = this.value;
            rule.value      = '';
            rule.use_option = smIsChoice(smGetField());
            smRebuildOpSel(rule.use_option);
            smRebuildValCtrl();
        });
        smRebuildValCtrl();

        var rm = document.createElement('button');
        rm.type = 'button'; rm.className = 'fabricator-cond-rm'; rm.title = _i18n.removeCondition || 'Remove condition';
        rm.innerHTML = '<i class="fa-solid fa-trash"></i>';
        rm.addEventListener('click', function () { cond.rules.splice(ri, 1); smRebuildRules(); });
        rr.appendChild(rm);

        return rr;
    }

    smRebuildRules();

    var smAddBtn = document.createElement('button');
    smAddBtn.type = 'button'; smAddBtn.className = 'fabricator-cond-add';
    smAddBtn.innerHTML = '<i class="fa-solid fa-plus"></i> ' + escHtml(_i18n.addCondition || 'Add condition');
    smAddBtn.addEventListener('click', function () {
        var SKIP_SM = ['group', 'html', 'pagebreak'];
        var firstSm = null;
        state.fields.some(function (f) {
            if (SKIP_SM.indexOf(f.type) !== -1) {
                return (f.children || []).some(function (c) {
                    if (c.id && SKIP_SM.indexOf(c.type) === -1) { firstSm = c; return true; }
                });
            }
            if (f.id) { firstSm = f; return true; }
        });
        cond.rules.push({ field_id: firstSm ? firstSm.id : '', operator: 'equals', value: '' });
        smRebuildRules();
    });
    smBody.appendChild(smAddBtn);
}

function renderSubmitPreview() {
    var bar = document.getElementById('fabricator-submit-preview-bar');
    if (!bar) return;
    var label = state.settings.submit_label || (_i18n.submitLabel || 'Submit');
    var cond  = state.settings.submit_conditions;
    var condActive = cond && (cond.rules || []).length > 0;

    bar.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.className = 'fabricator-submit-preview';
    wrap.title     = _i18n.configureButton || 'Configure button';
    wrap.addEventListener('click', openSubmitModal);

    var btnPreview = document.createElement('button');
    btnPreview.type      = 'button';
    btnPreview.className = 'fabricator-submit-preview-btn';
    btnPreview.textContent = label;
    btnPreview.tabIndex  = -1;
    wrap.appendChild(btnPreview);

    if (condActive) {
        var badge = document.createElement('span');
        badge.className   = 'fabricator-submit-cond-badge';
        badge.title       = _i18n.visibilityConditionsActive || 'Visibility: conditions active';
        badge.innerHTML   = '<i class="fa-solid fa-eye-slash"></i> ' + escHtml(_i18n.conditionalBadge || 'conditional');
        wrap.appendChild(badge);
    }

    var gear = document.createElement('span');
    gear.className = 'fabricator-submit-preview-gear';
    gear.innerHTML = '<i class="fa-solid fa-gear"></i>';
    wrap.appendChild(gear);

    bar.appendChild(wrap);
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
                    setSaveStatus(status, 'ok');
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
                    var blob = new Blob([resp.data.html], { type: 'text/html' });
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

/* Slugify a string for use as an option value */
function slugify(str) {
    return str.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '').replace(/-{2,}/g, '-');
}

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str || '')));
    /* Also encode " and ' (not just &,<,>) so this is safe when spliced into a quoted HTML attribute too. */
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

})();
