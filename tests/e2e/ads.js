// Sponsor banners: invisible to every other admin (404, no traces), spots, crop, rotation, dates, views/clicks, stats, Excel, lockdown.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const PHP = T.PHP;
const php = code => execFileSync(PHP, ['-d', 'extension=gd', '-d', 'extension=zip', '-r', code]).toString();
const BASE = 'http://127.0.0.1:8099/frontend/';
const REPO = T.ROOT_FWD + '/frontend/';
const S_DIR = T.RUNTIME;
const IMG = path.join(S_DIR, 'adimg');
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 230) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], panel: ['panel@test.local', 'panelpass123'], hr: ['admin@test.local', 'adminpass123'], miap: ['miap@test.local', 'miappass123'], sec: ['sec@test.local', 'secpass1234'] };
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
const wait = ms => new Promise(r => setTimeout(r, ms));
const today = sql('SELECT CURDATE()');
const day = n => sql(`SELECT CURDATE() + INTERVAL ${n} DAY`);
let browser;
async function as(role, opts = {}) {
    const page = await (await browser.createBrowserContext()).newPage();
    page.errors = [];
    page.on('pageerror', e => page.errors.push(e.message));
    page.on('dialog', d => d.accept());
    await page.setUserAgent(opts.ua || UA);
    if (opts.phone) await page.emulate({ viewport: { width: 390, height: 844, isMobile: true, hasTouch: true }, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' });
    else await page.setViewport({ width: 1280, height: 900 });
    if (role) {
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', ACC[role][0]); await page.type('#adminPassword', ACC[role][1]);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}
function get(url, ua = UA, cookie = '') {
    return new Promise((res, rej) => http.get(url, { headers: { 'User-Agent': ua, Cookie: cookie } }, r => { let b = ''; r.on('data', d => b += d); r.on('end', () => res({ status: r.statusCode, location: r.headers.location, body: b })); }).on('error', rej));
}
const stats = id => sql(`SELECT CONCAT(COALESCE(SUM(views),0),'/',COALESCE(SUM(clicks),0)) FROM ad_stats WHERE ad_id=${id}`);
const alertOf = page => page.$eval('.alert', e => e.textContent.trim()).catch(() => '');
async function addAd(S, { spot = 'home_hero', advertiser, link, start = today, end = day(30), img, mobile, active = true }) {
    await S.goto(BASE + `sponsors.php?new=1&spot=${spot}`);
    await S.type('#advertiser', advertiser);
    await S.type('#link', link);
    await S.$eval('#starts_on', (e, v) => e.value = v, start);
    await S.$eval('#ends_on', (e, v) => e.value = v, end);
    if (!active) await S.click('input[name=active]');
    for (const [name, file] of [['image', img], ['image_mobile', mobile]]) {
        if (!file) continue;
        await (await S.$(`input[name=${name}]`)).uploadFile(path.join(IMG, file));
        const opened = await S.waitForSelector('.crop-modal:not([hidden]) .cropper-container', { timeout: 3000 }).catch(() => null);
        if (opened) { await wait(400); await S.click('[data-act="use"]'); }
        await S.waitForFunction(n => /^(Ready|Not|This)/.test(document.querySelector(`input[name=${n}]`).parentNode.querySelector('.check-msg').textContent), { timeout: 20000 }, name);
    }
    await Promise.all([S.waitForNavigation(), S.$eval('#adForm button[type=submit]', b => b.click())]);
    return +sql(`SELECT COALESCE(MAX(id),0) FROM ads WHERE advertiser=${JSON.stringify(advertiser).replace(/"/g, "'")}`);
}

(async () => {
    fs.mkdirSync(IMG, { recursive: true });
    php(`$d='${IMG.replace(/\\/g, '/')}'; foreach ([['wide.jpg',1600,400],['fit.jpg',1200,200],['phone.jpg',600,300],['huge.png',1200,200]] as [$f,$w,$h]) { $i=imagecreatetruecolor($w,$h); for($x=0;$x<$w;$x+=40) imagefilledrectangle($i,$x,0,$x+20,$h,imagecolorallocate($i,($x*3)%255,90,200)); if ($f==='huge.png') { for($k=0;$k<200000;$k++) imagesetpixel($i,random_int(0,$w-1),random_int(0,$h-1),imagecolorallocate($i,random_int(0,255),random_int(0,255),random_int(0,255))); imagepng($i,"$d/$f",0); } else imagejpeg($i,"$d/$f",90); }`);
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 90000 });
    const files = [];
    try {
        const S = await as('super');
        const others = {};
        for (const r of ['panel', 'hr', 'miap', 'sec']) others[r] = await as(r);

        // ---------------------------------------------------------------- invisible to everyone else
        const strip = (html, u) => html.split(u.split('?')[0]).join('URL');
        for (const [r, p] of Object.entries(others)) {
            for (const u of ['sponsors.php', 'sponsors.php?tab=stats', 'sponsors_export.php?id=1']) {
                const resp = await p.goto(BASE + u);
                const body = await p.content();
                // like the server's own 404: "The requested URL /frontend/… was not found"; nothing else about the page
                const rest = strip(body, '/frontend/' + u);
                ok(`${r}: ${u} answers a plain 404`, resp.status() === 404 && p.url().endsWith(u) && body.includes('Not Found') && !/sponsor|advert|banner|super/i.test(rest), resp.status() + ' ' + rest.slice(0, 120));
            }
        }
        const anon = await get(BASE + 'sponsors.php');
        ok('logged-out visitor: 404 too (no login redirect)', anon.status === 404 && !anon.location);
        const pages = ['admin_dashboard.php', 'activity_log.php', 'media.php', 'handover.php', 'admin_management.php', 'attendance.php', 'video_gallery.php', 'notices.php', 'profile.php', 'theme.php', 'lockdown.php'];
        let leaks = [];
        for (const [r, p] of Object.entries(others)) {
            for (const u of pages) {
                await p.goto(BASE + u);
                const html = await p.content();
                // traces of the feature itself (the handover notes' own "Sponsors, vendors…" prompt is unrelated text)
                if (/sponsors(_export)?\.php|Sponsor Banners|Sponsor ad|go\.php\?id|sp-band|sp-tag|ad_spot|ad_created|ad_report|images\/sponsors/i.test(html)) leaks.push(`${r}:${u}`);
            }
        }
        ok('no menu item, text, script or log entry about it on any page of the other admins', leaks.length === 0, leaks.join(', '));
        await S.goto(BASE + 'super_admin_dashboard.php');
        ok('Super Admin has the menu item', !!(await S.$('a[href="sponsors.php"]')));

        // ---------------------------------------------------------------- add, crop, spots
        const a1 = await addAd(S, { advertiser: 'SoundWave Ltd', link: 'https://soundwave.example/promo', img: 'wide.jpg' });
        ok('ad added through the crop tool (4:1 photo cropped to the spot)', a1 > 0 && (await alertOf(S)).includes('added'));
        const img1 = sql(`SELECT image FROM ads WHERE id=${a1}`);
        files.push(img1);
        const dims = php(`$i=getimagesize('${REPO}${img1}'); echo $i[0],'x',$i[1],' ',filesize('${REPO}${img1}');`);
        ok('stored banner fits 1200 × 200 (6:1) and is compressed', /^1200x200 \d+$/.test(dims) && +dims.split(' ')[1] < 300000, dims);
        let V = await as(null);
        await V.goto(BASE + 'index.php');
        ok('spot off: nothing on the page (no gap, no message)', !(await V.$('.sp-band')) && !(await V.content()).includes('Sponsored'));
        await S.goto(BASE + 'sponsors.php?tab=spots');
        await Promise.all([S.waitForNavigation(), S.$eval('input[name=spot][value=home_hero]', i => { const c = i.form.querySelector('input[name=enabled]'); c.checked = true; c.dispatchEvent(new Event('change')); })]);
        ok('spot switched on (logged)', sql("SELECT enabled FROM ad_spots WHERE spot='home_hero'") === '1' && sql("SELECT COUNT(*) FROM admin_activity_log WHERE action='ad_spot_on'") === '1');
        await V.goto(BASE + 'index.php');
        const band = await V.$eval('.sp-home_hero', e => ({ a: e.querySelector('a').getAttribute('href'), t: e.querySelector('a').target, rel: e.querySelector('a').rel, label: e.querySelector('.sp-tag').textContent, lazy: e.querySelector('img').loading, w: e.querySelector('img').getAttribute('width'), h: e.querySelector('img').getAttribute('height'), after: e.previousElementSibling.id })).catch(e => null);
        ok('banner below the hero: Sponsored label, new tab, rel sponsored noopener, lazy, sized', band && band.a === `go.php?id=${a1}` && band.t === '_blank' && band.rel === 'sponsored noopener' && band.label === 'Sponsored' && band.lazy === 'lazy' && band.w === '1200' && band.h === '200' && band.after === 'section_1', JSON.stringify(band));
        await S.goto(BASE + 'sponsors.php?tab=spots');
        r = await S.evaluate(async () => { const fd = new FormData(); fd.append('action', 'spot'); fd.append('spot', 'events'); fd.append('enabled', '1'); fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content); return (await fetch('sponsors.php', { method: 'POST', body: fd })).text(); });
        ok('the future events spot cannot be switched on yet', sql("SELECT enabled FROM ad_spots WHERE spot='events'") === '0');

        // ---------------------------------------------------------------- phone image
        await S.goto(BASE + `sponsors.php?edit=${a1}`);
        await (await S.$('input[name=image_mobile]')).uploadFile(path.join(IMG, 'phone.jpg'));
        await S.waitForFunction(() => /^Ready/.test(document.querySelector('input[name=image_mobile]').parentNode.querySelector('.check-msg').textContent), { timeout: 20000 });
        await Promise.all([S.waitForNavigation(), S.$eval('#adForm button[type=submit]', b => b.click())]);
        files.push(sql(`SELECT image_mobile FROM ads WHERE id=${a1}`));
        const Ph = await as(null, { phone: true });
        await Ph.goto(BASE + 'index.php');
        await Ph.$eval('.sp-band', e => e.scrollIntoView());
        await wait(800);
        const cur = await Ph.$eval('.sp-band img', i => i.currentSrc);
        ok('phones get the phone image', cur.includes('-phone-'), cur);
        await Ph.goto(BASE + 'index.php');
        ok('phone: no sideways scrolling', await Ph.evaluate(() => document.documentElement.scrollWidth <= innerWidth));

        // ---------------------------------------------------------------- validation
        for (const [link, why] of [['javascript:alert(1)', 'javascript'], ['ftp://x.example/', 'ftp'], ['not a link', 'text']]) {
            await S.goto(BASE + 'sponsors.php?new=1&spot=home_hero');
            await S.type('#advertiser', 'Bad'); await S.$eval('#link', (e, v) => { e.type = 'text'; e.value = v; }, link);
            await (await S.$('input[name=image]')).uploadFile(path.join(IMG, 'fit.jpg'));
            await S.waitForFunction(() => /^Ready/.test(document.querySelector('input[name=image]').parentNode.querySelector('.check-msg').textContent), { timeout: 20000 });
            await Promise.all([S.waitForNavigation(), S.$eval('#adForm button[type=submit]', b => b.click())]);
            ok(`link refused: ${why}`, (await alertOf(S)).includes('https://') && sql("SELECT COUNT(*) FROM ads WHERE advertiser='Bad'") === '0');
        }
        r = await S.evaluate(async () => {
            const fd = new FormData(); fd.append('action', 'save_ad'); fd.append('spot', 'home_hero'); fd.append('advertiser', 'Raw'); fd.append('link', 'https://x.example/');
            fd.append('starts_on', '2026-01-10'); fd.append('ends_on', '2026-01-01'); fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            return (await fetch('sponsors.php', { method: 'POST', body: fd })).text();
        });
        ok('end before start refused', r.includes('end date must be on or after'));
        const bigBuf = Buffer.concat([fs.readFileSync(path.join(IMG, 'huge.png')), Buffer.alloc(1100 * 1024, 7)]);
        const big = bigBuf.toString('base64');
        r = await S.evaluate(async b64 => {
            const bin = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
            const fd = new FormData(); fd.append('action', 'save_ad'); fd.append('spot', 'home_hero'); fd.append('advertiser', 'Raw'); fd.append('link', 'https://x.example/');
            fd.append('starts_on', '2026-01-01'); fd.append('ends_on', '2026-12-31'); fd.append('image', new Blob([bin], { type: 'image/png' }), 'huge.png');
            fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            return (await fetch('sponsors.php', { method: 'POST', body: fd })).text();
        }, big);
        ok('server refuses an image over 1 MB (crop tool bypassed)', r.includes('at most 1 MB') && sql("SELECT COUNT(*) FROM ads WHERE advertiser='Raw'") === '0', bigBuf.length);

        // ---------------------------------------------------------------- dates, pause, rotation
        const a2 = await addAd(S, { advertiser: 'Cafe Bracu', link: 'https://cafe.example/', img: 'fit.jpg' });
        const a3 = await addAd(S, { advertiser: 'Tomorrow Co', link: 'https://tomorrow.example/', img: 'fit.jpg', start: day(1), end: day(10) });
        const a4 = await addAd(S, { advertiser: 'Old Co', link: 'https://old.example/', img: 'fit.jpg', start: day(-10), end: day(-1) });
        const a5 = await addAd(S, { advertiser: 'Paused Co', link: 'https://paused.example/', img: 'fit.jpg', active: false });
        for (const id of [a2, a3, a4, a5]) files.push(sql(`SELECT image FROM ads WHERE id=${id}`));
        const seen = new Set();
        for (let i = 0; i < 24; i++) { const r = await get(BASE + 'index.php', 'Googlebot/2.1'); const m = r.body.match(/go\.php\?id=(\d+)/); if (m) seen.add(+m[1]); }
        ok('two live ads in one spot rotate on page loads', seen.has(a1) && seen.has(a2), [...seen].join(','));
        ok('not-yet-started, ended and paused ads never show', !seen.has(a3) && !seen.has(a4) && !seen.has(a5));
        await S.goto(BASE + 'sponsors.php');
        const chips = await S.$$eval('.ad-row', rs => rs.map(r => r.querySelector('.chip').textContent));
        ok('status shown: live / starts / ended / paused', chips.some(c => c.startsWith('Live until')) && chips.some(c => c.startsWith('Starts')) && chips.some(c => c.startsWith('Ended')) && chips.includes('Paused'), chips.join(' | '));
        await Promise.all([S.waitForNavigation(), S.$eval(`#ad${a2} input[name=action][value=toggle_ad]`, i => i.form.querySelector('button').click())]);
        ok('pause logged', sql("SELECT COUNT(*) FROM admin_activity_log WHERE action='ad_paused'") === '1');

        // ---------------------------------------------------------------- views and clicks
        sql('DELETE FROM ad_stats; DELETE FROM ad_clicks');
        for (let i = 0; i < 3; i++) await get(BASE + 'index.php', UA);
        ok('a view per page load by a visitor', stats(a1) === '3/0', stats(a1));
        for (const bot of ['Googlebot/2.1 (+http://www.google.com/bot.html)', 'facebookexternalhit/1.1', 'WhatsApp/2.23', 'curl/8.4.0', 'Mozilla/5.0 HeadlessChrome/120', '']) await get(BASE + 'index.php', bot);
        ok('bots and link previews are not counted', stats(a1) === '3/0', stats(a1));
        await S.goto(BASE + 'index.php');
        ok('the Super Admin previewing the site is not counted', stats(a1) === '3/0', stats(a1));
        let c = await get(BASE + `go.php?id=${a1}`, UA);
        ok('click goes through the site and on to the advertiser', c.status === 302 && c.location === 'https://soundwave.example/promo' && stats(a1) === '3/1', `${c.status} ${c.location} ${stats(a1)}`);
        await get(BASE + `go.php?id=${a1}`, UA);
        ok('the same visitor clicking again within 10 minutes counts once', stats(a1) === '3/1');
        await get(BASE + `go.php?id=${a1}`, 'Googlebot/2.1');
        ok('bot clicks are not counted', stats(a1) === '3/1');
        c = await get(BASE + 'go.php?id=999999', UA);
        ok('unknown ad: back to the homepage (no open redirect)', c.status === 302 && c.location === 'index.php');
        c = await get(BASE + `go.php?id=${a1}&url=https://evil.example`, UA);
        ok('a target in the link is ignored', c.location === 'https://soundwave.example/promo');

        // ---------------------------------------------------------------- stats + report
        await S.goto(BASE + `sponsors.php?tab=stats&from=${today}&to=${today}`);
        const row = await S.$$eval('.tbl tbody tr', rs => rs.map(r => [...r.children].map(td => td.textContent.replace(/\s+/g, ' ').trim())));
        const sw = row.find(c => c[0].startsWith('SoundWave Ltd')) || [];
        ok('stats: views, clicks and click rate per ad', sw[2] === '3' && sw[3] === '1' && sw[4] === '33.33%', JSON.stringify(sw));
        await S.goto(BASE + `sponsors.php?tab=stats&from=${day(-40)}&to=${day(-20)}`);
        ok('date range filter', (await S.$$eval('.tbl tbody tr', rs => rs.map(r => r.textContent))).every(t => !t.includes('33.33%')));
        sql(`UPDATE ads SET advertiser='=HYPERLINK("http://evil","x")' WHERE id=${a1}`);
        const b64 = await S.evaluate(async q => { const r = await fetch('sponsors_export.php?' + q); const b = new Uint8Array(await r.arrayBuffer()); let s = ''; for (const x of b) s += String.fromCharCode(x); return btoa(s); }, `id=${a1}&from=${today}&to=${today}`);
        const xf = path.join(S_DIR, 'sponsor_report.xlsx');
        fs.writeFileSync(xf, Buffer.from(b64, 'base64'));
        const xl = php(`require '${T.ROOT_FWD}/backend/vendor/autoload.php'; $s=\\PhpOffice\\PhpSpreadsheet\\IOFactory::load('${xf.replace(/\\/g, '/')}')->getActiveSheet(); echo $s->getCell('A1')->getDataType(),'|',$s->getCell('A1')->getValue(),'|',$s->getCell('B6')->getValue(),'|',$s->getCell('B7')->getValue(),'|',$s->getCell('B8')->getValue(),'|',$s->getCell('B11')->getDataType();`);
        ok('per-ad Excel report: formula-safe text, numbers', xl.startsWith('s|Sponsor report: =HYPERLINK') && xl.includes('|3|1|33.33|n'), xl);
        ok('report export logged', sql("SELECT COUNT(*) FROM admin_activity_log WHERE action='ad_report_exported'") === '1');

        // ---------------------------------------------------------------- log visible only to the Super Admin
        await S.goto(BASE + 'activity_log.php');
        ok('Super Admin sees the ad actions in the log', (await S.content()).includes('Sponsor ad added'));
        await others.panel.goto(BASE + 'activity_log.php');
        ok('Panel Admin\'s log shows none of them', !/sponsor/i.test(await others.panel.content()));
        await others.panel.goto(BASE + 'activity_log.php?action=ad_created');
        ok('…not even when asked for directly (the unknown filter is ignored)', !/SoundWave|Sponsor ad|ad_created/i.test(await others.panel.content()));

        // ---------------------------------------------------------------- lockdown
        sql("INSERT INTO site_lockdown (starts_at, notice, status) VALUES (NOW() - INTERVAL 1 MINUTE, 'Test lockdown', 'scheduled')");
        const lock = await get(BASE + 'index.php', UA);
        ok('no ads on the maintenance page during a lockdown', lock.status === 503 && !lock.body.includes('sp-band') && !lock.body.includes('Sponsored'));
        sql('DELETE FROM site_lockdown');

        // ---------------------------------------------------------------- delete
        const before = files.filter(f => f && fs.existsSync(REPO + f)).length;
        await S.goto(BASE + 'sponsors.php');
        await Promise.all([S.waitForNavigation(), S.$eval(`#ad${a1} input[name=action][value=delete_ad]`, i => i.form.querySelector('button').click())]);
        ok('delete removes the ad, its images and stats', sql(`SELECT COUNT(*) FROM ads WHERE id=${a1}`) === '0' && sql(`SELECT COUNT(*) FROM ad_stats WHERE ad_id=${a1}`) === '0' && !fs.existsSync(REPO + files[0]) && !fs.existsSync(REPO + files[1]));
        ok('delete logged with its totals', sql("SELECT details FROM admin_activity_log WHERE action='ad_deleted'").includes('3 view(s), 1 click(s)'));

        for (const [k, p] of Object.entries({ S, V, Ph, ...others })) if (p.errors.length) ok(`no JS errors (${k})`, false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        try { for (const f of sql("SELECT CONCAT_WS(',', image, image_mobile) FROM ads").split('\n').join(',').split(',')) if (f) try { fs.unlinkSync(REPO + f); } catch (e) { } } catch (e) { }
        for (const f of files) if (f) try { fs.unlinkSync(REPO + f); } catch (e) { }
        try { sql('DELETE FROM ads; DELETE FROM ad_clicks; UPDATE ad_spots SET enabled=0; DELETE FROM site_lockdown'); } catch (e) { }
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
let r;
