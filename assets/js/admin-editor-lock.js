/*!
 * FormFabricator — Form editor heartbeat lock notice
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function ($) {
    'use strict';
    var data = window.FabricatorEditorLock;
    if (!data || !data.formId || !$ || !$.fn || !$(document).on) { return; }

    $(document).on('heartbeat-send', function (e, hbData) {
        hbData.fabricator_forms_lock = data.formId;
    });
    $(document).on('heartbeat-tick', function (e, hbData) {
        if (!hbData.fabricator_forms_lock_conflict) { return; }
        var msg = (data.i18n.lockConflict || '').replace('%s', hbData.fabricator_forms_lock_conflict);
        var notice = document.getElementById('fabricator-lock-notice');
        if (notice) {
            notice.textContent = msg;
            notice.style.display = '';
        } else {
            var status = document.getElementById('fabricator-save-status');
            if (status && status.parentNode) {
                var span = document.createElement('span');
                span.id = 'fabricator-lock-notice';
                span.className = 'fabricator-ss--err';
                span.textContent = msg;
                status.parentNode.insertBefore(span, status.nextSibling);
            }
        }
    });

    /* sendBeacon (not fetch/XHR) survives the page actually unloading. */
    window.addEventListener('pagehide', function () {
        if (!navigator.sendBeacon) { return; }
        var body = new URLSearchParams({
            action: 'fabricator_forms_unlock_form',
            nonce: data.nonce,
            form_id: String(data.formId)
        });
        navigator.sendBeacon(data.ajaxUrl, body);
    });
}(window.jQuery));
