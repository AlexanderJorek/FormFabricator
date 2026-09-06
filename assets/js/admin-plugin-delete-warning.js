/*!
 * FormFabricator — Plugins-screen delete confirmation
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 *
 * Replaces an inline onclick="" that was injected into WordPress core's own delete link by
 * regex-rewriting its markup. That broke silently whenever core changed that markup, and inline
 * event handlers are flagged by Plugin Check. Here the PHP side only wraps the link in a span
 * carrying the message, and this listener supplies the behaviour.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var wrap = event.target.closest
            ? event.target.closest('.fabricator-delete-warning')
            : null;
        if (!wrap) {
            return;
        }
        var link = event.target.closest('a');
        if (!link || !wrap.contains(link)) {
            return;
        }
        var message = wrap.getAttribute('data-fabricator-warning') || '';
        if (message && !window.confirm(message)) {
            event.preventDefault();
            event.stopPropagation();
        }
    });
}());
