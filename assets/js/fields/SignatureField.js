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
        var lastW = 0;
        function resize() {
            var rect  = canvas.getBoundingClientRect();
            var ratio = window.devicePixelRatio || 1;
            var cssW  = rect.width  || canvas.offsetWidth;
            var fallH = parseFloat(canvas.getAttribute('height') || '160');
            var cssH  = rect.height || canvas.offsetHeight || fallH;
            if (!cssW || !cssH) { return; } // still hidden — ResizeObserver will retry
            var snap  = lastW ? canvas.toDataURL() : null;
            lastW = cssW;
            canvas.width  = Math.round(cssW * ratio);
            canvas.height = Math.round(cssH * ratio);
            ctx.scale(ratio, ratio);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, cssW, cssH);
            ctx.strokeStyle = '#1d2327';
            ctx.lineWidth   = stroke;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            if (snap && snap !== 'data:,') {
                var img = new Image();
                img.onload = function () { ctx.drawImage(img, 0, 0, cssW, cssH); };
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
            }
        }
        function end() {
            if (!drawing) return;
            drawing = false;
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
            });
        }
        var ownerForm = canvas.closest('form');
        if (ownerForm) {
            ownerForm.addEventListener('reset', function () {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                input.value = '';
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