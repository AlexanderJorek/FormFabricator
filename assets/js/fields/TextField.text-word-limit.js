function (fieldEl) {
    var inp = fieldEl.querySelector('[data-word-limit]');
    if (!inp || !inp.value.trim()) return null;
    var limit = parseInt(inp.dataset.wordLimit, 10);
    if (!limit) return null;
    var count = inp.value.trim().split(/\s+/).filter(Boolean).length;
    if (count <= limit) return null;
    var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
    return ((_i18n && _i18n.word_limit_exceeded) || 'Please enter at most %1$d words (currently: %2$d).')
        .replace('%1$d', limit).replace('%2$d', count);
}
