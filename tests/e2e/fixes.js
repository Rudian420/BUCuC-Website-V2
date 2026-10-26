// Follow-up fixes: tenures, 12-tenure limit, deletes remove files, promotion in the DB per role, max image sizes.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const REPO = T.ROOT_FWD;
const MEDIA = T.MEDIA;
const GAS_LOG = T.GAS_LOG;
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 170) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], panel: ['panel@test.local', 'panelpass123'], hr: ['admin@test.local', 'adminpass123'], miap: ['miap@test.local', 'miappass123'], sec: ['sec@test.local', 'secpass1234'] };
const shown = (html, text) => { const i = html.indexOf(text); const dj = html.indexOf('id="bucuc-panel-years-data"'); return i > -1 && dj > -1 && i < dj; };   // drawn by the server in initial HTML before the data island
const getDataIsland = html => { const m = html.match(/<script\s+type=["']application\/json["']\s+id=["']bucuc-panel-years-data["']>([\s\S]*?)<\/script>/); return m ? JSON.parse(m[1]) : null; };
const exists = rel => fs.existsSync(`${REPO}/frontend/${rel}`);
const alertText = t => ((t.match(/class="alert alert-(?:success|danger)[^>]*>([\s\S]*?)<\/div>/) || [])[1] || '').replace(/<[^>]+>/g, '').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').trim();

let browser;
async function as(role) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    page.errors = [];
    page.on('pageerror', e => page.errors.push(e.message));
    page.on('dialog', d => d.accept());
    await page.setViewport({ width: 1280, height: 900 });
    if (role) {
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', ACC[role][0]);
        await page.type('#adminPassword', ACC[role][1]);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}
async function post(page, url, fields, file) {
    let b64 = null, fname = null, mime = null, field = null;
    if (file) { field = file[0]; fname = path.basename(file[1]); mime = { '.jpg': 'image/jpeg', '.png': 'image/png', '.mp4': 'video/mp4' }[path.extname(fname)]; b64 = fs.readFileSync(file[1]).toString('base64'); }
    return page.evaluate(async (url, fields, field, fname, mime, b64) => {
        const fd = new FormData();
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) fd.append('csrf_token', meta.content);
        for (const [k, v] of Object.entries(fields)) fd.append(k, v);
        if (b64) fd.append(field, new Blob([Uint8Array.from(atob(b64), c => c.charCodeAt(0))], { type: mime }), fname);
        const r = await fetch(url, { method: 'POST', body: fd });
        return { status: r.status, url: r.url, text: await r.text() };
    }, url, fields, field, fname, mime, b64);
}
async function move(page, id, position, action = 'update') {
    return page.evaluate(async (id, position, action) => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        const r = await fetch('gb_members.php?action=gas_proxy', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': meta ? meta.content : '' }, body: JSON.stringify({ action, id, position }) });
        return { status: r.status, body: await r.json().catch(() => ({})) };
    }, id, position, action);
}
const pos = sid => sql(`SELECT position FROM members WHERE university_id='${sid}'`);
const mid = sid => sql(`SELECT id FROM members WHERE university_id='${sid}'`);
const setPos = (sid, p) => sql(`UPDATE members SET position='${p}' WHERE university_id='${sid}'`);
const tid = label => sql(`SELECT id FROM tenures WHERE label='${label}'`);

(async () => {
    await fetch(BASE + 'index.php');   // first page load copies the old panels into the tenure tables
    // Back up the photo files of the oldest tenure (including companion .webp files) and members.json
    const oldestFiles = sql("SELECT DISTINCT image FROM tenure_people p JOIN tenures t ON t.id=p.tenure_id WHERE t.label='2019-20' AND image<>''").split('\n').filter(Boolean);
    const allOldestFiles = [];
    for (const f of oldestFiles) {
        allOldestFiles.push(f);
        const webp = f.replace(/\.(jpe?g|png)$/i, '.webp');
        if (exists(webp)) allOldestFiles.push(webp);
    }
    const backup = Object.fromEntries(allOldestFiles.filter(exists).map(f => [f, fs.readFileSync(`${REPO}/frontend/${f}`)]));
    const membersJson = fs.readFileSync(`${REPO}/backend/Api/members.json`);
    sql("DELETE FROM admin_activity_log; DELETE FROM admin_permissions; DELETE FROM site_settings; DELETE FROM site_events");
    try { fs.unlinkSync(GAS_LOG); } catch (e) { }
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, args: ['--no-first-run'] });
    try {
        const P = {};
        for (const r of Object.keys(ACC)) P[r] = await as(r);

        // ================================================= 4. promotion / demotion (database)
        await P.super.goto(BASE + 'gb_members.php');
        let r = await post(P.super, 'gb_members.php', { action: 'import_sheet' });
        ok('import from the Google sheet fills the members table', decodeURIComponent(r.url).includes('Imported') && pos('21100002') === 'ASB' && pos('21100003') === 'SB', decodeURIComponent(r.url).slice(-90));
        r = await post(P.super, 'gb_members.php', { action: 'import_sheet' });
        ok('importing again only updates (no duplicates)', +sql("SELECT COUNT(*) FROM members WHERE university_id LIKE '211000%'") === 5);

        const flow = async (role, sid, label) => {
            await P[role].goto(BASE + 'gb_members.php');
            setPos(sid, 'GB');
            const id = mid(sid);
            const steps = [['ASB', 'GB → ASB'], ['SB', 'ASB → Secretary Panel'], ['ASB', 'Secretary Panel → ASB'], ['GB', 'ASB → GB']];
            for (const [to, name] of steps) {
                const res = await move(P[role], id, to);
                ok(`${label}: ${name}`, res.status === 200 && res.body.success && pos(sid) === to, `${res.status} ${JSON.stringify(res.body)} db=${pos(sid)}`);
            }
        };
        await flow('super', '21100001', 'Super Admin');
        await flow('panel', '21100004', 'Panel Admin');

        // HR without the Secretary Panel permission
        await P.hr.goto(BASE + 'gb_members.php');
        setPos('21100001', 'GB'); setPos('21100002', 'ASB'); setPos('21100003', 'SB');
        let m = await move(P.hr, mid('21100001'), 'ASB');
        ok('HR: GB → ASB', m.status === 200 && pos('21100001') === 'ASB', JSON.stringify(m));
        m = await move(P.hr, mid('21100001'), 'GB');
        ok('HR: ASB → GB', m.status === 200 && pos('21100001') === 'GB', JSON.stringify(m));
        m = await move(P.hr, mid('21100002'), 'SB');
        ok('HR (no SP permission): ASB → Secretary Panel blocked', m.status === 403 && pos('21100002') === 'ASB', JSON.stringify(m));
        m = await move(P.hr, mid('21100003'), 'ASB');
        ok('HR (no SP permission): Secretary Panel → ASB blocked', m.status === 403 && pos('21100003') === 'SB', JSON.stringify(m));
        // grant, then HR can
        await P.panel.goto(BASE + 'admin_management.php');
        const hrId = sql("SELECT id FROM adminpanel WHERE email='admin@test.local'");
        const hrDefaults = ['final_approve', 'view_members', 'promote_gb_asb', 'upload_carousel', 'upload_panel', 'upload_events', 'upload_hero'];
        await P.panel.evaluate(async (id, perms) => {
            const fd = new FormData(); fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            fd.append('action', 'save_permissions'); fd.append('admin_id', id); perms.forEach(p => fd.append('perms[]', p));
            await fetch('admin_management.php', { method: 'POST', body: fd });
        }, hrId, [...hrDefaults, 'promote_sp']);
        m = await move(P.hr, mid('21100002'), 'SB');
        ok('HR (with SP permission): ASB → Secretary Panel', m.status === 200 && pos('21100002') === 'SB', JSON.stringify(m));
        m = await move(P.hr, mid('21100002'), 'ASB');
        ok('HR (with SP permission): Secretary Panel → ASB', m.status === 200 && pos('21100002') === 'ASB', JSON.stringify(m));
        await P.panel.evaluate(async (id, perms) => {
            const fd = new FormData(); fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            fd.append('action', 'save_permissions'); fd.append('admin_id', id); perms.forEach(p => fd.append('perms[]', p));
            await fetch('admin_management.php', { method: 'POST', body: fd });
        }, hrId, hrDefaults);
        m = await move(P.hr, mid('21100002'), 'SB');
        ok('HR: permission revoked again → blocked', m.status === 403 && pos('21100002') === 'ASB');
        m = await move(P.hr, mid('21100004'), 'SB');
        ok('no skipping a step (GB → Secretary Panel)', m.status === 400 && pos('21100004') === 'GB', JSON.stringify(m));
        m = await move(P.hr, mid('21100004'), 'ASB', 'delete');
        ok('HR cannot remove members', m.status === 403 && !!mid('21100004'));

        await P.miap.goto(BASE + 'gb_members.php');
        ok('MIAP cannot open the member pages', P.miap.url().includes('permission'), P.miap.url());
        m = await move(P.miap, mid('21100001'), 'ASB');
        ok('MIAP cannot promote (403)', m.status === 403 && pos('21100001') === 'GB', JSON.stringify(m));
        await P.sec.goto(BASE + 'admin_dashboard.php');
        m = await P.sec.evaluate(async id => (await fetch('gb_members.php?action=gas_proxy', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ action: 'update', id, position: 'ASB' }) })).status, mid('21100001'));
        ok('Secretary Admin cannot promote (403)', m === 403 && pos('21100001') === 'GB', m);

        // Real button flow in the page (HR on the GB page)
        await P.hr.goto(BASE + 'gb_members.php');
        await P.hr.waitForSelector('#membersTable tbody tr');
        const rowSel = `#membersTable tr[data-student-id="21100001"] select`;
        await P.hr.select(rowSel, 'ASB');
        await new Promise(res => setTimeout(res, 1200));
        ok('page select "Promote to ASB" changes the database', pos('21100001') === 'ASB', pos('21100001'));
        await P.hr.goto(BASE + 'asb_panel.php');
        await P.hr.waitForSelector('#membersTable tbody tr');
        ok('…and the member now shows on the ASB page after reload', (await P.hr.$$eval('#membersTable tbody tr', rs => rs.map(r => r.dataset.studentId))).includes('21100001'));
        const opts = await P.hr.$$eval(`#membersTable tr[data-student-id="21100001"] select option`, o => o.map(x => x.textContent));
        ok('HR on ASB page is offered only "Demote to GB"', opts.join('|') === 'ASB (now)|Demote to GB', opts.join('|'));

        const moves = +sql("SELECT COUNT(*) FROM admin_activity_log WHERE action IN ('member_promoted','member_demoted')");
        ok('every successful move is in the activity log', moves === 13, moves);
        const sample = sql("SELECT CONCAT_WS(' | ', admin_name, action, target, details) FROM admin_activity_log WHERE action='member_promoted' AND admin_name='Admin Tester' ORDER BY id LIMIT 1");
        ok('log entry: who / what / whom', /Admin Tester \| member_promoted \| Gina Body \(21100001\) \| GB → ASB/.test(sample), sample);
        const calls = fs.existsSync(GAS_LOG) ? fs.readFileSync(GAS_LOG, 'utf8').trim().split('\n').length : 0;
        ok('each move is also copied to the (fake) Google sheet', calls === 13, calls);

        // ================================================= 5. image sizes are maximums
        const t2627 = tid('2026-27');
        await P.miap.goto(BASE + `media.php?tab=tenures&t=${t2627}`);
        r = await post(P.miap, `media.php?tab=tenures&t=${t2627}`, { action: 'person_save', grp: 'panel', tenure_id: t2627, name: 'Big Photo', position: 'Treasurer' }, ['photo', `${MEDIA}/panel_big.jpg`]);
        ok('panel photo bigger than 800 × 1000 is refused', alertText(r.text).includes('too big'), alertText(r.text));
        r = await post(P.miap, `media.php?tab=tenures&t=${t2627}`, { action: 'person_save', grp: 'panel', tenure_id: t2627, name: 'Small Photo', position: 'Treasurer' }, ['photo', `${MEDIA}/panel_small.jpg`]);
        ok('smaller panel photo (400 × 500) is accepted', alertText(r.text).includes('was added'), alertText(r.text));
        r = await post(P.miap, `media.php?tab=tenures&t=${t2627}`, { action: 'person_save', grp: 'secretary', tenure_id: t2627, name: 'Big Sec', position: 'Secretary of Finance' }, ['photo', `${MEDIA}/car_big.jpg`]);
        ok('secretary photo bigger than 750 × 1000 is refused', alertText(r.text).includes('too big'), alertText(r.text));
        r = await post(P.miap, 'media.php?tab=events', { action: 'event_add', title: 'Huge', event_date: '2026-10-01' }, ['photo', `${MEDIA}/event_big.jpg`]);
        ok('event photo bigger than 1500 × 1000 is refused', alertText(r.text).includes('too big'), alertText(r.text));
        r = await post(P.miap, `media.php?tab=tenures&t=${t2627}`, { action: 'person_save', grp: 'panel', tenure_id: t2627, name: 'Square', position: 'X' }, ['photo', `${MEDIA}/car_square.jpg`]);
        ok('wrong ratio still refused', alertText(r.text).includes('4:5'), alertText(r.text));

        // ================================================= 3. deletes remove the file
        let img = sql("SELECT image FROM tenure_people WHERE name='Small Photo'");
        ok('uploaded photo file exists', exists(img), img);
        const smallId = sql("SELECT id FROM tenure_people WHERE name='Small Photo'");
        r = await post(P.miap, `media.php?tab=tenures&t=${t2627}`, { action: 'person_save', grp: 'panel', tenure_id: t2627, person_id: smallId, name: 'Small Photo', position: 'Treasurer' }, ['photo', `${MEDIA}/panel_ok2.jpg`]);
        const img2 = sql("SELECT image FROM tenure_people WHERE name='Small Photo'");
        ok('replacing a photo deletes the old file', !exists(img) && exists(img2), `${img} → ${img2}`);
        r = await post(P.miap, `media.php?tab=tenures&t=${t2627}`, { action: 'person_remove', person_id: smallId });
        ok('removing a person deletes the row and the file', !sql(`SELECT id FROM tenure_people WHERE id=${smallId}`) && !exists(img2), alertText(r.text));
        r = await post(P.miap, 'media.php?tab=events', { action: 'event_add', title: 'Delete Me', event_date: '2026-10-01' }, ['photo', `${MEDIA}/event_ok.jpg`]);
        const evImg = sql("SELECT image FROM site_events WHERE title='Delete Me'");
        r = await post(P.miap, 'media.php?tab=events', { action: 'event_remove', id: sql("SELECT id FROM site_events WHERE title='Delete Me'") });
        ok('removing an event deletes the row and the file', !sql("SELECT id FROM site_events WHERE title='Delete Me'") && !exists(evImg), evImg);
        r = await post(P.miap, 'media.php?tab=hero', { action: 'hero_upload' }, ['video', `${MEDIA}/hero_ok.mp4`]);
        const h1 = sql("SELECT value FROM site_settings WHERE name='hero_video'");
        await new Promise(res => setTimeout(res, 1100));
        r = await post(P.miap, 'media.php?tab=hero', { action: 'hero_upload' }, ['video', `${MEDIA}/hero_ok.mp4`]);
        const h2 = sql("SELECT value FROM site_settings WHERE name='hero_video'");
        ok('replacing the hero video deletes the previous upload', h1 !== h2 && !exists(h1) && exists(h2), `${h1} → ${h2}`);
        await P.miap.goto(BASE + 'media.php?tab=hero');
        const heroHtml = await P.miap.content();
        ok('hero video has no delete button (only replace)', !/hero_delete|Delete video/i.test(heroHtml) && heroHtml.includes('Replace with the original video'));
        r = await post(P.miap, 'media.php?tab=hero', { action: 'hero_reset' });
        ok('replacing with the original deletes the upload, originals stay', !exists(h2) && exists('video/bucuc1.mp4') && exists('video/bucuc1.webm'));
        const delLogs = sql("SELECT COUNT(*) FROM admin_activity_log WHERE (action LIKE '%removed' OR action='hero_video_changed' OR action='hero_video_reset') AND details LIKE '%deleted%'");
        ok('deletions are written to the activity log', +delLogs >= 4, delLogs);

        // ================================================= 1. tenures
        await P.sec.goto(BASE + 'media.php');
        ok('Secretary Admin cannot manage tenures', P.sec.url().includes('permission'));
        await P.miap.goto(BASE + 'media.php?tab=tenures');
        r = await post(P.miap, 'media.php?tab=tenures', { action: 'tenure_create', label: '2027-28' });
        const t2728 = tid('2027-28');
        ok('MIAP (upload power) creates tenure 2027-28', !!t2728 && alertText(r.text).includes('was created'), alertText(r.text));
        r = await post(P.miap, 'media.php?tab=tenures', { action: 'tenure_create', label: '2027-28' });
        ok('duplicate tenure refused', alertText(r.text).includes('already exists'));
        r = await post(P.miap, 'media.php?tab=tenures', { action: 'tenure_create', label: '27-28' });
        ok('bad label refused', alertText(r.text).includes('like 2026-27'));
        await post(P.miap, `media.php?tab=tenures&t=${t2728}`, { action: 'person_save', grp: 'panel', tenure_id: t2728, name: 'New President', position: 'President' }, ['photo', `${MEDIA}/panel_ok.png`]);
        await post(P.miap, `media.php?tab=tenures&t=${t2728}`, { action: 'person_save', grp: 'secretary', tenure_id: t2728, name: 'New Secretary', position: 'Secretary of Finance' }, ['photo', `${MEDIA}/car_ok.jpg`]);
        let home = await (await as(null)).goto(BASE + 'index.php').then(x => x.text());
        ok('before switching: homepage still shows 2026-27 first', /<option value="0">Current Panel \(2026-27\)/.test(home) && !shown(home, 'New President'));
        r = await post(P.miap, `media.php?tab=tenures&t=${t2728}`, { action: 'tenure_current', tenure_id: t2728 });
        home = await (await as(null)).goto(BASE + 'index.php').then(x => x.text());
        ok('marking 2027-28 current: homepage shows it by default', /<option value="0">Current Panel \(2027-28\)/.test(home) && shown(home, 'New President') && shown(home, 'New Secretary'), alertText(r.text));
        ok('older tenures stay available as past panels', home.includes('Panel 2026-27') && home.includes('Panel 2019-20'));
        const island = getDataIsland(home);
        ok('homepage renders valid JSON data island #bucuc-panel-years-data', Array.isArray(island) && island.length > 0 && island[0].label === 'Current Panel (2027-28)');
        const mj = JSON.parse(fs.readFileSync(`${REPO}/backend/Api/members.json`, 'utf8'));
        ok('members.json follows the tenures (public API)', mj.years[0].label === 'Current Panel (2027-28)' && mj.years[0].panel_members[0].name === 'New President');
        r = await post(P.miap, 'media.php?tab=tenures', { action: 'tenure_delete', tenure_id: t2728 });
        ok('the current tenure cannot be deleted', alertText(r.text).includes('cannot be deleted') && !!tid('2027-28'));

        // ================================================= 2. 12-tenure limit
        for (const lbl of ['2028-29', '2029-30', '2030-31', '2031-32']) await post(P.panel, 'media.php?tab=tenures', { action: 'tenure_create', label: lbl });
        ok('12 tenures now', +sql('SELECT COUNT(*) FROM tenures') === 12);
        const oldPeople = +sql(`SELECT COUNT(*) FROM tenure_people WHERE tenure_id=${tid('2019-20')}`);
        await P.panel.goto(BASE + 'media.php?tab=tenures');
        ok('page warns which tenure the next one deletes', (await P.panel.content()).includes('deletes <b>2019-20</b>'));
        r = await post(P.panel, 'media.php?tab=tenures', { action: 'tenure_create', label: '2032-33' });
        ok('13th tenure without confirming: asks first, names 2019-20, deletes nothing', r.text.includes('Delete tenure 2019-20?') && !!tid('2019-20') && !tid('2032-33') && +sql('SELECT COUNT(*) FROM tenures') === 12);
        r = await post(P.panel, 'media.php?tab=tenures', { action: 'tenure_create', label: '2032-33', confirm_delete: tid('2019-20') });
        ok('after confirming: 2019-20 deleted, 2032-33 saved, still 12', !tid('2019-20') && !!tid('2032-33') && +sql('SELECT COUNT(*) FROM tenures') === 12, alertText(r.text));
        ok('…its Presidential + Secretary Panel rows are gone', +sql("SELECT COUNT(*) FROM tenure_people p LEFT JOIN tenures t ON t.id=p.tenure_id WHERE t.id IS NULL") === 0 && oldPeople > 0);
        const stillThere = oldestFiles.filter(exists);
        ok('…and its photo files are deleted from the server', oldestFiles.length > 0 && stillThere.length === 0, `${oldestFiles.length} files, left: ${stillThere.join(',')}`);
        const dl = sql("SELECT CONCAT_WS(' | ', admin_name, target, details) FROM admin_activity_log WHERE action='tenure_deleted' ORDER BY id DESC LIMIT 1");
        ok('the automatic deletion is in the activity log', /Panel Tester \| Tenure 2019-20 \| Automatic/.test(dl), dl);
        r = await post(P.panel, 'media.php?tab=tenures', { action: 'tenure_create', label: '2033-34', confirm_delete: '999999' });
        ok('a stale/wrong confirmation does not delete anything', r.text.includes('Delete tenure 2020-21?') && !!tid('2020-21'));

        // tenure deletion by hand removes its uploaded files
        await post(P.panel, `media.php?tab=tenures&t=${t2627}`, { action: 'tenure_current', tenure_id: t2627 });
        const newFiles = sql(`SELECT image FROM tenure_people WHERE tenure_id=${t2728}`).split('\n').filter(Boolean);
        r = await post(P.panel, 'media.php?tab=tenures', { action: 'tenure_delete', tenure_id: t2728 });
        ok('deleting a tenure deletes its uploaded photo files', newFiles.length === 2 && newFiles.every(f => !exists(f)) && !tid('2027-28'), alertText(r.text));

        for (const [k, p] of Object.entries(P)) if (p.errors.length) ok(`no JS errors (${k})`, false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        for (const [f, buf] of Object.entries(backup)) { fs.mkdirSync(path.dirname(`${REPO}/frontend/${f}`), { recursive: true }); fs.writeFileSync(`${REPO}/frontend/${f}`, buf); }
        for (let i = 0; i < 20; i++) {
            try {
                fs.writeFileSync(`${REPO}/backend/Api/members.json`, membersJson);
                break;
            } catch (e) {
                await new Promise(r => setTimeout(r, 100));
            }
        }
        for (const f of fs.readdirSync(`${REPO}/frontend/video`)) if (f.startsWith('hero-')) fs.unlinkSync(`${REPO}/frontend/video/${f}`);
        for (const d of ['images/events', 'images/tenures']) if (fs.existsSync(`${REPO}/frontend/${d}`)) fs.rmSync(`${REPO}/frontend/${d}`, { recursive: true });
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
