function (root) {
    var IBAN_LEN = {
        AD:24,AE:23,AL:28,AT:20,AZ:28,BA:20,BE:16,BG:22,BH:22,BI:27,BR:29,BY:28,
        CH:21,CR:22,CY:28,CZ:24,DE:22,DJ:27,DK:18,DO:28,EE:20,EG:29,ES:24,FI:18,
        FK:18,FO:18,FR:27,GB:22,GE:22,GI:23,GL:18,GR:27,GT:28,HR:21,HU:28,IE:22,
        IL:23,IQ:23,IS:26,IT:27,JO:30,KW:30,KZ:20,LB:28,LC:32,LI:21,LT:20,LU:20,
        LV:21,LY:25,MC:27,MD:24,ME:22,MK:19,MN:20,MR:27,MT:31,MU:30,NI:28,NL:18,
        NO:15,OM:23,PK:24,PL:28,PS:29,PT:25,QA:29,RO:24,RS:22,RU:33,SA:24,SC:31,
        SD:18,SE:24,SI:19,SK:24,SM:27,SO:23,ST:25,SV:28,TL:23,TN:24,TR:26,UA:29,
        VA:22,VG:24,XK:20,YE:30
    };
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
    root.querySelectorAll('.fabricator-sepa-iban').forEach(function (input) {
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
            if (filterMode === 'off' || !filterList.length) return true;
            var inList = filterList.indexOf(cc) !== -1;
            if (filterMode === 'allow')    return inList;
            if (filterMode === 'disallow') return !inList;
            return true;
        }
        var noticeEl = input.parentNode.querySelector('.fabricator-field-hint');
        function showError(msg)       { if (errorEl)  errorEl.textContent  = msg; }
        function showIbanNotice(msg)  { if (noticeEl) noticeEl.textContent = msg; }
        function getBicInput() {
            var field = input.closest('.fabricator-field--sepa');
            return field ? field.querySelector('.fabricator-sepa-bic') : null;
        }
        // Mirrors PHP SepaField::ibanChecksumValid() — moves country+check-digits to the
        // end, converts letters to numbers (A=10..Z=35), then requires mod 97 == 1. Runs
        // client-side regardless of live_iban_lookup so the field can validate locally
        // when the (opt-in, GDPR-gated) openiban.com lookup is disabled.
        function ibanChecksumValid(iban) {
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
        var bicManuallyEntered = false;
        function lookupBic(iban) {
            if (bicManuallyEntered) return;
            if (input.dataset.liveLookup === '0') return;
            var bicInput = getBicInput();
            if (!bicInput) return;
            var ajaxUrl = (window.FabricatorForms && window.FabricatorForms.ajaxUrl) || '';
            if (!ajaxUrl) return;
            bicInput.value    = '';
            bicInput.disabled = true;
            var dots = 0;
            var dotTimer = setInterval(function () {
                dots = (dots + 1) % 4;
                var _i18nLu = window.FabricatorForms && window.FabricatorForms.i18n;
                bicInput.placeholder = ((_i18nLu && _i18nLu.sepa_looking_up) || 'Looking up') + '.'.repeat(dots);
            }, 400);
            var body = new FormData();
            body.append('action', 'fabricator_iban_bic');
            body.append('iban', iban);
            body.append('nonce', (window.FabricatorForms && window.FabricatorForms.ibanBicNonce) || '');
            fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    clearInterval(dotTimer);
                    bicInput.disabled    = false;
                    bicInput.placeholder = 'XXXXXXXXXXX';
                    var d = res.data || {};
                    if (!d.valid) {
                        input._fabricatorIbanValid   = false;
                        input._fabricatorIbanInvalid = true;
                        var _i18nIi = window.FabricatorForms && window.FabricatorForms.i18n;
                        showError((_i18nIi && _i18nIi.sepa_iban_invalid) || 'Invalid IBAN (check digit incorrect).');
                    } else {
                        input._fabricatorIbanValid   = true;
                        input._fabricatorIbanInvalid = false;
                        showError('');
                        if (d.bic && !bicManuallyEntered) {
                            bicInput.value = d.bic;
                        } else if (!d.bankCodeFound) {
                            var _i18nUv = window.FabricatorForms && window.FabricatorForms.i18n;
                            showIbanNotice((_i18nUv && _i18nUv.sepa_iban_unvalidated) || 'Could not be validated.');
                        }
                    }
                })
                .catch(function () {
                    clearInterval(dotTimer);
                    bicInput.disabled    = false;
                    bicInput.placeholder = 'XXXXXXXXXXX';
                });
        }
        var bicEl = getBicInput();
        if (bicEl) {
            bicEl.addEventListener('input', function () { bicManuallyEntered = !!this.value; });
        }
        function getRaw() { return input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase(); }
        input.addEventListener('input', function () {
            input._fabricatorIbanValid   = false;
            input._fabricatorIbanInvalid = false;
            var raw = getRaw();
            var cc  = raw.substring(0, 2);
            if (cc.length === 2 && IBAN_LEN[cc]) {
                if (countryAllowed(cc)) { lastValidCc = cc; showError(''); showIbanNotice(''); }
                else { var _i18nCb = window.FabricatorForms && window.FabricatorForms.i18n; showError((_i18nCb && _i18nCb.sepa_country_blocked) || 'This country is not allowed.'); }
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
                    showError((_i18nCk && _i18nCk.sepa_iban_invalid) || 'Invalid IBAN (check digit incorrect).');
                }
                lookupBic(raw);
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
    root.querySelectorAll('.fabricator-sepa-bic').forEach(function (input) {
        if (input._fabricatorBicInited) return;
        input._fabricatorBicInited = true;
        input.addEventListener('input', function () {
            this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 11);
        });
    });
    /* Signature canvas init — self-contained so SepaField has no dependency
       on SignatureField being registered. Sets data-fabricator-file-count so
       front.js can include SEPA signatures in the total file count. */
    var sepaSigSel = '.fabricator-sepa-mandate .fabricator-signature-wrap';
    root.querySelectorAll(sepaSigSel).forEach(function (wrap) {
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
        function resize() {
            var rect  = canvas.getBoundingClientRect();
            var ratio = window.devicePixelRatio || 1;
            var cssW  = rect.width  || canvas.offsetWidth;
            var fallH = parseFloat(canvas.getAttribute('height') || '160');
            var cssH  = rect.height || canvas.offsetHeight || fallH;
            if (!cssW || !cssH) return;
            canvas.width  = Math.round(cssW * ratio);
            canvas.height = Math.round(cssH * ratio);
            ctx.scale(ratio, ratio);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, cssW, cssH);
            ctx.strokeStyle = '#1d2327';
            ctx.lineWidth   = stroke;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
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
        }
        function end() {
            if (!drawing) return;
            drawing = false;
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
                wrap.dataset.fabricatorFileCount = '0';
            });
        }
        var ownerForm = canvas.closest('form');
        if (ownerForm) {
            ownerForm.addEventListener('reset', function () {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                input.value = '';
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