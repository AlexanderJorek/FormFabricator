function (fieldEl) {
    var inp = fieldEl.querySelector('input[type="number"]');
    if (!inp || inp.value.trim() === '') return null;
    var val = parseFloat(inp.value);
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    /* isFinite, not isNaN: "1e999" parses to Infinity, which NumberField::validate() rejects. */
    if (!isFinite(val)) return (_i18n && _i18n.number_invalid) || 'Please enter a valid number.';
    var min = inp.getAttribute('min');
    var max = inp.getAttribute('max');
    if (min !== null && min !== '' && val < parseFloat(min))
        return ((_i18n && _i18n.number_min) || 'Minimum value: %s').replace('%s', min);
    if (max !== null && max !== '' && val > parseFloat(max))
        return ((_i18n && _i18n.number_max) || 'Maximum value: %s').replace('%s', max);
    /* Mirrors NumberField::stepError(): on the grid min + k·step (or 0 + k·step), within a relative tolerance. */
    var step = parseFloat(inp.getAttribute('step'));
    if (step > 0) {
        var base = (min !== null && min !== '') ? parseFloat(min) : 0;
        var k = (val - base) / step;
        if (Math.abs(k - Math.round(k)) > 1e-9 * Math.max(1, Math.abs(k)))
            return ((_i18n && _i18n.number_step) || 'Please enter a value in steps of %s.').replace('%s', inp.getAttribute('step'));
    }
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
