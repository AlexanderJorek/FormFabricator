function (root) {
    /* Part order and separator per format key, as DateField::FORMATS. */
    var FORMATS = { dmy: ['dmy', '.'], mdy: ['mdy', '/'], ymd: ['ymd', '-'] };
    function formatDate(inp, y, m, d) {
        var f     = FORMATS[inp.dataset.dateFormat] || FORMATS.dmy;
        var parts = { d: String(d).padStart(2, '0'), m: String(m).padStart(2, '0'), y: String(y) };
        return f[0].split('').map(function (k) { return parts[k]; }).join(f[1]);
    }
    function prefillToday(inp) {
        var now = new Date();
        inp.value = formatDate(inp, now.getFullYear(), now.getMonth() + 1, now.getDate());
    }
    root.querySelectorAll('.fabricator-date-wrap').forEach(function (wrap) {
        /* Idempotent: front.js re-runs field inits after every successful submit, which stacked click/change listeners. */
        if (wrap._fabricatorDateInited) return;
        wrap._fabricatorDateInited = true;
        var text   = wrap.querySelector('.fabricator-date-text');
        var btn    = wrap.querySelector('.fabricator-date-cal-btn');
        var picker = wrap.querySelector('.fabricator-date-picker-hidden');
        if (btn && picker) {
            btn.addEventListener('click', function () {
                picker.showPicker ? picker.showPicker() : picker.click();
            });
            picker.addEventListener('change', function () {
                if (!text || !this.value) return;
                var parts = this.value.split('-');
                if (parts.length === 3) {
                    text.value = formatDate(text, parts[0], parts[1], parts[2]);
                }
            });
        }
        if (text && text.dataset.prefillToday === 'true') {
            if (!text.value) prefillToday(text);
            /* Re-applied after front.js resets the form. */
            var ownerForm = text.closest('form');
            if (ownerForm) {
                ownerForm.addEventListener('fabricator:reset', function () {
                    if (!text.value) prefillToday(text);
                });
            }
        }
    });
}
