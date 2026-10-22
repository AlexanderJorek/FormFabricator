/*!
 * FormFabricator — form builder: settings controls (the sp*() builders)
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
/*
 * A part of the form builder (admin-builder.js), loaded before it. The builder calls it with the names it
 * shares (B) and takes back the functions it adds there.
 */
window.FabricatorBuilderParts = window.FabricatorBuilderParts || [];
window.FabricatorBuilderParts.push(function (B) {
'use strict';

var mergeI18nMap = B.mergeI18nMap,
    makeSortable = B.makeSortable,
    mkSeg = B.mkSeg,
    isUnsafeUrl = B.isUnsafeUrl,
    slugify = B.slugify,
    escHtml = B.escHtml,
    _i18n = B._i18n,
    state = B.state;

/* Settings panel element builders */
function spRow(parent, key, label, type, value, onChange, hint) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('label');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'fabricator-sp-' + key;
    row.appendChild(lbl);
    /* 'textarea' creates a real <textarea>: <input type="textarea"> falls back to a single-line text input that drops line breaks. */
    var inp = document.createElement(type === 'textarea' ? 'textarea' : 'input');
    if (type === 'textarea') {
        inp.rows = 3;
    } else {
        inp.type = type || 'text';
    }
    inp.id        = 'fabricator-sp-' + key;
    inp.className = 'fabricator-sp-input';
    inp.value     = value !== null && value !== undefined ? String(value) : '';
    inp.addEventListener('input', function () { onChange(this.value); });
    row.appendChild(inp);
    if (hint) {
        var h = document.createElement('p');
        h.className   = 'fabricator-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

function spMediaUpload(parent, key, label, value, onChange, hint) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('label');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'fabricator-sp-' + key;
    row.appendChild(lbl);

    var wrap = document.createElement('div');
    wrap.className = 'fabricator-sp-media-wrap';

    var inp = document.createElement('input');
    inp.type        = 'text';
    inp.id          = 'fabricator-sp-' + key;
    inp.className   = 'fabricator-sp-input fabricator-sp-media-url';
    inp.value       = value || '';
    inp.placeholder = 'https://…';
    inp.addEventListener('input', function () { onChange(this.value); updateThumb(); });
    wrap.appendChild(inp);

    var btn = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'button fabricator-sp-media-btn';
    btn.innerHTML = '<i class="fa-solid fa-photo-film"></i> ' + escHtml(_i18n.mediaLibrary || 'Media library');
    btn.addEventListener('click', function () {
        if (typeof wp === 'undefined' || !wp.media) {
            var u = prompt(_i18n.imageUrlPrompt || 'Image URL:');
            if (u && !isUnsafeUrl(u, false)) { inp.value = u; onChange(u); updateThumb(); }
            return;
        }
        var frame = wp.media({ title: label, button: { text: _i18n.useButtonLabel || 'Use' }, multiple: false, library: { type: 'image' } });
        frame.on('open', function () {
            var modal   = document.querySelector('.media-modal');
            var overlay = document.querySelector('.media-modal-backdrop');
            if (modal)   modal.style.zIndex   = '200000';
            if (overlay) overlay.style.zIndex = '199999';
        });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            inp.value = att.url;
            onChange(att.url);
            updateThumb();
        });
        frame.open();
    });
    wrap.appendChild(btn);

    var thumb = document.createElement('img');
    thumb.className     = 'fabricator-sp-media-thumb';
    thumb.alt           = '';
    thumb.style.display = value ? 'block' : 'none';
    if (value) { thumb.src = value; }
    wrap.appendChild(thumb);

    function updateThumb() {
        var url = inp.value.trim();
        thumb.src           = url;
        thumb.style.display = url ? 'block' : 'none';
    }

    row.appendChild(wrap);

    if (hint) {
        var h = document.createElement('p');
        h.className   = 'fabricator-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

function spTextarea(parent, key, label, value, onChange, hint) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('label');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'fabricator-sp-' + key;
    row.appendChild(lbl);
    var ta = document.createElement('textarea');
    ta.id        = 'fabricator-sp-' + key;
    ta.className = 'fabricator-sp-input';
    ta.rows      = 4;
    ta.value     = value || '';
    ta.addEventListener('input', function () { onChange(this.value); });
    row.appendChild(ta);
    if (hint) {
        var h = document.createElement('p');
        h.className   = 'fabricator-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

/* A page break before the bar's own position doesn't count, so an entry splash page can precede it unnumbered. */
function spPageNamesList(parent, key, label, values, onChange, hint, fieldIdx) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);

    var pageBreaksFromHere = state.fields
        .slice(fieldIdx)
        .filter(function (f) { return f.type === 'pagebreak'; }).length;
    var pageCount = pageBreaksFromHere + 1;
    var names     = values.slice(0, pageCount);
    while (names.length < pageCount) { names.push(''); }

    var listEl = document.createElement('div');
    listEl.className = 'fabricator-sp-page-names-list';
    for (var i = 0; i < pageCount; i++) {
        (function (idx) {
            var itemRow = document.createElement('div');
            itemRow.className = 'fabricator-sp-page-name-row';

            var numTag = document.createElement('span');
            numTag.className   = 'fabricator-sp-page-name-num';
            numTag.textContent = String(idx + 1);
            itemRow.appendChild(numTag);

            var inp = document.createElement('input');
            inp.type        = 'text';
            inp.className   = 'fabricator-sp-input';
            inp.value       = names[idx] || '';
            inp.placeholder = (_i18n.pageNumber || 'Page %d').replace('%d', String(idx + 1));
            inp.addEventListener('input', function () {
                names[idx] = this.value;
                onChange(names.slice());
            });
            itemRow.appendChild(inp);

            listEl.appendChild(itemRow);
        }(i));
    }
    row.appendChild(listEl);

    if (hint) {
        var h = document.createElement('p');
        h.className   = 'fabricator-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

/* Toggle switch — used for all simple on/off settings fields */
function spInfoIcon(text) {
    var wrap = document.createElement('span');
    wrap.className = 'fabricator-info-icon';
    wrap.setAttribute('aria-label', text);
    wrap.setAttribute('role', 'button');
    wrap.setAttribute('tabindex', '0');
    var icon = document.createElement('i');
    icon.className = 'fa-solid fa-circle-info';
    wrap.appendChild(icon);

    // Appended to <body>, so the settings modal's overflow:hidden can't clip it.
    var tip = document.createElement('span');
    tip.className   = 'fabricator-info-tooltip';
    tip.textContent = text;
    document.body.appendChild(tip);

    var open = false;
    function position() {
        var r = wrap.getBoundingClientRect();
        // Render off-screen first so its real height can be measured before placing it.
        tip.style.left = '-9999px';
        tip.style.top  = '-9999px';
        tip.classList.add('fabricator-info-tooltip--visible');
        var tipRect = tip.getBoundingClientRect();
        tip.style.top  = (r.top - tipRect.height - 6) + 'px';
        tip.style.left = (r.right - tipRect.width) + 'px';
    }
    function show() { position(); }
    function hide() { tip.classList.remove('fabricator-info-tooltip--visible'); open = false; }

    wrap.addEventListener('mouseenter', show);
    wrap.addEventListener('mouseleave', hide);
    wrap.addEventListener('focus',      show);
    wrap.addEventListener('blur',       hide);
    wrap.addEventListener('click', function (e) {
        e.preventDefault();
        open = !open;
        open ? show() : hide();
    });
    wrap.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            open = !open;
            open ? show() : hide();
        }
        if (e.key === 'Escape') { hide(); }
    });

    // Tooltip lives on <body>, not inside `wrap` — remove it when the icon is removed or it leaks a detached node.
    var observer = new MutationObserver(function () {
        if (!document.body.contains(wrap)) {
            tip.remove();
            observer.disconnect();
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    return wrap;
}

function spCheckbox(parent, key, label, checked, onChange, disclaimer) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-toggle-row';

    var toggleLbl = document.createElement('label');
    toggleLbl.className = 'fabricator-toggle';
    toggleLbl.htmlFor   = 'fabricator-sp-' + key;

    var inp = document.createElement('input');
    inp.type    = 'checkbox';
    inp.id      = 'fabricator-sp-' + key;
    inp.checked = !!checked;
    inp.addEventListener('change', function () { onChange(this.checked); });
    toggleLbl.appendChild(inp);

    var slider = document.createElement('span');
    slider.className = 'fabricator-toggle-slider';
    toggleLbl.appendChild(slider);

    var textLbl = document.createElement('label');
    textLbl.className   = 'fabricator-toggle-label';
    textLbl.htmlFor     = 'fabricator-sp-' + key;
    textLbl.textContent = label;
    if (disclaimer) {
        textLbl.appendChild(spInfoIcon(disclaimer));
    }

    row.appendChild(toggleLbl);
    row.appendChild(textLbl);
    parent.appendChild(row);
}

function spCheckboxHint(parent, key, label, hint, checked, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'fabricator-sp-toggle-row fabricator-sp-toggle-row--hint';

    var left = document.createElement('div');
    left.className = 'fabricator-sp-toggle-row-text';
    var textLbl = document.createElement('label');
    textLbl.className   = 'fabricator-toggle-label';
    textLbl.htmlFor     = 'fabricator-sp-' + key;
    textLbl.textContent = label;
    left.appendChild(textLbl);
    if (hint) {
        var hintEl = document.createElement('div');
        hintEl.className   = 'fabricator-sp-hint';
        hintEl.textContent = hint;
        left.appendChild(hintEl);
    }

    var toggleLbl = document.createElement('label');
    toggleLbl.className = 'fabricator-toggle';
    toggleLbl.htmlFor   = 'fabricator-sp-' + key;
    var inp = document.createElement('input');
    inp.type    = 'checkbox';
    inp.id      = 'fabricator-sp-' + key;
    inp.checked = !!checked;
    inp.addEventListener('change', function () { onChange(this.checked); });
    toggleLbl.appendChild(inp);
    var slider = document.createElement('span');
    slider.className = 'fabricator-toggle-slider';
    toggleLbl.appendChild(slider);

    wrap.appendChild(left);
    wrap.appendChild(toggleLbl);
    parent.appendChild(wrap);
}

function spSelect(parent, key, label, options, value, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('label');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'fabricator-sp-' + key;
    row.appendChild(lbl);
    var sel = document.createElement('select');
    sel.id        = 'fabricator-sp-' + key;
    sel.className = 'fabricator-sp-input';
    options.forEach(function (opt) {
        var o = document.createElement('option');
        o.value       = opt.value !== undefined ? opt.value : opt;
        o.textContent = opt.label !== undefined ? opt.label : opt;
        if (String(o.value) === String(value)) o.selected = true;
        sel.appendChild(o);
    });
    sel.addEventListener('change', function () { onChange(this.value); });
    row.appendChild(sel);
    parent.appendChild(row);
}

/* English literal fallback; runtime values come from window.FabricatorBuilderI18n.countryNames. */
var COUNTRY_NAMES_EN = {
    AD:'Andorra',AL:'Albania',AT:'Austria',BE:'Belgium',BG:'Bulgaria',
    CH:'Switzerland',CY:'Cyprus',CZ:'Czechia',DE:'Germany',DK:'Denmark',
    EE:'Estonia',ES:'Spain',FI:'Finland',FR:'France',GB:'United Kingdom',
    GI:'Gibraltar',GR:'Greece',HR:'Croatia',HU:'Hungary',IE:'Ireland',
    IS:'Iceland',IT:'Italy',LI:'Liechtenstein',LT:'Lithuania',LU:'Luxembourg',
    LV:'Latvia',MC:'Monaco',MD:'Moldova',ME:'Montenegro',MK:'North Macedonia',
    MT:'Malta',NL:'Netherlands',NO:'Norway',PL:'Poland',PT:'Portugal',
    RO:'Romania',RS:'Serbia',SE:'Sweden',SI:'Slovenia',SK:'Slovakia',
    SM:'San Marino',VA:'Vatican City'
};
var COUNTRY_NAMES = mergeI18nMap(COUNTRY_NAMES_EN, (window.FabricatorBuilderI18n && window.FabricatorBuilderI18n.countryNames) || {});

function spCountryTags(parent, key, label, values, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);

    /* Unified box: chips + search input inline; clicking anywhere focuses the search input. */
    var box = document.createElement('div');
    box.className = 'fabricator-ctag-box';

    var search = document.createElement('input');
    search.type        = 'text';
    search.placeholder = _i18n.searchCountry || 'Search and add country…';
    search.className   = 'fabricator-ctag-search';
    search.autocomplete = 'off';

    var dropdown = document.createElement('div');
    dropdown.className = 'fabricator-ctag-dropdown';
    dropdown.hidden    = true;

    /* Clicking the box background focuses the search input */
    box.addEventListener('mousedown', function (e) {
        if (e.target === box) { e.preventDefault(); search.focus(); }
    });

    var current = values.slice();

    function chip(cc) {
        var c = document.createElement('span');
        c.className = 'fabricator-ctag-chip';

        var code = document.createElement('span');
        code.className   = 'fabricator-ctag-chip-code';
        code.textContent = cc;
        c.appendChild(code);

        var name = document.createElement('span');
        name.className   = 'fabricator-ctag-chip-name';
        name.textContent = COUNTRY_NAMES[cc] || '';
        c.appendChild(name);

        var rm = document.createElement('button');
        rm.type      = 'button';
        rm.className = 'fabricator-ctag-rm';
        rm.setAttribute('aria-label', _i18n.removeAriaLabel || 'Remove');
        rm.innerHTML = '&times;';
        rm.addEventListener('mousedown', function (e) {
            e.preventDefault();
            current = current.filter(function (x) { return x !== cc; });
            render();
            onChange(current.slice());
        });
        c.appendChild(rm);
        return c;
    }

    function render() {
        /* Clear everything except search and dropdown */
        Array.from(box.children).forEach(function (el) {
            if (el !== search && el !== dropdown) box.removeChild(el);
        });
        /* Insert chips before search */
        current.forEach(function (cc) {
            box.insertBefore(chip(cc), search);
        });
        search.placeholder = current.length ? '' : (_i18n.searchCountry || 'Search and add country…');
    }

    function positionDropdown() {
        var r          = box.getBoundingClientRect();
        var spaceBelow = window.innerHeight - r.bottom - 8;
        var spaceAbove = r.top - 8;

        dropdown.style.left  = r.left + 'px';
        dropdown.style.width = r.width + 'px';

        if (spaceBelow >= spaceAbove) {
            /* open downward, hard-cap height to available space */
            dropdown.style.top       = (r.bottom + 4) + 'px';
            dropdown.style.bottom    = '';
            dropdown.style.maxHeight = Math.min(220, spaceBelow) + 'px';
        } else {
            /* flip upward, cap to space above */
            dropdown.style.top       = '';
            dropdown.style.bottom    = (window.innerHeight - r.top + 4) + 'px';
            dropdown.style.maxHeight = Math.min(220, spaceAbove) + 'px';
        }
    }

    function renderDropdown(query) {
        dropdown.innerHTML = '';
        var q = query.trim().toUpperCase();
        var matches = Object.keys(COUNTRY_NAMES).filter(function (cc) {
            if (current.indexOf(cc) !== -1) return false;
            if (!q) return true;
            return cc.indexOf(q) !== -1 ||
                COUNTRY_NAMES[cc].toUpperCase().indexOf(q) !== -1;
        }).slice(0, 10);

        if (!matches.length) { dropdown.hidden = true; return; }

        matches.forEach(function (cc) {
            var opt = document.createElement('div');
            opt.className = 'fabricator-ctag-opt';
            var codeSpan = document.createElement('span');
            codeSpan.className   = 'fabricator-ctag-opt-code';
            codeSpan.textContent = cc;
            var nameSpan = document.createElement('span');
            nameSpan.className   = 'fabricator-ctag-opt-name';
            nameSpan.textContent = COUNTRY_NAMES[cc] || '';
            opt.appendChild(codeSpan);
            opt.appendChild(nameSpan);
            opt.addEventListener('mousedown', function (e) {
                e.preventDefault();
                if (current.indexOf(cc) === -1) current.push(cc);
                search.value = '';
                render();
                onChange(current.slice());
                search.focus();
                renderDropdown('');
            });
            dropdown.appendChild(opt);
        });
        positionDropdown();
        dropdown.hidden = false;
    }

    search.addEventListener('input', function () { renderDropdown(this.value); });
    search.addEventListener('focus', function () { renderDropdown(this.value); });
    search.addEventListener('blur',  function () {
        setTimeout(function () {
            dropdown.hidden = true;
        }, 150);
    });

    /* Reposition on scroll/resize; remove dropdown from DOM on row removal */
    function onScroll() { if (!dropdown.hidden) positionDropdown(); }
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);

    var observer = new MutationObserver(function () {
        if (!document.body.contains(box)) {
            dropdown.remove();
            observer.disconnect();
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    box.appendChild(search);
    document.body.appendChild(dropdown); /* fixed-position, outside scroll container */
    render();

    row.appendChild(box);
    parent.appendChild(row);
}

/* Stored value is the code string e.g. '+49'. English literal fallback; runtime values come from window.FabricatorBuilderI18n.phoneCodes. */
var PHONE_CALLING_CODES_EN = {
    '+1':   'USA / Canada',   '+7':   'Russia',         '+20':  'Egypt',
    '+27':  'South Africa',   '+30':  'Greece',         '+31':  'Netherlands',
    '+32':  'Belgium',        '+33':  'France',         '+34':  'Spain',
    '+36':  'Hungary',        '+39':  'Italy',          '+40':  'Romania',
    '+41':  'Switzerland',    '+43':  'Austria',        '+44':  'United Kingdom',
    '+45':  'Denmark',        '+46':  'Sweden',         '+47':  'Norway',
    '+48':  'Poland',         '+49':  'Germany',        '+51':  'Peru',
    '+52':  'Mexico',         '+54':  'Argentina',      '+55':  'Brazil',
    '+56':  'Chile',          '+57':  'Colombia',       '+61':  'Australia',
    '+62':  'Indonesia',      '+63':  'Philippines',    '+64':  'New Zealand',
    '+65':  'Singapore',      '+66':  'Thailand',       '+81':  'Japan',
    '+82':  'South Korea',    '+84':  'Vietnam',        '+86':  'China',
    '+90':  'Turkey',         '+91':  'India',          '+92':  'Pakistan',
    '+94':  'Sri Lanka',      '+98':  'Iran',           '+212': 'Morocco',
    '+213': 'Algeria',        '+216': 'Tunisia',        '+220': 'Gambia',
    '+234': 'Nigeria',        '+254': 'Kenya',          '+351': 'Portugal',
    '+352': 'Luxembourg',     '+353': 'Ireland',        '+354': 'Iceland',
    '+356': 'Malta',          '+357': 'Cyprus',         '+358': 'Finland',
    '+359': 'Bulgaria',       '+370': 'Lithuania',      '+371': 'Latvia',
    '+372': 'Estonia',        '+380': 'Ukraine',        '+385': 'Croatia',
    '+386': 'Slovenia',       '+420': 'Czechia',        '+421': 'Slovakia',
    '+423': 'Liechtenstein'
};
var PHONE_CALLING_CODES = mergeI18nMap(PHONE_CALLING_CODES_EN, (window.FabricatorBuilderI18n && window.FabricatorBuilderI18n.phoneCodes) || {});

function spCallingCodeTags(parent, key, label, values, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label || '';
    row.appendChild(lbl);

    var box = document.createElement('div');
    box.className = 'fabricator-ctag-box';

    var search = document.createElement('input');
    search.type         = 'text';
    search.placeholder  = (_i18n.searchDialCode || 'Search dial code (+49)…');
    search.className    = 'fabricator-ctag-search';
    search.autocomplete = 'off';

    var dropdown = document.createElement('div');
    dropdown.className = 'fabricator-ctag-dropdown';
    dropdown.hidden    = true;

    box.addEventListener('mousedown', function (e) {
        if (e.target === box) { e.preventDefault(); search.focus(); }
    });

    var current = values.slice();

    function chip(code) {
        var c = document.createElement('span');
        c.className = 'fabricator-ctag-chip';

        var codeSpan = document.createElement('span');
        codeSpan.className   = 'fabricator-ctag-chip-code';
        codeSpan.textContent = code;
        c.appendChild(codeSpan);

        var nameSpan = document.createElement('span');
        nameSpan.className   = 'fabricator-ctag-chip-name';
        nameSpan.textContent = PHONE_CALLING_CODES[code] || '';
        c.appendChild(nameSpan);

        var rm = document.createElement('button');
        rm.type      = 'button';
        rm.className = 'fabricator-ctag-rm';
        rm.setAttribute('aria-label', _i18n.removeAriaLabel || 'Remove');
        rm.innerHTML = '&times;';
        rm.addEventListener('mousedown', function (e) {
            e.preventDefault();
            current = current.filter(function (x) { return x !== code; });
            render();
            onChange(current.slice());
        });
        c.appendChild(rm);
        return c;
    }

    function render() {
        Array.from(box.children).forEach(function (el) {
            if (el !== search && el !== dropdown) box.removeChild(el);
        });
        current.forEach(function (code) { box.insertBefore(chip(code), search); });
        search.placeholder = current.length ? '' : (_i18n.searchDialCode || 'Search dial code (+49)…');
    }

    function positionDropdown() {
        var r = box.getBoundingClientRect();
        var spaceBelow = window.innerHeight - r.bottom - 8;
        var spaceAbove = r.top - 8;
        dropdown.style.left  = r.left + 'px';
        dropdown.style.width = r.width + 'px';
        if (spaceBelow >= spaceAbove) {
            dropdown.style.top       = (r.bottom + 4) + 'px';
            dropdown.style.bottom    = '';
            dropdown.style.maxHeight = Math.min(220, spaceBelow) + 'px';
        } else {
            dropdown.style.top       = '';
            dropdown.style.bottom    = (window.innerHeight - r.top + 4) + 'px';
            dropdown.style.maxHeight = Math.min(220, spaceAbove) + 'px';
        }
    }

    function renderDropdown(query) {
        dropdown.innerHTML = '';
        var q = query.trim().toLowerCase();
        var matches = Object.keys(PHONE_CALLING_CODES).filter(function (code) {
            if (current.indexOf(code) !== -1) return false;
            if (!q) return true;
            return code.indexOf(q) !== -1 ||
                PHONE_CALLING_CODES[code].toLowerCase().indexOf(q) !== -1;
        }).slice(0, 10);

        if (!matches.length) { dropdown.hidden = true; return; }

        matches.forEach(function (code) {
            var opt = document.createElement('div');
            opt.className = 'fabricator-ctag-opt';
            var cSpan = document.createElement('span');
            cSpan.className   = 'fabricator-ctag-opt-code';
            cSpan.textContent = code;
            var nSpan = document.createElement('span');
            nSpan.className   = 'fabricator-ctag-opt-name';
            nSpan.textContent = PHONE_CALLING_CODES[code] || '';
            opt.appendChild(cSpan);
            opt.appendChild(nSpan);
            opt.addEventListener('mousedown', function (e) {
                e.preventDefault();
                if (current.indexOf(code) === -1) current.push(code);
                search.value = '';
                render();
                onChange(current.slice());
                search.focus();
                renderDropdown('');
            });
            dropdown.appendChild(opt);
        });
        positionDropdown();
        dropdown.hidden = false;
    }

    search.addEventListener('input', function () { renderDropdown(this.value); });
    search.addEventListener('focus', function () { renderDropdown(this.value); });
    search.addEventListener('blur',  function () {
        setTimeout(function () { dropdown.hidden = true; }, 150);
    });

    function onScroll() { if (!dropdown.hidden) positionDropdown(); }
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);

    var observer = new MutationObserver(function () {
        if (!document.body.contains(box)) {
            dropdown.remove();
            observer.disconnect();
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    box.appendChild(search);
    document.body.appendChild(dropdown);
    render();

    row.appendChild(box);
    parent.appendChild(row);
}


/* Options list editor */
function spOptionsList(parent, key, label, values, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var titleRow = document.createElement('div');
    titleRow.className = 'fabricator-sp-label';
    titleRow.textContent = label;
    row.appendChild(titleRow);

    /* Copies each {value, label, default} so edits don't mutate the field config until onChange. */
    var opts = values.map(function (v) {
        v = v || {};
        return { value: v.value || '', label: v.label || '', 'default': !!v['default'] };
    });

    var colHeader = document.createElement('div');
    colHeader.className = 'fabricator-sp-option-cols-header';
    colHeader.innerHTML =
        '<span></span>' +
        '<span>' + escHtml(_i18n.optionLabelPlaceholder || 'Label') + '</span>' +
        '<span>' + escHtml(_i18n.valueWord || 'Value') + '</span>' +
        '<span title="' + escHtml(_i18n.preselected || 'Preselected') + '"><i class="fa-regular fa-star"></i></span>' +
        '<span></span>';
    row.appendChild(colHeader);

    var listEl = document.createElement('div');
    listEl.className = 'fabricator-sp-options-list';

    function rebuild() {
        listEl.innerHTML = '';
        opts.forEach(function (opt, i) {
            var optRow = document.createElement('div');
            optRow.className = 'fabricator-sp-option-row';

            /* Drag handle */
            var handle = document.createElement('span');
            handle.className = 'fabricator-sp-opt-handle';
            handle.innerHTML = '<i class="fa-solid fa-grip-vertical"></i>';
            optRow.appendChild(handle);

            var labelInp = document.createElement('input');
            labelInp.type        = 'text';
            labelInp.className   = 'fabricator-sp-opt-label';
            labelInp.value       = opt.label;
            labelInp.placeholder = _i18n.optionLabelPlaceholder || 'Label';
            labelInp.addEventListener('input', function () {
                opts[i].label = this.value;
                /* The value follows the label until it is edited by hand. */
                if (!opts[i].value || opts[i].value === slugify(this.value.slice(0, -1)) || opts[i].value === 'option-' + (i + 1)) {
                    opts[i].value = slugify(this.value) || ('option-' + (i + 1));
                    var vi = optRow.querySelector('.fabricator-sp-opt-value');
                    if (vi) vi.value = opts[i].value;
                }
                onChange(opts.slice());
            });
            optRow.appendChild(labelInp);

            var valueInp = document.createElement('input');
            valueInp.type        = 'text';
            valueInp.className   = 'fabricator-sp-opt-value';
            valueInp.value       = opt.value;
            valueInp.placeholder = _i18n.optionValuePlaceholder || 'value';
            valueInp.addEventListener('input', function () {
                opts[i].value = this.value;
                onChange(opts.slice());
            });
            optRow.appendChild(valueInp);

            /* Default-selected star toggle */
            var starBtn = document.createElement('button');
            starBtn.type      = 'button';
            starBtn.className = 'fabricator-sp-opt-star' + (opt['default'] ? ' fabricator-sp-opt-star--active' : '');
            starBtn.title     = _i18n.preselected || 'Preselected';
            starBtn.innerHTML = opt['default']
                ? '<i class="fa-solid fa-star"></i>'
                : '<i class="fa-regular fa-star"></i>';
            starBtn.addEventListener('click', function () {
                var isNowActive = !opts[i]['default'];
                opts.forEach(function (o) { o['default'] = false; });
                opts[i]['default'] = isNowActive;
                rebuild();
                onChange(opts.slice());
            });
            optRow.appendChild(starBtn);

            var rm = document.createElement('button');
            rm.type      = 'button';
            rm.className = 'fabricator-sp-option-remove';
            rm.innerHTML = '<i class="fa-solid fa-trash"></i>';
            rm.addEventListener('click', function () { opts.splice(i, 1); rebuild(); onChange(opts.slice()); });
            optRow.appendChild(rm);

            optRow.dataset.optIdx = String(i);
            listEl.appendChild(optRow);
        });
    }
    rebuild();
    makeSortable(listEl, '.fabricator-sp-opt-handle', '.fabricator-sp-option-row', function (rows) {
        var reordered = rows.map(function (r) { return opts[parseInt(r.dataset.optIdx, 10)]; });
        opts.length = 0;
        reordered.forEach(function (o) { opts.push(o); });
        rebuild();
        onChange(opts.slice());
    });
    row.appendChild(listEl);

    var addBtn = document.createElement('button');
    addBtn.type      = 'button';
    addBtn.className = 'fabricator-sp-add-option';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> ' + escHtml(_i18n.addOption || 'Add option');
    addBtn.addEventListener('click', function () {
        var n = opts.length + 1;
        opts.push({ value: 'option-' + n, label: 'Option ' + n, 'default': false });
        rebuild();
        onChange(opts.slice());
        var inputs = listEl.querySelectorAll('.fabricator-sp-opt-label');
        if (inputs.length) inputs[inputs.length - 1].focus();
    });
    row.appendChild(addBtn);
    parent.appendChild(row);
}

/* Labeled segmented control for a boolean field value */
function spBoolSeg(parent, key, label, value, falseLabel, trueLabel, onChange, swap) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);
    var vals   = swap ? ['1', '0'] : ['0', '1'];
    var labels = swap ? [trueLabel, falseLabel] : [falseLabel, trueLabel];
    row.appendChild(mkSeg(vals, labels, value ? '1' : '0', function (v) { onChange(v === '1'); }));
    parent.appendChild(row);
}

function spSectionTitle(parent, text) {
    var t = document.createElement('div');
    t.className   = 'fabricator-sp-section-title';
    t.textContent = text;
    parent.appendChild(t);
}

function spLimitRow(parent, typeKey, label, countKey, typeVal, countVal, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';
    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);
    var inner = document.createElement('div');
    inner.className = 'fabricator-sp-limit-row';
    var pill = mkSeg(['chars', 'words'], [_i18n.unitChars || 'Characters', _i18n.unitWords || 'Words'], typeVal || 'chars', function (v) {
        onChange(v, inp.value);
    });
    inner.appendChild(pill);
    var inp = document.createElement('input');
    inp.type        = 'number';
    inp.min         = '0';
    /* Must match BaseField::OTHER_TEXT_HARD_CAP — a value configured above this
       is silently clamped server-side, so don't let the admin set one here. */
    inp.max         = '5000';
    inp.className   = 'fabricator-sp-input';
    inp.value       = countVal !== null && countVal !== undefined ? String(countVal) : '';
    inp.placeholder = '∞';
    inp.addEventListener('input', function () {
        var curType = pill.querySelector('.fabricator-seg-btn.fabricator-seg-active');
        onChange(curType ? curType.dataset.value : 'chars', this.value);
    });
    inner.appendChild(inp);
    row.appendChild(inner);
    parent.appendChild(row);
}

function spNotice(parent, text, level) {
    var el = document.createElement('div');
    el.className   = 'fabricator-sp-notice fabricator-sp-notice--' + (level || 'info');
    el.textContent = text;
    parent.appendChild(el);
}

/* HTML/Section editor: textarea, optionally with a Code|Vorschau toggle */
function spHtmlEditor(parent, key, label, value, onChange, opts) {
    opts = opts || {};
    var showToggle = opts.showToggle !== false;

    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var hdr = document.createElement('div');
    hdr.className = 'fabricator-sp-html-hdr';

    if (label) {
        var lbl = document.createElement('div');
        lbl.className   = 'fabricator-sp-label';
        lbl.textContent = label;
        hdr.appendChild(lbl);
    }

    var ta = document.createElement('textarea');
    ta.className  = 'fabricator-sp-input fabricator-sp-html-textarea';
    ta.rows       = 8;
    ta.value      = value || '';
    ta.spellcheck = false;

    var previewDiv;
    if (showToggle) {
        var modeBar = document.createElement('div');
        modeBar.className = 'fabricator-sp-html-modebar';
        var modePill = mkSeg(['code', 'preview'], [_i18n.modeCode || 'Code', _i18n.modePreview || 'Preview'], 'code', switchMode);
        modeBar.appendChild(modePill);
        hdr.appendChild(modeBar);

        // Sandboxed iframe (no allow-scripts), not innerHTML — avoids a stored self-XSS between admin accounts.
        previewDiv = document.createElement('iframe');
        previewDiv.className = 'fabricator-sp-html-preview';
        previewDiv.setAttribute('sandbox', 'allow-same-origin');
        previewDiv.hidden    = true;
        previewDiv.srcdoc    = value || '';
    }

    if (hdr.children.length) row.appendChild(hdr);

    var resizeTimer = null;
    function resizeTa() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            ta.style.height = 'auto';
            ta.style.height = Math.max(ta.scrollHeight, 140) + 'px';
        }, 30);
    }

    ta.addEventListener('input', function () {
        onChange(this.value);
        if (previewDiv && !previewDiv.hidden) previewDiv.srcdoc = this.value;
        resizeTa();
    });
    row.appendChild(ta);
    if (previewDiv) row.appendChild(previewDiv);

    ta.style.overflowY = 'hidden';
    ta.style.resize     = 'none';
    resizeTa();
    if (window.IntersectionObserver) {
        var taIo = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { if (entry.isIntersecting) resizeTa(); });
        });
        taIo.observe(row);
    }

    function switchMode(mode) {
        if (mode === 'preview') {
            ta.hidden = true;
            previewDiv.hidden = false;
            previewDiv.srcdoc = ta.value;
        } else {
            ta.hidden = false;
            previewDiv.hidden = true;
        }
    }

    parent.appendChild(row);
}

