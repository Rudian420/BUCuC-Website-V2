// Roles / permissions / media / lockdown checks against the local scratch server.
// Google is faked: CSV + Apps Script on 127.0.0.1:8098 (tests/fixtures). No real e-mail is sent.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', '-P3307', '-uroot', '-prootpw', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const REPO = T.ROOT_FWD;
const MEDIA = T.MEDIA;
const GAS_LOG = T.GAS_LOG;
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 160) : ''}`);
const sleep = ms => new Promise(r => setTimeout(r, ms));

const ACC = {
    super: ['super@test.local', 'superpass123'],
    panel: ['panel@test.local', 'panelpass123'],
    hr: ['admin@test.local', 'adminpass123'],
    miap: ['miap@test.local', 'miappass123'],
    sec: ['sec@test.local', 'secpass1234'],
};
const id = email => sql(`SELECT id FROM adminpanel WHERE email='${email}'`);

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
const close = p => p.browserContext().close();
const sidebar = p => p.$$eval('.sidebar-nav a', a => a.map(x => x.textContent.trim()));

// POST a form (fields + optional file) from inside the page, with the page's CSRF token
async function post(page, url, fields, file) {
    let b64 = null, fname = null, mime = null, field = null;
    if (file) {
        field = file[0];
        fname = path.basename(file[1]);
        mime = { '.jpg': 'image/jpeg', '.png': 'image/png', '.mp4': 'video/mp4' }[path.extname(fname)];
        b64 = fs.readFileSync(file[1]).toString('base64');
    }
    return page.evaluate(async (url, fields, field, fname, mime, b64) => {
        const fd = new FormData();
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) fd.append('csrf_token', meta.content);
        for (const [k, v] of Object.entries(fields)) (Array.isArray(v) ? v : [v]).forEach(x => fd.append(k, x));
        if (b64) {
            const bin = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
            fd.append(field, new Blob([bin], { type: mime }), fname);
        }
        const r = await fetch(url, { method: 'POST', body: fd });
        return { status: r.status, url: r.url, text: await r.text() };
    }, url, fields, field, fname, mime, b64);
}
async function board(page, payload) {
    return page.evaluate(async payload => {
        const r = await fetch('sb_members.php?action=gas_proxy', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(payload) });
        return { status: r.status, body: await r.json().catch(() => ({})) };
    }, payload);
}
const alertText = t => ((t.match(/class="alert alert-(?:success|danger)[^>]*>([\s\S]*?)<\/div>/) || [])[1] || '').replace(/<[^>]+>/g, '').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').trim();
const dec = u => decodeURIComponent(u.replace(/\+/g, ' '));

(async () => {
    const membersBackup = fs.readFileSync(`${REPO}/backend/Api/members.json`);
    sql("DELETE FROM admin_activity_log; DELETE FROM admin_login_attempts; DELETE FROM admin_permissions; DELETE FROM site_lockdown; DELETE FROM site_events; DELETE FROM site_settings");
    try { fs.unlinkSync(GAS_LOG); } catch (e) { }
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, args: ['--no-first-run'] });

    try {
        // ------------------------------------------------------------ A. dashboards + sidebars
        const P = {};
        for (const r of Object.keys(ACC)) P[r] = await as(r);
        ok('super → super dashboard', P.super.url().endsWith('super_admin_dashboard.php'), P.super.url());
        for (const r of ['panel', 'hr', 'miap', 'sec']) ok(`${r} → admin dashboard`, P[r].url().endsWith('admin_dashboard.php'), P[r].url());
        const sb = {};
        for (const r of Object.keys(P)) sb[r] = await sidebar(P[r]);
        const has = (r, item) => sb[r].includes(item);
        ok('super sidebar: everything', ['Site Lockdown', 'Admin Management', 'Website Media', 'Dashboard Updates', 'Members', 'Activity Log'].every(i => has('super', i)), sb.super.join(','));
        ok('panel sidebar: no Site Lockdown, has Admin Mgmt/Media/Members/Dashboard Updates', !has('panel', 'Site Lockdown') && ['Admin Management', 'Website Media', 'Members', 'Dashboard Updates'].every(i => has('panel', i)), sb.panel.join(','));
        ok('hr sidebar: Admin Mgmt, Members, Media; no Dashboard Updates/Signup', ['Admin Management', 'Members', 'Website Media'].every(i => has('hr', i)) && !has('hr', 'Dashboard Updates') && !has('hr', 'Signup Control'), sb.hr.join(','));
        ok('miap sidebar: Media only (no Members/Approved/Admin Mgmt)', has('miap', 'Website Media') && !has('miap', 'Members') && !has('miap', 'Approved Members') && !has('miap', 'Admin Management'), sb.miap.join(','));
        ok('secretary sidebar: shortlisting only', !has('sec', 'Website Media') && !has('sec', 'Members') && !has('sec', 'Admin Management') && sb.sec.some(t => t.startsWith('Applications')), sb.sec.join(','));
        ok('sidebar shows role label', (await P.hr.$eval('.sidebar-subtitle', e => e.textContent)).includes('Secretary Admin (HR)'));

        // ------------------------------------------------------------ B. server-side page gates
        const gate = async (r, page) => { await P[r].goto(BASE + page); return P[r].url(); };
        for (const [r, pg] of [['miap', 'approved_members.php'], ['miap', 'member_types.php'], ['miap', 'sb_members.php'], ['sec', 'media.php'], ['sec', 'set_venue.php'],
            ['hr', 'lockdown.php'], ['panel', 'lockdown.php'], ['hr', 'dashboard_updates.php'], ['miap', 'admin_management.php'], ['sec', 'admin-signup-control.php']]) {
            const u = await gate(r, pg);
            ok(`${r} blocked from ${pg}`, u.includes('admin_dashboard.php') && u.includes('permission'), u);
        }
        ok('panel can open dashboard_updates', (await gate('panel', 'dashboard_updates.php')).endsWith('dashboard_updates.php'));
        ok('hr can open approved_members', (await gate('hr', 'approved_members.php')).endsWith('approved_members.php'));
        const exp = await P.miap.evaluate(() => fetch('../backend/Action/export_members_excel.php?status=Accepted').then(r => r.url));
        ok('miap cannot export approved members', exp.includes('permission'), exp);
        const clr = await P.hr.evaluate(() => fetch('../backend/Action/clear_all_applications.php', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: '{}' }).then(r => r.status));
        ok('hr cannot clear all (403)', clr === 403, clr);

        // ------------------------------------------------------------ C. Super Admin is invisible
        const superId = id('super@test.local');
        await P.panel.goto(BASE + 'admin_management.php');
        const panelMgmt = await P.panel.content();
        ok('panel: Admin Management hides the Super Admin', !panelMgmt.includes('super@test.local') && !panelMgmt.includes('>Super Admin<'));
        await P.super.goto(BASE + 'admin_management.php');
        const superMgmt = await P.super.content();
        ok('super: sees every admin', Object.values(ACC).every(([e]) => superMgmt.includes(e)));
        let r = await post(P.panel, 'admin_management.php', { action: 'delete_admin', admin_id: superId });
        ok('panel cannot remove the Super Admin (not even visible)', alertText(r.text).includes('no longer exists') && id('super@test.local') === superId, alertText(r.text));
        r = await post(P.panel, 'admin_management.php', { action: 'add_admin', username: 'x', email: 'x@test.local', password: 'xxxxxxxx1', confirm_password: 'xxxxxxxx1', role: 'super_admin' });
        ok('panel cannot create a Super Admin', alertText(r.text).includes("can't create"), alertText(r.text));
        r = await post(P.panel, 'admin_management.php', { action: 'add_admin', username: 'x', email: 'x@test.local', password: 'xxxxxxxx1', confirm_password: 'xxxxxxxx1', role: 'hr_admin' });
        ok('one account per role (HR taken)', alertText(r.text).includes('already exists'), alertText(r.text));
        r = await post(P.panel, 'admin_management.php', { action: 'update_admin', admin_id: superId, username: 'hack', email: 'super@test.local', role: 'super_admin' });
        ok('panel cannot edit the Super Admin', alertText(r.text).includes("can't edit") && sql(`SELECT username FROM adminpanel WHERE id=${superId}`) === 'Super Tester');

        // ------------------------------------------------------------ D. rank rules
        r = await post(P.hr, 'admin_management.php', { action: 'delete_admin', admin_id: id('panel@test.local') });
        ok('hr cannot remove the Panel Admin', alertText(r.text).includes('below you'), alertText(r.text));
        r = await post(P.hr, 'admin_management.php', { action: 'add_admin', username: 'y', email: 'y@test.local', password: 'yyyyyyyy1', confirm_password: 'yyyyyyyy1', role: 'secretary_admin' });
        ok('hr cannot create admins', alertText(r.text).includes("can't create"), alertText(r.text));
        r = await post(P.hr, 'admin_management.php', { action: 'toggle_status', admin_id: id('miap@test.local') });
        ok('hr deactivates MIAP', sql(`SELECT status FROM adminpanel WHERE email='miap@test.local'`) === 'inactive');
        await P.miap.goto(BASE + 'media.php');
        ok('deactivated MIAP is signed out', P.miap.url().endsWith('admin-login.php'), P.miap.url());
        await post(P.hr, 'admin_management.php', { action: 'toggle_status', admin_id: id('miap@test.local') });
        await close(P.miap); P.miap = await as('miap');
        r = await post(P.miap, 'admin_management.php', { action: 'delete_admin', admin_id: id('sec@test.local') });
        ok('miap cannot remove anyone', r.url.includes('permission'), r.url);
        r = await post(P.panel, 'admin_management.php', { action: 'delete_admin', admin_id: id('sec@test.local') });
        ok('panel removes the Secretary Admin', alertText(r.text).includes('removed') && !id('sec@test.local'), alertText(r.text));
        r = await post(P.panel, 'admin_management.php', { action: 'add_admin', username: 'Sec Tester', email: 'sec@test.local', password: 'secpass1234', confirm_password: 'secpass1234', role: 'secretary_admin' });
        ok('panel re-creates a Secretary Admin', !!id('sec@test.local'), alertText(r.text));
        r = await post(P.super, 'admin_management.php', { action: 'toggle_status', admin_id: superId });
        ok('super cannot deactivate self', alertText(r.text).includes('yourself'));
        await close(P.sec); P.sec = await as('sec');

        // ------------------------------------------------------------ E. permission toggles + final approval
        const pendId = sql('SELECT id FROM pending_applications ORDER BY id LIMIT 1');
        r = await post(P.sec, 'handle_application.php', { action: 'shortlist', member_id: pendId, redirect_to: 'pending_applications.php' });
        ok('secretary can shortlist', dec(r.url).includes('was shortlisted') && !dec(r.url).includes('Super'), r.url);
        const shortId = sql('SELECT id FROM shortlisted_members ORDER BY id DESC LIMIT 1');
        r = await post(P.sec, 'handle_application.php', { action: 'accept', member_id: shortId, redirect_to: 'shortlisted_members.php' });
        ok('secretary cannot final-approve by default', dec(r.url).includes('final approval permission'), r.url);
        r = await post(P.hr, 'admin_management.php', { action: 'save_permissions', admin_id: id('sec@test.local'), 'perms[]': ['final_approve'] });
        ok('hr cannot change permissions', alertText(r.text).includes("can't change permissions"), alertText(r.text));
        r = await post(P.panel, 'admin_management.php', { action: 'save_permissions', admin_id: id('sec@test.local'), 'perms[]': ['final_approve', 'view_members'] });
        ok('panel grants Secretary final approval + member lists', alertText(r.text).includes('saved'), alertText(r.text));
        ok('…stored as overrides', +sql(`SELECT COUNT(*) FROM admin_permissions WHERE admin_id=${id('sec@test.local')} AND allowed=1`) === 2);
        ok('secretary now opens Set Venue', (await gate('sec', 'set_venue.php')).endsWith('set_venue.php'));
        r = await post(P.sec, 'handle_application.php', { action: 'accept', member_id: shortId, redirect_to: 'shortlisted_members.php' });
        ok('secretary gives final approval after grant', dec(r.url).includes('Member accepted'), dec(r.url).slice(-100));
        r = await post(P.super, 'admin_management.php', { action: 'save_permissions', admin_id: id('sec@test.local'), 'perms[]': [] });
        ok('super revokes them', (await gate('sec', 'set_venue.php')).includes('permission'));
        r = await post(P.panel, 'admin_management.php', { action: 'save_permissions', admin_id: id('admin@test.local'), 'perms[]': ['view_members', 'promote_gb_asb'] });
        ok('panel can untick HR defaults (final approval off)', (await gate('hr', 'set_venue.php')).includes('permission'));
        await post(P.panel, 'admin_management.php', { action: 'save_permissions', admin_id: id('admin@test.local'), 'perms[]': ['final_approve', 'view_members', 'promote_gb_asb', 'upload_carousel', 'upload_panel', 'upload_events', 'upload_hero', 'send_notices', 'manage_gallery', 'take_attendance'] });
        ok('…and restore them (no overrides left)', +sql(`SELECT COUNT(*) FROM admin_permissions WHERE admin_id=${id('admin@test.local')}`) === 0);
        r = await post(P.panel, 'admin_management.php', { action: 'save_permissions', admin_id: id('panel@test.local'), 'perms[]': [] });
        ok('Panel Admin permissions are not toggleable', alertText(r.text).includes("can't change permissions"));

        // F. promotions and G. tenure photos are covered by fixes.js (database-backed member levels, tenures)
        // ------------------------------------------------------------ G. media uploads (events, hero)
        await P.hr.goto(BASE + 'media.php?tab=events');
        r = await post(P.hr, 'media.php?tab=events', { action: 'event_add', title: 'Test Night 2026', event_date: '2026-10-01', place: 'Auditorium', description: 'Para one.\n\nPara two.', highlights: 'First\nSecond' }, ['photo', `${MEDIA}/event_wide.jpg`]);
        ok('event photo must be 3:2', alertText(r.text).includes('3:2'), alertText(r.text));
        r = await post(P.hr, 'media.php?tab=events', { action: 'event_add', title: 'Test Night 2026', event_date: '2026-10-01', place: 'Auditorium', description: 'Para one.\n\nPara two.', highlights: 'First\nSecond' }, ['photo', `${MEDIA}/event_ok.jpg`]);
        ok('HR adds a past event', alertText(r.text).includes('was added'), alertText(r.text));
        home = await (await as(null)).goto(BASE + 'index.php').then(x => x.text());
        ok('…newest card on the homepage', home.indexOf('Test Night 2026') > 0 && home.indexOf('Test Night 2026') < home.indexOf('Club Leadership Bootcamp 2025'));
        const pe = await (await as(null)).goto(BASE + 'past_events.php').then(x => x.text());
        ok('…at the top of Past Events with paragraphs + highlights', pe.includes('id="ev-') && pe.includes('Para two.') && pe.includes('<li>Second</li>') && pe.indexOf('Test Night 2026') < pe.indexOf('id="event-6"'));
        const evId = sql("SELECT id FROM site_events WHERE title='Test Night 2026'");
        await post(P.hr, 'media.php?tab=events', { action: 'event_remove', id: evId });
        ok('…and can be removed', !sql("SELECT id FROM site_events WHERE title='Test Night 2026'"));

        for (const [f, word] of [['hero_long.mp4', 'seconds'], ['hero_43.mp4', '16:9'], ['hero_hevc.mp4', 'H.264']]) {
            r = await post(P.miap, 'media.php?tab=hero', { action: 'hero_upload' }, ['video', `${MEDIA}/${f}`]);
            ok(`hero video rejected: ${f}`, alertText(r.text).includes(word), alertText(r.text));
        }
        r = await post(P.miap, 'media.php?tab=hero', { action: 'hero_upload' }, ['video', `${MEDIA}/hero_ok.mp4`]);
        ok('MIAP uploads a valid hero video', alertText(r.text).includes('is live'), alertText(r.text));
        home = await (await as(null)).goto(BASE + 'index.php').then(x => x.text());
        ok('…homepage plays the new video only', /source src="video\/hero-\d{8}-\d{6}\.mp4\?v=\d+"/.test(home) && !home.includes('bucuc1.webm'));
        await post(P.miap, 'media.php?tab=hero', { action: 'hero_reset' });
        home = await (await as(null)).goto(BASE + 'index.php').then(x => x.text());
        ok('…reset brings the original back', home.includes('video/bucuc1.mp4') && home.includes('video/bucuc1.webm') && !fs.readdirSync(`${REPO}/frontend/video`).some(f => f.startsWith('hero-')));

        // ------------------------------------------------------------ H. lockdown
        const soon = new Date(Date.now() + 3 * 60000 + 6 * 3600000).toISOString().slice(0, 16); // Dhaka = UTC+6
        r = await post(P.super, 'lockdown.php', { action: 'schedule', starts_at: soon, notice: 'Maintenance test notice', public_message: 'Back after maintenance' });
        ok('super schedules a lockdown', alertText(r.text).includes('scheduled'), alertText(r.text));
        await P.panel.goto(BASE + 'admin_dashboard.php');
        ok('panel sees the notice + countdown on load', await P.panel.$eval('#auLockBanner', e => e.textContent.includes('Maintenance test notice')).catch(() => false)
            && /\dm \d+s/.test(await P.panel.$eval('#auLockCountdown', e => e.textContent).catch(() => '')));
        await P.miap.goto(BASE + 'media.php');
        ok('…on every admin page (MIAP media page too)', !!(await P.miap.$('#auLockBanner')));
        ok('public site still open before the start time', (await (await as(null)).goto(BASE + 'index.php')).status() === 200);
        r = await post(P.super, 'lockdown.php', { action: 'end' });
        await P.panel.goto(BASE + 'admin_dashboard.php');
        ok('cancelling removes the notice', alertText(r.text).includes('cancelled') && !(await P.panel.$('#auLockBanner')));

        r = await post(P.super, 'lockdown.php', { action: 'schedule', start_now: '1', notice: 'Locked now test', public_message: 'Back after maintenance' });
        ok('super locks the public site now', alertText(r.text).includes('now offline'), alertText(r.text));
        const anon = await as(null);
        const resp = await anon.goto(BASE + 'index.php');
        ok('public homepage → 503 "We\'ll be back soon"', resp.status() === 503 && (await anon.content()).includes('Back after maintenance'));
        ok('past events locked too', (await anon.goto(BASE + 'past_events.php')).status() === 503);
        ok('members API locked (JSON 503)', (await anon.goto('http://127.0.0.1:8099/backend/Api/get_members.php')).status() === 503);
        await P.panel.goto(BASE + 'pending_applications.php');
        ok('panel admin keeps working during lockdown', P.panel.url().endsWith('pending_applications.php'));
        await close(P.panel); P.panel = await as('panel');
        ok('panel admin can log in during lockdown', P.panel.url().endsWith('admin_dashboard.php'));
        await P.super.goto(BASE + 'pending_applications.php');
        ok('super keeps using the panel', P.super.url().endsWith('pending_applications.php') && (await P.super.content()).includes('admin panel works as normal'));
        ok('super can still preview the public site', (await P.super.goto(BASE + 'index.php')).status() === 200);
        await P.super.goto(BASE + 'lockdown.php');
        r = await post(P.super, 'lockdown.php', { action: 'end' });
        ok('super lifts the lockdown', alertText(r.text).includes('lifted'), alertText(r.text));
        ok('public site open again', (await anon.goto(BASE + 'index.php')).status() === 200);
        await close(P.panel); P.panel = await as('panel');
        ok('panel admin can log in again', P.panel.url().endsWith('admin_dashboard.php'));
        await close(anon);

        // ------------------------------------------------------------ I. activity log visibility
        const seen = async page => { await page.goto(BASE + 'activity_log.php'); return page.content(); };
        const panelLog = await seen(P.panel);
        ok('panel log: no trace of the Super Admin', !panelLog.includes('Super Tester') && !panelLog.includes('super@test.local') && !panelLog.includes('Lockdown') && !panelLog.includes('>Super Admin<'));
        ok('panel log: shows other admins\' actions', panelLog.includes('Promoted') && panelLog.includes('Permissions changed') && panelLog.includes('Carousel photo added'));
        const superLog = await seen(P.super);
        ok('super log: sees own actions too', superLog.includes('Super Tester') && superLog.includes('Lockdown scheduled') && superLog.includes('Lockdown lifted'));
        const secLog = await seen(P.sec);
        ok('secretary can read the log (without Super Admin)', secLog.includes('Activity Log') && !secLog.includes('Super Tester'));
        const actions = sql('SELECT DISTINCT action FROM admin_activity_log').split('\n');
        for (const a of ['admin_added', 'admin_deleted', 'admin_deactivated', 'permissions_changed', 'application_shortlisted', 'member_accepted', 'event_added', 'hero_video_changed', 'lockdown_scheduled', 'lockdown_lifted', 'lockdown_cancelled'])
            ok(`logged: ${a}`, actions.includes(a));
        const perm = sql("SELECT CONCAT_WS(' | ', admin_name, actor_role, target) FROM admin_activity_log WHERE action='permissions_changed' ORDER BY id LIMIT 1");
        ok('entries record who / what / to whom / when', /Panel Tester \| panel_admin \| Sec Tester <sec@test.local>/.test(perm), perm);

        for (const p of Object.values(P)) if (p.errors.length) ok('no JS errors', false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        fs.writeFileSync(`${REPO}/backend/Api/members.json`, membersBackup);
        for (const f of fs.readdirSync(`${REPO}/frontend/video`)) if (f.startsWith('hero-')) fs.unlinkSync(`${REPO}/frontend/video/${f}`);
        if (fs.existsSync(`${REPO}/frontend/images/events`)) fs.rmSync(`${REPO}/frontend/images/events`, { recursive: true });
        for (const f of fs.readdirSync(`${REPO}/backend/logs`)) if (f.endsWith('.bak') || f.startsWith('cleared_')) fs.unlinkSync(`${REPO}/backend/logs/${f}`);
        sql("DELETE FROM site_lockdown; DELETE FROM site_settings; DELETE FROM site_events; DELETE FROM admin_permissions; DELETE FROM admin_login_attempts");
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(r => r.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
