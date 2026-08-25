/*!
 * FormFabricator — Field test harness tab switching (dev-only, WP_DEBUG-gated)
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';
    document.querySelectorAll('.fabricator-test-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var panel = document.getElementById(tab.dataset.panel);
            if (!panel) { return; }
            document.querySelectorAll('.fabricator-test-tab').forEach(function (t) { t.classList.remove('active'); });
            document.querySelectorAll('.fabricator-test-panel').forEach(function (p) { p.classList.remove('active'); });
            tab.classList.add('active');
            panel.classList.add('active');
        });
    });
}());
