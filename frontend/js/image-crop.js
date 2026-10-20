/**
 * Built-in crop and resize for image uploads (Website Media and any future image upload).
 *
 * Any <input type="file" data-rule="KEY"> gets it on a page that loads vendor/cropperjs/cropper.min.js,
 * css/image-crop.css, this file, and window.MEDIA_RULES (printed from media_rules_js() in
 * backend/includes/site.php, the same rules the server checks: one place to change them).
 *
 * - Wrong ratio      → a crop window opens with the frame locked to the area's ratio (drag, zoom, pinch).
 * - Too big          → scaled down to fit the maximum after cropping. Never enlarged.
 * - Right already    → uploaded as it is; a "Crop" button allows adjusting anyway.
 * - Very small       → a "may look blurry" warning, still allowed.
 * - Always           → a preview of the final image with its final size before uploading.
 * Saved as JPEG (88 %) for JPEG photos, WebP (88 %) for PNG/WebP (PNG if the browser can't make WebP).
 * The server still checks type, size and ratio of whatever arrives: this is only a convenience.
 */
(function () {
    const CFG = window.MEDIA_RULES;
    if (!CFG || !window.Cropper) return;
    const QUALITY = 0.88;
    const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const EXT = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' };
    const NAME = { 'image/jpeg': 'JPEG', 'image/png': 'PNG', 'image/webp': 'WebP' };
    const kb = n => n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
    const states = new Map();   // input → state

    /* ------------------------------------------------------------------ crop window (one per page) */
    const modal = document.createElement('div');
    modal.className = 'crop-modal';
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'cropTitle');
    modal.innerHTML = `
        <div class="crop-box">
            <div class="crop-head">
                <h5 id="cropTitle">Crop the photo</h5>
                <span class="crop-rule"></span>
            </div>
            <p class="crop-why"></p>
            <div class="crop-stage"><img alt="Photo being cropped"></div>
            <div class="crop-foot">
                <span class="crop-size" aria-live="polite"></span>
                <div class="crop-btns">
                    <button type="button" class="btn btn-sm btn-outline-light" data-act="out" aria-label="Zoom out"><i class="fas fa-search-minus"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-light" data-act="in" aria-label="Zoom in"><i class="fas fa-search-plus"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-light" data-act="reset"><i class="fas fa-undo me-1"></i>Reset</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-act="cancel">Cancel</button>
                    <button type="button" class="btn btn-sm btn-success" data-act="use"><i class="fas fa-crop-alt me-1"></i>Use this crop</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(modal);
    const mImg = modal.querySelector('.crop-stage img');
    let cropper = null, current = null, lastFocus = null;

    function outputSize(rule, w, h) {
        const s = Math.min(1, rule.max[0] / w, rule.max[1] / h);
        // width from the scale, height from the exact ratio (the server allows ±2 %), both within the maximum
        let ow = Math.max(1, Math.min(rule.max[0], Math.round(w * s)));
        let oh = Math.max(1, Math.round(ow * rule.ratio[1] / rule.ratio[0]));
        if (oh > rule.max[1]) { oh = rule.max[1]; ow = Math.max(1, Math.round(oh * rule.ratio[0] / rule.ratio[1])); }
        return [ow, oh, s < 1];
    }

    function openCrop(st, why) {
        current = st;
        lastFocus = document.activeElement;
        modal.querySelector('.crop-rule').textContent = st.rule.label;
        modal.querySelector('.crop-why').textContent = why;
        modal.hidden = false;
        document.body.classList.add('crop-open');
        mImg.src = st.srcUrl;
        cropper = new Cropper(mImg, {
            aspectRatio: st.rule.ratio[0] / st.rule.ratio[1],
            viewMode: 1, dragMode: 'move', autoCropArea: 1, background: false, restore: false,
            toggleDragModeOnDblclick: false, zoomOnTouch: true, zoomOnWheel: true,
            ready() {
                if (st.cropData) cropper.setData(st.cropData);
                modal.querySelector('[data-act="use"]').focus();
            },
            crop(e) {
                const [ow, oh, scaled] = outputSize(st.rule, Math.round(e.detail.width), Math.round(e.detail.height));
                modal.querySelector('.crop-size').textContent = `Result: ${ow} × ${oh} px${scaled ? ' (scaled down to fit)' : ''}`;
            },
        });
    }

    function closeCrop() {
        if (cropper) cropper.destroy();
        cropper = null;
        modal.hidden = true;
        document.body.classList.remove('crop-open');
        if (lastFocus) lastFocus.focus();
    }

    modal.addEventListener('click', e => {
        const act = e.target.closest('[data-act]')?.dataset.act;
        if (!act || !cropper) return;
        if (act === 'in') cropper.zoom(0.1);
        else if (act === 'out') cropper.zoom(-0.1);
        else if (act === 'reset') cropper.reset();
        else if (act === 'cancel') cancelCrop();
        else if (act === 'use') useCrop();
    });
    document.addEventListener('keydown', e => {
        if (modal.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); cancelCrop(); }
    });

    function cancelCrop() {
        const st = current;
        closeCrop();
        if (!st) return;
        if (!st.ratioOk && !st.result) {
            // a wrong-ratio photo can't be uploaded uncropped
            clearInput(st);
            say(st, 'bad', `Not added: this photo must be cropped to ${st.rule.ratio[0]}:${st.rule.ratio[1]}. Choose it again to crop it.`);
        }
    }

    async function useCrop() {
        const st = current;
        const d = cropper.getData(true);   // the chosen area in the photo's own pixels
        st.cropData = d;
        const source = cropper.getCroppedCanvas({ imageSmoothingEnabled: true, imageSmoothingQuality: 'high', fillColor: st.type === 'image/jpeg' ? '#fff' : 'transparent' });
        closeCrop();
        await finish(st, source, d.width, d.height, true);
    }

    /* ------------------------------------------------------------------ making the final file */

    // Halve step by step before the last resize: much sharper than one big jump
    function resize(canvas, ow, oh) {
        let c = canvas;
        while (c.width / 2 >= ow && c.height / 2 >= oh) {
            const h = document.createElement('canvas');
            h.width = Math.round(c.width / 2); h.height = Math.round(c.height / 2);
            const x = h.getContext('2d'); x.imageSmoothingQuality = 'high'; x.drawImage(c, 0, 0, h.width, h.height);
            c = h;
        }
        if (c.width === ow && c.height === oh) return c;
        const out = document.createElement('canvas');
        out.width = ow; out.height = oh;
        const x = out.getContext('2d'); x.imageSmoothingQuality = 'high'; x.drawImage(c, 0, 0, ow, oh);
        return out;
    }

    const toBlob = (c, type, q) => new Promise(res => c.toBlob(res, type, q));
    async function encode(canvas, srcType) {
        const want = srcType === 'image/jpeg' ? 'image/jpeg' : 'image/webp';
        let blob = await toBlob(canvas, want, QUALITY);
        if (!blob || blob.type !== want) blob = await toBlob(canvas, 'image/png');
        return blob;
    }

    async function finish(st, sourceCanvas, srcW, srcH, cropped) {
        st.busy = true;
        say(st, '', 'Preparing the photo…');
        try {
            const [ow, oh, scaled] = outputSize(st.rule, srcW, srcH);
            const blob = await encode(resize(sourceCanvas, ow, oh), st.type);
            if (!blob) throw new Error('encode');
            const base = st.file.name.replace(/\.[^.]+$/, '') || 'photo';
            st.result = new File([blob], `${base}.${EXT[blob.type] || 'jpg'}`, { type: blob.type, lastModified: Date.now() });
            const notes = [];
            if (cropped) notes.push('cropped');
            if (scaled) notes.push(`scaled down from ${srcW} × ${srcH}`);
            show(st, st.result, ow, oh, notes);
        } catch (e) {
            clearInput(st);
            say(st, 'bad', 'This browser could not prepare the photo. Try another browser, or crop it on your computer first.');
        } finally {
            st.busy = false;
        }
    }

    /* ------------------------------------------------------------------ messages and preview */

    function say(st, tone, text) {
        st.msg.textContent = text;
        st.msg.className = 'check-msg' + (tone ? ' ' + tone : '');
    }

    function show(st, file, w, h, notes) {
        // the input now holds exactly what will be uploaded
        if (file !== st.file) setFile(st, file);
        if (st.previewUrl) URL.revokeObjectURL(st.previewUrl);
        st.previewUrl = URL.createObjectURL(file);
        const small = w < st.rule.warn[0] || h < st.rule.warn[1];
        st.box.hidden = false;
        st.box.querySelector('img').src = st.previewUrl;
        st.box.querySelector('img').style.aspectRatio = `${st.rule.ratio[0]} / ${st.rule.ratio[1]}`;
        st.box.querySelector('.cp-dim').textContent = `${w} × ${h} px`;
        st.box.querySelector('.cp-meta').textContent = `${NAME[file.type] || file.type} · ${kb(file.size)}` + (notes.length ? ` · ${notes.join(', ')}` : ' · uploaded as it is');
        const warn = st.box.querySelector('.cp-warn');
        warn.hidden = !small;
        warn.textContent = small ? `Small for its place on the site (best at least ${st.rule.warn[0]} × ${st.rule.warn[1]} px): it may look blurry. You can still upload it.` : '';
        say(st, 'good', 'Ready to upload. This is how it will look:');
    }

    function setFile(st, file) {
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            st.input.files = dt.files;
            st.fallback = null;
        } catch (e) {
            st.fallback = file;   // very old browser: sent by the form's submit handler instead
        }
    }

    function clearInput(st) {
        st.input.value = '';
        st.result = null; st.fallback = null; st.cropData = null;
        st.box.hidden = true;
        if (st.srcUrl) { URL.revokeObjectURL(st.srcUrl); st.srcUrl = null; }
    }

    /* ------------------------------------------------------------------ wiring each input */

    document.querySelectorAll('input[type=file][data-rule]').forEach(input => {
        const rule = CFG.rules[input.dataset.rule];
        if (!rule) return;
        let msg = input.parentNode.querySelector('.check-msg');
        if (!msg) { msg = document.createElement('div'); msg.className = 'check-msg'; input.after(msg); }
        const box = document.createElement('div');
        box.className = 'crop-preview';
        box.hidden = true;
        box.innerHTML = `<img alt="Preview of the photo that will be uploaded"><div class="cp-info"><b class="cp-dim"></b><span class="cp-meta"></span><span class="cp-warn" hidden></span>
            <button type="button" class="btn btn-sm btn-outline-light cp-crop"><i class="fas fa-crop-alt me-1"></i>Crop</button></div>`;
        msg.after(box);
        const st = { input, rule, msg, box, file: null, srcUrl: null, result: null, cropData: null, busy: false };
        states.set(input, st);

        box.querySelector('.cp-crop').addEventListener('click', () => openCrop(st, 'Drag the photo and zoom (or pinch) to choose the part to keep. The frame keeps the right shape.'));

        input.addEventListener('change', async () => {
            const file = input.files[0];
            if (st.srcUrl) URL.revokeObjectURL(st.srcUrl);
            Object.assign(st, { file, srcUrl: null, result: null, cropData: null, fallback: null });
            box.hidden = true;
            say(st, '', '');
            if (!file) return;
            if (!TYPES.includes(file.type)) { clearInput(st); return say(st, 'bad', 'Choose a JPG, PNG or WebP image.'); }
            st.type = file.type;
            st.srcUrl = URL.createObjectURL(file);
            const img = new Image();
            img.src = st.srcUrl;
            try { await img.decode(); } catch (e) { clearInput(st); return say(st, 'bad', 'This browser could not read the image.'); }
            const w = img.naturalWidth, h = img.naturalHeight, want = rule.ratio[0] / rule.ratio[1];
            st.ratioOk = Math.abs(w / h - want) / want <= CFG.tolerance;
            if (!st.ratioOk) {
                return openCrop(st, `This photo is ${w} × ${h} px, but this place needs ${rule.ratio[0]}:${rule.ratio[1]}. Drag and zoom (or pinch) to choose the part to keep.`);
            }
            if (w > rule.max[0] || h > rule.max[1] || file.size > CFG.maxBytes) {
                // right shape but too big: scale the whole photo down
                const c = document.createElement('canvas');
                c.width = w; c.height = h;
                const x = c.getContext('2d');
                if (st.type === 'image/jpeg') { x.fillStyle = '#fff'; x.fillRect(0, 0, w, h); }
                x.drawImage(img, 0, 0);
                return finish(st, c, w, h, false);
            }
            show(st, file, w, h, []);
        });

        // Don't send while a photo is being prepared; old-browser fallback sends the prepared file itself
        input.form?.addEventListener('submit', async e => {
            if (st.busy) { e.preventDefault(); say(st, '', 'Still preparing the photo, press the button again in a moment.'); return; }
            if (!st.fallback) return;
            e.preventDefault();
            const fd = new FormData(input.form);
            fd.set(input.name, st.fallback, st.fallback.name);
            const r = await fetch(input.form.action, { method: 'POST', body: fd });
            const html = await r.text();
            history.replaceState(null, '', r.url);
            document.open(); document.write(html); document.close();
        });
    });
})();
