function (fieldEl) {
    if (fieldEl.dataset.required !== 'true') return null;
    var missing = false;
    var iban = fieldEl.querySelector('.fabricator-sepa-iban');
    var ibanErr = iban ? iban.parentNode.querySelector('.fabricator-field-error') : null;
    if (iban && !iban.value.replace(/[^A-Za-z0-9]/g, '')) {
        var _i18nR = window.FabricatorForms && window.FabricatorForms.i18n;
        if (ibanErr && !ibanErr.textContent) ibanErr.textContent = (_i18nR && _i18nR.sepa_iban_required) || 'IBAN is required.';
        missing = true;
    }
    var bic = fieldEl.querySelector('.fabricator-sepa-bic');
    var bicErr = bic ? bic.parentNode.querySelector('.fabricator-field-error') : null;
    if (bic && !bic.value.trim()) {
        var _i18nBr = window.FabricatorForms && window.FabricatorForms.i18n;
        if (bicErr && !bicErr.textContent) bicErr.textContent = (_i18nBr && _i18nBr.sepa_bic_required) || 'BIC is required.';
        missing = true;
    }
    var holder = fieldEl.querySelector('.fabricator-sepa-holder');
    var holderErr = holder ? holder.parentNode.querySelector('.fabricator-field-error') : null;
    if (holder && !holder.value.trim()) {
        var _i18nH = window.FabricatorForms && window.FabricatorForms.i18n;
        if (holderErr && !holderErr.textContent)
            holderErr.textContent = (_i18nH && _i18nH.sepa_holder_required) || 'Account holder is required.';
        missing = true;
    }
    var sig = fieldEl.querySelector('.fabricator-sepa-sig-data');
    var sigErr = fieldEl.querySelector('.fabricator-sepa-sig-error');
    if (sig && !sig.value) {
        var _i18nSig = window.FabricatorForms && window.FabricatorForms.i18n;
        if (sigErr && !sigErr.textContent) sigErr.textContent = (_i18nSig && _i18nSig.sepa_sig_required) || 'Please sign.';
        missing = true;
    }
    return missing ? '​' : null;
}
