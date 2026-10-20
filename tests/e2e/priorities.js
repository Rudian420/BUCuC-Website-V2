// Priority 1 lockdown (public only), 2 homepage stats, 3 email notices. Mail is captured to a file.
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
const captured = () => fs.existsSync(CAPTURE) ? fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : [];

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
const homeStat = (html, key) => { const m = html.match(new RegExp(`data-stat="${key}"><b>([^<]*)</b><span>([^<]*)`)); return m ? { value: m[1], label: m[2] } : null; };

(async () => {
    sql("DELETE FROM admin_activity_log; DELETE FROM site_lockdown; DELETE FROM site_settings; DELETE FROM admin_permissions");
    try { sql("DELETE FROM notices; DELETE FROM notice_recipients; DELETE FROM application_events"); } catch (e) { }
    try { fs.unlinkSync(CAPTURE); } catch (e) { }
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, args: ['--no-first-run'] });
    try {
        const P = {};
        for (const r of Object.keys(ACC)) P[r] = await as(r);

        // ================================================= PRIORITY 1: lockdown = public site only
        await P.super.goto(BASE + 'lockdown.php');
        let r = await post(P.super, 'lockdown.php', { action: 'schedule', start_now: '1', notice: 'Down for upgrades until Friday 8 pm.' });
        ok('super locks the public site', alertText(r.text).includes('public website is now offline'), alertText(r.text));
        const anon = await as(null);
        let resp = await anon.goto(BASE + 'index.php');
        let html = await anon.content();
        ok('visitors get 503 maintenance page', resp.status() === 503 && html.includes('Down for maintenance'));
        ok('…showing the Super Admin\'s notice', html.includes('Down for upgrades until Friday 8 pm.'));
        ok('past events also offline', (await anon.goto(BASE + 'past_events.php')).status() === 503);
        await P.panel.goto(BASE + 'pending_applications.php');
        ok('Panel Admin keeps working in the panel', P.panel.url().endsWith('pending_applications.php'), P.panel.url());
        ok('…and sees the offline notice', /public website is offline/.test(await P.panel.$eval('#auLockBanner', e => e.textContent).catch(() => '')));
        for (const role of ['hr', 'miap', 'sec']) {
            await P[role].goto(BASE + 'admin_dashboard.php');
            ok(`${role} still uses the panel during lockdown`, P[role].url().endsWith('admin_dashboard.php') && !!(await P[role].$('#auLockBanner')));
        }
        const fresh = await as('miap');
        ok('admins can still log in during lockdown', fresh.url().endsWith('admin_dashboard.php'), fresh.url());
        r = await post(P.hr, 'handle_application.php', { action: 'shortlist', member_id: sql('SELECT id FROM pending_applications ORDER BY id LIMIT 1'), redirect_to: 'pending_applications.php' });
        ok('admins can still act (HR shortlists)', decodeURIComponent(r.url.replace(/\+/g, ' ')).includes('was shortlisted'));
        resp = await P.panel.goto(BASE + 'index.php');
        html = await P.panel.content();
        ok('signed-in admin can preview the public site with a ribbon', resp.status() === 200 && html.includes('Admin preview: the public website is offline'));
        await anon.goto(BASE + 'forgot-password.php');
        await anon.type('input[name=email]', 'admin@test.local');
        await Promise.all([anon.waitForNavigation(), anon.click('button[type=submit]')]);
        ok('password reset still works for admins during lockdown', captured().some(m => m.to === 'admin@test.local'));
        await P.super.goto(BASE + 'lockdown.php');
        r = await post(P.super, 'lockdown.php', { action: 'end' });
        ok('lift: public site online again', alertText(r.text).includes('online again') && (await anon.goto(BASE + 'index.php')).status() === 200);
        await close(fresh);

        // scheduled notice + countdown still shows for every admin
        const soon = new Date(Date.now() + 10 * 60000 + 6 * 3600000).toISOString().slice(0, 16);
        await post(P.super, 'lockdown.php', { action: 'schedule', starts_at: soon, notice: 'Planned maintenance tonight.' });
        await P.sec.goto(BASE + 'admin_dashboard.php');
        ok('scheduled: notice + countdown on admin pages', /Planned maintenance tonight/.test(await P.sec.$eval('#auLockBanner', e => e.textContent).catch(() => '')) && !!(await P.sec.$('#auLockCountdown')));
        ok('scheduled: public site still online', (await anon.goto(BASE + 'index.php')).status() === 200);
        await post(P.super, 'lockdown.php', { action: 'end' });

        // Priorities 2 and 3 (homepage stats, email notices) are covered by followup3.js

        for (const [k, p] of Object.entries(P)) if (p.errors.length) ok(`no JS errors (${k})`, false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        sql("DELETE FROM site_lockdown; DELETE FROM admin_permissions");
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
async function close(p) { await p.browserContext().close(); }
