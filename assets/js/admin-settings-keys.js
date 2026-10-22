/*!
 * FormFabricator — Settings page: PDF seal keys (SealKeySettings)
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';

    var pageData = window.FabricatorSealKeys || {};
    var I18N   = pageData.i18n   || {};
    var NONCES = pageData.nonces || {};
    var DATA   = pageData.data   || {};

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
                fd.append('action', 'fabricator_forms_confirm_key_download');
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
            fd.append('action', 'fabricator_forms_peek_key_download');
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
                fd.append('action',      'fabricator_forms_add_legacy_key');
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
                postBlocker('fabricator_forms_setup_get_master_key', null, function (data) {
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
                postBlocker('fabricator_forms_setup_get_master_key', null, function (data) {
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
                    postBlocker('fabricator_forms_setup_keep_default', null, function (data) {
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
                    postBlocker('fabricator_forms_setup_get_master_key', null, function (data) {
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
                    postBlocker('fabricator_forms_setup_reset_choice', null, function () {
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
                    postBlocker('fabricator_forms_setup_confirm_secure', null, function (data) {
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
                    postBlocker('fabricator_forms_setup_confirm_secure', null, function (data) {
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
               for the admin to tick the ones their backups show to be theirs (SealKeySettings::handleUpgradeGetMasterKey()). */
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
                fd.append('action', 'fabricator_forms_upgrade_get_master_key');
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
                fd.append('action', 'fabricator_forms_setup_confirm_secure');
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
}());
