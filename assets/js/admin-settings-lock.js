/*!
 * FormFabricator — Settings page heartbeat lock notice
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function ($) {
    'use strict';
    var data = window.FabricatorSettingsLock;
    if (!data || !$ || !$.fn || !$(document).on) { return; }

    $(document).on('heartbeat-send', function (e, hbData) {
        hbData.fabricator_settings_lock = 1;
    });
    $(document).on('heartbeat-tick', function (e, hbData) {
        if (!hbData.fabricator_settings_lock_conflict) { return; }
        var notice = document.getElementById('fabricator-lock-notice');
        var text   = document.getElementById('fabricator-lock-notice-text');
        var msg    = (data.i18n.lockConflict || '').replace('%s', hbData.fabricator_settings_lock_conflict);
        if (text) { text.textContent = msg; }
        if (notice) { notice.style.display = ''; }
    });

    /* Release the advisory lock on unload rather than waiting for soft-expiry. */
    window.addEventListener('pagehide', function () {
        if (!navigator.sendBeacon) { return; }
        var body = new URLSearchParams({
            action: 'fabricator_forms_unlock_settings',
            nonce: data.nonce
        });
        navigator.sendBeacon(data.ajaxUrl, body);
    });
}(window.jQuery));
