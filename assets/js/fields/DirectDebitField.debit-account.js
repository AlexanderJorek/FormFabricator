function (fieldEl) {
    // Mirrors DirectDebitField::checkPart('account'): Bacs 8 digits, ACH 4 to 17, spaces and hyphens ignored.
    var inp = fieldEl.querySelector('.fabricator-debit-account');
    if (!inp || !inp.value.trim()) return null;
    var bacs = fieldEl.dataset.scheme === 'bacs';
    if ((bacs ? /^\d{8}$/ : /^\d{4,17}$/).test(inp.value.replace(/[\s-]/g, ''))) return null;
    var err = inp.parentNode.querySelector('.fabricator-field-error');
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    var msg = bacs
        ? ((_i18n && _i18n.debit_bacs_account_invalid) || 'Please enter a valid account number (8 digits).')
        : ((_i18n && _i18n.debit_ach_account_invalid) || 'Please enter a valid account number (4 to 17 digits).');
    if (err && !err.textContent) err.textContent = msg;
    return '​';
}
