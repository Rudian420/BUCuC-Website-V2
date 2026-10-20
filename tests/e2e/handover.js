// Handover notes: sections, roles, contacts permission, versions + restore, conflicts, search, lock/unlock,
// survival of the 12-tenure rule, reminders (captured mail), PDF page, dashboard card, log.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', '-P3307', '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const S_DIR = T.RUNTIME;
const CAPTURE = path.join(S_DIR, 'auth_mail_capture.jsonl');
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 230) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], panel: ['panel@test.local', 'panelpass123'], hr: ['admin@test.local', 'adminpass123'], miap: ['miap@test.local', 'miappass123'], sec: ['sec@test.local', 'secpass1234'] };
let browser;
async function as(role, phone) {
    const page = await (await browser.createBrowserContext()).newPage();
    page.errors = [];
    page.on('pageerror', e => page.errors.push(e.message));
    page.on('dialog', d => d.accept());
    if (phone) await page.emulate({ viewport: { width: 390, height: 844, isMobile: true, hasTouch: true }, userAgent: 'Mozilla/5.0 (iPhone)' });
    else await page.setViewport({ width: 1280, height: 900 });
    await page.goto(BASE + 'admin-login.php');
    await page.type('#adminEmail', ACC[role][0]); await page.type('#adminPassword', ACC[role][1]);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    return page;
}
const alertOf = page => page.$eval('.alert', e => e.textContent.trim()).catch(() => '');
const post = (page, fields) => page.evaluate(async fields => {
    const fd = new FormData(); fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    for (const [k, v] of Object.entries(fields)) fd.append(k, v);
    const r = await fetch('handover.php?doc=' + fields.doc, { method: 'POST', body: fd });
    return { url: r.url, text: await r.text() };
}, fields);
const docOf = label => sql(`SELECT id FROM handover_docs WHERE tenure_label='${label}'`);
const content = (doc, key) => sql(`SELECT IFNULL((SELECT content FROM handover_sections WHERE doc_id=${doc} AND section_key='${key}'),'')`);
async function editText(page, doc, key, text) {
    await page.goto(BASE + `handover.php?doc=${doc}&edit=${encodeURIComponent(key)}`);
    await page.$eval('textarea[name=text]', (e, v) => e.value = v, text);
    await Promise.all([page.waitForNavigation(), page.$eval('textarea[name=text]', e => e.form.querySelector('button[type=submit]').click())]);
}
async function editRows(page, doc, key, rows) {
    await page.goto(BASE + `handover.php?doc=${doc}&edit=${key}`);
    while ((await page.$$('.erow')).length < rows.length) await page.click('.addrow');
    await page.evaluate(rows => {
        const boxes = [...document.querySelectorAll('.erow')];
        boxes.forEach((b, i) => b.querySelectorAll('textarea').forEach(t => { const col = t.name.match(/\[(\w+)\]$/)[1]; t.value = (rows[i] || {})[col] || ''; }));
    }, rows);
    await Promise.all([page.waitForNavigation(), page.$eval('.erow', e => e.closest('form').querySelector('button[type=submit]').click())]);
}

