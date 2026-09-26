function (fieldEl) {
    var inp = fieldEl.querySelector('input[type="number"]');
    if (!inp || inp.value.trim() === '') return null;
    var val = parseFloat(inp.value);
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (isNaN(val)) return (_i18n && _i18n.currency_invalid_amount) || 'Please enter a valid amount.';
    /* The amount is recorded with two decimals everywhere, so a third one would be rounded away. */
    if (Math.round(val * 100) / 100 !== val) {
        return (_i18n && _i18n.currency_decimals) || 'Please enter an amount with at most two decimal places.';
    }
    var min = inp.getAttribute('min');
    var max = inp.getAttribute('max');
    if (min !== null && min !== '' && val < parseFloat(min)) {
        return ((_i18n && _i18n.currency_min) || 'Minimum value: %s').replace('%s', min);
    }
    if (max !== null && max !== '' && val > parseFloat(max)) {
        return ((_i18n && _i18n.currency_max) || 'Maximum value: %s').replace('%s', max);
    }
    return null;
}
