/*!
 * FormFabricator — PDF Verification admin page
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';

    var i18n = (window.ForgeVerifyPage && window.ForgeVerifyPage.i18n) || {};

    // ── Drag & drop + file queue (main upload form) ──
    var dropZone   = document.getElementById('drop-zone');
    var fileInput  = document.getElementById('pdf-input');
    var fileQueue  = document.getElementById('forge-pdf-file-queue');
    var verifyBtn  = document.getElementById('forge-pdf-verify-btn');

    if (dropZone && fileInput && fileQueue && verifyBtn) {
        var stagedFiles = [];

        var mergeFiles = function (incoming) {
            var names = new Set(stagedFiles.map(function (f) { return f.name; }));
            Array.from(incoming).forEach(function (f) { if (!names.has(f.name)) stagedFiles.push(f); });
            rebuildInput();
            renderQueue();
        };

        var removeFile = function (name) {
            stagedFiles = stagedFiles.filter(function (f) { return f.name !== name; });
            rebuildInput();
            renderQueue();
        };

        function rebuildInput() {
            var dt = new DataTransfer();
            stagedFiles.forEach(function (f) { dt.items.add(f); });
            fileInput.files = dt.files;
        }

        function renderQueue() {
            fileQueue.innerHTML = '';
            stagedFiles.forEach(function (f) {
                var li   = document.createElement('li');
                var name = document.createElement('span');
                name.textContent = f.name;
                var btn  = document.createElement('button');
                btn.type = 'button';
                btn.className = 'forge-pdf-remove-file';
                btn.title = i18n.remove || 'Remove';
                btn.textContent = '×';
                btn.addEventListener('click', function () { removeFile(f.name); });
                li.appendChild(name);
                li.appendChild(btn);
                fileQueue.appendChild(li);
            });
            verifyBtn.disabled = stagedFiles.length === 0;
        }

        dropZone.addEventListener('click', function () { fileInput.click(); });
        dropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            dropZone.classList.add('forge-pdf-dragover');
        });
        dropZone.addEventListener('dragleave', function (e) {
            e.preventDefault();
            dropZone.classList.remove('forge-pdf-dragover');
        });
        dropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropZone.classList.remove('forge-pdf-dragover');
            mergeFiles(e.dataTransfer.files);
        });
        fileInput.addEventListener('change', function () { mergeFiles(fileInput.files); });

        var uploadForm    = document.getElementById('pdf-upload-form');
        var uploadOverlay = document.getElementById('forge-pdf-upload-overlay');

        /* Shows the "still uploading" indicator, then lets the browser's own real form
           submission proceed (does NOT preventDefault / intercept it). With several large
           PDFs the native POST's upload phase is dominated by the visitor's own upload
           bandwidth and can look hung with no feedback otherwise. Deliberately NOT
           XHR-driven: swapping the response into the live document via document.write()
           does not reset the JS global scope, so the response's own <script> tags collide
           with the still-alive top-level const/let bindings from the original load and throw
           redeclaration errors. A real native submit sidesteps that via an actual fresh
           navigation — the only cost is no byte-accurate percentage, just an indeterminate
           spinner. */
        // Scope to #wpcontent, not the full viewport (same pattern as admin-settings.js).
        function positionUploadOverlay() {
            if (!uploadOverlay) { return; }
            var wpc = document.getElementById('wpcontent');
            if (!wpc) { return; }
            var r = wpc.getBoundingClientRect();
            uploadOverlay.style.top    = r.top  + window.scrollY + 'px';
            uploadOverlay.style.left   = r.left + window.scrollX + 'px';
            uploadOverlay.style.width  = r.width + 'px';
            uploadOverlay.style.height = Math.max(r.height, window.innerHeight - r.top) + 'px';
        }
        positionUploadOverlay();
        window.addEventListener('resize', positionUploadOverlay);

        var showUploadingOverlay = function () {
            var idle = document.getElementById('forge-pdf-idle-state');
            var more = document.getElementById('forge-pdf-scan-more-btn');
            if (idle) idle.style.display = 'none';
            if (more) more.classList.add('forge-pdf-visible');
            if (uploadOverlay) {
                positionUploadOverlay();
                uploadOverlay.classList.add('forge-pdf-open');
            }
        };

        if (uploadForm) {
            uploadForm.addEventListener('submit', showUploadingOverlay);
        }

        // ── Scan-more modal ──
        var backdrop      = document.getElementById('forge-pdf-scan-more-backdrop');
        var scanMoreBtn   = document.getElementById('forge-pdf-scan-more-btn');
        var dropZoneMore  = document.getElementById('drop-zone-more');
        var fileInputMore = document.getElementById('pdf-input-more');
        var fileQueueMore = document.getElementById('forge-pdf-file-queue-more');
        var verifyMoreBtn = document.getElementById('forge-pdf-verify-more-btn');

        if (backdrop && scanMoreBtn && dropZoneMore && fileInputMore && fileQueueMore && verifyMoreBtn) {
            var stagedFilesMore = [];

            var openScanMore = function () { backdrop.classList.add('forge-pdf-open'); };
            var closeScanMore = function () {
                backdrop.classList.remove('forge-pdf-open');
                stagedFilesMore = [];
                renderQueueMore();
            };

            scanMoreBtn.addEventListener('click', openScanMore);
            var closeBtn = document.getElementById('forge-pdf-scan-more-close');
            if (closeBtn) closeBtn.addEventListener('click', closeScanMore);
            backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeScanMore(); });

            var mergeFilesMore = function (incoming) {
                var names = new Set(stagedFilesMore.map(function (f) { return f.name; }));
                Array.from(incoming).forEach(function (f) { if (!names.has(f.name)) stagedFilesMore.push(f); });
                renderQueueMore();
            };

            var removeFileMore = function (name) {
                stagedFilesMore = stagedFilesMore.filter(function (f) { return f.name !== name; });
                renderQueueMore();
            };

            function renderQueueMore() {
                fileQueueMore.innerHTML = '';
                stagedFilesMore.forEach(function (f) {
                    var li   = document.createElement('li');
                    var name = document.createElement('span');
                    name.textContent = f.name;
                    var btn  = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'forge-pdf-remove-file';
                    btn.title = i18n.remove || 'Remove';
                    btn.textContent = '×';
                    btn.addEventListener('click', function () { removeFileMore(f.name); });
                    li.appendChild(name);
                    li.appendChild(btn);
                    fileQueueMore.appendChild(li);
                });
                verifyMoreBtn.disabled = stagedFilesMore.length === 0;
            }

            dropZoneMore.addEventListener('click', function () { fileInputMore.click(); });
            dropZoneMore.addEventListener('dragover', function (e) {
                e.preventDefault();
                dropZoneMore.classList.add('forge-pdf-dragover');
            });
            dropZoneMore.addEventListener('dragleave', function (e) {
                e.preventDefault();
                dropZoneMore.classList.remove('forge-pdf-dragover');
            });
            dropZoneMore.addEventListener('drop', function (e) {
                e.preventDefault();
                dropZoneMore.classList.remove('forge-pdf-dragover');
                mergeFilesMore(e.dataTransfer.files);
            });
            fileInputMore.addEventListener('change', function () { mergeFilesMore(fileInputMore.files); });

            verifyMoreBtn.addEventListener('click', function () {
                if (!stagedFilesMore.length) return;
                // Inject files into the main form and submit.
                var dt = new DataTransfer();
                stagedFilesMore.forEach(function (f) { dt.items.add(f); });
                fileInput.files = dt.files;
                closeScanMore();
                scanMoreBtn.classList.remove('forge-pdf-visible');
                // form.submit() bypasses the "submit" event entirely (a well-known DOM
                // quirk) — show the overlay explicitly since that handler won't fire.
                showUploadingOverlay();
                uploadForm.submit();
            });
        }
    }

    // ── Collapsible detail sections ──
    if (!window.FORGE_PDF_IMAGE_TOGGLE_READY) {
        window.FORGE_PDF_IMAGE_TOGGLE_READY = true;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.forge-pdf-toggle');
            if (!btn) return;

            e.preventDefault();

            var id = btn.getAttribute('data-target');
            if (!id) return;

            var el = document.getElementById(id);
            if (!el) return;

            var isHidden = el.classList.contains('forge-pdf-hidden');
            el.classList.toggle('forge-pdf-hidden', !isHidden);
            el.classList.toggle('forge-pdf-visible', isHidden);

            // Rotate arrow on sub-toggle buttons.
            btn.classList.toggle('forge-pdf-open', isHidden);

            // Show or hide the parent section wrapper to match content visibility.
            var section = el.closest('.forge-pdf-detail-section');
            if (section) {
                if (isHidden) {
                    section.style.display = 'block';
                } else {
                    // Only hide the section if no other content inside is still open.
                    var stillOpen = section.querySelector(
                        '.forge-pdf-detail-content:not(.forge-pdf-hidden), .forge-pdf-visible'
                    );
                    if (!stillOpen) {
                        section.style.display = 'none';
                    }
                }
            }
        });

        // Reveal any section whose content was auto-opened in PHP (e.g. FAIL state).
        document.querySelectorAll('.forge-pdf-detail-section').forEach(function (sec) {
            var content = sec.querySelector('.forge-pdf-detail-content');
            if (content && !content.classList.contains('forge-pdf-hidden')) {
                sec.style.display = 'block';
            }
        });
    }

    // ── Image slot materialization ──
    if (!window.FORGE_PDF_IMAGE_SLOT_READY) {
        window.FORGE_PDF_IMAGE_SLOT_READY = true;

        var processImageSlots = function (root) {
            root = root || document;

            var blocks = root.querySelectorAll('.img-slot-content');
            if (!blocks.length) return;

            blocks.forEach(function (block) {
                var uid = block.dataset.slot;
                var slot = document.getElementById(uid);

                if (!slot) {
                    return;
                }

                slot.innerHTML = '';
                slot.appendChild(block);

                // Start hidden but layout-safe.
                slot.classList.add('forge-pdf-hidden');

                // Ensure images trigger reflow when loaded.
                slot.querySelectorAll('img').forEach(function (img) {
                    if (!img.complete) {
                        img.onload = function () { img.style.height = 'auto'; };
                    }
                });
            });
        };

        document.addEventListener('DOMContentLoaded', function () {
            processImageSlots(document);
        });

        // Expose for AJAX.
        window.FORGE_PDF_processImageSlots = processImageSlots;
    }
}());
