function (root) {
    root.querySelectorAll('input[value="__other__"]').forEach(function (inp) {
        /* Idempotent: front.js re-runs field inits after every successful submit. */
        if (inp._fabricatorOtherInited) return;
        inp._fabricatorOtherInited = true;
        function sync() {
            var wrap  = inp.closest('.fabricator-radio-group, .fabricator-checkbox-group');
            var other = wrap && wrap.querySelector('.fabricator-other-input');
            if (!other) return;
            other.style.display = inp.checked ? '' : 'none';
        }
        inp.addEventListener('change', sync);
        /* Dispatched by front.js after form.reset(), which unchecks "Other" without firing change. */
        var ownerForm = inp.closest('form');
        if (ownerForm) ownerForm.addEventListener('fabricator:reset', sync);
    });
}
