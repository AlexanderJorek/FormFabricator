function (root) {
    root.querySelectorAll('select.fabricator-select').forEach(function (native) {
        if (native.dataset.fabricatorSelectInit) return;
        native.dataset.fabricatorSelectInit = '1';
        native.classList.add('fabricator-select-native');
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
        function buildOptions() {
            panel.innerHTML = '';
            Array.from(native.options).forEach(function (opt) {
                var item = document.createElement('div');
                item.className = 'fabricator-select-option';
                item.textContent = opt.text;
                item.dataset.value = opt.value;
                item.setAttribute('role', 'option');
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
                el.classList.toggle('fabricator-select-option--selected', el.dataset.value === native.value);
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
        buildOptions();
    });
}