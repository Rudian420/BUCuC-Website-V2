/* Aurora sky background shared by every page (stars, aurora curtains, equalizer horizon,
 * shooting stars). Injects <canvas id="sky"> + shade + grain layers at the start of <body>.
 * Admin pages (body.au-admin) get a calmer version.
 * API: window.AuroraSky.pulse(n), .burst(), .onFrame(fn), .reduce
 */
/* Site palette colours for canvas drawing. CSS variables hold "R G B"; the fallback is the original
 * colour, used when no custom palette is set (Admin → Site Colours). */
window.AuroraColor = function (name, fallback, alpha) {
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const rgb = v ? v.split(/\s+/).map(Number) : fallback;
    return alpha === undefined ? 'rgb(' + rgb.join(',') + ')' : 'rgba(' + rgb.join(',') + ',' + alpha + ')';
};
window.AuroraRGB = function (name, fallback) {
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v ? v.split(/\s+/).map(Number) : fallback;
};

window.AuroraSky = (() => {
    const body = document.body;
    const calm = body.classList.contains('au-admin');

    const cv = document.createElement('canvas');
    cv.id = 'sky';
    cv.setAttribute('aria-hidden', 'true');
    const shade = document.createElement('div');
    shade.className = 'au-shade';
    shade.setAttribute('aria-hidden', 'true');
    const grain = document.createElement('div');
    grain.className = 'au-grain';
    grain.setAttribute('aria-hidden', 'true');
    body.prepend(grain);
    body.prepend(shade);
    body.prepend(cv);

    const ctx = cv.getContext('2d');
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const hooks = [];
    const AURORA = calm ? 0.5 : 1;     // aurora brightness
    const EQ = calm ? 0.28 : 0.6;      // equalizer opacity
    let W = 0, H = 0, DPR = 1, stars = [], shots = [];
    let bgGrad = null, barGrad = null, glowGrad = null;
    let energy = 0, boost = 0, mx = 0, my = 0, tx = 0, ty = 0;
    let T0 = performance.now(), last = T0, nextShot = 3, running = true;

    const C = window.AuroraColor, RGB = window.AuroraRGB;
    const LAYERS = [
        { c: [92, 255, 176], role: '--c-accent2', base: 0.10, amp: 0.080, f1: 0.0042, f2: 0.0091, s1: 0.22, s2: 0.15, len: 0.48, a: 0.52, off: 0.0 },
        { c: [45, 226, 230], role: '--c-accent', base: 0.07, amp: 0.090, f1: 0.0036, f2: 0.0073, s1: 0.17, s2: 0.21, len: 0.52, a: 0.40, off: 1.7 },
        { c: [139, 92, 255], role: '--c-primary', base: 0.13, amp: 0.080, f1: 0.0031, f2: 0.0105, s1: 0.13, s2: 0.18, len: 0.50, a: 0.48, off: 3.1 },
        { c: [255, 79, 216], role: '--c-accent3', base: 0.05, amp: 0.060, f1: 0.0050, f2: 0.0067, s1: 0.19, s2: 0.12, len: 0.42, a: 0.26, off: 4.6 }
    ];

    function barMax() { return Math.min(H * 0.17, 170); }

    function build() {
        DPR = 1;                    // the aurora is soft; full retina resolution only costs GPU time
        W = window.innerWidth; H = window.innerHeight;
        cv.width = Math.round(W * DPR); cv.height = Math.round(H * DPR);
        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);

        bgGrad = ctx.createLinearGradient(0, 0, 0, H);
        bgGrad.addColorStop(0, C('--c-fixed-04030c', [4, 3, 12]));
        bgGrad.addColorStop(0.55, C('--c-surface-0a0724', [10, 7, 36]));
        bgGrad.addColorStop(1, C('--c-surface-160b3c', [22, 11, 60]));

        LAYERS.forEach(L => {
            const g = ctx.createLinearGradient(0, 0, 0, H * L.len);
            const c = RGB(L.role, L.c).join(',');
            g.addColorStop(0, 'rgba(' + c + ',0)');
            g.addColorStop(0.45, 'rgba(' + c + ',0.22)');
            g.addColorStop(0.8, 'rgba(' + c + ',0.85)');
            g.addColorStop(0.92, 'rgba(' + c + ',0.42)');
            g.addColorStop(1, 'rgba(' + c + ',0)');
            L.grad = g;
        });

        const maxH = barMax();
        barGrad = ctx.createLinearGradient(0, H, 0, H - maxH * 1.2);
        barGrad.addColorStop(0, C('--c-primary', [139, 92, 255], 0.9));
        barGrad.addColorStop(0.55, C('--c-accent', [45, 226, 230], 0.9));
        barGrad.addColorStop(1, C('--c-accent2', [92, 255, 176], 1));

        glowGrad = ctx.createRadialGradient(W / 2, H, 0, W / 2, H, Math.max(W, H) * 0.6);
        glowGrad.addColorStop(0, C('--c-primary', [139, 92, 255], 0.30));
        glowGrad.addColorStop(1, C('--c-primary', [139, 92, 255], 0));

        starTint = C('--c-text-cfe6ff', [207, 230, 255]);
        const n = Math.round((W * H) / 5200);
        stars = Array.from({ length: n }, () => ({
            x: Math.random() * W, y: Math.random() * H * 0.95, z: Math.random(),
            r: Math.random() * 1.1 + 0.3, p: Math.random() * 6.28, s: 0.5 + Math.random() * 1.8
        }));
    }

    let starTint = '#cfe6ff';
    function draw(t) {
        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = 1;
        ctx.fillStyle = bgGrad;
        ctx.fillRect(0, 0, W, H);

        const intro = reduce ? 1 : Math.min(1, t / 2.6);

        for (let i = 0; i < stars.length; i++) {
            const s = stars[i];
            const tw = 0.55 + 0.45 * Math.sin(t * s.s + s.p);
            ctx.globalAlpha = tw * (0.3 + 0.7 * s.z) * Math.min(1, intro * 1.6);
            ctx.fillStyle = s.z > 0.82 ? starTint : '#ffffff';
            const px = s.x + mx * s.z * 18, py = s.y + my * s.z * 12;
            if (s.r > 0.9) { ctx.beginPath(); ctx.arc(px, py, s.r, 0, 6.2832); ctx.fill(); }
            else ctx.fillRect(px, py, s.r * 1.4, s.r * 1.4);
        }

        ctx.globalCompositeOperation = 'lighter';
        const step = W < 700 ? 9 : 7;
        for (let li = 0; li < LAYERS.length; li++) {
            const L = LAYERS[li];
            ctx.fillStyle = L.grad;
            for (let x = 0; x < W + step; x += step) {
                const w1 = Math.sin(x * L.f1 + t * L.s1 + L.off);
                const w2 = Math.sin(x * L.f2 - t * L.s2 * 1.3 + L.off * 2);
                const y = H * L.base + (w1 * 0.65 + w2 * 0.35) * H * L.amp;
                const ray = 0.55 + 0.45 * Math.sin(x * 0.045 + t * 1.1 + L.off * 3) * Math.sin(x * 0.013 - t * 0.4);
                const env = 0.25 + 0.75 * Math.pow(Math.max(0, Math.sin(Math.PI * Math.min(1, Math.max(0, x / W)))), 0.6);
                const a = L.a * AURORA * ray * env * intro * (1 + boost * 0.9 + energy * 0.4);
                ctx.globalAlpha = a > 1 ? 1 : (a < 0 ? 0 : a);
                ctx.setTransform(DPR, 0, 0, DPR, 0, y * DPR);
                ctx.fillRect(x, 0, step + 0.4, H * L.len);
            }
        }
        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);

        ctx.globalAlpha = 1;
        ctx.fillStyle = glowGrad;
        ctx.fillRect(0, 0, W, H);

        ctx.globalCompositeOperation = 'source-over';
        const bw = W < 700 ? 5 : 7, gap = W < 700 ? 4 : 6, n = Math.ceil(W / (bw + gap)), maxH = barMax();
        ctx.fillStyle = barGrad;
        ctx.globalAlpha = EQ * intro;
        for (let i = 0; i < n; i++) {
            const u = i / n;
            const hill = 0.2 + 0.8 * Math.pow(Math.max(0, Math.sin(Math.PI * u)), 1.1) * (0.75 + 0.25 * Math.sin(u * 9 + 1));
            const beat = 0.5 + 0.5 * Math.sin(t * 2.4 + i * 0.36 + Math.sin(t * 0.8 + i * 0.12) * 2.2);
            const e = (0.25 + 0.75 * beat) * (1 + energy * 0.9 + boost * 0.5);
            const h = Math.min(maxH * 1.2, maxH * hill * e * 0.7) + 2;
            ctx.fillRect(i * (bw + gap) + gap / 2, H - h, bw, h);
        }

        ctx.globalCompositeOperation = 'lighter';
        for (let i = shots.length - 1; i >= 0; i--) {
            const s = shots[i];
            const k = 1 - s.age / s.life;
            const x2 = s.x - s.vx * 0.09, y2 = s.y - s.vy * 0.09;
            const g = ctx.createLinearGradient(s.x, s.y, x2, y2);
            g.addColorStop(0, 'rgba(255,255,255,' + (0.9 * k).toFixed(3) + ')');
            g.addColorStop(1, C('--c-primary-a0c8ff', [160, 200, 255], 0));
            ctx.globalAlpha = 1;
            ctx.strokeStyle = g; ctx.lineWidth = 1.6;
            ctx.beginPath(); ctx.moveTo(s.x, s.y); ctx.lineTo(x2, y2); ctx.stroke();
        }
        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = 1;
    }

    function update(t, dt) {
        energy *= Math.pow(0.05, dt);
        boost *= Math.pow(0.35, dt);
        mx += (tx - mx) * Math.min(1, dt * 3);
        my += (ty - my) * Math.min(1, dt * 3);
        for (let i = shots.length - 1; i >= 0; i--) {
            const s = shots[i];
            s.x += s.vx * dt; s.y += s.vy * dt; s.age += dt;
            if (s.age > s.life) shots.splice(i, 1);
        }
        if (t > nextShot) {
            const dir = Math.random() < 0.5 ? -1 : 1;
            shots.push({
                x: W * (0.15 + Math.random() * 0.7), y: H * Math.random() * 0.3,
                vx: dir * (500 + Math.random() * 300), vy: 220 + Math.random() * 160, age: 0, life: 0.9
            });
            nextShot = t + 5 + Math.random() * 7;
        }
    }

    const FRAME_MS = 1000 / 30;      // ~30 fps is plenty for slow-moving light
    let lastDraw = 0;
    function frame(now) {
        if (!running) return;
        requestAnimationFrame(frame);
        if (now - lastDraw < FRAME_MS - 1) return;
        lastDraw = now;
        const t = (now - T0) / 1000;
        const dt = Math.min(0.05, (now - last) / 1000);
        last = now;
        update(t, dt);
        draw(t);
        for (let i = 0; i < hooks.length; i++) hooks[i](t);
    }

    build();
    let rs = 0;
    window.addEventListener('resize', () => {
        clearTimeout(rs);
        rs = setTimeout(() => { build(); if (reduce) { draw(8); hooks.forEach(h => h(8)); } }, 120);
    });

    // Pause the animation while the tab is hidden (saves battery)
    document.addEventListener('visibilitychange', () => {
        if (reduce) return;
        if (document.hidden) { running = false; }
        else if (!running) { running = true; last = performance.now(); requestAnimationFrame(frame); }
    });

    if (reduce) {
        draw(8);
    } else {
        window.addEventListener('pointermove', e => { tx = (e.clientX / W - 0.5) * 2; ty = (e.clientY / H - 0.5) * 2; }, { passive: true });
        requestAnimationFrame(frame);
    }

    return {
        reduce,
        pulse(n) { energy = Math.min(1.6, energy + n); },
        burst() { boost = 1.5; energy = 1.5; if (reduce) draw(8); },
        onFrame(fn) { hooks.push(fn); if (reduce) fn(8); }
    };
})();
