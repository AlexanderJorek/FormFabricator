/*!
 * FormFabricator — PDF Layout editor admin page
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';
    var pageData = window.FabricatorPdfLayoutPage || {};
    var I18N = pageData.i18n || {};
    var DATA = pageData.data || {};

(function () {
    /* Mirrors the Settings → Feldausgabe option so the browser preview
       matches what the actual mPDF template (layout.php) renders. */
    var fieldLayoutMode = DATA.fieldLayoutMode;

    /* Same sample content as the server-rendered PDF preview so the browser preview matches it exactly. */
    var dummyTextFields = DATA.dummyText;
    var dummySignatureSrc = 'data:image/png;base64,'
        + DATA.dummySignature;
    var dummyUploadSrc = 'data:image/png;base64,'
        + DATA.dummyUpload;

    /* Same msgid as Generator.php's footerHtml() so this preview matches the real PDF, not a hardcoded duplicate. */
        
    var pageOfTpl = I18N.pageOfPage;

    /* Auto-dismiss save notice: wait 5 s, then fade out over 2 s */
    var notice = document.querySelector('.fabricator-settings-notice');
    if (notice) {
        setTimeout(function () {
            notice.style.opacity = '0';
            setTimeout(function () { notice.style.display = 'none'; }, 2000);
        }, 5000);
    }

    /* particle canvas */
    var canvas = document.getElementById('fabricator-particle-canvas');
    if (canvas) {
        var ctx = canvas.getContext('2d'), mouse = {x:-9999,y:-9999};
        var _ah=getComputedStyle(document.documentElement).getPropertyValue('--fabricator-admin-accent').trim()||'#2271b1';
        var _rgb=function(h){return parseInt(h.slice(1,3),16)+','+parseInt(h.slice(3,5),16)+','+parseInt(h.slice(5,7),16);};
        var DOTS=Math.min(120,Math.max(40,Math.round(innerWidth*innerHeight/26000)));
        var LINK=150,SPEED=1.0,COLOR=_rgb(_ah),particles=[],paused=false,FRAME_MS=1000/30;
        function resize(){ canvas.width=innerWidth; canvas.height=innerHeight; }
        function rand(a,b){ return a+Math.random()*(b-a); }
        function initP(){
            particles=[];
            for(var i=0;i<DOTS;i++) particles.push({x:rand(0,canvas.width),y:rand(0,canvas.height),vx:rand(-SPEED,SPEED),vy:rand(-SPEED,SPEED),r:rand(2,3.5)});
        }
        function draw(){
            if(paused) return;
            ctx.clearRect(0,0,canvas.width,canvas.height);
            for(var i=0;i<particles.length;i++){var p=particles[i];p.x+=p.vx;p.y+=p.vy;if(p.x<0||p.x>canvas.width)p.vx*=-1;if(p.y<0||p.y>canvas.height)p.vy*=-1;}
            ctx.lineWidth=1;
            for(var i=0;i<particles.length;i++){
                for(var j=i+1;j<particles.length;j++){
                    var dx=particles[i].x-particles[j].x,dy=particles[i].y-particles[j].y,d=Math.sqrt(dx*dx+dy*dy);
                    if(d<LINK){ctx.beginPath();ctx.moveTo(particles[i].x,particles[i].y);ctx.lineTo(particles[j].x,particles[j].y);ctx.strokeStyle='rgba('+COLOR+','+(1-d/LINK)*0.3+')';ctx.stroke();}
                }
                var mdx=particles[i].x-mouse.x,mdy=particles[i].y-mouse.y,md=Math.sqrt(mdx*mdx+mdy*mdy);
                if(md<LINK){ctx.beginPath();ctx.moveTo(particles[i].x,particles[i].y);ctx.lineTo(mouse.x,mouse.y);ctx.strokeStyle='rgba('+COLOR+','+(1-md/LINK)*0.55+')';ctx.stroke();}
            }
            ctx.fillStyle='rgba('+COLOR+',0.5)';
            for(var i=0;i<particles.length;i++){ctx.beginPath();ctx.arc(particles[i].x,particles[i].y,particles[i].r,0,Math.PI*2);ctx.fill();}
            setTimeout(function(){requestAnimationFrame(draw);},FRAME_MS-2);
        }
        document.addEventListener('mousemove',function(e){mouse.x=e.clientX;mouse.y=e.clientY;});
        document.addEventListener('visibilitychange',function(){paused=document.hidden;if(!paused)requestAnimationFrame(draw);});
        window.addEventListener('resize',function(){resize();initP();});
        resize();initP();requestAnimationFrame(draw);
    }

    /* helpers */
    function $(id){ return document.getElementById(id); }
    function val(id){ var e=$(id); return e?e.value:''; }

    /* font family → CSS font-stack (approximates the mPDF font in browser preview) */
    /* Single quotes inside font names — these go into style="" attributes, so double quotes would break parsing */
    var fontMap = {
        'dejavusans':     'Arial, Helvetica, sans-serif',
        'dejavuserif':    "'Times New Roman', Times, serif",
        'dejavusansmono': "'DejaVu Sans Mono', 'Courier New', monospace",
        'freemono':       "'Courier New', Courier, monospace"
    };

    /* dummyTextFields comes from PHP (PDFLayoutEditor::dummyFields) — same
       content as the server-rendered PDF preview. */
    var sampleFields = dummyTextFields.map(function (f) {
        return {label: f.label, value: f.value};
    });

    /* mPDF renders A4 at 120 DPI: 120/25.4 = 4.7244 px/mm, 120/72 = 1.6667 px/pt */
    function mm(n){ return (n*4.7244).toFixed(1)+'px'; }
    function pt(n){ return (n*120/72).toFixed(1)+'px'; }

    function collectSettings(){
        var hidden=[];
        document.querySelectorAll('#fabricator-sections-sortable .fabricator-section-item').forEach(function(li){
            if(li.classList.contains('fabricator-section-hidden')) hidden.push(li.dataset.slug);
        });
        return {
            accent_color:    val('accent_color')||'#f59e0b',
            separator_color: val('separator_color')||'#c9cdd4',
            font_family:     val('font_family')||'dejavusans',
            font_size_body:  parseInt(val('font_size_body'))||11,
            title_size:      parseInt(val('title_size'))||14,
            footer_text:     val('footer_text'),
            margin_top:      parseInt(val('margin_top'))||15,
            margin_bottom:   parseInt(val('margin_bottom'))||15,
            margin_left:     parseInt(val('margin_left'))||15,
            margin_right:    parseInt(val('margin_right'))||15,
            section_hidden:  hidden
        };
    }

    var paper = $('fabricator-a4-paper');
    var stageInner = $('fabricator-preview-stage-inner');

    function scaleA4() {
        if (!stageInner) return;
        var papers = stageInner.querySelectorAll('.fabricator-a4-paper');
        var paperW = 992;
        var vw     = document.documentElement.clientWidth;

        if (vw > 1440) {
            papers.forEach(function(p) {
                p.style.width  = '';
                p.style.zoom   = '';
                p.style.transform = '';
                p.style.transformOrigin = '';
            });
            stageInner.style.width  = '';
            stageInner.style.height = '';
            return;
        }

        /* Grid container is a stable width reference — sizes to its outer context,
           not to its children, so it stays correct even when papers overflow. */
        var editorWrap  = document.querySelector('.fabricator-pdf-editor-wrap');
        var refW        = editorWrap ? editorWrap.clientWidth : Math.max(vw - 20, 100);
        var stageStyles = stageInner.parentElement ? window.getComputedStyle(stageInner.parentElement) : null;
        var padL        = stageStyles ? parseFloat(stageStyles.paddingLeft)  : 10;
        var padR        = stageStyles ? parseFloat(stageStyles.paddingRight) : 10;
        var available   = Math.max(refW - padL - padR, 100);
        var scale       = available / paperW;

        stageInner.style.width  = available + 'px';
        stageInner.style.height = '';   /* let flex column size naturally to zoomed papers */

        /* zoom (unlike transform:scale) affects layout flow, so flex gap and page stacking work without manual height hacks. */
        papers.forEach(function(p) {
            p.style.width = paperW + 'px';
            p.style.zoom  = String(scale);
        });
    }
    scaleA4();
    window.addEventListener('resize', scaleA4);

    function buildPreview(s){
        var ff  = fontMap[s.font_family]||'Arial,sans-serif';
        var fs  = pt(s.font_size_body);
        var pad = mm(s.margin_top)+' '+mm(s.margin_right)+' '+mm(s.margin_bottom)+' '+mm(s.margin_left);
        /* line-height:1.7 matches mPDF's default — browsers default to ~1.2
           which makes all block heights ~22px shorter than the real PDF. */
        var out = '<div style="font-family:'+ff+';font-size:'+fs+';line-height:1.6;color:#222;padding:'+pad+';box-sizing:border-box;">';

        var FIXED_ORDER = ['header','fields','signatures','metadata','legal'];
        FIXED_ORDER.forEach(function(slug){
            if(s.section_hidden.indexOf(slug)!==-1) return;

            if(slug==='header'){
                out+='<table style="width:100%;border-collapse:collapse;margin-bottom:10px;"><tr>';
                out+='<td style="text-align:right;vertical-align:middle;font-size:'+pt(s.title_size)+';'
                    +'font-weight:bold;color:#1d2327;">Beispielformular</td>';
                out+='</tr></table>';
            }

            if(slug==='fields'){
                sampleFields.forEach(function(f){
                    /* Mirror layout.php .field-block / .field-label / .field-separator-thin
                       / .field-value / .field-separator-thick CSS exactly */
                    if(fieldLayoutMode==='inline'){
                        out+='<div style="margin-bottom:14px;">';
                        out+='<span style="font-weight:700;font-size:'+pt(s.title_size)+';color:#222;">'+f.label+':</span> ';
                        out+='<span style="font-size:'+pt(s.font_size_body)+';color:#333;margin-bottom:5px;">'+f.value+'</span>';
                        out+='<div style="border-bottom:3px solid '+s.accent_color+';margin-top:2px;"></div>';
                        out+='</div>';
                    } else {
                        out+='<div style="margin-bottom:14px;">';
                        out+='<div style="font-weight:700;font-size:'+pt(s.title_size)+';margin-bottom:4px;color:#222;">'+f.label+'</div>';
                        out+='<div style="border-bottom:1px solid '+s.separator_color+';margin-bottom:4px;"></div>';
                        out+='<div style="font-size:'+pt(s.font_size_body)+';margin-bottom:5px;color:#333;">'+f.value+'</div>';
                        out+='<div style="border-bottom:3px solid '+s.accent_color+';margin-top:2px;"></div>';
                        out+='</div>';
                    }
                });
            }

            if(slug==='signatures'){
                /* Mirrors Generator.php's layout['image'] rendering. */
                /* width/height = natural PNG dimensions; explicit attrs keep sandbox measurement correct before async decode. */
                var imgStyle = 'max-width:100%;max-height:300px;border:1px solid #ccc;padding:4px;display:block;';
                /* Signature */
                out+='<div style="margin-bottom:14px;">';
                out+='<div style="font-weight:700;font-size:'+pt(s.title_size)+';margin-bottom:4px;color:#222;">Unterschrift</div>';
                out+='<div style="border-bottom:1px solid '+s.separator_color+';margin-bottom:4px;"></div>';
                out+='<div style="margin:8px 0;"><img src="'+dummySignatureSrc+'" width="300" height="80" style="'+imgStyle+'" alt="Unterschrift"></div>';
                out+='<div style="border-bottom:3px solid '+s.accent_color+';margin-top:2px;"></div>';
                out+='</div>';
                /* Upload / Anhang */
                out+='<div style="margin-bottom:14px;">';
                out+='<div style="font-weight:700;font-size:'+pt(s.title_size)+';margin-bottom:4px;color:#222;">Anhang</div>';
                out+='<div style="border-bottom:1px solid '+s.separator_color+';margin-bottom:4px;"></div>';
                out+='<div style="font-size:'+pt(s.font_size_body)+';margin-bottom:5px;color:#333;">beispiel-dokument.png</div>';
                out+='<div style="margin:8px 0;"><img src="'+dummyUploadSrc+'" width="300" height="80" style="'+imgStyle+'" alt="Anhang"></div>';
                out+='<div style="border-bottom:3px solid '+s.accent_color+';margin-top:2px;"></div>';
                out+='</div>';
            }

            if(slug==='metadata'){
                /* Labels mirror layout.php's real metadata block so this preview matches the generated PDF. */
                out+='<div style="margin:12px 0;padding:8px 10px;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;font-size:'+pt(8)+';color:#555;">';
                out+='<strong>' + I18N.metadata + '</strong><br>';
                out+=I18N.created + ' '+new Date().toLocaleString()+'<br>';
                out+=I18N.formLabel + ' Beispielformular';
                out+='</div>';
            }

            if(slug==='legal'){
                /* Same msgids as pdf-templates/layout.php's real legal-notice block. */
                out+='<p style="font-size:'+pt(7.5)+';color:#666;margin-top:6px;line-height:1.4;">';
                out+='<strong>' + I18N.legalNotice + '</strong> '
                    +I18N.legalNoticeBody;
                out+='</p>';
            }

            /* footer is rendered as a per-page overlay in updatePreview,
               not as inline content — skip here so it doesn't paginate. */
        });

        out+='</div>';
        return out;
    }

    var PAGE_HEIGHT_PX = 1402; /* A4 at 120dpi (mPDF), matches .fabricator-a4-paper */

    /* Splits buildPreview()'s single (unbounded) HTML output into multiple
       A4-sized pages by measuring top-level blocks in an offscreen sandbox —
       mirrors real pagination instead of one ever-growing sheet. */
    function paginate(s, fullHtml){
        var inner = fullHtml.replace(/^<div[^>]*>/, '').replace(/<\/div>\s*$/, '');
        var ff  = fontMap[s.font_family]||'Arial,sans-serif';
        var fs  = pt(s.font_size_body);
        var pad = mm(s.margin_top)+' '+mm(s.margin_right)+' '+mm(s.margin_bottom)+' '+mm(s.margin_left);

        var parser = document.createElement('div');
        parser.innerHTML = inner;
        var blocks = Array.prototype.slice.call(parser.children).map(function(el){
            return el.outerHTML;
        });

        var sandbox = document.createElement('div');
        sandbox.style.cssText = 'position:absolute;visibility:hidden;left:-99999px;top:0;'
            +'width:992px;box-sizing:border-box;font-family:'+ff+';font-size:'+fs
            +';line-height:1.6;padding:'+pad+';';
        document.body.appendChild(sandbox);

        var pages = [];
        var current = '';
        blocks.forEach(function(blockHtml){
            var trial = current + blockHtml;
            sandbox.innerHTML = trial;
            if(sandbox.offsetHeight > PAGE_HEIGHT_PX && current !== ''){
                pages.push(current);
                current = blockHtml;
            } else {
                current = trial;
            }
        });
        if(current || pages.length===0) pages.push(current);
        document.body.removeChild(sandbox);

        return {pages: pages, ff: ff, fs: fs, pad: pad, footerText: s.footer_text||''};
    }

    function updatePreview(){
        var s = collectSettings();
        var hi = $('fabricator-section-hidden-input');
        if(hi) hi.value = s.section_hidden.join(',');

        var stage = stageInner;
        if(!stage) return;

        var result = paginate(s, buildPreview(s));
        var total  = result.pages.length;

        /* Pre-process footer template once — page number substituted per-page */
        var footerBase = (result.footerText||'')
            .replace(/\{site_name\}/g, DATA.siteName)
            .replace(/\{site_url\}/g,  DATA.siteUrl)
            .replace(/\{date\}/g, new Date().toLocaleDateString())
            .replace(/\{nbpg\}/g, total);

        stage.innerHTML = '';
        result.pages.forEach(function(pageContent, idx){
            var pageEl = document.createElement('div');
            pageEl.className = 'fabricator-a4-paper';
            if(idx===0) pageEl.id = 'fabricator-a4-paper';

            var padParts = result.pad.split(' ');
            var pRight = padParts[1]||padParts[0];
            var pBottom = padParts[2]||padParts[0];
            var pLeft  = padParts[3]||padParts[1]||padParts[0];

            /* Mirror PHP footerHtml(): user text left, page numbers right,
               always present (page numbers shown even with no user text). */
            var pageNum = idx + 1;
            var userFt  = footerBase || '';
            var pageNumHtml = pageOfTpl.replace('%1$s', pageNum).replace('%2$s', total);
            var footerHtml;
            if(userFt){
                var userFtHtml = userFt.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
                footerHtml = '<table style="width:100%;border-collapse:collapse;font-size:'+pt(8)+';"><tr>'
                    +'<td style="text-align:left;color:#888;white-space:pre-wrap;">'+userFtHtml+'</td>'
                    +'<td style="text-align:right;white-space:nowrap;font-size:'+pt(10)+';">'
                    +pageNumHtml+'</td></tr></table>';
            } else {
                footerHtml = '<div style="text-align:right;font-size:'+pt(10)+';">'
                    +pageNumHtml+'</div>';
            }
            footerHtml = '<div style="position:absolute;bottom:0;left:0;right:0;'
                +'padding:0 '+pRight+' '+pBottom+' '+pLeft+';'
                +'background:#fff;box-sizing:border-box;">'
                +footerHtml+'</div>';

            pageEl.innerHTML = '<div style="font-family:'+result.ff+';font-size:'+result.fs+';'
                +'line-height:1.6;color:#222;padding:'+result.pad+';box-sizing:border-box;height:100%;overflow:hidden;">'
                +pageContent+'</div>'+footerHtml;
            stage.appendChild(pageEl);
        });
        paper = document.getElementById('fabricator-a4-paper');
        scaleA4();
    }

    /* range sliders */
    [
        ['font_size_body', 'font-size-body-val'],
        ['title_size',     'title-size-val'],
        ['margin_top',     'margin-top-val'],
        ['margin_right',   'margin-right-val'],
        ['margin_bottom',  'margin-bottom-val'],
        ['margin_left',    'margin-left-val'],
    ].forEach(function(pair){
        var inp = $(pair[0]), lbl = $(pair[1]);
        if(!inp||!lbl) return;
        inp.addEventListener('input', function(){ lbl.textContent=inp.value; updatePreview(); });
    });


    /* font & textarea */
    var fontSel = $('font_family'), footerTxt = $('footer_text');
    if(fontSel)  fontSel.addEventListener('change', updatePreview);
    if(footerTxt) footerTxt.addEventListener('input', updatePreview);

    /* Placeholder chips — insert token at cursor position */
    document.querySelectorAll('.fabricator-placeholder-chip').forEach(function(btn){
        btn.addEventListener('click', function(){
            var token = btn.dataset.insert;
            if(!footerTxt) return;
            footerTxt.focus();
            var start = footerTxt.selectionStart, end = footerTxt.selectionEnd;
            var val   = footerTxt.value;
            footerTxt.value = val.slice(0,start) + token + val.slice(end);
            footerTxt.selectionStart = footerTxt.selectionEnd = start + token.length;
            updatePreview();
        });
    });



    /* section hide/show toggles */
    var sortable = $('fabricator-sections-sortable');
    if(sortable){
        sortable.addEventListener('click', function(e){
            var btn = e.target.closest('.fabricator-section-toggle');
            if(!btn) return;
            var li  = btn.closest('.fabricator-section-item');
            var ico = btn.querySelector('i');
            if(li.classList.toggle('fabricator-section-hidden')){
                ico.className='fa-solid fa-eye-slash';
            } else {
                ico.className='fa-solid fa-eye';
            }
            updatePreview();
        });
    }

    /* ── PDF preview button ── */
    var pdfBtn = $('fabricator-pdf-preview-btn');
    if(pdfBtn){
        pdfBtn.addEventListener('click', function(){
            var origHtml = pdfBtn.innerHTML;
            pdfBtn.disabled = true;
            pdfBtn.innerHTML = '<span class="fabricator-spinner"></span> ' + I18N.generating;
            var fd = new FormData();
            fd.append('action', 'fabricator_forms_pdf_preview');
            fd.append('nonce',  DATA.nonce);
            fd.append('settings', JSON.stringify(collectSettings()));
            fetch(DATA.ajaxUrl, {method:'POST', body:fd})
                .then(function(r){
                    return r.text().then(function(text){
                        var resp;
                        try {
                            resp = JSON.parse(text);
                        } catch (e) {
                            /* Server returned something other than JSON (PHP warning/fatal,
                               wp_die() on nonce failure, etc.) — surface it instead of
                               reporting it as a generic network error. */
                            console.error('PDF preview: non-JSON response (HTTP ' + r.status + ')', text);
                            throw new Error(
                                
                                I18N.unexpectedResponse
                                    .replace('%d', r.status)
                            );
                        }
                        return resp;
                    });
                })
                .then(function(resp){
                    if(resp.success && resp.data.pdf_b64){
                        var bin  = atob(resp.data.pdf_b64);
                        var buf  = new Uint8Array(bin.length);
                        for(var i=0;i<bin.length;i++) buf[i]=bin.charCodeAt(i);
                        var blob = new Blob([buf],{type:'application/pdf'});
                        var url  = URL.createObjectURL(blob);
                        window.open(url,'_blank');
                        setTimeout(function(){ URL.revokeObjectURL(url); }, 30000);
                    } else {
                        alert((resp.data && resp.data.message) || I18N.pdfNotGenerated);
                    }
                })
                .catch(function(err){
                    if (err && err.message) {
                        /* Already logged above (non-JSON response case) or is our own thrown Error. */
                        alert(err.message);
                    } else {
                        console.error('PDF preview: request failed', err);
                        alert(I18N.requestUnreachable);
                    }
                })
                .finally(function(){
                    pdfBtn.disabled = false;
                    pdfBtn.innerHTML = origHtml;
                });
        });
    }

    /* ================================================================
     * Header Builder
     * ================================================================ */
    var HB_COLS = 42;   /* A4 at 5 mm/cell */
    var HB_CELL = 15;   /* px per cell */
    /* Localized labels for the header-builder property panel — built server-side
       so this admin-only editor UI is translated like the rest of the plugin
       instead of hardcoding a single language. */
    var hbi18n = I18N.hb;
    var hbLayout   = { rows: 8, elements: [] };
    /* Initialize from saved DB value immediately so preview works without opening the modal */
    (function(){
        var inp = document.getElementById('fabricator-header-layout-input');
        if(inp && inp.value){ try{ hbLayout = JSON.parse(inp.value); }catch(e){} }
        if(!hbLayout || !Array.isArray(hbLayout.elements)) hbLayout = { rows:8, elements:[] };
    })();
    var hbSnapshot = null; /* for cancel */
    var hbSel      = null; /* selected element id */
    var hbNextId   = 1;
    var hbDrag     = null;
    /* hbDrag = { type:'move'|'resize', dir, elId, mx0, my0, ex0, ey0, ew0, eh0 } */

    var hbModal  = document.getElementById('fabricator-hb-modal');
    var hbCanvas = document.getElementById('fabricator-hb-canvas');
    var hbProps  = document.getElementById('fabricator-hb-props');

    function hbEsc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    /* Strips <script>, on*="" handlers and javascript:/vbscript: URIs before the
       live (unsaved) header-builder preview assigns element html into innerHTML.
       sanitizeHeaderLayout() is the server-side authority on save; this is just
       defense-in-depth for the in-browser preview of not-yet-saved content. */
    function hbSanitizePreviewHtml(html){
        html = String(html||'').replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
        html = html.replace(/[\s\/]+on[a-z]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '');
        html = html.replace(/\b(href|src)\s*=\s*(["'])\s*(?:javascript|vbscript)\s*:[^"']*\2/gi, '$1=$2#$2');
        return html;
    }
    function hbGetEl(id){ return hbLayout.elements.find(function(e){ return e.id===id; }); }

    function hbOpen(){
        var inp = document.getElementById('fabricator-header-layout-input');
        if(inp && inp.value){ try{ hbLayout = JSON.parse(inp.value); }catch(e){} }
        if(!hbLayout || !Array.isArray(hbLayout.elements)) hbLayout = { rows:8, elements:[] };
        hbSnapshot = JSON.parse(JSON.stringify(hbLayout));
        hbSel = null;
        hbNextId = hbLayout.elements.reduce(function(m,e){ return Math.max(m, parseInt((e.id||'0').replace(/\D/g,''))||0); }, 0) + 1;
        var ri = document.getElementById('fabricator-hb-rows');
        if(ri) ri.value = hbLayout.rows || 8;
        hbRender();
        hbRenderProps();
        if(hbModal) hbModal.hidden = false;
    }

    function hbClose(){ if(hbModal) hbModal.hidden = true; hbDrag = null; }

    function hbCancel(){
        if(hbSnapshot) hbLayout = JSON.parse(JSON.stringify(hbSnapshot));
        hbClose();
    }

    function hbApply(){
        var inp = document.getElementById('fabricator-header-layout-input');
        if(inp) inp.value = JSON.stringify(hbLayout);
        hbClose();
        updatePreview();
    }

    function hbRender(){
        if(!hbCanvas) return;
        var rows = Math.max(2, hbLayout.rows||8);
        hbCanvas.style.width  = (HB_COLS * HB_CELL) + 'px';
        hbCanvas.style.height = (rows    * HB_CELL) + 'px';
        hbCanvas.innerHTML = '';
        hbLayout.elements.forEach(function(el){ hbCanvas.appendChild(hbMakeNode(el)); });
    }

    function hbMakeNode(el){
        var node = document.createElement('div');
        node.className = 'fabricator-hb-el' + (hbSel===el.id ? ' fabricator-hb-el--selected' : '');
        node.dataset.id = el.id;
        node.style.cssText = 'left:'+(el.x*HB_CELL)+'px;top:'+(el.y*HB_CELL)+'px;width:'+(el.w*HB_CELL)+'px;height:'+(el.h*HB_CELL)+'px;';

        /* label */
        var lbl = document.createElement('div');
        lbl.className = 'fabricator-hb-el-label';
        lbl.textContent = el.type === 'title' ? hbi18n.elTitle : el.type === 'image' ? hbi18n.elImage : hbi18n.elHtml;
        node.appendChild(lbl);

        /* inner content */
        var inner = document.createElement('div');
        inner.className = 'fabricator-hb-el-inner';
        if(el.type==='image' && el.src){
            inner.innerHTML = '<img src="'+hbEsc(el.src)+'" style="width:100%;height:auto;max-height:100%;display:block;">';
        } else if(el.type==='title'){
            var tcnt = (el.content||el.text||'{form_title}').replace(/\{form_title\}/g,'Beispielformular');
            inner.innerHTML = '<div style="width:100%;height:100%;display:flex;align-items:center;padding:2px 4px;box-sizing:border-box;">'
                +'<span style="width:100%;font-size:'+(el.size||14)+'pt;color:'+hbEsc(el.color||'#1d2327')+';text-align:'+hbEsc(el.align||'left')+';">'
                +tcnt+'</span></div>';
        } else if(el.type==='html'){
            inner.innerHTML = el.html ? hbSanitizePreviewHtml(el.html) : '<span style="color:#aaa;font-size:10px;padding:4px">[HTML]</span>';
        } else {
            inner.innerHTML = '<span style="color:#aaa;font-size:10px;padding:4px">['+el.type+']</span>';
        }
        node.appendChild(inner);

        /* resize handles (only when selected) */
        if(hbSel===el.id){
            ['nw','n','ne','w','e','sw','s','se'].forEach(function(dir){
                var h = document.createElement('div');
                h.className = 'fabricator-hb-handle fabricator-hb-handle--'+dir;
                h.dataset.dir = dir;
                node.appendChild(h);
            });
        }

        node.addEventListener('mousedown', function(e){
            e.preventDefault(); e.stopPropagation();
            hbSel = el.id;
            if(e.target.dataset && e.target.dataset.dir){
                hbDrag = { type:'resize', dir:e.target.dataset.dir, elId:el.id,
                    mx0:e.clientX, my0:e.clientY, ex0:el.x, ey0:el.y, ew0:el.w, eh0:el.h };
            } else {
                hbDrag = { type:'move', elId:el.id,
                    mx0:e.clientX, my0:e.clientY, ex0:el.x, ey0:el.y };
            }
            hbRender(); hbRenderProps();
        });
        return node;
    }

    function hbSyncPosInputs(el){
        if(!el||!hbProps) return;
        hbProps.querySelectorAll('input[data-p]').forEach(function(inp){
            var p=inp.dataset.p;
            if(p==='x'||p==='y'||p==='w'||p==='h') inp.value=el[p];
        });
    }

    document.addEventListener('mousemove', function(e){
        if(!hbDrag) return;
        var dx = Math.round((e.clientX - hbDrag.mx0) / HB_CELL);
        var dy = Math.round((e.clientY - hbDrag.my0) / HB_CELL);
        var el = hbGetEl(hbDrag.elId);
        if(!el) return;

        var maxRows = Math.max(2, hbLayout.rows||8);
        if(hbDrag.type==='move'){
            el.x = Math.max(0, Math.min(HB_COLS - el.w, hbDrag.ex0 + dx));
            el.y = Math.max(0, Math.min(maxRows - el.h, hbDrag.ey0 + dy));
        } else {
            var dir=hbDrag.dir, nx=hbDrag.ex0, ny=hbDrag.ey0, nw=hbDrag.ew0, nh=hbDrag.eh0;
            if(dir.indexOf('e')>=0) nw = Math.max(1, hbDrag.ew0 + dx);
            if(dir.indexOf('s')>=0) nh = Math.max(1, Math.min(maxRows - hbDrag.ey0, hbDrag.eh0 + dy));
            if(dir.indexOf('w')>=0){ nx = hbDrag.ex0+dx; nw = Math.max(1, hbDrag.ew0-dx); }
            if(dir.indexOf('n')>=0){ ny = hbDrag.ey0+dy; nh = Math.max(1, hbDrag.eh0-dy); }
            el.x = Math.max(0, Math.min(HB_COLS-1, nx));
            el.y = Math.max(0, Math.min(maxRows-1, ny));
            el.w = Math.min(HB_COLS-el.x, Math.max(1, nw));
            el.h = Math.min(maxRows-el.y, Math.max(1, nh));
        }
        hbRender();
        hbSyncPosInputs(el);
    });

    document.addEventListener('mouseup', function(){ if(hbDrag){ hbDrag=null; hbRender(); } });

    /* click canvas background → deselect */
    if(hbCanvas){
        hbCanvas.addEventListener('mousedown', function(e){
            if(e.target===hbCanvas){ hbSel=null; hbRender(); hbRenderProps(); }
        });
    }

    /* Delete key (ENTF) — removes selected element */
    document.addEventListener('keydown', function(e){
        if(!hbModal||hbModal.hidden) return;
        if(e.key!=='Delete') return;
        var t=document.activeElement;
        if(t&&(t.tagName==='INPUT'||t.tagName==='TEXTAREA'||t.tagName==='SELECT'||t.isContentEditable)) return;
        if(!hbSel) return;
        hbLayout.elements = hbLayout.elements.filter(function(el){ return el.id!==hbSel; });
        hbSel=null; hbRender(); hbRenderProps();
        e.preventDefault();
    });

    function hbAdd(type){
        var rows = Math.max(2, hbLayout.rows||8);
        var el = { id:'e'+(hbNextId++), type:type, x:0, y:0,
            w: HB_COLS, h: rows };
        if(type==='title'){ el.content='{form_title}'; el.size=14; el.align='right'; el.color='#1d2327'; }
        else if(type==='html'){ el.html=''; }
        hbLayout.elements.push(el);
        hbSel=el.id; hbRender(); hbRenderProps();
    }

    function hbAddImageWithSrc(src, natW, natH){
        var rows = Math.max(2, hbLayout.rows||8);
        var w = 8;
        var h = (natW && natH) ? Math.max(1, Math.min(rows, Math.round(natH / natW * w))) : Math.min(4, rows);
        var el = { id:'e'+(hbNextId++), type:'image', x:0, y:0, w:w, h:h, src:src, fit:'contain' };
        hbLayout.elements.push(el);
        hbSel=el.id; hbRender(); hbRenderProps();
    }

    function hbOpenMediaPicker(onSelect){
        if(!window.wp || !wp.media){
            var u=prompt(I18N.imageUrl);
            if(u && !/^\s*(javascript|vbscript|data):/i.test(u)) onSelect(u,0,0);
            return;
        }
        var fr=wp.media({title:I18N.selectImage,button:{text:I18N.insert},multiple:false});
        fr.on('open',function(){
            var wrap=document.querySelector('.media-modal'), over=document.querySelector('.media-modal-backdrop');
            if(wrap) wrap.style.zIndex='200000';
            if(over) over.style.zIndex='199999';
        });
        fr.on('select',function(){
            var att=fr.state().get('selection').first().toJSON();
            onSelect(att.url, att.width||0, att.height||0);
        });
        fr.open();
    }

    function hbPickImageAndAdd(){
        /* Show inline picker in props panel — user chooses URL or media library */
        if(!hbProps) return;
        hbSel = null;
        hbProps.innerHTML = '<div class="fabricator-hb-img-picker">'
            +'<p class="fabricator-hb-img-picker-title"><i class="fa-solid fa-image"></i> ' + I18N.addImage + '</p>'
            +'<button type="button" class="button button-primary" id="hb-pick-media" style="width:100%">'
            +'<i class="fa-solid fa-photo-film"></i> ' + I18N.chooseFromLibrary + '</button>'
            +'<div class="fabricator-hb-img-picker-sep"><span>' + I18N.orLabel + '</span></div>'
            +'<div class="fabricator-hb-prop-group"><span>' + I18N.externalUrl + '</span>'
            +'<input type="text" id="hb-pick-url" placeholder="https://…" style="margin-bottom:4px">'
            +'<button type="button" class="button" id="hb-pick-url-confirm" style="width:100%">' + I18N.insert + '</button>'
            +'</div>'
            +'<button type="button" class="button" id="hb-pick-cancel" style="width:100%;margin-top:8px">' + I18N.cancel + '</button>'
            +'</div>';

        document.getElementById('hb-pick-media').addEventListener('click', function(){
            hbOpenMediaPicker(function(url,w,h){ hbAddImageWithSrc(url,w,h); });
        });
        document.getElementById('hb-pick-url-confirm').addEventListener('click', function(){
            var u = document.getElementById('hb-pick-url').value.trim();
            if(u) hbAddImageWithSrc(u,0,0);
            else document.getElementById('hb-pick-url').focus();
        });
        document.getElementById('hb-pick-url').addEventListener('keydown', function(e){
            if(e.key==='Enter'){ var u=this.value.trim(); if(u) hbAddImageWithSrc(u,0,0); }
        });
        document.getElementById('hb-pick-cancel').addEventListener('click', function(){
            hbRenderProps();
        });
    }

    function hbRenderProps(){
        if(!hbProps) return;
        var el = hbSel ? hbGetEl(hbSel) : null;
        if(!el){ hbProps.innerHTML='<p class="fabricator-hb-empty">'+hbEsc(hbi18n.selectElement)+'<br>'+hbEsc(hbi18n.toEdit)+'</p>'; return; }

        var h='<div class="fabricator-hb-card"><div class="fabricator-hb-prop-row2">'
            +'<div class="fabricator-hb-prop-group"><span>X</span><input type="number" data-p="x" value="'+el.x+'" min="0" max="41"></div>'
            +'<div class="fabricator-hb-prop-group"><span>Y</span><input type="number" data-p="y" value="'+el.y+'" min="0"></div>'
            +'<div class="fabricator-hb-prop-group"><span>'+hbEsc(hbi18n.width)+'</span><input type="number" data-p="w" value="'+el.w+'" min="1" max="42"></div>'
            +'<div class="fabricator-hb-prop-group"><span>'+hbEsc(hbi18n.height)+'</span><input type="number" data-p="h" value="'+el.h+'" min="1"></div>'
            +'</div></div>';

        if(el.type==='image'){
            /* Image: picker first, then position/size, then fit */
            h=''; /* reset — image skips the shared X/Y/W/H block above */
            h+='<div class="fabricator-hb-card"><div class="fabricator-hb-prop-row2">'
                +'<div class="fabricator-hb-prop-group"><span>X</span><input type="number" data-p="x" value="'+el.x+'" min="0" max="41"></div>'
                +'<div class="fabricator-hb-prop-group"><span>Y</span><input type="number" data-p="y" value="'+el.y+'" min="0"></div>'
                +'<div class="fabricator-hb-prop-group"><span>'+hbEsc(hbi18n.width)+'</span><input type="number" data-p="w" value="'+el.w+'" min="1" max="42"></div>'
                +'<div class="fabricator-hb-prop-group"><span>'+hbEsc(hbi18n.height)+'</span><input type="number" data-p="h" value="'+el.h+'" min="1"></div>'
                +'</div></div>';
            h+='<div class="fabricator-hb-card fabricator-hb-card--image">'
                +'<div class="fabricator-hb-img-preview">'
                +(el.src ? '<img src="'+hbEsc(el.src)+'" style="max-width:100%;max-height:80px;display:block;border-radius:3px;">' : '<span style="color:#aaa;font-size:11px;">'+hbEsc(hbi18n.noImageSelected)+'</span>')
                +'</div>'
                +'<button type="button" class="button" id="hb-media-pick" style="width:100%">'
                +'<i class="fa-solid fa-upload"></i> '+hbEsc(el.src?hbi18n.changeImage:hbi18n.chooseFromLibrary)+'</button>'
                +'<input type="text" data-p="src" value="'+hbEsc(el.src||'')+'" placeholder="'+hbEsc(hbi18n.orEnterUrl)+'">'
                +'</div>';
            h+='<div class="fabricator-hb-card">'
                +'<div class="fabricator-hb-fit-btns">'
                +'<button type="button" class="fabricator-hb-fit-btn'+((!el.fit||el.fit==='contain')?' fabricator-hb-fit-btn--active':'')+'" data-fit="contain">'+hbEsc(hbi18n.fitContain)+'</button>'
                +'<button type="button" class="fabricator-hb-fit-btn'+(el.fit==='cover'?' fabricator-hb-fit-btn--active':'')+'" data-fit="cover">'+hbEsc(hbi18n.fitCover)+'</button>'
                +'<button type="button" class="fabricator-hb-fit-btn'+(el.fit==='fill'?' fabricator-hb-fit-btn--active':'')+'" data-fit="fill">'+hbEsc(hbi18n.fitFill)+'</button>'
                +'</div>'
                +'</div>';
        } else if(el.type==='title'){
            var _al = (!el.align||el.align==='left') ? ' fabricator-hb-tb-btn--active' : '';
            var _ac = el.align==='center' ? ' fabricator-hb-tb-btn--active' : '';
            var _ar = el.align==='right'  ? ' fabricator-hb-tb-btn--active' : '';
            h+='<div class="fabricator-hb-card"><div class="fabricator-hb-fmt-toolbar">'
                /* Rich-text format buttons — execCommand, use data-cmd */
                +'<button type="button" class="fabricator-hb-tb-btn" data-cmd="bold" title="'+hbEsc(hbi18n.bold)+'"><i class="fa-solid fa-bold"></i></button>'
                +'<button type="button" class="fabricator-hb-tb-btn" data-cmd="italic" title="'+hbEsc(hbi18n.italic)+'"><i class="fa-solid fa-italic"></i></button>'
                /* Underline split button */
                +'<div class="fabricator-hb-tb-split">'
                +'<button type="button" class="fabricator-hb-tb-btn" data-cmd="underline" title="'+hbEsc(hbi18n.underline)+'"><i class="fa-solid fa-underline"></i></button>'
                +'<button type="button" class="fabricator-hb-tb-btn fabricator-hb-tb-chevron" data-action="ul-menu" title="'+hbEsc(hbi18n.underlineStyle)+'"><i class="fa-solid fa-chevron-down"></i></button>'
                +'<div class="fabricator-hb-ul-menu" hidden>'
                +'<button type="button" data-ul-style="solid"><span class="fabricator-hb-ul-prev fabricator-hb-ul-solid"></span>'+hbEsc(hbi18n.solid)+'</button>'
                +'<button type="button" data-ul-style="double"><span class="fabricator-hb-ul-prev fabricator-hb-ul-double"></span>'+hbEsc(hbi18n.double)+'</button>'
                +'<button type="button" data-ul-style="dotted"><span class="fabricator-hb-ul-prev fabricator-hb-ul-dotted"></span>'+hbEsc(hbi18n.dotted)+'</button>'
                +'<button type="button" data-ul-style="dashed"><span class="fabricator-hb-ul-prev fabricator-hb-ul-dashed"></span>'+hbEsc(hbi18n.dashed)+'</button>'
                +'</div></div>'
                +'<button type="button" class="fabricator-hb-tb-btn" data-cmd="strikeThrough" title="'+hbEsc(hbi18n.strikethrough)+'"><i class="fa-solid fa-strikethrough"></i></button>'
                +'<div class="fabricator-hb-tb-sep"></div>'
                +'<button type="button" class="fabricator-hb-tb-btn" data-valign="super" title="'+hbEsc(hbi18n.superscript)+'"><i class="fa-solid fa-superscript"></i></button>'
                +'<button type="button" class="fabricator-hb-tb-btn" data-valign="sub" title="'+hbEsc(hbi18n.subscript)+'"><i class="fa-solid fa-subscript"></i></button>'
                +'<div class="fabricator-hb-tb-sep"></div>'
                /* Alignment — element-level, use data-p */
                +'<button type="button" class="fabricator-hb-tb-btn'+_al+'" data-p="align" data-val="left" title="'+hbEsc(hbi18n.left)+'"><i class="fa-solid fa-align-left"></i></button>'
                +'<button type="button" class="fabricator-hb-tb-btn'+_ac+'" data-p="align" data-val="center" title="'+hbEsc(hbi18n.center)+'"><i class="fa-solid fa-align-center"></i></button>'
                +'<button type="button" class="fabricator-hb-tb-btn'+_ar+'" data-p="align" data-val="right" title="'+hbEsc(hbi18n.right)+'"><i class="fa-solid fa-align-right"></i></button>'
                +'<div class="fabricator-hb-tb-sep"></div>'
                /* Size + colour — element-level */
                +(function(){
                    var sizes=[8,9,10,11,12,14,16,18,20,24,28,32,36,48,72];
                    var cur=el.size||14;
                    var s='<select class="fabricator-hb-tb-size-sel" data-sz title="'+hbEsc(hbi18n.fontSize)+'">';
                    sizes.forEach(function(n){
                        s+='<option value="'+n+'"'+(n===cur?' selected':'')+'>'+n+' pt</option>';
                    });
                    return s+'</select>';
                }())
                +'<input type="color" data-p="color" value="'+hbEsc(el.color||'#1d2327')+'" class="fabricator-hb-tb-color" title="'+hbEsc(hbi18n.color)+'">'
                +'</div>';
            h+='<div class="fabricator-hb-prop-group fabricator-hb-prop-group--editor"><span>'+hbEsc(hbi18n.text)+'</span>'
                +'<div class="fabricator-hb-title-editor" contenteditable="true" spellcheck="false" '
                +'style="font-size:'+(el.size||14)+'pt;color:'+hbEsc(el.color||'#1d2327')+';text-align:'+hbEsc(el.align||'left')+';">'
                +(el.content||el.text||'{form_title}')
                +'</div></div>'
                +'</div>';
        } else if(el.type==='html'){
            h+='<div class="fabricator-hb-prop-group"><span>'+hbEsc(hbi18n.htmlCode)+'</span><textarea data-p="html"></textarea></div>';
            h+='<p style="font-size:11px;color:#888;margin:0">'+hbEsc(hbi18n.htmlNote)+'</p>';
        }

        h+='<div style="padding-top:10px;border-top:1px solid #e2e4e7;">'
            +'<button type="button" class="button" id="hb-delete-btn" style="color:#d63638;width:100%"><i class="fa-solid fa-trash"></i> '+hbEsc(hbi18n.deleteElement)+'</button>'
            +'</div>';

        hbProps.innerHTML = h;

        /* Set textarea value safely (avoids HTML injection via innerHTML) */
        if(el.type==='html'){
            var ta = hbProps.querySelector('textarea[data-p="html"]');
            if(ta){
                ta.value = el.html||'';
                /* Not covered by the generic 'input[data-p]' wiring below (that
                   selector only matches <input>, not <textarea>) — without this,
                   edits typed here were silently discarded. */
                ta.addEventListener('input', function(){
                    el.html = ta.value;
                    hbRender();
                });
            }
        }

        /* Rich-text editor (title element) */
        var titleEd   = hbProps.querySelector('.fabricator-hb-title-editor');
        var savedRange = null;

        /* Save selection whenever focus might leave the editor */
        if(titleEd){
            hbProps.addEventListener('mousedown', function(e){
                if(titleEd.contains(e.target)) return;
                var sel=window.getSelection();
                if(sel && sel.rangeCount && titleEd.contains(sel.anchorNode)){
                    savedRange = sel.getRangeAt(0).cloneRange();
                }
            }, true);
        }

        function hbRestoreRange(){
            if(!savedRange || !titleEd) return false;
            titleEd.focus();
            var sel=window.getSelection();
            sel.removeAllRanges();
            sel.addRange(savedRange);
            return !savedRange.collapsed;
        }

        function hbApplySpan(props){
            if(!titleEd) return;
            var hasRange=hbRestoreRange();
            if(!hasRange){ return; }
            var sel=window.getSelection();
            var range=sel.getRangeAt(0);
            var span=document.createElement('span');
            Object.keys(props).forEach(function(k){
                if(k.indexOf('data-')===0) span.setAttribute(k, props[k]);
                else span.style[k]=props[k];
            });
            try{ range.surroundContents(span); }
            catch(ex){
                var frag=range.extractContents();
                span.appendChild(frag);
                range.insertNode(span);
            }
            el.content=titleEd.innerHTML; hbRender();
        }

        /* execCommand buttons — mousedown+preventDefault keeps editor focus intact */
        hbProps.querySelectorAll('button[data-cmd]').forEach(function(btn){
            btn.addEventListener('mousedown', function(e){
                e.preventDefault();
                if(titleEd) titleEd.focus();
                document.execCommand(btn.dataset.cmd, false, null);
                setTimeout(function(){
                    if(titleEd){ el.content=titleEd.innerHTML; hbRender(); hbSyncToolbarState(); }
                }, 0);
            });
        });

        /* Superscript / Subscript — use spans so line-height is unaffected */
        hbProps.querySelectorAll('button[data-valign]').forEach(function(btn){
            btn.addEventListener('mousedown', function(e){
                e.preventDefault();
                var va = btn.dataset.valign;
                hbRestoreRange();
                var sel = window.getSelection();
                if(!sel || !sel.rangeCount || !titleEd) return;

                /* Find all existing [data-va=va] spans that overlap the selection */
                var existing = Array.prototype.slice.call(
                    titleEd.querySelectorAll('span[data-va="'+va+'"]')
                ).filter(function(s){
                    return sel.containsNode(s, true);
                });

                if(existing.length){
                    /* Unwrap every matched span */
                    existing.forEach(function(s){
                        var frag=document.createDocumentFragment();
                        while(s.firstChild) frag.appendChild(s.firstChild);
                        s.parentNode.replaceChild(frag, s);
                    });
                } else if(!sel.getRangeAt(0).collapsed){
                    hbApplySpan({verticalAlign:va, fontSize:'75%', 'data-va':va});
                }
                el.content=titleEd.innerHTML; hbRender(); hbSyncToolbarState();
            });
        });

        /* Underline-style split button */
        var ulChevron = hbProps.querySelector('[data-action="ul-menu"]');
        var ulMenu    = hbProps.querySelector('.fabricator-hb-ul-menu');
        if(ulChevron && ulMenu){
            ulChevron.addEventListener('mousedown', function(e){
                e.preventDefault(); ulMenu.hidden=!ulMenu.hidden;
            });
            ulMenu.querySelectorAll('[data-ul-style]').forEach(function(opt){
                opt.addEventListener('mousedown', function(e){
                    e.preventDefault();
                    ulMenu.hidden=true;
                    if(!titleEd) return;
                    titleEd.focus();
                    var sel=window.getSelection();
                    if(sel && sel.rangeCount && !sel.getRangeAt(0).collapsed){
                        var range=sel.getRangeAt(0);
                        var span=document.createElement('span');
                        span.style.cssText='text-decoration:underline;text-decoration-style:'+opt.dataset.ulStyle;
                        try{ range.surroundContents(span); }
                        catch(ex){ document.execCommand('underline',false,null); }
                    } else {
                        document.execCommand('underline',false,null);
                    }
                    el.content=titleEd.innerHTML; hbRender();
                });
            });
            /* Close menu on outside click */
            document.addEventListener('mousedown', function hbUlClose(e){
                if(!ulMenu.contains(e.target) && e.target!==ulChevron){
                    ulMenu.hidden=true;
                    document.removeEventListener('mousedown', hbUlClose);
                }
            });
        }

        /* Alignment & other element-level toolbar buttons (data-p on <button>) */
        hbProps.querySelectorAll('button[data-p]').forEach(function(btn){
            btn.addEventListener('mousedown', function(e){
                e.preventDefault();
                el[btn.dataset.p]=btn.dataset.val;
                if(titleEd) titleEd.style.textAlign=el.align||'left';
                hbRender(); hbRenderProps();
            });
        });

        /* Font-size select: per-selection if text is selected, else element-level */
        var szSel=hbProps.querySelector('[data-sz]');
        if(szSel){
            szSel.addEventListener('change', function(){
                var sz=parseInt(szSel.value)||14;
                var hadRange=hbRestoreRange();
                if(hadRange){
                    hbApplySpan({fontSize:sz+'pt'});
                } else {
                    el.size=sz;
                    if(titleEd) titleEd.style.fontSize=sz+'pt';
                    hbRender();
                }
            });
        }

        /* Live toolbar state (bold/italic/underline active when cursor is inside) */
        function hbSyncToolbarState(){
            if(!titleEd) return;
            ['bold','italic','underline','strikeThrough'].forEach(function(cmd){
                var btn=hbProps.querySelector('button[data-cmd="'+cmd+'"]');
                if(!btn) return;
                try{ btn.classList.toggle('fabricator-hb-tb-btn--active',
                    document.queryCommandState(cmd)); }
                catch(ex){}
            });
            /* valign: active if cursor is inside OR selection contains a [data-va] span */
            ['super','sub'].forEach(function(va){
                var btn=hbProps.querySelector('button[data-valign="'+va+'"]');
                if(!btn) return;
                var sel=window.getSelection();
                var active=false;
                if(sel&&sel.rangeCount){
                    /* cursor inside a span? */
                    var node=sel.anchorNode;
                    if(node&&node.nodeType===3) node=node.parentNode;
                    if(node&&node.closest) active=!!node.closest('span[data-va="'+va+'"]');
                    /* selection contains a span? */
                    if(!active&&titleEd){
                        active=Array.prototype.some.call(
                            titleEd.querySelectorAll('span[data-va="'+va+'"]'),
                            function(s){ return sel.containsNode(s,true); }
                        );
                    }
                }
                btn.classList.toggle('fabricator-hb-tb-btn--active', active);
            });
        }
        document.addEventListener('selectionchange', function hbSC(){
            if(!titleEd || !document.body.contains(titleEd)){
                document.removeEventListener('selectionchange', hbSC); return;
            }
            var sel=window.getSelection();
            if(sel&&sel.rangeCount&&titleEd.contains(sel.anchorNode)){
                hbSyncToolbarState();
            }
        });

        /* Color input: per-selection when text is selected, element-level otherwise */
        hbProps.querySelectorAll('input[data-p="color"]').forEach(function(inp){
            inp.addEventListener('change', function(){
                var v=inp.value;
                var hadRange=hbRestoreRange();
                if(hadRange){
                    hbApplySpan({color:v});
                } else {
                    el.color=v;
                    if(titleEd) titleEd.style.color=v;
                    hbRender();
                }
            });
        });

        /* Other element-level inputs (grid coords etc.) */
        hbProps.querySelectorAll('input[data-p]:not([data-p="color"])').forEach(function(inp){
            var ev = inp.type==='color' ? 'change' : 'input';
            inp.addEventListener(ev, function(){
                var p=inp.dataset.p, v;
                if(inp.type==='number') v=parseInt(inp.value)||0;
                else v=inp.value;
                el[p]=v;
                if(titleEd){
                    if(p==='size') titleEd.style.fontSize=v+'pt';
                }
                /* clamp grid coords */
                el.x=Math.max(0,Math.min(HB_COLS-el.w,el.x));
                el.y=Math.max(0,el.y);
                el.w=Math.max(1,Math.min(HB_COLS-el.x,el.w));
                el.h=Math.max(1,el.h);
                hbRender();
            });
        });

        /* Image fit buttons */
        hbProps.querySelectorAll('button[data-fit]').forEach(function(btn){
            btn.addEventListener('click', function(){
                el.fit=btn.dataset.fit;
                hbProps.querySelectorAll('button[data-fit]').forEach(function(b){
                    b.classList.toggle('fabricator-hb-fit-btn--active', b===btn);
                });
                hbRender();
            });
        });

        /* Select inputs (remaining selects) */
        hbProps.querySelectorAll('select[data-p]').forEach(function(sel){
            sel.addEventListener('change', function(){
                el[sel.dataset.p]=sel.value; hbRender();
            });
        });

        /* Contenteditable live update */
        if(titleEd){
            titleEd.addEventListener('input', function(){
                el.content=titleEd.innerHTML; hbRender();
            });
        }

        /* Media picker (change image button inside props) */
        var mpBtn=document.getElementById('hb-media-pick');
        if(mpBtn) mpBtn.addEventListener('click', function(){
            hbOpenMediaPicker(function(url){ el.src=url; hbRender(); hbRenderProps(); });
        });

        /* Delete */
        var delBtn=document.getElementById('hb-delete-btn');
        if(delBtn) delBtn.addEventListener('click',function(){
            hbLayout.elements=hbLayout.elements.filter(function(e){ return e.id!==hbSel; });
            hbSel=null; hbRender(); hbRenderProps();
        });
    }

    /* Wire up toolbar / dialog buttons */
    ['fabricator-open-header-builder','fabricator-open-header-builder-card'].forEach(function(id){
        var btn=document.getElementById(id);
        if(btn) btn.addEventListener('click', hbOpen);
    });

    ['fabricator-hb-close','fabricator-hb-cancel'].forEach(function(id){
        var btn=document.getElementById(id);
        if(btn) btn.addEventListener('click', hbCancel);
    });

    var hbOverlay=document.getElementById('fabricator-hb-overlay');
    if(hbOverlay) hbOverlay.addEventListener('click', hbCancel);

    var hbApplyBtn=document.getElementById('fabricator-hb-apply');
    if(hbApplyBtn) hbApplyBtn.addEventListener('click', hbApply);

    var hbAddTitle=document.getElementById('fabricator-hb-add-title');
    if(hbAddTitle) hbAddTitle.addEventListener('click',function(){ hbAdd('title'); });
    var hbAddImage=document.getElementById('fabricator-hb-add-image');
    if(hbAddImage) hbAddImage.addEventListener('click', hbPickImageAndAdd);

    var hbRowsInp=document.getElementById('fabricator-hb-rows');
    if(hbRowsInp) hbRowsInp.addEventListener('input',function(){
        hbLayout.rows=Math.max(2,Math.min(30,parseInt(this.value)||8));
        hbRender();
    });

    /* ── Update collectSettings to include header_layout ── */
    /* Always read from the live hbLayout variable so preview reflects unsaved canvas changes */
    var _origCollect = collectSettings;
    collectSettings = function(){
        var s = _origCollect();
        s.header_layout = JSON.parse(JSON.stringify(hbLayout));
        return s;
    };

    /* ── Update buildPreview to render header from layout ── */
    var _origBuild = buildPreview;
    buildPreview = function(s){
        var hl = s.header_layout;
        if(hl && Array.isArray(hl.elements) && hl.elements.length > 0){
            /* patch: replace the header section with grid-based render */
            var _origS = s;
            /* Temporarily wrap to intercept header slug */
            var patched = false;
            var result = '';
            var order = ['header','fields','signatures','metadata','legal'];
            var hidden = s.section_hidden || [];
            var ff = (function(){
                var fm = {
                    'dejavusans': 'Arial,Helvetica,sans-serif',
                    'dejavuserif': "'Times New Roman',Times,serif",
                    'dejavusansmono': "'DejaVu Sans Mono','Courier New',monospace",
                    'freemono': "'Courier New',Courier,monospace"
                };
                return fm[s.font_family] || 'Arial,sans-serif';
            })();
            var fs = pt(s.font_size_body);
            var pad = mm(s.margin_top)+' '+mm(s.margin_right)+' '+mm(s.margin_bottom)+' '+mm(s.margin_left);
            result += '<div style="font-family:'+ff+';font-size:'+fs+';color:#222;padding:'+pad+';box-sizing:border-box;">';
            order.forEach(function(slug){
                if(hidden.indexOf(slug)!==-1) return;
                if(slug==='header'){
                    /* Render header at builder canvas scale then CSS-scale to the paper's content area, keeping image aspect ratios matched to the builder canvas. */
                    var canvasW   = HB_COLS * HB_CELL;
                    var marginPx  = parseFloat(mm(s.margin_left)) + parseFloat(mm(s.margin_right));
                    /* Paper is always at its 992px design width (JS forces this on mobile via
                       p.style.width). Using paper.offsetWidth was fragile — it varied with
                       stageInner width and broke header scale on mobile. */
                    var contentW  = Math.max(1, 992 - marginPx);
                    var scale     = contentW / canvasW; /* fills paper content area; height scales proportionally */
                    var hpx = 0;
                    hl.elements.forEach(function(el){ var b=(el.y+el.h)*HB_CELL; if(b>hpx) hpx=b; });
                    hpx = Math.max(HB_CELL, hpx);
                    var scaledH = Math.ceil(hpx * scale);
                    result += '<div style="overflow:hidden;margin-bottom:4px;height:'+scaledH+'px;">';
                    result += '<div style="position:relative;width:'+canvasW+'px;height:'+hpx+'px;transform-origin:left top;transform:scale('+scale.toFixed(6)+')">';
                    hl.elements.forEach(function(el){
                        var lp  = (el.x * HB_CELL)+'px';
                        var tp  = (el.y * HB_CELL)+'px';
                        var wp2 = (el.w * HB_CELL)+'px';
                        var hp2 = (el.h * HB_CELL)+'px';
                        result += '<div style="position:absolute;left:'+lp+';top:'+tp+';width:'+wp2+';height:'+hp2+';overflow:hidden;">';
                        if(el.type==='image' && el.src){
                            result += '<img src="'+hbEsc(el.src)
                                +'" style="width:100%;height:auto;max-height:100%;display:block;">';
                        } else if(el.type==='title'){
                            var pcnt = (el.content||el.text||'{form_title}').replace(/\{form_title\}/g,'Beispielformular');
                            result += '<div style="width:100%;height:100%;display:flex;align-items:center;">'
                                +'<span style="width:100%;font-size:'+(el.size||14)+'pt;color:'+hbEsc(el.color||'#1d2327')+';text-align:'+hbEsc(el.align||'left')+';">'+pcnt+'</span></div>';
                        }
                        result += '</div>';
                    });
                    result += '</div></div>';
                    patched = true;
                    return;
                }
                /* all other sections: delegate to original build */
                var allSlugs = ['header','fields','signatures','metadata','legal'];
                var hideAll = allSlugs.filter(function(x){ return x !== slug; });
                var fakeS = Object.assign({}, s, { section_hidden: hideAll });
                /* extract just this section's HTML by calling original with all-but-one hidden */
                var chunk = _origBuild(fakeS);
                /* strip the outer wrapper div that _origBuild adds */
                chunk = chunk.replace(/^<div[^>]*>/, '').replace(/<\/div>\s*$/, '');
                result += chunk;
            });
            result += '</div>';
            return result;
        }
        return _origBuild(s);
    };

    window.fabricatorPdfUpdatePreview = updatePreview;
    updatePreview();
}());

/* ---- AJAX save for #fabricator-pdf-layout-form (no page reload) ---- */
(function(){
    var form = document.getElementById('fabricator-pdf-layout-form');
    if (!form) return;
    function showNotice(msg, isError) {
        var existing = document.querySelector('.fabricator-settings-notice');
        if (existing) existing.remove();
        var n = document.createElement('div');
        n.className = 'fabricator-settings-notice fabricator-settings-notice--'
            + (isError ? 'error' : 'success');
        n.innerHTML = '<i class="fa-solid fa-'
            + (isError ? 'circle-xmark' : 'circle-check') + '"></i> ' + msg;
        var topbar = document.querySelector('.fabricator-settings-topbar');
        var ref = topbar || form;
        ref.parentNode.insertBefore(n, ref);
        n.scrollIntoView({behavior:'smooth', block:'nearest'});
        if (!isError) {
            setTimeout(function() {
                n.style.transition = 'opacity .4s';
                n.style.opacity = '0';
                setTimeout(function() { n.remove(); }, 420);
            }, 3000);
        }
    }
    form.addEventListener('submit', function(e){
        e.preventDefault();
        var btn = document.querySelector('[form="fabricator-pdf-layout-form"][type="submit"]')
            || form.querySelector('button[type="submit"]');
        var origHtml = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="fabricator-spinner"></span> ' + I18N.saving; }
        var fd = new FormData(form);
        fd.set('action', 'fabricator_save_pdf_layout');
        requestAnimationFrame(function(){ requestAnimationFrame(function(){
        fetch(DATA.ajaxUrl, {method:'POST', body:fd})
            .then(function(r){
                return r.text().then(function(text){
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        /* Server returned something other than JSON (PHP warning/fatal,
                           wp_die() on nonce failure, etc.) — surface it instead of
                           reporting it as a generic network error. */
                        console.error('PDF layout save: non-JSON response (HTTP ' + r.status + ')', text);
                        throw new Error(I18N.unexpectedResponse.replace('%d', r.status));
                    }
                    return data;
                });
            })
            .then(function(data){
                if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                if (data.success) {
                    showNotice(data.data.message, false);
                    if (data.data.snapshot !== undefined) {
                        var snapInput = form.querySelector('[name="fabricator_pdf_layout_snapshot"]');
                        if (snapInput) { snapInput.value = data.data.snapshot; }
                    }
                } else {
                    showNotice((data.data && data.data.message) || I18N.errorSaving, true);
                }
            })
            .catch(function(err){
                if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                if (err && err.message) {
                    showNotice(err.message, true);
                } else {
                    console.error('PDF layout save: request failed', err);
                    showNotice(I18N.networkError, true);
                }
            });
        }); }); // requestAnimationFrame double-frame
    });
}());

}());
