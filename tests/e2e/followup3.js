// Per-role sender accounts, Gmail + G-Suite recipients, manual homepage stats. Mail is captured to a file.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', '-P3307', '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const CAPTURE = path.join(T.RUNTIME, 'auth_mail_capture.jsonl');
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 170) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], panel: ['panel@test.local', 'panelpass123'], hr: ['admin@test.local', 'adminpass123'], miap: ['miap@test.local', 'miappass123'], sec: ['sec@test.local', 'secpass1234'] };
const alertText = t => ((t.match(/class="alert alert-(?:success|danger)[^>]*>([\s\S]*?)<\/div>/) || [])[1] || '').replace(/<[^>]+>/g, '').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').trim();
const captured = () => fs.existsSync(CAPTURE) ? fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse).filter(m => m.kind === 'notice') : [];
const clearCapture = () => { try { fs.unlinkSync(CAPTURE); } catch (e) { } };
const homeStat = (html, key) => { const m = html.match(new RegExp(`data-stat="${key}"><b>([^<]*)</b><span>([^<]*)`)); return m ? { value: m[1], label: m[2] } : null; };

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
async function post(page, url, fields) {
    return page.evaluate(async (url, fields) => {
        const fd = new FormData();
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) fd.append('csrf_token', meta.content);
        for (const [k, v] of Object.entries(fields)) (Array.isArray(v) ? v : [v]).forEach(x => fd.append(k, x));
        const r = await fetch(url, { method: 'POST', body: fd });
        return { status: r.status, url: r.url, text: await r.text() };
    }, url, fields);
}
async function batchUntilDone(page, id) {
    let d;
    for (let i = 0; i < 50; i++) {
        d = await page.evaluate(async id => {
            const fd = new FormData(); fd.append('id', id);
            const r = await fetch('notices.php?action=batch', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: fd });
            return r.json();
        }, id);
        if (!d.success || d.done || d.limitReached) break;
    }
    return d;
}
const noticeId = r => +((r.url.match(/run=(\d+)/) || [])[1] || 0);

