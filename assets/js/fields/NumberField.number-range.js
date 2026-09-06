function (fieldEl) {
    var inp = fieldEl.querySelector('input[type="number"]');
    if (!inp || inp.value.trim() === '') return null;
    var val = parseFloat(inp.value);
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (isNaN(val)) return (_i18n && _i18n.number_invalid) || 'Please enter a valid number.';
    var min = inp.getAttribute('min');
    var max = inp.getAttribute('max');
    if (min !== null && min !== '' && val < parseFloat(min))
        return ((_i18n && _i18n.number_min) || 'Minimum value: %s').replace('%s', min);
    if (max !== null && max !== '' && val > parseFloat(max))
        return ((_i18n && _i18n.number_max) || 'Maximum value: %s').replace('%s', max);
    var rule = inp.dataset.validation || '';
    var isInt = val === Math.floor(val);
    if (rule === 'integer' && !isInt) {
        return (_i18n && _i18n.number_not_integer) || 'Please enter a whole number.';
    }
    if (rule === 'positive' && val <= 0) {
        return (_i18n && _i18n.number_not_positive) || 'Please enter a positive number.';
    }
    if (rule === 'positive_int' && (!isInt || val <= 0)) {
        return (_i18n && _i18n.number_not_positive_int) || 'Please enter a positive whole number.';
    }
    return null;
}
