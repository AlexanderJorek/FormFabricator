/*!
 * FormFabricator — Form editor particle background
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';
    var canvas = document.getElementById('forge-particle-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var mouse = { x: -9999, y: -9999 };
    var _ah = getComputedStyle(document.documentElement).getPropertyValue('--forge-admin-accent').trim() || '#2271b1';
    var _rgb = function (h) { return parseInt(h.slice(1, 3), 16) + ',' + parseInt(h.slice(3, 5), 16) + ',' + parseInt(h.slice(5, 7), 16); };
    var DOTS = Math.min(120, Math.max(40, Math.round(window.innerWidth * window.innerHeight / 26000)));
    var LINK = 150, SPEED = 1.0, COLOR = _rgb(_ah);
    var particles = [], paused = false, FRAME_MS = 1000 / 30;
    function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
    function rand(a, b) { return a + Math.random() * (b - a); }
    function init() {
        particles = [];
        for (var i = 0; i < DOTS; i++) {
            particles.push({
                x: rand(0, canvas.width), y: rand(0, canvas.height),
                vx: rand(-SPEED, SPEED), vy: rand(-SPEED, SPEED), r: rand(2, 3.5)
            });
        }
    }
    function draw() {
        if (paused) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        for (var i = 0; i < particles.length; i++) {
            var p = particles[i];
            p.x += p.vx; p.y += p.vy;
            if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
            if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
        }
        ctx.lineWidth = 1;
        for (var i = 0; i < particles.length; i++) {
            for (var j = i + 1; j < particles.length; j++) {
                var dx = particles[i].x - particles[j].x, dy = particles[i].y - particles[j].y;
                var d = Math.sqrt(dx * dx + dy * dy);
                if (d < LINK) {
                    ctx.beginPath(); ctx.moveTo(particles[i].x, particles[i].y);
                    ctx.lineTo(particles[j].x, particles[j].y);
                    ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - d / LINK) * 0.3 + ')';
                    ctx.stroke();
                }
            }
            var mdx = particles[i].x - mouse.x, mdy = particles[i].y - mouse.y;
            var md = Math.sqrt(mdx * mdx + mdy * mdy);
            if (md < LINK) {
                ctx.beginPath(); ctx.moveTo(particles[i].x, particles[i].y);
                ctx.lineTo(mouse.x, mouse.y);
                ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - md / LINK) * 0.55 + ')';
                ctx.stroke();
            }
        }
        ctx.fillStyle = 'rgba(' + COLOR + ', 0.5)';
        for (var i = 0; i < particles.length; i++) {
            ctx.beginPath(); ctx.arc(particles[i].x, particles[i].y, particles[i].r, 0, Math.PI * 2); ctx.fill();
        }
        setTimeout(function () { requestAnimationFrame(draw); }, FRAME_MS - 2);
    }
    document.addEventListener('mousemove', function (e) { mouse.x = e.clientX; mouse.y = e.clientY; });
    document.addEventListener('visibilitychange', function () {
        paused = document.hidden;
        if (!paused) requestAnimationFrame(draw);
    });
    window.addEventListener('resize', function () { resize(); init(); });
    resize(); init(); requestAnimationFrame(draw);
}());
