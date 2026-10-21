/*!
 * FormFabricator — heartbeat lock notice for the Settings and PDF Layout pages
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function ($) {
    'use strict';
    var data = window.FabricatorAdminLock;
    if (!data || !data.screen || !$ || !$.fn || !$(document).on) { return; }
    /* The server's names for this screen's lock: AdminLock::heartbeatReceived() and AdminLock::ajaxUnlock(). */
    var lockKey     = 'fabricator_' + data.screen + '_lock';
    var conflictKey = lockKey + '_conflict';

    $(document).on('heartbeat-send', function (e, hbData) {
        hbData[lockKey] = 1;
    });
    $(document).on('heartbeat-tick', function (e, hbData) {
        var notice = document.getElementById('fabricator-lock-notice');
        /* No conflict: the other administrator has left, and the server gave this page the lock. */
        if (!hbData[conflictKey]) {
            if (notice) { notice.style.display = 'none'; }
            return;
        }
        var text   = document.getElementById('fabricator-lock-notice-text');
        /* Function replacement: a user name containing $& or $' would otherwise be expanded by String.replace. */
        var msg    = (data.i18n.lockConflict || '').replace('%s', function () { return hbData[conflictKey]; });
        if (text) { text.textContent = msg; }
        if (notice) { notice.style.display = ''; }
    });

    /* Release the advisory lock on unload rather than waiting for soft-expiry. */
    window.addEventListener('pagehide', function () {
        if (!navigator.sendBeacon) { return; }
        var body = new URLSearchParams({
            action: 'fabricator_forms_unlock_' + data.screen,
            nonce: data.nonce
        });
        navigator.sendBeacon(data.ajaxUrl, body);
    });
}(window.jQuery));
