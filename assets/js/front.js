/*!
 * FormFabricator — Frontend interactions
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';

    /* ── Helpers ──────────────────────────────────────────────────────────── */

    function on(root, sel, evt, fn) {
        root.querySelectorAll(sel).forEach(function (el) {
            el.addEventListener(evt, fn);
        });
    }

    /* ── Field-type init functions ───────────────────────────────────────── */
    /* Field-specific init logic lives in each field's PHP class via
       getClientInit(); Assets::enqueueFront() collects them into
       window.FabricatorFieldInits keyed by field type, so front.js needs no
       knowledge of specific field types. */

    /* ── Validator registry ───────────────────────────────────────────────── */
    /* Populated by Assets::enqueueFront() from each field's getClientValidation().
       Each entry: function(fieldEl) → error string | null.
       These client-side rules mirror the authoritative checks in each field's
       PHP validate() (includes/Fields/*.php), which runs again server-side.
       If a validate() rule changes, update the matching client rule too, or
       the two will silently drift. */
    var VALIDATORS = window.FabricatorValidators || {};

    /* ── Client-side validation ──────────────────────────────────────────── */

    function validatePage(scope) {
        var i18n        = (window.FabricatorForms && window.FabricatorForms.i18n) || {};
        var hasRequired = false;
        var hasInvalid  = false;
        var firstError  = null;

        scope.querySelectorAll('.fabricator-field-error').forEach(function (e) {
            e.textContent = '';
        });

        function markError(fieldEl, msg) {
            if (!fieldEl) return;
            var errEl = fieldEl.querySelector('.fabricator-field-error');
            if (errEl && !errEl.textContent) errEl.textContent = msg;
            if (!firstError) firstError = fieldEl;
        }

        function fieldIsEmpty(fieldEl) {
            var type    = (fieldEl.className.match(/fabricator-field--(\S+)/) || [])[1] || '';
            var checker = (window.FabricatorEmptyChecks || {})[type];
            if (checker) return checker(fieldEl);
            /* Generic fallback */
            var inp = fieldEl.querySelector('input:not([type="hidden"]):not([type="submit"]), textarea, select');
            if (!inp) return true;
            if (inp.type === 'radio') return !fieldEl.querySelector('input[type="radio"]:checked');
            return !inp.value.trim();
        }

        function isConditionallyHidden(el) {
            while (el) {
                if (el.dataset && 'conditions' in el.dataset && el.style.display === 'none') return true;
                el = el.parentElement;
            }
            return false;
        }

        var skipTypes      = window.FabricatorSkipValidation || [];
        var ignoreRequired = !!window.FabricatorIgnoreRequired;
        scope.querySelectorAll('.fabricator-field').forEach(function (fieldEl) {
            var type = (fieldEl.className.match(/fabricator-field--(\S+)/) || [])[1] || '';
            if (skipTypes.indexOf(type) !== -1) return;
            if (isConditionallyHidden(fieldEl)) return;

            var isRequired = fieldEl.classList.contains('fabricator-required-field')
                || fieldEl.dataset.required === 'true';
            var empty = fieldIsEmpty(fieldEl);

            /* 1 — Required check */
            if (isRequired && empty && !ignoreRequired) {
                hasRequired = true;
                markError(fieldEl, i18n.field_required || 'This field is required.');
                return; /* skip format check on empty required field */
            }

            /* 2 — Per-sub-input required check (composite fields like address/name expanded) */
            if (!ignoreRequired) {
                fieldEl.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (inp) {
                    if (inp.type === 'hidden' || inp.value.trim()) return;
                    hasRequired = true;
                    var container = inp.parentNode;
                    var errEl = (container && container.querySelector('.fabricator-field-error'))
                        || fieldEl.querySelector('.fabricator-field-error');
                    if (errEl && !errEl.textContent) {
                        errEl.textContent = i18n.field_required || 'This field is required.';
                    }
                    if (!firstError) firstError = fieldEl;
                });
            }

            /* 3 — Format/validity check (only when field has content) */
            if (!empty) {
                var rules = [];
                try { rules = JSON.parse(fieldEl.dataset.validate || '[]'); } catch (_) {}
                rules.forEach(function (rule) {
                    if (!VALIDATORS[rule]) return;
                    var err = VALIDATORS[rule](fieldEl);
                    if (err) {
                        hasInvalid = true;
                        markError(fieldEl, err);
                    }
                });
            }
        });

        return {
            valid:       !hasRequired && !hasInvalid,
            hasRequired: hasRequired,
            hasInvalid:  hasInvalid,
            firstError:  firstError,
        };
    }

    function scrollToField(fieldEl) {
        if (!fieldEl) return;
        var top = fieldEl.getBoundingClientRect().top + window.pageYOffset - 80;
        window.scrollTo(0, Math.max(0, top));
    }

    /* ── Multi-page navigation ────────────────────────────────────────────── */

    function initPageBreaks(root) {
        var forms = root.querySelectorAll('.fabricator-form');
        forms.forEach(function (form) {
            /* Guards against init() running twice on the same form (e.g. script included twice), which would stack a duplicate click listener + step bar. */
            if (form.dataset.fabricatorPagesInit) return;
            form.dataset.fabricatorPagesInit = '1';

            var pages = Array.from(form.querySelectorAll('.fabricator-form-page'));
            if (!pages.length) return;

            /* Relocate the page-header row to sit before the pages so it stays visible on later pages too (its own init already read its original page position — see PageHeaderField::getClientInit()). */
            var headerEl  = form.querySelector('.fabricator-page-header');
            var headerRow = headerEl && headerEl.closest('.fabricator-row');
            if (headerRow && pages.indexOf(headerRow.parentNode) !== -1) {
                pages[0].parentNode.insertBefore(headerRow, pages[0]);
            }

            var footer   = form.querySelector('.fabricator-form-footer');
            var wrap     = form.closest('.fabricator-form-wrap') || form;
            var furthest = 0;

            function applyPage(idx) {
                pages.forEach(function (p, i) {
                    p.classList.toggle('fabricator-page-active', i === idx);
                });
                if (footer) {
                    footer.style.display = (idx === pages.length - 1) ? '' : 'none';
                }
                if (idx > furthest) furthest = idx;
                form.dispatchEvent(new CustomEvent('fabricator:page-change', {
                    bubbles: false, cancelable: false,
                    detail: { index: idx, furthest: furthest, total: pages.length },
                }));
            }

            function showPage(idx, scroll) {
                if (!scroll) {
                    applyPage(idx);
                    return;
                }

                var target   = Math.max(0, wrap.getBoundingClientRect().top + window.pageYOffset - 20);
                var startY   = window.pageYOffset;
                var distance = startY - target;

                if (distance < 4) {
                    applyPage(idx);
                    return;
                }

                /* Swap the page content and measure the height difference. */
                var tallHeight = document.body.scrollHeight;
                applyPage(idx);
                var shortHeight = document.body.scrollHeight;
                var gap = tallHeight - shortHeight;

                /* Spacer holds the footer at its current position and shrinks in sync so it descends smoothly instead of snapping to the shorter page height. */
                var spacer = document.createElement('div');
                spacer.style.height = gap + 'px';
                wrap.parentNode.insertBefore(spacer, wrap.nextSibling);

                var duration  = Math.min(1050, Math.max(450, distance * 0.225));
                var startTime = null;

                function ease(t) {
                    return t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
                }

                function tick(ts) {
                    if (!startTime) startTime = ts;
                    var p = Math.min(1, (ts - startTime) / duration);
                    var e = ease(p);
                    window.scrollTo(0, startY - distance * e);
                    spacer.style.height = Math.ceil(gap * (1 - e)) + 'px';
                    if (p < 1) {
                        requestAnimationFrame(tick);
                    } else {
                        spacer.parentNode.removeChild(spacer);
                    }
                }
                requestAnimationFrame(tick);
            }

            function currentIdx() {
                return pages.findIndex(function (p) {
                    return p.classList.contains('fabricator-page-active');
                });
            }

            form.addEventListener('fabricator:reset', function () {
                /* Reset furthest to 0 too, or the step bar's later steps stay clickable from the previous run. */
                furthest = 0;
                showPage(0, false);
            });

            form.addEventListener('click', function (e) {
                if (e.target.classList.contains('fabricator-btn-next')) {
                    var idx    = currentIdx();
                    var result = validatePage(pages[idx]);
                    if (!result.valid) {
                        scrollToField(result.firstError);
                        return;
                    }
                    if (idx < pages.length - 1) showPage(idx + 1, true);
                }
                if (e.target.classList.contains('fabricator-btn-prev')) {
                    var idx2 = currentIdx();
                    if (idx2 > 0) showPage(idx2 - 1, true);
                }
                var gotoEl = e.target.closest('[data-fabricator-goto-page]');
                if (gotoEl) {
                    var gotoIdx = parseInt(gotoEl.getAttribute('data-fabricator-goto-page'), 10);
                    if (!isNaN(gotoIdx) && gotoIdx >= 0 && gotoIdx <= furthest && gotoIdx !== currentIdx()) {
                        showPage(gotoIdx, true);
                    }
                }
            });

            showPage(0, false);
        });
    }

    /* ── Conditional logic ───────────────────────────────────────────────── */

    function initConditions(root) {
        root.querySelectorAll('.fabricator-form').forEach(function (form) {
            if (!form.querySelector('[data-conditions]')) return;
            /* See initPageBreaks() — same double-init guard. */
            if (form.dataset.fabricatorConditionsInit) return;
            form.dataset.fabricatorConditionsInit = '1';

            function getFieldValue(fieldId) {
                var name = CSS.escape(fieldId);
                /* CheckboxField uses name="{id}[]"; every other field uses the bare id — match both or checkbox conditions always see an empty value. */
                var all = Array.from(form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]'));
                if (!all.length) return '';
                /* Treat inputs inside a hidden conditional ancestor as absent */
                var inputs = all.filter(function (i) {
                    var p = i.parentElement;
                    while (p && p !== form) {
                        if ('conditions' in (p.dataset || {}) && p.style.display === 'none') return false;
                        p = p.parentElement;
                    }
                    return true;
                });
                if (!inputs.length) return all[0].type === 'checkbox' ? [] : '';
                var first = inputs[0];
                if (first.type === 'radio') {
                    var chk = inputs.find(function (i) { return i.checked; });
                    return chk ? chk.value : '';
                }
                if (first.type === 'checkbox') {
                    return inputs.filter(function (i) { return i.checked; }).map(function (i) { return i.value; });
                }
                if (first.tagName === 'SELECT' && first.multiple) {
                    return Array.from(first.selectedOptions).map(function (o) { return o.value; });
                }
                return first.value;
            }

            function testRule(rule) {
                var val  = getFieldValue(rule.field_id || '');
                var op   = rule.operator || 'equals';
                var rv   = (rule.value || '').toString().toLowerCase();
                var isArr = Array.isArray(val);
                var str   = isArr ? '' : (val || '').toString().toLowerCase();
                switch (op) {
                    case 'equals':       return isArr ? val.some(function (v) { return v.toLowerCase() === rv; }) : str === rv;
                    case 'not_equals':   return isArr ? val.every(function (v) { return v.toLowerCase() !== rv; }) : str !== rv;
                    case 'contains':     return rv !== '' && (isArr
                        ? val.some(function (v) { return v.toLowerCase().indexOf(rv) !== -1; })
                        : str.indexOf(rv) !== -1);
                    case 'not_contains': return rv === '' || (isArr
                        ? val.every(function (v) { return v.toLowerCase().indexOf(rv) === -1; })
                        : str.indexOf(rv) === -1);
                    case 'empty':        return isArr ? val.length === 0 : str === '';
                    case 'not_empty':    return isArr ? val.length > 0   : str !== '';
                    case 'greater':      { var a = parseFloat(str), b = parseFloat(rv); return !isNaN(a) && !isNaN(b) && a > b; }
                    case 'less':         { var a2 = parseFloat(str), b2 = parseFloat(rv); return !isNaN(a2) && !isNaN(b2) && a2 < b2; }
                    default:             return true;
                }
            }

            function applyAll() {
                form.querySelectorAll('[data-conditions]').forEach(function (el) {
                    var cond;
                    try { cond = JSON.parse(el.dataset.conditions); } catch (_) { return; }
                    var rules = cond.rules || [];
                    if (!rules.length) return;
                    var pass = cond.match === 'any'
                        ? rules.some(testRule)
                        : rules.every(testRule);
                    el.style.display = (cond.action === 'hide' ? !pass : pass) ? '' : 'none';
                });
            }

            form.addEventListener('change', applyAll);
            form.addEventListener('input',  applyAll);
            applyAll();
        });
    }

    /* ── AJAX form submission ─────────────────────────────────────────────── */

    function initForms(root) {
        root.querySelectorAll('.fabricator-form').forEach(function (form) {
            /* See initPageBreaks() — same double-init guard, critical here since a
             * second bound submit handler would submit the form twice per click. */
            if (form.dataset.fabricatorFormsInit) return;
            form.dataset.fabricatorFormsInit = '1';

            var isSubmitting = false;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var submitBtn = form.querySelector('.fabricator-submit-btn');
                /* Durable flag survives a bfcache restore, blocking a second submit before pageshow resets the form. */
                if (isSubmitting || (submitBtn && submitBtn.dataset.fabricatorSubmitted === '1')) return;

                var wrap    = form.closest('.fabricator-form-wrap');
                var msgBox  = wrap && wrap.querySelector('.fabricator-form-messages');
                var btn     = form.querySelector('.fabricator-submit-btn');
                var label   = btn && btn.querySelector('.fabricator-submit-label');
                var spinner = btn && btn.querySelector('.fabricator-submit-spinner');
                var i18n    = (window.FabricatorForms && window.FabricatorForms.i18n) || {};

                /* Client-side validation before sending */
                var vr = validatePage(form);
                if (!vr.valid) {
                    var msg = vr.hasRequired && vr.hasInvalid
                        ? (i18n.validation_both || 'Please fill in all required fields and correct the invalid entries.')
                        : vr.hasRequired
                            ? (i18n.validation_required || 'Please fill in all required fields.')
                            : (i18n.validation_invalid || 'Please enter valid data.');
                    if (msgBox) {
                        msgBox.className    = 'fabricator-form-messages error';
                        msgBox.textContent  = msg;
                        msgBox.style.display = '';
                    }
                    scrollToField(vr.firstError);
                    return;
                }

                /* Cross-field file count guard.
                   Sums data-fabricator-file-count across all file-bearing elements.
                   phpMax reflects PHP's max_file_uploads limit (set via data-max-files). */
                var overflowDetected = false;
                (function () {
                    var counters = form.querySelectorAll('[data-fabricator-file-count]');
                    if (!counters.length) { return; }
                    var phpMax = parseInt(form.dataset.fabricatorFileMax || '0', 10);
                    if (!phpMax) {
                        form.querySelectorAll('[data-max-files]').forEach(function (z) {
                            var m = parseInt(z.dataset.maxFiles || '0', 10);
                            if (m > phpMax) { phpMax = m; }
                        });
                    }
                    if (!phpMax) { return; }
                    var total = 0;
                    counters.forEach(function (el) {
                        total += parseInt(el.dataset.fabricatorFileCount || '0', 10);
                    });
                    if (total > phpMax) {
                        form.dispatchEvent(new CustomEvent('fabricator:upload-overflow', {
                            bubbles: false, cancelable: false,
                            detail: { total: total, max: phpMax },
                        }));
                        overflowDetected = true;
                    }
                }());
                if (overflowDetected) { return; }

                var origLabel = label ? label.textContent : '';

                if (btn) btn.disabled = true;
                if (label) label.textContent = (btn && btn.dataset.working) || i18n.submitting || 'Sending…';
                if (spinner) spinner.style.display = '';
                if (msgBox) { msgBox.style.display = 'none'; msgBox.className = 'fabricator-form-messages'; }

                isSubmitting = true;

                function resetSubmitUi() {
                    isSubmitting = false;
                    if (label) label.textContent = origLabel;
                    if (btn) btn.disabled = false;
                    if (spinner) spinner.style.display = 'none';
                }

                function showServerError() {
                    if (msgBox) {
                        msgBox.className = 'fabricator-form-messages error';
                        msgBox.textContent = i18n.error_server || 'Server error. Please try again.';
                        msgBox.style.display = '';
                    }
                }

                var ajaxUrl = (window.FabricatorForms && window.FabricatorForms.ajaxUrl) || '';

                /* fabricator_nonce/fabricator_submission_token are intentionally never rendered into this
                   page's HTML (see FormRenderer::render()'s comment) — fetched fresh here, right
                   before the actual submit, so a full-page cache in front of this page can't hand
                   two different visitors the same replay-protection token. */
                fetch(ajaxUrl, {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'fabricator_forms_get_token', form_id: form.dataset.formId || '' }),
                    credentials: 'same-origin',
                })
                .then(function (r) { return r.json(); })
                .then(function (tokenRes) {
                    if (!tokenRes.success || !tokenRes.data) {
                        resetSubmitUi();
                        showServerError();
                        return;
                    }
                    var nonceField = form.querySelector('.fabricator-nonce-field');
                    var tokenField = form.querySelector('.fabricator-submission-token-field');
                    if (nonceField) nonceField.value = tokenRes.data.nonce || '';
                    if (tokenField) tokenField.value = tokenRes.data.token || '';

                    var data = new FormData(form);

                    return fetch(ajaxUrl, {
                        method: 'POST',
                        body: data,
                        credentials: 'same-origin',
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                    if (spinner) spinner.style.display = 'none';

                    isSubmitting = false;
                    if (res.success) {
                        if (label) label.textContent = origLabel;
                        if (btn) btn.disabled = false;
                        if (btn) btn.dataset.fabricatorSubmitted = '1';
                        if (msgBox) {
                            msgBox.className = 'fabricator-form-messages success';
                            msgBox.textContent = (btn && btn.dataset.success)
                                || (res.data && res.data.message)
                                || i18n.thank_you
                                || 'Thank you!';
                            msgBox.style.display = '';
                        }
                        form.reset();
                        form.dispatchEvent(new Event('change'));
                        form.dispatchEvent(new Event('fabricator:reset'));
                        /* Re-init dynamic fields after reset */
                        var fi = window.FabricatorFieldInits || {};
                        Object.keys(fi).forEach(function (t) { fi[t](form); });
                        /* Scroll to form top so the success message is in view */
                        var scrollTarget = wrap || form;
                        var scrollTop = scrollTarget.getBoundingClientRect().top + window.pageYOffset - 20;
                        window.scrollTo({ top: Math.max(0, scrollTop), behavior: 'smooth' });
                    } else {
                        if (label) label.textContent = origLabel;
                        if (btn) btn.disabled = false;
                        var errMsg = (res.data && res.data.message)
                            || i18n.error_server
                            || 'Server error. Please try again.';
                        if (msgBox) {
                            msgBox.className    = 'fabricator-form-messages error';
                            msgBox.textContent  = errMsg;
                            msgBox.style.display = '';
                        }

                        /* Show per-field server errors */
                        var fieldErrors = res.data && res.data.errors;
                        if (fieldErrors) {
                            var firstErrEl = null;
                            Object.keys(fieldErrors).forEach(function (fid) {
                                var errEl = form.querySelector('#' + CSS.escape(fid) + '-error');
                                if (errEl) {
                                    errEl.textContent = fieldErrors[fid];
                                    if (!firstErrEl) firstErrEl = errEl.closest('.fabricator-field');
                                }
                            });
                            scrollToField(firstErrEl);
                        }
                    }
                    })
                    .catch(function () {
                        resetSubmitUi();
                        showServerError();
                    });
                })
                .catch(function () {
                    resetSubmitUi();
                    showServerError();
                });
            });
        });
    }

    /* ── CAPTCHA click-to-activate ───────────────────────────────────────────
       The reCAPTCHA script (and the connection to Google it triggers) is only
       requested once the visitor explicitly clicks the placeholder button —
       not on page load — so the field doesn't load a third-party script
       before the visitor has interacted with the form. */
    var recaptchaLoading = false;
    var recaptchaCallbacks = [];
    var recaptchaErrorCallbacks = [];
    function loadRecaptchaScript(cb, onError) {
        if (window.grecaptcha && window.grecaptcha.render) {
            cb();
            return;
        }
        recaptchaCallbacks.push(cb);
        if (onError) { recaptchaErrorCallbacks.push(onError); }
        if (recaptchaLoading) {
            return;
        }
        recaptchaLoading = true;
        window.__fabricatorRecaptchaOnLoad = function () {
            recaptchaCallbacks.forEach(function (fn) { fn(); });
            recaptchaCallbacks = [];
            recaptchaErrorCallbacks = [];
        };
        var s = document.createElement('script');
        s.src = 'https://www.google.com/recaptcha/api.js?onload=__fabricatorRecaptchaOnLoad&render=explicit';
        s.async = true;
        s.defer = true;
        /* Ad blockers commonly block this request, leaving the activation button stuck disabled. */
        s.onerror = function () {
            recaptchaLoading = false;
            recaptchaCallbacks = [];
            var errCbs = recaptchaErrorCallbacks;
            recaptchaErrorCallbacks = [];
            errCbs.forEach(function (fn) { fn(); });
        };
        document.head.appendChild(s);
    }
    /* Extracted so FieldTestPage's JS test suite can drive it without a real blocked request. */
    function showCaptchaBlockedNotice(gate, btn) {
        var i18n = (window.FabricatorForms && window.FabricatorForms.i18n) || {};
        btn.disabled = false;
        if (!gate.querySelector('.fabricator-notice')) {
            gate.insertAdjacentHTML('beforeend',
                '<p class="fabricator-notice fabricator-error"></p>');
        }
        var notice = gate.querySelector('.fabricator-notice');
        if (notice) {
            notice.textContent = i18n.recaptcha_blocked
                || 'Could not load CAPTCHA. Please disable content blockers for this site or try another browser.';
        }
    }
    function initCaptchaGates(root) {
        root.querySelectorAll('.fabricator-captcha-gate').forEach(function (gate) {
            /* See initPageBreaks() — same double-init guard. */
            if (gate.dataset.fabricatorCaptchaInit) return;
            gate.dataset.fabricatorCaptchaInit = '1';

            var btn = gate.querySelector('.fabricator-captcha-activate');
            if (!btn) return;
            btn.addEventListener('click', function () {
                btn.disabled = true;
                loadRecaptchaScript(function () {
                    var widget = document.createElement('div');
                    gate.innerHTML = '';
                    gate.appendChild(widget);
                    window.grecaptcha.render(widget, { sitekey: gate.dataset.sitekey });
                }, function () { showCaptchaBlockedNotice(gate, btn); });
            });
        });
    }

    /* ── Boot ─────────────────────────────────────────────────────────────── */

    function init(root) {
        root = root || document;
        /* Guards the whole boot sequence against running twice on the same root — not all field getClientInit() implementations are safe to re-run. */
        if (root === document && window.__fabricatorFrontInited) return;
        if (root === document) window.__fabricatorFrontInited = true;

        var fieldInits = window.FabricatorFieldInits || {};
        Object.keys(fieldInits).forEach(function (type) { fieldInits[type](root); });
        initPageBreaks(root);
        initConditions(root);
        initForms(root);
        initCaptchaGates(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    /* Resets stale submitted/message state after a bfcache restore; named so tests can call it directly. */
    function resetFormsOnBfcacheRestore() {
        document.querySelectorAll('.fabricator-form').forEach(function (form) {
            form.reset();
            var btn = form.querySelector('.fabricator-submit-btn');
            if (btn) delete btn.dataset.fabricatorSubmitted;
            var wrap   = form.closest('.fabricator-form-wrap');
            var msgBox = wrap && wrap.querySelector('.fabricator-form-messages');
            if (msgBox) msgBox.style.display = 'none';
        });
    }
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        resetFormsOnBfcacheRestore();
    });

    /* ── Test hook (WP_DEBUG only) ────────────────────────────────────────── */
    if (window.__FABRICATOR_TEST__) {
        window.FabricatorTestHooks = {
            validatePage:               validatePage,
            initConditions:             initConditions,
            showCaptchaBlockedNotice:   showCaptchaBlockedNotice,
            resetFormsOnBfcacheRestore: resetFormsOnBfcacheRestore,
        };
    }
}());
