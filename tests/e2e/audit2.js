// Checks for the 19 Oct 2026 audit fixes that the older suites don't cover. Mail is captured to a file.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', '-P3307', '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const PHP = T.PHP;
const REPO = T.ROOT_FWD;
const BASE = 'http://127.0.0.1:8099/frontend/';
const S = T.RUNTIME;
const CAPTURE = path.join(S, 'auth_mail_capture.jsonl');
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 170) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], panel: ['panel@test.local', 'panelpass123'], hr: ['admin@test.local', 'adminpass123'] };
const alertText = t => ((t.match(/class="alert alert-(?:success|danger)[^>]*>([\s\S]*?)<\/div>/) || [])[1] || '').replace(/<[^>]+>/g, '').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').trim();
const notices = () => fs.existsSync(CAPTURE) ? fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse).filter(m => m.kind === 'notice') : [];

let browser;
async function as(role) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    page.on('dialog', d => d.accept());
    if (role) {
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', ACC[role][0]);
        await page.type('#adminPassword', ACC[role][1]);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}
async function post(page, url, fields, file) {
    return page.evaluate(async (url, fields, file) => {
        const fd = new FormData();
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) fd.append('csrf_token', meta.content);
        for (const [k, v] of Object.entries(fields)) fd.append(k, v);
        if (file) {
            const bin = Uint8Array.from(atob(file.b64), c => c.charCodeAt(0));
            fd.append(file.field, new Blob([bin], { type: file.type }), file.name);
        }
        const r = await fetch(url, { method: 'POST', body: fd });
        return { status: r.status, url: r.url, text: await r.text() };
    }, url, fields, file || null);
}
const batch = (page, id) => page.evaluate(async id => {
    const fd = new FormData(); fd.append('id', id);
    const r = await fetch('notices.php?action=batch', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: fd });
    return r.json();
}, id);
const countFiles = dir => { let n = 0; const walk = d => { if (!fs.existsSync(d)) return; for (const f of fs.readdirSync(d, { withFileTypes: true })) f.isDirectory() ? walk(path.join(d, f.name)) : n++; }; walk(dir); return n; };

