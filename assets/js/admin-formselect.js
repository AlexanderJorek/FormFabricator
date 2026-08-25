/*!
 * FormFabricator — Form Select List admin page
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
        (function () {
            var pageData  = window.ForgeFormSelectPage || {};
            var ajaxurl   = pageData.ajaxUrl;
            var fseli18n  = pageData.i18n || {};
            var saveNonce = pageData.saveNonce;
            var allForms  = pageData.allForms || [];
            var fselData  = pageData.fselData || [];

            var list         = document.getElementById('forge-fsel-list');
            var emptyEl      = document.getElementById('forge-fsel-empty');
            var toolbarLeft  = document.getElementById('forge-fsel-toolbar-left');
            var toolbarCenter = document.getElementById('forge-fsel-toolbar-center');

            /* ── modal elements ── */
            var modal      = document.getElementById('forge-fsel-modal');
            var modalTitle = document.getElementById('forge-fsel-modal-title');
            var titleInput = document.getElementById('forge-fsel-title-input');
            var searchInput  = document.getElementById('forge-fsel-search');
            var searchResults = document.getElementById('forge-fsel-search-results');
            var itemsEl    = document.getElementById('forge-fsel-items');
            var saveBtn    = document.getElementById('forge-fsel-save');
            var cancelBtn  = document.getElementById('forge-fsel-cancel');

            /* ── delete modal ── */
            var delModal   = document.getElementById('forge-fsel-del-modal');
            var delConfirm = document.getElementById('forge-fsel-del-confirm');
            var delCancel  = document.getElementById('forge-fsel-del-cancel');

            var currentId  = 0;
            var favName    = 'fsel-fav-modal';

            /* ── helpers ── */
            function post(action, body, cb) {
                var fd = new FormData();
                fd.append('action', action);
                for (var k in body) { fd.append(k, body[k]); }
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(cb)
                    .catch(function () { cb({ success: false }); });
            }

            function getChecked() {
                return list.querySelectorAll('.forge-row-check:checked');
            }

            function syncBulkBar() {
                var checked = getChecked();
                var bulkBar = document.getElementById('forge-fsel-bulk-bar');
                var countEl = document.getElementById('forge-fsel-bulk-count');
                if (bulkBar) { bulkBar.hidden = checked.length === 0; }
                if (countEl) { countEl.textContent = fseli18n.selectedCount.replace('%d', String(checked.length)); }
            }

            function syncEmpty() {
                var rows = list.querySelectorAll('.forge-form-row');
                var isEmpty = rows.length === 0;
                emptyEl.hidden = !isEmpty;
                if (toolbarLeft)   { toolbarLeft.hidden   = isEmpty; }
                if (toolbarCenter) { toolbarCenter.hidden = isEmpty; }
                if (isEmpty) { syncBulkBar(); }
            }

            function escHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            var colHeader      = document.getElementById('forge-fsel-col-header');
            var addBtn         = document.getElementById('forge-fsel-add-btn');
            var searchWrap     = document.getElementById('forge-fsel-search-wrap');

            function syncColHeader() {
                if (colHeader) { colHeader.hidden = itemsEl.children.length === 0; }
            }

            function openSearch() {
                var rect = addBtn.getBoundingClientRect();
                if (window.innerHeight - rect.bottom > 260) {
                    searchWrap.style.top = 'calc(100% + 4px)';
                    searchWrap.style.bottom = 'auto';
                } else {
                    searchWrap.style.bottom = 'calc(100% + 4px)';
                    searchWrap.style.top = 'auto';
                }
                searchWrap.hidden = false;
                searchInput.value = '';
                doSearch('');
                searchInput.focus();
            }
            function closeSearch() {
                searchWrap.hidden = true;
            }

            addBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                searchWrap.hidden ? openSearch() : closeSearch();
            });
            document.addEventListener('click', function (e) {
                if (!searchWrap.hidden && !searchWrap.contains(e.target) && e.target !== addBtn) {
                    closeSearch();
                }
            });

            /* ── open editor modal ── */
            function openModal(id) {
                currentId = id;
                itemsEl.innerHTML = '';
                closeSearch();

                if (id === 0) {
                    modalTitle.textContent = fseli18n.createTitle;
                    titleInput.value = fseli18n.newSelectionName;
                    saveBtn.textContent = fseli18n.create;
                } else {
                    modalTitle.textContent = fseli18n.editTitle;
                    saveBtn.textContent = fseli18n.save;
                    var rec = fselData.find(function (r) { return r.id === id; });
                    if (rec) {
                        titleInput.value = rec.title;
                        (rec.items || []).forEach(function (item) {
                            var form = allForms.find(function (f) { return f.id === item.form_id; });
                            addItem(item.form_id, form ? form.title : '#' + item.form_id,
                                    item.label, item.description, item.favorite);
                        });
                    }
                }

                syncColHeader();
                modal.hidden = false;
                titleInput.focus();
            }

            function closeModal() {
                modal.hidden = true;
                closeSearch();
            }

            /* ── items ── */
            function addItem(formId, formTitle, label, desc, fav) {
                if (itemsEl.querySelector('[data-form-id="' + formId + '"]')) { return; }

                var div = document.createElement('div');
                div.className = 'forge-fsel-item';
                div.dataset.formId = formId;

                var isChecked = fav ? 'checked' : '';
                div.innerHTML =
                    '<span class="forge-fsel-item-drag"><i class="fa-solid fa-grip-vertical"></i></span>' +
                    '<span class="forge-fsel-item-badge" title="' + escHtml(formTitle) + '">' +
                        escHtml(formTitle) +
                    '</span>' +
                    '<input type="text" class="forge-fsel-item-label" ' +
                           'value="' + escHtml(label || '') + '" ' +
                           'placeholder="' + escHtml(fseli18n.label) + '">' +
                    '<input type="text" class="forge-fsel-item-desc" ' +
                           'value="' + escHtml(desc || '') + '" ' +
                           'placeholder="' + escHtml(fseli18n.description) + '">' +
                    '<label class="forge-fsel-item-fav" title="' + escHtml(fseli18n.preselectDefault) + '">' +
                        '<input type="radio" name="' + favName + '" ' +
                               'class="forge-fsel-item-fav-radio" ' + isChecked + '>' +
                        '<i class="' + (isChecked ? 'fa-solid' : 'fa-regular') + ' fa-star"></i>' +
                    '</label>' +
                    '<button type="button" class="forge-fsel-item-remove" title="' + escHtml(fseli18n.remove) + '">' +
                        '<i class="fa-solid fa-trash"></i>' +
                    '</button>';

                div.querySelector('.forge-fsel-item-remove').addEventListener('click', function () {
                    div.remove();
                    syncColHeader();
                });
                div.querySelector('.forge-fsel-item-fav-radio').addEventListener('change', function () {
                    itemsEl.querySelectorAll('.forge-fsel-item-fav-radio').forEach(function (r) {
                        var icon = r.nextElementSibling;
                        if (!icon) { return; }
                        icon.className = r.checked ? 'fa-solid fa-star' : 'fa-regular fa-star';
                    });
                });
                itemsEl.appendChild(div);
                syncColHeader();
            }

            /* ── form search (client-side) ── */
            var srchTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(srchTimer);
                srchTimer = setTimeout(function () { doSearch(searchInput.value.trim()); }, 150);
            });

            function getAddedIds() {
                var ids = {};
                itemsEl.querySelectorAll('.forge-fsel-item').forEach(function (el) {
                    ids[el.dataset.formId] = true;
                });
                return ids;
            }

            function doSearch(q) {
                var added = getAddedIds();
                var ql = q.toLowerCase();
                var hits = allForms.filter(function (f) {
                    if (added[f.id]) { return false; }
                    return q === '' ||
                           f.title.toLowerCase().indexOf(ql) !== -1 ||
                           String(f.id).indexOf(q) !== -1;
                }).slice(0, 15);
                showResults(hits);
            }

            function showResults(forms) {
                searchResults.innerHTML = '';
                if (forms.length === 0) {
                    var empty = document.createElement('div');
                    empty.className = 'forge-fsel-sr-empty';
                    empty.textContent = fseli18n.noFormsFound;
                    searchResults.appendChild(empty);
                } else {
                    forms.forEach(function (f) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'forge-fsel-sr-item';
                        btn.textContent = f.title;
                        btn.addEventListener('click', function () {
                            addItem(f.id, f.title, '', '', false);
                            closeSearch();
                        });
                        searchResults.appendChild(btn);
                    });
                }
                searchResults.hidden = false;
            }

            /* ── save ── */
            saveBtn.addEventListener('click', function () {
                saveBtn.disabled = true;
                var title = titleInput.value.trim();
                var items = [];
                itemsEl.querySelectorAll('.forge-fsel-item').forEach(function (el) {
                    items.push({
                        form_id:     parseInt(el.dataset.formId, 10),
                        label:       el.querySelector('.forge-fsel-item-label').value.trim(),
                        description: el.querySelector('.forge-fsel-item-desc').value.trim(),
                        favorite:    el.querySelector('.forge-fsel-item-fav-radio').checked,
                    });
                });

                post('forge_fsel_save', {
                    nonce: saveNonce,
                    id:    currentId,
                    title: title,
                    items: JSON.stringify(items),
                }, function (data) {
                    saveBtn.disabled = false;
                    if (!data.success) { return; }

                    /* update local data cache */
                    var idx = fselData.findIndex(function (r) { return r.id === data.data.id; });
                    var rec = { id: data.data.id, title: data.data.title, items: data.data.items };
                    if (idx >= 0) { fselData[idx] = rec; } else { fselData.push(rec); }

                    /* inject / replace row */
                    var tmp = document.createElement('div');
                    tmp.innerHTML = data.data.html;
                    var newRow = tmp.firstElementChild;
                    wireRow(newRow);

                    if (data.data.is_new) {
                        list.insertBefore(newRow, list.firstChild);
                    } else {
                        var old = list.querySelector('.forge-form-row[data-id="' + currentId + '"]');
                        if (old) { old.replaceWith(newRow); }
                    }

                    syncEmpty();
                    closeModal();
                });
            });

            cancelBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) { closeModal(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) { closeModal(); }
            });

            /* ── delete modal ── */
            var pendingDelId    = 0;
            var pendingDelNonce = '';

            function openDelModal(id, nonce) {
                pendingDelId    = id;
                pendingDelNonce = nonce;
                delModal.hidden = false;
            }

            delCancel.addEventListener('click', function () { delModal.hidden = true; });
            delModal.addEventListener('click', function (e) {
                if (e.target === delModal) { delModal.hidden = true; }
            });
            delConfirm.addEventListener('click', function () {
                delConfirm.disabled = true;
                post('forge_fsel_delete', { nonce: pendingDelNonce, id: pendingDelId },
                function (data) {
                    delConfirm.disabled = false;
                    if (!data.success) { return; }
                    var row = list.querySelector('.forge-form-row[data-id="' + pendingDelId + '"]');
                    if (row) { row.remove(); }
                    fselData = fselData.filter(function (r) { return r.id !== pendingDelId; });
                    delModal.hidden = true;
                    syncEmpty();
                });
            });

            /* ── row dropdown (hoisted to body, same pattern as FormList) ── */
            var openDd = null;
            function closeAllDropdowns() {
                if (openDd) { openDd.hidden = true; openDd = null; }
            }

            function wireDropdown(row) {
                var btn = row.querySelector('.forge-row-menu-btn');
                if (!btn) { return; }
                var dd = btn.nextElementSibling;
                document.body.appendChild(dd);

                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var alreadyOpen = (openDd === dd && !dd.hidden);
                    closeAllDropdowns();
                    if (alreadyOpen) { return; }

                    var rect = btn.getBoundingClientRect();
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

            document.addEventListener('click', closeAllDropdowns);

            /* ── wire a row's actions ── */
            function wireRow(row) {
                var cb = row.querySelector('.forge-row-check');
                if (cb) { cb.addEventListener('change', function () { syncBulkBar(); }); }

                /* edit button + clickable title */
                row.querySelectorAll('.forge-fsel-edit-btn, .forge-fsel-edit-link')
                   .forEach(function (el) {
                    el.addEventListener('click', function () {
                        openModal(parseInt(row.dataset.id, 10));
                        closeAllDropdowns();
                    });
                });

                /* delete */
                var delBtn = row.querySelector('.forge-fsel-del-btn');
                if (delBtn) {
                    delBtn.addEventListener('click', function () {
                        closeAllDropdowns();
                        openDelModal(
                            parseInt(delBtn.dataset.id, 10),
                            delBtn.dataset.nonce
                        );
                    });
                }

                /* copy shortcode */
                var copyBtn = row.querySelector('.forge-copy-shortcode');
                if (copyBtn) {
                    copyBtn.addEventListener('click', function () {
                        closeAllDropdowns();
                        navigator.clipboard && navigator.clipboard.writeText(copyBtn.dataset.code);
                    });
                }

                wireDropdown(row);
            }

            /* ── "New" buttons ── */
            document.querySelectorAll('.forge-fsel-new-btn').forEach(function (btn) {
                btn.addEventListener('click', function () { openModal(0); });
            });

            /* ── drag-to-reorder for fsel items ── */
            (function () {
                var ghost = null, ph = null, startY = 0, offY = 0;

                function getItems() {
                    return Array.from(itemsEl.querySelectorAll('.forge-fsel-item'));
                }

                itemsEl.addEventListener('pointerdown', function (e) {
                    var handle = e.target.closest('.forge-fsel-item-drag');
                    if (!handle) { return; }
                    var item = handle.closest('.forge-fsel-item');
                    if (!item) { return; }

                    e.preventDefault();
                    startY = e.clientY;
                    var rect = item.getBoundingClientRect();
                    offY = e.clientY - rect.top;

                    /* placeholder keeps the space */
                    ph = document.createElement('div');
                    ph.style.cssText = 'height:' + rect.height + 'px;margin-bottom:4px;' +
                        'border:2px dashed #c3c4c7;border-radius:6px;box-sizing:border-box;';
                    item.after(ph);

                    /* ghost floats under cursor */
                    ghost = item;
                    ghost.style.cssText = 'position:fixed;left:' + rect.left + 'px;' +
                        'top:' + (e.clientY - offY) + 'px;width:' + rect.width + 'px;' +
                        'z-index:200000;opacity:.9;pointer-events:none;box-shadow:0 4px 16px rgba(0,0,0,.18);';

                    document.body.appendChild(ghost);

                    function onMove(ev) {
                        ghost.style.top = (ev.clientY - offY) + 'px';

                        /* find sibling to insert before */
                        var siblings = getItems();
                        var insertBefore = null;
                        for (var i = 0; i < siblings.length; i++) {
                            var sr = siblings[i].getBoundingClientRect();
                            if (ev.clientY < sr.top + sr.height / 2) {
                                insertBefore = siblings[i];
                                break;
                            }
                        }
                        if (insertBefore) {
                            itemsEl.insertBefore(ph, insertBefore);
                        } else {
                            itemsEl.appendChild(ph);
                        }
                    }

                    function onUp() {
                        document.removeEventListener('pointermove', onMove);
                        document.removeEventListener('pointerup', onUp);

                        /* restore item to placeholder position */
                        ghost.style.cssText = '';
                        ph.replaceWith(ghost);
                        ph = null;
                        ghost = null;
                    }

                    document.addEventListener('pointermove', onMove);
                    document.addEventListener('pointerup', onUp);
                });
            }());

            /* ── live search ── */
            var searchInput = document.getElementById('forge-fsel-form-search');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    var q = this.value.toLowerCase().trim();
                    list.querySelectorAll('.forge-form-row').forEach(function (row) {
                        row.hidden = q !== '' && row.dataset.title.indexOf(q) === -1;
                    });
                    var noRes = document.getElementById('forge-fsel-no-results');
                    if (noRes) {
                        noRes.hidden = list.querySelectorAll('.forge-form-row:not([hidden])').length > 0;
                    }
                });
            }

            /* ── select-all ── */
            var selectAll = document.getElementById('forge-fsel-select-all');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    list.querySelectorAll('.forge-row-check').forEach(function (cb) {
                        cb.checked = selectAll.checked;
                    });
                    syncBulkBar();
                });
            }
            list.addEventListener('change', function (e) {
                if (e.target.classList.contains('forge-row-check')) {
                    if (selectAll && !e.target.checked) { selectAll.checked = false; }
                    syncBulkBar();
                }
            });

            /* ── bulk action dropdown ── */
            var bulkActionBtn = document.getElementById('forge-fsel-bulk-action-btn');
            var bulkActionDd  = document.getElementById('forge-fsel-bulk-action-dd');
            var bulkApply     = document.getElementById('forge-fsel-bulk-apply');
            var bulkAction    = 'delete';

            if (bulkActionBtn && bulkActionDd) {
                bulkActionBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    bulkActionDd.hidden = !bulkActionDd.hidden;
                });
                document.addEventListener('click', function () { bulkActionDd.hidden = true; });
                bulkActionDd.querySelectorAll('.forge-dd-item').forEach(function (item) {
                    item.addEventListener('click', function () {
                        bulkAction = item.dataset.action;
                        bulkActionDd.hidden = true;
                    });
                });
            }

            if (bulkApply) {
                bulkApply.addEventListener('click', function () {
                    if (bulkAction !== 'delete') { return; }
                    var checked = Array.from(getChecked());
                    if (checked.length === 0) { return; }
                    var remaining = checked.length;
                    checked.forEach(function (cb) {
                        post('forge_fsel_delete', { nonce: cb.dataset.delNonce, id: cb.value },
                        function (data) {
                            if (data.success) {
                                var row = list.querySelector('.forge-form-row[data-id="' + cb.value + '"]');
                                if (row) { row.remove(); }
                                fselData = fselData.filter(function (r) {
                                    return r.id !== parseInt(cb.value, 10);
                                });
                            }
                            remaining--;
                            if (remaining === 0) { syncEmpty(); }
                        });
                    });
                });
            }

            /* ── wire existing rows ── */
            list.querySelectorAll('.forge-form-row').forEach(wireRow);

            syncEmpty();
        }());
