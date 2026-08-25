/*!
 * FormFabricator — Settings admin page
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function ($) {
    'use strict';
    var pageData = window.ForgeSettingsPage || {};
    var I18N   = pageData.i18n   || {};
    var NONCES = pageData.nonces || {};
    var DATA   = pageData.data   || {};

        jQuery(function ($) {
function forgeLuminance(hex) {
    var r = parseInt(hex.slice(1, 3), 16);
    var g = parseInt(hex.slice(3, 5), 16);
    var b = parseInt(hex.slice(5, 7), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
}
try {
    $('.forge-iris-input').wpColorPicker({
        change: function (event, ui) {
            var hex = ui.color.toString();
            var id  = this.id;
            if (id === 'admin_accent') {
                var lum = forgeLuminance(hex);
                document.documentElement.style.setProperty('--forge-admin-accent', hex);
                document.documentElement.style.setProperty('--forge-accent-text', lum > 0.55 ? '#1d2327' : '#ffffff');
                document.documentElement.style.setProperty('--forge-admin-accent-fg', lum > 0.55 ? '#1d2327' : hex);
            } else if (id === 'hover_color') {
                document.documentElement.style.setProperty('--forge-hover-color', hex);
                document.documentElement.style.setProperty('--forge-hover-color-fg', forgeLuminance(hex) > 0.55 ? '#1d2327' : hex);
            }
        },
    });
} catch (e) {
    console.error('[FormFabricator] wpColorPicker init failed', e);
}

            /* The Security/Miscellaneous cards stay in the DOM (hidden) for
               non-admins so existing JS bindings don't null-crash. This is
               UI-only — the real boundary is server-side (manage_options
               checks in the AJAX handlers) — but re-hide on tamper anyway. */
            if (!DATA.isFullAdmin) {
                var adminOnlySel = '.forge-settings-card--security, ' +
                    '#forge-access-tile-btn, #forge-reset-tile-btn';
                /* Modal overlays for the hidden tiles — toggled via the same
                   bare hidden attribute, but not nested inside a card. */
                var adminOnlyOverlayIds = [
                    'forge-key-overlay', 'forge-key-view-overlay',
                    'forge-reset-overlay', 'forge-key-dl-overlay',
                    'forge-master-key-overlay', 'forge-legacy-key-overlay',
                    'forge-access-overlay'
                ];
                function rehideAdminCards() {
                    document.querySelectorAll(adminOnlySel).forEach(function (el) {
                        var card = el.closest('.forge-settings-card') || el;
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
            var overlay   = document.getElementById('forge-reset-overlay');
            var chk       = document.getElementById('forge-reset-delete-forms');
            var confirmBtn = document.getElementById('forge-reset-confirm');
            var countdownTimer = null;

            var confirmed = false;

            document.getElementById('forge-reset-tile-btn').addEventListener('click', function () {
                chk.checked = false;
                confirmed = false;
                resetConfirmBtn();
                overlay.hidden = false;
                document.getElementById('forge-reset-cancel').focus();
            });

            document.getElementById('forge-reset-cancel').addEventListener('click', closeModal);

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
                confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + I18N.resetting;

                $.post(ajaxurl, {
                    action:    'forge_forms_factory_reset',
                    nonce:     NONCES.factoryReset,
                    del_forms: chk.checked ? '1' : '0'
                }, function (res) {
                    if (res.success) {
                        overlay.hidden = true;
                        window.location.reload();
                    } else {
                        confirmed = false;
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = I18N.errorTryAgain;
                    }
                }).fail(function () {
                    confirmed = false;
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = I18N.errorTryAgain;
                });
            });

            function startCountdown() {
                var sec = 10;
                
                var confirmLabel = I18N.areYouSureCountdown;
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = confirmLabel.replace('%d', sec);
                countdownTimer = setInterval(function () {
                    sec--;
                    if (sec <= 0) {
                        clearInterval(countdownTimer);
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<i class="fa-solid fa-check"></i> ' + I18N.yesReset;
                    } else {
                        confirmBtn.innerHTML = confirmLabel.replace('%d', sec);
                    }
                }, 1000);
            }

            function resetConfirmBtn() {
                clearInterval(countdownTimer);
                confirmed = false;
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = I18N.reset;
            }

            function closeModal() {
                clearInterval(countdownTimer);
                overlay.hidden = true;
            }
        });

        /* ── Key-rotation modal ── */
        (function () {
            var keyOverlay  = document.getElementById('forge-key-overlay');
            var triggerBtn  = document.getElementById('forge-rotate-key-trigger');
            var cancelBtn   = document.getElementById('forge-key-cancel');
            var confirmBtn  = document.getElementById('forge-key-confirm');
            var pwInput     = document.getElementById('forge_key_pw');
            var pw2Input    = document.getElementById('forge_key_pw2');
            var chk         = document.getElementById('forge_key_compromised');
            var cmpHint     = document.getElementById('forge-key-compromised-hint');
            var errList     = document.getElementById('forge-key-pw-errors');
            var bars        = document.querySelectorAll('#forge-key-strength span');
            var modalMsg    = document.getElementById('forge-key-modal-msg');

            if (!triggerBtn) { return; }

            function rules(pw) {
                return [
                    { ok: pw.length >= 12,          label: I18N.minChars },
                    { ok: /[A-Z]/.test(pw),         label: I18N.upperLetter },
                    { ok: /[a-z]/.test(pw),         label: I18N.lowerLetter },
                    { ok: /[0-9]/.test(pw),         label: I18N.digit },
                    { ok: /[^A-Za-z0-9]/.test(pw), label: I18N.specialChar },
                ];
            }

            function validate() {
                var pw  = pwInput.value;
                var rs  = rules(pw);
                var passed = rs.filter(function (r) { return r.ok; }).length;

                bars.forEach(function (b, i) {
                    b.className = i < passed ? 'forge-key-bar-' + Math.min(passed, 5) : '';
                });

                errList.innerHTML = '';
                rs.forEach(function (r) {
                    if (!r.ok) {
                        var li = document.createElement('li');
                        li.textContent = r.label;
                        errList.appendChild(li);
                    }
                });

                var allPass = rs.every(function (r) { return r.ok; });
                var match   = pw !== '' && pw === pw2Input.value;
                confirmBtn.disabled = !(allPass && match);
            }

            function openModal() {
                pwInput.value  = '';
                pw2Input.value = '';
                chk.checked    = false;
                cmpHint.hidden = true;
                modalMsg.hidden = true;
                errList.innerHTML = '';
                bars.forEach(function (b) { b.className = ''; });
                confirmBtn.disabled = true;
                keyOverlay.hidden = false;
                pwInput.focus();
            }

            function closeKeyModal() {
                keyOverlay.hidden = true;
            }

            triggerBtn.addEventListener('click', openModal);
            cancelBtn.addEventListener('click', closeKeyModal);

            keyOverlay.addEventListener('click', function (e) {
                if (e.target === keyOverlay) { closeKeyModal(); }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !keyOverlay.hidden) { closeKeyModal(); }
            });

            pwInput.addEventListener('input', validate);
            pw2Input.addEventListener('input', validate);

            chk.addEventListener('change', function () {
                cmpHint.hidden = !chk.checked;
            });

            confirmBtn.addEventListener('click', function () {
                if (confirmBtn.disabled) { return; }
                confirmBtn.disabled = true;

                var fd = new FormData();
                fd.append('action',               'forge_forms_rotate_key');
                fd.append('nonce',                NONCES.rotate);
                fd.append('key_password',         pwInput.value);
                fd.append('key_password_confirm', pw2Input.value);
                fd.append('key_compromised',      chk.checked ? '1' : '0');

                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var ok  = data.success;
                        var txt = (data.data && data.data.message)
                            ? data.data.message
                            : (ok ? I18N.success : I18N.error);
                        modalMsg.hidden      = false;
                        modalMsg.textContent = txt;
                        modalMsg.style.color = ok ? '#1a5c28' : '#b32d2e';
                        if (ok) {
                            closeKeyModal();
                            showKeyDownloadModal({
                                uuid:       data.data.key_uuid,
                                key:        data.data.key_value,
                                created_at: data.data.created_at,
                            });
                        } else {
                            confirmBtn.disabled = false;
                        }
                    })
                    .catch(function () {
                        modalMsg.hidden      = false;
                        modalMsg.textContent = I18N.networkError;
                        modalMsg.style.color = '#b32d2e';
                        confirmBtn.disabled  = false;
                    });
            });
        }());

        /* ── Key-download modal ── */
        function showKeyDownloadModal(keyData) {
            var dlOverlay  = document.getElementById('forge-key-dl-overlay');
            var dlUuid     = document.getElementById('forge-key-dl-uuid');
            var dlDate     = document.getElementById('forge-key-dl-date');
            var dlBtn      = document.getElementById('forge-key-dl-btn');
            var dlConfirm  = document.getElementById('forge-key-dl-confirm');
            if (!dlOverlay) { location.reload(); return; }

            dlUuid.textContent    = keyData.uuid || '—';
            dlDate.textContent    = keyData.created_at || '—';
            dlConfirm.disabled    = true;
            dlOverlay._keyData    = keyData;
            dlOverlay.hidden      = false;

            dlBtn.onclick = function () {
                var payload = JSON.stringify({
                    plugin:     'FormFabricator PDF Seal Key',
                    uuid:       keyData.uuid,
                    key:        keyData.key,
                    created_at: keyData.created_at,
                }, null, 2);
                var blob = new Blob([payload], { type: 'application/json' });
                var a    = document.createElement('a');
                a.href     = URL.createObjectURL(blob);
                a.download = 'formfabricator-key-' + (keyData.uuid || 'key').substring(0, 8) + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(a.href);
                dlConfirm.disabled = false;
            };

            dlConfirm.onclick = function () {
                dlOverlay.hidden = true;
                location.reload();
            };
        }

        /* Show download modal on page load if a key was just auto-generated */
        (function () {
            var pending = DATA.pendingDownload;
            if (pending && pending.uuid && pending.key) {
                showKeyDownloadModal(pending);
            }
        }());

        /* ── Legacy-key import modal ── */
        (function () {
            var overlay       = document.getElementById('forge-legacy-key-overlay');
            var trigger       = document.getElementById('forge-legacy-key-trigger');
            var cancelBtn     = document.getElementById('forge-legacy-key-cancel');
            var confirmBtn    = document.getElementById('forge-legacy-key-confirm');
            var textarea      = document.getElementById('forge-legacy-key-json');
            var errEl         = document.getElementById('forge-legacy-key-error');
            var mismatchMsg   = document.getElementById('forge-legacy-key-mismatch-msg');
            var forceBtn      = document.getElementById('forge-legacy-key-force');
            var radioRotated = document.getElementById('forge-legacy-status-rotated');
            if (!trigger) { return; }

            function open() {
                textarea.value            = '';
                errEl.style.display       = 'none';
                mismatchMsg.style.display = 'none';
                confirmBtn.style.display  = '';
                forceBtn.style.display    = 'none';
                radioRotated.checked      = true;
                overlay.hidden            = false;
                textarea.focus();
            }
            function close() { overlay.hidden = true; }

            forceBtn.addEventListener('click', function () { submitLegacy(true); });

            trigger.addEventListener('click', open);
            cancelBtn.addEventListener('click', close);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) { close(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !overlay.hidden) { close(); }
            });

            function submitLegacy(confirmMismatch) {
                confirmBtn.disabled = true;
                errEl.style.display       = 'none';
                mismatchMsg.style.display = 'none';
                var statusRadio = overlay.querySelector('input[name="forge_legacy_status"]:checked');
                var fd = new FormData();
                fd.append('action',      'forge_add_legacy_key');
                fd.append('nonce',       DATA.legacyKeyNonce);
                fd.append('key_json',    textarea.value);
                fd.append('key_status',  statusRadio ? statusRadio.value : 'rotated-legacy');
                if (confirmMismatch) { fd.append('confirm_mismatch', '1'); }
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            close();
                            location.reload();
                            return;
                        }
                        if (data.data && data.data.code === 'plugin_mismatch') {
                            mismatchMsg.textContent  = '';
                            var msgNode = document.createTextNode(data.data.text || '');
                            var brNode  = document.createElement('br');
                            var q2Node  = document.createTextNode(data.data.confirm || '');
                            mismatchMsg.appendChild(msgNode);
                            mismatchMsg.appendChild(brNode);
                            mismatchMsg.appendChild(q2Node);
                            mismatchMsg.style.display = 'block';
                            confirmBtn.style.display  = 'none';
                            forceBtn.style.display    = '';
                            confirmBtn.disabled = false;
                            return;
                        }
                        errEl.textContent   = (data.data && data.data.message) || I18N.error;
                        errEl.style.display = 'block';
                        confirmBtn.disabled = false;
                    })
                    .catch(function () {
                        errEl.textContent   = I18N.networkError;
                        errEl.style.display = 'block';
                        confirmBtn.disabled = false;
                    });
            }

            confirmBtn.addEventListener('click', function () { submitLegacy(false); });
        }());

        /* ── Blocking setup overlay ── */
        (function () {
            var blocker     = document.getElementById('forge-setup-blocker');
            if (!blocker) { return; } // already setup_done, element not rendered

            // Scope backdrop to the WP content area, not the full viewport.
            function positionBlocker() {
                var wpc = document.getElementById('wpcontent');
                if (wpc) {
                    var r = wpc.getBoundingClientRect();
                    blocker.style.top    = r.top  + window.scrollY + 'px';
                    blocker.style.left   = r.left + window.scrollX + 'px';
                    blocker.style.width  = r.width  + 'px';
                    blocker.style.height = Math.max(r.height, window.innerHeight - r.top) + 'px';
                }
            }
            positionBlocker();
            window.addEventListener('resize', positionBlocker);

            var btnDefault  = document.getElementById('forge-blocker-default');
            var btnSecure   = document.getElementById('forge-blocker-secure');
            var blockerErr  = document.getElementById('forge-blocker-error');

            // Card hover effect.
            [btnDefault, btnSecure].forEach(function (btn) {
                btn.addEventListener('mouseenter', function () {
                    btn.style.borderColor = '#2271b1';
                });
                btn.addEventListener('mouseleave', function () {
                    btn.style.borderColor = '#dcdcde';
                });
            });
            var secActions    = document.getElementById('forge-security-actions');
            var mkStep        = document.getElementById('forge-blocker-mk-step');
            var mkLine        = document.getElementById('forge-blocker-mk-line');
            var mkBack        = document.getElementById('forge-blocker-mk-back');
            var mkConfirm     = document.getElementById('forge-blocker-mk-confirm');
            var mkError       = document.getElementById('forge-blocker-mk-error');
            var readyStep     = document.getElementById('forge-blocker-ready-step');
            var readyConfirm  = document.getElementById('forge-blocker-ready-confirm');
            var readyError    = document.getElementById('forge-blocker-ready-error');

            var step1Div = document.getElementById('forge-blocker-step1');

            function showBlockerError(msg) {
                blockerErr.textContent   = msg;
                blockerErr.style.display = 'block';
            }

            function postBlocker(action, extra, onSuccess) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce',  DATA.setupNonce);
                if (extra) {
                    Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
                }
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(onSuccess)
                    .catch(function () { showBlockerError(I18N.networkError); });
            }

            function hideStep1() { step1Div.style.display = 'none'; }
            function showStep1() { step1Div.style.display = ''; }

            function showMkStep(defineLine) {
                mkLine.textContent     = defineLine || '…';
                mkError.style.display  = 'none';
                hideStep1();
                mkStep.style.display   = 'block';
                mkConfirm.disabled     = !defineLine;
            }

            function showReadyStep() {
                readyError.style.display = 'none';
                hideStep1();
                readyStep.style.display  = 'block';
            }

            function finishSetup(data) {
                blocker.style.display = 'none';
                showKeyDownloadModal(data.data);
                if (secActions) { secActions.removeAttribute('hidden'); }
            }

            // ── Auto-advance based on server-detected state ──
            var setupState = DATA.setupState || 'choose';
            if (setupState === 'masterkey') {
                // Encryption chosen but master key not in wp-config.php yet.
                showMkStep(''); // immediate — define line filled in on response
                postBlocker('forge_setup_get_master_key', null, function (data) {
                    if (data.success) {
                        mkLine.textContent = data.data.define_line;
                        mkConfirm.disabled = false;
                    } else {
                        showBlockerError((data.data && data.data.message) || I18N.error);
                    }
                });
            } else if (setupState === 'ready') {
                // Encryption chosen AND master key already present — skip straight to finalise.
                showReadyStep();
            }

            // ── Step-1 card buttons ──
            if (btnDefault) {
                btnDefault.addEventListener('click', function () {
                    btnDefault.disabled = true;
                    btnSecure.disabled  = true;
                    blockerErr.style.display = 'none';
                    postBlocker('forge_setup_keep_default', null, function (data) {
                        if (data.success) {
                            finishSetup(data);
                        } else {
                            btnDefault.disabled = false;
                            btnSecure.disabled  = false;
                            showBlockerError((data.data && data.data.message) || I18N.error);
                        }
                    });
                });
            }

            if (btnSecure) {
                btnSecure.addEventListener('click', function () {
                    blockerErr.style.display = 'none';
                    showMkStep(''); // immediate transition; define line filled in on response
                    postBlocker('forge_setup_get_master_key', null, function (data) {
                        if (!data.success) {
                            // Roll back to step 1 on error.
                            mkStep.style.display = 'none';
                            showStep1();
                            showBlockerError((data.data && data.data.message) || I18N.error);
                            return;
                        }
                        mkLine.textContent = data.data.define_line;
                        mkConfirm.disabled = false;
                    });
                });
            }

            // ── Step-2a back button ──
            if (mkBack) {
                mkBack.addEventListener('click', function () {
                    mkBack.disabled = true;
                    postBlocker('forge_setup_reset_choice', null, function () {
                        mkBack.disabled = false;
                        mkStep.style.display = 'none';
                        showStep1();
                        btnDefault.disabled = false;
                        btnSecure.disabled  = false;
                        blockerErr.style.display = 'none';
                    });
                });
            }

            // ── Step-2a: confirm master key added ──
            if (mkConfirm) {
                mkConfirm.addEventListener('click', function () {
                    mkConfirm.disabled    = true;
                    mkError.style.display = 'none';
                    postBlocker('forge_setup_confirm_secure', null, function (data) {
                        if (!data.success) {
                            mkConfirm.disabled    = false;
                            mkError.textContent   = (data.data && data.data.message) || I18N.error;
                            mkError.style.display = 'block';
                            return;
                        }
                        finishSetup(data);
                    });
                });
            }

            // ── Step-2b: master key already present, finalise ──
            if (readyConfirm) {
                readyConfirm.addEventListener('click', function () {
                    readyConfirm.disabled    = true;
                    readyError.style.display = 'none';
                    postBlocker('forge_setup_confirm_secure', null, function (data) {
                        if (!data.success) {
                            readyConfirm.disabled    = false;
                            readyError.textContent   = (data.data && data.data.message) || I18N.error;
                            readyError.style.display = 'block';
                            return;
                        }
                        finishSetup(data);
                    });
                });
            }
        }());


        /* ── Encryption upgrade (Standard → AES-256-GCM) ── */
        (function () {
            var upgradeBtn = document.getElementById('forge-upgrade-enc-btn');
            if (!upgradeBtn) { return; }

            var mkOverlay = document.getElementById('forge-master-key-overlay');
            var mkLine    = document.getElementById('forge-master-key-line');
            var mkConfirm = document.getElementById('forge-master-key-confirm');
            var mkCancel  = document.getElementById('forge-master-key-cancel');
            var mkError   = document.getElementById('forge-master-key-error');

            function openMkModal(defineLine) {
                mkLine.textContent    = defineLine || '…';
                mkError.style.display = 'none';
                mkConfirm.disabled    = !defineLine;
                mkOverlay.hidden      = false;
            }
            function closeMkModal() { mkOverlay.hidden = true; }

            upgradeBtn.addEventListener('click', function () {
                upgradeBtn.disabled = true;
                openMkModal('');
                var fd = new FormData();
                fd.append('action', 'forge_setup_get_master_key');
                fd.append('nonce',  DATA.setupNonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        upgradeBtn.disabled = false;
                        if (data.success) {
                            mkLine.textContent = data.data.define_line;
                            mkConfirm.disabled = false;
                        } else {
                            closeMkModal();
                        }
                    })
                    .catch(function () {
                        upgradeBtn.disabled = false;
                        closeMkModal();
                    });
            });

            mkCancel.addEventListener('click', closeMkModal);
            mkOverlay.addEventListener('click', function (e) {
                if (e.target === mkOverlay) { closeMkModal(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !mkOverlay.hidden) { closeMkModal(); }
            });

            mkConfirm.addEventListener('click', function () {
                mkConfirm.disabled    = true;
                mkError.style.display = 'none';
                var fd = new FormData();
                fd.append('action', 'forge_setup_confirm_secure');
                fd.append('nonce',  DATA.setupNonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            mkConfirm.disabled    = false;
                            mkError.textContent   = (data.data && data.data.message) || I18N.error;
                            mkError.style.display = 'block';
                            return;
                        }
                        closeMkModal();
                        if (data.data && data.data.upgrade) {
                            location.reload();
                            return;
                        }
                        showKeyDownloadModal(data.data);
                    })
                    .catch(function () {
                        mkConfirm.disabled    = false;
                        mkError.textContent   = I18N.networkError;
                        mkError.style.display = 'block';
                    });
            });
        }());

        /* ── Fake-password: remove readonly on focus so managers can't pre-fill ── */
        (function () {
            document.querySelectorAll('.forge-fake-password').forEach(function (el) {
                el.addEventListener('focus', function () { el.removeAttribute('readonly'); });
            });
        }());

        /* ── Privacy policy text: language switch (instant, no round-trip — every
           language's text is pre-rendered server-side into the data attribute)
           and copy-to-clipboard ── */
        (function () {
            var overlay  = document.getElementById('forge-privacy-text-overlay');
            var trigger  = document.getElementById('forge-privacy-text-trigger');
            var closeBtn = document.getElementById('forge-privacy-text-close');
            var box      = document.getElementById('forge-privacy-text-box');
            var input    = document.getElementById('forge-privacy-text-lang-input');
            var list     = document.getElementById('forge-privacy-lang-list');
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
            var options = Array.prototype.slice.call(list.querySelectorAll('.forge-combobox-option'));

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
                if (!list.hidden && !e.target.closest('#forge-privacy-lang-combobox')) {
                    closeCombobox();
                }
            });

            var copyBtn  = document.getElementById('forge-privacy-text-copy');
            var copiedEl = document.getElementById('forge-privacy-text-copied');
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

        /* ── Key-view modal ── */
        (function () {
            var viewOverlay = document.getElementById('forge-key-view-overlay');
            var viewTrigger = document.getElementById('forge-key-view-trigger');
            var viewClose   = document.getElementById('forge-key-view-close');
            /* viewOverlay markup is only rendered server-side for full admins;
               bail out for everyone else instead of throwing on the null ref. */
            if (!viewTrigger || !viewOverlay || !viewClose) { return; }

            function openView()  { viewOverlay.hidden = false; }
            function closeView() { viewOverlay.hidden = true; }

            viewTrigger.addEventListener('click', openView);
            viewClose.addEventListener('click', closeView);
            viewOverlay.addEventListener('click', function (e) {
                if (e.target === viewOverlay) { closeView(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !viewOverlay.hidden) { closeView(); }
            });
        }());

        /* ── User-access modal ── */
        (function () {
            var CAPS = [
                ['view_forms',      I18N.permList],
                ['edit_forms',      I18N.permForms],
                ['edit_pdf_layout', I18N.permPdfLayout],
                ['use_verifier',    I18N.permVerifier],
                ['settings',        I18N.permSettings],
            ];

            var overlay    = document.getElementById('forge-access-overlay');
            var loading    = document.getElementById('forge-access-loading');
            var content    = document.getElementById('forge-access-content');
            var rolesHead  = document.getElementById('forge-access-roles-head');
            var rolesBody  = document.getElementById('forge-access-roles-body');
            var usersHead  = document.getElementById('forge-access-users-head');
            var usersBody  = document.getElementById('forge-access-users-body');
            var noUsers    = document.getElementById('forge-access-no-users');
            var searchInput = document.getElementById('forge-access-user-search');
            var dropdown   = document.getElementById('forge-access-user-dropdown');
            var saveBtn    = document.getElementById('forge-access-save');
            var cancelBtn  = document.getElementById('forge-access-cancel');
            var errorEl    = document.getElementById('forge-access-error');

            /* roles  = { slug: { view_forms: bool, ... } }
               users  = [{ id, name, perms: { view_forms: bool, ... } }]
               roleNames = { slug: label } */
            var roles = {}, roleNames = {}, users = [], userList = [];

            document.getElementById('forge-access-tile-btn').addEventListener('click', function () {
                var d    = DATA.accessData || {};
                roles     = d.roles      || {};
                roleNames = d.role_names || {};
                users     = (d.user_overrides || []).map(function (u) {
                    return { id: u.id, name: u.name, perms: u.perms || {} };
                });
                userList  = d.user_list  || [];
                overlay.hidden = false;
                loading.style.display = 'none';
                content.style.display = '';
                errorEl.style.display = 'none';
                buildHeaders();
                renderRoles();
                renderUsers();
                renderUserSelect();
            });

            function closeModal() { overlay.hidden = true; }
            cancelBtn.addEventListener('click', closeModal);

            var suppressNextOverlayClose = false;
            overlay.addEventListener('mousedown', function (e) {
                if (e.target === overlay && !dropdown.hidden) {
                    suppressNextOverlayClose = true;
                }
            });
            overlay.addEventListener('click', function (e) {
                if (e.target !== overlay) return;
                if (suppressNextOverlayClose) {
                    suppressNextOverlayClose = false;
                    return;
                }
                closeModal();
            });

            function buildHeaders() {
                [rolesHead, usersHead].forEach(function (head, isUsers) {
                    head.innerHTML = '';
                    var thName = document.createElement('th');
                    thName.textContent = isUsers
                        ? I18N.userLabel
                        : I18N.roleLabel;
                    thName.className = 'forge-access-th forge-access-th--name';
                    head.appendChild(thName);
                    CAPS.forEach(function (cap) {
                        var th = document.createElement('th');
                        th.textContent = cap[1];
                        th.className = 'forge-access-th forge-access-th--cap';
                        head.appendChild(th);
                    });
                });
            }

            function emptyPerms() {
                var p = {};
                CAPS.forEach(function (c) { p[c[0]] = false; });
                return p;
            }

            function renderRoles() {
                rolesBody.innerHTML = '';
                Object.keys(roleNames).forEach(function (slug) {
                    var isAdmin = slug === 'administrator';
                    var perms   = roles[slug] || emptyPerms();
                    var tr = document.createElement('tr');
                    tr.className = 'forge-access-row';

                    var tdName = document.createElement('td');
                    tdName.className = 'forge-access-td forge-access-td--name';
                    tdName.textContent = roleNames[slug];
                    tr.appendChild(tdName);

                    CAPS.forEach(function (cap) {
                        var td = document.createElement('td');
                        td.className = 'forge-access-td forge-access-td--cap';
                        if (isAdmin) {
                            var icon = document.createElement('i');
                            icon.className = 'fa-solid fa-check forge-access-always';
                            td.appendChild(icon);
                        } else {
                            var cb = document.createElement('input');
                            cb.type = 'checkbox';
                            cb.className = 'forge-access-cb';
                            cb.checked = !!perms[cap[0]];
                            (function (s, key) {
                                cb.addEventListener('change', function () {
                                    if (!roles[s]) roles[s] = emptyPerms();
                                    roles[s][key] = this.checked;
                                });
                            }(slug, cap[0]));
                            td.appendChild(cb);
                        }
                        tr.appendChild(td);
                    });
                    rolesBody.appendChild(tr);
                });
            }

            function renderUsers() {
                Array.from(usersBody.querySelectorAll('tr.forge-u-row')).forEach(function (r) { r.remove(); });
                noUsers.style.display = users.length ? 'none' : '';
                users.forEach(function (u, i) {
                    var tr = document.createElement('tr');
                    tr.className = 'forge-access-row forge-u-row';

                    var tdName = document.createElement('td');
                    tdName.className = 'forge-access-td forge-access-td--name';
                    var nameBtn = document.createElement('button');
                    nameBtn.type = 'button';
                    nameBtn.className = 'forge-access-name-btn';
                    nameBtn.title = I18N.clickToRemove;
                    nameBtn.textContent = u.name;
                    (function (idx) {
                        nameBtn.addEventListener('click', function () {
                            users.splice(idx, 1);
                            renderUsers();
                            renderUserSelect();
                        });
                    }(i));
                    tdName.appendChild(nameBtn);
                    tr.appendChild(tdName);

                    CAPS.forEach(function (cap) {
                        var td = document.createElement('td');
                        td.className = 'forge-access-td forge-access-td--cap';
                        var cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.className = 'forge-access-cb';
                        cb.checked = !!(u.perms && u.perms[cap[0]]);
                        (function (userObj, key) {
                            cb.addEventListener('change', function () {
                                if (!userObj.perms) userObj.perms = emptyPerms();
                                userObj.perms[key] = this.checked;
                            });
                        }(u, cap[0]));
                        td.appendChild(cb);
                        tr.appendChild(td);
                    });
                    usersBody.insertBefore(tr, noUsers);
                });
            }

            function renderUserSelect() {
                searchInput.value = '';
                dropdown.hidden = true;
                dropdown.innerHTML = '';
            }

            function availableUsers() {
                var usedIds = users.map(function (u) { return u.id; });
                return userList.filter(function (u) { return usedIds.indexOf(u.id) === -1; });
            }

            function positionDropdown() {
                var r = searchInput.getBoundingClientRect();
                dropdown.style.top   = (r.bottom + 4) + 'px';
                dropdown.style.left  = r.left + 'px';
                dropdown.style.width = r.width + 'px';
            }

            function showDropdown(term) {
                var list = availableUsers().filter(function (u) {
                    return !term || u.name.toLowerCase().indexOf(term.toLowerCase()) !== -1;
                });
                dropdown.innerHTML = '';
                if (!list.length) {
                    dropdown.hidden = true;
                    return;
                }
                list.forEach(function (u) {
                    var item = document.createElement('div');
                    item.className = 'forge-access-dropdown-item';

                    var nameSpan = document.createElement('span');
                    nameSpan.textContent = u.name;
                    item.appendChild(nameSpan);

                    var inlineAdd = document.createElement('button');
                    inlineAdd.type = 'button';
                    inlineAdd.className = 'forge-access-inline-add';
                    inlineAdd.textContent = '+ ' + I18N.addLabel;
                    inlineAdd.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        users.push({ id: u.id, name: u.name, perms: emptyPerms() });
                        renderUsers();
                        var term = searchInput.value;
                        renderUserSelect();
                        searchInput.value = term;
                        showDropdown(term);
                        searchInput.focus();
                    });
                    item.appendChild(inlineAdd);

                    dropdown.appendChild(item);
                });
                positionDropdown();
                dropdown.hidden = false;
            }

            searchInput.addEventListener('input', function () {
                showDropdown(this.value);
            });

            searchInput.addEventListener('focus', function () {
                if (availableUsers().length) showDropdown(this.value);
            });

            searchInput.addEventListener('blur', function () {
                dropdown.hidden = true;
            });

            saveBtn.addEventListener('click', function () {
                saveBtn.disabled = true;
                errorEl.style.display = 'none';
                var rolesData = {}, usersData = {};
                Object.keys(roleNames).forEach(function (slug) {
                    if (slug === 'administrator') return;
                    rolesData[slug] = {};
                    CAPS.forEach(function (cap) {
                        rolesData[slug][cap[0]] = (roles[slug] && roles[slug][cap[0]]) ? '1' : '0';
                    });
                });
                users.forEach(function (u) {
                    usersData[u.id] = {};
                    CAPS.forEach(function (cap) {
                        usersData[u.id][cap[0]] = (u.perms && u.perms[cap[0]]) ? '1' : '0';
                    });
                });
                jQuery.post(ajaxurl, {
                    action: 'forge_save_access_settings',
                    nonce: DATA.accessNonce,
                    roles: rolesData,
                    users: usersData
                }, function (resp) {
                    saveBtn.disabled = false;
                    if (resp.success) {
                        DATA.accessData.roles = roles;
                        DATA.accessData.user_overrides = users.map(function (u) {
                            return { id: u.id, name: u.name, perms: u.perms || {} };
                        });
                        closeModal();
                    } else {
                        showError(I18N.errorSaving);
                    }
                }).fail(function () {
                    saveBtn.disabled = false;
                    showError(I18N.errorSaving);
                });
            });

            function showError(msg) {
                loading.style.display = 'none';
                content.style.display = '';
                errorEl.textContent = msg;
                errorEl.style.display = '';
            }
        }());

        /* ---- AJAX save for #forge-settings-form (no page reload) ---- */
        (function(){
            var form = document.getElementById('forge-settings-form');
            if (!form) return;
            function showNotice(msg, isError) {
                var existing = document.querySelector('.forge-settings-notice');
                if (existing) existing.remove();
                var n = document.createElement('div');
                n.className = 'forge-settings-notice forge-settings-notice--'
                    + (isError ? 'error' : 'success');
                n.innerHTML = '<i class="fa-solid fa-'
                    + (isError ? 'circle-xmark' : 'circle-check') + '"></i> ' + msg;
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
                if (btn) { btn.disabled = true; btn.innerHTML = '<span class="forge-spinner"></span> ' + I18N.saving; }
                var fd = new FormData(form);
                fd.set('action', 'forge_save_general_settings');
                requestAnimationFrame(function(){ requestAnimationFrame(function(){
                fetch(ajaxurl, {method:'POST', body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                        if (data.success) {
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
