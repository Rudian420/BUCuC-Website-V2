// Video gallery admin manager: YouTube lookup preview, reordering, categories
(function () {
    const configEl = document.getElementById('gallery-config-data');
    let cfg = {};
    if (configEl) {
        try { cfg = JSON.parse(configEl.textContent); } catch (e) {}
    }
    const CATS = cfg.cats || {};
    const TENURES = cfg.tenures || {};
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrf = csrfMeta ? csrfMeta.content : '';
    const form = document.getElementById('videoForm');
    const $ = id => document.getElementById(id);
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    let video = cfg.video || null;
    let autoTitle = video ? video.title : '';

    function preview() {
        const box = $('preview');
        if (!box) return;
        if (!video) { box.innerHTML = '<p class="note">Paste a link to see the preview.</p>'; return; }
        const short = $('is_short') ? $('is_short').checked : false;
        const feat = $('featured') ? $('featured').checked : false;
        const d = $('video_date') ? $('video_date').value : '';
        const t = $('tenure_id') ? $('tenure_id').value : '';
        const meta = [d ? new Date(d + 'T00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : '', t ? 'Tenure ' + (TENURES[t] || '') : ''].filter(Boolean).join(' · ');
        const desc = $('description') ? $('description').value.trim() : '';
        const catVal = $('category') ? $('category').value : '';
        const titleVal = $('title') ? $('title').value.trim() : '';
        box.innerHTML = `<article class="gv-card${short ? ' short' : ''}${feat ? ' featured' : ''}">
            <div class="gv-frame"><div class="gv-badges">${feat ? '<span class="gv-badge feat">★ Featured</span>' : ''}${short ? '<span class="gv-badge">Short</span>' : ''}</div>
            <span class="gv-play"><img src="${esc(video.thumbnail)}" alt=""><span class="gv-icon"></span></span></div>
            <div class="gv-body"><span class="gv-cat">${esc(CATS[catVal] || '')}</span><h3 class="gv-title">${esc(titleVal || video.title)}</h3>
            ${meta ? `<span class="gv-meta">${esc(meta)}</span>` : ''}${desc ? `<p class="gv-desc">${esc(desc)}</p>` : ''}</div></article>`;
    }
    ['title', 'category', 'tenure_id', 'video_date', 'description', 'featured', 'is_short'].forEach(id => {
        const el = $(id);
        if (el) el.addEventListener('input', preview);
    });

    const msg = (tone, text) => {
        const lm = $('linkMsg');
        if (lm) {
            lm.className = 'check-msg' + (tone ? ' ' + tone : '');
            lm.textContent = text;
        }
    };
    let lastLink = '';
    async function check() {
        const linkEl = $('link');
        if (!linkEl) return;
        const link = linkEl.value.trim();
        if (!link || link === lastLink) return;
        lastLink = link;
        msg('', 'Checking with YouTube…');
        const fd = new FormData();
        fd.append('action', 'lookup');
        fd.append('link', link);
        if (form && form.elements.id) fd.append('id', form.elements.id.value);
        fd.append('csrf_token', csrf);
        try {
            const r = await fetch('video_gallery.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (!d.success) { video = null; preview(); return msg('bad', d.error); }
            const titleEl = $('title');
            if (titleEl && (!titleEl.value.trim() || titleEl.value === autoTitle)) titleEl.value = d.title;
            autoTitle = d.title;
            const shortEl = $('is_short');
            if (shortEl) shortEl.checked = d.short;
            video = d;
            if (d.duplicates && d.duplicates.length) msg('warn', `⚠ Already in the gallery: ${d.duplicates.map(x => '"' + x.title + '"' + (x.hidden ? ' (hidden)' : '')).join(', ')}. You can still add it; you'll be asked to confirm.`);
            else msg('good', '✓ Found on YouTube and allowed on other websites' + (d.start ? `. Starts at ${Math.floor(d.start / 60)}:${String(d.start % 60).padStart(2, '0')}` : '') + (d.short ? '. Vertical video (Short)' : '') + '.');
            preview();
        } catch (e) {
            lastLink = '';
            msg('bad', 'Could not check the link. Reload the page and try again.');
        }
    }
    const checkBtn = $('checkBtn');
    if (checkBtn) checkBtn.addEventListener('click', () => { lastLink = ''; check(); });
    const linkEl = $('link');
    if (linkEl) {
        linkEl.addEventListener('change', check);
        linkEl.addEventListener('paste', () => setTimeout(check, 50));
    }
    if (video) preview();
    else if (linkEl && linkEl.value.trim()) check();

    // Drag to reorder
    const list = $('vlist');
    if (!list) return;
    let dragged = null;
    list.addEventListener('dragstart', e => {
        dragged = e.target.closest('.vrow');
        if (!dragged) return;
        dragged.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragged.dataset.id);
    });
    list.addEventListener('dragover', e => {
        const row = e.target.closest('.vrow');
        if (!dragged || !row || row === dragged) return;
        e.preventDefault();
        const r = row.getBoundingClientRect();
        list.insertBefore(dragged, e.clientY > r.top + r.height / 2 ? row.nextSibling : row);
    });
    list.addEventListener('dragend', async () => {
        if (!dragged) return;
        dragged.classList.remove('dragging');
        dragged = null;
        const rows = [...list.querySelectorAll('.vrow')];
        rows.forEach((row, i) => {
            const p = row.querySelector('.pos');
            if (p) p.value = i + 1;
        });
        const fd = new FormData();
        fd.append('action', 'reorder');
        fd.append('ids', JSON.stringify(rows.map(r => +r.dataset.id)));
        fd.append('csrf_token', csrf);
        const os = $('orderStatus');
        if (os) os.textContent = 'Saving the order…';
        try {
            const d = await (await fetch('video_gallery.php', { method: 'POST', body: fd })).json();
            if (os) os.textContent = d.success ? '✓ Order saved' : (d.error || 'Could not save the order.');
        } catch (e) {
            if (os) os.textContent = 'Could not save the order. Reload the page.';
        }
    });
})();
