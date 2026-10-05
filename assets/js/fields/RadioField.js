function (root) {
    /* Radio groups only: CheckboxField.js handles its own "Other" (fields don't depend on another field's script). */
    root.querySelectorAll('.fabricator-radio-group input[value="__other__"]').forEach(function (inp) {
        /* Idempotent: front.js re-runs field inits after every successful submit. */
        if (inp._fabricatorOtherInited) return;
        inp._fabricatorOtherInited = true;
        var wrap = inp.closest('.fabricator-radio-group');
        function sync() {
            var other = wrap && wrap.querySelector('.fabricator-other-input');
            if (!other) return;
            other.style.display = inp.checked ? '' : 'none';
        }
        /* The whole group, not only "Other": a radio fires change only when it becomes checked, so choosing another
           option after "Other" left the text input showing. */
        if (wrap) wrap.addEventListener('change', sync);
        /* Dispatched by front.js after form.reset(), which unchecks "Other" without firing change. */
        var ownerForm = inp.closest('form');
        if (ownerForm) ownerForm.addEventListener('fabricator:reset', sync);
    });
}