(async () => {
    try { fs.unlinkSync(CAPTURE); } catch (e) { }
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 90000 });
    try {
        const S = await as('super'), P = await as('panel'), H = await as('hr'), M = await as('miap');
        await S.goto(BASE + 'handover.php');
        const cur = docOf('2026-27');
        ok('current tenure document opens, editable, with 9 sections', S.url().includes('handover.php') && (await S.content()).includes('Current tenure · editable') && (await S.$$('.panel.sec')).length === 9);
        ok('every section has its guiding prompt', (await S.$$eval('.panel.sec .prompt', ps => ps.filter(p => p.textContent.length > 20).length)) === 9);
        ok('accounts section warns: never passwords', (await S.$eval('#accounts .warnbox', e => e.textContent)).includes('Never write passwords'));
        await S.goto(BASE + 'super_admin_dashboard.php');
        ok('dashboard shows which sections are empty', (await S.$eval('.handover-card', e => e.textContent)).includes('9 sections are still empty'));

        // ---------- text section by HR (with Bangla)
        await editText(H, cur, 'summary', 'Rhythm Revive 25.1 was the biggest show. বৈশাখী উৎসব went well.\nMembers: 520.');
        ok('HR saves the summary', (await alertOf(H)).includes('saved') && content(cur, 'summary').includes('বৈশাখী'));
        ok('author shown by role, not name', (await H.$eval('#summary .meta', e => e.textContent)).includes('Secretary Admin (HR)') && !(await H.$eval('#summary', e => e.textContent)).includes('Admin Tester'));

        // ---------- tables: contacts, pending, accounts (password guard), links
        await editRows(P, cur, 'contacts', [{ name: 'Rafiq Uddin', organisation: 'SoundWave Ltd', role: 'Owner', contact: '01700000001', notes: 'Gives a 20% discount if booked early' }, { name: 'Ms. Tahsina', organisation: 'OCA', role: 'Joint Director', contact: 'oca@example.edu', notes: 'Approvals take 2 weeks' }]);
        ok('Panel saves contacts as a table', JSON.parse(content(cur, 'contacts')).length === 2);
        await editRows(P, cur, 'pending', [{ task: 'Pay SoundWave balance', notes: '15,000 tk by Dec' }, { task: 'Return borrowed costumes', notes: 'Dance dept' }]);
        ok('pending tasks saved with ids', JSON.parse(content(cur, 'pending')).every(r => r.id && r.done === false));
        await editRows(P, cur, 'accounts', [{ account: 'Facebook page', used_for: 'Announcements', holder: 'PR Secretary', notes: 'password: hunter2' }]);
        ok('a password in Accounts is refused', (await alertOf(P)).includes('password') && content(cur, 'accounts') === '', await alertOf(P));
        ok('…and the typed rows are kept in the form', (await P.$$eval('.erow textarea', t => t.map(x => x.value))).includes('Facebook page'));
        await editRows(P, cur, 'accounts', [{ account: 'Facebook page', used_for: 'Announcements', holder: 'PR Secretary', notes: '2FA on the PR phone' }]);
        ok('accounts saved without a password', JSON.parse(content(cur, 'accounts')).length === 1);
        await editRows(P, cur, 'links', [{ title: 'Sponsorship deck', url: 'ftp://x', notes: '' }]);
        ok('links must be http(s)', (await alertOf(P)).includes('https://'));
        await editRows(P, cur, 'links', [{ title: 'Sponsorship deck', url: 'https://drive.google.com/x', notes: '2026' }]);
        ok('link saved and clickable', !!(await P.$('#links a[href="https://drive.google.com/x"]')));

        // ---------- role spaces
        await editText(H, cur, 'role:hr_admin', 'Start recruitment planning in week 1.');
        ok('HR writes in the HR space', content(cur, 'role:hr_admin').includes('week 1'));
        await H.goto(BASE + `handover.php?doc=${cur}`);
        ok("HR has no Edit button for another role's space", !(await H.$('a[href*="edit=role%3Apanel_admin"]')) && !!(await H.$('a[href*="edit=role%3Ahr_admin"]')));
        let r = await post(H, { action: 'save_section', doc: cur, key: 'role:panel_admin', base: 0, text: 'hijack' });
        ok("forged edit of another role's space refused", content(cur, 'role:panel_admin') === '' && r.text.includes('edit this section'));
        ok("the Super Admin's role space is hidden from others", !(await H.$('#role-super_admin')) && !!(await S.goto(BASE + `handover.php?doc=${cur}`), await S.$('#role-super_admin')));

        // ---------- Super Admin shows as "an admin"
        await editText(S, cur, 'worked', 'Early sponsor outreach to SoundWave Ltd.');
        await H.goto(BASE + `handover.php?doc=${cur}`);
        ok('Super Admin edits show as "an admin" to others', (await H.$eval('#worked .meta', e => e.textContent)).includes('an admin') && (await S.$eval('#worked .meta', e => e.textContent)).includes('Super Admin'));

        // ---------- contacts permission
        await M.goto(BASE + `handover.php?doc=${cur}`);
        ok('MIAP reads the notes but not Contacts', !!(await M.$('#summary')) && !(await M.$('#contacts')) && !(await M.content()).includes('SoundWave Ltd</td>'));
        await M.goto(BASE + 'handover.php?q=Rafiq');
        ok('…search hides contacts from MIAP', (await M.$$('.result')).length === 0);
        r = await post(M, { action: 'save_section', doc: cur, key: 'contacts', base: 0, 'rows[0][name]': 'x' });
        ok('…and MIAP cannot write contacts', JSON.parse(content(cur, 'contacts')).length === 2);
        const Sec = await as('sec');
        await Sec.goto(BASE + `handover_print.php?doc=${cur}`);
        ok('Secretary Admin: PDF page without Contacts', !(await Sec.content()).includes('Rafiq') && (await Sec.content()).includes('Contacts are not included'));
        const miapId = sql("SELECT id FROM adminpanel WHERE email='miap@test.local'");
        sql(`INSERT INTO admin_permissions (admin_id, permission, allowed) VALUES (${miapId}, 'handover_contacts', 1)`);
        await M.goto(BASE + `handover.php?doc=${cur}`);
        ok('granting "See handover contacts" shows them to MIAP', !!(await M.$('#contacts')) && (await M.content()).includes('Rafiq Uddin'));

        // ---------- versions and restore
        await editText(H, cur, 'summary', 'Rewritten summary (oops, the old one was better).');
        await H.goto(BASE + `handover.php?doc=${cur}&history=summary`);
        const vers = await H.$$('#summary details.ver');
        ok('history lists every version with role and time', vers.length === 2 && (await H.$eval('#summary details.ver summary', e => e.textContent)).includes('Secretary Admin (HR)'));
        await Promise.all([H.waitForNavigation(), H.$eval('#summary details.ver:nth-of-type(2) form', f => f.querySelector('button').click())]);
        ok('an older version can be restored', content(cur, 'summary').startsWith('Rhythm Revive 25.1') && sql(`SELECT COUNT(*) FROM handover_versions WHERE doc_id=${cur} AND section_key='summary'`) === '3');
        ok('…and the replaced text is still in the history', sql(`SELECT COUNT(*) FROM handover_versions WHERE doc_id=${cur} AND section_key='summary' AND content LIKE 'Rewritten%'`) === '1');

        // ---------- two people editing the same section
        await H.goto(BASE + `handover.php?doc=${cur}&edit=didnt`);
        await editText(P, cur, 'didnt', 'Booking the hall too late.');
        await H.$eval('textarea[name=text]', e => e.value = 'HR version of what went wrong');
        await Promise.all([H.waitForNavigation(), H.$eval('textarea[name=text]', e => e.form.querySelector('button[type=submit]').click())]);
        ok('a save over someone else\'s newer save is refused, the text is kept', (await alertOf(H)).includes('Someone else saved') && (await H.$eval('textarea[name=text]', e => e.value)) === 'HR version of what went wrong' && content(cur, 'didnt') === 'Booking the hall too late.');

        // ---------- search across tenures
        const old = docOf('2025-26');
        await S.goto(BASE + `handover.php?doc=${old}&edit=summary`);
        ok('old tenure notes need an unlock first (not current)', !(await S.$('textarea[name=text]')) && (await S.content()).includes('Read-only'));
        await S.goto(BASE + `handover.php?doc=${old}`);
        await Promise.all([S.waitForNavigation(), S.$eval('input[name=action][value=unlock]', i => i.form.querySelector('button').click())]);
        await editText(S, old, 'summary', 'Previous year: SoundWave Ltd sponsored the sound for free.');
        ok('Super Admin unlocks an old tenure, then it can be edited', content(old, 'summary').includes('SoundWave'));
        await Promise.all([S.waitForNavigation(), S.$eval('input[name=action][value=lock]', i => i.form.querySelector('button').click())]);
        await S.goto(BASE + 'handover.php?q=soundwave');
        const found = await S.$$eval('.result b', b => b.map(x => x.textContent));
        ok('search finds a sponsor in every tenure and section', found.some(t => t.includes('2026-27') && t.includes('Contacts')) && found.some(t => t.includes('2025-26') && t.includes('Summary')) && found.some(t => t.includes('What worked')), found.join(' | '));
        await S.goto(BASE + 'handover.php?q=' + encodeURIComponent('বৈশাখী'));
        ok('search works in Bangla', (await S.$$('.result')).length === 1);
        r = await post(P, { action: 'unlock', doc: old });
        ok('only the Super Admin can unlock', r.text.includes('Only the Super Admin') && sql(`SELECT unlocked FROM handover_docs WHERE id=${old}`) === '0');

        // ---------- next tenure becomes current: old notes read-only, tasks still tickable
        sql("INSERT INTO tenures (label, start_year, is_current) VALUES ('2027-28', 2027, 0)");
        sql("UPDATE tenures SET is_current = (label = '2027-28')");
        await H.goto(BASE + `handover.php?doc=${cur}`);
        ok('after the next tenure is current, the old notes are read-only', (await H.content()).includes('Read-only') && !(await H.$('a[href*="&edit="]')));
        r = await post(H, { action: 'save_section', doc: cur, key: 'summary', base: sql(`SELECT version_id FROM handover_sections WHERE doc_id=${cur} AND section_key='summary'`), text: 'late change' });
        ok('…forged edits are refused too', !content(cur, 'summary').includes('late change') && r.text.includes('read-only'));
        await Promise.all([H.waitForNavigation(), H.$eval('#pending form button.tick', b => b.click())]);
        const pend = JSON.parse(content(cur, 'pending'));
        ok('the next panel ticks a pending task done on the old notes', pend[0].done === true && pend[0].done_role === 'hr_admin' && (await H.$eval('#pending tbody tr', tr => tr.className)).includes('done'));
        ok('…kept as a version and logged', sql(`SELECT note FROM handover_versions WHERE doc_id=${cur} AND section_key='pending' ORDER BY id DESC LIMIT 1`).startsWith('Done: Pay SoundWave') && sql("SELECT COUNT(*) FROM admin_activity_log WHERE action='handover_task_done'") === '1');
        const next = docOf('2027-28');
        ok('the new current tenure has its own empty document', !!next && (await H.goto(BASE + 'handover.php'), (await H.content()).includes('Tenure 2027-28')));

        // ---------- the 12-tenure rule never deletes notes
        sql(`DELETE FROM tenures WHERE label='2026-27'`);
        await S.goto(BASE + `handover.php?doc=${cur}`);
        ok('deleting a tenure keeps its handover notes', content(cur, 'summary').startsWith('Rhythm Revive') && sql(`SELECT IFNULL(tenure_id,'none') FROM handover_docs WHERE id=${cur}`) === 'none' && (await S.content()).includes('Tenure removed; notes kept'));

        // ---------- end date + reminder
        r = await post(H, { action: 'set_end', doc: next, ends_on: '2027-06-30' });
        ok('HR cannot set the end date', r.text.includes('Only the Super Admin or the Panel Admin'));
        const soon = sql("SELECT CURDATE() + INTERVAL 20 DAY");
        await P.goto(BASE + `handover.php?doc=${next}`);
        await P.$eval('#ends_on', (e, v) => e.value = v, soon);
        await Promise.all([P.waitForNavigation(), P.$eval('#ends_on', e => e.form.querySelector('button[type=submit]').click())]);
        ok('Panel Admin sets the end date', sql(`SELECT ends_on FROM handover_docs WHERE id=${next}`) === soon && (await alertOf(P)).includes('reminder'));
        await S.goto(BASE + 'super_admin_dashboard.php');
        await new Promise(r => setTimeout(r, 1500));
        const mails = fs.existsSync(CAPTURE) ? fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').map(JSON.parse).filter(m => m.subject.startsWith('Handover notes')) : [];
        ok('reminder e-mailed once to every active admin (within a month of the end)', mails.length === 5 && new Set(mails.map(m => m.to)).size === 5, mails.map(m => m.to).join(', '));
        ok('…listing the empty sections and the link', mails[0] && mails[0].text.includes('Summary of the year') && mails[0].text.includes('/frontend/handover.php'), mails[0] && mails[0].text.slice(0, 200));
        await S.goto(BASE + 'super_admin_dashboard.php');
        await new Promise(r => setTimeout(r, 1000));
        const mails2 = fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').filter(l => l.includes('Handover notes'));
        ok('…and not again', mails2.length === 5);
        sql(`UPDATE handover_docs SET reminder_sent_at = NULL WHERE id=${next}`);
        const cron = execFileSync(T.PHP, ['-d', `auto_prepend_file=${T.PREPEND}`, T.ROOT_FWD + '/backend/scripts/handover_reminders.php']).toString();
        ok('cron script sends it too', cron.includes('sent to 5 admin'), cron);
        ok('reminder logged', sql("SELECT COUNT(*) FROM admin_activity_log WHERE action='handover_reminder_sent'") === '2');

        // ---------- PDF page + log
        await S.goto(BASE + `handover_print.php?doc=${cur}`);
        const pdf = await S.content();
        ok('PDF page: all sections, contacts for the Super Admin, done ticks, no site chrome', pdf.includes('Handover Notes · Tenure 2026-27') && pdf.includes('Rafiq Uddin') && pdf.includes('✓') && !pdf.includes('class="sidebar'));
        ok('export logged', sql("SELECT COUNT(*) FROM admin_activity_log WHERE action='handover_exported'") === '2');
        ok('edits, unlocks and locks logged', ['handover_edited', 'handover_unlocked', 'handover_locked', 'handover_restored'].every(a => +sql(`SELECT COUNT(*) FROM admin_activity_log WHERE action='${a}'`) > 0));
        await S.goto(BASE + 'activity_log.php?action=handover_edited');
        ok('log page shows the readable label', (await S.content()).includes('Handover notes edited'));

        // ---------- phone
        const Ph = await as('hr', true);
        await Ph.goto(BASE + `handover.php?doc=${cur}`);
        const lay = await Ph.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: innerWidth }));
        ok('phone: no sideways scrolling', lay.sw <= lay.vw, JSON.stringify(lay));
        await Ph.screenshot({ path: path.join(S_DIR, 'shot_handover_phone.png'), fullPage: false });
        await S.goto(BASE + `handover.php?doc=${cur}`);
        await S.screenshot({ path: path.join(S_DIR, 'shot_handover.png'), fullPage: true });

        for (const [k, p] of Object.entries({ S, P, H, M, Sec, Ph })) if (p.errors.length) ok(`no JS errors (${k})`, false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