/* designMode, not contenteditable — contenteditable strips the <html>/<head>/<body> wrapper. */
/* HTML field editor: Visuell|Code toggle — same pattern as buildNotifContentTab */
function spHtmlFieldEditor(parent, key, label, value, onChange) {
    var currentValue = value || '';
    var currentMode  = 'text'; /* 'text' = visual iframe, 'html' = plain code textarea */

    /* Header row: label + Visuell|Code pill */
    var hdrWrap = document.createElement('div');
    hdrWrap.className = 'fabricator-sp-row';
    var hdr = document.createElement('div');
    hdr.className = 'fabricator-sp-html-hdr';
    if (label) {
        var lbl = document.createElement('div');
        lbl.className   = 'fabricator-sp-label';
        lbl.textContent = label;
        hdr.appendChild(lbl);
    }
    var modeBar = document.createElement('div');
    modeBar.className = 'fabricator-sp-html-modebar';
    modeBar.appendChild(mkSeg(['text', 'html'], [_i18n.modeVisual || 'Visual', _i18n.modeCode || 'Code'], 'text', function (v) {
        currentMode = v;
        renderEditor();
    }));
    hdr.appendChild(modeBar);
    hdrWrap.appendChild(hdr);
    parent.appendChild(hdrWrap);

    /* Swap container — editor re-renders here on mode change */
    var contentWrap = document.createElement('div');
    parent.appendChild(contentWrap);

    function renderEditor() {
        contentWrap.innerHTML = '';
        if (currentMode === 'html') {
            spHtmlEditor(contentWrap, key, '', currentValue, function (v) {
                currentValue = v;
                onChange(v);
            }, { showToggle: false });
        } else {
            // fragmentOnly: stores an HTML fragment, not a full document (unlike the notification-body editor).
            spRichTextEditor(contentWrap, key, '', currentValue, function (v) {
                currentValue = v;
                onChange(v);
            }, { fragmentOnly: true });
        }
    }

    renderEditor();
}

