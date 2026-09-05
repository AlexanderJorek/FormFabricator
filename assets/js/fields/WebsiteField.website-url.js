function (fieldEl) {
    var inp = fieldEl.querySelector('input[type="url"]');
    if (!inp || !inp.value.trim() || inp.dataset.validateUrl !== '1') return null;
    if (/^https?:\/\/.+\..+/.test(inp.value.trim())) return null;
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    return (_i18n && _i18n.website_invalid_url) || 'Please enter a valid URL (e.g. https://example.com).';
}
