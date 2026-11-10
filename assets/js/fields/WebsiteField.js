window.FabricatorFieldInits = window.FabricatorFieldInits || {};
window.FabricatorFieldInits['website'] = function (root) {
    /* An address copied from the browser's address bar spells its non-ASCII characters as percent codes
       ("M%C3%BCnchen"), which an answer can't hold (front.js answerProblem()). They are written back as the characters
       they stand for ("München"): the same address, readable, and seen by the visitor in the field. Codes for ASCII
       characters ("%2F", "%3F", "%20") stay: written out, they would change what the address means, so the visitor
       is asked to fix them. A run that is no UTF-8 stays as it is. */
    function readable(value) {
        return value.replace(/(?:%[89a-f][0-9a-f])+/gi, function (run) {
            try {
                return decodeURIComponent(run);
            } catch (e) {
                return run;
            }
        });
    }

    root.querySelectorAll('.fabricator-field--website input[type="url"]').forEach(function (inp) {
        /* Idempotent: front.js re-runs field inits after every successful submit. */
        if (inp._fabricatorReadableUrl) return;
        inp._fabricatorReadableUrl = true;
        function update() {
            var next = readable(inp.value);
            if (next === inp.value) return;
            inp.value = next;
            /* Conditions that read this field see the new value. */
            inp.dispatchEvent(new Event('input', { bubbles: true }));
        }
        inp.addEventListener('paste', function () { setTimeout(update, 0); });
        inp.addEventListener('change', update);
        /* Enter submits without leaving the field: capture runs before front.js checks the form. */
        var ownerForm = inp.closest('form');
        if (ownerForm) ownerForm.addEventListener('submit', update, true);
    });
};