function spRichTextEditor(parent, key, label, value, onChange, opts) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    if (label) {
        var lbl = document.createElement('div');
        lbl.className   = 'fabricator-sp-label';
        lbl.textContent = label;
        row.appendChild(lbl);
    }

    var toolbar = document.createElement('div');
    toolbar.className = 'fabricator-sp-richtext-toolbar';
    var commands = [
        ['bold', _i18n.rtBold || 'Bold', 'fa-bold'],
        ['italic', _i18n.rtItalic || 'Italic', 'fa-italic'],
        ['underline', _i18n.rtUnderline || 'Underline', 'fa-underline'],
        ['insertUnorderedList', _i18n.rtBulletList || 'List', 'fa-list-ul'],
        ['insertOrderedList', _i18n.rtNumberedList || 'Numbered list', 'fa-list-ol'],
        ['createLink', _i18n.rtLink || 'Link', 'fa-link'],
    ];
    commands.forEach(function (cmd) {
        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'fabricator-sp-richtext-btn';
        btn.title     = cmd[1];
        btn.innerHTML = '<i class="fa-solid ' + cmd[2] + '"></i>';
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function () {
            var doc = iframe.contentDocument;
            iframe.contentWindow.focus();
            if (cmd[0] === 'createLink') {
                var url = window.prompt(_i18n.linkUrlPrompt || 'Enter link URL:', 'https://');
                if (!url || isUnsafeUrl(url, false)) return;
                doc.execCommand('createLink', false, url);
            } else {
                doc.execCommand(cmd[0], false, null);
            }
            emitChange();
        });
        toolbar.appendChild(btn);
    });
    row.appendChild(toolbar);

    var iframe = document.createElement('iframe');
    iframe.className = 'fabricator-sp-input fabricator-sp-richtext-editor';
    iframe.setAttribute('sandbox', 'allow-same-origin');
    iframe.setAttribute('title', label || (_i18n.message || 'Message'));
    row.appendChild(iframe);
    parent.appendChild(row);

    function emitChange() {
        var out = (opts && opts.fragmentOnly)
            ? sanitizeRichDocFragment(iframe.contentDocument)
            : sanitizeRichDoc(iframe.contentDocument);
        onChange(out);
        resize();
    }

    var resizeTimer = null;
    function resize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(doResize, 30);
    }

    function doResize() {
        var doc = iframe.contentDocument;
        if (!doc || !doc.documentElement || !doc.body) return;
        /* scroll metrics are unreliable with overflow:hidden containers, so measure real element bottom edges instead. */
        var max = 0;
        var nodes = doc.body.querySelectorAll('*');
        for (var i = 0; i < nodes.length; i++) {
            var bottom = nodes[i].getBoundingClientRect().bottom;
            if (bottom > max) max = bottom;
        }
        var bodyRect = doc.body.getBoundingClientRect();
        if (bodyRect.bottom > max) max = bodyRect.bottom;

        var fallback = Math.max(
            doc.documentElement.scrollHeight,
            doc.documentElement.offsetHeight,
            doc.body.scrollHeight,
            doc.body.offsetHeight
        );
        var h = Math.max(max, fallback);
        iframe.style.height = Math.max(Math.ceil(h) + 2, 140) + 'px';
    }

    function loadDoc(html) {
        var doc = iframe.contentDocument;
        if (!doc) {
            /* iframe is in a detached subtree — defer until caller inserts the row into the live DOM. */
            setTimeout(function () { loadDoc(html); }, 0);
            return;
        }
        // id="fabricator-editor-preview-style" is display-only; cleanRichDoc() strips it before saving.
        var hasHtmlDoc = /<html[\s>]/i.test(html || '');
        var full = hasHtmlDoc
            ? html
            : '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
                + '<body>' + (html || '') + '</body></html>';
        doc.open();
        doc.write(full);
        doc.close();
        doc.designMode = 'on';
        doc.addEventListener('input', emitChange);

        // A saved doc has it stripped, so re-inject it.
        if (!doc.getElementById('fabricator-editor-preview-style')) {
            var scaffold = doc.createElement('style');
            scaffold.id = 'fabricator-editor-preview-style';
            scaffold.textContent = 'html,body{margin:0;}'
                + 'body{padding:10px 12px;box-sizing:border-box;'
                + 'font-family:-apple-system,Segoe UI,Arial,sans-serif;'
                + 'font-size:13px;line-height:1.6;color:#1d2327;}'
                /* Collapse only the first child's top margin, else UA default spacing reads as a blank line. */
                + 'body>:first-child{margin-top:0;}';
            if (doc.head) {
                doc.head.appendChild(scaffold);
            } else {
                doc.documentElement.insertBefore(scaffold, doc.body);
            }
        }

        /* A horizontal scrollbar would eat into clientHeight and force unwanted vertical scroll, so clip overflow-x instead. */
        doc.documentElement.style.overflowX = 'hidden';
        doc.body.style.overflowX = 'hidden';

        /* Images load async and can change the height after the fact. */
        doc.querySelectorAll('img').forEach(function (img) {
            img.addEventListener('load', resize);
            img.addEventListener('error', resize);
        });

        /* WebKit runs no event listener in a sandboxed document without allow-scripts, so the input listener above
           misses edits there; a MutationObserver sees them in every browser. Set up last, so the scaffolding is no edit. */
        if (window.MutationObserver) {
            new MutationObserver(emitChange).observe(doc.documentElement, {
                childList: true, subtree: true, characterData: true, attributes: true,
            });
        }

        resize();
    }

    loadDoc(value);

    /* Catch late layout shifts (fonts, async assets). */
    setTimeout(resize, 300);

    /* Tab starts hidden, so resize() above measures a 0x0 box and falls back to 140px until it's visible. */
    if (window.IntersectionObserver) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) resize();
            });
        });
        io.observe(row);
    }
}

