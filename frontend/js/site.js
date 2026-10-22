/* BUCuC public site behaviour (index.php). Needs js/aurora-sky.js (window.AuroraSky),
 * Bootstrap (carousel, tabs) and Swiper. Panel data comes from window.BUCUC_PANEL_YEARS. */
(function () {
    'use strict';
    const Sky = window.AuroraSky || { pulse() {}, burst() {}, onFrame() {}, reduce: true };
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g,
        c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /* ------------------------------------------------ Navbar + mobile drawer */
    const nav = $('mainNavbar');
    const onScroll = () => nav && nav.classList.toggle('scrolled', window.scrollY > 30);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    const drawer = $('mobileSidebar'), overlay = $('mobileSidebarOverlay'), menuBtn = $('sidebarToggle');
    // Closed drawer is inert (its links can't be reached with Tab); open drawer holds focus until closed.
    function setDrawer(open, refocus) {
        const was = drawer.classList.contains('open');
        drawer.classList.toggle('open', open);
        overlay.classList.toggle('open', open);
        menuBtn.classList.toggle('open', open);
        menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        menuBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        drawer.inert = !open;
        if (open) { const first = drawer.querySelector('a, button'); if (first) first.focus(); }
        else if (was && refocus) menuBtn.focus();
    }
    if (drawer && overlay && menuBtn) {
        drawer.inert = true;
        menuBtn.addEventListener('click', () => setDrawer(!drawer.classList.contains('open'), true));
        overlay.addEventListener('click', () => setDrawer(false, true));
        const closeBtn = $('drawerClose');
        if (closeBtn) closeBtn.addEventListener('click', () => setDrawer(false, true));
        drawer.querySelectorAll('a').forEach(a => a.addEventListener('click', () => setDrawer(false)));
        document.addEventListener('keydown', e => {
            if (!drawer.classList.contains('open')) return;
            if (e.key === 'Escape') { setDrawer(false, true); return; }
            if (e.key === 'Tab') {
                // keep Tab inside the open drawer
                const f = [...drawer.querySelectorAll('a, button')];
                const i = f.indexOf(document.activeElement);
                if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
                else if (!e.shiftKey && (i === f.length - 1 || i === -1)) { e.preventDefault(); f[0].focus(); }
            }
        });
        // desktop width: the drawer is never shown, keep it out of the Tab order
        window.addEventListener('resize', () => { if (innerWidth > 991 && drawer.classList.contains('open')) setDrawer(false); });
    }

    /* ------------------------------------------------ Vinyl planet: staff ring + orbiting notes */
    (function () {
        const sys = $('system');
        if (!sys) return;
        const vinyl = sys.querySelector('.vinyl');
        const C = window.AuroraColor || ((n, fb) => 'rgb(' + fb.join(',') + ')');
        const COLORS = [C('--c-accent2', [92, 255, 176]), C('--c-accent', [45, 226, 230]), C('--c-primary', [139, 92, 255]),
            C('--c-primary-c457f0', [196, 87, 240]), C('--c-accent3', [255, 79, 216])];
        const KS = [1.42, 1.47, 1.52, 1.57, 1.62];
        ['back', 'front'].forEach(kind => KS.forEach((k, i) => {
            const r = document.createElement('i');
            r.className = 'ring ' + kind;
            r.style.setProperty('--k', k);
            r.style.setProperty('--c', COLORS[i]);
            sys.appendChild(r);
        }));
        const notes = ['♪', '♫', '♩', '♬'].map((g, i) => {
            const n = document.createElement('span');
            n.className = 'note';
            n.textContent = g;
            n.style.setProperty('--c', COLORS[(i * 2 + 1) % 5]);
            sys.appendChild(n);
            return n;
        });
        const TILT = -17 * Math.PI / 180, RATIO = 0.27, K = 1.52;
        Sky.onFrame(t => {
            const d = vinyl.offsetWidth, a = d * K / 2, b = a * RATIO;
            notes.forEach((n, i) => {
                const th = t * (0.34 + i * 0.03) + i * 1.6;
                const x = a * Math.cos(th), y = b * Math.sin(th);
                const xr = x * Math.cos(TILT) - y * Math.sin(TILT);
                const yr = x * Math.sin(TILT) + y * Math.cos(TILT);
                const front = Math.sin(th) > 0;
                n.style.transform = 'translate(' + xr.toFixed(1) + 'px,' + yr.toFixed(1) + 'px) translate(-50%,-50%) scale(' + (front ? 1.15 : 0.8) + ')';
                n.style.zIndex = front ? 3 : 0;
                n.style.opacity = front ? 1 : 0.5;
            });
        });
    })();

    /* ------------------------------------------------ Hero video sound */
    (function () {
        const video = $('heroVideo'), btn = $('unmuteBtn'), hero = $('section_1');
        if (!video || !btn || !hero) return;
        let userSound = false;
        video.muted = true;
        video.play().catch(() => {});
        const show = on => {
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.setAttribute('aria-label', on ? 'Turn video sound off' : 'Turn video sound on');
            btn.querySelector('span').textContent = on ? 'Sound on' : 'Tap for sound';
        };
        // Button or a tap on the video itself toggles sound. Must run inside the click so the
        // browser counts it as a user gesture (otherwise sound is blocked).
        const toggle = () => {
            userSound = !userSound;
            video.muted = !userSound;
            if (userSound) {
                video.volume = 1;
                const p = video.play();
                if (p && p.catch) p.catch(() => {
                    // The browser refused to play with sound: fall back to muted and say so
                    userSound = false;
                    video.muted = true;
                    video.play().catch(() => {});
                    show(false);
                    btn.querySelector('span').textContent = 'Sound blocked by browser';
                });
            }
            show(userSound);
        };
        btn.addEventListener('click', e => { e.stopPropagation(); toggle(); });
        video.addEventListener('click', toggle);
        // Mute whenever the hero scrolls out of view; resume if the visitor turned sound on
        // Out of view: pause (stops decoding) and mute. Back in view: resume, with sound only if chosen.
        new IntersectionObserver(entries => entries.forEach(entry => {
            video.muted = !(entry.isIntersecting && userSound);
            if (entry.isIntersecting) video.play().catch(() => {});
            else video.pause();
        }), { threshold: 0.2 }).observe(hero);
    })();

    /* ------------------------------------------------ Secretaries carousel */
    window.initSbSwiper = function () {
        if (!window.Swiper) return;
        if (window.sbSwiperInstance) window.sbSwiperInstance.destroy(true, true);
        const count = document.querySelectorAll('.sb-swiper .swiper-slide').length;
        window.sbSwiperInstance = new Swiper('.sb-swiper', {
            effect: 'coverflow',
            grabCursor: true,
            centeredSlides: true,
            loop: count > 5,
            loopAdditionalSlides: 2,
            coverflowEffect: { rotate: 26, stretch: 0, depth: 140, modifier: 1, slideShadows: false },
            pagination: { el: '.swiper-pagination', clickable: true },
            autoplay: Sky.reduce ? false : { delay: 3000, disableOnInteraction: false, pauseOnMouseEnter: true },
            breakpoints: {
                320: { slidesPerView: 1.2, spaceBetween: 14 },
                480: { slidesPerView: 2, spaceBetween: 20 },
                768: { slidesPerView: 3, spaceBetween: 30 },
                1024: { slidesPerView: 5, spaceBetween: 40 }
            }
        });
    };
    window.initSbSwiper();
    const sbSection = $('section_4');
    if (sbSection && !Sky.reduce) {
        new IntersectionObserver(entries => entries.forEach(entry => {
            const sw = window.sbSwiperInstance;
            if (!sw || !sw.autoplay) return;
            entry.isIntersecting ? sw.autoplay.start() : sw.autoplay.stop();
        }), { threshold: 0.3 }).observe(sbSection);
    }

    /* ------------------------------------------------ Panel year switcher */
    (function () {
        const years = window.BUCUC_PANEL_YEARS || [];
        const select = $('panelYearSelect'), grid = $('panelMembersGrid'), wrapper = $('sbSwiperWrapper');
        if (!select || !grid || !wrapper) return;

        // Same rule as panelColumnClass() in PHP: 4 members fill one row, otherwise rows of 3
        const columnClass = count => count === 4 ? 'col-lg-3 col-md-6 col-12 pmc-col' : 'col-lg-4 col-md-6 col-12 pmc-col';
        const webpOf = url => {
            if (!url || typeof url !== 'string') return url;
            const parts = url.split('?');
            if (/\.(jpe?g|png)$/i.test(parts[0])) {
                return parts[0].replace(/\.(jpe?g|png)$/i, '.webp') + (parts[1] ? '?' + parts[1] : '');
            }
            return url;
        };

        select.addEventListener('change', () => {
            const data = years[select.value];
            if (!data) return;
            $('panelYearTitle').textContent = data.label;
            grid.innerHTML = data.panel.map(m => `
                <div class="${columnClass(data.panel.length)}">
                    <div class="panel-member-card">
                        <div class="pmc-image-wrap">
                            <picture>
                                <source srcset="${esc(webpOf(m.src))}" type="image/webp">
                                <img src="${esc(m.src)}" alt="${esc(m.name)}" loading="lazy" onerror="this.onerror=null; var p=this.parentElement; if(p&&p.tagName==='PICTURE'){p.querySelectorAll('source').forEach(function(s){s.remove();});} this.src='images/placeholder.svg';">
                            </picture>
                            ${m.facebook ? `<div class="pmc-overlay"><a href="${esc(m.facebook)}" target="_blank" rel="noopener" aria-label="Facebook – ${esc(m.name)}"><ion-icon name="logo-facebook"></ion-icon></a></div>` : ''}
                        </div>
                        <div class="pmc-body">
                            <p class="pmc-name">${esc(m.name)}</p>
                            <span class="pmc-role">${esc(m.position)}</span>
                        </div>
                    </div>
                </div>`).join('');
            wrapper.innerHTML = data.secretaries.map(m => `
                <div class="swiper-slide">
                    <picture>
                        <source srcset="${esc(webpOf(m.src))}" type="image/webp">
                        <img src="${esc(m.src)}" alt="${esc(m.name)}" loading="lazy" onerror="this.onerror=null; var p=this.parentElement; if(p&&p.tagName==='PICTURE'){p.querySelectorAll('source').forEach(function(s){s.remove();});} this.src='images/placeholder.png';" />
                    </picture>
                    <div class="overlay">${m.facebook ? `<a href="${esc(m.facebook)}" target="_blank" rel="noopener" aria-label="Facebook – ${esc(m.name)}"><ion-icon name="logo-facebook"></ion-icon></a>` : ''}</div>
                    <div class="member-name"><span class="name">${esc(m.name)}</span><span class="position">${esc(m.position || 'Secretary')}</span></div>
                </div>`).join('');
            window.initSbSwiper();
            Sky.pulse(0.8);
        });
    })();

    /* ------------------------------------------------ Soft notes while typing (off by default) */
    const Sound = (() => {
        let ac = null, master = null, on = false, idx = 0;
        const PENT = [0, 2, 4, 7, 9];
        function init() {
            if (ac) return true;
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return false;
            ac = new AC();
            master = ac.createGain(); master.gain.value = 0.9;
            const dly = ac.createDelay(1); dly.delayTime.value = 0.27;
            const fb = ac.createGain(); fb.gain.value = 0.34;
            const lp = ac.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 2400;
            master.connect(ac.destination);
            master.connect(dly); dly.connect(lp); lp.connect(fb); fb.connect(dly); lp.connect(ac.destination);
            return true;
        }
        function tone(semi, dur, vol, when) {
            if (!on || !ac) return;
            const t = ac.currentTime + (when || 0);
            const o = ac.createOscillator(), g = ac.createGain();
            o.type = 'sine'; o.frequency.value = 220 * Math.pow(2, semi / 12);
            g.gain.setValueAtTime(0.0001, t);
            g.gain.linearRampToValueAtTime(vol, t + 0.012);
            g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
            o.connect(g); g.connect(master);
            o.start(t); o.stop(t + dur + 0.05);
        }
        return {
            toggle() {
                on = !on;
                if (on) {
                    if (!init()) { on = false; return false; }
                    if (ac.resume) ac.resume();
                    tone(12, 0.9, 0.04); tone(19, 1.1, 0.03, 0.09);
                }
                return on;
            },
            key() {
                if (!on) return;
                idx = (idx + (Math.random() < 0.5 ? 1 : 2)) % 10;
                tone(PENT[idx % 5] + 12 * Math.floor(idx / 5) + 12, 0.7, 0.05);
            },
            chime() { tone(12, 0.9, 0.04); tone(19, 1.1, 0.03, 0.09); },
            done() { [0, 4, 7, 12, 16, 19, 24].forEach((s, i) => tone(s + 12, 1.4, 0.05, i * 0.1)); }
        };
    })();
    const soundBtn = $('soundBtn');
    if (soundBtn) {
        soundBtn.addEventListener('click', () => {
            const on = Sound.toggle();
            soundBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            soundBtn.querySelector('span').textContent = on ? 'Typing sound on' : 'Typing sound off';
        });
    }

    /* ------------------------------------------------ Apply form */
    (function () {
        const form = $('signup-form');
        if (!form) return;
        const panel = $('applyPanel');
        const banner = $('applyBanner');
        const submitBtn = $('submitBtn');
        const progBars = Array.from(document.querySelectorAll('#prog i'));
        const progText = $('progText');

        // Department preference lists: each list hides the other list's choice
        const DEPTS = [
            ['Admin', 'Admin'], ['PR', 'PR - Public Relations and Editorial'], ['HR', 'HR - Human Resources'],
            ['EM', 'EM - Event Management and Logistics'], ['Creative', 'Creative'], ['Finance', 'Finance'],
            ['Performance', 'Performance'], ['RD', 'R&D - Research and Development'], ['MIAP', 'MIAP - Marketing IT Archive & Photography']
        ];
        const first = $('signup-first-dept'), second = $('signup-second-dept');
        function populate(select, exclude, keep) {
            select.innerHTML = '<option value="">Select department</option>';
            DEPTS.forEach(([v, t]) => { if (v !== exclude) select.add(new Option(t, v)); });
            if (keep && keep !== exclude) select.value = keep;
        }
        function syncDepts() {
            const a = first.value, b = second.value;
            populate(first, b, a);
            populate(second, first.value, b);
        }
        populate(first, null, first.dataset.old);
        populate(second, first.value, second.dataset.old);
        first.addEventListener('change', syncDepts);
        second.addEventListener('change', syncDepts);

        const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const cleanPhone = v => v.replace(/[\s\-().]/g, '');
        const normFb = v => { v = v.trim(); if (v && !/^https?:\/\//i.test(v)) v = 'https://' + v.replace(/^\/+/, ''); return v; };
        const todayISO = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); };
        const validDob = v => {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return false;
            const [y, m, d] = v.split('-').map(Number);
            const dt = new Date(Date.UTC(y, m - 1, d));
            return dt.getUTCFullYear() === y && dt.getUTCMonth() === m - 1 && dt.getUTCDate() === d && v >= '1950-01-01' && v <= todayISO();
        };

        // Same rules the server applies in backend/Action/signup_handler.php (plus a phone format hint)
        const RULES = {
            'signup-name': [v => v.replace(/\s+/g, ' ').trim().length >= 2, 'Enter your full name.'],
            'signup-id': [v => v.trim().length >= 4, 'Enter your university ID.'],
            'signup-main-email': [v => EMAIL_RE.test(v.trim()), 'Enter a valid email address, for example name@gmail.com.'],
            'signup-gsuite-email': [v => EMAIL_RE.test(v.trim()), 'Enter your G-Suite address, for example name@g.bracu.ac.bd.'],
            'signup-department': [v => v.trim().length >= 2, 'Enter your academic department, for example CSE.'],
            'signup-phone': [v => /^\+?\d{10,15}$/.test(cleanPhone(v)), 'Enter a phone number with 10 to 15 digits.'],
            'signup-semester': [v => v !== '', 'Choose your current semester.'],
            'signup-gender': [v => v !== '', 'Choose your gender.'],
            'signup-dob': [validDob, 'Pick your date of birth. It must be a date in the past.'],
            'signup-facebook': [v => /^https?:\/\/[^\s.]+\.[^\s]+$/i.test(normFb(v)), 'Paste the link to your Facebook profile, for example facebook.com/your.profile.'],
            'signup-first-dept': [v => v !== '', 'Choose your first department preference.'],
            'signup-second-dept': [v => v !== '' && v !== first.value, 'Choose a second preference, different from the first.']
        };
        const KEYS = Object.keys(RULES);
        const els = {};
        KEYS.forEach(k => {
            const input = $(k);
            const row = input.closest('.field');
            els[k] = { input, row, err: row.querySelector('.err'), touched: input.value !== '' };
        });
        $('signup-dob').max = todayISO();

        const isOk = k => { const v = els[k].input.value; return v.trim() !== '' && RULES[k][0](v); };
        function check(k, show) {
            const f = els[k], ok = isOk(k), bad = show && !ok;
            f.row.classList.toggle('ok', ok);
            f.row.classList.toggle('bad', bad);
            f.input.setAttribute('aria-invalid', bad ? 'true' : 'false');
            f.err.textContent = bad ? RULES[k][1] : '';
            if (f.input.tagName === 'SELECT' || f.input.type === 'date') f.input.classList.toggle('empty', f.input.value === '');
            return ok;
        }
        function progress() {
            const n = KEYS.filter(isOk).length;
            const lit = Math.round(n / KEYS.length * progBars.length);
            progBars.forEach((b, i) => b.classList.toggle('on', i < lit));
            progText.textContent = n + ' of ' + KEYS.length + ' filled';
        }

        KEYS.forEach(k => {
            const f = els[k];
            const evt = f.input.tagName === 'SELECT' ? 'change' : 'input';
            f.input.addEventListener(evt, e => {
                check(k, f.touched);
                if (k === 'signup-first-dept' || k === 'signup-second-dept') { check('signup-first-dept', els['signup-first-dept'].touched); check('signup-second-dept', els['signup-second-dept'].touched); }
                progress();
                Sky.pulse(0.3);
                if (!e.inputType || e.inputType.indexOf('delete') !== 0) Sound.key();
            });
            f.input.addEventListener('change', () => { check(k, f.touched); progress(); });
            f.input.addEventListener('blur', () => { if (f.input.value.trim() !== '') f.touched = true; check(k, f.touched); progress(); });
            f.input.addEventListener('focus', () => Sound.chime());
            check(k, f.touched && f.input.value !== '');
        });
        setTimeout(progress, 400); // picks up browser autofill
        progress();

        form.addEventListener('submit', e => {
            let firstBad = null;
            KEYS.forEach(k => { els[k].touched = true; if (!check(k, true) && !firstBad) firstBad = k; });
            progress();
            if (firstBad) {
                e.preventDefault();
                els[firstBad].input.focus();
                panel.classList.remove('shake'); void panel.offsetWidth; panel.classList.add('shake');
                return;
            }
            // Send cleaned values
            els['signup-name'].input.value = els['signup-name'].input.value.replace(/\s+/g, ' ').trim();
            els['signup-phone'].input.value = cleanPhone(els['signup-phone'].input.value);
            els['signup-facebook'].input.value = normFb(els['signup-facebook'].input.value);
            ['signup-main-email', 'signup-gsuite-email'].forEach(k => { els[k].input.value = els[k].input.value.trim().toLowerCase(); });
            submitBtn.disabled = true;
            submitBtn.classList.add('busy');
            $('goText').textContent = 'Submitting';
            if (banner) banner.hidden = true;
        });
    })();

    /* ------------------------------------------------ Success ticket */
    (function () {
        const done = $('applyDone');
        if (!done) return;
        const wave = $('wave');
        const id = (done.dataset.id || '').replace(/\D/g, '') || '2002';
        id.split('').forEach((ch, i) => {
            const d = Number(ch);
            [0, 1].forEach(j => {
                const b = document.createElement('i');
                b.style.height = (28 + ((d * 7 + j * 17 + i * 5) % 72)) + '%';
                b.style.animationDelay = (-(i * 2 + j) * 0.13).toFixed(2) + 's';
                wave.appendChild(b);
            });
        });
        setTimeout(() => Sky.burst(), 600);
        const again = $('againBtn');
        if (again) again.addEventListener('click', () => {
            done.hidden = true;
            $('signup-form').hidden = false;
            $('applyHeadProgress').hidden = false;
            $('signup-name').focus();
        });
    })();

    /* ------------------------------------------------ Deep links (#section_5, #signup, QR codes) */
    function scrollToHash() {
        if (!location.hash || location.hash.length < 2) return;
        let target = null;
        try { target = document.querySelector(location.hash); } catch (e) { return; }
        if (!target) return;
        setTimeout(() => {
            const top = target.getBoundingClientRect().top + window.scrollY - 76;
            window.scrollTo({ top, behavior: Sky.reduce ? 'auto' : 'smooth' });
        }, 450);
    }
    scrollToHash();                                   // right away (layout is reserved by aspect-ratio boxes)
    window.addEventListener('load', scrollToHash);    // and once more after images settle
    window.addEventListener('hashchange', scrollToHash);
})();
