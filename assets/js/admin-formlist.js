/*!
 * FormFabricator — Forms list admin page
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
        (function() {

            /* Localized strings for dynamically-generated UI text (alerts, toasts,
               modal messages set from JS) — mirrors the wp_localize_script i18n
               pattern used elsewhere (e.g. Assets.php's ForgeVerifier), inlined here
               since this script is embedded directly rather than a static asset. */
            var pageData = window.ForgeFormListPage || {};
            var ffi18n = pageData.i18n || {};

            /* ── Dropdown: hoist to body, smart flip up/down ── */
            var openDd = null;

            function closeAllDropdowns() {
                if (openDd) { openDd.hidden = true; openDd = null; }
            }

            document.addEventListener('click', closeAllDropdowns);
            document.addEventListener('scroll', closeAllDropdowns, true);

            /* ── Live search ── */
            var searchInput = document.getElementById('forge-form-search');
            var noResults   = document.getElementById('forge-no-results');

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var q = this.value.toLowerCase().trim();
                    var visible = 0;
                    document.querySelectorAll('.forge-form-row').forEach(function(row) {
                        var title = row.dataset.title || '';
                        var show  = !q || title.indexOf(q) !== -1;
                        row.hidden = !show;
                        if (show) visible++;
                    });
                    if (noResults) noResults.hidden = visible > 0;
                });
            }

            /* ── Multi-select ── */
            var selectAll   = document.getElementById('forge-select-all');
            var bulkBar       = document.getElementById('forge-bulk-bar');
            var bulkCount     = document.getElementById('forge-bulk-count');
            var bulkApply     = document.getElementById('forge-bulk-apply');
            var bulkActionBtn = document.getElementById('forge-bulk-action-btn');
            var bulkActionDd  = document.getElementById('forge-bulk-action-dd');
            var bulkActionLbl = document.getElementById('forge-bulk-action-label');
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
                bulkActionDd.querySelectorAll('.forge-dd-item[data-action]').forEach(function(item) {
                    item.addEventListener('click', function() {
                        selectedBulkAction = this.dataset.action;
                        bulkActionLbl.textContent = this.textContent.trim();
                        closeAllDropdowns();
                    });
                });
            }

            function getChecked() {
                return document.querySelectorAll('.forge-row-check:checked');
            }

            var emptyState   = document.getElementById('forge-list-empty');
            var listToolbar  = document.getElementById('forge-list-toolbar');
            var deleteModal  = document.getElementById('forge-delete-modal');
            var modalMsg     = document.getElementById('forge-modal-msg');
            var modalConfirm = document.getElementById('forge-modal-confirm');
            var modalCancel  = document.getElementById('forge-modal-cancel');
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
                if (document.querySelectorAll('.forge-form-row').length === 0) {
                    if (emptyState) emptyState.hidden = false;
                    var tl = document.getElementById('forge-toolbar-left');
                    var tc = document.getElementById('forge-toolbar-center');
                    if (tl) tl.hidden = true;
                    if (tc) tc.hidden = true;
                }
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
                    var all = document.querySelectorAll('.forge-row-check');
                    selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
                    selectAll.checked = all.length > 0 && checked.length === all.length;
                }
            }

            /* ── Per-row event binding (also called after dynamic injection) ── */
            function bindRow(row) {
                /* Grab all references before dd is hoisted to body */
                var menuBtn = row.querySelector('.forge-row-menu-btn');
                var copyBtn = row.querySelector('.forge-copy-shortcode');
                var dupBtn  = row.querySelector('.forge-duplicate-form');
                var delBtn  = row.querySelector('.forge-delete-form');
                var expBtn  = row.querySelector('.forge-export-form');
                var cb      = row.querySelector('.forge-row-check');

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
                            copyBtn.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> ' + ffi18n.copied;
                            setTimeout(function() { copyBtn.innerHTML = '<i class="fa-regular fa-clipboard" aria-hidden="true"></i> ' + ffi18n.copyShortcode; }, 1500);
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
                                action: 'forge_forms_duplicate',
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
                                var list = document.getElementById('forge-form-list');
                                if (list && newRow) {
                                    list.insertBefore(newRow, list.firstChild);
                                    bindRow(newRow);
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
                                    action: 'forge_forms_delete',
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
                                action: 'forge_forms_export',
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

            document.querySelectorAll('.forge-form-row').forEach(bindRow);

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    document.querySelectorAll('.forge-row-check').forEach(function(cb) {
                        if (!cb.closest('.forge-form-row').hidden) cb.checked = selectAll.checked;
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
                                action: 'forge_forms_bulk_delete',
                                ids: JSON.stringify(ids),
                                nonces: JSON.stringify(nonces)
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                (data.data.deleted || []).forEach(function(id) {
                                    var cb  = document.querySelector('.forge-row-check[value="' + id + '"]');
                                    var row = cb ? cb.closest('.forge-form-row') : null;
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
                                action: 'forge_forms_bulk_duplicate',
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
            var exportModal   = document.getElementById('forge-export-modal');
            var exportLoading = document.getElementById('forge-export-loading');
            var exportResult  = document.getElementById('forge-export-result');
            var exportBar     = document.getElementById('forge-export-bar');
            var exportString  = document.getElementById('forge-export-string');
            var exportCopy    = document.getElementById('forge-export-copy');
            var exportClose   = document.getElementById('forge-export-close');
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
                        exportCopy.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> ' + ffi18n.copied;
                        setTimeout(function() {
                            exportCopy.innerHTML = '<i class="fa-solid fa-copy"></i> ' + ffi18n.copy;
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
            var importInput  = document.getElementById('forge-import-input');
            var importSubmit = document.getElementById('forge-import-submit');
            var importNonce  = pageData.importNonce;

            function doImport() {
                var str = importInput ? importInput.value.trim() : '';
                if (!str) return;
                importSubmit.disabled = true;
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'forge_forms_import',
                        string: str,
                        nonce: importNonce
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    importSubmit.disabled = false;
                    if (data.success && data.data.html) {
                        var tmp = document.createElement('div');
                        tmp.innerHTML = data.data.html.trim();
                        var newRow = tmp.firstElementChild;
                        var list = document.getElementById('forge-form-list');
                        if (list && newRow) {
                            list.insertBefore(newRow, list.firstChild);
                            bindRow(newRow);
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
