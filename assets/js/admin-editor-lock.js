/*!
 * FormFabricator — Form editor heartbeat lock notice
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function ($) {
    'use strict';
    var data = window.ForgeEditorLock;
    if (!data || !data.formId || !$ || !$.fn || !$(document).on) { return; }

    $(document).on('heartbeat-send', function (e, hbData) {
        hbData.forge_forms_lock = data.formId;
    });
    $(document).on('heartbeat-tick', function (e, hbData) {
        if (!hbData.forge_forms_lock_conflict) { return; }
        var msg = (data.i18n.lockConflict || '').replace('%s', hbData.forge_forms_lock_conflict);
        var notice = document.getElementById('forge-lock-notice');
        if (notice) {
            notice.textContent = msg;
            notice.style.display = '';
        } else {
            var status = document.getElementById('forge-save-status');
            if (status && status.parentNode) {
                var span = document.createElement('span');
                span.id = 'forge-lock-notice';
                span.className = 'forge-ss--err';
                span.textContent = msg;
                status.parentNode.insertBefore(span, status.nextSibling);
            }
        }
    });

    /* sendBeacon (not fetch/XHR) survives the page actually unloading. */
    window.addEventListener('pagehide', function () {
        if (!navigator.sendBeacon) { return; }
        var body = new URLSearchParams({
            action: 'forge_forms_unlock_form',
            nonce: data.nonce,
            form_id: String(data.formId)
        });
        navigator.sendBeacon(data.ajaxUrl, body);
    });
}(window.jQuery));
