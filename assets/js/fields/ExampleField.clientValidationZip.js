function (fieldEl) {
    var inp = fieldEl.querySelector('input');
    if (!inp || !inp.value.trim()) return null;
    if (/^\d{5}$/.test(inp.value.trim())) return null;
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    return (_i18n && _i18n.example_zip_invalid) || 'Please enter a five-digit number.';
}
