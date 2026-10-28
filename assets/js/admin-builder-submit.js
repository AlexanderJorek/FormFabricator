/*!
 * FormFabricator — form builder: submit button preview and dialog
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
/*
 * A part of the form builder (admin-builder.js), loaded before it. The builder calls it with the names it
 * shares (B) and takes back the functions it adds there.
 */
window.FabricatorBuilderParts = window.FabricatorBuilderParts || [];
window.FabricatorBuilderParts.push(function (B) {
'use strict';

var markDirty = B.markDirty,
    mkSeg = B.mkSeg,
    mkSel = B.mkSel,
    mkSpan = B.mkSpan,
    findFieldById = B.findFieldById,
    escHtml = B.escHtml,
    _i18n = B._i18n,
    state = B.state,
    OPERATORS = B.OPERATORS,
    spRow = B.spRow;

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
    /* One listener for the whole modal, as the notification modal has: the condition rows write straight into
       state without marking the form unsaved, so leaving the page lost them with no warning. */
    submitModal.addEventListener('input',  markDirty);
    submitModal.addEventListener('change', markDirty);
    submitModal.addEventListener('click', function (e) {
        if (e.target.closest('.fabricator-cond-add, .fabricator-cond-rm, .fabricator-seg-btn')) {
            markDirty();
        }
    });
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
            return findFieldById(fSel.value);
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
                var modePill = mkSeg(['option','value'],[_i18n.segOption || 'Option', _i18n.segValue || 'Value'],
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
                    var val = (opt && opt.value) || '';
                    var lbl = (opt && opt.label) || val;
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

B.createSubmitModal = createSubmitModal;
B.openSubmitModal = openSubmitModal;
B.closeSubmitModal = closeSubmitModal;
B.buildSubmitLabelsTab = buildSubmitLabelsTab;
B.buildSubmitConditionsTab = buildSubmitConditionsTab;
B.renderSubmitPreview = renderSubmitPreview;
});
