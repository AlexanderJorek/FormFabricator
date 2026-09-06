function (root) {
    root.querySelectorAll('.fabricator-page-header').forEach(function (headerEl) {
        if (headerEl.dataset.fabricatorStepsInit) return;
        headerEl.dataset.fabricatorStepsInit = '1';

        var form = headerEl.closest('.fabricator-form');
        if (!form) return;

        var pages = Array.from(form.querySelectorAll('.fabricator-form-page'));
        var total = pages.length;
        if (!total) return;

        /* Must run before front.js's initPageBreaks() relocates this row to page 0, while headerEl is still in its original page. */
        var containingPage = headerEl.closest('.fabricator-form-page');
        var nativePage     = containingPage ? pages.indexOf(containingPage) : 0;
        if (nativePage === -1) nativePage = 0;
        var stepCount = Math.max(0, total - nativePage);

        var showNames = headerEl.dataset.showNames === '1';
        var names     = [];
        try { names = JSON.parse(headerEl.dataset.names || '[]'); } catch (e) { names = []; }

        var stepsWrap = document.createElement('div');
        stepsWrap.className = 'fabricator-page-steps';
        for (var i = 0; i < stepCount; i++) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fabricator-page-step';
            btn.setAttribute('data-fabricator-goto-page', String(nativePage + i));

            var numSpan = document.createElement('span');
            numSpan.className = 'fabricator-page-step-num';
            numSpan.textContent = String(i + 1);
            btn.appendChild(numSpan);

            if (showNames && names[i]) {
                var nameSpan = document.createElement('span');
                nameSpan.className = 'fabricator-page-step-name';
                // textContent, not innerHTML — names[i] is server-sanitized
                // (wp_strip_all_tags) but this is the real XSS boundary,
                // not the PHP-side stripping.
                nameSpan.textContent = String(names[i]);
                btn.appendChild(nameSpan);
            }

            stepsWrap.appendChild(btn);
        }
        headerEl.appendChild(stepsWrap);

        function update(idx, furthest) {
            headerEl.style.display = idx >= nativePage ? '' : 'none';
            Array.from(stepsWrap.children).forEach(function (btn, i) {
                var pageIdx = nativePage + i;
                btn.classList.toggle('is-active', pageIdx === idx);
                btn.classList.toggle('is-reachable', pageIdx <= furthest);
                btn.disabled = pageIdx > furthest;
            });
        }

        form.addEventListener('fabricator:page-change', function (e) {
            update(e.detail.index, e.detail.furthest);
        });
        update(0, 0);
    });
}