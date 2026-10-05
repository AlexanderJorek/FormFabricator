/*!
 * FormFabricator — Forms list admin page
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
        (function() {

    /* Text as a TEXT NODE, not innerHTML — a .mo (loaded from WP_LANG_DIR, not just reviewed packs) could inject markup. */
    function fabIconThenText(el, iconHtml, text) {
        if (!el) { return; }
        el.innerHTML = iconHtml;
        el.appendChild(document.createTextNode(String(text == null ? '' : text)));
    }


            /* Inlined (not a static asset) since this script is embedded directly. */
            var pageData = window.FabricatorFormListPage || {};
            var ffi18n = pageData.i18n || {};

            /* ── Dropdown: hoist to body, smart flip up/down ── */
            var openDd = null;

            function closeAllDropdowns() {
                if (openDd) { openDd.hidden = true; openDd = null; }
            }

            document.addEventListener('click', closeAllDropdowns);
            document.addEventListener('scroll', closeAllDropdowns, true);

            /* ── Live search ── */
            var searchInput = document.getElementById('fabricator-form-search');
            var noResults   = document.getElementById('fabricator-no-results');

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var q = this.value.toLowerCase().trim();
                    var visible = 0;
                    document.querySelectorAll('.fabricator-form-row').forEach(function(row) {
                        var title = row.dataset.title || '';
                        var show  = !q || title.indexOf(q) !== -1;
                        row.hidden = !show;
                        if (show) visible++;
                        /* A bulk action works on what the admin can see: a row the search hides is unticked. */
                        var rowCb = show ? null : row.querySelector('.fabricator-row-check');
                        if (rowCb) rowCb.checked = false;
                    });
                    if (noResults) noResults.hidden = visible > 0;
                    if (selectAll) selectAll.checked = false;
                    updateBulkBar();
                });
            }

            /* ── Multi-select ── */
            var selectAll   = document.getElementById('fabricator-select-all');
            var bulkBar       = document.getElementById('fabricator-bulk-bar');
            var bulkCount     = document.getElementById('fabricator-bulk-count');
            var bulkApply     = document.getElementById('fabricator-bulk-apply');
            var bulkActionBtn = document.getElementById('fabricator-bulk-action-btn');
            var bulkActionDd  = document.getElementById('fabricator-bulk-action-dd');
            var bulkActionLbl = document.getElementById('fabricator-bulk-action-label');
            var selectedBulkAction = '';

            /* ── Bulk action custom dropdown ── */
            if (bulkActionBtn && bulkActionDd) {
                document.body.appendChild(bulkActionDd);
                bulkActionBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var open = !bulkActionDd.hidden;
                    closeAllDropdowns();
                    if (open) return;
                    var rect = bulkActionBtn.getBoundingClientRect();
                    bulkActionDd.style.top   = (rect.bottom + 4) + 'px';
                    bulkActionDd.style.left  = rect.left + 'px';
                    bulkActionDd.style.right = 'auto';
                    bulkActionDd.hidden = false;
                    openDd = bulkActionDd;
                });
                bulkActionDd.querySelectorAll('.fabricator-dd-item[data-action]').forEach(function(item) {
                    item.addEventListener('click', function() {
                        selectedBulkAction = this.dataset.action;
                        bulkActionLbl.textContent = this.textContent.trim();
                        closeAllDropdowns();
                    });
                });
            }

            function getChecked() {
                return document.querySelectorAll('.fabricator-row-check:checked');
            }

            var emptyState   = document.getElementById('fabricator-list-empty');
            var listToolbar  = document.getElementById('fabricator-list-toolbar');
            var deleteModal  = document.getElementById('fabricator-delete-modal');
            var modalMsg     = document.getElementById('fabricator-modal-msg');
            var modalConfirm = document.getElementById('fabricator-modal-confirm');
            var modalCancel  = document.getElementById('fabricator-modal-cancel');
            var modalResolve = null;

            function showDeleteModal(msg) {
                return new Promise(function(resolve) {
                    modalMsg.textContent = msg;
                    deleteModal.hidden = false;
                    modalResolve = resolve;
                });
            }

            if (modalConfirm) {
                modalConfirm.addEventListener('click', function() {
                    deleteModal.hidden = true;
                    if (modalResolve) { modalResolve(true); modalResolve = null; }
                });
            }
            if (modalCancel) {
                modalCancel.addEventListener('click', function() {
                    deleteModal.hidden = true;
                    if (modalResolve) { modalResolve(false); modalResolve = null; }
                });
            }
            if (deleteModal) {
                deleteModal.addEventListener('click', function(e) {
                    if (e.target === deleteModal) {
                        deleteModal.hidden = true;
                        if (modalResolve) { modalResolve(false); modalResolve = null; }
                    }
                });
            }

            function checkEmpty() {
                if (document.querySelectorAll('.fabricator-form-row').length === 0) {
                    if (emptyState) emptyState.hidden = false;
                    var tl = document.getElementById('fabricator-toolbar-left');
                    var tc = document.getElementById('fabricator-toolbar-center');
                    if (tl) tl.hidden = true;
                    if (tc) tc.hidden = true;
                }
            }

            /* The list container is hidden while the site has no forms, so the first imported or duplicated row has to
               reveal it and the toolbar again, or it stayed invisible until the page was reloaded. */
            function checkFilled() {
                if (document.querySelectorAll('.fabricator-form-row').length === 0) return;
                if (emptyState) emptyState.hidden = true;
                var listEl = document.getElementById('fabricator-form-list');
                if (listEl) listEl.hidden = false;
                var tl = document.getElementById('fabricator-toolbar-left');
                var tc = document.getElementById('fabricator-toolbar-center');
                if (tl) tl.hidden = false;
                if (tc) tc.hidden = false;
            }

            function fadeRemoveRow(row, cb) {
                row.style.transition = 'opacity .2s';
                row.style.opacity = '0';
                setTimeout(function() { row.remove(); checkEmpty(); if (cb) cb(); }, 220);
            }

            function updateBulkBar() {
                var checked = getChecked();
                if (bulkBar)   bulkBar.hidden = checked.length === 0;
                if (bulkCount) bulkCount.textContent = ffi18n.selectedCount.replace('%d', String(checked.length));
                if (selectAll) {
                    var all = document.querySelectorAll('.fabricator-row-check');
                    selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
                    selectAll.checked = all.length > 0 && checked.length === all.length;
                }
            }

            /* ── Per-row event binding (also called after dynamic injection) ── */
            function bindRow(row) {
                /* Grab all references before dd is hoisted to body */
                var menuBtn = row.querySelector('.fabricator-row-menu-btn');
                var copyBtn = row.querySelector('.fabricator-copy-shortcode');
                var dupBtn  = row.querySelector('.fabricator-duplicate-form');
                var delBtn  = row.querySelector('.fabricator-delete-form');
                var expBtn  = row.querySelector('.fabricator-export-form');
                var cb      = row.querySelector('.fabricator-row-check');

                if (menuBtn) {
                    var dd = menuBtn.nextElementSibling;
                    document.body.appendChild(dd);
                    menuBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var alreadyOpen = (openDd === dd && !dd.hidden);
                        closeAllDropdowns();
                        if (alreadyOpen) return;
                        var rect = menuBtn.getBoundingClientRect();
                        dd.style.bottom = 'auto';
                        dd.style.top    = (rect.bottom + 4) + 'px';
                        dd.style.left   = 'auto';
                        dd.style.right  = (window.innerWidth - rect.right) + 'px';
                        dd.hidden = false;
                        openDd = dd;
                        var ddH = dd.getBoundingClientRect().height;
                        if (rect.bottom + 4 + ddH > window.innerHeight - 8) {
                            dd.style.top    = 'auto';
                            dd.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
                        }
                    });
                }

                if (copyBtn) {
                    copyBtn.addEventListener('click', function() {
                        navigator.clipboard.writeText(copyBtn.dataset.code).then(function() {
                            fabIconThenText(copyBtn, '<i class="fa-solid fa-check" aria-hidden="true"></i> ', ffi18n.copied);
                            setTimeout(function() { fabIconThenText(copyBtn, '<i class="fa-regular fa-clipboard" aria-hidden="true"></i> ', ffi18n.copyShortcode); }, 1500);
                        });
                        closeAllDropdowns();
                    });
                }

                if (dupBtn) {
                    dupBtn.addEventListener('click', function() {
                        closeAllDropdowns();
                        var formId = dupBtn.dataset.id;
                        var nonce  = dupBtn.dataset.nonce;
                        dupBtn.disabled = true;
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                action: 'fabricator_forms_duplicate',
                                form_id: formId,
                                nonce: nonce
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            dupBtn.disabled = false;
                            if (data.success && data.data.html) {
                                var tmp = document.createElement('div');
                                tmp.innerHTML = data.data.html.trim();
                                var newRow = tmp.firstElementChild;
                                var list = document.getElementById('fabricator-form-list');
                                if (list && newRow) {
                                    list.insertBefore(newRow, list.firstChild);
                                    bindRow(newRow);
                                    checkFilled();
                                }
                            } else {
                                alert((data.data && data.data.message) || ffi18n.error);
                            }
                        })
                        .catch(function() {
                            dupBtn.disabled = false;
                            alert(ffi18n.error);
                        });
                    });
                }

                if (delBtn) {
                    delBtn.addEventListener('click', function() {
                        closeAllDropdowns();
                        var formId = delBtn.dataset.id;
                        var nonce  = delBtn.dataset.nonce;
                        var rowEl  = row;
                        showDeleteModal(ffi18n.deleteConfirm).then(function(confirmed) {
                            if (!confirmed) return;
                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: new URLSearchParams({
                                    action: 'fabricator_forms_delete',
                                    form_id: formId,
                                    nonce: nonce
                                })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    if (rowEl) fadeRemoveRow(rowEl, updateBulkBar);
                                } else {
                                    alert((data.data && data.data.message) || ffi18n.error);
                                }
                            })
                            .catch(function() {
                                alert(ffi18n.error);
                            });
                        });
                    });
                }

                if (expBtn) {
                    expBtn.addEventListener('click', function() {
                        closeAllDropdowns();
                        showExportLoading();
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                action: 'fabricator_forms_export',
                                form_id: expBtn.dataset.id,
                                nonce: expBtn.dataset.nonce
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                showExportResult(data.data.string);
                            } else {
                                closeExportModal();
                                alert((data.data && data.data.message) || ffi18n.error);
                            }
                        })
                        .catch(function() {
                            closeExportModal();
                            alert(ffi18n.error);
                        });
                    });
                }

                if (cb) cb.addEventListener('change', updateBulkBar);
            }

            document.querySelectorAll('.fabricator-form-row').forEach(bindRow);

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    document.querySelectorAll('.fabricator-row-check').forEach(function(cb) {
                        if (!cb.closest('.fabricator-form-row').hidden) cb.checked = selectAll.checked;
                    });
                    updateBulkBar();
                });
            }

            /* ── Bulk apply ── */
            if (bulkApply) {
                bulkApply.addEventListener('click', function() {
                    var action  = selectedBulkAction;
                    var checked = getChecked();
                    if (!action)         { alert(ffi18n.chooseActionAlert); return; }
                    if (!checked.length) return;

                    var ids    = [];
                    var nonces = [];
                    checked.forEach(function(cb) {
                        ids.push(cb.value);
                        nonces.push(cb.dataset.delNonce);
                    });

                    if (action === 'delete') {
                        showDeleteModal(ffi18n.deleteConfirmMulti.replace('%d', String(checked.length))).then(function(confirmed) {
                        if (!confirmed) return;
                        bulkApply.disabled = true;
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                action: 'fabricator_forms_bulk_delete',
                                ids: JSON.stringify(ids),
                                nonces: JSON.stringify(nonces)
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                (data.data.deleted || []).forEach(function(id) {
                                    var cb  = document.querySelector('.fabricator-row-check[value="' + id + '"]');
                                    var row = cb ? cb.closest('.fabricator-form-row') : null;
                                    if (row) fadeRemoveRow(row, updateBulkBar);
                                });
                            } else {
                                alert((data.data && data.data.message) || ffi18n.error);
                            }
                            bulkApply.disabled = false;
                            selectedBulkAction = '';
                            if (bulkActionLbl) bulkActionLbl.textContent = ffi18n.chooseAction;
                        })
                        .catch(function() {
                            bulkApply.disabled = false;
                            alert(ffi18n.error);
                        });
                        }); // showDeleteModal

                    } else if (action === 'duplicate') {
                        var dupNonces = [];
                        checked.forEach(function(cb) { dupNonces.push(cb.dataset.dupNonce); });
                        bulkApply.disabled = true;
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                action: 'fabricator_forms_bulk_duplicate',
                                ids: JSON.stringify(ids),
                                nonces: JSON.stringify(dupNonces)
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            bulkApply.disabled = false;
                            if (data.success && data.data.created && data.data.created.length) {
                                window.location.reload();
                            } else if (!data.success) {
                                alert((data.data && data.data.message) || ffi18n.error);
                            }
                        })
                        .catch(function() {
                            bulkApply.disabled = false;
                            alert(ffi18n.error);
                        });
                    }
                });
            }

            /* ── Export ── */
            var exportModal   = document.getElementById('fabricator-export-modal');
            var exportLoading = document.getElementById('fabricator-export-loading');
            var exportResult  = document.getElementById('fabricator-export-result');
            var exportBar     = document.getElementById('fabricator-export-bar');
            var exportString  = document.getElementById('fabricator-export-string');
            var exportCopy    = document.getElementById('fabricator-export-copy');
            var exportClose   = document.getElementById('fabricator-export-close');
            var exportBarTimer = null;

            function closeExportModal() {
                if (exportModal) exportModal.hidden = true;
                clearTimeout(exportBarTimer);
            }

            function showExportLoading() {
                exportLoading.hidden = false;
                exportResult.hidden  = true;
                exportModal.hidden   = false;
                /* Animate bar: quick run to ~70%, then slow crawl waiting for response */
                exportBar.style.transition = 'none';
                exportBar.style.width = '0%';
                requestAnimationFrame(function() {
                    exportBar.style.transition = 'width .6s ease-out';
                    exportBar.style.width = '70%';
                    exportBarTimer = setTimeout(function() {
                        exportBar.style.transition = 'width 6s linear';
                        exportBar.style.width = '92%';
                    }, 650);
                });
            }

            function showExportResult(str) {
                clearTimeout(exportBarTimer);
                exportBar.style.transition = 'width .2s ease-out';
                exportBar.style.width = '100%';
                setTimeout(function() {
                    exportLoading.hidden    = true;
                    exportResult.hidden     = false;
                    exportString.value      = str;
                    exportString.style.height = 'auto';
                    exportString.style.height = exportString.scrollHeight + 'px';
                    exportString.select();
                }, 220);
            }


            if (exportCopy) {
                exportCopy.addEventListener('click', function() {
                    exportString.select();
                    navigator.clipboard.writeText(exportString.value).then(function() {
                        fabIconThenText(exportCopy, '<i class="fa-solid fa-check" aria-hidden="true"></i> ', ffi18n.copied);
                        setTimeout(function() {
                            fabIconThenText(exportCopy, '<i class="fa-solid fa-copy"></i> ', ffi18n.copy);
                        }, 1500);
                    });
                });
            }
            if (exportClose) { exportClose.addEventListener('click', closeExportModal); }
            if (exportModal) {
                exportModal.addEventListener('click', function(e) {
                    if (e.target === exportModal) closeExportModal();
                });
            }

            /* ── Import ── */
            var importInput  = document.getElementById('fabricator-import-input');
            var importSubmit = document.getElementById('fabricator-import-submit');
            var importNonce  = pageData.importNonce;

            function postImport(str, preview) {
                return fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'fabricator_forms_import',
                        string: str,
                        nonce: importNonce,
                        preview: preview ? '1' : ''
                    })
                })
                .then(function(r) { return r.json(); });
            }

            /* One line per notification: "• Name: a@example.com, {email-1}". Plain text for confirm(), never markup. */
            function describeRecipients(list) {
                return (list || []).map(function(n) {
                    var addresses = (n.recipients || []).join(', ') || ffi18n.importNoAddress;
                    return '• ' + (n.name || ffi18n.importUnnamed) + ': ' + addresses;
                }).join('\n');
            }

            function doImport() {
                var str = importInput ? importInput.value.trim() : '';
                if (!str) return;
                importSubmit.disabled = true;
                /* Step one previews where the notifications send submissions, so a pasted string can't reroute them unseen. */
                postImport(str, true)
                .then(function(preview) {
                    if (!preview.success || !preview.data) {
                        return preview;
                    }
                    var list = describeRecipients(preview.data.recipients);
                    /* Function replacement: a title containing $& or $' would otherwise be expanded by String.replace. */
                    var msg  = ffi18n.importConfirm.replace('%s', function () { return preview.data.title || ''; })
                        + '\n\n' + (list || ffi18n.importNoRecipients);
                    if (!window.confirm(msg)) {
                        return null;
                    }
                    return postImport(str, false);
                })
                .then(function(data) {
                    importSubmit.disabled = false;
                    if (data === null) return;
                    if (data.success && data.data.html) {
                        var tmp = document.createElement('div');
                        tmp.innerHTML = data.data.html.trim();
                        var newRow = tmp.firstElementChild;
                        var list = document.getElementById('fabricator-form-list');
                        if (list && newRow) {
                            list.insertBefore(newRow, list.firstChild);
                            bindRow(newRow);
                            checkFilled();
                        }
                        if (importInput) importInput.value = '';
                    } else {
                        alert((data.data && data.data.message) || ffi18n.importError);
                        if (importInput) importInput.value = '';
                    }
                })
                .catch(function() {
                    importSubmit.disabled = false;
                    alert(ffi18n.importError);
                });
            }

            if (importSubmit) { importSubmit.addEventListener('click', doImport); }
            if (importInput) {
                importInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') doImport();
                });
            }


        }());
