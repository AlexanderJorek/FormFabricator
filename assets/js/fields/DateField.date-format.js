function (fieldEl) {
    var inp = fieldEl.querySelector('.fabricator-date-text');
    if (!inp || !inp.value.trim()) return null;
    var v = inp.value.trim();
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (!/^\d{2}\.\d{2}\.\d{4}$/.test(v))
        return (_i18n && _i18n.date_invalid_format) || 'Please enter a date in DD.MM.YYYY format.';
    var p  = v.split('.');
    var d  = parseInt(p[0], 10);
    var m  = parseInt(p[1], 10);
    var y  = parseInt(p[2], 10);
    var dt = new Date(y, m - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d)
        return (_i18n && _i18n.date_invalid_date) || 'Please enter a valid date.';
    return null;
}
