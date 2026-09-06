function (fieldEl) {
    var inp = fieldEl.querySelector('input[type="email"]');
    if (!inp || !inp.value.trim()) return null;
    var v = inp.value.trim().toLowerCase();
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))
        return (_i18n && _i18n.email_invalid) || 'Please enter a valid email address.';
    // Allow/block pattern matching is intentionally NOT replicated here — the admin-configured
    // pattern list can encode internal/partner/competitor domain names and must not be exposed
    // to the client (see EmailField::render()). validate() server-side is the sole, authoritative
    // enforcement of filter_mode/filter_patterns; a filtered address is only caught on submit.
    return null;
}
