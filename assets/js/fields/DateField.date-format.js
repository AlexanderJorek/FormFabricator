function (fieldEl) {
    var inp = fieldEl.querySelector('.fabricator-date-text');
    if (!inp || !inp.value.trim()) return null;
    var v = inp.value.trim();
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    /* Mirrors DateField::parseDate(): the field's own format decides the pattern and the order of its parts. */
    var formats = {
        dmy: [/^(\d{2})\.(\d{2})\.(\d{4})$/, 'dmy'],
        mdy: [/^(\d{2})\/(\d{2})\/(\d{4})$/, 'mdy'],
        ymd: [/^(\d{4})-(\d{2})-(\d{2})$/, 'ymd']
    };
    var f = formats[inp.dataset.dateFormat] || formats.dmy;
    var match = f[0].exec(v);
    if (!match)
        return ((_i18n && _i18n.date_invalid_format) || 'Please enter a date in %s format.')
            .replace('%s', inp.dataset.dateLabel || 'DD.MM.YYYY');
    var parts = {};
    f[1].split('').forEach(function (k, i) { parts[k] = parseInt(match[i + 1], 10); });
    var dt = new Date(parts.y, parts.m - 1, parts.d);
    if (dt.getFullYear() !== parts.y || dt.getMonth() !== parts.m - 1 || dt.getDate() !== parts.d)
        return (_i18n && _i18n.date_invalid_date) || 'Please enter a valid date.';
    return null;
}
