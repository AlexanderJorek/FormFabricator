function (root) {
    root.querySelectorAll('select.fabricator-select').forEach(function (native) {
        if (native.dataset.fabricatorSelectInit) return;
        native.dataset.fabricatorSelectInit = '1';
        native.classList.add('fabricator-select-native');
        /* The native select stays for the form data but is invisible, so it must not be a tab stop of its own or
           be announced twice; the custom combobox below takes its place and its name. */
        native.tabIndex = -1;
        native.setAttribute('aria-hidden', 'true');
        var wrap = document.createElement('div');
        wrap.className = 'fabricator-select-wrap';
        native.parentNode.insertBefore(wrap, native);
        wrap.appendChild(native);
        var custom = document.createElement('div');
        custom.className = 'fabricator-select-custom';
        custom.tabIndex  = 0;
        custom.setAttribute('role', 'combobox');
        custom.setAttribute('aria-expanded', 'false');
        custom.setAttribute('aria-haspopup', 'listbox');
        var display = document.createElement('span');
        display.className = 'fabricator-select-display';
        var arrow = document.createElement('span');
        arrow.className   = 'fabricator-select-arrow';
        arrow.textContent = '▾';
        arrow.setAttribute('aria-hidden', 'true');
        var panel = document.createElement('div');
        panel.className = 'fabricator-select-panel';
        panel.setAttribute('role', 'listbox');
        custom.appendChild(display); custom.appendChild(arrow); custom.appendChild(panel);
        wrap.appendChild(custom);
        /* The field's label points at the hidden select, so its text names the combobox and clicking it focuses it. */
        var labelEl = native.id ? document.querySelector('label[for="' + CSS.escape(native.id) + '"]') : null;
        if (labelEl) {
            if (!labelEl.id) { labelEl.id = native.id + '-label'; }
            custom.setAttribute('aria-labelledby', labelEl.id);
            labelEl.addEventListener('click', function () { custom.focus(); });
        }
        if (native.required) { custom.setAttribute('aria-required', 'true'); }
        function buildOptions() {
            panel.innerHTML = '';
            Array.from(native.options).forEach(function (opt) {
                var item = document.createElement('div');
                item.className = 'fabricator-select-option';
                item.textContent = opt.text;
                item.dataset.value = opt.value;
                item.setAttribute('role', 'option');
                item.id = (native.id || 'fabricator-select') + '-opt-' + opt.index;
                if (!opt.value) item.classList.add('fabricator-select-option--placeholder');
                if (opt.selected) item.classList.add('fabricator-select-option--selected');
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
            panel.querySelectorAll('.fabricator-select-option').forEach(function (el) {
                var isSel = el.dataset.value === native.value;
                el.classList.toggle('fabricator-select-option--selected', isSel);
                /* role="option" without aria-selected leaves a screen reader unable to say which one is current. */
                el.setAttribute('aria-selected', isSel ? 'true' : 'false');
                if (isSel) { custom.setAttribute('aria-activedescendant', el.id); }
            });
            if (sel && sel.value) {
                display.textContent = sel.text;
                display.classList.remove('fabricator-select-display--placeholder');
            } else {
                display.textContent = sel ? sel.text : '';
                display.classList.add('fabricator-select-display--placeholder');
            }
            var otherInput = wrap.nextElementSibling;
            if (otherInput && otherInput.classList.contains('fabricator-other-input')) {
                otherInput.style.display = native.value === '__other__' ? '' : 'none';
            }
        }
        function open() {
            custom.setAttribute('aria-expanded', 'true');
            document.querySelectorAll('.fabricator-select-custom[aria-expanded="true"]').forEach(function (o) {
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
                var dir = e.key === 'ArrowDown' ? 1 : -1;
                var idx = native.selectedIndex + dir;
                if (idx >= 0 && idx < native.options.length) {
                    native.selectedIndex = idx;
                    native.dispatchEvent(new Event('change', { bubbles: true }));
                    syncDisplay();
                }
            }
        });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
        native.addEventListener('change', syncDisplay);
        /* Dispatched by front.js after form.reset(), which puts the native select back without a change event: the
           custom display kept the previous choice, so a second entry could send a value the visitor never saw. */
        var ownerForm = native.closest('form');
        if (ownerForm) ownerForm.addEventListener('fabricator:reset', syncDisplay);
        buildOptions();
    });
}