function (fieldEl) {
    // Mirrors DirectDebitField::routingNumberValid(): nine digits, a Federal Reserve prefix and the 3-7-1 check digit.
    var inp = fieldEl.querySelector('.fabricator-debit-routing');
    if (!inp || !inp.value.trim()) return null;
    var r = inp.value.replace(/[\s-]/g, '');
    var ok = /^\d{9}$/.test(r) && r !== '000000000';
    if (ok) {
        var p = parseInt(r.substring(0, 2), 10);
        ok = p <= 12 || (p >= 21 && p <= 32) || (p >= 61 && p <= 72) || p === 80;
    }
    if (ok) {
        var d = r.split('').map(Number);
        ok = (3 * (d[0] + d[3] + d[6]) + 7 * (d[1] + d[4] + d[7]) + d[2] + d[5] + d[8]) % 10 === 0;
    }
    if (ok) return null;
    var err = inp.parentNode.querySelector('.fabricator-field-error');
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (err && !err.textContent) err.textContent = (_i18n && _i18n.debit_routing_invalid) || 'Please enter a valid routing number.';
    return '​';
}
