/*!
 * FormFabricator — Settings admin page
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function ($) {
    'use strict';

    /* Text as a TEXT NODE, not innerHTML — a .mo (loaded from WP_LANG_DIR, not just reviewed packs) could inject markup. */
    function fabIconThenText(el, iconHtml, text) {
        if (!el) { return; }
        el.innerHTML = iconHtml;
        el.appendChild(document.createTextNode(String(text == null ? '' : text)));
    }

    var pageData = window.FabricatorSettingsPage || {};
    var I18N   = pageData.i18n   || {};
    var NONCES = pageData.nonces || {};
    var DATA   = pageData.data   || {};

        jQuery(function ($) {
function fabricatorLuminance(hex) {
    var r = parseInt(hex.slice(1, 3), 16);
    var g = parseInt(hex.slice(3, 5), 16);
    var b = parseInt(hex.slice(5, 7), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
}
try {
    $('.fabricator-iris-input').wpColorPicker({
        change: function (event, ui) {
            var hex = ui.color.toString();
            var id  = this.id;
            if (id === 'admin_accent') {
                var lum = fabricatorLuminance(hex);
                document.documentElement.style.setProperty('--fabricator-admin-accent', hex);
                document.documentElement.style.setProperty('--fabricator-accent-text', lum > 0.55 ? '#1d2327' : '#ffffff');
                document.documentElement.style.setProperty('--fabricator-admin-accent-fg', lum > 0.55 ? '#1d2327' : hex);
            } else if (id === 'hover_color') {
                document.documentElement.style.setProperty('--fabricator-hover-color', hex);
                document.documentElement.style.setProperty('--fabricator-hover-color-fg', fabricatorLuminance(hex) > 0.55 ? '#1d2327' : hex);
            }
        },
    });
} catch (e) {
    console.error('[FormFabricator] wpColorPicker init failed', e);
}

            // Cards stay in DOM (hidden) for non-admins so JS bindings don't null-crash; real boundary is server-side manage_options checks.
            if (!DATA.isFullAdmin) {
                var adminOnlySel = '.fabricator-settings-card--security, ' +
                    '#fabricator-access-tile-btn, #fabricator-reset-tile-btn';
                /* Modal overlays for the hidden tiles — toggled via the same
                   bare hidden attribute, but not nested inside a card. */
                var adminOnlyOverlayIds = [
                    'fabricator-key-overlay', 'fabricator-key-view-overlay',
                    'fabricator-reset-overlay', 'fabricator-key-dl-overlay',
                    'fabricator-master-key-overlay', 'fabricator-legacy-key-overlay',
                    'fabricator-access-overlay'
                ];
                function rehideAdminCards() {
                    document.querySelectorAll(adminOnlySel).forEach(function (el) {
                        var card = el.closest('.fabricator-settings-card') || el;
                        if (!card.hidden) card.hidden = true;
                    });
                    adminOnlyOverlayIds.forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el && !el.hidden) el.hidden = true;
                    });
                }
                rehideAdminCards();
                new MutationObserver(rehideAdminCards).observe(document.body, {
                    attributes: true,
                    attributeFilter: ['hidden'],
                    subtree: true
                });
            }

            /* ── Factory-reset modal ── */
            var overlay   = document.getElementById('fabricator-reset-overlay');
            var chk       = document.getElementById('fabricator-reset-delete-forms');
            var confirmBtn = document.getElementById('fabricator-reset-confirm');
            var countdownTimer = null;

            var confirmed = false;

            document.getElementById('fabricator-reset-tile-btn').addEventListener('click', function () {
                chk.checked = false;
                confirmed = false;
                resetConfirmBtn();
                overlay.hidden = false;
                document.getElementById('fabricator-reset-cancel').focus();
            });

            document.getElementById('fabricator-reset-cancel').addEventListener('click', closeModal);

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeModal();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !overlay.hidden) closeModal();
            });

            confirmBtn.addEventListener('click', function () {
                if (confirmBtn.disabled) return;

                if (!confirmed) {
                    confirmed = true;
                    startCountdown();
                    return;
                }

                confirmBtn.disabled = true;
                fabIconThenText(confirmBtn, '<i class="fa-solid fa-spinner fa-spin"></i> ', I18N.resetting);

                $.post(ajaxurl, {
                    action:    'fabricator_forms_factory_reset',
                    nonce:     NONCES.factoryReset,
                    del_forms: chk.checked ? '1' : '0'
                }, function (res) {
                    if (res.success) {
                        overlay.hidden = true;
                        window.location.reload();
                    } else {
                        confirmed = false;
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = I18N.errorTryAgain;
                    }
                }).fail(function () {
                    confirmed = false;
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = I18N.errorTryAgain;
                });
            });

            function startCountdown() {
                var sec = 10;
                
                var confirmLabel = I18N.areYouSureCountdown;
                confirmBtn.disabled = true;
                confirmBtn.textContent = confirmLabel.replace('%d', sec);
                countdownTimer = setInterval(function () {
                    sec--;
                    if (sec <= 0) {
                        clearInterval(countdownTimer);
                        confirmBtn.disabled = false;
                        fabIconThenText(confirmBtn, '<i class="fa-solid fa-check"></i> ', I18N.yesReset);
                    } else {
                        confirmBtn.textContent = confirmLabel.replace('%d', sec);
                    }
                }, 1000);
            }

            function resetConfirmBtn() {
                clearInterval(countdownTimer);
                confirmed = false;
                confirmBtn.disabled = false;
                confirmBtn.textContent = I18N.reset;
            }

            function closeModal() {
                clearInterval(countdownTimer);
                overlay.hidden = true;
            }
        });

        /* ── Privacy policy text: language switch (every language pre-rendered) and copy-to-clipboard ── */
        (function () {
            var overlay  = document.getElementById('fabricator-privacy-text-overlay');
            var trigger  = document.getElementById('fabricator-privacy-text-trigger');
            var closeBtn = document.getElementById('fabricator-privacy-text-close');
            var box      = document.getElementById('fabricator-privacy-text-box');
            var input    = document.getElementById('fabricator-privacy-text-lang-input');
            var list     = document.getElementById('fabricator-privacy-lang-list');
            /* Trigger/overlay markup is only rendered server-side for full admins;
               bail out for everyone else instead of throwing on the null ref. */
            if (!overlay || !trigger || !closeBtn || !box || !input || !list) { return; }

            function openModal()  { overlay.hidden = false; }
            function closeModal() { overlay.hidden = true; closeCombobox(); }

            trigger.addEventListener('click', openModal);
            closeBtn.addEventListener('click', closeModal);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) { closeModal(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !overlay.hidden) { closeModal(); }
            });

            var texts = {};
            try { texts = JSON.parse(box.dataset.privacyTexts || '{}'); } catch (e) { texts = {}; }

            /* ── Searchable language combobox ── */
            var options = Array.prototype.slice.call(list.querySelectorAll('.fabricator-combobox-option'));

            function selectLanguage(opt) {
                input.value = opt.textContent.trim();
                input.dataset.value = opt.dataset.value;
                options.forEach(function (o) { o.classList.toggle('is-selected', o === opt); });
                box.value = texts[opt.dataset.value] || '';
                closeCombobox();
            }

            function openCombobox() {
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            }
            function closeCombobox() {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                // Snap back to the currently selected language's label — filtering
                // may have left a partial search string in the input.
                var selected = options.filter(function (o) { return o.dataset.value === input.dataset.value; })[0];
                if (selected) { input.value = selected.textContent.trim(); }
                options.forEach(function (o) { o.hidden = false; });
            }
            function filterOptions() {
                var q = input.value.trim().toLowerCase();
                options.forEach(function (o) {
                    o.hidden = q !== '' && o.textContent.trim().toLowerCase().indexOf(q) === -1;
                });
            }

            input.addEventListener('focus', openCombobox);
            input.addEventListener('click', openCombobox);
            input.addEventListener('input', function () {
                openCombobox();
                filterOptions();
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeCombobox();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var visible = options.filter(function (o) { return !o.hidden; });
                    if (visible.length) { selectLanguage(visible[0]); }
                }
            });
            options.forEach(function (opt) {
                opt.addEventListener('mousedown', function (e) {
                    // mousedown (not click) fires before the input's blur, so the
                    // option is still in the DOM/visible when this runs.
                    e.preventDefault();
                    selectLanguage(opt);
                });
            });
            document.addEventListener('click', function (e) {
                if (!list.hidden && !e.target.closest('#fabricator-privacy-lang-combobox')) {
                    closeCombobox();
                }
            });

            var copyBtn  = document.getElementById('fabricator-privacy-text-copy');
            var copiedEl = document.getElementById('fabricator-privacy-text-copied');
            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    var done = function () {
                        if (!copiedEl) { return; }
                        copiedEl.style.display = '';
                        setTimeout(function () { copiedEl.style.display = 'none'; }, 2000);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(box.value).then(done);
                    } else {
                        box.focus();
                        box.select();
                        document.execCommand('copy');
                        done();
                    }
                });
            }
        }());

        /* ---- AJAX save for #fabricator-settings-form (no page reload) ---- */
        (function(){
            var form = document.getElementById('fabricator-settings-form');
            if (!form) return;
            function showNotice(msg, isError) {
                var existing = document.querySelector('.fabricator-settings-notice');
                if (existing) existing.remove();
                var n = document.createElement('div');
                n.className = 'fabricator-settings-notice fabricator-settings-notice--'
                    + (isError ? 'error' : 'success');
                /* msg is never markup (see callers) — same .mo-injection reasoning as the header note above. */
                fabIconThenText(
                    n,
                    '<i class="fa-solid fa-' + (isError ? 'circle-xmark' : 'circle-check') + '"></i> ',
                    msg
                );
                form.parentNode.insertBefore(n, form);
                n.scrollIntoView({behavior:'smooth', block:'nearest'});
                if (!isError) {
                    setTimeout(function() {
                        n.style.transition = 'opacity .4s';
                        n.style.opacity = '0';
                        setTimeout(function() { n.remove(); }, 420);
                    }, 3000);
                }
            }
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = form.querySelector('button[type="submit"]');
                var origHtml = btn ? btn.innerHTML : '';
                if (btn) { btn.disabled = true; fabIconThenText(btn, '<span class="fabricator-spinner"></span> ', I18N.saving); }
                var fd = new FormData(form);
                fd.set('action', 'fabricator_save_general_settings');
                requestAnimationFrame(function(){ requestAnimationFrame(function(){
                fetch(ajaxurl, {method:'POST', body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                        if (data.success) {
                            /* The saved state is the new baseline: without this every further save on the same page
                               was refused as "changed elsewhere". */
                            var snap = form.querySelector('input[name="fabricator_settings_snapshot"]');
                            if (snap && data.data && data.data.snapshot) { snap.value = data.data.snapshot; }
                            showNotice(data.data.message, false);
                        } else {
                            showNotice((data.data && data.data.message) || I18N.errorSaving, true);
                        }
                    })
                    .catch(function(){
                        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                        showNotice(I18N.networkError, true);
                    });
                }); }); // requestAnimationFrame double-frame
            });
        }());
}(window.jQuery));