/* Shared cleaning pass used by both serialization modes below — mutates doc in place, returns nothing. */
function cleanRichDoc(doc) {
    // Editor's own display-only scaffold (see loadDoc()) — must not survive into the fragment/full-document output (sent as emails).
    var editorStyle = doc.getElementById('fabricator-editor-preview-style');
    if (editorStyle) { editorStyle.remove(); }
    // loadDoc()'s inline overflow-x:hidden is scaffolding too.
    [doc.documentElement, doc.body].forEach(function (el) {
        if (!el) { return; }
        el.style.removeProperty('overflow-x');
        if (!el.getAttribute('style')) { el.removeAttribute('style'); }
    });
    doc.querySelectorAll('script').forEach(function (el) { el.remove(); });
    doc.querySelectorAll(
        'style.darkreader, link.darkreader, meta[name="darkreader"], .darkreader--fallback'
    ).forEach(function (el) { el.remove(); });
    doc.querySelectorAll('*').forEach(function (el) {
        Array.prototype.slice.call(el.attributes).forEach(function (attr) {
            var name = attr.name.toLowerCase();
            var val  = attr.value || '';
            if (/^on/.test(name) || name.indexOf('data-darkreader') === 0) {
                el.removeAttribute(attr.name);
            } else if (['href', 'src', 'xlink:href', 'action', 'formaction'].indexOf(name) !== -1 && isUnsafeUrl(val, true)) {
                el.removeAttribute(attr.name);
            }
        });
    });
}

