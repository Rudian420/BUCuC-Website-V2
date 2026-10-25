// The Super Admin is invisible to everyone else: after the Super Admin has used every feature, no other admin (and no
// visitor) finds the account's name, e-mail or even the words "Super Admin" on any page they can open, including
// error messages, filters and the page source.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 400) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], panel: ['panel@test.local', 'panelpass123'], hr: ['admin@test.local', 'adminpass123'], miap: ['miap@test.local', 'miappass123'], sec: ['sec@test.local', 'secpass1234'] };
// the Super Admin's account name and e-mail, and the role's name in any spelling
const NEEDLES = [/Super Tester/, /super@test\.local/i, /super/i];
const SKIP = /(go\.php|handle_application|logout\.php|super_admin_dashboard)/i;

let browser;
async function as(role) {
    const page = await (await browser.createBrowserContext()).newPage();
    page.on('dialog', d => d.dismiss());
    await page.setViewport({ width: 1280, height: 900 });
    if (role) {
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', ACC[role][0]); await page.type('#adminPassword', ACC[role][1]);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}
const post = (p, url, data) => p.evaluate(async (url, data) => {
    const fd = new FormData(); fd.append('csrf_token', document.querySelector('meta[name=csrf-token]').content);
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    return (await fetch(url, { method: 'POST', body: fd })).status;
}, url, data);

(async () => {
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 120000 });
    try {
        // ---------------------------------------------- the Super Admin uses the site
        const S = await as('super');
        const sid = sql("SELECT id FROM adminpanel WHERE email='super@test.local'");
        await S.goto(BASE + 'home_stats.php'); await post(S, 'home_stats.php', { semester: 'Probe Semester' });
        await S.goto(BASE + 'admin-signup-control.php'); await post(S, 'admin-signup-control.php', { signup_status: '1' });
        await S.goto(BASE + 'gb_members.php'); await post(S, 'gb_members.php', { action: 'import_sheet' });
        await S.goto(BASE + 'notices.php'); await post(S, 'notices.php', { action: 'create', audience: 'all', subject: 'Probe notice', body: 'Dear {name}' });
        await S.goto(BASE + 'media.php?tab=events'); await post(S, 'media.php?tab=events', { action: 'event_add', title: 'Probe event', event_date: '2026-01-05', place: 'Hall', description: 'Text', highlights: '' });
        await S.goto(BASE + 'handover.php');
        const doc = sql('SELECT id FROM handover_docs ORDER BY id DESC LIMIT 1');
        // authorship rows the Super Admin leaves behind in every feature
        sql(`INSERT INTO handover_versions (doc_id, section_key, content, saved_by, saved_by_role, saved_at) VALUES (${doc}, 'summary', 'Probe text', ${sid}, 'super_admin', NOW());
             INSERT INTO handover_sections (doc_id, section_key, content, version_id, updated_by, updated_by_role, updated_at) VALUES (${doc}, 'summary', 'Probe text', LAST_INSERT_ID(), ${sid}, 'super_admin', NOW())
             ON DUPLICATE KEY UPDATE content=VALUES(content), updated_by=VALUES(updated_by), updated_by_role=VALUES(updated_by_role)`);
        sql(`INSERT INTO attendance_sessions (title, type, session_date, start_time, audience, created_by) VALUES ('Probe rehearsal', 'rehearsal', CURDATE(), '10:00', 'all', ${sid})`);
        const att = sql('SELECT MAX(id) FROM attendance_sessions');
        sql(`INSERT IGNORE INTO attendance_records (session_id, member_id, status, method, marked_by, marked_at) SELECT ${att}, id, 'present', 'manual', ${sid}, NOW() FROM members LIMIT 3`);
        sql(`INSERT INTO site_lockdown (starts_at, notice, status, created_by) VALUES (DATE_ADD(NOW(), INTERVAL 3 DAY), 'Probe maintenance', 'scheduled', ${sid})`);
        sql(`INSERT INTO site_settings (name, value) VALUES ('palette_history', '[{"roles":{"primary":"#8b5cff"},"main":null,"manual":false,"saved_at":"2026-10-19 10:00:00","saved_by":"Super Tester","saved_by_role":"super_admin","saved_by_id":${sid}}]') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
        sql(`INSERT INTO admin_activity_log (admin_id, admin_name, actor_role, action, details, target, ip) VALUES (${sid}, 'Super Tester', 'super_admin', 'super_recovered', 'Recovered', 'super@test.local', '127.0.0.1')`);
        ok('the Super Admin left activity behind', +sql(`SELECT COUNT(*) FROM admin_activity_log WHERE admin_id=${sid}`) >= 5);
        const nid = sql('SELECT MAX(id) FROM notices');

        // ---------------------------------------------- everyone else looks everywhere
        for (const role of [null, 'panel', 'hr', 'miap', 'sec']) {
            const p = await as(role);
            const queue = role
                ? [p.url(), BASE + `handover.php?doc=${doc}&history=summary`, BASE + `handover_print.php?doc=${doc}`, BASE + `attendance_session.php?id=${att}`, BASE + `notices.php?run=${nid}`, BASE + 'theme.php', BASE + 'admin_management.php', BASE + 'activity_log.php?page=1', BASE + 'lockdown.php', BASE + 'sponsors.php', BASE + 'recover.php']
                : ['index.php', 'past_events.php', 'performances.php', 'admin-login.php', 'forgot-password.php', 'checkin.php', 'recover.php', 'sponsors.php', '404.php'].map(x => BASE + x);
            const done = new Set(); const hits = [];
            while (queue.length) {
                const u = queue.shift().split('#')[0];
                if (!u.startsWith(BASE) || SKIP.test(u) || done.has(u) || done.size >= 120) continue;
                done.add(u);
                let html = '';
                try {
                    const r = await p.goto(u, { waitUntil: 'load', timeout: 20000 });
                    const ct = r ? (r.headers()['content-type'] || '') : '';
                    if (!/html|json|text/.test(ct)) continue;   // Excel downloads hold member data only
                    html = await p.content();
                    for (const l of await p.$$eval('a[href]', a => a.map(x => x.href))) if (l.startsWith(BASE) && !done.has(l.split('#')[0])) queue.push(l);
                } catch (e) { continue; }
                for (const n of NEEDLES) {
                    const m = html.match(new RegExp('.{0,60}' + n.source + '.{0,40}', 'g' + (n.flags.includes('i') ? 'i' : '')));
                    if (m) hits.push(`${u.replace(BASE, '')}: ${m[0].replace(/\s+/g, ' ')}`);
                }
            }
            ok(`${role || 'visitor'}: nothing on ${done.size} pages names or hints at the Super Admin`, hits.length === 0, [...new Set(hits)].slice(0, 6).join(' || '));
            await p.browserContext().close();
        }
    } catch (e) {
        ok('suite ran without crashing', false, e.stack);
    } finally {
        await browser.close();
        console.log(results.join('\n'));
        console.log(`\n${results.filter(r => r.startsWith('PASS')).length}/${results.length} passed`);
    }
})();
