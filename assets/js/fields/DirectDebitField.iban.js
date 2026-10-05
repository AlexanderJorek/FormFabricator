function (fieldEl) {
    var inp = fieldEl.querySelector('.fabricator-debit-iban');
    if (!inp) return null;
    var raw = inp.value.replace(/[^A-Za-z0-9]/g, '');
    if (!raw) return null;
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (inp._fabricatorIbanInvalid) return (_i18n && _i18n.debit_iban_invalid)    || 'Invalid IBAN (check digit incorrect).';
    if (!inp._fabricatorIbanValid)  return (_i18n && _i18n.debit_iban_incomplete) || 'Please enter a complete and valid IBAN.';
    return null;
}