/* Full-document serialization — used only by the notification-body editor, which stores a complete HTML document. */
function sanitizeRichDoc(doc) {
    // A detached clone, so the live editing surface keeps its styles.
    var clone = doc.cloneNode(true);
    cleanRichDoc(clone);
    return (clone.doctype ? '<!DOCTYPE ' + clone.doctype.name + '>' : '')
        + clone.documentElement.outerHTML;
}

/* Fragment-only serialization: discards the doctype/<html>/<head> wrapper loadDoc() injects purely for iframe display. */
function sanitizeRichDocFragment(doc) {
    // See sanitizeRichDoc()'s comment above — same reason to clean a clone, not the live doc.
    var clone = doc.cloneNode(true);
    cleanRichDoc(clone);
    return clone.body ? clone.body.innerHTML : '';
}

/* Rating icon row: select (icon type) + half-values pill in one line */
function spIconRow(parent, iconKey, label, options, iconVal, halfKey, halfVal, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'fabricator-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);

    var inner = document.createElement('div');
    inner.className = 'fabricator-sp-icon-row';

    var sel = document.createElement('select');
    sel.className = 'fabricator-sp-input';
    options.forEach(function (opt) {
        var o = document.createElement('option');
        o.value       = opt.value !== undefined ? opt.value : opt;
        o.textContent = opt.label !== undefined ? opt.label : opt;
        if (String(o.value) === String(iconVal)) o.selected = true;
        sel.appendChild(o);
    });

    var halfPill = mkSeg(
        ['0', '1'], [_i18n.wholeValues || 'Whole values', _i18n.halfValues || 'Half values'],
        halfVal ? '1' : '0',
        function (v) { onChange(sel.value, v === '1'); }
    );

    sel.addEventListener('change', function () {
        var active = halfPill.querySelector('.fabricator-seg-btn.fabricator-seg-active');
        onChange(this.value, active ? active.dataset.value === '1' : false);
    });

    inner.appendChild(sel);
    inner.appendChild(halfPill);
    row.appendChild(inner);
    parent.appendChild(row);
}

