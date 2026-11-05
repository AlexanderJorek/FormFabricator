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

    /* PHP's trim(), as the server trims an answer (BaseField::answer()); String.prototype.trim() also removes
       U+FEFF and the Unicode spaces, which the server keeps. */
    function phpTrim(s) {
        return s.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
    }

    /* ── Field-type init functions ───────────────────────────────────────── */
    /* Populated by Assets::frontFieldAssets() from each field's getClientInit(); front.js needs no field-type knowledge. */

    /* ── Validator registry ───────────────────────────────────────────────── */
    /* Mirrors each field's PHP validate() — keep both in sync or they'll silently drift. */
    var VALIDATORS = window.FabricatorValidators || {};

    /* ── Client-side validation ──────────────────────────────────────────── */

    /* What an answer may hold, as Utils\Text\AnswerText checks it on the server: an answer is recorded as typed or refused,
       never changed. Refused are control and invisible format characters, line breaks and tabs outside a textarea, and
       HTML character references, which a page or email shows as the character they name. */
    /* The zero-width non-joiner and joiner are typed (a Persian keyboard, emoji such as a family) and reorder nothing. */
    var REFUSED_CHAR = /[\p{Cc}\p{Zl}\p{Zp}]|(?![\u200C\u200D])\p{Cf}/u;
    var CHAR_REFERENCE = /&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);/g;

    function codePoint(ch) {
        var hex = ch.codePointAt(0).toString(16).toUpperCase();
        return 'U+' + ('0000' + hex).slice(-Math.max(4, hex.length));
    }

    /* Whether a character reference names a character (html_entity_decode() changes it), read by the browser's own
       HTML parser in a textarea, where tags stay text. */
    function namesCharacter(ref) {
        var t = document.createElement('textarea');
        t.innerHTML = ref;
        return t.value !== ref;
    }

    function answerProblem(value, multiline, i18n) {
        var text = phpTrim(value);
        if (text === '') return '';
        var checked = multiline ? text.replace(/[\r\n\t]/g, '') : text;
        var m = REFUSED_CHAR.exec(checked);
        if (m) {
            if (!multiline && /[\r\n\t]/.test(m[0])) {
                return i18n.answer_one_line || 'This answer can\'t hold line breaks or tabs. Please write it on one line.';
            }
            return (i18n.answer_invisible || 'This answer contains an invisible character (%s). Please remove it, or type the answer instead of pasting it.')
                .replace('%s', codePoint(m[0]));
        }
        var refs = text.match(CHAR_REFERENCE) || [];
        for (var i = 0; i < refs.length; i++) {
            if (namesCharacter(refs[i])) {
                return i18n.answer_reference || 'This answer contains an HTML character reference (such as "&amp;"). Please write the character itself.';
            }
        }
        return '';
    }

    /* The first problem among the field's typed inputs (text boxes and textareas; not a hidden input, a choice or a
       file), or ''. Field types whose answers aren't typed (BaseField::typedAnswer()) are skipped by the caller. */
    function fieldAnswerProblem(fieldEl, i18n) {
        var inputs = fieldEl.querySelectorAll('textarea, input:not([type]), input[type="text"], input[type="email"], input[type="tel"], input[type="url"], input[type="number"], input[type="search"]');
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].disabled) continue;
            var problem = answerProblem(inputs[i].value || '', inputs[i].tagName === 'TEXTAREA', i18n);
            if (problem) return problem;
        }
        return '';
    }

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
        var untypedTypes   = window.FabricatorUntypedAnswers || [];
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

            /* 3 — What the answer holds: the server refuses the same (Utils\Text\AnswerText) */
            if (untypedTypes.indexOf(type) === -1) {
                var problem = fieldAnswerProblem(fieldEl, i18n);
                if (problem) {
                    hasInvalid = true;
                    markError(fieldEl, problem);
                    return;
                }
            }

            /* 4 — Format/validity check (only when field has content) */
            if (!empty) {
                var rules = [];
                try { rules = JSON.parse(fieldEl.dataset.validate || '[]'); } catch (_) {}
                rules.forEach(function (rule) {
                    if (!VALIDATORS[rule]) return;
                    /* null: valid; a text: the message for the field; true: invalid, the rule wrote its message at the
                       part that is wrong (a mandate's IBAN) and the field's own slot stays empty. */
                    var err = VALIDATORS[rule](fieldEl);
                    if (err === true) {
                        hasInvalid = true;
                        if (!firstError) firstError = fieldEl;
                    } else if (err) {
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

    /* A field on another page of the form (the submit-time check and the server see every page) is shown first:
       scrolling to an element on a hidden page goes nowhere. */
    function scrollToField(fieldEl) {
        if (!fieldEl) return;
        var page = fieldEl.closest('.fabricator-form-page');
        var form = page && page.closest('.fabricator-form');
        if (form && !page.classList.contains('fabricator-page-active')) {
            form.dispatchEvent(new CustomEvent('fabricator:show-page', { bubbles: false, cancelable: false, detail: { page: page } }));
        }
        var top = fieldEl.getBoundingClientRect().top + window.pageYOffset - 80;
        window.scrollTo(0, Math.max(0, top));
    }

    /* The message box sits above the form, out of view from the submit button at its end. */
    function scrollToMessages(msgBox) {
        if (!msgBox) return;
        var top = msgBox.getBoundingClientRect().top + window.pageYOffset - 20;
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    }

    /* ── Errors for screen readers and the keyboard ───────────────────────── */
    /* A field's error slots are no live regions: the form's message area announces the errors once, with a link to each
       field, and the cursor goes to the first. Each control is tied to the field's description and to the slots that
       concern it (aria-describedby), and is aria-invalid while one of them holds a message, so a screen reader reads the
       error when the control gets the focus. */
    var ERROR_SLOTS = '.fabricator-field-error, .fabricator-signature-place-error, .fabricator-upload-error';
    var CONTROLS    = 'input:not([type="hidden"]), select, textarea';
    var ariaIds     = 0;

    /* Elements of this field, not of a field nested in it (a group's children). */
    function ownedBy(fieldEl, selector) {
        return Array.prototype.filter.call(fieldEl.querySelectorAll(selector), function (el) {
            return el.closest('.fabricator-field') === fieldEl;
        });
    }

    function idOf(el, kind) {
        if (!el.id) el.id = 'fabricator-' + kind + '-' + (++ariaIds);
        return el.id;
    }

    function hasMessage(slot) {
        return slot.textContent.trim() !== '';
    }

    function syncFieldAria(fieldEl) {
        var desc  = ownedBy(fieldEl, '.fabricator-field-description')[0];
        var slots = ownedBy(fieldEl, ERROR_SLOTS);
        ownedBy(fieldEl, CONTROLS).forEach(function (control) {
            /* The ids the markup gave it come first and stay. */
            if (control.dataset.fabricatorDescribedby === undefined) {
                control.dataset.fabricatorDescribedby = control.getAttribute('aria-describedby') || '';
            }
            var ids = control.dataset.fabricatorDescribedby.split(/\s+/).filter(Boolean);
            if (desc) ids.push(idOf(desc, 'description'));
            var invalid = false;
            slots.forEach(function (slot) {
                /* A part's slot (a mandate's IBAN, an address's street) concerns that part; the field's, all of it. */
                if (!slot.parentNode.contains(control)) return;
                ids.push(idOf(slot, 'error'));
                if (hasMessage(slot)) invalid = true;
            });
            ids = ids.filter(function (id, i) { return ids.indexOf(id) === i; });
            if (ids.length) {
                control.setAttribute('aria-describedby', ids.join(' '));
            } else {
                control.removeAttribute('aria-describedby');
            }
            if (invalid) {
                control.setAttribute('aria-invalid', 'true');
            } else {
                control.removeAttribute('aria-invalid');
            }
        });
    }

    /* Messages reach the slots from validatePage(), the server's answer and the field scripts (a mandate checks its IBAN
       when the visitor leaves it), so the slots are watched rather than each writer asked to keep the attributes. */
    function initErrorAria(root) {
        root.querySelectorAll('.fabricator-form').forEach(function (form) {
            if (form.dataset.fabricatorAriaInit) return;
            form.dataset.fabricatorAriaInit = '1';
            form.querySelectorAll('.fabricator-field').forEach(syncFieldAria);
            if (typeof MutationObserver !== 'function') return;
            new MutationObserver(function (records) {
                var fields = [];
                records.forEach(function (record) {
                    var el   = record.target.nodeType === 1 ? record.target : record.target.parentNode;
                    var slot = el && el.closest && el.closest(ERROR_SLOTS);
                    var fieldEl = slot && slot.closest('.fabricator-field');
                    if (fieldEl && fields.indexOf(fieldEl) === -1) fields.push(fieldEl);
                });
                fields.forEach(syncFieldAria);
            }).observe(form, { childList: true, characterData: true, subtree: true });
        });
    }

    /* The fields in scope with an error, in page order. */
    function fieldsWithErrors(scope) {
        return Array.prototype.filter.call(scope.querySelectorAll('.fabricator-field'), function (fieldEl) {
            return ownedBy(fieldEl, ERROR_SLOTS).some(hasMessage);
        });
    }

    /* The field's name as its label gives it, without the required mark. */
    function fieldName(fieldEl) {
        var label = ownedBy(fieldEl, '.fabricator-label')[0];
        var name  = '';
        if (label) {
            var copy = label.cloneNode(true);
            copy.querySelectorAll('.fabricator-required').forEach(function (mark) { mark.remove(); });
            name = copy.textContent.trim();
        }
        if (!name) {
            var control = ownedBy(fieldEl, CONTROLS)[0];
            name = (control && control.getAttribute('aria-label')) || '';
        }
        var i18n = (window.FabricatorForms && window.FabricatorForms.i18n) || {};
        return name || i18n.unnamed_field || 'Unnamed field';
    }

    /* Shows the field (on its page, in view) and puts the cursor in it: in its first control with an error, else its
       first control, else on the field itself (a drawing pad). */
    function focusField(fieldEl) {
        if (!fieldEl) return;
        scrollToField(fieldEl);
        var controls = ownedBy(fieldEl, CONTROLS).filter(function (c) { return !c.disabled; });
        var target   = controls.filter(function (c) { return c.getAttribute('aria-invalid') === 'true'; })[0] || controls[0];
        if (!target) {
            if (!fieldEl.hasAttribute('tabindex')) fieldEl.setAttribute('tabindex', '-1');
            target = fieldEl;
        }
        target.focus({ preventScroll: true });
    }

    /* After a check failed in scope: the message area names the fields with an error, each a link to it, and the cursor
       goes to the first. The message area is the form's one live region, so this is read out once, not once a field. */
    function reportErrors(form, scope, message) {
        var fields = fieldsWithErrors(scope);
        fields.forEach(syncFieldAria);
        var wrap   = form.closest('.fabricator-form-wrap');
        var msgBox = wrap && wrap.querySelector('.fabricator-form-messages');
        if (msgBox && message) {
            msgBox.className   = 'fabricator-form-messages error';
            msgBox.textContent = message;
            if (fields.length) {
                var list = document.createElement('ul');
                fields.forEach(function (fieldEl) {
                    var control = ownedBy(fieldEl, CONTROLS)[0];
                    var link    = document.createElement('a');
                    link.href        = '#' + (control ? idOf(control, 'control') : idOf(fieldEl, 'field'));
                    link.textContent = fieldName(fieldEl);
                    link.addEventListener('click', function (e) {
                        e.preventDefault();
                        focusField(fieldEl);
                    });
                    var item = document.createElement('li');
                    item.appendChild(link);
                    list.appendChild(item);
                });
                msgBox.appendChild(list);
            }
            msgBox.style.display = '';
        }
        if (fields.length) {
            focusField(fields[0]);
        } else {
            scrollToMessages(msgBox);
        }
    }

    /* ── Multi-page navigation ────────────────────────────────────────────── */

    function initPageBreaks(root) {
        var forms = root.querySelectorAll('.fabricator-form');
        forms.forEach(function (form) {
            /* Once per form, even if the script is included twice. */
            if (form.dataset.fabricatorPagesInit) return;
            form.dataset.fabricatorPagesInit = '1';

            var pages = Array.from(form.querySelectorAll('.fabricator-form-page'));
            if (!pages.length) return;

            /* The page-header row moves before the pages, to stay visible on every page. */
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
                    /* A class, not style.display, which the submit-button conditions own. */
                    footer.classList.toggle('fabricator-footer-off-page', idx !== pages.length - 1);
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

                /* Spacer that lets the footer descend smoothly to the shorter page's height. */
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

            /* A page the visitor asked for: the cursor goes to its top, so the keyboard and a screen reader carry on there
               instead of on the button that is now hidden. */
            function goToPage(idx) {
                showPage(idx, true);
                if (!pages[idx].hasAttribute('tabindex')) pages[idx].setAttribute('tabindex', '-1');
                pages[idx].focus({ preventScroll: true });
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

            /* scrollToField() asks for the page that holds a field with an error. */
            form.addEventListener('fabricator:show-page', function (e) {
                var idx = pages.indexOf(e.detail && e.detail.page);
                if (idx !== -1 && idx !== currentIdx()) showPage(idx, false);
            });

            form.addEventListener('click', function (e) {
                if (e.target.classList.contains('fabricator-btn-next')) {
                    var idx    = currentIdx();
                    var result = validatePage(pages[idx]);
                    if (!result.valid) {
                        focusField(result.firstError);
                        return;
                    }
                    if (idx < pages.length - 1) goToPage(idx + 1);
                }
                if (e.target.classList.contains('fabricator-btn-prev')) {
                    var idx2 = currentIdx();
                    if (idx2 > 0) goToPage(idx2 - 1);
                }
                var gotoEl = e.target.closest('[data-fabricator-goto-page]');
                if (gotoEl) {
                    var gotoIdx = parseInt(gotoEl.getAttribute('data-fabricator-goto-page'), 10);
                    if (!isNaN(gotoIdx) && gotoIdx >= 0 && gotoIdx <= furthest && gotoIdx !== currentIdx()) {
                        goToPage(gotoIdx);
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

            /* The conditional elements hidden by the pass being decided; see applyAll(). */
            var hiddenNow = new Set();

            /* The server compares answers as typed, trimmed (phpTrim()). */
            function readValue(el) {
                return phpTrim(String(el.value || ''));
            }

            /* Inside a conditional element the current pass hides: read as absent (BaseField::hiddenConditionValue()). */
            function isMasked(el) {
                var p = el.parentElement;
                while (p && p !== form) {
                    if (hiddenNow.has(p)) return true;
                    p = p.parentElement;
                }
                return false;
            }

            /* Fields without an input named after them, read as BaseField::conditionValue() does: "id[key]" inputs as
               their non-empty values joined by a space ("_" keys skipped), a CAPTCHA as its reCAPTCHA token. */
            function getCompositeValue(fieldId) {
                var prefix = fieldId + '[';
                var parts  = [];
                form.querySelectorAll('[name^="' + CSS.escape(prefix) + '"]').forEach(function (el) {
                    var key = el.name.slice(prefix.length, -1);
                    if (el.name.slice(-1) !== ']' || key === '' || key.indexOf('[') !== -1 || key.indexOf(']') !== -1) return;
                    if (key.charAt(0) === '_' || isMasked(el)) return;
                    var v = readValue(el);
                    if (v !== '') parts.push(v);
                });
                if (parts.length) return parts.join(' ');
                var wrap = form.querySelector('[data-field-id="' + CSS.escape(fieldId) + '"]');
                if (wrap && wrap.classList.contains('fabricator-field--captcha') && !isMasked(wrap)) {
                    var token = wrap.querySelector('[name="g-recaptcha-response"]');
                    return token ? readValue(token) : '';
                }
                return '';
            }

            function getFieldValue(fieldId) {
                var name = CSS.escape(fieldId);
                /* "{id}[]" for checkboxes, the bare id otherwise. */
                var all = Array.from(form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]'));
                if (!all.length) return getCompositeValue(fieldId);
                var inputs = all.filter(function (i) { return !isMasked(i); });
                if (!inputs.length) return all[0].type === 'checkbox' ? [] : '';
                var first = inputs[0];
                /* A file input: the number of files chosen, as UploadField::conditionValue() counts them. */
                if (first.type === 'file') {
                    var n = first.files ? first.files.length : 0;
                    return n > 0 ? String(n) : '';
                }
                if (first.type === 'radio') {
                    var chk = inputs.find(function (i) { return i.checked; });
                    return chk ? readValue(chk) : '';
                }
                if (first.type === 'checkbox') {
                    return inputs.filter(function (i) { return i.checked; }).map(readValue);
                }
                if (first.tagName === 'SELECT' && first.multiple) {
                    return Array.from(first.selectedOptions).map(readValue);
                }
                return readValue(first);
            }

            /* The shape PHP's is_numeric() accepts, so both sides agree on what counts as a number. */
            function asNumber(v) {
                var t = (v === null || v === undefined ? '' : v).toString().trim();
                return /^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/.test(t) ? parseFloat(t) : null;
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
                    /* Empty value = half-configured rule: unsatisfied, matching ConditionRule::holds(). */
                    case 'not_contains': return rv !== '' && (isArr
                        ? val.every(function (v) { return v.toLowerCase().indexOf(rv) === -1; })
                        : str.indexOf(rv) === -1);
                    case 'empty':        return isArr ? val.length === 0 : str === '';
                    case 'not_empty':    return isArr ? val.length > 0   : str !== '';
                    /* asNumber(), not parseFloat(): parseFloat('12abc') is 12 while the server's is_numeric() says no,
                       which is another way client and server disagreed on whether a field is visible. */
                    case 'greater':      { var a = asNumber(str), b = asNumber(rv); return a !== null && b !== null && a > b; }
                    case 'less':         { var a2 = asNumber(str), b2 = asNumber(rv); return a2 !== null && b2 !== null && a2 < b2; }
                    /* Unsatisfied, like ConditionRule::holds(): "true" showed a field the server then treated as hidden and dropped. */
                    default:             return false;
                }
            }

            /* A hidden field reads as empty, which can change another. From "all visible", passes repeat until nothing
               changes, at most MAX_PASSES, exactly as FormProcessor::resolveVisibility(). */
            var MAX_PASSES = 64; /* FormProcessor::MAX_CONDITION_PASSES */

            function applyAll() {
                var targets = [];
                form.querySelectorAll('[data-conditions]').forEach(function (el) {
                    var cond;
                    try { cond = JSON.parse(el.dataset.conditions); } catch (_) { return; }
                    if (!cond || !(cond.rules || []).length) return;
                    targets.push({ el: el, cond: cond });
                });
                hiddenNow = new Set();
                for (var pass = 0; pass < MAX_PASSES; pass++) {
                    var next = new Set();
                    targets.forEach(function (t) {
                        var rules = t.cond.rules;
                        var match = t.cond.match === 'any' ? rules.some(testRule) : rules.every(testRule);
                        if (t.cond.action === 'hide' ? match : !match) next.add(t.el);
                    });
                    var settled = next.size === hiddenNow.size
                        && Array.from(next).every(function (el) { return hiddenNow.has(el); });
                    hiddenNow = next;
                    if (settled) break;
                }
                targets.forEach(function (t) {
                    t.el.style.display = hiddenNow.has(t.el) ? 'none' : '';
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

            /* Rendered disabled, so no native POST happens without JS; enabled once the handler is attached. */
            var initialSubmitBtn = form.querySelector('.fabricator-submit-btn');
            if (initialSubmitBtn) initialSubmitBtn.disabled = false;

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
                    reportErrors(form, form, msg);
                    return;
                }

                /* Total file count across fields, against PHP's max_file_uploads (data-max-files). */
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
                    scrollToMessages(msgBox);
                }

                var ajaxUrl = (window.FabricatorForms && window.FabricatorForms.ajaxUrl) || '';

                /* Nonce/token fetched fresh here, never rendered into HTML, so a full-page cache can't hand two visitors the same replay token. */
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
                        resetForm(form);
                        /* Scroll to form top so the success message is in view */
                        var scrollTarget = wrap || form;
                        var scrollTop = scrollTarget.getBoundingClientRect().top + window.pageYOffset - 20;
                        window.scrollTo({ top: Math.max(0, scrollTop), behavior: 'smooth' });

                        /* Editing the reset form clears fabricatorSubmitted, so a new entry can be sent. */
                        if (btn) {
                            var rearmSubmit = function () {
                                delete btn.dataset.fabricatorSubmitted;
                                form.removeEventListener('input', rearmSubmit);
                                form.removeEventListener('change', rearmSubmit);
                            };
                            form.addEventListener('input', rearmSubmit);
                            form.addEventListener('change', rearmSubmit);
                        }
                    } else {
                        if (label) label.textContent = origLabel;
                        if (btn) btn.disabled = false;
                        resetCaptchas(form);
                        var errMsg = (res.data && res.data.message)
                            || i18n.error_server
                            || 'Server error. Please try again.';

                        /* Per-field server errors go to their fields; the message names them, or stands alone. */
                        var fieldErrors = res.data && res.data.errors;
                        if (fieldErrors) {
                            /* A later copy of the same form on the page carries an id suffix (FormRenderer::uniqueIds()). */
                            var idSuffix = form.dataset.fabricatorIdSuffix || '';
                            Object.keys(fieldErrors).forEach(function (fid) {
                                var errEl = form.querySelector('#' + CSS.escape(fid + '-error' + idSuffix));
                                if (errEl) errEl.textContent = fieldErrors[fid];
                            });
                        }
                        reportErrors(form, form, errMsg);
                    }
                    })
                    .catch(function () {
                        resetSubmitUi();
                        resetCaptchas(form);
                        showServerError();
                    });
                })
                .catch(function () {
                    resetSubmitUi();
                    resetCaptchas(form);
                    showServerError();
                });
            });
        });
    }

    /* A used or expired token can't be sent again, so every failed submission clears the widget for a fresh one. */
    function resetCaptchas(form) {
        form.querySelectorAll('altcha-widget').forEach(function (widget) {
            if (typeof widget.reset === 'function') widget.reset();
        });
        if (!window.grecaptcha || !window.grecaptcha.reset) return;
        form.querySelectorAll('.fabricator-captcha-gate[data-fabricator-captcha-widget]').forEach(function (gate) {
            try { window.grecaptcha.reset(Number(gate.dataset.fabricatorCaptchaWidget)); } catch (_) { /* widget gone */ }
        });
    }

    /* ── CAPTCHA click-to-activate ── */
    /* reCAPTCHA is fetched only on explicit click, not page load, so the form doesn't load a third-party script unasked. */
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
    /* Extracted so the JS test suite (tests/js/resilience.test.js) can drive it without a real blocked request. */
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
                    /* Kept so a failed submission can reset the widget: its token is single-use and expires
                       after about two minutes, so without this every retry failed "Please confirm the CAPTCHA". */
                    gate.dataset.fabricatorCaptchaWidget = String(
                        window.grecaptcha.render(widget, { sitekey: gate.dataset.sitekey })
                    );
                }, function () { showCaptchaBlockedNotice(gate, btn); });
            });
        });
    }

    /* ── Boot ─────────────────────────────────────────────────────────────── */

    function init(root) {
        root = root || document;
        /* Boots once per document. Field inits must be idempotent anyway: initForms() re-runs them after each submit. */
        if (root === document && window.__fabricatorFrontInited) return;
        if (root === document) window.__fabricatorFrontInited = true;

        var fieldInits = window.FabricatorFieldInits || {};
        Object.keys(fieldInits).forEach(function (type) { fieldInits[type](root); });
        initPageBreaks(root);
        initConditions(root);
        initForms(root);
        initCaptchaGates(root);
        initErrorAria(root);
    }

    /* Scoped to the new wrapper, never document: boots only what arrived, instead of re-running every step over the whole page. */
    function initLateForm(form) {
        if (!form || form.dataset.fabricatorFormsInit) return;
        var scope = (form.closest && form.closest('.fabricator-form-wrap')) || form.parentElement;
        if (scope) init(scope);
    }

    /* childList+subtree on document.body fires on every DOM mutation site-wide, so skip via a cheap property read before querySelectorAll(). */
    var lateFormObserver = null;

    function observeLateForms() {
        if (typeof MutationObserver !== 'function' || !document.body) return;
        if (lateFormObserver) return;
        lateFormObserver = new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (!node || node.nodeType !== 1) return;
                    if (node.classList && node.classList.contains('fabricator-form')) {
                        initLateForm(node);
                    } else if (node.firstElementChild && node.querySelectorAll) {
                        Array.prototype.forEach.call(
                            node.querySelectorAll('.fabricator-form'),
                            initLateForm
                        );
                    }
                });
            });
        });
        lateFormObserver.observe(document.body, { childList: true, subtree: true });
    }

    /* Explicit escape hatch for integrations that insert forms in ways a MutationObserver on
       document.body can't see (e.g. inside a shadow root): FabricatorForms.initForms(container). */
    window.FabricatorForms = window.FabricatorForms || {};
    window.FabricatorForms.initForms = function (container) {
        init(container || document);
    };

    /* Lets a site that inserts every form up front (or one that manages its own late forms via
       initForms above) stop paying for the observer entirely. */
    window.FabricatorForms.stopObservingLateForms = function () {
        if (lateFormObserver) {
            lateFormObserver.disconnect();
            lateFormObserver = null;
        }
    };

    function boot() {
        init(document);
        observeLateForms();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    /* Resets stale submitted/message state after a bfcache restore; named so tests can call it directly. */
    /* Clears a form for the next entry, after a send or a back/forward-cache restore. form.reset() fires no event, so
       this dispatches change and fabricator:reset for the widgets and conditions, and re-runs the field inits. */
    function resetForm(form) {
        form.reset();
        form.dispatchEvent(new Event('change'));
        form.dispatchEvent(new Event('fabricator:reset'));
        var fi = window.FabricatorFieldInits || {};
        Object.keys(fi).forEach(function (t) { fi[t](form); });
    }

    function resetFormsOnBfcacheRestore() {
        document.querySelectorAll('.fabricator-form').forEach(function (form) {
            resetForm(form);
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

    /* ── Test hook: set only by the Node test suite (tests/js/support/page.js), never on a real page ── */
    if (window.__FABRICATOR_TEST__) {
        window.FabricatorTestHooks = {
            validatePage:               validatePage,
            reportErrors:               reportErrors,
            initConditions:             initConditions,
            showCaptchaBlockedNotice:   showCaptchaBlockedNotice,
            resetFormsOnBfcacheRestore: resetFormsOnBfcacheRestore,
        };
    }
}());
