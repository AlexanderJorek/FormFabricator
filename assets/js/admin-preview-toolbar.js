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

    /* Fake fetch so preview submissions don't fire real AJAX. */
    var _origFetch = window.fetch;
    window.fetch = function (url, opts) {
        var isSubmit = (url === '' || url === location.href)
            && opts && opts.body instanceof FormData
            && typeof opts.body.get === 'function'
            && opts.body.get('action') === 'fabricator_forms_submit';
        if (isSubmit) {
            return new Promise(function (resolve) {
                setTimeout(function () {
                    resolve(new Response(
                        JSON.stringify({ success: true, data: { message: '' } }),
                        { status: 200, headers: { 'Content-Type': 'application/json' } }
                    ));
                }, 700);
            });
        }
        return _origFetch.apply(this, arguments);
    };
}());
