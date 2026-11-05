/*!
 * FormFabricator — form builder: notifications list and dialog
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
    findFieldById = B.findFieldById,
    escHtml = B.escHtml,
    withBoldName = B.withBoldName,
    _i18n = B._i18n,
    state = B.state,
    OPERATORS = B.OPERATORS,
    spRow = B.spRow,
    spCheckboxHint = B.spCheckboxHint,
    spHtmlEditor = B.spHtmlEditor,
    spRichTextEditor = B.spRichTextEditor;

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
            '<p>' + withBoldName(_i18n.noNotificationsHtml || 'No notifications yet. Click %s.', _i18n.addNotification || 'Add notification') + '</p>';
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
            name: (_i18n.notificationName || 'Notification %d').replace('%d', n),
            to: '{admin_email}', subject: _i18n.notificationSubject || 'New Entry: {form_title}',
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
    /* One listener for the whole modal: recipients, routing rules, subject, body, attachments and sender each wrote
       straight into state without marking the form unsaved, so leaving the page lost them with no warning. */
    notifModal.addEventListener('input',  markDirty);
    notifModal.addEventListener('change', markDirty);
    notifModal.addEventListener('click', function (e) {
        if (e.target.closest('.fabricator-cond-add, .fabricator-cond-rm, .fabricator-seg-btn, .fabricator-sp-inline-toggle')) {
            markDirty();
        }
    });
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
        /* Hint shown: To accepts multiple addresses too, same as Cc/Bcc (resolveRecipientList splits on ';'/','). */
        spRow(panel, 'notif-to', _i18n.toEmail || 'To (email)', 'text', notif.to || '', function (v) {
            state.notifications[notifModalIdx].to = v;
        }, _i18n.separateWithSemicolon || 'Separate multiple with semicolons');
        spRow(panel, 'notif-cc', _i18n.ccEmails || 'CC emails', 'text', notif.cc || '',
            function (v) { state.notifications[notifModalIdx].cc = v; },
            _i18n.separateWithSemicolon || 'Separate multiple with semicolons');
        spRow(panel, 'notif-bcc', _i18n.bccEmails || 'BCC emails', 'text', notif.bcc || '',
            function (v) { state.notifications[notifModalIdx].bcc = v; },
            _i18n.separateWithSemicolon || 'Separate multiple with semicolons');
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
                    return findFieldById(fSel.value);
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
                        var modePill = mkSeg(['option','value'],[_i18n.segOption || 'Option', _i18n.segValue || 'Value'],
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

                /* Per-rule Cc/Bcc, so each routed enquiry reaches its own group. */
                function copyRow(key, arrowLabel, placeholder) {
                    var row = document.createElement('div');
                    row.className = 'fabricator-notif-routing-email-row';
                    var arrow = document.createElement('span');
                    arrow.className   = 'fabricator-notif-routing-arrow';
                    arrow.textContent = arrowLabel;
                    row.appendChild(arrow);
                    var inp = document.createElement('input');
                    inp.type = 'text';
                    inp.className = 'fabricator-cond-val';
                    inp.value = rule[key] || '';
                    inp.placeholder = placeholder;
                    inp.addEventListener('input', function () { rules[ri][key] = this.value; });
                    row.appendChild(inp);
                    content.appendChild(row);
                }
                copyRow('cc', _i18n.arrowCc || '→ Cc:', _i18n.separateWithSemicolon || 'Separate multiple with semicolons');
                copyRow('bcc', _i18n.arrowBcc || '→ Bcc:', _i18n.separateWithSemicolon || 'Separate multiple with semicolons');

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
        spRow(panel, 'notif-fallback-cc', _i18n.fallbackCc || 'Fallback CC', 'text', notif.routing_fallback_cc || '',
            function (v) { state.notifications[notifModalIdx].routing_fallback_cc = v; },
            _i18n.separateWithSemicolon || 'Separate multiple with semicolons');
        spRow(panel, 'notif-fallback-bcc', _i18n.fallbackBcc || 'Fallback BCC', 'text', notif.routing_fallback_bcc || '',
            function (v) { state.notifications[notifModalIdx].routing_fallback_bcc = v; },
            _i18n.separateWithSemicolon || 'Separate multiple with semicolons');
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
    var typePill = mkSeg(['text','html'], [_i18n.segVisual || 'Visual', _i18n.segCode || 'Code'], isHtml ? 'html' : 'text', function (v) {
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
        /* markDirty() here: typing happens inside the editor's iframe, whose input events never reach the dialog's
           listener, so leaving the page after editing only the body lost it without a warning. */
        spRichTextEditor(panel, 'notif-body', '', notif.body || '',
            function (v) { state.notifications[notifModalIdx].body = v; markDirty(); });
    }
    /* Saving removes what email may not carry (EmailBodySanitizer::sanitize()); say so before it happens. */
    var bodyHint = document.createElement('p');
    bodyHint.className   = 'fabricator-sp-hint';
    bodyHint.textContent = _i18n.notifBodyHint || 'Removed on save: scripts, embedded content (iframe, object, embed), event handlers (onclick and the like), javascript: links, external stylesheets, form actions, and tags or styles outside the email-safe set.';
    panel.appendChild(bodyHint);

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

    /* Automation section */
    var autoSep = document.createElement('div');
    autoSep.className   = 'fabricator-sp-section-sep';
    autoSep.textContent = _i18n.automation || 'Automation';
    panel.appendChild(autoSep);

    spCheckboxHint(panel, 'notif-markers', _i18n.automationMarkers || 'Add automation markers',
        _i18n.automationMarkersHint || 'Each answer in the email is written between [FABRICATOR_FIELD_<field id>] and [FABRICATOR_FIELD_END], so a mail parser can find it. Answers containing "[FABRICATOR_FIELD_" are then refused.',
        !!notif.automation_markers,
        function (v) { state.notifications[notifModalIdx].automation_markers = v; });

    spCheckboxHint(panel, 'notif-placeholder-labels', _i18n.placeholderLabels || 'Show the field label with a single field\'s placeholder',
        _i18n.placeholderLabelsHint || 'A single field\'s placeholder in the message, such as {name}, then shows the field\'s label above its answer, as the list of all fields does. Off, it shows the answer alone, to use within a sentence.',
        !!notif.placeholder_labels,
        function (v) { state.notifications[notifModalIdx].placeholder_labels = v; });
}

function buildNotifSenderTab(notif) {
    var panel = document.getElementById('fabricator-nstab-sender');
    panel.innerHTML = '';

    function sr(key, label, hint) {
        spRow(panel, 'notif-' + key, label, 'text', notif[key] || '',
            function (v) { state.notifications[notifModalIdx][key] = v; }, hint);
    }
    sr('from_name',  _i18n.fromName  || 'Sender name',                  _i18n.defaultSiteNameHint  || '{site_name} for default value');
    sr('from_email', _i18n.fromEmail || 'Sender email address',            _i18n.fromEmailHint || '{admin_email}, or a fixed address of this site.');
    /* A form field as sender is not saved (FormSanitizer::sanitizeFromEmail()): mail claiming to come from a visitor's
       domain is discarded by strict domains after it was sent. Say so while the value is being typed. */
    var fromInput = document.getElementById('fabricator-sp-notif-from_email');
    if (fromInput) {
        var fromWarn = document.createElement('p');
        fromWarn.className   = 'fabricator-sp-notice fabricator-sp-notice--warning';
        fromWarn.textContent = _i18n.fromEmailFieldWarning || 'A form field cannot be the sender.';
        fromInput.parentNode.appendChild(fromWarn);
        var checkFrom = function () { fromWarn.hidden = !/\{(?!admin_email\})[^{}]*\}/.test(fromInput.value); };
        fromInput.addEventListener('input', checkFrom);
        checkFrom();
    }
    sr('reply_to',   _i18n.replyTo   || 'Reply-to email',                  _i18n.emptyMeansSenderEmail || 'Empty = sender email');
    /* CC/BCC live on the Recipients tab — they ARE recipients, and in routing mode they belong to
       the individual rule rather than the notification, which this tab has no concept of. */

    /* A test email with this notification's sender as it stands, saved or not, to the admin's own address
       (FormEditor::ajaxSendTestMail()): a failing mail setup would lose every submission. */
    var testRow = document.createElement('div');
    testRow.className = 'fabricator-sp-row';
    var testBtn = document.createElement('button');
    testBtn.type        = 'button';
    testBtn.className   = 'button';
    testBtn.id          = 'fabricator-notif-test-mail';
    testBtn.textContent = _i18n.sendTestMail || 'Send test email';
    var testHint = document.createElement('p');
    testHint.className   = 'fabricator-sp-hint';
    testHint.textContent = _i18n.sendTestMailHint || 'Sends a short email with this sender to your own address.';
    var testStatus = document.createElement('p');
    testStatus.className = 'fabricator-sp-notice';
    testStatus.hidden    = true;
    testStatus.setAttribute('role', 'status');
    testRow.appendChild(testBtn);
    testRow.appendChild(testHint);
    testRow.appendChild(testStatus);
    panel.appendChild(testRow);

    function showTestResult(ok, message) {
        testStatus.className   = 'fabricator-sp-notice fabricator-sp-notice--' + (ok ? 'info' : 'warning');
        testStatus.textContent = message;
        testStatus.hidden      = false;
    }
    testBtn.addEventListener('click', function () {
        var current = state.notifications[notifModalIdx] || {};
        testBtn.disabled = true;
        testStatus.hidden = true;
        var fd = new FormData();
        fd.append('action',     'fabricator_forms_send_test_mail');
        fd.append('nonce',      B.nonce());
        fd.append('from_name',  current.from_name || '');
        fd.append('from_email', current.from_email || '');
        fd.append('form_title', state.formName || '');
        fetch(B.ajaxUrl(), { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                showTestResult(!!resp.success, (resp.data && resp.data.message) || (_i18n.unknownError || 'Unknown error'));
            })
            .catch(function () { showTestResult(false, _i18n.networkError || 'Network error.'); })
            .finally(function () { testBtn.disabled = false; });
    });
}

B.renderNotifications = renderNotifications;
B.buildNotifRow = buildNotifRow;
B.createNotifModal = createNotifModal;
B.openNotifModal = openNotifModal;
B.closeNotifModal = closeNotifModal;
B.buildNotifRecipientTab = buildNotifRecipientTab;
B.buildNotifContentTab = buildNotifContentTab;
B.buildNotifSenderTab = buildNotifSenderTab;
});
