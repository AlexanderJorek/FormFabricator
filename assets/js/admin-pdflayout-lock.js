/*!
 * FormFabricator — PDF Layout page heartbeat lock notice
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function ($) {
    'use strict';
    var data = window.ForgePdfLayoutLock;
    if (!data || !$ || !$.fn || !$(document).on) { return; }

    $(document).on('heartbeat-send', function (e, hbData) {
        hbData.forge_pdf_layout_lock = 1;
    });
    $(document).on('heartbeat-tick', function (e, hbData) {
        if (!hbData.forge_pdf_layout_lock_conflict) { return; }
        var notice = document.getElementById('forge-lock-notice');
        var text   = document.getElementById('forge-lock-notice-text');
        var msg    = (data.i18n.lockConflict || '').replace('%s', hbData.forge_pdf_layout_lock_conflict);
        if (text) { text.textContent = msg; }
        if (notice) { notice.style.display = ''; }
    });

    /* Release the advisory lock on unload rather than waiting for soft-expiry. */
    window.addEventListener('pagehide', function () {
        if (!navigator.sendBeacon) { return; }
        var body = new URLSearchParams({
            action: 'forge_forms_unlock_pdf_layout',
            nonce: data.nonce
        });
        navigator.sendBeacon(data.ajaxUrl, body);
    });
}(window.jQuery));
