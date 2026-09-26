/*!
 * FormFabricator — Form preview toolbar (standalone preview document only)
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';

    /* Seed from the checkbox's current state, not assumed unchecked — browsers restore form state on reload/back. */
    var cb = document.getElementById('fpt-skip-required');
    if (cb) {
        window.FabricatorIgnoreRequired = cb.checked;
        cb.addEventListener('change', function () {
            window.FabricatorIgnoreRequired = this.checked;
        });
    }

    /* Fake fetch so preview submissions don't fire real AJAX — must intercept both the token request and the submit. */
    function bodyAction(body) {
        if (!body || typeof body.get !== 'function') { return null; }
        if (!(body instanceof FormData) && !(body instanceof URLSearchParams)) { return null; }
        return body.get('action');
    }

    function fakeJson(payload, delayMs) {
        return new Promise(function (resolve) {
            setTimeout(function () {
                resolve(new Response(
                    JSON.stringify(payload),
                    { status: 200, headers: { 'Content-Type': 'application/json' } }
                ));
            }, delayMs);
        });
    }

    var _origFetch = window.fetch;
    window.fetch = function (url, opts) {
        var sameDoc = (url === '' || url === location.href);
        var action  = sameDoc && opts ? bodyAction(opts.body) : null;

        if (action === 'fabricator_forms_get_token') {
            /* Values are never validated in the preview — nothing reaches the server. */
            return fakeJson({ success: true, data: { nonce: 'preview', token: 'preview' } }, 0);
        }
        if (action === 'fabricator_forms_submit') {
            return fakeJson({ success: true, data: { message: '' } }, 700);
        }
        return _origFetch.apply(this, arguments);
    };
}());
