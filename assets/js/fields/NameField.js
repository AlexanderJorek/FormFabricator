function (root) {
    /* The salutation as a dropdown drawn by the page, like every other dropdown of the form: a native select's open list
       is drawn by the browser, without the form's border and shadow. The native select stays for the form data. */
    root.querySelectorAll('select.fabricator-name-prefix').forEach(function (native) {
        if (native.dataset.fabricatorNamePrefixInit) return;
        native.dataset.fabricatorNamePrefixInit = '1';
        native.classList.add('fabricator-name-prefix-native');
        /* Invisible, so neither a tab stop of its own nor announced twice; the combobox below takes its place. */
        native.tabIndex = -1;
        native.setAttribute('aria-hidden', 'true');
        var wrap = document.createElement('div');
        wrap.className = 'fabricator-name-prefix-wrap';
        native.parentNode.insertBefore(wrap, native);
        wrap.appendChild(native);
        var custom = document.createElement('div');
        custom.className = 'fabricator-name-prefix-custom';
        custom.tabIndex  = 0;
        custom.setAttribute('role', 'combobox');
        custom.setAttribute('aria-expanded', 'false');
        custom.setAttribute('aria-haspopup', 'listbox');
        var display = document.createElement('span');
        display.className = 'fabricator-name-prefix-display';
        var arrow = document.createElement('span');
        arrow.className   = 'fabricator-name-prefix-arrow';
        arrow.textContent = '▾';
        arrow.setAttribute('aria-hidden', 'true');
        var panel = document.createElement('div');
        panel.className = 'fabricator-name-prefix-panel';
        panel.setAttribute('role', 'listbox');
        custom.appendChild(display); custom.appendChild(arrow); custom.appendChild(panel);
        wrap.appendChild(custom);
        /* The sub-label points at the hidden select: its text names the combobox, and clicking it focuses it. */
        var labelEl = native.id ? document.querySelector('label[for="' + CSS.escape(native.id) + '"]') : null;
        if (labelEl) {
            if (!labelEl.id) { labelEl.id = native.id + '-label'; }
            custom.setAttribute('aria-labelledby', labelEl.id);
            labelEl.addEventListener('click', function () { custom.focus(); });
        } else if (native.getAttribute('aria-label')) {
            custom.setAttribute('aria-label', native.getAttribute('aria-label'));
        }
        if (native.required) { custom.setAttribute('aria-required', 'true'); }
        function buildOptions() {
            panel.innerHTML = '';
            Array.from(native.options).forEach(function (opt) {
                var item = document.createElement('div');
                item.className = 'fabricator-name-prefix-option';
                item.textContent = opt.text;
                item.dataset.value = opt.value;
                item.setAttribute('role', 'option');
                item.id = (native.id || 'fabricator-name-prefix') + '-opt-' + opt.index;
                if (!opt.value) item.classList.add('fabricator-name-prefix-option--placeholder');
                item.addEventListener('click', function (e) {
                    e.stopPropagation();
                    native.value = opt.value;
                    native.dispatchEvent(new Event('change', { bubbles: true }));
                    close();
                });
                panel.appendChild(item);
            });
            syncDisplay();
        }
        function syncDisplay() {
            var sel = native.options[native.selectedIndex];
            panel.querySelectorAll('.fabricator-name-prefix-option').forEach(function (el) {
                var isSel = el.dataset.value === native.value;
                el.classList.toggle('fabricator-name-prefix-option--selected', isSel);
                /* Without aria-selected a screen reader can't say which option is current. */
                el.setAttribute('aria-selected', isSel ? 'true' : 'false');
                if (isSel) { custom.setAttribute('aria-activedescendant', el.id); }
            });
            display.textContent = sel ? sel.text : '';
            display.classList.toggle('fabricator-name-prefix-display--placeholder', !(sel && sel.value));
        }
        function open() {
            custom.setAttribute('aria-expanded', 'true');
            document.querySelectorAll('.fabricator-name-prefix-custom[aria-expanded="true"]').forEach(function (o) {
                if (o !== custom) o.setAttribute('aria-expanded', 'false');
            });
        }
        function close() { custom.setAttribute('aria-expanded', 'false'); }
        custom.addEventListener('click', function () {
            custom.getAttribute('aria-expanded') === 'true' ? close() : open();
        });
        custom.addEventListener('keydown', function (e) {
            var isOpen = custom.getAttribute('aria-expanded') === 'true';
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); isOpen ? close() : open(); }
            else if (e.key === 'Escape') { close(); }
            else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                var idx = native.selectedIndex + (e.key === 'ArrowDown' ? 1 : -1);
                if (idx >= 0 && idx < native.options.length) {
                    native.selectedIndex = idx;
                    native.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
        native.addEventListener('change', syncDisplay);
        /* front.js dispatches this after form.reset(), which puts the native select back without a change event. */
        var ownerForm = native.closest('form');
        if (ownerForm) ownerForm.addEventListener('fabricator:reset', syncDisplay);
        buildOptions();
    });
}
