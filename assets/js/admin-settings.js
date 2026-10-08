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

        /* ── Key-rotation modal ── */
        (function () {
            var keyOverlay  = document.getElementById('fabricator-key-overlay');
            var triggerBtn  = document.getElementById('fabricator-rotate-key-trigger');
            var cancelBtn   = document.getElementById('fabricator-key-cancel');
            var confirmBtn  = document.getElementById('fabricator-key-confirm');
            var chk         = document.getElementById('fabricator_key_compromised');
            var cmpHint     = document.getElementById('fabricator-key-compromised-hint');
            var modalMsg    = document.getElementById('fabricator-key-modal-msg');

            if (!triggerBtn) { return; }

            function openModal() {
                chk.checked    = false;
                cmpHint.hidden = true;
                modalMsg.hidden = true;
                confirmBtn.disabled = false;
                keyOverlay.hidden = false;
                confirmBtn.focus();
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

            chk.addEventListener('change', function () {
                cmpHint.hidden = !chk.checked;
            });

            confirmBtn.addEventListener('click', function () {
                if (confirmBtn.disabled) { return; }
                confirmBtn.disabled = true;

                var fd = new FormData();
                fd.append('action',               'fabricator_forms_rotate_key');
                fd.append('nonce',                NONCES.rotate);
                fd.append('key_compromised',      chk.checked ? '1' : '0');
                // Shown only while the configured master key doesn't open the stored keys.
                var lost = document.getElementById('fabricator_key_master_lost');
                if (lost && lost.checked) { fd.append('master_key_lost', '1'); }

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
                                uuid:        data.data.key_uuid,
                                key:         data.data.key_value,
                                fingerprint: data.data.key_fingerprint,
                                created_at:  data.data.created_at,
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
            var dlOverlay  = document.getElementById('fabricator-key-dl-overlay');
            var dlUuid     = document.getElementById('fabricator-key-dl-uuid');
            var dlDate     = document.getElementById('fabricator-key-dl-date');
            var dlFp       = document.getElementById('fabricator-key-dl-fingerprint');
            var dlBtn      = document.getElementById('fabricator-key-dl-btn');
            var dlConfirm  = document.getElementById('fabricator-key-dl-confirm');
            if (!dlOverlay) { location.reload(); return; }

            dlUuid.textContent    = keyData.uuid || '—';
            dlDate.textContent    = keyData.created_at || '—';
            if (dlFp) { dlFp.textContent = keyData.fingerprint || '—'; }
            dlConfirm.disabled    = true;
            dlOverlay._keyData    = keyData;
            dlOverlay.hidden      = false;

            dlBtn.onclick = function () {
                var payload = JSON.stringify({
                    plugin:     'FormFabricator PDF Seal Key',
                    uuid:        keyData.uuid,
                    key:         keyData.key,
                    /* HashSeal::keyFingerprint(): what the upgrade dialog shows for this key, to compare it by more than
                       its UUID; checked against the key again on import. */
                    fingerprint: keyData.fingerprint,
                    created_at:  keyData.created_at,
                }, null, 2);
                var blob = new Blob([payload], { type: 'application/json' });
                var a    = document.createElement('a');
                a.href     = URL.createObjectURL(blob);
                a.download = 'formfabricator-key-' + (keyData.uuid || 'key').substring(0, 8) + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                /* Revoked on the next turn, not immediately: some browsers cancel a download whose object URL is
                   released in the same task, and this is the only chance to save the key. */
                var href = a.href;
                setTimeout(function () { URL.revokeObjectURL(href); }, 60000);
                dlConfirm.disabled = false;
            };

            dlConfirm.onclick = function () {
                dlOverlay.hidden = true;
                // Only this click deletes the one-shot transient server-side; a failed request just reshows the modal next load.
                var fd = new FormData();
                fd.append('action', 'fabricator_confirm_key_download');
                fd.append('nonce', NONCES.confirmDownload || '');
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .catch(function () {})
                    .then(function () {
                        location.reload();
                    });
            };
        }

        /* Page carries only a boolean flag; the plaintext key is fetched on demand so it never lands in page source or bfcache. */
        (function () {
            if (!DATA.hasPendingDownload) { return; }

            var fd = new FormData();
            fd.append('action', 'fabricator_peek_key_download');
            fd.append('nonce', NONCES.confirmDownload || '');
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var pending = data && data.success ? data.data : null;
                    if (pending && pending.uuid && pending.key) {
                        showKeyDownloadModal(pending);
                    }
                })
                .catch(function () {});
        }());

        /* ── Legacy-key import modal ── */
        (function () {
            var overlay       = document.getElementById('fabricator-legacy-key-overlay');
            var trigger       = document.getElementById('fabricator-legacy-key-trigger');
            var cancelBtn     = document.getElementById('fabricator-legacy-key-cancel');
            var confirmBtn    = document.getElementById('fabricator-legacy-key-confirm');
            var textarea      = document.getElementById('fabricator-legacy-key-json');
            var errEl         = document.getElementById('fabricator-legacy-key-error');
            var mismatchMsg   = document.getElementById('fabricator-legacy-key-mismatch-msg');
            var forceBtn      = document.getElementById('fabricator-legacy-key-force');
            var radioRotated = document.getElementById('fabricator-legacy-status-rotated');
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
                var statusRadio = overlay.querySelector('input[name="fabricator_legacy_status"]:checked');
                var fd = new FormData();
                fd.append('action',      'fabricator_add_legacy_key');
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
            var blocker     = document.getElementById('fabricator-setup-blocker');
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

            var btnDefault  = document.getElementById('fabricator-blocker-default');
            var btnSecure   = document.getElementById('fabricator-blocker-secure');
            var blockerErr  = document.getElementById('fabricator-blocker-error');

            // Card hover effect.
            [btnDefault, btnSecure].forEach(function (btn) {
                btn.addEventListener('mouseenter', function () {
                    btn.style.borderColor = '#2271b1';
                });
                btn.addEventListener('mouseleave', function () {
                    btn.style.borderColor = '#dcdcde';
                });
            });
            var secActions    = document.getElementById('fabricator-security-actions');
            var mkStep        = document.getElementById('fabricator-blocker-mk-step');
            var mkLine        = document.getElementById('fabricator-blocker-mk-line');
            var mkBack        = document.getElementById('fabricator-blocker-mk-back');
            var mkConfirm     = document.getElementById('fabricator-blocker-mk-confirm');
            var mkError       = document.getElementById('fabricator-blocker-mk-error');
            var readyStep     = document.getElementById('fabricator-blocker-ready-step');
            var readyConfirm  = document.getElementById('fabricator-blocker-ready-confirm');
            var readyError    = document.getElementById('fabricator-blocker-ready-error');

            var step1Div = document.getElementById('fabricator-blocker-step1');

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
                postBlocker('fabricator_setup_get_master_key', null, function (data) {
                    if (data.success) {
                        mkLine.textContent = data.data.define_line;
                        mkConfirm.disabled = false;
                    } else {
                        showBlockerError((data.data && data.data.message) || I18N.error);
                    }
                });
            } else if (setupState === 'ready') {
                /* Encryption chosen and a master key already present: the server confirms the session's issued key, or
                   else the one in wp-config.php, and the setup finalises. */
                postBlocker('fabricator_setup_get_master_key', null, function (data) {
                    if (data.success) {
                        showReadyStep();
                    } else {
                        showBlockerError((data.data && data.data.message) || I18N.error);
                    }
                });
            }

            // ── Step-1 card buttons ──
            if (btnDefault) {
                btnDefault.addEventListener('click', function () {
                    btnDefault.disabled = true;
                    btnSecure.disabled  = true;
                    blockerErr.style.display = 'none';
                    postBlocker('fabricator_setup_keep_default', null, function (data) {
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
                    postBlocker('fabricator_setup_get_master_key', null, function (data) {
                        if (!data.success) {
                            // Roll back to step 1 on error.
                            mkStep.style.display = 'none';
                            showStep1();
                            showBlockerError((data.data && data.data.message) || I18N.error);
                            return;
                        }
                        /* wp-config.php already holds a master key (perhaps another site's): no line to add. */
                        if (data.data.existing) {
                            mkStep.style.display = 'none';
                            showReadyStep();
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
                    postBlocker('fabricator_setup_reset_choice', null, function () {
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
                    postBlocker('fabricator_setup_confirm_secure', null, function (data) {
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
                    postBlocker('fabricator_setup_confirm_secure', null, function (data) {
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
            var upgradeBtn = document.getElementById('fabricator-upgrade-enc-btn');
            if (!upgradeBtn) { return; }

            var mkOverlay = document.getElementById('fabricator-master-key-overlay');
            var mkLine    = document.getElementById('fabricator-master-key-line');
            var mkConfirm = document.getElementById('fabricator-master-key-confirm');
            var mkCancel  = document.getElementById('fabricator-master-key-cancel');
            var mkError   = document.getElementById('fabricator-master-key-error');
            var mkIntro   = document.getElementById('fabricator-master-key-intro');
            var mkLose    = document.getElementById('fabricator-master-key-lose-hint');
            var mkKeys    = document.getElementById('fabricator-master-key-keys');

            function openMkModal(defineLine) {
                mkLine.textContent    = defineLine || '…';
                mkError.style.display = 'none';
                mkConfirm.disabled    = !defineLine;
                showExisting(false, []);
                mkOverlay.hidden      = false;
            }

            /* The master key is already in wp-config.php: no line to add or lose, and the unencrypted keys are listed
               for the admin to tick the ones their backups show to be theirs (FormSettings::handleUpgradeGetMasterKey()). */
            function showExisting(existing, keys) {
                if (mkIntro) { mkIntro.hidden = existing; }
                if (mkLose)  { mkLose.hidden  = existing; }
                if (!mkKeys) { return; }
                mkKeys.textContent = '';
                mkKeys.hidden      = !existing || !keys.length;
                if (mkKeys.hidden) { return; }
                var intro = document.createElement('p');
                intro.className   = 'fabricator-settings-hint';
                intro.textContent = (I18N.masterKeyUnencrypted || '') + ' ' + (I18N.masterKeyActiveUnticked || '');
                mkKeys.appendChild(intro);
                var statusText = {
                    active: I18N.keyStatusActive, retired: I18N.keyStatusRetired,
                    'set-aside': I18N.keyStatusSetAside, pending: I18N.keyStatusPending
                };
                keys.forEach(function (key) {
                    var row = document.createElement('label');
                    row.style.display = 'block';
                    row.style.margin  = '4px 0';
                    var box = document.createElement('input');
                    box.type  = 'checkbox';
                    box.value = key.uuid;
                    box.className = 'fabricator-master-key-choice';
                    /* The fingerprint names the key itself; a UUID is a label a database write can put on any key. */
                    var fp = document.createElement('code');
                    fp.textContent = key.fingerprint;
                    var uuid = document.createElement('small');
                    uuid.textContent = ' ' + key.uuid + ' — ' + (statusText[key.status] || key.status) + (key.date ? ', ' + key.date : '');
                    row.appendChild(box);
                    row.appendChild(document.createTextNode(' '));
                    row.appendChild(fp);
                    row.appendChild(uuid);
                    mkKeys.appendChild(row);
                });
            }
            function closeMkModal() { mkOverlay.hidden = true; }

            upgradeBtn.addEventListener('click', function () {
                upgradeBtn.disabled = true;
                openMkModal('');
                var fd = new FormData();
                /* Its own action: the setup one refuses once setup is done, and this dialog then closed without a word. */
                fd.append('action', 'fabricator_upgrade_get_master_key');
                fd.append('nonce',  DATA.setupNonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        upgradeBtn.disabled = false;
                        if (data.success) {
                            /* A master key already in wp-config.php is confirmed, never replaced: keys may be encrypted with it. */
                            mkLine.textContent = data.data.existing ? (I18N.masterKeyExisting || '') : data.data.define_line;
                            showExisting(!!data.data.existing, data.data.keys || []);
                            mkConfirm.disabled = false;
                        } else {
                            mkError.textContent   = (data.data && data.data.message) || I18N.error;
                            mkError.style.display = 'block';
                        }
                    })
                    .catch(function () {
                        upgradeBtn.disabled   = false;
                        mkError.textContent   = I18N.networkError;
                        mkError.style.display = 'block';
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
                fd.append('action', 'fabricator_setup_confirm_secure');
                fd.append('nonce',  DATA.setupNonce);
                /* Existing master key: only the keys the admin ticked are encrypted (and so trusted again). */
                if (mkKeys) {
                    mkKeys.querySelectorAll('.fabricator-master-key-choice:checked').forEach(function (box) {
                        fd.append('keys[]', box.value);
                    });
                }
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

        /* ── Key-view modal ── */
        (function () {
            var viewOverlay = document.getElementById('fabricator-key-view-overlay');
            var viewTrigger = document.getElementById('fabricator-key-view-trigger');
            var viewClose   = document.getElementById('fabricator-key-view-close');
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
            /* slug, column header, hover text saying what the grant confers. */
            var CAPS = [
                ['view_forms',      I18N.permList,      I18N.permListDesc],
                ['edit_forms',      I18N.permForms,     I18N.permFormsDesc],
                ['edit_pdf_layout', I18N.permPdfLayout, I18N.permPdfLayoutDesc],
                ['use_verifier',    I18N.permVerifier,  I18N.permVerifierDesc],
                ['settings',        I18N.permSettings,  I18N.permSettingsDesc],
            ];

            var overlay    = document.getElementById('fabricator-access-overlay');
            var loading    = document.getElementById('fabricator-access-loading');
            var content    = document.getElementById('fabricator-access-content');
            var rolesHead  = document.getElementById('fabricator-access-roles-head');
            var rolesBody  = document.getElementById('fabricator-access-roles-body');
            var usersHead  = document.getElementById('fabricator-access-users-head');
            var usersBody  = document.getElementById('fabricator-access-users-body');
            var noUsers    = document.getElementById('fabricator-access-no-users');
            var searchInput = document.getElementById('fabricator-access-user-search');
            var dropdown   = document.getElementById('fabricator-access-user-dropdown');
            var saveBtn    = document.getElementById('fabricator-access-save');
            var cancelBtn  = document.getElementById('fabricator-access-cancel');
            var errorEl    = document.getElementById('fabricator-access-error');

            /* roles  = { slug: { view_forms: bool, ... } }
               users  = [{ id, name, perms: { view_forms: bool, ... } }]
               roleNames = { slug: label } */
            var roles = {}, roleNames = {}, users = [];

            document.getElementById('fabricator-access-tile-btn').addEventListener('click', function () {
                var d    = DATA.accessData || {};
                roles     = d.roles      || {};
                roleNames = d.role_names || {};
                users     = (d.user_overrides || []).map(function (u) {
                    return { id: u.id, name: u.name, perms: u.perms || {} };
                });
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
                    thName.className = 'fabricator-access-th fabricator-access-th--name';
                    head.appendChild(thName);
                    CAPS.forEach(function (cap) {
                        var th = document.createElement('th');
                        th.textContent = cap[1];
                        th.className = 'fabricator-access-th fabricator-access-th--cap';
                        if (cap[2]) {
                            /* title, not innerHTML: the text comes from a .mo, which is loaded from
                               WP_LANG_DIR and is not limited to reviewed language packs. */
                            th.title = cap[2];
                            th.classList.add('fabricator-access-th--help');
                        }
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
                    tr.className = 'fabricator-access-row';

                    var tdName = document.createElement('td');
                    tdName.className = 'fabricator-access-td fabricator-access-td--name';
                    tdName.textContent = roleNames[slug];
                    tr.appendChild(tdName);

                    CAPS.forEach(function (cap) {
                        var td = document.createElement('td');
                        td.className = 'fabricator-access-td fabricator-access-td--cap';
                        if (isAdmin) {
                            var icon = document.createElement('i');
                            icon.className = 'fa-solid fa-check fabricator-access-always';
                            td.appendChild(icon);
                        } else {
                            var cb = document.createElement('input');
                            cb.type = 'checkbox';
                            cb.className = 'fabricator-access-cb';
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
                Array.from(usersBody.querySelectorAll('tr.fabricator-u-row')).forEach(function (r) { r.remove(); });
                noUsers.style.display = users.length ? 'none' : '';
                users.forEach(function (u, i) {
                    var tr = document.createElement('tr');
                    tr.className = 'fabricator-access-row fabricator-u-row';

                    var tdName = document.createElement('td');
                    tdName.className = 'fabricator-access-td fabricator-access-td--name';
                    var nameBtn = document.createElement('button');
                    nameBtn.type = 'button';
                    nameBtn.className = 'fabricator-access-name-btn';
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
                        td.className = 'fabricator-access-td fabricator-access-td--cap';
                        var cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.className = 'fabricator-access-cb';
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

            function availableUsers(list) {
                var usedIds = users.map(function (u) { return u.id; });
                return list.filter(function (u) { return usedIds.indexOf(u.id) === -1; });
            }

            /* Asks the server for matching users as the admin types (FormSettings::handleAccessUserSearch()), so the
               page never embeds every account on the site. The sequence number drops out-of-order replies. */
            var searchTimer = null;
            var searchSeq   = 0;
            function searchUsers(term) {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    var seq = ++searchSeq;
                    var fd  = new FormData();
                    fd.append('action', 'fabricator_access_user_search');
                    fd.append('nonce', DATA.accessNonce);
                    fd.append('term', term || '');
                    fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (resp) {
                            if (seq !== searchSeq || document.activeElement !== searchInput) return;
                            showDropdown(resp && resp.success && Array.isArray(resp.data) ? resp.data : []);
                        })
                        .catch(function () {
                            if (seq === searchSeq) dropdown.hidden = true;
                        });
                }, 200);
            }

            function positionDropdown() {
                var r = searchInput.getBoundingClientRect();
                dropdown.style.top   = (r.bottom + 4) + 'px';
                dropdown.style.left  = r.left + 'px';
                dropdown.style.width = r.width + 'px';
            }

            function showDropdown(results) {
                var list = availableUsers(results || []);
                dropdown.innerHTML = '';
                if (!list.length) {
                    dropdown.hidden = true;
                    return;
                }
                list.forEach(function (u) {
                    var item = document.createElement('div');
                    item.className = 'fabricator-access-dropdown-item';

                    var nameSpan = document.createElement('span');
                    nameSpan.textContent = u.name;
                    item.appendChild(nameSpan);

                    var inlineAdd = document.createElement('button');
                    inlineAdd.type = 'button';
                    inlineAdd.className = 'fabricator-access-inline-add';
                    inlineAdd.textContent = '+ ' + I18N.addLabel;
                    inlineAdd.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        users.push({ id: u.id, name: u.name, perms: emptyPerms() });
                        renderUsers();
                        var term = searchInput.value;
                        renderUserSelect();
                        searchInput.value = term;
                        searchInput.focus();
                        searchUsers(term);
                    });
                    item.appendChild(inlineAdd);

                    dropdown.appendChild(item);
                });
                positionDropdown();
                dropdown.hidden = false;
            }

            searchInput.addEventListener('input', function () {
                searchUsers(this.value);
            });

            searchInput.addEventListener('focus', function () {
                searchUsers(this.value);
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
                    action: 'fabricator_save_access_settings',
                    nonce: DATA.accessNonce,
                    /* What this page loaded, so a save that would overwrite another administrator's is refused. */
                    snapshot: DATA.accessSnapshot || '',
                    roles: rolesData,
                    users: usersData
                }, function (resp) {
                    saveBtn.disabled = false;
                    if (resp.success) {
                        DATA.accessData.roles = roles;
                        DATA.accessData.user_overrides = users.map(function (u) {
                            return { id: u.id, name: u.name, perms: u.perms || {} };
                        });
                        /* The saved matrix is the new baseline, or the next save here would be refused as changed elsewhere. */
                        if (resp.data && resp.data.snapshot) { DATA.accessSnapshot = resp.data.snapshot; }
                        closeModal();
                    } else {
                        showError(I18N.errorSaving);
                    }
                }).fail(function (xhr) {
                    saveBtn.disabled = false;
                    /* A 409 carries its own message (changed elsewhere); anything else is a plain save error. */
                    var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                    showError(msg || I18N.errorSaving);
                });
            });

            function showError(msg) {
                loading.style.display = 'none';
                content.style.display = '';
                errorEl.textContent = msg;
                errorEl.style.display = '';
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
