// Site colour palette: permissions, live preview, warnings, save / history / restore / reset, logging.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
// No saved palette = no ':root{--c-…}' block (the Bootstrap mapping is always injected)
const noPalette = async pg => !(await pg.$eval('#site-palette', e => e.textContent.includes(':root{')).catch(() => false));
const path = require('path');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 170) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], panel: ['panel@test.local', 'panelpass123'], hr: ['admin@test.local', 'adminpass123'], miap: ['miap@test.local', 'miappass123'], sec: ['sec@test.local', 'secpass1234'] };
const OUT = path.join(T.RUNTIME, 'shots9');
require('fs').mkdirSync(OUT, { recursive: true });

let browser;
async function as(role) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    page.errors = [];
    page.on('pageerror', e => page.errors.push(e.message));
    page.on('dialog', d => d.accept());
    await page.setViewport({ width: 1366, height: 900 });
    if (role) {
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', ACC[role][0]);
        await page.type('#adminPassword', ACC[role][1]);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}
const visitorHome = async () => { const p = await as(null); await p.goto(BASE + 'index.php', { waitUntil: 'networkidle2' }); return p; };
const primaryOf = p => p.evaluate(() => getComputedStyle(document.querySelector('.au-btn')).backgroundImage);
const setColor = (p, sel, hex) => p.$eval(sel, (el, hex) => { el.value = hex; el.dispatchEvent(new Event('input', { bubbles: true })); }, hex);
const save = async p => { await Promise.all([p.waitForNavigation(), p.click('#saveForm button[type=submit]')]); };

