function (root) {
    root.querySelectorAll('.fabricator-date-cal-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap   = this.closest('.fabricator-date-wrap');
            var picker = wrap && wrap.querySelector('.fabricator-date-picker-hidden');
            if (!picker) return;
            picker.showPicker ? picker.showPicker() : picker.click();
        });
    });
    root.querySelectorAll('.fabricator-date-picker-hidden').forEach(function (picker) {
        picker.addEventListener('change', function () {
            var wrap = this.closest('.fabricator-date-wrap');
            var text = wrap && wrap.querySelector('.fabricator-date-text');
            if (!text || !this.value) return;
            var parts = this.value.split('-');
            if (parts.length === 3) {
                text.value = parts[2] + '.' + parts[1] + '.' + parts[0];
            }
        });
    });
    root.querySelectorAll('.fabricator-date-text[data-prefill-today="true"]').forEach(function (inp) {
        if (inp.value) return;
        var now = new Date();
        var d   = String(now.getDate()).padStart(2, '0');
        var m   = String(now.getMonth() + 1).padStart(2, '0');
        inp.value = d + '.' + m + '.' + now.getFullYear();
    });
}