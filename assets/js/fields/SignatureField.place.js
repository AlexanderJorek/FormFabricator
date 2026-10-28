function (fieldEl) {
    // As SignatureField::validate(): once switched on, the place of signing is needed with a signature; an unsigned
    // optional signature needs none. A required check, so it honours FabricatorIgnoreRequired as validatePage() does.
    var place = fieldEl.querySelector('.fabricator-signature-place');
    var err   = fieldEl.querySelector('.fabricator-signature-place-error');
    if (err) err.textContent = '';
    if (window.FabricatorIgnoreRequired) return null;
    var sig = fieldEl.querySelector('.fabricator-signature-wrap input[type="hidden"]');
    if (!place || !sig || sig.value === '') return null;
    // Empty as PHP's trim() leaves it.
    if (place.value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '') !== '') return null;
    if (err) err.textContent = place.getAttribute('data-required-message') || 'This field is required.';
    // The message is at the input; the field's own error slot stays empty, and the page still isn't sent.
    return true;
}
