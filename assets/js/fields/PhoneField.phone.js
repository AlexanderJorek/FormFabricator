function (fieldEl) {
    var inp = fieldEl.querySelector('input[type="tel"]');
    if (!inp || !inp.value.trim()) return null;
    var mode = inp.dataset.phoneMode || '';
    if (!mode) return null;
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    var v = inp.value.replace(/[\s\-\(\)\/]/g, '');
    if (mode === 'any') {
        return /^\+?[0-9]{7,15}$/.test(v)
            ? null : ((_i18n && _i18n.phone_invalid) || 'Please enter a valid phone number.');
    }
    if (mode === 'countries') {
        if (v.charAt(0) !== '+')
            return (_i18n && _i18n.phone_intl_required) || 'Please enter the number with international prefix (+...).';
        var digits = v.slice(1);
        var cmode  = inp.dataset.phoneCountryMode || 'allow';
        var list   = JSON.parse(inp.dataset.phoneCountryList || '[]')
                        .map(function (c) { return c.replace('+', ''); });
        list.sort(function (a, b) { return b.length - a.length; });
        var inList = list.some(function (code) { return digits.indexOf(code) === 0; });
        var blocked = (_i18n && _i18n.phone_country_blocked) || 'This phone number is not allowed for your country.';
        if (cmode === 'allow'    && !inList) return blocked;
        if (cmode === 'disallow' &&  inList) return blocked;
    }
    return null;
}
