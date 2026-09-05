function (fieldEl) {
    var wrap = fieldEl.querySelector('.fabricator-slider-wrap');
    if (!wrap) return null;
    var min = parseFloat(wrap.dataset.min);
    var max = parseFloat(wrap.dataset.max);
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    var invalidMsg = (_i18n && _i18n.slider_invalid_value) || 'Please enter a valid value.';
    if (wrap.classList.contains('fabricator-slider-wrap--range')) {
        var fromInp = fieldEl.querySelector('.fabricator-slider-input-from');
        var toInp   = fieldEl.querySelector('.fabricator-slider-input-to');
        if (!fromInp || !toInp) return null;
        var from = parseFloat(fromInp.value);
        var to   = parseFloat(toInp.value);
        if (isNaN(from) || isNaN(to)) return invalidMsg;
        if (from < min || to > max) {
            return ((_i18n && _i18n.slider_out_of_range) || 'Value outside the allowed range (%1$s–%2$s).')
                .replace('%1$s', min).replace('%2$s', max);
        }
    } else {
        var inp = fieldEl.querySelector('input[type="hidden"]');
        if (!inp) return null;
        var val = parseFloat(inp.value);
        if (isNaN(val)) return invalidMsg;
        if (val < min) return ((_i18n && _i18n.slider_min) || 'Minimum value: %s').replace('%s', min);
        if (val > max) return ((_i18n && _i18n.slider_max) || 'Maximum value: %s').replace('%s', max);
    }
    return null;
}
