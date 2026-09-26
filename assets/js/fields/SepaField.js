function (root) {
    // Single source of truth is PHP SepaField::IBAN_LEN, localized into window.FabricatorForms.ibanLen
    // (see Utils/Assets.php, Admin/FormEditor.php) — no separate copy here.
    var IBAN_LEN = (window.FabricatorForms && window.FabricatorForms.ibanLen) || {};
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
        // Mirrors PHP SepaField::ibanChecksumValid().
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
    // Self-contained so SepaField has no dependency on SignatureField; sets data-fabricator-file-count for front.js's total file count.
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
        var drew    = false;
        var lastW   = 0;
        function resize() {
            var rect  = canvas.getBoundingClientRect();
            var ratio = window.devicePixelRatio || 1;
            var cssW  = rect.width  || canvas.offsetWidth;
            var fallH = parseFloat(canvas.getAttribute('height') || '160');
            var cssH  = rect.height || canvas.offsetHeight || fallH;
            if (!cssW || !cssH) return;
            /* Resizing a canvas clears it, while the hidden input still holds the signature: without redrawing, a mobile
               address bar collapsing or a rotation showed an empty pad yet submitted the old signature. Snapshot and
               redraw, as SignatureField.js does. */
            var snap = lastW ? canvas.toDataURL() : null;
            lastW = cssW;
            canvas.width  = Math.round(cssW * ratio);
            canvas.height = Math.round(cssH * ratio);
            ctx.scale(ratio, ratio);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, cssW, cssH);
            ctx.strokeStyle = '#1d2327';
            ctx.lineWidth   = stroke;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            if (snap && snap !== 'data:,' && input.value) {
                var img = new Image();
                img.onload = function () { ctx.drawImage(img, 0, 0, cssW, cssH); };
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