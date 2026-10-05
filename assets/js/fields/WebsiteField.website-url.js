function (fieldEl) {
    var inp = fieldEl.querySelector('input[type="url"]');
    if (!inp || !inp.value.trim() || inp.dataset.validateUrl !== '1') return null;
    // Invisible characters (bidi overrides, zero-width, non-breaking spaces) are refused, as WebsiteField::validate() does
    // on the value trimmed as PHP trims: String.prototype.trim() would also drop a non-breaking space the server refuses.
    var asServer = inp.value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
    if (!/[\p{Cc}\p{Cf}\p{Z}]/u.test(asServer) && /^https?:\/\/.+\..+/.test(inp.value.trim())) return null;
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    return (_i18n && _i18n.website_invalid_url) || 'Please enter a valid URL (e.g. https://example.com).';
}
