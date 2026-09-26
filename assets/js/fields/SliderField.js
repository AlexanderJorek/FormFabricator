function (root) {
    root.querySelectorAll('.fabricator-slider-wrap').forEach(function (wrap) {
        /* Idempotent: front.js re-runs every field init after a successful submit, and a second pass stacked listeners
           whose closures held stale values and fought the current ones on keyboard input. */
        if (wrap._fabricatorSliderInited) return;
        wrap._fabricatorSliderInited = true;
        /* Guarded like SliderField::bounds(): step <= 0 or max <= min divided by zero below (NaN thumb positions). */
        var min  = parseFloat(wrap.dataset.min);
        var max  = parseFloat(wrap.dataset.max);
        var step = parseFloat(wrap.dataset.step);
        if (!isFinite(min)) min = 0;
        if (!(step > 0)) step = 1;
        if (!isFinite(max) || max <= min) max = min + step;
        var isRange = wrap.classList.contains('fabricator-slider-wrap--range');
        var track = wrap.querySelector('.fabricator-slider-track');
        var fill  = wrap.querySelector('.fabricator-slider-fill');
        var ownerForm = wrap.closest('form');
        function snap(raw) {
            var stepped = Math.round((raw - min) / step) * step + min;
            return Math.min(max, Math.max(min, parseFloat(stepped.toFixed(10))));
        }
        function pct(val) { return (val - min) / (max - min) * 100; }
        function valFromX(clientX) {
            var rect  = track.getBoundingClientRect();
            var ratio = rect.width ? Math.min(1, Math.max(0, (clientX - rect.left) / rect.width)) : 0;
            return snap(min + ratio * (max - min));
        }
        if (!isRange) {
            var thumb  = wrap.querySelector('.fabricator-slider-thumb');
            var input  = wrap.querySelector('input[type="hidden"]');
            var disp   = wrap.querySelector('.fabricator-slider-value');
            var initVal = parseFloat(wrap.dataset.value);
            if (!isFinite(initVal)) initVal = min;
            var curVal = initVal;
            function setVal(v, writeInput) {
                curVal = v;
                var p = pct(v);
                thumb.style.left = p + '%';
                fill.style.width = p + '%';
                if (writeInput && input) input.value = v;
                if (disp)  disp.textContent = v;
                wrap.querySelector('.fabricator-slider-custom').setAttribute('aria-valuenow', v);
            }
            /* Visual-only init — hidden input stays '' until user interacts */
            setVal(curVal, false);
            function startDrag(clientX) {
                setVal(valFromX(clientX), true);
                function onMove(e) {
                    if (e.touches) { e.preventDefault(); }
                    setVal(valFromX(e.touches ? e.touches[0].clientX : e.clientX), true);
                }
                function onUp() {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    document.removeEventListener('touchmove', onMove);
                    document.removeEventListener('touchend', onUp);
                }
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
                document.addEventListener('touchmove', onMove, { passive: false });
                document.addEventListener('touchend', onUp);
            }
            track.addEventListener('mousedown',  function (e) { e.preventDefault(); startDrag(e.clientX); });
            track.addEventListener('touchstart', function (e) { e.preventDefault(); startDrag(e.touches[0].clientX); }, { passive: false });
            var slider = wrap.querySelector('.fabricator-slider-custom');
            slider.addEventListener('keydown', function (e) {
                var delta = 0;
                if (e.key === 'ArrowRight' || e.key === 'ArrowUp')   delta =  step;
                if (e.key === 'ArrowLeft'  || e.key === 'ArrowDown') delta = -step;
                if (e.key === 'PageUp')   delta =  step * 10;
                if (e.key === 'PageDown') delta = -step * 10;
                if (e.key === 'Home') { e.preventDefault(); setVal(min, true); return; }
                if (e.key === 'End')  { e.preventDefault(); setVal(max, true); return; }
                if (delta) { e.preventDefault(); setVal(snap(curVal + delta), true); }
            });
            /* form.reset() leaves hidden inputs alone, so clear the value explicitly and put the thumb back. */
            if (ownerForm) {
                ownerForm.addEventListener('fabricator:reset', function () {
                    if (input) input.value = '';
                    setVal(initVal, false);
                });
            }
        } else {
            var thumbFrom = wrap.querySelector('.fabricator-slider-thumb--from');
            var thumbTo   = wrap.querySelector('.fabricator-slider-thumb--to');
            var inputFrom = wrap.querySelector('.fabricator-slider-input-from');
            var inputTo   = wrap.querySelector('.fabricator-slider-input-to');
            var dispFrom  = wrap.querySelector('.fabricator-slider-from-display');
            var dispTo    = wrap.querySelector('.fabricator-slider-to-display');
            var initFrom = parseFloat(wrap.dataset.from);
            var initTo   = parseFloat(wrap.dataset.to);
            if (!isFinite(initFrom)) initFrom = min;
            if (!isFinite(initTo))   initTo   = max;
            var from = initFrom;
            var to   = initTo;
            function setRange(writeInput) {
                var pFrom = pct(from), pTo = pct(to);
                thumbFrom.style.left = pFrom + '%';
                thumbTo.style.left   = pTo   + '%';
                fill.style.left  = pFrom + '%';
                fill.style.width = (pTo - pFrom) + '%';
                if (writeInput && inputFrom) inputFrom.value = from;
                if (writeInput && inputTo)   inputTo.value   = to;
                if (dispFrom)  dispFrom.textContent = from;
                if (dispTo)    dispTo.textContent   = to;
                thumbFrom.setAttribute('aria-valuenow', from);
                thumbTo.setAttribute('aria-valuenow',   to);
            }
            /* Visual-only init */
            setRange(false);
            function dragThumb(isFrom, clientX) {
                var v = valFromX(clientX);
                if (isFrom) from = Math.min(v, to   - step);
                else        to   = Math.max(v, from + step);
                setRange(true);
                function onMove(e) {
                    if (e.touches) { e.preventDefault(); }
                    var v2 = valFromX(e.touches ? e.touches[0].clientX : e.clientX);
                    if (isFrom) from = Math.min(v2, to   - step);
                    else        to   = Math.max(v2, from + step);
                    setRange(true);
                }
                function onUp() {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    document.removeEventListener('touchmove', onMove);
                    document.removeEventListener('touchend', onUp);
                }
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
                document.addEventListener('touchmove', onMove, { passive: false });
                document.addEventListener('touchend', onUp);
            }
            /* Keyboard control for each thumb (WCAG 2.1.1): both are focusable role="slider" elements that previously
               ignored every key. The thumbs never cross, as with dragging. */
            function keyThumb(isFrom, e) {
                var current = isFrom ? from : to;
                var target;
                if (e.key === 'ArrowRight' || e.key === 'ArrowUp')        target = current + step;
                else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown')  target = current - step;
                else if (e.key === 'PageUp')                              target = current + step * 10;
                else if (e.key === 'PageDown')                            target = current - step * 10;
                else if (e.key === 'Home')                                target = min;
                else if (e.key === 'End')                                 target = max;
                else return;
                e.preventDefault();
                if (isFrom) from = Math.min(snap(target), to - step);
                else        to   = Math.max(snap(target), from + step);
                setRange(true);
            }
            thumbFrom.addEventListener('mousedown',  function (e) { e.preventDefault(); dragThumb(true,  e.clientX); });
            thumbTo.addEventListener('mousedown',    function (e) { e.preventDefault(); dragThumb(false, e.clientX); });
            thumbFrom.addEventListener('touchstart', function (e) { e.preventDefault(); dragThumb(true,  e.touches[0].clientX); }, { passive: false });
            thumbTo.addEventListener('touchstart',   function (e) { e.preventDefault(); dragThumb(false, e.touches[0].clientX); }, { passive: false });
            thumbFrom.addEventListener('keydown', function (e) { keyThumb(true, e); });
            thumbTo.addEventListener('keydown',   function (e) { keyThumb(false, e); });
            if (ownerForm) {
                ownerForm.addEventListener('fabricator:reset', function () {
                    if (inputFrom) inputFrom.value = '';
                    if (inputTo)   inputTo.value   = '';
                    from = initFrom;
                    to   = initTo;
                    setRange(false);
                });
            }
        }
    });
}
