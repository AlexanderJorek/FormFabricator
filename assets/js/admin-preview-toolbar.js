/*!
 * FormFabricator — Form preview toolbar (standalone preview document only)
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';

    /* Skip-required toggle. */
    var cb = document.getElementById('fpt-skip-required');
    if (cb) {
        cb.addEventListener('change', function () {
            window.FabricatorIgnoreRequired = this.checked;
        });
    }

    /* Fake fetch so preview submissions don't fire real AJAX.
       Both of front.js's calls have to be intercepted: it first requests a nonce/replay token
       with a URLSearchParams body, then submits with a FormData body. Intercepting only the
       latter left the token request to hit the network for real — which, on the blob: document
       the preview opens in, can never succeed — so every preview submit ended in front.js's
       catch with "Server error" and the simulated success path was unreachable. */
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
