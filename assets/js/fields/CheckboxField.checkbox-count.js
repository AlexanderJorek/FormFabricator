function (fieldEl) {
    var group = fieldEl.querySelector('.fabricator-checkbox-group');
    if (!group) return null;
    var min = parseInt(group.dataset.minSelections || '0', 10);
    var max = parseInt(group.dataset.maxSelections || '0', 10);
    if (!min && !max) return null;
    var cnt = fieldEl.querySelectorAll('input[type="checkbox"]:checked').length;
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (min > 0 && cnt < min) return (_i18n && _i18n.checkbox_min ? _i18n.checkbox_min.replace('%d', min) : 'Please select at least ' + min + ' option(s).');
    if (max > 0 && cnt > max) return (_i18n && _i18n.checkbox_max ? _i18n.checkbox_max.replace('%d', max) : 'Please select at most ' + max + ' option(s).');
    return null;
}