/* Time format + prefill in one row */
function spTimeRow(parent, formatKey, prefillKey, formatVal, prefillVal, onChange) {
    var row = document.createElement('div');
    row.className = 'fabricator-sp-time-row';

    /* Left: Format pill */
    var fmtWrap = document.createElement('div');
    fmtWrap.className = 'fabricator-sp-time-fmt';
    var fmtLbl = document.createElement('div');
    fmtLbl.className   = 'fabricator-sp-label';
    fmtLbl.textContent = _i18n.formatLabel || 'Format';
    fmtWrap.appendChild(fmtLbl);
    var fmtPill = mkSeg(['0', '1'], [_i18n.format24h || '24h', _i18n.format12h || '12h (AM/PM)'], formatVal ? '1' : '0', function (v) {
        onChange(v === '1', prefillChk.checked);
    });
    fmtWrap.appendChild(fmtPill);

    /* Right: Prefill toggle */
    var prefillWrap = document.createElement('div');
    prefillWrap.className = 'fabricator-sp-time-prefill';
    var toggleLbl = document.createElement('label');
    toggleLbl.className = 'fabricator-toggle';
    var prefillChk = document.createElement('input');
    prefillChk.type    = 'checkbox';
    prefillChk.checked = !!prefillVal;
    prefillChk.addEventListener('change', function () {
        var active = fmtPill.querySelector('.fabricator-seg-btn.fabricator-seg-active');
        onChange(active ? active.dataset.value === '1' : false, this.checked);
    });
    toggleLbl.appendChild(prefillChk);
    var slider = document.createElement('span');
    slider.className = 'fabricator-toggle-slider';
    toggleLbl.appendChild(slider);
    var textLbl = document.createElement('label');
    textLbl.className   = 'fabricator-toggle-label';
    textLbl.textContent = _i18n.prefillNow || 'Prefill now';
    prefillWrap.appendChild(toggleLbl);
    prefillWrap.appendChild(textLbl);

    row.appendChild(fmtWrap);
    row.appendChild(prefillWrap);
    parent.appendChild(row);
}

