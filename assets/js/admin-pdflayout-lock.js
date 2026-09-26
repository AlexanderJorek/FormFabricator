/*!
 * FormFabricator — PDF Layout page heartbeat lock notice
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function ($) {
    'use strict';
    var data = window.FabricatorPdfLayoutLock;
    if (!data || !$ || !$.fn || !$(document).on) { return; }

    $(document).on('heartbeat-send', function (e, hbData) {
        hbData.fabricator_pdf_layout_lock = 1;
    });
    $(document).on('heartbeat-tick', function (e, hbData) {
        if (!hbData.fabricator_pdf_layout_lock_conflict) { return; }
        var notice = document.getElementById('fabricator-lock-notice');
        var text   = document.getElementById('fabricator-lock-notice-text');
        /* Function replacement: a user name containing $& or $' would otherwise be expanded by String.replace. */
        var msg    = (data.i18n.lockConflict || '').replace('%s', function () { return hbData.fabricator_pdf_layout_lock_conflict; });
        if (text) { text.textContent = msg; }
        if (notice) { notice.style.display = ''; }
    });

    /* Release the advisory lock on unload rather than waiting for soft-expiry. */
    window.addEventListener('pagehide', function () {
        if (!navigator.sendBeacon) { return; }
        var body = new URLSearchParams({
            action: 'fabricator_forms_unlock_pdf_layout',
            nonce: data.nonce
        });
        navigator.sendBeacon(data.ajaxUrl, body);
    });
}(window.jQuery));
