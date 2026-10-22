// UI polish / QA checks: every public and admin page (as each role) at phone, tablet and desktop widths —
// no sideways scrolling, unique "Page | BUCuC" titles, no script errors or broken files, no "#" links,
// tap targets, menus (open / close / link / Escape / Tab stays inside), 404 page, favicons, footer year,
// busy buttons, crop window focus, empty lists, friendly error messages, no debug output in the code.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', '-P3307', '-uroot', '-prootpw', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const ROOT = T.ROOT_FWD;
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 300) : ''}`);
const sleep = ms => new Promise(r => setTimeout(r, ms));

const ACC = {
    super: ['super@test.local', 'superpass123'],
    hr: ['admin@test.local', 'adminpass123'],
    panel: ['panel@test.local', 'panelpass123'],
    miap: ['miap@test.local', 'miappass123'],
    sec: ['sec@test.local', 'secpass1234'],
};
const PUBLIC = ['index.php', 'past_events.php', 'performances.php', 'admin-login.php', 'forgot-password.php', 'reset-password.php?token=bad', 'checkin.php', '404.php'];
const SKIP = /(export|go\.php|handle_application|\.(jpg|png|pdf|xlsx|csv)$)/i;
const keyOf = u => { const x = new URL(u); return x.pathname; };   // one check per page file (query variants are the same page)

let browser;
async function as(role) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    page.on('dialog', d => d.dismiss());
    page.problems = [];
    page.on('pageerror', e => page.problems.push('script error: ' + e.message));
    page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) page.problems.push('console error: ' + m.text()); });
    page.on('response', r => { const u = r.url(); if (r.status() >= 400 && u.startsWith('http://127.0.0.1:8099/') && u !== page.url()) page.problems.push(`HTTP ${r.status()} ${u}`); });
    await page.setViewport({ width: 1366, height: 900 });
    if (role) {
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', ACC[role][0]);
        await page.type('#adminPassword', ACC[role][1]);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}
async function view(page, w) {
    await page.setViewport({ width: w, height: w < 800 ? 780 : 900, isMobile: w < 800, hasTouch: w < 800 });
}

/** Everything measured on one page at the current width. */
function measure() {
    const W = innerWidth;
    const vis = e => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !e.closest('[inert], [aria-hidden=true]'); };
    const clipped = e => { for (let p = e.parentElement; p && p !== document.body; p = p.parentElement) { const s = getComputedStyle(p); if (/(auto|scroll|hidden|clip)/.test(s.overflowX) || s.position === 'fixed') return true; } return false; };
    const desc = e => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/)[0] : '') + ` "${(e.innerText || e.value || e.getAttribute('aria-label') || '').trim().slice(0, 25)}"`;
    const out = { title: document.title, scrollW: document.documentElement.scrollWidth, over: [], small: [], hash: [], broken: [] };
    if (out.scrollW > W + 1) for (const e of document.querySelectorAll('body *')) { if (vis(e) && e.getBoundingClientRect().right > W + 1 && !clipped(e)) { out.over.push(desc(e)); if (out.over.length > 4) break; } }
    // Tap targets (WCAG 2.5.8): at least 24 × 24 px. Links inside running text are exempt, as are elements inside
    // a sideways-scrolling strip or a 3D carousel (their size on screen changes as they move).
    for (const e of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [role=tab]')) {
        if (!vis(e) || e.closest('.swiper, .carousel-3d, [data-no-tapcheck]')) continue;
        // a link inside a sentence is exempt (WCAG 2.5.8 "inline" exception)
        if (e.tagName === 'A' && getComputedStyle(e).display === 'inline' && e.parentElement.textContent.trim().length > e.textContent.trim().length) continue;
        if (e.type === 'checkbox' || e.type === 'radio') {
            const lbl = e.closest('label') || (e.id ? document.querySelector(`label[for="${e.id}"]`) : null);
            if (lbl && lbl.getBoundingClientRect().height >= 24) continue;
        }
        const r = e.getBoundingClientRect();
        if (r.height < 24 || r.width < 24) out.small.push(desc(e) + ` ${Math.round(r.width)}x${Math.round(r.height)}`);
    }
    out.hash = [...document.querySelectorAll('a[href]')].filter(a => { const h = a.getAttribute('href').trim(); return h === '#' || h === '' || /^javascript:/i.test(h); }).map(desc);
    out.broken = [...document.images].filter(i => i.complete && i.naturalWidth === 0 && i.getAttribute('src') && !i.closest('[hidden]')).map(i => i.getAttribute('src'));
    out.links = [...document.querySelectorAll('a[href]')].map(a => a.href);
    // a positive tabindex makes Tab jump out of the visual order
    out.posTab = [...document.querySelectorAll('[tabindex]')].filter(e => e.tabIndex > 0).map(desc);
    return out;
}

async function checkPage(page, label, url, widths, seenTitles) {
    let m;
    for (const w of widths) {
        await view(page, w);
        page.problems = [];
        const resp = await page.goto(url, { waitUntil: 'load', timeout: 30000 }).catch(e => null);
        await page.evaluate(async () => { document.querySelectorAll('img[loading=lazy]').forEach(i => { i.loading = 'eager'; }); await new Promise(r => setTimeout(r, 500)); });
        m = await page.evaluate(measure);
        const status = resp ? resp.status() : 0;
        const tag = `${label} @${w}`;
        if (!/404\.php/.test(url)) ok(`${tag}: page opens (HTTP ${status})`, status === 200, page.url());
        ok(`${tag}: no sideways scrolling`, m.scrollW <= w + 1, `${m.scrollW}px wide: ${m.over.join(' | ')}`);
        ok(`${tag}: tap targets at least 24px`, m.small.length === 0, m.small.slice(0, 6).join(' | '));
        if (w === widths[widths.length - 1]) {
            ok(`${tag}: no script errors or missing files`, page.problems.length === 0, [...new Set(page.problems)].slice(0, 4).join(' || '));
            ok(`${tag}: no broken images`, m.broken.length === 0, m.broken.join(' '));
            ok(`${tag}: no "#" or empty links`, m.hash.length === 0, m.hash.join(' | '));
            ok(`${tag}: Tab order follows the page (no positive tabindex)`, m.posTab.length === 0, m.posTab.join(' | '));
            ok(`${tag}: title "Page | BUCuC" or "Page | BUCuC Admin"`, /^\S.* \| BUCuC( Admin)?$/.test(m.title), m.title);
            const p = keyOf(page.url());
            if (seenTitles[m.title] && seenTitles[m.title] !== p && !/Page Not Found/.test(m.title)) ok(`${tag}: title is unique`, false, `"${m.title}" also used by ${seenTitles[m.title]}`);
            seenTitles[m.title] = seenTitles[m.title] || p;
        }
    }
    return m;
}

(async () => {
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 120000 });
    try {
        // ------------------------------------------------------------ static checks (source code)
        const walk = d => fs.readdirSync(d, { withFileTypes: true }).flatMap(e => e.isDirectory() ? (/^(vendor|node_modules|fonts|images|video)$/.test(e.name) ? [] : walk(path.join(d, e.name))) : [path.join(d, e.name)]);
        const src = [...walk(path.join(ROOT, 'frontend')), ...walk(path.join(ROOT, 'backend'))].filter(f => /\.(php|js|html)$/.test(f) && !/\.min\.js$/.test(f));
        const debug = src.flatMap(f => fs.readFileSync(f, 'utf8').split('\n').map((l, i) => [f, i + 1, l]))
            .filter(([f, i, l]) => /console\.(log|debug|info|table|error)\(|\bvar_dump\(|\bprint_r\(|^\s*debugger;/.test(l) && !/^\s*(\/\/|\*)/.test(l));
        ok('no console.log / var_dump / debug output left in the code', debug.length === 0, debug.slice(0, 5).map(([f, i]) => path.relative(ROOT, f) + ':' + i).join(', '));
        const heads = src.filter(f => /frontend[\\/][^\\/]+\.(php|html)$|dashboard_view|member_board/.test(f)).filter(f => /<title>/.test(fs.readFileSync(f, 'utf8')));
        const noIcon = heads.filter(f => !/favicon-32\.png/.test(fs.readFileSync(f, 'utf8')) || !/apple-touch-icon/.test(fs.readFileSync(f, 'utf8')));
        ok(`every page links the favicon set (${heads.length} pages)`, noIcon.length === 0, noIcon.map(f => path.basename(f)).join(', '));
        const icons = ['favicon.ico', 'frontend/images/favicon-16.png', 'frontend/images/favicon-32.png', 'frontend/images/apple-touch-icon.png', 'frontend/images/icon-192.png', 'frontend/images/icon-512.png', 'frontend/site.webmanifest'];
        ok('favicon files exist (tab, Apple, Android, manifest)', icons.every(f => fs.existsSync(path.join(ROOT, f))), icons.filter(f => !fs.existsSync(path.join(ROOT, f))).join(', '));
        const ht = fs.readFileSync(path.join(ROOT, '.htaccess'), 'utf8');
        ok('.htaccess sends missing pages to the 404 page', /ErrorDocument 404 \/frontend\/404\.php/.test(ht));
        ok('tests/ is blocked from the web', /Require all denied/.test(fs.readFileSync(path.join(ROOT, 'tests', '.htaccess'), 'utf8')));
        // big photos slow phones down: no image a page uses may be over 1 MB (backend/scripts/compress_images.php)
        const allSrc = src.map(f => fs.readFileSync(f, 'utf8')).join('\n');
        const imgs = walkImgs(path.join(ROOT, 'frontend', 'images'));
        const heavy = imgs.filter(f => fs.statSync(f).size > 1024 * 1024 && allSrc.includes(path.basename(f)));
        ok('no image used by a page is over 1 MB', heavy.length === 0, heavy.map(f => path.relative(ROOT, f) + ' ' + Math.round(fs.statSync(f).size / 1024) + ' KB').join(', '));
        // raw database errors never reach the page
        const fe = execFileSync(T.PHP, ['-d', `auto_prepend_file=${T.PREPEND}`, '-r', `require '${ROOT}/backend/includes/site.php'; echo friendly_error(new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'x.secret'"), 'Saving the venue'), "\\n", friendly_error(new RuntimeException('That notice no longer exists.'), 'Sending');`]).toString();
        ok('database errors are shown as a plain message (no SQL)', !/SQLSTATE|secret|1146/.test(fe) && /Saving the venue failed because of a server problem/.test(fe), fe);
        ok("the site's own messages are shown as they are", /That notice no longer exists\./.test(fe));

        // Ensure attendance session and expected record exist so attendance subpages (session, member, checkin)
        // are always discovered by the admin crawler regardless of suite execution order.
        sql(`CREATE TABLE IF NOT EXISTS attendance_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, type VARCHAR(20) NOT NULL DEFAULT 'rehearsal',
            session_date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME NULL, audience VARCHAR(10) NOT NULL DEFAULT 'all',
            event_id INT NULL, qr_enabled TINYINT(1) NOT NULL DEFAULT 0, checkin_secret CHAR(64) NULL,
            checkin_opened_at DATETIME NULL, checkin_closes_at DATETIME NULL,
            created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_att_date (session_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        sql(`CREATE TABLE IF NOT EXISTS attendance_expected (
            session_id INT NOT NULL, member_id INT NOT NULL, PRIMARY KEY (session_id, member_id), KEY idx_att_exp_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        sql(`CREATE TABLE IF NOT EXISTS attendance_records (
            id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, member_id INT NOT NULL,
            status ENUM('present','late','excused','absent') NOT NULL,
            method ENUM('manual','qr','bulk') NOT NULL DEFAULT 'manual',
            marked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            marked_by INT NULL, reason VARCHAR(255) NULL,
            UNIQUE KEY uniq_att_rec (session_id, member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);

        const sessCount = parseInt(sql('SELECT COUNT(*) FROM attendance_sessions'), 10) || 0;
        if (sessCount === 0) {
            const memCount = parseInt(sql('SELECT COUNT(*) FROM members'), 10) || 0;
            if (memCount === 0) {
                sql(`INSERT INTO members (full_name, university_id, email, department, phone, semester, gender, position) VALUES
                    ('Ayaan Member', '21100001', 'ayaan@example.com', 'CSE', '01711111111', '1st', 'Male', 'GB'),
                    ('Nusrat Member', '21100002', 'nusrat@example.com', 'EEE', '01711111112', '2nd', 'Female', 'GB'),
                    ('Rahim Member', '21100003', 'rahim@example.com', 'BBA', '01711111113', '3rd', 'Male', 'GB'),
                    ('Tanjila Member', '21100004', 'tanjila@example.com', 'Law', '01711111114', '4th', 'Female', 'ASB'),
                    ('Farhan Member', '21100005', 'farhan@example.com', 'Physics', '01711111115', '5th', 'Male', 'SB')`);
            }
            const mid1 = sql("SELECT id FROM members WHERE university_id='21100001' LIMIT 1");
            const mid2 = sql("SELECT id FROM members WHERE university_id='21100002' LIMIT 1");
            sql(`INSERT INTO attendance_sessions (title, type, session_date, start_time, end_time, audience, qr_enabled, checkin_secret)
                VALUES ('UIQA Rehearsal', 'rehearsal', CURDATE(), '16:00:00', '18:00:00', 'all', 1, 'uiqasecret')`);
            const sid = sql("SELECT id FROM attendance_sessions WHERE title='UIQA Rehearsal' LIMIT 1");
            if (sid && mid1) {
                sql(`INSERT INTO attendance_expected (session_id, member_id) VALUES (${sid}, ${mid1})` + (mid2 ? `, (${sid}, ${mid2})` : ''));
                sql(`INSERT INTO attendance_records (session_id, member_id, status, method, marked_at) VALUES (${sid}, ${mid1}, 'present', 'manual', NOW())`);
            }
        }

        // ------------------------------------------------------------ every page, every role, every width
        const seenTitles = {};
        const anon = await as(null);
        for (const p of PUBLIC) await checkPage(anon, 'public ' + p, BASE + p, [360, 390, 768, 1366], seenTitles);

        const pagesByRole = {};
        for (const role of Object.keys(ACC)) {
            const page = await as(role);
            const start = page.url();
            const queue = [start], done = new Set();
            // the admin menu lists every page this role may open; pages linked from those (attendance session,
            // member boards, …) are checked too
            while (queue.length) {
                const u = queue.shift().split('#')[0];
                if (!u.startsWith(BASE) || SKIP.test(u) || done.has(keyOf(u)) || /logout\.php|admin-login|index\.php|past_events|performances|checkin\.php/.test(u)) continue;
                done.add(keyOf(u));
                const widths = role === 'super' ? [360, 390, 768, 1366] : [360, 1366];
                const m = await checkPage(page, `${role} ${u.replace(BASE, '')}`, u, widths, role === 'super' ? seenTitles : {});
                for (const l of m.links) if (l.startsWith(BASE) && !done.has(keyOf(l))) queue.push(l);
            }
            pagesByRole[role] = [...done];
            await page.browserContext().close();
        }
        ok(`Super Admin pages checked (${pagesByRole.super.length})`, pagesByRole.super.length >= 20, pagesByRole.super.join(' '));

        // ------------------------------------------------------------ keyboard: Tab reaches visible things, focus ring visible
        const tabWalk = async (page, n) => {
            const bad = [];
            for (let i = 0; i < n; i++) {
                await page.keyboard.press('Tab');
                const f = await page.evaluate(() => {
                    const e = document.activeElement;
                    if (!e || e === document.body) return null;
                    const r = e.getBoundingClientRect(), cs = getComputedStyle(e);
                    return { what: e.tagName + (e.id ? '#' + e.id : '') + ' ' + (e.innerText || e.value || e.getAttribute('aria-label') || '').trim().slice(0, 20),
                        seen: r.width > 0 && r.height > 0 && cs.visibility !== 'hidden', ring: cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 2 };
                });
                if (f && (!f.seen || !f.ring)) bad.push(f.what + (f.seen ? ' (no focus ring)' : ' (invisible)'));
            }
            return bad;
        };
        for (const [who, p, w] of [[null, 'index.php', 1366], [null, 'index.php', 390], [null, 'admin-login.php', 390], ['super', 'media.php', 1366], ['hr', 'notices.php', 390], ['super', 'attendance.php', 390]]) {
            const pg = who ? await as(who) : anon;
            await view(pg, w);
            await pg.goto(BASE + p);
            const bad = await tabWalk(pg, 25);
            ok(`keyboard ${who || 'public'} ${p} @${w}: every Tab stop is visible and shows a focus ring`, bad.length === 0, bad.slice(0, 5).join(' | '));
            if (who) await pg.browserContext().close();
        }

        // ------------------------------------------------------------ 404 page
        let r = await anon.goto(BASE + '404.php');
        ok('404 page: status 404, site design, link back home', r.status() === 404 && await anon.$('a[href="/frontend/index.php"]') !== null && /Page Not Found/.test(await anon.title()));
        r = await anon.goto(BASE + 'recover.php');
        ok('recover.php without its token looks exactly like the 404 page', r.status() === 404 && /Page Not Found/.test(await anon.title()));

        // ------------------------------------------------------------ footer year
        await view(anon, 1366);
        await anon.goto(BASE + 'index.php');
        const foot = await anon.$eval('footer', f => f.innerText);
        ok('footer copyright year is the current year', foot.includes('© ' + new Date().getFullYear()), foot.match(/Copyright[^\n]*/)?.[0]);

        // ------------------------------------------------------------ public phone menu
        await view(anon, 390);
        await anon.goto(BASE + 'index.php');
        const st = () => anon.evaluate(() => ({ open: document.getElementById('mobileSidebar').classList.contains('open'), inert: document.getElementById('mobileSidebar').inert, exp: document.getElementById('sidebarToggle').getAttribute('aria-expanded'), inDrawer: !!document.activeElement.closest('#mobileSidebar'), focus: document.activeElement.id || document.activeElement.className }));
        let s = await st();
        ok('public menu: closed menu is out of the Tab order', !s.open && s.inert && s.exp === 'false', JSON.stringify(s));
        await anon.click('#sidebarToggle'); await sleep(400);
        s = await st();
        ok('public menu: button opens it and moves focus inside', s.open && !s.inert && s.exp === 'true' && s.inDrawer, JSON.stringify(s));
        for (let i = 0; i < 15; i++) await anon.keyboard.press('Tab');
        ok('public menu: Tab stays inside the open menu', (await st()).inDrawer, JSON.stringify(await st()));
        await anon.keyboard.press('Escape'); await sleep(300);
        s = await st();
        ok('public menu: Escape closes it and returns focus to the button', !s.open && s.focus === 'sidebarToggle', JSON.stringify(s));
        await anon.click('#sidebarToggle'); await sleep(400);
        await anon.click('#mobileSidebar a[href="#section_2"]'); await sleep(400);
        ok('public menu: tapping a link closes it', !(await st()).open);
        await anon.click('#sidebarToggle'); await sleep(400);
        await anon.click('#drawerClose'); await sleep(300);
        ok('public menu: the close button closes it', !(await st()).open);
        await anon.keyboard.press('Tab');
        await anon.focus('#sidebarToggle'); await anon.keyboard.press('Enter'); await sleep(400);
        ok('public menu: opens with the keyboard (Enter)', (await st()).open);

        // ------------------------------------------------------------ admin Menu bar (every admin page but the dashboard)
        const hr = await as('hr');
        await view(hr, 390);
        await hr.goto(BASE + 'notices.php');
        const am = () => hr.evaluate(() => { const d = document.getElementById('amDrawer'); return d && { open: d.classList.contains('open'), inert: d.inert, exp: document.querySelector('.am-btn').getAttribute('aria-expanded'), inDrawer: !!document.activeElement.closest('#amDrawer'), focus: document.activeElement.className, links: [...d.querySelectorAll('a')].map(a => a.textContent), current: (d.querySelector('[aria-current=page]') || {}).textContent }; });
        s = await am();
        ok('admin menu: present on admin pages, closed and out of the Tab order', s && !s.open && s.inert && s.exp === 'false', JSON.stringify(s));
        ok('admin menu: marks the page you are on', s && s.current === 'Email Notices', s && s.current);
        ok("admin menu: HR doesn't see Super-Admin-only links", s && !s.links.includes('Sponsor Banners') && !s.links.includes('Site Lockdown') && s.links.includes('Logout'), s && s.links.join(', '));
        await hr.click('.am-btn'); await sleep(400);
        s = await am();
        ok('admin menu: button opens it and moves focus inside', s.open && !s.inert && s.exp === 'true' && s.inDrawer, JSON.stringify(s));
        for (let i = 0; i < 30; i++) await hr.keyboard.press('Tab');
        ok('admin menu: Tab stays inside the open menu', (await am()).inDrawer);
        await hr.keyboard.press('Escape'); await sleep(300);
        s = await am();
        ok('admin menu: Escape closes it and returns focus to the button', !s.open && /am-btn/.test(s.focus), JSON.stringify(s));
        await hr.click('.am-btn'); await sleep(400);
        await Promise.all([hr.waitForNavigation(), hr.click('#amDrawer a[href$="profile.php"]')]);
        ok('admin menu: a link goes to its page', hr.url().endsWith('profile.php'));
        await view(hr, 1366);
        await hr.goto(BASE + 'admin_dashboard.php');
        ok('admin dashboard keeps its own sidebar (no second menu)', await hr.$('#amDrawer') === null && await hr.$('#sidebar') !== null);
        const side = await hr.$$eval('#sidebar a', a => a.map(x => x.textContent.trim().replace(/\s+\d+$/, '')));
        ok('dashboard sidebar and Menu bar list the same pages', JSON.stringify(side) === JSON.stringify(s.links), side.join(',') + ' / ' + s.links.join(','));
        const sup = await as('super');
        await sup.goto(BASE + 'notices.php');
        const supLinks = await sup.$$eval('#amDrawer a', a => a.map(x => x.textContent));
        ok('admin menu: Super Admin sees Sponsor Banners and Site Lockdown', supLinks.includes('Sponsor Banners') && supLinks.includes('Site Lockdown'));

        // dashboard sidebar on a phone
        await view(hr, 390);
        await hr.goto(BASE + 'admin_dashboard.php');
        const sb = () => hr.evaluate(() => ({ open: document.getElementById('sidebar').classList.contains('open'), inert: document.getElementById('sidebar').inert, exp: document.getElementById('sidebarToggle').getAttribute('aria-expanded'), inBar: !!document.activeElement.closest('#sidebar'), focus: document.activeElement.id }));
        s = await sb();
        ok('dashboard phone menu: closed sidebar is out of the Tab order', !s.open && s.inert && s.exp === 'false', JSON.stringify(s));
        await hr.click('#sidebarToggle'); await sleep(400);
        s = await sb();
        ok('dashboard phone menu: opens and moves focus inside', s.open && s.exp === 'true' && s.inBar, JSON.stringify(s));
        for (let i = 0; i < 30; i++) await hr.keyboard.press('Tab');
        ok('dashboard phone menu: Tab stays inside', (await sb()).inBar || (await sb()).focus === 'sidebarToggle');
        await hr.keyboard.press('Escape'); await sleep(300);
        s = await sb();
        ok('dashboard phone menu: Escape closes it, focus back on the button', !s.open && s.focus === 'sidebarToggle', JSON.stringify(s));
        await hr.click('#sidebarToggle'); await sleep(300);
        await hr.mouse.click(370, 600); await sleep(300);
        ok('dashboard phone menu: a tap outside closes it', !(await sb()).open);
        const heading = await hr.evaluate(() => { const b = document.getElementById('sidebarToggle').getBoundingClientRect(), h = document.querySelector('.dashboard-header h1').getBoundingClientRect(); return h.top >= b.bottom - 1; });
        ok('dashboard phone menu: the button does not cover the heading', heading);

        // ------------------------------------------------------------ busy buttons (no double submit)
        await view(sup, 1366);
        await sup.goto(BASE + 'home_stats.php');
        let posts = 0;
        const countPosts = req => { if (req.method() === 'POST') posts++; };
        sup.on('request', countPosts);
        const btn = 'form[method=post] button[type=submit], form[method=POST] button[type=submit]';
        await sup.$eval('form[method=post] input[type=number], form[method=POST] input[type=number]', i => { i.value = (+i.value || 0) + 1; });
        // press Save twice in a row; 5 ms after the first press note the button's state in sessionStorage
        // (kept across the page reload that follows)
        await Promise.all([sup.waitForNavigation({ timeout: 15000 }), sup.$eval(btn, b => {
            setTimeout(() => sessionStorage.setItem('busyProbe', JSON.stringify({ disabled: b.disabled, spin: !!b.querySelector('.ui-spin'), aria: b.getAttribute('aria-busy') })), 5);
            b.click(); b.click();
        })]);
        await sleep(300);
        const busy = JSON.parse(await sup.evaluate(() => sessionStorage.getItem('busyProbe')) || '{}');
        sup.off('request', countPosts);
        ok('busy button: Save shows a spinner and is disabled while sending', busy.disabled && busy.spin && busy.aria === 'true', JSON.stringify(busy));
        ok('busy button: pressing twice sends the form once', posts === 1, posts + ' POSTs');
        ok('busy button: the save still works', /saved|updated/i.test(await sup.$eval('body', b => b.innerText)), (await sup.$eval('.alert', a => a.innerText).catch(() => 'no message')));

        // ------------------------------------------------------------ crop window keeps focus
        const tid = sql('SELECT id FROM tenures WHERE is_current=1');
        await sup.goto(BASE + `media.php?tab=tenures&t=${tid}`);
        await (await sup.$('form:has(input[name=grp][value=panel]) input[name=photo]')).uploadFile(path.join(T.CROPIMG, 'wide.jpg'));
        await sup.waitForSelector('.crop-modal:not([hidden]) .cropper-container', { timeout: 15000 });
        await sleep(400);
        const inModal = () => sup.evaluate(() => !!document.activeElement.closest('.crop-modal'));
        ok('crop window: focus moves into it', await inModal());
        let stays = true;
        for (let i = 0; i < 12; i++) { await sup.keyboard.press('Tab'); stays = stays && await inModal(); }
        for (let i = 0; i < 4; i++) { await sup.keyboard.down('Shift'); await sup.keyboard.press('Tab'); await sup.keyboard.up('Shift'); stays = stays && await inModal(); }
        ok('crop window: Tab and Shift+Tab stay inside it', stays);
        await sup.keyboard.press('Escape'); await sleep(300);
        ok('crop window: Escape closes it', await sup.$eval('.crop-modal', m => m.hidden));

        // ------------------------------------------------------------ empty lists say so
        sql("DELETE FROM pending_applications; DELETE FROM shortlisted_members; DELETE FROM venuinfo; DELETE FROM notices; DELETE FROM members; DELETE FROM attendance_sessions; DELETE FROM gallery_videos; DELETE FROM site_events; DELETE FROM ad_stats; DELETE FROM ad_clicks; DELETE FROM ads");
        for (const f of fs.existsSync(path.join(ROOT, 'backend', 'cache')) ? fs.readdirSync(path.join(ROOT, 'backend', 'cache')) : []) if (f.endsWith('.json')) try { fs.unlinkSync(path.join(ROOT, 'backend', 'cache', f)); } catch (e) { }
        const EMPTY = {
            'pending_applications.php': /No pending applications/,
            'shortlisted_members.php': /No shortlisted members found/,
            'approved_members.php': /No approved members found/,
            'set_venue.php': /No venue information available/,
            'notices.php': /No notices sent yet/,
            'gb_members.php': /No members are at the GB level yet/,
            'sb_members.php': /No members are at the SB level yet/,
            'attendance.php': /No sessions yet/,
            'attendance.php?tab=report': /No attendance yet/,
            'video_gallery.php': /No videos yet/,
            'media.php?tab=events': /No past events yet/,
            'activity_log.php?q=nothing-matches-this': /No activity matches these filters/,
            'sponsors.php': /No ads yet/,
            'performances.php': /No videos yet/,
            'past_events.php': /No events yet/,
            'index.php': /Event stories are on their way/,
        };
        for (const [p, re] of Object.entries(EMPTY)) {
            await sup.goto(BASE + p);
            const text = await sup.$eval('body', b => b.innerText);
            ok(`empty list message on ${p}`, re.test(text), text.replace(/\s+/g, ' ').slice(0, 200));
        }
    } catch (e) {
        ok('suite ran without crashing', false, e.stack);
    } finally {
        await browser.close();
        console.log(results.join('\n'));
        console.log(`\n${results.filter(r => r.startsWith('PASS')).length}/${results.length} passed`);
    }
})();

function walkImgs(d) {
    return fs.readdirSync(d, { withFileTypes: true }).flatMap(e => e.isDirectory() ? walkImgs(path.join(d, e.name)) : /\.(jpe?g|png|webp|gif)$/i.test(e.name) ? [path.join(d, e.name)] : []);
}