/* Rating live preview — renders current icon/half/max config */
function spRatingPreview(parent, field) {
    /* Filled glyph at low opacity for "empty" state, avoiding the Unicode size mismatch between filled/outline glyph pairs. */
    var ICONS_JS = {
        star:    '★',
        heart:   '♥',
        circle:  '●',
        diamond: '◆',
    };
    var max       = parseInt(field.max || 5, 10);
    var half      = !!field.allow_half;
    var custom    = !!field.icon_source && !!field.custom_icon_url;
    var iconKey   = field.icon_type || 'star';
    var glyph     = ICONS_JS[iconKey] || ICONS_JS.star;
    var customUrl = custom ? field.custom_icon_url : '';

    var wrap = document.createElement('div');
    wrap.className = 'fabricator-sp-rating-preview';

    var filledFull = Math.floor(max * 0.6);
    var filledHalf = half && (filledFull < max) ? 1 : 0;

    for (var i = 1; i <= max; i++) {
        var outer = document.createElement('span');
        outer.className = 'fabricator-sp-rating-star';

        var isFull     = i <= filledFull;
        var isHalfStep = !isFull && half && (i === filledFull + 1) && filledHalf;

        if (customUrl) {
            outer.className += ' fabricator-sp-rating-img-star';
            var baseImg = document.createElement('img');
            baseImg.src    = customUrl;
            baseImg.width  = 24;
            baseImg.height = 24;
            baseImg.style.opacity = isFull ? '1' : '0.25';
            outer.appendChild(baseImg);
            if (isHalfStep) {
                outer.className += ' fabricator-sp-rating-half';
                var clipImg = document.createElement('img');
                clipImg.src       = customUrl;
                clipImg.width     = 24;
                clipImg.height    = 24;
                clipImg.className = 'fabricator-sp-rating-half-fill';
                outer.appendChild(clipImg);
            }
        } else if (isHalfStep) {
            outer.className += ' fabricator-sp-rating-half';
            var baseSpan = document.createElement('span');
            baseSpan.textContent      = glyph;
            baseSpan.style.opacity    = '0.2';
            outer.appendChild(baseSpan);
            var clipSpan = document.createElement('span');
            clipSpan.className   = 'fabricator-sp-rating-half-fill';
            clipSpan.textContent = glyph;
            outer.appendChild(clipSpan);
        } else {
            outer.textContent  = glyph;
            outer.style.opacity = isFull ? '1' : '0.2';
        }
        wrap.appendChild(outer);
    }

    parent.appendChild(wrap);
}

