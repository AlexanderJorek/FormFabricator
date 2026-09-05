function (fieldEl) {
    var inp = fieldEl.querySelector('input[type="email"]');
    if (!inp || !inp.value.trim()) return null;
    var v = inp.value.trim().toLowerCase();
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))
        return (_i18n && _i18n.email_invalid) || 'Please enter a valid email address.';
    var mode = inp.dataset.filterMode || '';
    if (!mode) return null;
    var list = JSON.parse(inp.dataset.filterPatterns || '[]');
    var matched = list.some(function (pat) {
        var re = new RegExp(
            '^' + pat.toLowerCase()
                .replace(/[.+?^${}()|[\]\\]/g, '\\$&')
                .replace(/\*/g, '.*') + '$'
        );
        return re.test(v);
    });
    var blockedMsg = (_i18n && _i18n.email_not_allowed) || 'This email address is not allowed.';
    if (mode === 'allow' && !matched) return blockedMsg;
    if (mode === 'block' &&  matched) return blockedMsg;
    return null;
}
