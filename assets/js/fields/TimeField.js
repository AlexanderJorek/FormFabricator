function (root) {
    root.querySelectorAll('.fabricator-field--time input[type="time"]').forEach(function (inp) {
        if (inp._fabricatorTimeInit) return;
        inp._fabricatorTimeInit = true;

        if (inp.hasAttribute('data-prefill-now') && !inp.value) {
            var now = new Date();
            var hh = String(now.getHours()).padStart(2, '0');
            var mm = String(now.getMinutes()).padStart(2, '0');
            inp.value = hh + ':' + mm;
        }

        if (inp.dataset.timeFormat === '12h') {
            var hint = document.createElement('span');
            hint.className = 'fabricator-time-12h-hint';
            inp.insertAdjacentElement('afterend', hint);

            var update = function () {
                if (!inp.value) { hint.textContent = ''; return; }
                var parts = inp.value.split(':');
                var h = parseInt(parts[0], 10);
                var m = parts[1] || '00';
                var _i18n  = window.FabricatorForms && window.FabricatorForms.i18n;
                var suffix = h >= 12
                    ? ((_i18n && _i18n.time_pm) || 'PM')
                    : ((_i18n && _i18n.time_am) || 'AM');
                var h12 = h % 12;
                if (h12 === 0) h12 = 12;
                hint.textContent = h12 + ':' + m + ' ' + suffix;
            };
            inp.addEventListener('input', update);
            inp.addEventListener('change', update);
            update();
        }
    });
}