/* Sub-fields editor: enable toggle is independent of the header's expand/collapse click. */
function spSubfields(parent, items, field, change) {
    spSectionTitle(parent, _i18n.subfieldsSection || 'Subfields');

    var wrap = document.createElement('div');
    wrap.className = 'fabricator-sp-subfields';

    items.forEach(function (item) {
        var k = item.key;

        var card = document.createElement('div');
        card.className = 'fabricator-sp-subfield-card';

        var isEnabled = field[k + '_enabled'] !== false;
        if (!isEnabled) {
            card.classList.add('fabricator-sp-subfield--off');
        }

        /* ---- header: [toggle] [name] [chevron] ---- */
        var header = document.createElement('div');
        header.className = 'fabricator-sp-subfield-hdr';

        /* stop-propagation so the enable toggle doesn't also fire the accordion header handler */
        var togWrap = document.createElement('label');
        togWrap.className = 'fabricator-toggle fabricator-toggle--sm';
        togWrap.title     = _i18n.enableSubfield || 'Enable subfield';
        togWrap.addEventListener('click', function (e) { e.stopPropagation(); });
        var togInp = document.createElement('input');
        togInp.type    = 'checkbox';
        togInp.checked = isEnabled;
        (function (cardEl, sfKey) {
            togInp.addEventListener('change', function () {
                var v = this.checked;
                change(sfKey + '_enabled', v);
                cardEl.classList.toggle('fabricator-sp-subfield--off', !v);
            });
        }(card, k));
        togWrap.appendChild(togInp);
        var togSlider = document.createElement('span');
        togSlider.className = 'fabricator-toggle-slider';
        togWrap.appendChild(togSlider);
        header.appendChild(togWrap);

        var nameLbl = document.createElement('span');
        nameLbl.className   = 'fabricator-sp-subfield-name';
        nameLbl.textContent = item.label;
        header.appendChild(nameLbl);

        /* Chevron on the RIGHT */
        var chevron = document.createElement('span');
        chevron.className = 'fabricator-sp-subfield-chevron';
        chevron.innerHTML = '<i class="fa-solid fa-chevron-down"></i>';
        header.appendChild(chevron);

        card.appendChild(header);

        /* ---- body (collapsed by default) ---- */
        var body = document.createElement('div');
        body.className = 'fabricator-sp-subfield-body fabricator-sp-subfield-body--collapsed';

        /* Label */
        var lblRow = document.createElement('div');
        lblRow.className = 'fabricator-sp-subrow';
        var lblLabel = document.createElement('label');
        lblLabel.className   = 'fabricator-sp-sublabel';
        lblLabel.textContent = _i18n.labelField || 'Label';
        var lblInp = document.createElement('input');
        lblInp.type      = 'text';
        lblInp.className = 'fabricator-sp-input';
        lblInp.value     = field[k + '_label'] !== undefined ? field[k + '_label'] : item.label;
        (function (sfKey) {
            lblInp.addEventListener('input', function () { change(sfKey + '_label', this.value); });
        }(k));
        lblRow.appendChild(lblLabel);
        lblRow.appendChild(lblInp);
        body.appendChild(lblRow);

        /* Placeholder (skip for prefix select) */
        if (!item.is_select) {
            var phRow = document.createElement('div');
            phRow.className = 'fabricator-sp-subrow';
            var phLabel = document.createElement('label');
            phLabel.className   = 'fabricator-sp-sublabel';
            phLabel.textContent = _i18n.placeholderLabel || 'Placeholder';
            var phInp = document.createElement('input');
            phInp.type      = 'text';
            phInp.className = 'fabricator-sp-input';
            phInp.value     = field[k + '_placeholder'] || '';
            (function (sfKey) {
                phInp.addEventListener('input', function () { change(sfKey + '_placeholder', this.value); });
            }(k));
            phRow.appendChild(phLabel);
            phRow.appendChild(phInp);
            body.appendChild(phRow);
        }

        /* Required toggle */
        var reqRow = document.createElement('div');
        reqRow.className = 'fabricator-sp-toggle-row fabricator-sp-subrow-toggle';
        var reqTogWrap = document.createElement('label');
        reqTogWrap.className = 'fabricator-toggle';
        var reqInp = document.createElement('input');
        reqInp.type    = 'checkbox';
        reqInp.checked = !!field[k + '_required'];
        (function (sfKey) {
            reqInp.addEventListener('change', function () { change(sfKey + '_required', this.checked); });
        }(k));
        reqTogWrap.appendChild(reqInp);
        var reqSlider = document.createElement('span');
        reqSlider.className = 'fabricator-toggle-slider';
        reqTogWrap.appendChild(reqSlider);
        var reqLabel = document.createElement('label');
        reqLabel.className   = 'fabricator-toggle-label';
        reqLabel.textContent = _i18n.requiredField || 'Required field';
        reqRow.appendChild(reqTogWrap);
        reqRow.appendChild(reqLabel);
        body.appendChild(reqRow);

        card.appendChild(body);

        /* Accordion toggle on header click */
        header.addEventListener('click', function () {
            var collapsed = body.classList.toggle('fabricator-sp-subfield-body--collapsed');
            chevron.classList.toggle('fabricator-sp-subfield-chevron--up', !collapsed);
        });

        wrap.appendChild(card);
    });

    parent.appendChild(wrap);
}

B.spRow = spRow;
B.spMediaUpload = spMediaUpload;
B.spTextarea = spTextarea;
B.spPageNamesList = spPageNamesList;
B.spInfoIcon = spInfoIcon;
B.spCheckbox = spCheckbox;
B.spCheckboxHint = spCheckboxHint;
B.spSelect = spSelect;
B.spCountryTags = spCountryTags;
B.spCallingCodeTags = spCallingCodeTags;
B.spOptionsList = spOptionsList;
B.spBoolSeg = spBoolSeg;
B.spSectionTitle = spSectionTitle;
B.spLimitRow = spLimitRow;
B.spNotice = spNotice;
B.spHtmlEditor = spHtmlEditor;
B.spHtmlFieldEditor = spHtmlFieldEditor;
B.spRichTextEditor = spRichTextEditor;
B.cleanRichDoc = cleanRichDoc;
B.sanitizeRichDoc = sanitizeRichDoc;
B.sanitizeRichDocFragment = sanitizeRichDocFragment;
B.spIconRow = spIconRow;
B.spTimeRow = spTimeRow;
B.spRatingPreview = spRatingPreview;
B.spSubfields = spSubfields;
});