(async () => {
    sql("DELETE FROM admin_activity_log; DELETE FROM site_settings; DELETE FROM admin_permissions");
    try { sql("DELETE FROM notices; DELETE FROM notice_recipients"); } catch (e) { }
    clearCapture();
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, args: ['--no-first-run'] });
    try {
        const P = {};
        for (const r of Object.keys(ACC)) P[r] = await as(r);

        // ---------------------------------------------- import with a Gmail column
        await P.super.goto(BASE + 'gb_members.php');
        await post(P.super, 'gb_members.php', { action: 'import_sheet' });
        sql("UPDATE members SET position='SB' WHERE university_id='21100003'");
        const mem = sid => sql(`SELECT CONCAT_WS('|', email, IFNULL(gsuite_email,'-')) FROM members WHERE university_id='${sid}'`);
        ok('import fills the personal Gmail from the sheet', mem('21100001') === 'gina.body@example.com|gina@g.example.edu', mem('21100001'));
        ok('member with only Gmail is imported with no G-Suite', mem('21100005') === 'nora@example.com|-', mem('21100005'));
        ok('member with only G-Suite keeps a placeholder personal e-mail', mem('21100002') === '21100002@members.bucuc.local|asha@g.example.edu', mem('21100002'));
        // a member with neither address
        sql("INSERT INTO members (full_name, university_id, email, gsuite_email, department, phone, semester, gender, position) VALUES ('No Mail', '21100099', '21100099@members.bucuc.local', NULL, '', '', '', '', 'GB')");

        // ---------------------------------------------- sender per role
        await P.super.goto(BASE + 'notices.php');
        ok('Super Admin sends from club.bucuc@g.bracu.ac.bd (shown before sending)', (await P.super.$eval('.sender', e => e.textContent)).includes('club.bucuc@g.bracu.ac.bd') && (await P.super.$eval('.sender', e => e.textContent)).includes('1,500'));
        await P.panel.goto(BASE + 'notices.php');
        ok('Panel Admin sends from club.bucuc@g.bracu.ac.bd', (await P.panel.$eval('.sender', e => e.textContent)).includes('club.bucuc@g.bracu.ac.bd'));
        await P.hr.goto(BASE + 'notices.php');
        const hrSender = await P.hr.$eval('.sender', e => e.textContent);
        ok('HR sends from hr.bucuc@gmail.com with a 400/day limit', hrSender.includes('hr.bucuc@gmail.com') && hrSender.includes('limit 400'), hrSender.replace(/\s+/g, ' '));
        const html = await P.hr.content();
        ok('no password is shown anywhere on the page', !/not-used-in-tests|App Password:|password_const|HR_SMTP_PASSWORD/i.test(html));
        ok('counts show members and e-mails', (await P.hr.$eval('.aud', e => e.textContent)).includes('5 members · 7 e-mails'), (await P.hr.$eval('.aud', e => e.textContent)).replace(/\s+/g, ' '));
        ok('members with neither address are counted as skipped', html.includes('1 member(s) have neither and are skipped'));

        // ---------------------------------------------- both addresses, counted as e-mails
        clearCapture();
        let r = await post(P.hr, 'notices.php', { action: 'create', audience: 'all', subject: 'Hello {first_name}', body: 'Dear {name}' });
        let nid = noticeId(r);
        ok('notice stores 7 e-mails for 5 members, from the HR address', +sql(`SELECT total FROM notices WHERE id=${nid}`) === 7 && +sql(`SELECT members FROM notices WHERE id=${nid}`) === 5 && sql(`SELECT sender_email FROM notices WHERE id=${nid}`) === 'hr.bucuc@gmail.com');
        await P.hr.goto(BASE + `notices.php?run=${nid}`);
        await P.hr.waitForFunction(() => /Finished/.test(document.getElementById('runMsg').textContent), { timeout: 20000 });
        ok('progress counts e-mails (7 sent)', (await P.hr.$eval('#nSent', e => e.textContent)) === '7' && (await P.hr.$eval('#nTotal', e => e.textContent)) === '7');
        const mails = captured();
        const to = mails.map(m => m.to).sort();
        ok('Gina gets it at Gmail AND G-Suite', to.includes('gina.body@example.com') && to.includes('gina@g.example.edu'));
        ok('Asha (G-Suite only) and Nora (Gmail only) get it at the one they have', to.includes('asha@g.example.edu') && to.includes('nora@example.com') && !to.some(t => t.includes('members.bucuc.local')));
        ok('every e-mail is from hr.bucuc@gmail.com', mails.length === 7 && mails.every(m => m.from === 'hr.bucuc@gmail.com'));
        ok('each copy is personalised', mails.filter(m => m.to.startsWith('gina')).every(m => m.subject === 'Hello Gina' && m.text === 'Dear Gina Body'));
        const log = sql("SELECT CONCAT_WS(' | ', admin_name, action, target, details) FROM admin_activity_log WHERE action IN ('notice_sent','notice_finished') ORDER BY id");
        ok('activity log records the sending address', /notice_sent \| All members \| 7 e-mail\(s\) to 5 member\(s\) from hr\.bucuc@gmail\.com; subject "Hello \{first_name\}"/.test(log) && /notice_finished .*from hr\.bucuc@gmail\.com/.test(log), log);
        await P.hr.goto(BASE + 'notices.php');
        ok('sent notices list shows the From address', (await P.hr.content()).includes('<td class="note">hr.bucuc@gmail.com</td>'));

        // ---------------------------------------------- daily limit is per account
        sql(`INSERT INTO notice_recipients (notice_id, name, email, status, sent_at) SELECT ${nid}, 'x', 'x@x.x', 'sent', NOW() FROM (SELECT 1 FROM information_schema.columns LIMIT 393) t`);
        r = await post(P.hr, 'notices.php', { action: 'create', audience: 'group', group: 'GB', subject: 'GB news', body: 'Hi {name}' });
        let d = await batchUntilDone(P.hr, noticeId(r));
        ok('HR account pauses at 400 e-mails a day', d.limitReached === true && d.sent === 0, JSON.stringify(d));
        clearCapture();
        r = await post(P.super, 'notices.php', { action: 'create', audience: 'group', group: 'GB', subject: 'GB news', body: 'Hi {name}' });
        d = await batchUntilDone(P.super, noticeId(r));
        ok('…while the club account still sends (separate limits)', d.done && d.sent === 4 && captured().every(m => m.from === 'club.bucuc@g.bracu.ac.bd'), JSON.stringify(d));
        sql(`DELETE FROM notice_recipients WHERE notice_id=${nid} AND email='x@x.x'`);

        // ---------------------------------------------- MIAP with permission uses the configured account
        const miapId = sql("SELECT id FROM adminpanel WHERE email='miap@test.local'");
        await P.panel.goto(BASE + 'admin_management.php');
        await post(P.panel, 'admin_management.php', { action: 'save_permissions', admin_id: miapId, 'perms[]': ['upload_carousel', 'upload_panel', 'upload_events', 'upload_hero', 'send_notices'] });
        await P.miap.goto(BASE + 'notices.php');
        ok('MIAP (with permission) sees its sender address', (await P.miap.$eval('.sender', e => e.textContent)).includes('club.bucuc@g.bracu.ac.bd'));

        // ---------------------------------------------- homepage stats: manual only
        await P.hr.goto(BASE + 'home_stats.php');
        const statsPage = await P.hr.content();
        ok('no "automatic" behaviour on the page', !/automatic/i.test(statsPage) && (await P.hr.$$('input[type=number][required]')).length === 4);
        r = await post(P.hr, 'home_stats.php', { semester: 'Summer 2026', 'members[value]': '520', 'shows[value]': '42', 'applicants[value]': '375', 'rejections[value]': '60',
            'members[visible]': '1', 'shows[visible]': '1', 'applicants[visible]': '1' });
        ok('HR saves the four numbers and the semester label', alertText(r.text).includes('saved'), alertText(r.text));
        let home = await (await (await as(null)).goto(BASE + 'index.php')).text();
        ok('homepage shows exactly the typed numbers', homeStat(home, 'members')?.value === '520' && homeStat(home, 'shows')?.value === '42' && homeStat(home, 'applicants')?.value === '375');
        ok('semester label is the typed one', homeStat(home, 'applicants')?.label === 'Applicants · Summer 2026');
        ok('hidden stat is not shown', !homeStat(home, 'rejections'));
        // database changes must not move the homepage numbers
        sql("INSERT INTO application_events (kind, university_id, applied_at) VALUES ('applied','x',NOW()), ('rejected','y',NOW())");
        sql("INSERT INTO members (full_name, university_id, email, department, phone, semester, gender) VALUES ('Extra', '21109999', 'extra@x.com', '', '', '', '')");
        home = await (await (await as(null)).goto(BASE + 'index.php')).text();
        ok('new members / applications do not change the homepage', homeStat(home, 'members')?.value === '520' && homeStat(home, 'applicants')?.value === '375');
        ok('application / rejection records are still kept internally', +sql("SELECT COUNT(*) FROM application_events") >= 2);
        const slog = sql("SELECT details FROM admin_activity_log WHERE action='home_stats_changed' ORDER BY id DESC LIMIT 1");
        ok('save is logged with old and new values', /Members to date: 520 \(was 0\).*Rejections hidden.*Semester label: "Summer 2026"/.test(slog), slog);
        r = await post(P.hr, 'home_stats.php', { semester: '', 'members[value]': '', 'shows[value]': '1', 'applicants[value]': '1', 'rejections[value]': '1' });
        ok('blank or non-number is refused', alertText(r.text).includes('whole number'));
        for (const role of ['miap', 'sec']) {
            await P[role].goto(BASE + 'home_stats.php');
            ok(`${role} cannot manage homepage stats`, P[role].url().includes('permission'));
        }

        for (const [k, p] of Object.entries(P)) if (p.errors.length) ok(`no JS errors (${k})`, false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        sql("DELETE FROM admin_permissions");
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
