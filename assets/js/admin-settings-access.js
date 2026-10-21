/*!
 * FormFabricator — Settings page: user access matrix (AccessSettings)
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';

    var pageData = window.FabricatorAccessSettings || {};
    var I18N   = pageData.i18n   || {};
    var DATA   = pageData.data   || {};

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

            /* Asks the server for matching users as the admin types (AccessSettings::handleAccessUserSearch()), so the
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
}());
