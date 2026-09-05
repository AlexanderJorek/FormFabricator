function (fieldEl) {
    var bic = fieldEl.querySelector('.fabricator-sepa-bic');
    if (!bic || !bic.value.trim()) return null;
    var bicErr = bic.parentNode.querySelector('.fabricator-field-error');
    if (/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/.test(bic.value.trim())) return null;
    var _i18nB = window.FabricatorForms && window.FabricatorForms.i18n;
    if (bicErr && !bicErr.textContent) bicErr.textContent = (_i18nB && _i18nB.sepa_bic_invalid) || 'Please enter a valid BIC.';
    return '​';
}