(async () => {
    try { fs.unlinkSync(CAPTURE); } catch (e) { }
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, args: ['--no-first-run'] });
    try {
        const P = {};
        for (const r of Object.keys(ACC)) P[r] = await as(r);

        // ---------------------------------------------- H4: parallel batches never send twice
        await P.super.goto(BASE + 'gb_members.php');
        await post(P.super, 'gb_members.php', { action: 'import_sheet' });
        await P.super.goto(BASE + 'notices.php');
        let r = await post(P.super, 'notices.php', { action: 'create', audience: 'all', subject: 'Parallel {first_name}', body: 'Dear {name}' });
        const nid = +((r.url.match(/run=(\d+)/) || [])[1] || 0);
        const total = +sql(`SELECT total FROM notices WHERE id=${nid}`);
        await P.super.goto(BASE + 'notices.php?run=' + nid);
        // five batch requests at the same time, from two tabs
        const res = await Promise.all([batch(P.super, nid), batch(P.super, nid), batch(P.super, nid), batch(P.super, nid), batch(P.super, nid)]);
        let last = res[res.length - 1];
        for (let i = 0; i < 10 && !last.done; i++) last = await batch(P.super, nid);
        const sent = notices().filter(m => m.subject.startsWith('Parallel'));
        const uniq = new Set(sent.map(m => m.to));
        ok('H4: parallel batches send each e-mail exactly once', total > 0 && sent.length === total && uniq.size === total, `${sent.length} sent, ${uniq.size} unique, total ${total}`);
        ok('H4: notice finished, nothing left in "sending"', last.done && sql(`SELECT COUNT(*) FROM notice_recipients WHERE notice_id=${nid} AND status IN ('pending','sending')`) === '0');
        ok('M10: daily count includes every e-mail', +sql("SELECT COUNT(*) FROM notice_recipients r JOIN notices n ON n.id=r.notice_id WHERE n.sender_key='club' AND r.status='sent'") >= total);
        ok('M8: notice_recipients has its foreign key', sql("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_nr_notice'") === '1');

        // ---------------------------------------------- H3: Super Admin never named
        await P.panel.goto(BASE + 'notices.php');
        let html = await P.panel.content();
        ok('H3: Email Notices shows "an admin" for the Super Admin', !html.includes('Super Tester') && html.includes('an admin'));
        sql(`INSERT INTO site_settings (name, value) VALUES ('palette_history', '[{"roles":{"primary":"#8b5cff"},"main":null,"manual":false,"saved_at":"2026-10-19 10:00:00","saved_by":"Super Tester","saved_by_role":"super_admin","saved_by_id":${sql("SELECT id FROM adminpanel WHERE email='super@test.local'")}}]') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
        await P.panel.goto(BASE + 'theme.php');
        html = await P.panel.content();
        ok('H3: Site Colours history shows "an admin" to the Panel Admin', !html.includes('Super Tester') && html.includes('an admin'));
        await P.super.goto(BASE + 'theme.php');
        ok('H3: …and the real name to the Super Admin', (await P.super.content()).includes('Super Tester'));
        sql("DELETE FROM site_settings WHERE name='palette_history'");

        // ---------------------------------------------- A4: neutral duplicate message
        await P.super.goto(BASE + 'admin_management.php');
        r = await post(P.super, 'admin_management.php', { action: 'update_admin', admin_id: sql("SELECT id FROM adminpanel WHERE email='miap@test.local'"), username: 'Miap Tester', email: 'admin@test.local', role: 'miap_admin', status: 'active' });
        const a4 = alertText(r.text);
        ok('A4: duplicate account message names nobody', a4.includes("can't be used") && !a4.toLowerCase().includes('already exists'), a4);

        // ---------------------------------------------- M7: forms keep typed values
        await P.super.goto(BASE + 'media.php?tab=events');
        r = await post(P.super, 'media.php?tab=events', { action: 'event_add', title: 'Kept Title 123', event_date: '2026-01-05', place: 'Kept Place', description: 'Kept description', highlights: 'Kept highlight' });
        ok('M7: event form refilled after an error', alertText(r.text) !== '' && r.text.includes('value="Kept Title 123"') && r.text.includes('Kept description'), alertText(r.text));
        await P.super.goto(BASE + 'home_stats.php');
        r = await post(P.super, 'home_stats.php', { semester: 'Kept Semester', 'members[value]': 'abc', 'shows[value]': '77' });
        ok('M7: homepage stats refilled after an error', r.text.includes('value="Kept Semester"') && r.text.includes('value="abc"'), alertText(r.text));
        await P.super.goto(BASE + 'set_venue.php');
        r = await post(P.super, 'set_venue.php', { venue_name: 'Kept Hall', venue_location: '', venue_date: '2026-11-01', venue_time: '15:00', venue_starting_time: '3:00', venue_ending_time: '5:00', venue_ampm: 'AM' });
        ok('M7: venue form refilled after an error', r.text.includes('value="Kept Hall"') && /value="AM" selected/.test(r.text));
        await P.hr.goto(BASE + 'profile.php');
        r = await post(P.hr, 'profile.php', { update_profile: '1', username: 'Panel Tester', email: 'kept@test.local' });
        ok('M7: profile refilled after an error', r.text.includes('value="kept@test.local"') && r.text.includes('value="Panel Tester"'));

        // ---------------------------------------------- M2: upload removed when the database refuses the row
        const tid = sql("SELECT id FROM tenures WHERE is_current=1");
        const img = path.join(S, 'probe45.jpg');
        execFileSync(PHP, ['-d', 'extension=gd', '-r', `$i=imagecreatetruecolor(400,500);imagejpeg($i,'${img.replace(/\\/g, '/')}');`]);
        const before = countFiles(`${REPO}/frontend/images/tenures`);
        await P.super.goto(BASE + 'media.php?tab=tenures&t=' + tid);
        r = await post(P.super, 'media.php?tab=tenures&t=' + tid, { action: 'person_save', grp: 'panel', tenure_id: tid, person_id: '', name: 'N'.repeat(300), position: 'Probe' },
            { field: 'photo', name: 'p.jpg', type: 'image/jpeg', b64: fs.readFileSync(img).toString('base64') });
        ok('M2: failed save leaves no orphan photo file', countFiles(`${REPO}/frontend/images/tenures`) === before && alertText(r.text).includes('database problem'), alertText(r.text));
        ok('M2: no SQL text shown to the admin', !/SQLSTATE|Data too long/i.test(r.text));

        // ---------------------------------------------- M3: Excel cells are text
        sql("INSERT INTO pending_applications (full_name, university_id, email, gsuite_email, department, phone, semester, gender, facebook_url, firstPriority, secondPriority) VALUES ('=HYPERLINK(\"http://evil\",\"x\")', '29990077', 'f@x.com', 'f@g.example.edu', '+CSE', '01700000000', '1st', 'Other', '@fb', 'HR', 'PR')");
        await P.super.goto(BASE + 'pending_applications.php');
        const b64 = await P.super.evaluate(async () => { const r = await fetch('../backend/Action/export_members_excel.php?status=Pending'); const b = new Uint8Array(await r.arrayBuffer()); let s = ''; for (const x of b) s += String.fromCharCode(x); return btoa(s); });
        const xlsx = path.join(S, 'probe_export.xlsx');
        fs.writeFileSync(xlsx, Buffer.from(b64, 'base64'));
        const chk = execFileSync(PHP, ['-d', 'extension=zip', '-d', 'extension=gd', '-r',
            `require '${REPO}/backend/vendor/autoload.php'; $s=\\PhpOffice\\PhpSpreadsheet\\IOFactory::load('${xlsx.replace(/\\/g, '/')}')->getActiveSheet(); foreach ($s->getRowIterator() as $row) { $c=$s->getCell('B'.$row->getRowIndex()); if ($c->getValue()==='29990077') { $a=$s->getCell('A'.$row->getRowIndex()); echo $a->getDataType(),'|',$a->getValue(),'|',$s->getCell('E'.$row->getRowIndex())->getDataType(); } }`]).toString();
        ok('M3: "=…" name exported as text, not a formula', chk.startsWith('s|=HYPERLINK') && chk.endsWith('|s'), chk);
        sql("DELETE FROM pending_applications WHERE university_id='29990077'");

        // ---------------------------------------------- L5: logout only by POST
        const pg = await as('hr');
        await pg.goto(BASE + 'logout.php');
        await pg.goto(BASE + 'admin_dashboard.php');
        ok('L5: opening logout.php does not log out', pg.url().endsWith('admin_dashboard.php'), pg.url());
        await pg.goto(BASE + 'logout.php');
        await Promise.all([pg.waitForNavigation(), pg.click('form[action="logout.php"] button')]);
        await pg.goto(BASE + 'admin_dashboard.php');
        ok('L5: the Log out button logs out', pg.url().includes('admin-login.php'), pg.url());

        // ---------------------------------------------- L11: 12-hour limit
        const pg2 = await as('hr');
        const sid = (await pg2.cookies()).find(c => c.name === 'PHPSESSID').value;
        const sf = path.join(T.SESSIONS, 'sess_' + sid);
        const data = fs.readFileSync(sf, 'utf8');
        fs.writeFileSync(sf, data.replace(/login_at\|i:\d+;/, `login_at|i:${Math.floor(Date.now() / 1000) - 13 * 3600};`));
        await pg2.goto(BASE + 'admin_dashboard.php');
        ok('L11: signed out 12 hours after login even when active', pg2.url().includes('admin-login.php') && (await pg2.content()).includes('12 hours after logging in'), pg2.url());

        // ---------------------------------------------- L1, L8
        await P.panel.goto(BASE + 'dashboard_updates.php');
        ok('L1: Dashboard Updates back link goes to the own dashboard', (await P.panel.$eval('.back-btn', a => a.getAttribute('href'))) === 'admin_dashboard.php');
        const v = await as(null);
        await v.goto(BASE + 'past_events.php', { waitUntil: 'networkidle2' });
        const broken = await v.$$eval('img.event-image', im => im.filter(i => i.complete && i.naturalWidth === 0).map(i => i.src));
        ok('L8: no broken event photos on Past Events', broken.length === 0, broken.join(', '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
