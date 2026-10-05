function (fieldEl) {
    // Required check disguised as a format rule (so per-sub-input error placement works); must honour FabricatorIgnoreRequired manually since it bypasses validatePage()'s required blocks.
    if (window.FabricatorIgnoreRequired) return null;
    var inputs = fieldEl.querySelectorAll('[data-debit-part]');
    // Empty as DirectDebitField::validate() sees it: nothing left after PHP's trim(). A lone hyphen is something typed,
    // which the server then checks (and refuses) as a sort code or account number.
    function blank(value) { return value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '') === ''; }
    var sig = fieldEl.querySelector('.fabricator-debit-sig-data');
    // All or nothing, as DirectDebitField::validate(): an optional mandate left untouched passes; once anything is
    // entered or signed, every detail and the signature are needed.
    var touched = (sig && sig.value !== '') || Array.prototype.some.call(inputs, function (inp) {
        return !blank(inp.value);
    });
    if (fieldEl.dataset.required !== 'true' && !touched) return null;
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    var fallback = {
        iban: 'IBAN is required.', holder: 'Account holder is required.',
        sort_code: 'Sort code is required.', routing: 'Routing number is required.',
        account: 'Account number is required.', account_type: 'Please choose the account type.'
    };
    var missing = false;
    // The BIC is needed only for an IBAN from a SEPA country outside the EEA (DirectDebitField::SEPA_NON_EEA).
    var nonEea  = (window.FabricatorForms && window.FabricatorForms.sepaNonEea) || [];
    var ibanIn  = fieldEl.querySelector('[data-debit-part="iban"]');
    var ibanCc  = ibanIn ? ibanIn.value.replace(/\s/g, '').slice(0, 2).toUpperCase() : '';
    // Only the chosen scheme's inputs and the debtor details switched on are on the page, each marked with what it
    // holds. A debtor detail carries its own message, named by the label the admin set, as the server words it.
    Array.prototype.forEach.call(inputs, function (inp) {
        if (!blank(inp.value)) return;
        var part = inp.getAttribute('data-debit-part');
        var err  = inp.parentNode.querySelector('.fabricator-field-error');
        if (part === 'bic') {
            if (nonEea.indexOf(ibanCc) === -1) return;
            if (err && !err.textContent) {
                err.textContent = ((_i18n && _i18n.debit_bic_needed) || 'The BIC is needed for IBANs from %s.').replace('%s', ibanCc);
            }
            missing = true;
            return;
        }
        if (err && !err.textContent) {
            err.textContent = inp.getAttribute('data-required-message')
                || (_i18n && _i18n['debit_' + part + '_required']) || fallback[part] || 'This field is required.';
        }
        missing = true;
    });
    var sigErr = fieldEl.querySelector('.fabricator-debit-sig-error');
    if (sig && !sig.value) {
        if (sigErr && !sigErr.textContent) sigErr.textContent = (_i18n && _i18n.debit_sig_required) || 'Please sign.';
        missing = true;
    }
    return missing ? '​' : null;
}
