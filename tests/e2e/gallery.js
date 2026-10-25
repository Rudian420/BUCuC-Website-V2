// Performance gallery: admin page, YouTube checks (fake oEmbed on :8098), public page, permissions, log.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 220) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], panel: ['panel@test.local', 'panelpass123'], miap: ['miap@test.local', 'miappass123'], sec: ['sec@test.local', 'secpass1234'] };
const wait = ms => new Promise(r => setTimeout(r, ms));
let browser;

async function as(role, viewport) {
    const page = await (await browser.createBrowserContext()).newPage();
    page.errors = [];
    page.on('pageerror', e => page.errors.push(e.message));
    page.on('dialog', d => d.accept());
    await page.setViewport(viewport || { width: 1280, height: 900 });
    if (role) {
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', ACC[role][0]); await page.type('#adminPassword', ACC[role][1]);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}
const post = (page, fields) => page.evaluate(async fields => {
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    for (const [k, v] of Object.entries(fields)) fd.append(k, v);
    const r = await fetch('video_gallery.php', { method: 'POST', body: fd });
    return { url: r.url, text: await r.text() };
}, fields);
const alertOf = async page => page.$eval('.alert', e => e.textContent.trim()).catch(() => '');
async function lookup(page, link) {
    await page.$eval('#link', e => e.value = '');
    await page.type('#link', link);
    await page.click('#checkBtn');
    await page.waitForFunction(() => !/Checking/.test(document.getElementById('linkMsg').textContent) && document.getElementById('linkMsg').textContent !== '', { timeout: 15000 });
    return page.$eval('#linkMsg', e => ({ tone: e.className, text: e.textContent }));
}
async function save(page) {
    await Promise.all([page.waitForNavigation(), page.click('#videoForm button[type=submit]:not([name])')]);
}
const count = () => +sql('SELECT COUNT(*) FROM gallery_videos');

(async () => {
    try { sql('DELETE FROM gallery_videos'); } catch (e) { }
    sql("DELETE FROM admin_activity_log; DELETE FROM admin_permissions; DELETE FROM site_lockdown");
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 60000 });
    try {
        const S = await as('super');
        const visitor = await as(null);

        // ---------- empty states
        await S.goto(BASE + 'video_gallery.php');
        ok('admin: empty list message', (await S.content()).includes('No videos yet. Paste a YouTube link'));
        await visitor.goto(BASE + 'performances.php');
        ok('public: empty state when there are no videos', (await visitor.$eval('.pg-empty', e => e.textContent)).includes('No videos yet'));

        // ---------- rejected links
        let m = await lookup(S, 'https://vimeo.com/123456');
        ok('non-YouTube link refused', m.tone.includes('bad') && m.text.includes("isn't a YouTube video link"), m.text);
        m = await lookup(S, 'https://www.youtube.com/watch?v=noEmbed0001');
        ok('embedding disabled / private: clear error', m.tone.includes('bad') && m.text.includes('turned off embedding'), m.text);
        m = await lookup(S, 'https://youtu.be/deleted0001');
        ok('deleted video: clear error', m.tone.includes('bad') && m.text.includes("doesn't exist"), m.text);
        let r = await post(S, { action: 'save', link: 'https://youtu.be/noEmbed0001', title: 'x', category: 'other' });
        ok('…and the server never saves it', count() === 0 && r.text.includes('turned off embedding'));
        r = await post(S, { action: 'save', link: 'https://evil.com/watch?v=goodVideo01', title: 'x', category: 'other' });
        ok('server refuses a lookalike host', count() === 0 && r.text.includes('a YouTube video link'));

        // ---------- add a normal video (timestamp + playlist parameters)
        await S.goto(BASE + 'video_gallery.php');
        m = await lookup(S, 'https://www.youtube.com/watch?v=goodVideo01&list=PL123&index=2&t=1m5s');
        ok('valid link: checked and confirmed', m.tone.includes('good') && m.text.includes('Starts at 1:05'), m.text);
        ok('title filled in from YouTube', (await S.$eval('#title', e => e.value)) === 'Test video goodVideo01 <b>&"quote"');
        ok('preview card shown before saving', !!(await S.$('#preview .gv-card .gv-play img')) && (await S.$eval('#preview .gv-title', e => e.textContent)).includes('<b>'));
        await S.select('#category', 'music_video');
        ok('preview follows the category', (await S.$eval('#preview .gv-cat', e => e.textContent)) === 'Music Video');
        await save(S);
        ok('video added', (await alertOf(S)).includes('was added to the gallery') && count() === 1, await alertOf(S));
        const v1 = sql("SELECT CONCAT_WS('|', youtube_id, start_seconds, category, IFNULL(tenure_id,'-'), is_short, featured, hidden) FROM gallery_videos");
        ok('stored: ID, start time, category, current tenure; no file', v1 === `goodVideo01|65|music_video|${sql('SELECT id FROM tenures WHERE is_current=1')}|0|0|0`, v1);

        // ---------- shorts: /shorts/ link, and a tall video given as a watch link
        m = await lookup(S, 'https://www.youtube.com/shorts/tallShort01?feature=share');
        ok('Shorts link: marked vertical', await S.$eval('#is_short', e => e.checked) && (await S.$eval('#preview .gv-card', e => e.classList.contains('short'))), m.text);
        await S.select('#category', 'flash_mob');
        await S.select('#tenure_id', '');
        await S.$eval('#video_date', e => e.value = '2025-03-10');
        await S.$eval('#description', e => e.value = 'Flash mob at the campus atrium.');
        await save(S);
        m = await lookup(S, 'youtu.be/tallVideo02');
        ok('tall video from a normal link is detected as vertical too', await S.$eval('#is_short', e => e.checked), m.text);
        await S.select('#category', 'show_highlight');
        await save(S);
        ok('three videos stored', count() === 3);

        // ---------- duplicate warning
        await S.goto(BASE + 'video_gallery.php');
        m = await lookup(S, 'https://youtu.be/goodVideo01');
        ok('duplicate: warned while checking', m.tone.includes('warn') && m.text.includes('Already in the gallery'), m.text);
        await save(S);
        ok('duplicate: saving asks to confirm, nothing saved yet', !!(await S.$('#dupWarn')) && count() === 3);
        await Promise.all([S.waitForNavigation(), S.click('#dupWarn button[name=confirm_dup]')]);
        ok('duplicate: "Add it again anyway" saves it', count() === 4, await alertOf(S));
        const dupId = sql("SELECT MAX(id) FROM gallery_videos WHERE youtube_id='goodVideo01'");
        await Promise.all([S.waitForNavigation(), S.$eval(`#v${dupId} form input[name=action][value=delete]`, i => i.form.querySelector('button').click())]);
        ok('delete removes it (confirm dialog)', count() === 3 && (await alertOf(S)).includes('was deleted'), await alertOf(S));

        // ---------- edit: title, featured
        const firstId = sql("SELECT id FROM gallery_videos WHERE youtube_id='goodVideo01'");
        await S.goto(BASE + `video_gallery.php?edit=${firstId}`);
        ok('edit form prefilled with the link, start time and preview', (await S.$eval('#link', e => e.value)).includes('goodVideo01&t=65s') && !!(await S.$('#preview .gv-card')));
        await S.$eval('#title', e => e.value = 'Rhythm Revive 25.1 Highlights');
        await S.click('#featured');
        await save(S);
        ok('edit saved: new title, featured', sql(`SELECT CONCAT(title,'|',featured) FROM gallery_videos WHERE id=${firstId}`) === 'Rhythm Revive 25.1 Highlights|1');

        // ---------- hide / show
        const shortId = sql("SELECT id FROM gallery_videos WHERE youtube_id='tallShort01'");
        await Promise.all([S.waitForNavigation(), S.$eval(`#v${shortId} form input[name=action][value=toggle]`, i => i.form.querySelector('button').click())]);
        await visitor.goto(BASE + 'performances.php');
        ok('hidden video is not on the public page', !(await visitor.content()).includes('tallShort01'));
        await Promise.all([S.waitForNavigation(), S.$eval(`#v${shortId} form input[name=action][value=toggle]`, i => i.form.querySelector('button').click())]);

        // ---------- reorder: position number, then drag order endpoint
        const tallId = sql("SELECT id FROM gallery_videos WHERE youtube_id='tallVideo02'");
        await S.goto(BASE + 'video_gallery.php');
        await Promise.all([S.waitForNavigation(), S.$eval(`#v${tallId} .pos`, e => { e.value = 1; e.dispatchEvent(new Event('change')); })]);
        let order = await S.$$eval('#vlist .vrow', rows => rows.map(r => r.dataset.id));
        ok('position number moves a video', order.indexOf(String(tallId)) <= 1, order.join(','));
        r = await S.evaluate(async ids => {
            const fd = new FormData(); fd.append('action', 'reorder'); fd.append('ids', JSON.stringify(ids)); fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            return (await fetch('video_gallery.php', { method: 'POST', body: fd })).json();
        }, [firstId, shortId, tallId].map(Number));
        await S.reload();
        order = await S.$$eval('#vlist .vrow', rows => rows.map(r => r.dataset.id));
        ok('drag order is saved', r.success && order.join(',') === [firstId, shortId, tallId].join(','), order.join(','));
        // real drag in the page
        await S.setDragInterception(true);
        const rowsEl = await S.$$('#vlist .vrow');
        const lastId = await rowsEl[2].evaluate(e => e.dataset.id);
        await rowsEl[2].dragAndDrop(rowsEl[0], { delay: 100 });
        await S.waitForFunction(() => /Order saved|Could not/.test(document.getElementById('orderStatus').textContent), { timeout: 8000 }).catch(() => {});
        ok('dragging a row moves it up', (await S.$$eval('#vlist .vrow', rs => rs.map(r => r.dataset.id))).indexOf(lastId) < 2);
        ok('dragging a row in the page saves the order', (await S.$eval('#orderStatus', e => e.textContent)).includes('Order saved') && (await S.$$eval('#vlist .vrow', rs => rs.map(r => r.dataset.id))).join(',') === sql('SELECT GROUP_CONCAT(id ORDER BY sort_order) FROM gallery_videos'), await S.$eval('#orderStatus', e => e.textContent));

        // ---------- public page
        sql(`UPDATE gallery_videos SET description='Highlights <script>window.__xss=1</script>' WHERE id=${tallId}`);
        const requests = [];
        visitor.on('request', q => requests.push(q.url()));
        await visitor.goto(BASE + 'performances.php', { waitUntil: 'networkidle2' });
        const cards = await visitor.$$eval('.gv-card', cs => cs.map(c => ({ t: c.querySelector('.gv-title').textContent, f: c.classList.contains('featured'), s: c.classList.contains('short'), cat: c.querySelector('.gv-cat').textContent })));
        ok('public: grid of thumbnails with title and category', cards.length === 3 && cards.every(c => c.t && c.cat), JSON.stringify(cards));
        ok('public: featured video first', cards[0].f && cards[0].t === 'Rhythm Revive 25.1 Highlights');
        ok('public: no YouTube player loaded before a click', !requests.some(u => /youtube(-nocookie)?\.com\//.test(u)) && !(await visitor.$('iframe')), requests.filter(u => /youtube/.test(u)).join(' '));
        ok('public: text is escaped (no HTML from titles or descriptions)', !(await visitor.$('.gv-title b')) && !(await visitor.evaluate(() => window.__xss)));
        const sh = await visitor.$eval('.gv-card.short .gv-frame', e => { const r = e.getBoundingClientRect(); return r.height / r.width; });
        ok('public: Shorts shown in a vertical frame', Math.abs(sh - 16 / 9) < 0.02, sh.toFixed(3));
        // filters
        await visitor.click('.chip[data-cat="flash_mob"]');
        let vis = await visitor.$$eval('.gv-card', cs => cs.filter(c => !c.hidden).map(c => c.dataset.cat));
        ok('public: category filter', vis.length === 1 && vis[0] === 'flash_mob', vis.join(','));
        await visitor.click('.chip[data-cat=""]');
        const opts = await visitor.$$eval('#when option', o => o.map(x => x.value));
        await visitor.select('#when', '2025');
        vis = await visitor.$$eval('.gv-card', cs => cs.filter(c => !c.hidden).map(c => c.dataset.when));
        ok('public: year / tenure filter', opts.includes('2025') && opts.some(o => o.startsWith('Tenure ')) && vis.length === 1 && vis[0] === '2025', opts.join(',') + ' → ' + vis.join(','));
        await visitor.click('.chip[data-cat="music_video"]');
        ok('public: "no match" message for an empty combination', await visitor.$eval('#noMatch', e => !e.hidden));
        await visitor.select('#when', ''); await visitor.click('.chip[data-cat=""]');
        // play
        await visitor.click('.gv-card.featured .gv-play');
        await wait(300);
        const src = await visitor.$eval('.gv-card.featured iframe', f => f.src);
        ok('public: clicking plays via youtube-nocookie.com, at the saved start time', src.startsWith('https://www.youtube-nocookie.com/embed/goodVideo01?') && src.includes('autoplay=1') && src.includes('start=65'), src);

        // phone
        const phone = await as(null, { width: 390, height: 844, isMobile: true, hasTouch: true });
        await phone.goto(BASE + 'performances.php', { waitUntil: 'networkidle2' });
        const lay = await phone.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: innerWidth, short: (() => { const r = document.querySelector('.gv-card.short .gv-frame').getBoundingClientRect(); return [r.width, r.height]; })() }));
        ok('phone: no sideways scrolling, Short stays vertical', lay.sw <= lay.vw && lay.short[1] > lay.short[0], JSON.stringify(lay));
        await phone.screenshot({ path: require('path').join(T.RUNTIME, 'shot_gallery_mobile.png'), fullPage: true });
        await visitor.setViewport({ width: 1280, height: 900 });
        await visitor.goto(BASE + 'performances.php', { waitUntil: 'networkidle2' });
        await visitor.screenshot({ path: require('path').join(T.RUNTIME, 'shot_gallery.png'), fullPage: true });
        await S.goto(BASE + `video_gallery.php?edit=${tallId}`);
        await wait(300);
        await S.screenshot({ path: require('path').join(T.RUNTIME, 'shot_gallery_admin.png'), fullPage: true });

        // homepage link
        await visitor.goto(BASE + 'index.php');
        ok('homepage links to the gallery (nav + footer)', (await visitor.$$('a[href="performances.php"]')).length >= 3);

        // ---------- permissions
        const MI = await as('miap'), SE = await as('sec'), PA = await as('panel');
        await MI.goto(BASE + 'admin_dashboard.php');
        ok('MIAP sees the menu item and can open the page', !!(await MI.$('a[href="video_gallery.php"]')) && (await MI.goto(BASE + 'video_gallery.php'), MI.url().endsWith('video_gallery.php')));
        await PA.goto(BASE + 'video_gallery.php');
        ok('Panel Admin can open the page', PA.url().endsWith('video_gallery.php'));
        await SE.goto(BASE + 'admin_dashboard.php');
        const secMenu = !!(await SE.$('a[href="video_gallery.php"]'));
        await SE.goto(BASE + 'video_gallery.php');
        ok('Secretary Admin (not granted) has no menu item and is refused', !secMenu && SE.url().includes('permission'), SE.url());
        r = await SE.evaluate(async () => {
            const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', '1'); fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const x = await fetch('video_gallery.php', { method: 'POST', body: fd }); return x.url;
        });
        ok('…and a forged delete is refused', count() === 3 && r.includes('permission'), r);
        const secId = sql("SELECT id FROM adminpanel WHERE email='sec@test.local'");
        await PA.goto(BASE + `admin_management.php?perms_id=${secId}`);
        ok('permission listed in the tick cards', (await PA.content()).includes('Manage the performance gallery'));
        sql(`INSERT INTO admin_permissions (admin_id, permission, allowed) VALUES (${secId}, 'manage_gallery', 1)`);
        await SE.goto(BASE + 'video_gallery.php');
        ok('granting the permission lets the Secretary Admin in', SE.url().endsWith('video_gallery.php'));

        // ---------- tenure deleted → video stays, tenure cleared
        sql("INSERT INTO tenures (label, start_year, is_current) VALUES ('2099-00', 2099, 0)");
        const tId = sql("SELECT id FROM tenures WHERE label='2099-00'");
        sql(`UPDATE gallery_videos SET tenure_id=${tId} WHERE id=${tallId}`);
        sql(`DELETE FROM tenures WHERE id=${tId}`);
        ok('deleting a tenure keeps its videos (tenure cleared)', sql(`SELECT IFNULL(tenure_id,'none') FROM gallery_videos WHERE id=${tallId}`) === 'none');

        // ---------- lockdown closes the public page
        sql("INSERT INTO site_lockdown (starts_at, notice, status) VALUES (NOW() - INTERVAL 1 MINUTE, 'Test lockdown', 'scheduled')");
        const resp = await visitor.goto(BASE + 'performances.php');
        ok('lockdown: public gallery closed for visitors', resp.status() === 503);
        sql('DELETE FROM site_lockdown');

        // ---------- activity log
        const acts = sql("SELECT GROUP_CONCAT(DISTINCT action ORDER BY action) FROM admin_activity_log WHERE action LIKE 'gallery_%'");
        ok('activity log: added, edited, hidden, shown, deleted', acts === 'gallery_video_added,gallery_video_deleted,gallery_video_hidden,gallery_video_shown,gallery_video_updated', acts);
        ok('edit log says what changed', sql("SELECT details FROM admin_activity_log WHERE action='gallery_video_updated' LIMIT 1").includes('now featured'));
        await S.goto(BASE + 'activity_log.php?action=gallery_video_added');
        ok('log page shows the readable label', (await S.content()).includes('Gallery video added'));

        for (const [k, p] of Object.entries({ S, visitor, MI, SE, PA, phone })) if (p.errors.length) ok(`no JS errors (${k})`, false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        try { sql('DELETE FROM gallery_videos; DELETE FROM admin_permissions; DELETE FROM site_lockdown'); } catch (e) { }
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
