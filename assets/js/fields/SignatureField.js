function (root) {
    function initSignature(wrap) {
        if (wrap._fabricatorCanvasInited) return;
        wrap._fabricatorCanvasInited = true;
        var canvas   = wrap.querySelector('.fabricator-signature-canvas');
        var input    = wrap.querySelector('input[type="hidden"]');
        var clearBtn = wrap.querySelector('.fabricator-signature-clear');
        if (!canvas || !input) return;
        var ctx     = canvas.getContext('2d');
        var stroke  = parseFloat(wrap.dataset.stroke || '2');
        var fmt     = wrap.dataset.format || 'png';
        var drawing  = false;
        var leftArea = false;
        var drew     = false;
        var lastW = 0;
        var lastH = 0;
        /* Turning a phone resizes the pad twice while the snapshot still loads: the second resize reuses that snapshot,
           and only the latest one paints. */
        var pendingSnap = null;
        var resizeGen   = 0;
        /* Clearing the pad: a redraw still loading must not paint the old drawing back. */
        function cancelRedraw() {
            pendingSnap = null;
            resizeGen++;
        }
        function resize() {
            var rect  = canvas.getBoundingClientRect();
            var ratio = window.devicePixelRatio || 1;
            var cssW  = rect.width  || canvas.offsetWidth;
            var fallH = parseFloat(canvas.getAttribute('height') || '160');
            var cssH  = rect.height || canvas.offsetHeight || fallH;
            if (!cssW || !cssH) { return; } // still hidden — ResizeObserver will retry
            if (cssW === lastW && cssH === lastH) { return; } // nothing to redraw
            var snap  = pendingSnap || (lastW ? canvas.toDataURL() : null);
            lastW = cssW;
            lastH = cssH;
            canvas.width  = Math.round(cssW * ratio);
            canvas.height = Math.round(cssH * ratio);
            ctx.scale(ratio, ratio);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, cssW, cssH);
            ctx.strokeStyle = '#1d2327';
            ctx.lineWidth   = stroke;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            var gen = ++resizeGen;
            pendingSnap = null;
            if (snap && snap !== 'data:,') {
                pendingSnap = snap;
                var img = new Image();
                img.onload = function () {
                    if (gen !== resizeGen) { return; } // a later resize paints it at the newer size
                    ctx.drawImage(img, 0, 0, cssW, cssH);
                    pendingSnap = null;
                };
                img.src = snap;
            }
        }
        function pos(e) {
            var rect = canvas.getBoundingClientRect();
            var src  = e.touches ? e.touches[0] : e;
            return { x: src.clientX - rect.left, y: src.clientY - rect.top };
        }
        function start(e) {
            e.preventDefault();
            drawing = true;
            var p = pos(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        }
        function move(e) {
            if (!drawing) return;
            e.preventDefault();
            var p = pos(e);
            if (leftArea) {
                ctx.beginPath(); ctx.moveTo(p.x, p.y); leftArea = false;
            } else {
                ctx.lineTo(p.x, p.y); ctx.stroke();
                drew = true;
            }
        }
        function end() {
            if (!drawing) return;
            drawing = false;
            /* A tap that draws nothing left a blank white image behind, which validate() accepts — so a required
               signature, including the direct debit mandate's, could be submitted empty. */
            if (!drew) return;
            input.value = canvas.toDataURL(fmt === 'jpeg' ? 'image/jpeg' : 'image/png');
        }
        canvas.addEventListener('mousedown',  start, { passive: false });
        canvas.addEventListener('mousemove',  move,  { passive: false });
        canvas.addEventListener('mouseleave', function () { leftArea = true; });
        document.addEventListener('mouseup',  end);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove',  move,  { passive: false });
        canvas.addEventListener('touchend',   end);
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                input.value = '';
                drew        = false;
                cancelRedraw();
            });
        }
        /* Typing the name instead of drawing (WCAG 2.1.1), into the same hidden input a drawing uses. */
        var modeBtn  = wrap.querySelector('.fabricator-signature-mode');
        var typedRow = wrap.querySelector('.fabricator-signature-typed-row');
        var typed    = typedRow && typedRow.querySelector('.fabricator-signature-typed');
        var hint     = wrap.querySelector('.fabricator-signature-hint');
        function setTyping(on) {
            if (!modeBtn || !typed) return;
            wrap.classList.toggle('fabricator-signature-wrap--typed', on);
            canvas.hidden   = on;
            typedRow.hidden = !on;
            if (clearBtn) clearBtn.hidden = on;
            if (hint) hint.hidden = on;
            modeBtn.textContent = on ? modeBtn.dataset.drawLabel : modeBtn.dataset.typeLabel;
            modeBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            // Only one way signs: switching drops what the other way held.
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            drew        = false;
            cancelRedraw();
            typed.value = '';
            input.value = '';
            if (on) {
                typed.focus();
            } else {
                // Back to drawing at its proper size, now that the canvas is shown again.
                resize();
            }
        }
        if (modeBtn && typed) {
            modeBtn.addEventListener('click', function () {
                setTyping(!wrap.classList.contains('fabricator-signature-wrap--typed'));
            });
            typed.addEventListener('input', function () {
                // Trimmed as PHP's trim() does, so a name of spaces is no signature here either.
                input.value = typed.value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
            });
        }
        var ownerForm = canvas.closest('form');
        if (ownerForm) {
            ownerForm.addEventListener('reset', function () {
                if (wrap.classList.contains('fabricator-signature-wrap--typed')) {
                    setTyping(false);
                }
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                input.value = '';
                drew        = false;
                cancelRedraw();
            });
        }
        resize();
        window.addEventListener('resize', resize);
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(function (entries) {
                if (entries[0].contentRect.width > 0) { resize(); }
            }).observe(canvas);
        }
    }
    (root || document).querySelectorAll('.fabricator-signature-wrap').forEach(initSignature);
}