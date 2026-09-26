function (root) {
    root.querySelectorAll('.fabricator-upload-zone').forEach(function (zone) {
        /* Idempotent: front.js re-runs field inits after every successful submit, which stacked drop/change/reset listeners. */
        if (zone._fabricatorUploadInited) return;
        zone._fabricatorUploadInited = true;
        var input    = zone.querySelector('.fabricator-upload-input');
        var errEl    = zone.parentNode
            ? zone.parentNode.querySelector('.fabricator-upload-error') : null;
        var listEl   = zone.parentNode
            ? zone.parentNode.querySelector('.fabricator-upload-filelist') : null;
        var multiple = zone.dataset.multiple === '1';
        var maxFiles = parseInt(zone.dataset.maxFiles || '0', 10);

        function showError(msg) {
            if (!errEl) return;
            errEl.textContent = msg;
            errEl.style.color = '';
        }
        function showNotice(msg) {
            if (!errEl) return;
            errEl.textContent = msg;
            errEl.style.color = 'var(--fabricator-warning, #996600)';
        }
        function clearError() {
            if (!errEl) return;
            errEl.textContent = '';
            errEl.style.color = '';
        }
        function publishCount(n) {
            zone.dataset.fabricatorFileCount = String(n);
        }
        function renderChips(files) {
            if (!listEl) return;
            listEl.innerHTML = '';
            clearError();
            if (!files || !files.length) { publishCount(0); return; }
            publishCount(files.length);
            Array.from(files).forEach(function (file, idx) {
                var li   = document.createElement('li');
                li.className = 'fabricator-upload-chip';
                var nm  = document.createElement('span');
                nm.className   = 'fabricator-upload-chip-name';
                nm.textContent = file.name;
                nm.title       = file.name;
                var btn = document.createElement('button');
                btn.type      = 'button';
                btn.className = 'fabricator-upload-chip-remove';
                var _i18n = window.FabricatorForms && window.FabricatorForms.i18n;
                btn.setAttribute(
                    'aria-label', ((_i18n && _i18n.upload_remove_prefix) || 'Remove: ') + file.name
                );
                btn.textContent = '×';
                btn.addEventListener('click', function () {
                    try {
                        var dt = new DataTransfer();
                        Array.from(input.files).forEach(function (f, i) {
                            if (i !== idx) dt.items.add(f);
                        });
                        input.files = dt.files;
                        renderChips(input.files);
                    } catch (e) { /* DataTransfer not supported */ }
                });
                li.appendChild(nm);
                li.appendChild(btn);
                listEl.appendChild(li);
            });
        }
        function filterAllowed(fileList) {
            var accept = (input ? input.accept : '') || '';
            if (!accept) return Array.from(fileList);
            var parts = accept.split(',').map(function (s) {
                return s.trim().toLowerCase();
            });
            return Array.from(fileList).filter(function (f) {
                var ext  = '.' + f.name.split('.').pop().toLowerCase();
                var mime = (f.type || '').toLowerCase();
                return parts.some(function (p) {
                    if (p.charAt(0) === '.') return p === ext;
                    if (p.slice(-2) === '/*') {
                        return mime.indexOf(p.slice(0, -1)) === 0;
                    }
                    return p === mime;
                });
            });
        }
        function checkLimit(files) {
            if (maxFiles > 0 && files.length > maxFiles) {
                var _i18nL = window.FabricatorForms && window.FabricatorForms.i18n;
                showError(
                    (_i18nL && _i18nL.upload_too_many)
                        ? _i18nL.upload_too_many.replace('%d', maxFiles)
                        : 'Too many files. Maximum ' + maxFiles + ' allowed.'
                );
                return false;
            }
            return true;
        }
        function applyFiles(fileList) {
            var all     = Array.from(fileList);
            var allowed = filterAllowed(fileList);
            if (!multiple && allowed.length > 1) {
                allowed = [allowed[0]];
            }
            /* The browser has already put the new selection in the input, so a rejected one has to be cleared:
               it used to stay behind and be submitted while the chips still showed the previous selection. */
            function clearInput() {
                try {
                    input.files = new DataTransfer().files;
                } catch (e) { /* DataTransfer not supported */ }
                renderChips(input.files);
            }
            if (!allowed.length) {
                var _i18nA = window.FabricatorForms && window.FabricatorForms.i18n;
                clearInput();
                showError((_i18nA && _i18nA.upload_no_types) || 'No allowed file types in selection.');
                return;
            }
            if (!checkLimit(allowed)) {
                clearInput();
                return;
            }
            try {
                var dt = new DataTransfer();
                allowed.forEach(function (f) { dt.items.add(f); });
                input.files = dt.files;
                renderChips(input.files);
                var skipped = all.length - allowed.length;
                if (skipped > 0) {
                    var _i18nS = window.FabricatorForms && window.FabricatorForms.i18n;
                    var _skippedMsg = skipped === 1
                        ? ((_i18nS && _i18nS.upload_skipped_one) || '1 file was skipped due to file type.')
                        : ((_i18nS && _i18nS.upload_skipped_many) || '%d files were skipped due to file type.').replace('%d', skipped);
                    showNotice(_skippedMsg);
                }
            } catch (e) { /* DataTransfer not supported */ }
        }
        publishCount(0);
        if (input) {
            input.addEventListener('change', function () {
                applyFiles(this.files);
            });
            var form = input.closest('form');
            if (form) {
                form.addEventListener('reset', function () {
                    renderChips(null);
                });
                if (!form.dataset.fabricatorOverflowBound) {
                    form.dataset.fabricatorOverflowBound = '1';
                    form.addEventListener('fabricator:upload-overflow', function (ev) {
                        var firstField = null;
                        form.querySelectorAll('.fabricator-upload-zone').forEach(
                            function (z) {
                                var zi = z.querySelector('.fabricator-upload-input');
                                var ze = z.parentNode
                                    ? z.parentNode.querySelector(
                                        '.fabricator-upload-error'
                                    ) : null;
                                if (!ze || !zi || !zi.files || !zi.files.length) {
                                    return;
                                }
                                var _i18nO = window.FabricatorForms && window.FabricatorForms.i18n;
                                ze.textContent = (_i18nO && _i18nO.upload_overflow)
                                    ? _i18nO.upload_overflow.replace('%1$d', ev.detail.total).replace('%2$d', ev.detail.max)
                                    : 'Too many files total (' + ev.detail.total + '). Max. ' + ev.detail.max + ' per submission.';
                                if (!firstField) {
                                    firstField = z.closest('.fabricator-field') || z;
                                }
                            }
                        );
                        if (firstField) {
                            var top = firstField.getBoundingClientRect().top
                                + window.pageYOffset - 80;
                            window.scrollTo(0, Math.max(0, top));
                            firstField.setAttribute('tabindex', '-1');
                            firstField.focus({ preventScroll: true });
                        }
                    });
                }
            }
        }
        zone.addEventListener('dragover', function (e) {
            e.preventDefault();
            zone.classList.add('fabricator-upload-zone--drag');
        });
        zone.addEventListener('dragleave', function () {
            zone.classList.remove('fabricator-upload-zone--drag');
        });
        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.classList.remove('fabricator-upload-zone--drag');
            if (!input || !e.dataTransfer.files.length) return;
            applyFiles(e.dataTransfer.files);
        });
    });
}