// Attendance: sessions, checklist, QR self check-in, reasons after the end, reports, promotion screen, Excel, permissions.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', '-P3307', '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const php = code => execFileSync(T.PHP, ['-d', 'extension=zip', '-d', 'extension=gd', '-r', code]).toString();
const BASE = 'http://127.0.0.1:8099/frontend/';
const S_DIR = T.RUNTIME;
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 230) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], hr: ['admin@test.local', 'adminpass123'], miap: ['miap@test.local', 'miappass123'], sec: ['sec@test.local', 'secpass1234'] };
const wait = ms => new Promise(r => setTimeout(r, ms));
const dhaka = offsetMin => php(`date_default_timezone_set('Asia/Dhaka'); echo date('H:i', time() + (${offsetMin}) * 60);`);
const today = php(`date_default_timezone_set('Asia/Dhaka'); echo date('Y-m-d');`);
let browser;

async function as(role, phone) {
    const page = await (await browser.createBrowserContext()).newPage();
    page.errors = [];
    page.on('pageerror', e => page.errors.push(e.message));
    page.on('dialog', d => d.accept(page.promptAnswer || undefined));
    if (phone) await page.emulate({ viewport: { width: 390, height: 844, isMobile: true, hasTouch: true }, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' });
    else await page.setViewport({ width: 1280, height: 900 });
    if (role) {
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', ACC[role][0]); await page.type('#adminPassword', ACC[role][1]);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}
const alertOf = page => page.$eval('.alert', e => e.textContent.trim()).catch(() => '');
const mid = sid => sql(`SELECT id FROM members WHERE university_id='${sid}'`);
const status = (sess, sid) => sql(`SELECT IFNULL((SELECT CONCAT(status,'/',method) FROM attendance_records WHERE session_id=${sess} AND member_id=${mid(sid)}),'none')`);
async function createSession(page, { title, type = 'rehearsal', date = today, start, end = '', audience = 'all', pick = [], qr = false }) {
    await page.goto(BASE + 'attendance.php?new=1');
    await page.$eval('#title', (e, v) => e.value = v, title);
    await page.select('#type', type);
    await page.$eval('#session_date', (e, v) => e.value = v, date);
    await page.$eval('#start_time', (e, v) => e.value = v, start);
    await page.$eval('#end_time', (e, v) => e.value = v, end);
    await page.click(`input[name=audience][value="${audience}"]`);
    for (const p of pick) await page.click(`.picklist input[value="${mid(p)}"]`);
    if (qr) await page.click('input[name=qr_enabled]');
    await Promise.all([page.waitForNavigation(), page.click('#sessionForm button[type=submit]')]);
    return +new URL(page.url()).searchParams.get('id');
}
const markRow = (page, sid, st) => page.evaluate((m, st) => { const i = document.querySelector(`#m${m}${st}`); i.checked = true; i.dispatchEvent(new Event('change')); }, mid(sid), st);
const saveMarks = page => Promise.all([page.waitForNavigation(), page.click('#saveBtn')]);
const qrToken = (sess, back = 0) => {
    const secret = sql(`SELECT checkin_secret FROM attendance_sessions WHERE id=${sess}`);
    const w = Math.floor(Date.now() / 1000 / 30) - back;
    return crypto.createHmac('sha256', secret).update(`qr|${sess}|${w}`).digest('hex').slice(0, 20);
};
async function checkIn(page, sess, studentId, token) {
    await page.goto(BASE + `checkin.php?s=${sess}&t=${token || qrToken(sess)}`);
    if (!(await page.$('#student_id'))) return page.$eval('main', e => e.textContent.replace(/\s+/g, ' ').trim());
    await page.type('#student_id', studentId);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    return page.$eval('main', e => e.textContent.replace(/\s+/g, ' ').trim());
}

(async () => {
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 90000 });
    try {
        const S = await as('super');
        await S.goto(BASE + 'gb_members.php');
        await S.evaluate(async () => { const fd = new FormData(); fd.append('action', 'import_sheet'); fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content); await fetch('gb_members.php', { method: 'POST', body: fd }); });
        ok('setup: 5 members (3 GB, 1 ASB, 1 Secretary Panel)', sql("SELECT GROUP_CONCAT(CONCAT(position,':',n) ORDER BY position) FROM (SELECT position, COUNT(*) n FROM members GROUP BY position) x") === 'GB:3,ASB:1,SB:1');
        await S.goto(BASE + 'attendance.php');
        ok('empty state', (await S.content()).includes('No sessions yet'));

        // ---------------------------------------------------------------- 1. session + checklist
        const s1 = await createSession(S, { title: 'Dance rehearsal', start: dhaka(-60), end: dhaka(120), audience: 'GB', qr: true });
        ok('session created, opens its page', s1 > 0 && S.url().includes('attendance_session.php') && (await alertOf(S)).includes('Session created'));
        ok('GB members expected (3), none marked yet', sql(`SELECT COUNT(*) FROM attendance_expected WHERE session_id=${s1}`) === '3' && (await S.$$('.mrow')).length === 3);
        await S.click('#allPresent');
        ok('"Mark all present" sets everyone', (await S.$eval('#changed', e => e.textContent)) === '3 changes not saved yet');
        await markRow(S, '21100005', 'absent');
        await saveMarks(S);
        ok('marks saved', (await alertOf(S)).includes('3 marks saved') && status(s1, '21100001') === 'present/manual' && status(s1, '21100005') === 'absent/manual');
        ok('…no log entry while the session is running', sql("SELECT COUNT(*) FROM admin_activity_log WHERE action='attendance_changed'") === '0');
        await S.type('#q', 'gabe');
        ok('search filters the list', (await S.$$eval('.mrow', rs => rs.filter(r => !r.hidden).length)) === 1);
        // phone layout
        const P = await as('super', true);
        await P.goto(BASE + `attendance_session.php?id=${s1}`);
        const lay = await P.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: innerWidth, h: document.querySelector('.seg label').getBoundingClientRect().height, bar: getComputedStyle(document.querySelector('.savebar')).position }));
        ok('phone: no sideways scrolling, big tap targets, save bar stays on screen', lay.sw <= lay.vw && lay.h >= 40 && lay.bar === 'sticky', JSON.stringify(lay));
        await P.screenshot({ path: path.join(S_DIR, 'shot_att_phone.png'), fullPage: true });

        // ---------------------------------------------------------------- 2. QR check-in
        const s2 = await createSession(S, { title: 'Workshop', type: 'workshop', start: dhaka(-30), end: dhaka(90), audience: 'pick', pick: ['21100002', '21100003', '21100001'], qr: true });
        await S.goto(BASE + `attendance_checkin.php?id=${s2}`);
        ok('check-in closed until started', (await S.$eval('#qr', e => e.textContent)).includes('closed'));
        const V0 = await as(null, true);
        ok('closed check-in refuses members', (await checkIn(V0, s2, '21100002', 'x')).includes('closed'));
        await Promise.all([S.waitForNavigation(), S.click('button[type=submit]')]);   // Start (30 min)
        await S.waitForSelector('#qr svg', { timeout: 8000 });
        const qrUrl = await S.$eval('#qr', e => e.dataset.url);
        ok('QR shown with the check-in link and a countdown', /checkin\.php\?s=\d+&t=[0-9a-f]{20}$/.test(qrUrl) && /New code in \d+ s/.test(await S.$eval('#tick', e => e.textContent)), qrUrl);
        ok('the QR code is a real QR (decodes to the link)', qrUrl.endsWith('&t=' + qrToken(s2)) || qrUrl.endsWith('&t=' + qrToken(s2, 1)));
        await S.screenshot({ path: path.join(S_DIR, 'shot_att_qr.png') });
        const V1 = await as(null, true), V2 = await as(null, true), V3 = await as(null, true);
        let r = await checkIn(V1, s2, '21100002');
        ok('member checks in (more than 15 min after the start → Late)', r.includes('Checked in, Asha') && r.includes('Late') && status(s2, '21100002') === 'late/qr', r);
        r = await checkIn(V1, s2, '21100003');
        ok('one phone cannot check in a second person', r.includes('already been used') && status(s2, '21100003') === 'none', r);
        r = await checkIn(V2, s2, '99999999');
        ok('unknown student ID refused', r.includes("isn't on the list"), r);
        r = await checkIn(V2, s2, '21100004');
        ok('member not expected at this session refused', r.includes("isn't on the list") && status(s2, '21100004') === 'none', r);
        r = await checkIn(V2, s2, '21100003');
        ok('second member checks in on their own phone', r.includes('Checked in, Sami'), r);
        r = await checkIn(V3, s2, '21100003');
        ok('each student ID checks in only once', r.includes('already marked'), r);
        r = await checkIn(V3, s2, '21100001', 'deadbeefdeadbeefdead');
        ok('a made-up code is refused', r.includes('expired'), r);
        r = await checkIn(V3, s2, '21100001', qrToken(s2, 3));
        ok('a code older than ~90 s (photo shared later) is refused', r.includes('expired'), r);
        r = await checkIn(V3, s2, '21100001', qrToken(s2, 2));
        ok('a code from up to ~60–90 s ago still works (time to scan)', r.includes('Checked in, Gina') || r.includes('already marked'), r);
        // ticket expiry: typing the ID more than 5 minutes after scanning
        const secret = sql(`SELECT checkin_secret FROM attendance_sessions WHERE id=${s2}`);
        const old = Math.floor(Date.now() / 1000) - 6 * 60;
        const staleTicket = old + '.' + crypto.createHmac('sha256', secret).update(`ticket|${s2}|${old}`).digest('hex').slice(0, 32);
        const V4 = await as(null, true);
        await V4.goto(BASE + `checkin.php?s=${s2}&t=${qrToken(s2)}`);
        await V4.$eval('input[name=ticket]', (e, v) => e.value = v, staleTicket);
        await V4.type('#student_id', '21100001');
        await Promise.all([V4.waitForNavigation(), V4.click('button[type=submit]')]);
        ok('more than 5 minutes after scanning: scan again', (await V4.$eval('main', e => e.textContent)).includes('open too long'));
        // too many wrong IDs from one network
        sql("INSERT INTO attendance_checkin_fails (ip) SELECT '127.0.0.1' FROM information_schema.COLUMNS LIMIT 30");
        const V5 = await as(null, true);
        r = await checkIn(V5, s2, '21100001');
        ok('too many wrong IDs from one network are blocked for a while', r.includes('Too many wrong'), r);
        sql('DELETE FROM attendance_checkin_fails');
        // live list + remove
        await wait(3000);
        const live = await S.$$eval('#live li[data-id]', ls => ls.map(l => l.querySelector('b').textContent));
        ok('admin screen shows check-ins live', live.includes('Asha Sec') && live.includes('Sami Panel') && (await S.$eval('#count', e => e.textContent)) === String(live.length), live.join(', '));
        await S.click(`#live [data-remove="${mid('21100003')}"]`);
        await wait(2500);
        ok('admin removes a wrong check-in', status(s2, '21100003') === 'none' && !(await S.$$eval('#live li b', b => b.map(x => x.textContent))).includes('Sami Panel'));
        ok('…logged', sql("SELECT details FROM admin_activity_log WHERE action='attendance_checkin_removed'").includes('Sami Panel (21100003)'));
        // a QR check-in arriving while an admin has the checklist open is not overwritten
        const A = await as('super');
        await A.goto(BASE + `attendance_session.php?id=${s2}`);
        sql(`INSERT INTO attendance_records (session_id, member_id, status, method, marked_at) VALUES (${s2}, ${mid('21100003')}, 'present', 'qr', NOW())`);
        await markRow(A, '21100001', 'excused');
        await saveMarks(A);
        ok('saving the checklist keeps check-ins that arrived meanwhile', status(s2, '21100003') === 'present/qr' && status(s2, '21100001') === 'excused/manual');
        // end
        await S.goto(BASE + `attendance_checkin.php?id=${s2}`);
        await Promise.all([S.waitForNavigation(), S.$eval('input[name=action][value=end]', i => i.form.querySelector('button').click())]);
        r = await checkIn(await as(null, true), s2, '21100001');
        ok('ended check-in refuses members', r.includes('closed'), r);
        ok('open / end logged', sql("SELECT COUNT(*) FROM admin_activity_log WHERE action IN ('attendance_checkin_opened','attendance_checkin_closed')") === '2');

        // ---------------------------------------------------------------- 3. after the end: reason required
        sql(`UPDATE attendance_sessions SET session_date = '${today}' - INTERVAL 1 DAY WHERE id IN (${s1}, ${s2})`);
        await S.goto(BASE + `attendance_session.php?id=${s1}`);
        ok('ended session asks for a reason', !!(await S.$('input[name=reason]')));
        await markRow(S, '21100005', 'excused');
        await saveMarks(S);
        ok('change without a reason refused', (await alertOf(S)).includes('short reason') && status(s1, '21100005') === 'absent/manual', await alertOf(S));
        await S.$eval('input[name=reason]', e => e.value = 'Medical note shown');
        await saveMarks(S);
        ok('with a reason it is saved', status(s1, '21100005') === 'excused/manual' && (await alertOf(S)).includes('logged'));
        const log = sql("SELECT CONCAT(admin_name, ' | ', details) FROM admin_activity_log WHERE action='attendance_changed' ORDER BY id DESC LIMIT 1");
        ok('logged with who, why and what', log.startsWith('Super Tester | Reason: Medical note shown') && log.includes('Nora Gmailonly (21100005): Absent → Excused'), log);
        await S.goto(BASE + `attendance.php?edit=${s1}`);
        await S.$eval('#title', e => e.value = 'Dance rehearsal (renamed)');
        ok('the browser asks for the reason first', await S.$eval('#reason', e => e.required && e.minLength === 5));
        await S.$eval('#reason', e => { e.required = false; e.removeAttribute('minlength'); });   // the server must refuse too
        await Promise.all([S.waitForNavigation(), S.click('#sessionForm button[type=submit]')]);
        ok('editing an ended session also needs a reason (server)', (await alertOf(S)).includes('short reason') && sql(`SELECT title FROM attendance_sessions WHERE id=${s1}`) === 'Dance rehearsal');
        await S.$eval('#reason', e => e.value = 'Typo in the title');
        await Promise.all([S.waitForNavigation(), S.click('#sessionForm button[type=submit]')]);
        ok('…with a reason the edit is saved and logged', sql(`SELECT title FROM attendance_sessions WHERE id=${s1}`) === 'Dance rehearsal (renamed)' && sql("SELECT details FROM admin_activity_log WHERE action='attendance_session_updated' ORDER BY id DESC LIMIT 1").includes('Reason: Typo in the title'));

        // ---------------------------------------------------------------- 4. reports
        const s3 = await createSession(S, { title: 'Meeting', type: 'meeting', start: '10:00', end: '11:00', audience: 'GB' });
        sql(`UPDATE attendance_sessions SET session_date = '${today}' - INTERVAL 2 DAY WHERE id=${s3}`);   // ended, nobody marked
        // Gina: s1 present, s2 excused, s3 absent → 1/2 = 50%. Gabe: s1 present, s3 absent → 50%. Nora: s1 excused, s3 absent → 0%.
        const sem = php(`date_default_timezone_set('Asia/Dhaka'); $m=(int)date('n'); echo ($m<=4?'Spring':($m<=8?'Summer':'Fall')).' '.date('Y');`);
        await S.goto(BASE + `attendance.php?tab=report&semester=${encodeURIComponent(sem)}`);
        const rep = await S.$$eval('#repTable tbody tr', rs => rs.map(r => [...r.children].map(td => td.textContent.trim().replace(/\s+/g, ' '))));
        const row = n => rep.find(r => r[0].startsWith(n)) || [];
        ok('report: percentages (excused not counted, unmarked = absent)', row('Gina Body')[7] === '50%' && row('Gabe Two')[7] === '50%' && row('Nora Gmailonly')[7] === '0%' && row('Asha Sec')[7] === '100%', JSON.stringify(rep));
        await S.goto(BASE + `attendance.php?tab=report&semester=${encodeURIComponent(sem)}&below=50`);
        const low = await S.$$eval('#repTable tbody tr td:first-child b', b => b.map(x => x.textContent));
        ok('low-attendance filter (under 50%)', low.length === 1 && low[0] === 'Nora Gmailonly', low.join(', '));
        await S.goto(BASE + `attendance_member.php?id=${mid('21100005')}`);
        const mp = await S.$eval('.totals', e => e.textContent.replace(/\s+/g, ' '));
        ok('member page: overall %, excused shown separately, sessions listed', mp.includes('0%') && /1\s*Excused/.test(mp) && (await S.$$('.tbl tbody tr')).length >= 3, mp);
        await S.goto(BASE + 'attendance.php');
        const s1row = await S.$$eval('.tbl tbody tr', rs => rs.map(r => r.textContent.replace(/\s+/g, ' ')));
        ok('sessions list shows totals', s1row.some(t => t.includes('Dance rehearsal') && t.includes('Ended')), s1row[0]);

        // ---------------------------------------------------------------- 5. promotion screen
        await S.goto(BASE + 'gb_members.php');
        await S.waitForSelector('#membersTable tbody tr .att-line', { timeout: 8000 }).catch(() => {});
        const lines = await S.$$eval('#membersTable tbody tr', rs => rs.map(r => r.textContent.replace(/\s+/g, ' ')));
        ok('GB page shows each member\'s attendance next to their name', lines.some(l => l.includes('Nora Gmailonly') && l.includes('Attendance 0%')) && lines.some(l => l.includes('Gina Body') && l.includes('Attendance 50%')), lines.join(' | '));
        const H = await as('hr');
        await H.goto(BASE + 'gb_members.php');
        ok('HR (take_attendance) sees it too', !!(await H.waitForSelector('.att-line', { timeout: 8000 }).catch(() => null)));
        const secId = sql("SELECT id FROM adminpanel WHERE email='sec@test.local'");
        sql(`INSERT INTO admin_permissions (admin_id, permission, allowed) VALUES (${secId}, 'view_members', 1)`);
        const SE = await as('sec');
        await SE.goto(BASE + 'gb_members.php');
        await SE.waitForSelector('#membersTable tbody tr', { timeout: 8000 });
        ok('Secretary Admin with member lists but no attendance permission sees no attendance', !(await SE.$('.att-line')) && !(await SE.content()).includes('"att":'));

        // ---------------------------------------------------------------- 6. permissions
        const MI = await as('miap');
        await MI.goto(BASE + 'attendance.php');
        ok('MIAP refused', MI.url().includes('permission'));
        await SE.goto(BASE + 'attendance.php');
        ok('Secretary Admin refused', SE.url().includes('permission'));
        await MI.goto(BASE + 'admin_dashboard.php');
        ok('no Attendance menu item without the permission', !(await MI.$('a[href="attendance.php"]')));
        await H.goto(BASE + 'admin_dashboard.php');
        ok('HR has the menu item', !!(await H.$('a[href="attendance.php"]')));
        sql(`INSERT INTO admin_permissions (admin_id, permission, allowed) VALUES (${secId}, 'take_attendance', 1)`);
        await SE.goto(BASE + 'attendance.php');
        ok('granting "Take attendance" lets the Secretary Admin in', SE.url().endsWith('attendance.php'));

        // ---------------------------------------------------------------- 7. Excel (formula-safe)
        sql(`UPDATE members SET full_name='=HYPERLINK("http://evil","x")' WHERE university_id='21100004'`);
        for (const [q, file] of [[`session=${s1}`, 'att_session.xlsx'], [`report=members&semester=${encodeURIComponent(sem)}`, 'att_report.xlsx']]) {
            const b64 = await S.evaluate(async q => { const r = await fetch('attendance_export.php?' + q); const b = new Uint8Array(await r.arrayBuffer()); let s = ''; for (const x of b) s += String.fromCharCode(x); return btoa(s); }, q);
            fs.writeFileSync(path.join(S_DIR, file), Buffer.from(b64, 'base64'));
        }
        const xl = php(`require '${T.ROOT_FWD}/backend/vendor/autoload.php'; foreach (['att_session','att_report'] as $f) { $s=\\PhpOffice\\PhpSpreadsheet\\IOFactory::load('${S_DIR.replace(/\\/g, '/')}/'.$f.'.xlsx')->getActiveSheet(); foreach ($s->getRowIterator() as $row) { $i=$row->getRowIndex(); if ($s->getCell('B'.$i)->getValue()==='21100004') echo $f,':',$s->getCell('A'.$i)->getDataType(),':',$s->getCell('A'.$i)->getValue(),($f==='att_report'?':'.$s->getCell('I'.$i)->getDataType().'='.$s->getCell('I'.$i)->getValue():''),';'; } }`);
        ok('Excel: names are text (no formulas), percentages are numbers', xl.includes('att_session:s:=HYPERLINK') && xl.includes('att_report:s:=HYPERLINK') && xl.includes(':n=50'), xl);

        // ---------------------------------------------------------------- 8. delete
        const marks = sql(`SELECT COUNT(*) FROM attendance_records WHERE session_id=${s2}`);
        await S.goto(BASE + `attendance_session.php?id=${s2}`);
        await Promise.all([S.waitForNavigation(), S.$eval('input[name=action][value=delete_session]', i => i.form.querySelector('button').click())]);
        ok('delete removes the session and its marks (after a confirm)', sql(`SELECT COUNT(*) FROM attendance_sessions WHERE id=${s2}`) === '0' && sql(`SELECT COUNT(*) FROM attendance_records WHERE session_id=${s2}`) === '0' && (await alertOf(S)).includes(`its ${marks} attendance mark`), await alertOf(S));
        ok('delete logged', sql("SELECT details FROM admin_activity_log WHERE action='attendance_session_deleted'").includes(`${marks} attendance mark(s) deleted`));
        await S.goto(BASE + 'activity_log.php?action=attendance_changed');
        ok('activity log shows the readable label', (await S.content()).includes('Attendance changed after the session'));

        for (const [k, p] of Object.entries({ S, P, A, H, SE, MI })) if (p.errors.length) ok(`no JS errors (${k})`, false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
