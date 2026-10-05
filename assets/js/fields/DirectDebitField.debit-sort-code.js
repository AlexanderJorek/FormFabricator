function (fieldEl) {
    // Mirrors DirectDebitField::checkPart('sort_code'): six digits, spaces and hyphens ignored.
    var inp = fieldEl.querySelector('.fabricator-debit-sort-code');
    if (!inp || !inp.value.trim()) return null;
    if (/^\d{6}$/.test(inp.value.replace(/[\s-]/g, ''))) return null;
    var err = inp.parentNode.querySelector('.fabricator-field-error');
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (err && !err.textContent) err.textContent = (_i18n && _i18n.debit_sort_code_invalid) || 'Please enter a valid sort code (6 digits).';
    return '​';
}
