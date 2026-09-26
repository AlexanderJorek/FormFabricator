function (fieldEl) {
    var inp = fieldEl.querySelector('input[type="email"]');
    if (!inp || !inp.value.trim()) return null;
    var v = inp.value.trim().toLowerCase();
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))
        return (_i18n && _i18n.email_invalid) || 'Please enter a valid email address.';
    // Allow/block patterns aren't replicated client-side — they can encode internal domain names; server validate() is authoritative.
    return null;
}
