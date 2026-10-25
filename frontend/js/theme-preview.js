// Theme Preview and Palette Generator
(function () {
    const configEl = document.getElementById('theme-config-data');
    if (!configEl) return;
    let cfg = {};
    try { cfg = JSON.parse(configEl.textContent); } catch (e) { return; }

    const ROLES = cfg.roles || {};
    const VARS = cfg.vars || {};
    const PAIRS = cfg.pairs || [];
    const ROTATE = cfg.rotate || [];
    const HISTORY = cfg.history || [];
    let roles = cfg.startRoles || {};
    window.roles = roles;
    let main = cfg.main || '#00b4d8';
    let manual = Boolean(cfg.manual);

    // ---- colour maths (same formulas as backend/includes/palette.php)
    const hexRgb = h => { h = h.replace('#', ''); if (h.length === 3) h = h.split('').map(c => c + c).join(''); return [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16)); };
    const rgbHex = rgb => '#' + rgb.map(c => Math.max(0, Math.min(255, Math.round(c))).toString(16).padStart(2, '0')).join('');
    function rgbHsl([r, g, b]) {
        r /= 255; g /= 255; b /= 255;
        const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
        if (max === min) return [0, 0, l];
        const d = max - min, s = l <= 0.5 ? d / (max + min) : d / (2 - max - min);
        let h = max === r ? (g - b) / d : max === g ? 2 + (b - r) / d : 4 + (r - g) / d;
        return [((h * 60) % 360 + 360) % 360, s, l];
    }
    function hslRgb(h, s, l) {
        h = (((h % 360) + 360) % 360) / 360; s = Math.max(0, Math.min(1, s)); l = Math.max(0, Math.min(1, l));
        if (s === 0) return [l * 255, l * 255, l * 255];
        const q = l < 0.5 ? l * (1 + s) : l + s - l * s, p = 2 * l - q;
        const f = t => { t = ((t % 1) + 1) % 1; return t < 1 / 6 ? p + (q - p) * 6 * t : t < 1 / 2 ? q : t < 2 / 3 ? p + (q - p) * (2 / 3 - t) * 6 : p; };
        return [f(h + 1 / 3) * 255, f(h) * 255, f(h - 1 / 3) * 255];
    }
    function contrast(a, b) {
        const lum = hex => { const c = hexRgb(hex).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
        const x = lum(a), y = lum(b);
        return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
    }
    const lumRgb = rgb => { const c = rgb.map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
    function matchLum(h, s, target) {
        let lo = 0, hi = 1;
        for (let i = 0; i < 30; i++) { const mid = (lo + hi) / 2; if (lumRgb(hslRgb(h, s, mid)) < target) lo = mid; else hi = mid; }
        return hslRgb(h, s, (lo + hi) / 2);
    }
    const chroma = rgb => (Math.max(...rgb) - Math.min(...rgb)) / 255;
    function rehue(rgb, newHue) {
        const s0 = rgbHsl(rgb)[1], y = lumRgb(rgb), c0 = chroma(rgb);
        let out = matchLum(newHue, s0, y);
        if (chroma(out) > c0 * 1.1) {
            let lo = 0, hi = s0;
            for (let i = 0; i < 25; i++) { const mid = (lo + hi) / 2; if (chroma(matchLum(newHue, mid, y)) > c0 * 1.1) hi = mid; else lo = mid; }
            out = matchLum(newHue, lo, y);
        }
        return out;
    }
    let mainAdjusted = false;
    function generate(mainHex) {
        const m = hexRgb(mainHex);
        const dh = rgbHsl(m)[0] - rgbHsl(hexRgb(ROLES.primary.default))[0];
        const out = {};
        for (const [k, r] of Object.entries(ROLES)) {
            let rgb = hexRgb(r.default);
            if (ROTATE.includes(k) && k !== 'primary') rgb = rehue(rgb, rgbHsl(rgb)[0] + dh);
            out[k] = rgbHex(rgb);
        }
        const [h, s, l] = rgbHsl(m);
        const bg = out.bg, darkBg = lumRgb(hexRgb(bg)) < 0.18;
        let pick = m;
        mainAdjusted = false;
        if (contrast(rgbHex(pick), bg) < 4.5) {
            let lo = l, hi = darkBg ? 1 : 0;
            for (let i = 0; i < 30; i++) { const mid = (lo + hi) / 2; if (contrast(rgbHex(hslRgb(h, s, mid)), bg) >= 4.5) hi = mid; else lo = mid; }
            pick = hslRgb(h, s, hi);
            mainAdjusted = true;
        }
        out.primary = rgbHex(pick);
        return out;
    }
    function cssVars(rs) {
        const out = {};
        for (const [name, [role, rgb, d]] of Object.entries(VARS)) {
            if (rs[role].toLowerCase() === ROLES[role].default.toLowerCase()) { out[name] = rgb.join(' '); continue; }
            if (name === '--c-' + role) { out[name] = hexRgb(rs[role]).join(' '); continue; }
            const [h, s, l] = rgbHsl(hexRgb(rs[role]));
            out[name] = hslRgb(h + d[0], s + d[1], l + d[2]).map(Math.round).join(' ');
        }
        return out;
    }

    const rolesBox = document.getElementById('roles');
    if (rolesBox) {
        for (const [k, r] of Object.entries(ROLES)) {
            const row = document.createElement('div');
            row.className = 'role';
            row.innerHTML = `<input type="color" data-k="${k}" aria-label="${r.label}"><label>${r.label}</label><input class="hex" data-k="${k}" maxlength="7" aria-label="${r.label} hex">`;
            rolesBox.appendChild(row);
        }
    }
    function paint() {
        document.querySelectorAll('#roles input').forEach(i => { if (document.activeElement !== i) i.value = roles[i.dataset.k]; });
        const mw = document.getElementById('manualWarn');
        if (mw) mw.classList.toggle('show', manual);
        const an = document.getElementById('adjustNote');
        if (an) an.style.display = mainAdjusted && !manual ? 'block' : 'none';
        let bad = 0, low = 0;
        const pEl = document.getElementById('pairs');
        if (pEl) {
            pEl.innerHTML = PAIRS.map(([label, fg, bg]) => {
                const r = contrast(roles[fg], roles[bg]);
                const lvl = r >= 4.5 ? 'ok' : r >= 3 ? 'low' : 'bad';
                if (lvl === 'bad') bad++; if (lvl === 'low') low++;
                return `<tr><td><span class="sample" style="color:${roles[fg]};background:${roles[bg]}">Aa</span></td><td>${label}</td><td class="lvl ${lvl}">${r.toFixed(1)} · ${lvl === 'ok' ? 'Easy to read' : lvl === 'low' ? 'May be hard to read' : 'Hard to read'}</td></tr>`;
            }).join('');
        }
        const cw = document.getElementById('contrastWarn');
        if (cw) {
            cw.classList.toggle('show', bad + low > 0);
            cw.innerHTML = bad ? `<i class="fas fa-exclamation-circle me-2"></i><b>${bad} text colour${bad > 1 ? 's are' : ' is'} hard to read</b> on ${bad > 1 ? 'their' : 'its'} background${low ? `, and ${low} more may be hard to read` : ''}. Visitors may not be able to read parts of the site.`
                : `<i class="fas fa-exclamation-triangle me-2"></i>${low} text colour${low > 1 ? 's' : ''} may be hard to read. Check the list below.`;
        }
        window.roles = roles;
        preview();
    }
    function applyTo(frame) {
        try {
            if (!frame) return;
            const doc = frame.contentDocument;
            if (!doc || !doc.documentElement) return;
            const v = cssVars(roles);
            for (const [k, val] of Object.entries(v)) doc.documentElement.style.setProperty(k, val);
            frame.contentWindow.dispatchEvent(new Event('resize'));
        } catch (e) { }
    }
    function preview() { ['pvPublic', 'pvAdmin'].forEach(id => applyTo(document.getElementById(id))); }
    ['pvPublic', 'pvAdmin'].forEach(id => {
        const frame = document.getElementById(id);
        if (frame) frame.addEventListener('load', e => applyTo(e.target));
    });

    const SIZES = { desktop: [1366, 900], mobile: [390, 844] };
    function fitRow(row) {
        const stage = row.querySelector('.pv-stage'), frame = row.querySelector('iframe');
        if (!stage || !frame) return;
        const mode = row.dataset.mode || 'desktop';
        const [w] = SIZES[mode];
        const pad = mode === 'mobile' ? 16 : 0;
        const scale = Math.min(1, (stage.clientWidth - pad * 2) / w);
        frame.style.width = w + 'px';
        frame.style.height = Math.round((stage.clientHeight - pad * 2) / scale) + 'px';
        frame.style.transform = `scale(${scale})`;
        frame.style.left = (mode === 'mobile' ? Math.max(pad, (stage.clientWidth - w * scale) / 2) : 0) + 'px';
        frame.style.top = pad + 'px';
        stage.classList.toggle('mobile', mode === 'mobile');
        const sizeEl = row.querySelector('.pv-size');
        if (sizeEl) sizeEl.textContent = `${w} px wide, shown at ${Math.round(scale * 100)}%`;
    }
    document.querySelectorAll('.pv-row').forEach(row => {
        row.dataset.mode = 'desktop';
        row.querySelectorAll('.seg button').forEach(b => b.addEventListener('click', () => {
            row.dataset.mode = b.dataset.mode;
            row.querySelectorAll('.seg button').forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
            fitRow(row);
        }));
        fitRow(row);
    });
    window.addEventListener('resize', () => document.querySelectorAll('.pv-row').forEach(fitRow));

    const mc = document.getElementById('mainColor');
    if (mc) {
        mc.addEventListener('input', e => { main = e.target.value; roles = generate(main); manual = false; paint(); });
    }
    const gb = document.getElementById('generateBtn');
    if (gb && mc) {
        gb.addEventListener('click', () => { main = mc.value; roles = generate(main); manual = false; paint(); });
    }
    if (rolesBox) {
        rolesBox.addEventListener('input', e => {
            const k = e.target.dataset.k; let v = e.target.value.trim();
            if (e.target.classList.contains('hex')) { if (!/^#?[0-9a-fA-F]{6}$/.test(v)) return; if (v[0] !== '#') v = '#' + v; }
            roles[k] = v.toLowerCase(); manual = true; paint();
        });
    }
    const so = document.getElementById('startOver');
    if (so) {
        so.addEventListener('click', () => {
            roles = Object.fromEntries(Object.entries(ROLES).map(([k, r]) => [k, r.default]));
            main = ROLES.primary.default; manual = false;
            if (mc) mc.value = main;
            paint();
        });
    }
    document.querySelectorAll('[data-try]').forEach(b => b.addEventListener('click', () => {
        const h = HISTORY[+b.dataset.try]; roles = { ...h.roles }; main = h.main || ROLES.primary.default; manual = false;
        if (mc) mc.value = main;
        paint();
    }));
    const sf = document.getElementById('saveForm');
    if (sf) {
        sf.addEventListener('submit', e => {
            const bad = PAIRS.filter(([, fg, bg]) => contrast(roles[fg], roles[bg]) < 3).length;
            const msg = (bad ? `${bad} text colour${bad > 1 ? 's are' : ' is'} hard to read with these colours.\n\n` : '') + 'Make these colours live for everyone (website and admin panel)?';
            if (!confirm(msg)) { e.preventDefault(); return; }
            document.getElementById('fRoles').value = JSON.stringify(roles);
            document.getElementById('fMain').value = main;
            document.getElementById('fManual').value = manual ? '1' : '';
        });
    }
    paint();
})();
