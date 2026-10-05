function (fieldEl) {
    var group = fieldEl.querySelector('.fabricator-checkbox-group');
    if (!group) return null;
    var min = parseInt(group.dataset.minSelections || '0', 10);
    var max = parseInt(group.dataset.maxSelections || '0', 10);
    if (!min && !max) return null;
    var cnt = fieldEl.querySelectorAll('input[type="checkbox"]:checked').length;
    /* CheckboxField::render() writes each message with its plural form already chosen for the configured count. */
    if (min > 0 && cnt < min) return group.dataset.minMessage || ('Please select at least ' + min + ' options.');
    if (max > 0 && cnt > max) return group.dataset.maxMessage || ('Please select at most ' + max + ' options.');
    return null;
}
