/*!
 * FormFabricator — Field test harness admin page (dev-only, WP_DEBUG-gated)
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
window.fabricatorCollapseSections = function (table) {
    if (!table) { return; }
    table.querySelectorAll('tr.ff-section').forEach(function (sec) {
        var bodyRows = [];
        var hasFail  = false;
        var cur = sec.nextElementSibling;
        while (cur && !cur.classList.contains('ff-section')) {
            bodyRows.push(cur);
            if (cur.classList.contains('ff-fail')) { hasFail = true; }
            cur = cur.nextElementSibling;
        }
        if (!bodyRows.length) { return; }

        var passCount = bodyRows.filter(function (r) { return r.classList.contains('ff-pass'); }).length;
        var td = sec.querySelector('td');

        var arrow = document.createElement('span');
        arrow.style.cssText = 'display:inline-block;width:1.4em;font-style:normal;';
        td.insertBefore(arrow, td.firstChild);

        var count = document.createElement('span');
        count.style.cssText = 'color:#888;font-weight:normal;margin-left:8px;font-size:11px;';
        count.textContent = '(' + passCount + '/' + bodyRows.length + ')';
        td.appendChild(count);

        sec.style.cursor = 'pointer';
        sec.title = 'Click to toggle';

        function doCollapse() {
            bodyRows.forEach(function (r) { r.style.display = 'none'; });
            arrow.innerHTML = '<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>';
        }
        function doExpand() {
            bodyRows.forEach(function (r) { r.style.display = ''; });
            arrow.innerHTML = '<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>';
        }

        sec.addEventListener('click', function () {
            if (bodyRows[0].style.display === 'none') { doExpand(); } else { doCollapse(); }
        });

        if (hasFail) { doExpand(); } else { doCollapse(); }
    });
};
window.fabricatorCollapseSections(document.getElementById('fabricator-php-tests'));

(function () {
    'use strict';
    var pass = 0;
    var fail = 0;
    var rows = [];

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function run(name, fn) {
        var r;
        try {
            r = fn();
        } catch (e) {
            fail++;
            rows.push('<tr class="ff-fail"><td><i class="fa-solid fa-xmark" aria-hidden="true"></i></td><td>' + esc(name) + '</td>'
                + '<td></td><td></td><td>Exception: ' + esc(e.message) + '</td></tr>');
            return;
        }
        if (r.ok) {
            pass++;
            rows.push('<tr class="ff-pass"><td><i class="fa-solid fa-check" aria-hidden="true"></i></td><td>' + esc(name) + '</td>'
                + '<td class="ff-io">' + esc(r.i) + '</td>'
                + '<td class="ff-io ff-out">' + esc(r.o) + '</td>'
                + '<td></td></tr>');
        } else {
            fail++;
            rows.push('<tr class="ff-fail"><td><i class="fa-solid fa-xmark" aria-hidden="true"></i></td><td>' + esc(name) + '</td>'
                + '<td class="ff-io">' + esc(r.i) + '</td>'
                + '<td class="ff-io ff-out">' + esc(r.o) + '</td>'
                + '<td>' + esc(r.msg || 'FAIL') + '</td></tr>');
        }
    }

    function section(label) {
        rows.push('<tr class="ff-section"><td colspan="5"><strong>' + esc(label) + '</strong></td></tr>');
    }

    function ok(i, o)       { return { ok: true,  i: i, o: o }; }
    function ko(i, o, msg)  { return { ok: false, i: i, o: String(o), msg: msg }; }

    /* ── FabricatorSkipValidation ─────────────────────────────────────────────── */
    section('JS: FabricatorSkipValidation');
    var skip = window.FabricatorSkipValidation || [];
    run('skip array exists', function () {
        return Array.isArray(skip) ? ok('FabricatorSkipValidation', skip.join(', ')) : ko('', '', 'not an array');
    });
    run('html is skipped', function () {
        return skip.indexOf('html') !== -1 ? ok('html', 'in skip list') : ko('html', '', 'not in skip list');
    });
    run('pagebreak is skipped', function () {
        return skip.indexOf('pagebreak') !== -1 ? ok('pagebreak', 'in skip list') : ko('pagebreak', '', 'not in skip list');
    });
    run('page-header is skipped', function () {
        return skip.indexOf('page-header') !== -1 ? ok('page-header', 'in skip list') : ko('page-header', '', 'not in skip list');
    });

    /* ── FabricatorValidators ─────────────────────────────────────────────────── */
    section('JS: FabricatorValidators');
    var validators = window.FabricatorValidators || {};
    run('FabricatorValidators is an object', function () {
        return typeof validators === 'object'
            ? ok('FabricatorValidators', Object.keys(validators).join(', '))
            : ko('', '', 'not object');
    });

    /* email */
    run('email: valid → null', function () {
        var fn = validators['email'];
        if (!fn) { return ko('', '', 'email validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="email" value="test@example.com">';
        var r = fn(el);
        return r == null ? ok('test@example.com', 'null') : ko('test@example.com', r, 'expected null');
    });
    run('email: invalid → error string', function () {
        var fn = validators['email'];
        if (!fn) { return ko('', '', 'email validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="email" value="not-an-email">';
        var r = fn(el);
        return (typeof r === 'string' && r.length > 0)
            ? ok('not-an-email', r)
            : ko('not-an-email', r, 'expected error string');
    });

    /* iban — validator reads _fabricatorIbanValid/_fabricatorIbanInvalid flags set by the
       init+input handler, so we set them manually to test each branch */
    run('iban: valid flag → null', function () {
        var fn = validators['iban'];
        if (!fn) { return ko('', '', 'iban validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="fabricator-sepa-iban" value="DE89370400440532013000">';
        el.querySelector('.fabricator-sepa-iban')._fabricatorIbanValid = true;
        var r = fn(el);
        return r == null ? ok('DE89370400440532013000 + _fabricatorIbanValid', 'null') : ko('DE89370400440532013000', r, 'expected null');
    });
    run('iban: invalid checksum flag → error string', function () {
        var fn = validators['iban'];
        if (!fn) { return ko('', '', 'iban validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="fabricator-sepa-iban" value="DE00000000000000000000">';
        el.querySelector('.fabricator-sepa-iban')._fabricatorIbanInvalid = true;
        var r = fn(el);
        return (typeof r === 'string' && r.length > 0)
            ? ok('_fabricatorIbanInvalid=true', r)
            : ko('_fabricatorIbanInvalid=true', r, 'expected error string');
    });
    run('iban: incomplete (no flags) → incomplete error', function () {
        var fn = validators['iban'];
        if (!fn) { return ko('', '', 'iban validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="fabricator-sepa-iban" value="DE123">';
        var r = fn(el);
        return (typeof r === 'string' && r.length > 0)
            ? ok('DE123 (no flags set)', r)
            : ko('DE123', r, 'expected incomplete-IBAN error');
    });

    /* phone ─────────────────────────────────────────────────────────────── */
    section('JS: validators — phone');
    run('phone: empty → null', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '';
        inp.dataset.phoneMode = 'any';
        el.appendChild(inp);
        return fn(el) == null ? ok('(empty) mode=any', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('phone: no mode → null (no validation)', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = 'abc123';
        el.appendChild(inp);
        return fn(el) == null ? ok('abc123 (no mode)', 'null') : ko('abc123', fn(el), 'expected null when no mode');
    });
    run('phone: mode=any valid → null', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '+4915123456789';
        inp.dataset.phoneMode = 'any';
        el.appendChild(inp);
        var r = fn(el);
        return r == null ? ok('+4915123456789 mode=any', 'null') : ko('+4915123456789', r, 'expected null');
    });
    run('phone: mode=any too short → error', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '123';
        inp.dataset.phoneMode = 'any';
        el.appendChild(inp);
        var r = fn(el);
        return typeof r === 'string' && r ? ok('123 mode=any', r) : ko('123', r, 'expected error');
    });
    run('phone: mode=countries missing + → error', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '015123456789';
        inp.dataset.phoneMode = 'countries';
        inp.dataset.phoneCountryMode = 'allow';
        inp.dataset.phoneCountryList = '["49"]';
        el.appendChild(inp);
        var r = fn(el);
        return typeof r === 'string' && r ? ok('015… (no +)', r) : ko('015…', r, 'expected + required error');
    });
    run('phone: mode=countries allow matching → null', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '+4915123456789';
        inp.dataset.phoneMode = 'countries';
        inp.dataset.phoneCountryMode = 'allow';
        inp.dataset.phoneCountryList = '["49"]';
        el.appendChild(inp);
        var r = fn(el);
        return r == null ? ok('+4915… (DE, in allow list)', 'null') : ko('+4915…', r, 'expected null');
    });
    run('phone: mode=countries allow non-matching → error', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '+33123456789';
        inp.dataset.phoneMode = 'countries';
        inp.dataset.phoneCountryMode = 'allow';
        inp.dataset.phoneCountryList = '["49"]';
        el.appendChild(inp);
        var r = fn(el);
        return typeof r === 'string' && r ? ok('+33… (FR, not in DE allow list)', r) : ko('+33…', r, 'expected error');
    });

    /* number-range ──────────────────────────────────────────────────────── */
    section('JS: validators — number-range');
    run('number-range: empty → null', function () {
        var fn = validators['number-range'];
        if (!fn) { return ko('', '', 'number-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="" min="1" max="10">';
        return fn(el) == null ? ok('(empty)', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('number-range: in range → null', function () {
        var fn = validators['number-range'];
        if (!fn) { return ko('', '', 'number-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="5" min="1" max="10">';
        var r = fn(el);
        return r == null ? ok('5 (1–10)', 'null') : ko('5', r, 'expected null');
    });
    run('number-range: below min → error', function () {
        var fn = validators['number-range'];
        if (!fn) { return ko('', '', 'number-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="0" min="1" max="10">';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('1') !== -1 ? ok('0 < min=1', r) : ko('0', r, 'expected Mindestwert: 1');
    });
    run('number-range: above max → error', function () {
        var fn = validators['number-range'];
        if (!fn) { return ko('', '', 'number-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="11" min="1" max="10">';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('10') !== -1 ? ok('11 > max=10', r) : ko('11', r, 'expected Maximalwert: 10');
    });

    /* date-format ───────────────────────────────────────────────────────── */
    section('JS: validators — date-format');
    run('date-format: empty → null', function () {
        var fn = validators['date-format'];
        if (!fn) { return ko('', '', 'date-format not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="fabricator-date-text" value="">';
        return fn(el) == null ? ok('(empty)', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('date-format: valid TT.MM.JJJJ → null', function () {
        var fn = validators['date-format'];
        if (!fn) { return ko('', '', 'date-format not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="fabricator-date-text" value="15.06.2024">';
        var r = fn(el);
        return r == null ? ok('15.06.2024', 'null') : ko('15.06.2024', r, 'expected null');
    });
    run('date-format: ISO format → format error', function () {
        var fn = validators['date-format'];
        if (!fn) { return ko('', '', 'date-format not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="fabricator-date-text" value="2024-06-15">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('2024-06-15', r) : ko('2024-06-15', r, 'expected format error');
    });
    run('date-format: impossible date 32.01.2024 → error', function () {
        var fn = validators['date-format'];
        if (!fn) { return ko('', '', 'date-format not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="fabricator-date-text" value="32.01.2024">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('32.01.2024', r) : ko('32.01.2024', r, 'expected invalid date error');
    });

    /* currency-range ────────────────────────────────────────────────────── */
    section('JS: validators — currency-range');
    run('currency-range: empty → null', function () {
        var fn = validators['currency-range'];
        if (!fn) { return ko('', '', 'currency-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="" min="10" max="1000">';
        return fn(el) == null ? ok('(empty)', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('currency-range: valid → null', function () {
        var fn = validators['currency-range'];
        if (!fn) { return ko('', '', 'currency-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="100" min="10" max="1000">';
        var r = fn(el);
        return r == null ? ok('100 (10–1000)', 'null') : ko('100', r, 'expected null');
    });
    run('currency-range: below min → error', function () {
        var fn = validators['currency-range'];
        if (!fn) { return ko('', '', 'currency-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="5" min="10" max="1000">';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('10') !== -1 ? ok('5 < min=10', r) : ko('5', r, 'expected Mindestwert: 10');
    });
    run('currency-range: above max → error', function () {
        var fn = validators['currency-range'];
        if (!fn) { return ko('', '', 'currency-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="2000" min="10" max="1000">';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('1000') !== -1 ? ok('2000 > max=1000', r) : ko('2000', r, 'expected Maximalwert: 1000');
    });

    /* text-word-limit ───────────────────────────────────────────────────── */
    section('JS: validators — text-word-limit');
    run('text-word-limit: no data-word-limit attr → null', function () {
        var fn = validators['text-word-limit'];
        if (!fn) { return ko('', '', 'text-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="text" value="too many words here">';
        return fn(el) == null ? ok('(no data-word-limit)', 'null') : ko('', fn(el), 'expected null');
    });
    run('text-word-limit: within limit → null', function () {
        var fn = validators['text-word-limit'];
        if (!fn) { return ko('', '', 'text-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="text" value="two words" data-word-limit="5">';
        var r = fn(el);
        return r == null ? ok('"two words" limit=5', 'null') : ko('"two words"', r, 'expected null');
    });
    run('text-word-limit: over limit → error', function () {
        var fn = validators['text-word-limit'];
        if (!fn) { return ko('', '', 'text-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="text" value="one two three four" data-word-limit="3">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('4 words limit=3', r) : ko('4 words', r, 'expected word-limit error');
    });

    /* textarea-word-limit ───────────────────────────────────────────────── */
    section('JS: validators — textarea-word-limit');
    run('textarea-word-limit: within limit → null', function () {
        var fn = validators['textarea-word-limit'];
        if (!fn) { return ko('', '', 'textarea-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<textarea data-word-limit="5">hello world</textarea>';
        var r = fn(el);
        return r == null ? ok('"hello world" limit=5', 'null') : ko('"hello world"', r, 'expected null');
    });
    run('textarea-word-limit: over limit → error', function () {
        var fn = validators['textarea-word-limit'];
        if (!fn) { return ko('', '', 'textarea-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<textarea data-word-limit="2">one two three</textarea>';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('"one two three" limit=2', r) : ko('', r, 'expected error');
    });

    /* website-url ───────────────────────────────────────────────────────── */
    section('JS: validators — website-url');
    run('website-url: no data-validate-url attr → null', function () {
        var fn = validators['website-url'];
        if (!fn) { return ko('', '', 'website-url not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="url" value="not-a-url">';
        return fn(el) == null ? ok('(no data-validate-url)', 'null') : ko('', fn(el), 'expected null');
    });
    run('website-url: valid https → null', function () {
        var fn = validators['website-url'];
        if (!fn) { return ko('', '', 'website-url not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="url" value="https://example.de" data-validate-url="1">';
        var r = fn(el);
        return r == null ? ok('https://example.de', 'null') : ko('https://example.de', r, 'expected null');
    });
    run('website-url: invalid → error', function () {
        var fn = validators['website-url'];
        if (!fn) { return ko('', '', 'website-url not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="url" value="not-a-url" data-validate-url="1">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('not-a-url', r) : ko('not-a-url', r, 'expected url error');
    });

    /* checkbox-count ────────────────────────────────────────────────────── */
    section('JS: validators — checkbox-count');
    run('checkbox-count: no .fabricator-checkbox-group → null', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="checkbox" checked>';
        return fn(el) == null ? ok('(no group)', 'null') : ko('', fn(el), 'expected null');
    });
    run('checkbox-count: no min/max → null', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="fabricator-checkbox-group"><input type="checkbox" checked></div>';
        return fn(el) == null ? ok('(no min/max)', 'null') : ko('', fn(el), 'expected null');
    });
    run('checkbox-count: below min → error', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="fabricator-checkbox-group" data-min-selections="2" data-max-selections="0">'
            + '<input type="checkbox" checked><input type="checkbox"></div>';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('2') !== -1 ? ok('1 checked, min=2', r) : ko('1/min=2', r, 'expected min error');
    });
    run('checkbox-count: at min → null', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="fabricator-checkbox-group" data-min-selections="2" data-max-selections="0">'
            + '<input type="checkbox" checked><input type="checkbox" checked></div>';
        var r = fn(el);
        return r == null ? ok('2 checked, min=2', 'null') : ko('2/min=2', r, 'expected null');
    });
    run('checkbox-count: above max → error', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="fabricator-checkbox-group" data-min-selections="0" data-max-selections="1">'
            + '<input type="checkbox" checked><input type="checkbox" checked></div>';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('1') !== -1 ? ok('2 checked, max=1', r) : ko('2/max=1', r, 'expected max error');
    });

    /* slider-range ──────────────────────────────────────────────────────── */
    section('JS: validators — slider-range');
    run('slider-range: no .fabricator-slider-wrap → null', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="hidden" value="5">';
        return fn(el) == null ? ok('(no wrap)', 'null') : ko('', fn(el), 'expected null');
    });
    run('slider-range: single, in range → null', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="fabricator-slider-wrap" data-min="0" data-max="100"></div>'
            + '<input type="hidden" value="50">';
        var r = fn(el);
        return r == null ? ok('50 (0–100)', 'null') : ko('50', r, 'expected null');
    });
    run('slider-range: single, below min → error', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="fabricator-slider-wrap" data-min="10" data-max="100"></div>'
            + '<input type="hidden" value="5">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('5 < min=10', r) : ko('5/min=10', r, 'expected min error');
    });
    run('slider-range: range mode, valid → null', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="fabricator-slider-wrap fabricator-slider-wrap--range" data-min="0" data-max="100">'
            + '<input class="fabricator-slider-input-from" value="20">'
            + '<input class="fabricator-slider-input-to" value="80"></div>';
        var r = fn(el);
        return r == null ? ok('from=20 to=80 (0–100)', 'null') : ko('20–80', r, 'expected null');
    });
    run('slider-range: range mode, out of bounds → error', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="fabricator-slider-wrap fabricator-slider-wrap--range" data-min="0" data-max="100">'
            + '<input class="fabricator-slider-input-from" value="-5">'
            + '<input class="fabricator-slider-input-to" value="80"></div>';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('from=-5 (min=0)', r) : ko('-5/0–100', r, 'expected out-of-range error');
    });

    /* sepa-bic ──────────────────────────────────────────────────────────── */
    section('JS: validators — sepa-bic');
    run('sepa-bic: empty → null', function () {
        var fn = validators['sepa-bic'];
        if (!fn) { return ko('', '', 'sepa-bic not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="fabricator-sepa-bic" value="">';
        return fn(el) == null ? ok('(empty)', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('sepa-bic: valid 8-char BIC → null', function () {
        var fn = validators['sepa-bic'];
        if (!fn) { return ko('', '', 'sepa-bic not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div><input class="fabricator-sepa-bic" value="COBADEFF"><span class="fabricator-field-error"></span></div>';
        var r = fn(el);
        return r == null ? ok('COBADEFF', 'null') : ko('COBADEFF', r, 'expected null');
    });
    run('sepa-bic: valid 11-char BIC → null', function () {
        var fn = validators['sepa-bic'];
        if (!fn) { return ko('', '', 'sepa-bic not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div><input class="fabricator-sepa-bic" value="COBADEFFXXX"><span class="fabricator-field-error"></span></div>';
        var r = fn(el);
        return r == null ? ok('COBADEFFXXX', 'null') : ko('COBADEFFXXX', r, 'expected null');
    });
    run('sepa-bic: 7-char (INVALID) → error', function () {
        var fn = validators['sepa-bic'];
        if (!fn) { return ko('', '', 'sepa-bic not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div><input class="fabricator-sepa-bic" value="INVALID"><span class="fabricator-field-error"></span></div>';
        var r = fn(el);
        return (r != null && r.length > 0) ? ok('INVALID (7 chars)', 'non-null') : ko('INVALID', r, 'expected BIC error');
    });

    /* sepa-required ─────────────────────────────────────────────────────── */
    section('JS: validators — sepa-required');
    run('sepa-required: data-required missing → null', function () {
        var fn = validators['sepa-required'];
        if (!fn) { return ko('', '', 'sepa-required not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="fabricator-sepa-iban" value="">';
        return fn(el) == null ? ok('(no data-required)', 'null') : ko('', fn(el), 'expected null');
    });
    run('sepa-required: required + empty IBAN → error', function () {
        var fn = validators['sepa-required'];
        if (!fn) { return ko('', '', 'sepa-required not registered'); }
        var el = document.createElement('div');
        el.dataset.required = 'true';
        el.innerHTML = '<div><input class="fabricator-sepa-iban" value=""><span class="fabricator-field-error"></span></div>'
            + '<div><input class="fabricator-sepa-bic" value="COBADEFF"><span class="fabricator-field-error"></span></div>'
            + '<div><input class="fabricator-sepa-holder" value="Max"><span class="fabricator-field-error"></span></div>';
        var r = fn(el);
        return (r != null && r.length > 0) ? ok('required, IBAN empty', 'non-null error') : ko('IBAN empty', r, 'expected error');
    });
    run('sepa-required: required + all filled → null', function () {
        var fn = validators['sepa-required'];
        if (!fn) { return ko('', '', 'sepa-required not registered'); }
        var el = document.createElement('div');
        el.dataset.required = 'true';
        el.innerHTML = '<div><input class="fabricator-sepa-iban" value="DE89370400440532013000"><span class="fabricator-field-error"></span></div>'
            + '<div><input class="fabricator-sepa-bic" value="COBADEFF"><span class="fabricator-field-error"></span></div>'
            + '<div><input class="fabricator-sepa-holder" value="Max Muster"><span class="fabricator-field-error"></span></div>';
        var r = fn(el);
        return r == null ? ok('all filled', 'null') : ko('all filled', r, 'expected null');
    });

    /* ── FabricatorFieldInits ─────────────────────────────────────────────────── */
    section('JS: FabricatorFieldInits');
    var inits = window.FabricatorFieldInits || {};
    run('FabricatorFieldInits is an object', function () {
        return typeof inits === 'object'
            ? ok('FabricatorFieldInits', Object.keys(inits).join(', '))
            : ko('', '', 'not object');
    });
    ['slider', 'rating', 'date', 'select', 'radio', 'upload', 'signature', 'sepa'].forEach(function (type) {
        run(type + ' init function registered', function () {
            return typeof inits[type] === 'function'
                ? ok(type, 'typeof function')
                : ko(type, typeof inits[type], 'expected function');
        });
    });

    /* ── validatePage (via FabricatorTestHooks) ───────────────────────────────── */
    section('JS: validatePage');
    var hooks = window.FabricatorTestHooks || {};
    var vp = hooks.validatePage;
    run('FabricatorTestHooks.validatePage exported', function () {
        return typeof vp === 'function'
            ? ok('FabricatorTestHooks', 'validatePage function')
            : ko('', '', 'not exported — check __FABRICATOR_TEST__ hook in front.js');
    });

    if (typeof vp === 'function') {
        run('empty scope → valid', function () {
            var el = document.createElement('div');
            var r = vp(el);
            return (r.valid && !r.hasRequired && !r.hasInvalid)
                ? ok('empty scope', JSON.stringify(r))
                : ko('empty scope', JSON.stringify(r), 'expected valid=true');
        });

        run('required empty text → hasRequired', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="fabricator-field fabricator-field--text fabricator-required-field">'
                + '<input type="text" value=""><span class="fabricator-field-error"></span></div>';
            var r = vp(el);
            return (!r.valid && r.hasRequired)
                ? ok('required empty text', JSON.stringify({ valid: r.valid, hasRequired: r.hasRequired }))
                : ko('required empty text', JSON.stringify(r), 'expected hasRequired=true');
        });

        run('required filled text → valid', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="fabricator-field fabricator-field--text fabricator-required-field">'
                + '<input type="text" value="hello"><span class="fabricator-field-error"></span></div>';
            var r = vp(el);
            return r.valid
                ? ok('"hello" required text', JSON.stringify({ valid: r.valid }))
                : ko('"hello" required text', JSON.stringify(r), 'expected valid=true');
        });

        run('html field skipped (in FabricatorSkipValidation)', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="fabricator-field fabricator-field--html fabricator-required-field">'
                + '<input type="text" value=""><span class="fabricator-field-error"></span></div>';
            var r = vp(el);
            return r.valid
                ? ok('required html (skipped)', JSON.stringify({ valid: r.valid }))
                : ko('required html (skipped)', JSON.stringify(r), 'html field should be skipped');
        });

        run('conditionally hidden field → skipped', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div data-conditions=\'{"rules":[]}\' style="display:none">'
                + '<div class="fabricator-field fabricator-field--text fabricator-required-field">'
                + '<input type="text" value=""><span class="fabricator-field-error"></span></div></div>';
            var r = vp(el);
            return r.valid
                ? ok('hidden required field', JSON.stringify({ valid: r.valid }))
                : ko('hidden required field', JSON.stringify(r), 'hidden field should be skipped');
        });

        run('invalid email format → hasInvalid', function () {
            var fn = validators['email'];
            if (!fn) { return ok('(skipped — email validator not registered)', ''); }
            var el = document.createElement('div');
            el.innerHTML = '<div class="fabricator-field fabricator-field--email" data-validate=\'["email"]\'>'
                + '<input type="email" value="not-valid"><span class="fabricator-field-error"></span></div>';
            var r = vp(el);
            return (!r.valid && r.hasInvalid)
                ? ok('not-valid', JSON.stringify({ valid: r.valid, hasInvalid: r.hasInvalid }))
                : ko('not-valid', JSON.stringify(r), 'expected hasInvalid=true');
        });

        run('per-sub-input required check (composite field)', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="fabricator-field fabricator-field--name">'
                + '<input type="text" value="Hans">'
                + '<input type="text" required value="">'
                + '<span class="fabricator-field-error"></span></div>';
            var r = vp(el);
            return (!r.valid && r.hasRequired)
                ? ok('one sub-input required+empty', JSON.stringify({ valid: r.valid, hasRequired: r.hasRequired }))
                : ko('one sub-input required+empty', JSON.stringify(r), 'expected hasRequired=true');
        });

        run('per-sub-input required check all filled → valid', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="fabricator-field fabricator-field--name">'
                + '<input type="text" required value="Hans">'
                + '<input type="text" required value="Müller">'
                + '<span class="fabricator-field-error"></span></div>';
            var r = vp(el);
            return r.valid
                ? ok('all sub-inputs filled', JSON.stringify({ valid: r.valid }))
                : ko('all sub-inputs filled', JSON.stringify(r), 'expected valid=true');
        });

        run('unregistered validation rule is silently skipped', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="fabricator-field fabricator-field--text" data-validate=\'["not-a-real-rule"]\'>'
                + '<input type="text" value="anything"><span class="fabricator-field-error"></span></div>';
            var r = vp(el);
            return r.valid
                ? ok('unknown rule "not-a-real-rule"', JSON.stringify({ valid: r.valid }))
                : ko('unknown rule', JSON.stringify(r), 'expected valid=true (rule silently ignored)');
        });

        run('multiple validation rules — second rule catches error', function () {
            var fn = validators['email'];
            if (!fn) { return ok('(skipped — email validator not registered)', ''); }
            var el = document.createElement('div');
            el.innerHTML = '<div class="fabricator-field fabricator-field--email" data-validate=\'["not-a-real-rule","email"]\'>'
                + '<input type="email" value="not-valid"><span class="fabricator-field-error"></span></div>';
            var r = vp(el);
            return (!r.valid && r.hasInvalid)
                ? ok('rules: [unknown, email]', JSON.stringify({ valid: r.valid, hasInvalid: r.hasInvalid }))
                : ko('rules: [unknown, email]', JSON.stringify(r), 'expected hasInvalid=true from second rule');
        });
    }

    /* ── Condition operators (via initConditions DOM) ─────────────────────── */
    section('JS: condition operators (via initConditions)');
    var ic = hooks.initConditions;
    run('FabricatorTestHooks.initConditions exported', function () {
        return typeof ic === 'function'
            ? ok('FabricatorTestHooks', 'initConditions function')
            : ko('', '', 'not exported — check __FABRICATOR_TEST__ hook in front.js');
    });

    if (typeof ic === 'function') {
        function condTest(label, inputVal, op, condVal, expectVisible) {
            return function () {
                var wrap = document.createElement('div');
                var cond = JSON.stringify({
                    action: 'show', match: 'all',
                    rules: [{ field_id: 'ctrl', operator: op, value: condVal }]
                });
                wrap.innerHTML = '<form class="fabricator-form">'
                    + '<input name="ctrl" type="text">'
                    + '<div class="fabricator-field" data-conditions=\'' + cond + '\'>'
                    + '<span>target</span></div></form>';
                document.body.appendChild(wrap);
                ic(wrap);
                var ctrl = wrap.querySelector('input[name="ctrl"]');
                ctrl.value = inputVal;
                ctrl.dispatchEvent(new Event('change', { bubbles: true }));
                var target = wrap.querySelector('[data-conditions]');
                var visible = target.style.display !== 'none';
                document.body.removeChild(wrap);
                var inp = 'ctrl="' + inputVal + '" op=' + op + ' cond="' + condVal + '"';
                var out = visible ? 'visible' : 'hidden';
                return visible === expectVisible
                    ? ok(inp, out)
                    : ko(inp, out, 'expected ' + (expectVisible ? 'visible' : 'hidden'));
            };
        }

        run('equals: matching value → show',          condTest('equals match',    'hello', 'equals',      'hello', true));
        run('equals: non-matching value → hide',       condTest('equals no match', 'world', 'equals',      'hello', false));
        run('not_equals: different value → show',      condTest('not_eq pass',    'world', 'not_equals',   'hello', true));
        run('not_equals: same value → hide',           condTest('not_eq fail',    'hello', 'not_equals',   'hello', false));
        run('contains: substring present → show',      condTest('contains pass',  'hello world', 'contains', 'hello', true));
        run('contains: substring absent → hide',       condTest('contains fail',  'goodbye', 'contains',   'hello', false));
        run('not_contains: absent → show',             condTest('ncontains pass', 'goodbye', 'not_contains', 'hello', true));
        run('not_contains: present → hide',            condTest('ncontains fail', 'hello world', 'not_contains', 'hello', false));
        run('empty: empty value → show',               condTest('empty pass',     '', 'empty',      '', true));
        run('empty: non-empty value → hide',           condTest('empty fail',     'text', 'empty',  '', false));
        run('not_empty: non-empty → show',             condTest('nempty pass',    'text', 'not_empty', '', true));
        run('not_empty: empty → hide',                 condTest('nempty fail',    '', 'not_empty',   '', false));
        run('greater: 10 > 5 → show',                 condTest('greater pass',   '10', 'greater', '5', true));
        run('greater: 3 > 5 → hide',                  condTest('greater fail',   '3',  'greater', '5', false));
        run('less: 3 < 5 → show',                     condTest('less pass',      '3',  'less',    '5', true));
        run('less: 10 < 5 → hide',                    condTest('less fail',      '10', 'less',    '5', false));

        run('action=hide: matching value → hidden', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'hide', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'equals', value: 'hello' }]
            });
            wrap.innerHTML = '<form class="fabricator-form">'
                + '<input name="ctrl" type="text">'
                + '<div class="fabricator-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var ctrl = wrap.querySelector('input[name="ctrl"]');
            ctrl.value = 'hello';
            ctrl.dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return !visible
                ? ok('action=hide, ctrl="hello" matches', 'hidden')
                : ko('action=hide, ctrl="hello" matches', 'visible', 'expected hidden when hide-rule matches');
        });

        run('match=any: one of two rules passes → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'any',
                rules: [
                    { field_id: 'ctrl', operator: 'equals', value: 'nomatch' },
                    { field_id: 'ctrl', operator: 'equals', value: 'hello' },
                ]
            });
            wrap.innerHTML = '<form class="fabricator-form">'
                + '<input name="ctrl" type="text">'
                + '<div class="fabricator-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var ctrl = wrap.querySelector('input[name="ctrl"]');
            ctrl.value = 'hello';
            ctrl.dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('match=any, second rule matches', 'visible')
                : ko('match=any, second rule matches', 'hidden', 'expected visible (any rule matching is enough)');
        });

        run('match=all: one of two rules fails → hide', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [
                    { field_id: 'ctrl', operator: 'equals', value: 'nomatch' },
                    { field_id: 'ctrl', operator: 'equals', value: 'hello' },
                ]
            });
            wrap.innerHTML = '<form class="fabricator-form">'
                + '<input name="ctrl" type="text">'
                + '<div class="fabricator-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var ctrl = wrap.querySelector('input[name="ctrl"]');
            ctrl.value = 'hello';
            ctrl.dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return !visible
                ? ok('match=all, one rule fails', 'hidden')
                : ko('match=all, one rule fails', 'visible', 'expected hidden (all rules must match)');
        });

        run('checkbox group value: equals matches one checked box → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'equals', value: 'b' }]
            });
            wrap.innerHTML = '<form class="fabricator-form">'
                + '<input name="ctrl" type="checkbox" value="a">'
                + '<input name="ctrl" type="checkbox" value="b">'
                + '<div class="fabricator-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var boxes = wrap.querySelectorAll('input[name="ctrl"]');
            boxes[1].checked = true;
            boxes[1].dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('checkbox "b" checked, cond=b', 'visible')
                : ko('checkbox "b" checked, cond=b', 'hidden', 'expected visible — array value should match "equals"');
        });

        run('checkbox group value: contains checks any checked box → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'contains', value: 'b' }]
            });
            wrap.innerHTML = '<form class="fabricator-form">'
                + '<input name="ctrl" type="checkbox" value="a">'
                + '<input name="ctrl" type="checkbox" value="ab">'
                + '<div class="fabricator-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var boxes = wrap.querySelectorAll('input[name="ctrl"]');
            boxes[1].checked = true;
            boxes[1].dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('checkbox "ab" checked, cond contains "b"', 'visible')
                : ko('checkbox "ab" checked, cond contains "b"', 'hidden', 'expected visible');
        });

        run('checkbox group value: none checked → empty → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'empty', value: '' }]
            });
            wrap.innerHTML = '<form class="fabricator-form">'
                + '<input name="ctrl" type="checkbox" value="a">'
                + '<input name="ctrl" type="checkbox" value="b">'
                + '<div class="fabricator-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var boxes = wrap.querySelectorAll('input[name="ctrl"]');
            boxes[0].dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('no checkboxes checked, cond=empty', 'visible')
                : ko('no checkboxes checked, cond=empty', 'hidden', 'expected visible — [] should count as empty');
        });

        run('select multiple value: equals matches one selected option → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'equals', value: 'y' }]
            });
            wrap.innerHTML = '<form class="fabricator-form">'
                + '<select name="ctrl" multiple>'
                + '<option value="x">X</option><option value="y">Y</option></select>'
                + '<div class="fabricator-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var sel = wrap.querySelector('select[name="ctrl"]');
            sel.options[1].selected = true;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('multi-select "y" selected, cond=y', 'visible')
                : ko('multi-select "y" selected, cond=y', 'hidden', 'expected visible');
        });

        run('input nested inside a hidden conditional ancestor counts as absent', function () {
            var wrap = document.createElement('div');
            /* Condition genuinely computes to hidden here — not a hardcoded inline style init would overwrite. */
            var innerCond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'gate', operator: 'equals', value: 'impossible' }]
            });
            var outerCond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'not_empty', value: '' }]
            });
            wrap.innerHTML = '<form class="fabricator-form">'
                + '<input name="gate" type="text" value="">'
                + '<div data-conditions=\'' + innerCond + '\'>'
                + '<input name="ctrl" type="text" value="hello"></div>'
                + '<div class="fabricator-field" data-conditions=\'' + outerCond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var innerWrap = wrap.querySelector('div[data-conditions]:not(.fabricator-field)');
            if (innerWrap.style.display !== 'none') {
                document.body.removeChild(wrap);
                return ko('setup', 'inner wrap visible', 'test setup broken: inner wrapper should already be hidden');
            }
            var visible = wrap.querySelector('.fabricator-field[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return !visible
                ? ok('ctrl hidden inside hidden ancestor, cond=not_empty', 'hidden')
                : ko('ctrl hidden inside hidden ancestor, cond=not_empty', 'visible', 'expected hidden — hidden-ancestor input should count as absent (empty)');
        });
    }

    /* ── front.js resilience guards (via FabricatorTestHooks) ── */
    section('JS: front.js — resilience guards');

    run('FabricatorTestHooks.showCaptchaBlockedNotice exported', function () {
        return typeof hooks.showCaptchaBlockedNotice === 'function'
            ? ok('FabricatorTestHooks', 'showCaptchaBlockedNotice function')
            : ko('', '', 'not exported — check __FABRICATOR_TEST__ hook in front.js');
    });
    run('FabricatorTestHooks.resetFormsOnBfcacheRestore exported', function () {
        return typeof hooks.resetFormsOnBfcacheRestore === 'function'
            ? ok('FabricatorTestHooks', 'resetFormsOnBfcacheRestore function')
            : ko('', '', 'not exported — check __FABRICATOR_TEST__ hook in front.js');
    });

    if (typeof hooks.showCaptchaBlockedNotice === 'function') {
        run('CAPTCHA blocked: re-enables the activation button', function () {
            var wrap = document.createElement('div');
            wrap.innerHTML = '<div class="fabricator-captcha-gate" data-sitekey="x">'
                + '<button type="button" class="fabricator-captcha-activate" disabled></button></div>';
            document.body.appendChild(wrap);
            var gate = wrap.querySelector('.fabricator-captcha-gate');
            var btn  = wrap.querySelector('.fabricator-captcha-activate');
            hooks.showCaptchaBlockedNotice(gate, btn);
            var stillDisabled = btn.disabled;
            document.body.removeChild(wrap);
            return !stillDisabled
                ? ok('btn.disabled=true → showCaptchaBlockedNotice()', 'btn.disabled=false')
                : ko('btn.disabled=true → showCaptchaBlockedNotice()', 'btn.disabled=true', 'expected the button to be re-enabled so the visitor isn\'t stuck');
        });

        run('CAPTCHA blocked: shows a visible, non-empty error notice', function () {
            var wrap = document.createElement('div');
            wrap.innerHTML = '<div class="fabricator-captcha-gate" data-sitekey="x">'
                + '<button type="button" class="fabricator-captcha-activate" disabled></button></div>';
            document.body.appendChild(wrap);
            var gate = wrap.querySelector('.fabricator-captcha-gate');
            var btn  = wrap.querySelector('.fabricator-captcha-activate');
            hooks.showCaptchaBlockedNotice(gate, btn);
            var notice = gate.querySelector('.fabricator-notice.fabricator-error');
            var text   = notice ? notice.textContent : '';
            document.body.removeChild(wrap);
            return (notice && text.length > 0)
                ? ok('showCaptchaBlockedNotice()', text)
                : ko('showCaptchaBlockedNotice()', '(no notice)', 'expected a non-empty .fabricator-notice.fabricator-error message');
        });

        run('CAPTCHA blocked: calling it twice does not duplicate the notice', function () {
            var wrap = document.createElement('div');
            wrap.innerHTML = '<div class="fabricator-captcha-gate" data-sitekey="x">'
                + '<button type="button" class="fabricator-captcha-activate" disabled></button></div>';
            document.body.appendChild(wrap);
            var gate = wrap.querySelector('.fabricator-captcha-gate');
            var btn  = wrap.querySelector('.fabricator-captcha-activate');
            hooks.showCaptchaBlockedNotice(gate, btn);
            hooks.showCaptchaBlockedNotice(gate, btn);
            var count = gate.querySelectorAll('.fabricator-notice.fabricator-error').length;
            document.body.removeChild(wrap);
            return count === 1
                ? ok('showCaptchaBlockedNotice() x2', count + ' notice(s)')
                : ko('showCaptchaBlockedNotice() x2', count + ' notice(s)', 'expected exactly 1 notice element, not a growing pile');
        });
    }

    if (typeof hooks.resetFormsOnBfcacheRestore === 'function') {
        run('bfcache restore: clears the durable "submitted" flag', function () {
            var wrap = document.createElement('div');
            wrap.className = 'fabricator-form-wrap';
            wrap.innerHTML = '<div class="fabricator-form-messages" style="display:block;"></div>'
                + '<form class="fabricator-form"><button type="submit" class="fabricator-submit-btn" data-fabricator-submitted="1"></button></form>';
            document.body.appendChild(wrap);
            hooks.resetFormsOnBfcacheRestore();
            var btn = wrap.querySelector('.fabricator-submit-btn');
            var stillSet = 'fabricatorSubmitted' in btn.dataset;
            document.body.removeChild(wrap);
            return !stillSet
                ? ok('btn.dataset.fabricatorSubmitted=1 → resetFormsOnBfcacheRestore()', 'flag cleared')
                : ko('btn.dataset.fabricatorSubmitted=1 → resetFormsOnBfcacheRestore()', 'flag still set', 'a restored page would let a second click fire another submission');
        });

        run('bfcache restore: hides the stale message box', function () {
            var wrap = document.createElement('div');
            wrap.className = 'fabricator-form-wrap';
            wrap.innerHTML = '<div class="fabricator-form-messages" style="display:block;">Thank you!</div>'
                + '<form class="fabricator-form"><button type="submit" class="fabricator-submit-btn"></button></form>';
            document.body.appendChild(wrap);
            hooks.resetFormsOnBfcacheRestore();
            var msgBox = wrap.querySelector('.fabricator-form-messages');
            var stillVisible = msgBox.style.display !== 'none';
            document.body.removeChild(wrap);
            return !stillVisible
                ? ok('message box visible → resetFormsOnBfcacheRestore()', 'display:none')
                : ko('message box visible → resetFormsOnBfcacheRestore()', 'still visible', 'a restored page would show a stale success/error message');
        });
    }

    /* ── Render results ──────────────────────────────────────────────────── */
    var container = document.getElementById('fabricator-js-tests');
    if (!container) { return; }
    var total = pass + fail;
    var badge = document.getElementById('fabricator-js-tab-badge');
    if (badge) {
        badge.className = 'fabricator-tab-badge ' + (fail === 0 ? 'fabricator-tab-badge--pass' : 'fabricator-tab-badge--fail');
        badge.innerHTML = fail === 0
            ? '<i class="fa-solid fa-check" aria-hidden="true"></i> ' + total
            : '<i class="fa-solid fa-xmark" aria-hidden="true"></i> ' + fail + '/' + total;
    }
    container.innerHTML = '<table class="fabricator-test-table">'
        + '<colgroup><col style="width:24px"><col style="width:26%"><col style="width:20%"><col style="width:28%"><col></colgroup>'
        + '<thead><tr>'
        + '<th></th>'
        + '<th>Test</th>'
        + '<th>Input</th>'
        + '<th>Output</th>'
        + '<th>Note</th>'
        + '</tr></thead>'
        + '<tbody>' + rows.join('') + '</tbody></table>';
    if (window.fabricatorCollapseSections) {
        window.fabricatorCollapseSections(container.querySelector('table'));
    }
}());
