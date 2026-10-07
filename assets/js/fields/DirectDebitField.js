function (root) {
    // Single source of truth is PHP DirectDebitField::IBAN_LEN, localized into window.FabricatorForms.ibanLen
    // (see Utils/Assets.php, Admin/FormEditor.php) — no separate copy here.
    var IBAN_LEN = (window.FabricatorForms && window.FabricatorForms.ibanLen) || {};
    // DirectDebitField::SEPA_COUNTRIES: an IBAN from outside SEPA is refused whatever the field's own filter says.
    var SEPA_COUNTRIES = (window.FabricatorForms && window.FabricatorForms.sepaCountries) || [];
    // DirectDebitField::SEPA_NON_EEA: an IBAN from these needs a BIC, so the BIC's required mark shows for them.
    var SEPA_NON_EEA = (window.FabricatorForms && window.FabricatorForms.sepaNonEea) || [];
    function ibanTemplate(cc) {
        var len = IBAN_LEN[cc];
        if (!len) return cc + '__ …';
        var raw = cc + new Array(len - 1).join('_');
        var out = '', pos = 0;
        while (pos < raw.length) {
            if (pos > 0) out += ' ';
            out += raw.substring(pos, pos + 4);
            pos += 4;
        }
        return out;
    }
    root.querySelectorAll('.fabricator-debit-iban').forEach(function (input) {
        if (input._fabricatorIbanInited) return;
        input._fabricatorIbanInited = true;
        var filterMode  = input.dataset.countryFilter || 'off';
        var filterList  = input.dataset.countryList
            ? input.dataset.countryList.toUpperCase().split(',') : [];
        var defaultCc   = (input.dataset.placeholderCountry || 'DE').toUpperCase();
        var errorEl     = input.parentNode.querySelector('.fabricator-field-error');
        var lastValidCc = IBAN_LEN[defaultCc] ? defaultCc : 'DE';
        input.placeholder = ibanTemplate(lastValidCc);
        function countryAllowed(cc) {
            if (SEPA_COUNTRIES.indexOf(cc) === -1) return false;
            if (filterMode === 'off' || !filterList.length) return true;
            var inList = filterList.indexOf(cc) !== -1;
            if (filterMode === 'allow')    return inList;
            if (filterMode === 'disallow') return !inList;
            return true;
        }
        var noticeEl = input.parentNode.querySelector('.fabricator-field-hint');
        function showError(msg)       { if (errorEl)  errorEl.textContent  = msg; }
        function showIbanNotice(msg)  { if (noticeEl) noticeEl.textContent = msg; }
        // Mirrors PHP DirectDebitField::ibanChecksumValid(): check digits 02-98 (ISO 13616), then mod-97.
        function ibanChecksumValid(iban) {
            var checkDigits = parseInt(iban.substring(2, 4), 10);
            if (!(checkDigits >= 2 && checkDigits <= 98)) return false;
            var rearranged = iban.substring(4) + iban.substring(0, 4);
            var numeric = '';
            for (var i = 0; i < rearranged.length; i++) {
                var ch = rearranged.charAt(i);
                numeric += /[A-Z]/.test(ch) ? String(ch.charCodeAt(0) - 55) : ch;
            }
            var remainder = 0;
            for (var pos = 0; pos < numeric.length; pos += 7) {
                remainder = Number(String(remainder) + numeric.substring(pos, pos + 7)) % 97;
            }
            return remainder === 1;
        }
        function getRaw() { return input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase(); }
        // The BIC is required exactly when the server will ask for it (DirectDebitField::validate()): for an IBAN
        // from a SEPA country outside the EEA. Its mark is rendered only on a required mandate.
        var mandate = input.closest('.fabricator-field--directdebit');
        var bicMark = mandate && mandate.querySelector('.fabricator-debit-bic-mark');
        var bicIn   = mandate && mandate.querySelector('.fabricator-debit-bic');
        function markBic() {
            var needed = SEPA_NON_EEA.indexOf(getRaw().substring(0, 2)) !== -1;
            if (!bicMark) return; // an optional mandate: nothing is marked required
            bicMark.style.display = needed ? '' : 'none';
            if (bicIn) bicIn.setAttribute('aria-required', needed ? 'true' : 'false');
        }
        markBic();
        input.addEventListener('input', markBic);
        input.addEventListener('input', function () {
            input._fabricatorIbanValid   = false;
            input._fabricatorIbanInvalid = false;
            var raw = getRaw();
            var cc  = raw.substring(0, 2);
            if (cc.length === 2 && IBAN_LEN[cc]) {
                if (countryAllowed(cc)) { lastValidCc = cc; showError(''); showIbanNotice(''); }
                else { var _i18nCb = window.FabricatorForms && window.FabricatorForms.i18n; showError((_i18nCb && _i18nCb.debit_country_blocked) || 'This country is not allowed.'); }
            } else { showError(''); showIbanNotice(''); }
            this.placeholder = ibanTemplate(lastValidCc);
            var maxLen = IBAN_LEN[cc] || 34;
            raw = raw.substring(0, maxLen);
            var out = '', pos = 0;
            while (pos < raw.length) {
                if (pos > 0) out += ' ';
                out += raw.substring(pos, pos + 4);
                pos += 4;
            }
            this.value = out;
            if (cc.length === 2 && IBAN_LEN[cc] && raw.length === IBAN_LEN[cc] && countryAllowed(cc)) {
                if (ibanChecksumValid(raw)) {
                    input._fabricatorIbanValid   = true;
                    input._fabricatorIbanInvalid = false;
                    showError('');
                } else {
                    input._fabricatorIbanValid   = false;
                    input._fabricatorIbanInvalid = true;
                    var _i18nCk = window.FabricatorForms && window.FabricatorForms.i18n;
                    showError((_i18nCk && _i18nCk.debit_iban_invalid) || 'Invalid IBAN (check digit incorrect).');
                }
            }
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace') {
                var pos = this.selectionStart;
                if (pos > 0 && this.value[pos - 1] === ' ') {
                    this.value = this.value.slice(0, pos - 1) + this.value.slice(pos);
                    this.setSelectionRange(pos - 1, pos - 1);
                    e.preventDefault();
                }
            }
        });
    });
    root.querySelectorAll('.fabricator-debit-bic').forEach(function (input) {
        if (input._fabricatorBicInited) return;
        input._fabricatorBicInited = true;
        input.addEventListener('input', function () {
            this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 11);
        });
    });
    // Digits only, cut to the input's maxlength; a sort code reads as 12-34-56 while typing.
    root.querySelectorAll('.fabricator-debit-sort-code, .fabricator-debit-routing, .fabricator-debit-account').forEach(function (input) {
        if (input._fabricatorDigitsInited) return;
        input._fabricatorDigitsInited = true;
        var sortCode = input.classList.contains('fabricator-debit-sort-code');
        var max      = sortCode ? 6 : (parseInt(input.getAttribute('maxlength'), 10) || 17);
        input.addEventListener('input', function () {
            var digits = this.value.replace(/\D/g, '').slice(0, max);
            this.value = sortCode ? digits.replace(/(\d{2})(?=\d)/g, '$1-') : digits;
        });
    });
    // Self-contained so DirectDebitField has no dependency on SignatureField. Clearing the pad also resets
    // data-fabricator-file-count to 0; nothing here counts the signature as a file.
    var sigSel = '.fabricator-debit-mandate .fabricator-signature-wrap';
    root.querySelectorAll(sigSel).forEach(function (wrap) {
        if (wrap._fabricatorCanvasInited) return;
        wrap._fabricatorCanvasInited = true;
        var canvas   = wrap.querySelector('.fabricator-signature-canvas');
        var input    = wrap.querySelector('input[type="hidden"]');
        var clearBtn = wrap.querySelector('.fabricator-signature-clear');
        if (!canvas || !input) return;
        var ctx    = canvas.getContext('2d');
        var stroke = parseFloat(wrap.dataset.stroke || '2');
        var fmt    = wrap.dataset.format || 'png';
        var drawing = false;
        var drew    = false;
        var lastW   = 0;
        var lastH   = 0;
        /* Turning a phone resizes the pad twice while the snapshot still loads: the second resize reuses that snapshot,
           and only the latest one paints. */
        var pendingSnap = null;
        var resizeGen   = 0;
        /* Clearing the pad: a redraw still loading must not paint the old drawing back. */
        function cancelRedraw() {
            pendingSnap = null;
            resizeGen++;
        }
        function resize() {
            var rect  = canvas.getBoundingClientRect();
            var ratio = window.devicePixelRatio || 1;
            var cssW  = rect.width  || canvas.offsetWidth;
            var fallH = parseFloat(canvas.getAttribute('height') || '160');
            var cssH  = rect.height || canvas.offsetHeight || fallH;
            if (!cssW || !cssH) return;
            if (cssW === lastW && cssH === lastH) return; // nothing to redraw
            /* Resizing clears the canvas but not the hidden input, so redraw, as SignatureField.js does. */
            var snap = pendingSnap || (lastW ? canvas.toDataURL() : null);
            lastW = cssW;
            lastH = cssH;
            canvas.width  = Math.round(cssW * ratio);
            canvas.height = Math.round(cssH * ratio);
            ctx.scale(ratio, ratio);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, cssW, cssH);
            ctx.strokeStyle = '#1d2327';
            ctx.lineWidth   = stroke;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            var gen = ++resizeGen;
            pendingSnap = null;
            if (snap && snap !== 'data:,' && input.value) {
                pendingSnap = snap;
                var img = new Image();
                img.onload = function () {
                    if (gen !== resizeGen) return; // a later resize paints it at the newer size
                    ctx.drawImage(img, 0, 0, cssW, cssH);
                    pendingSnap = null;
                };
                img.src = snap;
            }
        }
        function pos(e) {
            var rect = canvas.getBoundingClientRect();
            var src  = e.touches ? e.touches[0] : e;
            return { x: src.clientX - rect.left, y: src.clientY - rect.top };
        }
        function start(e) {
            e.preventDefault();
            drawing = true;
            var p = pos(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        }
        function move(e) {
            if (!drawing) return;
            e.preventDefault();
            var p = pos(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            drew = true;
        }
        function end() {
            if (!drawing) return;
            drawing = false;
            /* A tap that draws nothing left a blank white image behind, which validate() accepts — so the mandate
               could be submitted with an empty signature. Same guard as SignatureField.js. */
            if (!drew) return;
            var mime = fmt === 'jpeg' ? 'image/jpeg' : 'image/png';
            input.value = canvas.toDataURL(mime);
        }
        canvas.addEventListener('mousedown',  start, { passive: false });
        canvas.addEventListener('mousemove',  move,  { passive: false });
        document.addEventListener('mouseup',  end);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove',  move,  { passive: false });
        canvas.addEventListener('touchend',   end);
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                input.value = '';
                drew        = false;
                cancelRedraw();
                wrap.dataset.fabricatorFileCount = '0';
            });
        }
        var ownerForm = canvas.closest('form');
        if (ownerForm) {
            ownerForm.addEventListener('reset', function () {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                input.value = '';
                drew        = false;
                cancelRedraw();
            });
        }
        resize();
        window.addEventListener('resize', resize);
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(function (entries) {
                if (entries[0].contentRect.width > 0) resize();
            }).observe(canvas);
        }
    });
}