(async () => {
    sql("DELETE FROM admin_activity_log; DELETE FROM site_settings WHERE name LIKE 'palette%'");
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, args: ['--no-first-run'] });
    try {
        const P = {};
        for (const r of Object.keys(ACC)) P[r] = await as(r);
        for (const r of ['miap', 'sec']) {
            await P[r].goto(BASE + 'theme.php');
            ok(`${r} cannot open Site Colours`, P[r].url().includes('permission'));
        }
        for (const r of ['super', 'panel', 'hr']) {
            await P[r].goto(BASE + 'admin_dashboard.php');
            const links = await P[r].$$eval('.sidebar-nav a', a => a.map(x => x.textContent.trim()));
            ok(`${r} has the Site Colours link`, links.includes('Site Colours'));
        }

        let v = await visitorHome();
        const origBtn = await primaryOf(v);
        ok('with no palette saved, pages get no palette at all', (await noPalette(v)));

        // ---- live preview, nothing saved yet
        const t = P.hr;
        await t.goto(BASE + 'theme.php', { waitUntil: 'networkidle2' });
        ok('Site Colours page never gets the palette', !(await t.$('#site-palette')));
        await setColor(t, '#mainColor', '#2de2e6');
        await new Promise(r => setTimeout(r, 600));
        const roles = await t.evaluate(() => roles);
        ok('picking a main colour generates a full palette', roles.primary === '#2de2e6' && roles.bg !== '#06040f' && roles.accent !== '#2de2e6' && Object.keys(roles).length === 13, JSON.stringify(roles));
        const pv = await t.evaluate(() => document.getElementById('pvPublic').contentDocument.documentElement.style.getPropertyValue('--c-primary'));
        ok('live preview of the public site uses the new colours', pv.trim() === '45 226 230', pv);
        const pva = await t.evaluate(() => document.getElementById('pvAdmin').contentDocument.documentElement.style.getPropertyValue('--c-primary'));
        ok('live preview of the admin panel uses the new colours', pva.trim() === '45 226 230', pva);
        // ---- preview rows: Desktop / Mobile per row, real scrollable pages, instant updates
        const vw = id => t.evaluate(id => document.getElementById(id).contentWindow.innerWidth, id);
        ok('both rows start on Desktop (1366 px wide)', (await vw('pvPublic')) === 1366 && (await vw('pvAdmin')) === 1366);
        await t.click('#rowPublic .seg button[data-mode=mobile]');
        await new Promise(r => setTimeout(r, 700));
        ok('Public row switches to Mobile (390 px) on its own', (await vw('pvPublic')) === 390 && (await vw('pvAdmin')) === 1366);
        const burger = await t.evaluate(() => { const d = document.getElementById('pvPublic').contentDocument; const b = d.getElementById('sidebarToggle'); return b ? getComputedStyle(b).display : 'missing'; });
        ok('mobile view uses the phone layout (menu button shows)', burger !== 'none' && burger !== 'missing', burger);
        await t.click('#rowAdmin .seg button[data-mode=mobile]');
        await t.click('#rowPublic .seg button[data-mode=desktop]');
        await new Promise(r => setTimeout(r, 700));
        ok('rows are independent (public Desktop + admin Mobile)', (await vw('pvPublic')) === 1366 && (await vw('pvAdmin')) === 390);
        ok('switch buttons show which is on', (await t.$eval('#rowAdmin .seg button[data-mode=mobile]', b => b.getAttribute('aria-pressed'))) === 'true'
            && (await t.$eval('#rowPublic .seg button[data-mode=desktop]', b => b.getAttribute('aria-pressed'))) === 'true');
        await t.evaluate(() => document.getElementById('pvPublic').contentWindow.scrollTo({ top: 3000, behavior: 'instant' }));
        await new Promise(r => setTimeout(r, 400));
        const scrolled = await t.evaluate(() => document.getElementById('pvPublic').contentWindow.scrollY);
        ok('preview pages can be scrolled through', scrolled > 1000, scrolled);
        const scaled = await t.$eval('#pvPublic', f => f.style.transform);
        ok('frames are real pages scaled down to fit', /scale\(0\.\d+\)/.test(scaled), scaled);
        await t.$eval('#roles input.hex[data-k="accent"]', el => { el.value = '#ff0000'; el.dispatchEvent(new Event('input', { bubbles: true })); });
        const inst = await t.evaluate(() => ['pvPublic', 'pvAdmin'].map(id => document.getElementById(id).contentDocument.documentElement.style.getPropertyValue('--c-accent').trim()));
        ok('a colour edit updates both previews instantly', inst[0] === '255 0 0' && inst[1] === '255 0 0', inst.join(','));
        await setColor(t, '#mainColor', '#2de2e6');   // back to the generated palette
        await new Promise(r => setTimeout(r, 300));
        await t.screenshot({ path: path.join(OUT, 'theme_preview_rows.png'), fullPage: true });

        v = await visitorHome();
        ok('nothing changes for visitors before saving', (await noPalette(v)) && (await primaryOf(v)) === origBtn);
        ok('no hand-edit warning for a generated palette', !(await t.$eval('#manualWarn', e => e.classList.contains('show'))));
        ok('generated palette reads fine', !(await t.$eval('#contrastWarn', e => e.classList.contains('show'))), await t.$eval('#contrastWarn', e => e.textContent));

        // ---- manual edits + readability
        await setColor(t, '#roles input[type=color][data-k="text"]', '#0e0924');   // text = panel colour: unreadable
        await new Promise(r => setTimeout(r, 300));
        ok('editing by hand shows the "might not look good" warning', await t.$eval('#manualWarn', e => e.classList.contains('show')));
        const cw = await t.$eval('#contrastWarn', e => e.classList.contains('show') ? e.textContent : '');
        ok('unreadable text is flagged clearly', /hard to read/.test(cw) && (await t.$$eval('.lvl.bad', x => x.length)) >= 2, cw);
        await t.screenshot({ path: path.join(OUT, 'theme_warning.png'), fullPage: false });
        const fixedBg = await t.evaluate(() => getComputedStyle(document.body).backgroundColor);
        ok('Site Colours page keeps its fixed colours while editing', fixedBg === 'rgb(6, 4, 15)', fixedBg);

        // ---- save a generated palette
        await setColor(t, '#mainColor', '#2de2e6');   // back to the generated one
        await save(t);
        ok('save works', (await t.content()).includes('The new colours are live'));
        v = await visitorHome();
        const css = await v.$eval('#site-palette', e => e.textContent).catch(() => '');
        ok('saved palette reaches the public site', css.includes('--c-primary: 45 226 230') && (await primaryOf(v)) !== origBtn);
        await v.screenshot({ path: path.join(OUT, 'home_teal.png') });
        await P.panel.goto(BASE + 'admin_dashboard.php', { waitUntil: 'networkidle2' });
        ok('…and the admin panel', !!(await P.panel.$('#site-palette')));
        await P.panel.screenshot({ path: path.join(OUT, 'admin_teal.png') });
        await t.goto(BASE + 'theme.php');
        ok('…but never the Site Colours page', !(await t.$('#site-palette')) && (await t.evaluate(() => getComputedStyle(document.body).backgroundColor)) === 'rgb(6, 4, 15)');
        const ex = await (await as(null)).goto('http://127.0.0.1:8099/backend/Api/get_members.php').then(r => r.text());
        ok('JSON responses are left alone', ex.trim().startsWith('{') && !ex.includes('site-palette'));

        // ---- history keeps 5, restore, reset
        for (const hex of ['#ff4fd8', '#5cffb0', '#ffcf7d', '#3366ff', '#ff8800']) {
            await t.goto(BASE + 'theme.php', { waitUntil: 'networkidle2' });
            await setColor(t, '#mainColor', hex);
            await save(t);
        }
        await t.goto(BASE + 'theme.php');
        const hist = await t.$$eval('.hist-row', r => r.length);
        ok('only the last 5 saved palettes are kept', hist === 5, hist);
        ok('newest is marked live', (await t.$eval('.hist-row', r => r.textContent)).includes('live now'));
        // restore the 4th (#5cffb0 one)
        await Promise.all([t.waitForNavigation(), t.$eval('.hist-row:nth-child(5) form', f => f.submit())]);
        const cur = JSON.parse(sql("SELECT value FROM site_settings WHERE name='palette'"));
        ok('restoring an older palette makes it live', cur.main === '#5cffb0', cur.main);
        v = await visitorHome();
        ok('restored palette is on the public site', (await v.$eval('#site-palette', e => e.textContent)).includes('--c-primary: 92 255 176'));
        await t.goto(BASE + 'theme.php');
        await Promise.all([t.waitForNavigation(), t.$eval('form input[name=action][value=reset]', i => i.form.submit())]);
        v = await visitorHome();
        ok('reset brings the original colours back for everyone', (await noPalette(v)) && (await primaryOf(v)) === origBtn);

        const log = sql("SELECT action FROM admin_activity_log WHERE action LIKE 'palette_%' ORDER BY id").split('\n');
        ok('every save, restore and reset is logged', log.filter(a => a === 'palette_saved').length === 6 && log.includes('palette_restored') && log.includes('palette_reset'), log.join(','));
        const detail = sql("SELECT CONCAT_WS(' | ', admin_name, target, details) FROM admin_activity_log WHERE action='palette_saved' ORDER BY id LIMIT 1");
        ok('log says who, what and the readability', /Admin Tester \| Site colours \| Main colour #2de2e6, generated; \d+ of 13 colours differ from the original; readability/.test(detail), detail);

        await t.goto(BASE + 'theme.php', { waitUntil: 'networkidle2' });
        await t.screenshot({ path: path.join(OUT, 'theme_page.png'), fullPage: false });
        for (const [k, p] of Object.entries(P)) if (p.errors.length) ok(`no JS errors (${k})`, false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        sql("DELETE FROM site_settings WHERE name LIKE 'palette%'");
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
