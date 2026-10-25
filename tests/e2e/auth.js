// Auth hardening checks against the local scratch server (127.0.0.1:8099).
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const MARIADB = T.MYSQL;
const sql = q => execFileSync(MARIADB, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const CAPTURE = path.join(T.RUNTIME, 'auth_mail_capture.jsonl');
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + extra : ''}`);
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function fresh(browser) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    page.errors = [];
    page.on('pageerror', e => page.errors.push(e.message));
    page.on('dialog', d => d.accept());
    await page.setViewport({ width: 1280, height: 900 });
    return page;
}

async function login(page, email, password) {
    await page.goto(BASE + 'admin-login.php');
    await page.type('#adminEmail', email);
    await page.type('#adminPassword', password);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    return page.url();
}
const errorText = page => page.$eval('.error-message', e => e.textContent.trim()).catch(() => '');
const noticeText = page => page.$eval('.success-message', e => e.textContent.trim()).catch(() => '');

(async () => {
    // clean slate for the auth tables
    sql("DELETE FROM admin_login_attempts; DELETE FROM admin_password_resets; DELETE FROM admin_activity_log; UPDATE adminpanel SET status='active'");
    try { fs.unlinkSync(CAPTURE); } catch (e) { }

    const browser = await puppeteer.launch({
        executablePath: T.CHROME,
        headless: true, args: ['--no-first-run', '--disable-extensions'],
    });

    // ---------------------------------------------------------- login + CSRF + cookie flags
    {
        const page = await fresh(browser);
        await page.goto(BASE + 'admin-login.php');
        ok('login form has csrf field', !!(await page.$('#adminLoginForm input[name=csrf_token]')));
        ok('login page links Forgot password', !!(await page.$('a[href="forgot-password.php"]')));
        const url = await login(page, 'super@test.local', 'superpass123');
        ok('super login → super dashboard (no 1s delay page)', url.endsWith('super_admin_dashboard.php'), url);
        const cookies = await page.cookies();
        const sess = cookies.find(c => c.name === 'PHPSESSID');
        ok('session cookie httponly + SameSite=Lax', sess && sess.httpOnly && sess.sameSite === 'Lax', JSON.stringify(sess && { h: sess.httpOnly, s: sess.sameSite }));
        ok('dashboard has csrf meta', !!(await page.$('meta[name="csrf-token"]')));
        ok('sidebar links Activity Log', !!(await page.$('a[href="activity_log.php"]')));

        // POST without token is refused
        const noTok = await page.evaluate(() => fetch('handle_application.php', { method: 'POST', body: new URLSearchParams({ action: 'reject', member_id: '999', source: 'pending' }) }).then(r => r.url));
        ok('POST without csrf token refused', noTok.includes('expired'), noTok);
        const jsonNoTok = await page.evaluate(() => fetch('../backend/Action/delete_venue.php', { method: 'POST', body: '{"venue_id":999}' }).then(r => r.status));
        ok('JSON endpoint without X-CSRF-Token → 419', jsonNoTok === 419, String(jsonNoTok));
        const jsonTok = await page.evaluate(() => fetch('../backend/Action/delete_venue.php', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: '{"venue_id":999999}' }).then(r => r.json()));
        ok('JSON endpoint with token accepted', jsonTok.message === 'Venue not found or already deleted', JSON.stringify(jsonTok));

        // every admin POST form got a token injected
        for (const p of ['pending_applications.php', 'admin_management.php', 'profile.php', 'set_venue.php', 'dashboard_updates.php', 'admin-signup-control.php']) {
            await page.goto(BASE + p);
            const missing = await page.$$eval('form', fs => fs.filter(f => f.method.toLowerCase() === 'post' && !f.querySelector('input[name=csrf_token]')).length);
            ok(`${p}: every POST form has csrf`, missing === 0, missing + ' missing');
        }
        // the visible GET-based admin login redirect when already logged in
        await page.goto(BASE + 'admin-login.php');
        ok('logged-in visit to login page → dashboard', page.url().endsWith('super_admin_dashboard.php'), page.url());
        await page.browserContext().close();
    }

    // ---------------------------------------------------------- throttle
    {
        const page = await fresh(browser);
        for (let i = 0; i < 5; i++) await login(page, 'admin@test.local', 'wrong-password');
        ok('5th wrong password → generic error', /Invalid email or password/.test(await errorText(page)));
        await login(page, 'admin@test.local', 'adminpass123');
        const err = await errorText(page);
        ok('6th try (correct password) blocked by lock', /Too many failed attempts/.test(err) && page.url().includes('admin-login'), err);
        ok('failed logins recorded in activity log', +sql("SELECT COUNT(*) FROM admin_activity_log WHERE action='login_failed'") === 5);
        sql("DELETE FROM admin_login_attempts");
        const url = await login(page, 'admin@test.local', 'adminpass123');
        ok('after lock clears, admin logs in', url.endsWith('admin_dashboard.php'), url);
        await page.browserContext().close();
    }

    // ---------------------------------------------------------- deactivate kicks out
    {
        const adminPage = await fresh(browser);
        await login(adminPage, 'admin@test.local', 'adminpass123');
        const superPage = await fresh(browser);
        await login(superPage, 'super@test.local', 'superpass123');
        await superPage.goto(BASE + 'admin_management.php');
        const adminId = sql("SELECT id FROM adminpanel WHERE email='admin@test.local'");
        const superId = sql("SELECT id FROM adminpanel WHERE email='super@test.local'");
        ok('no deactivate button for yourself', !(await superPage.$(`form input[name=action][value=toggle_status] ~ input[name=admin_id][value="${superId}"]`)));
        await Promise.all([superPage.waitForNavigation(), superPage.$eval(`input[name=action][value=toggle_status] ~ input[name=admin_id][value="${adminId}"]`, i => i.form.submit())]);
        ok('deactivate shows confirmation', /deactivated/.test(await superPage.$eval('.alert-success', e => e.textContent).catch(() => '')));
        ok('status is inactive in DB', sql(`SELECT status FROM adminpanel WHERE id=${adminId}`) === 'inactive');

        await adminPage.goto(BASE + 'pending_applications.php');
        ok('deactivated admin kicked to login on next request', adminPage.url().endsWith('admin-login.php'), adminPage.url());
        ok('…with an explanation', /no longer active/.test(await noticeText(adminPage)));
        await login(adminPage, 'admin@test.local', 'adminpass123');
        ok('inactive admin cannot log in', /inactive/.test(await errorText(adminPage)));

        // self-deactivation via forged POST is refused
        const self = await superPage.evaluate(id => fetch('admin_management.php', { method: 'POST', body: new URLSearchParams({ action: 'toggle_status', admin_id: id, csrf_token: document.querySelector('meta[name="csrf-token"]').content }) }).then(r => r.text()), superId);
        ok('cannot deactivate yourself', self.includes('deactivate yourself') && sql(`SELECT status FROM adminpanel WHERE id=${superId}`) === 'active');

        await superPage.goto(BASE + 'admin_management.php');
        await Promise.all([superPage.waitForNavigation(), superPage.$eval(`input[name=action][value=toggle_status] ~ input[name=admin_id][value="${adminId}"]`, i => i.form.submit())]);
        ok('reactivate works', sql(`SELECT status FROM adminpanel WHERE id=${adminId}`) === 'active');
        ok('admin_management: short password rejected', await superPage.evaluate(id => fetch('admin_management.php', { method: 'POST', body: new URLSearchParams({ action: 'update_admin', admin_id: id, username: 'Admin Tester', email: 'admin@test.local', role: 'hr_admin', status: 'active', password: 'short', csrf_token: document.querySelector('meta[name="csrf-token"]').content }) }).then(r => r.text()).then(t => t.includes('at least 8')), adminId));
        await superPage.browserContext().close();
        await adminPage.browserContext().close();
    }

    // ---------------------------------------------------------- forgot / reset password
    {
        const page = await fresh(browser);
        // unknown email → same generic answer, no mail
        await page.goto(BASE + 'forgot-password.php');
        await page.type('input[name=email]', 'nobody@test.local');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        const generic1 = await noticeText(page);
        await page.goto(BASE + 'forgot-password.php');
        await page.type('input[name=email]', 'admin@test.local');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        const generic2 = await noticeText(page);
        ok('same response for unknown and real email', generic1.replace('nobody@test.local', 'X') === generic2.replace('admin@test.local', 'X'), generic2.slice(0, 80));
        const mails = fs.existsSync(CAPTURE) ? fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').map(JSON.parse) : [];
        ok('exactly one reset email (only for the real admin)', mails.length === 1 && mails[0].to === 'admin@test.local', mails.map(m => m.to).join(','));
        const link = (mails[0] && mails[0].text.match(/https?:\/\/\S+token=[a-f0-9]{64}/) || [])[0];
        ok('reset link uses APP_URL + 64-hex token', !!link && link.startsWith('http://127.0.0.1:8099/frontend/reset-password.php'), link);
        ok('only the token hash is stored', !!link && sql('SELECT token_hash FROM admin_password_resets ORDER BY id DESC LIMIT 1') !== link.split('token=')[1]);

        // a second logged-in session of that admin, to check it ends after the reset
        const other = await fresh(browser);
        await login(other, 'admin@test.local', 'adminpass123');

        await page.goto(link);
        await page.type('input[name=password]', 'short');
        await page.type('input[name=confirm_password]', 'short');
        await page.$eval('#resetForm', f => { f.querySelectorAll('[minlength]').forEach(i => i.removeAttribute('minlength')); });
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        ok('reset rejects short password', /at least 8/.test(await errorText(page)));
        await page.type('input[name=password]', 'newadminpass1');
        await page.type('input[name=confirm_password]', 'newadminpass1');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        ok('reset → login page with notice', page.url().endsWith('admin-login.php') && /password was changed/.test(await noticeText(page)), page.url());
        const mails2 = fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').map(JSON.parse);
        ok('password-changed confirmation email sent', mails2.length === 2 && /was changed/.test(mails2[1].subject));

        await page.goto(link);
        ok('reset link is single-use', /invalid, already used, or expired/.test(await errorText(page)));

        await other.goto(BASE + 'pending_applications.php');
        ok('other session of that admin signed out after reset', other.url().endsWith('admin-login.php'), other.url());

        ok('old password no longer works', (await login(page, 'admin@test.local', 'adminpass123')).includes('admin-login'));
        ok('new password works', (await login(page, 'admin@test.local', 'newadminpass1')).endsWith('admin_dashboard.php'));

        // expired token
        await page.goto(BASE + 'logout.php'); if (await page.$('form[action="logout.php"] button')) await Promise.all([page.waitForNavigation(), page.click('form[action="logout.php"] button')]);
        await page.goto(BASE + 'forgot-password.php');
        await page.type('input[name=email]', 'admin@test.local');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        const link2 = fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').map(JSON.parse).pop().text.match(/https?:\/\/\S+token=[a-f0-9]{64}/)[0];
        sql('UPDATE admin_password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE');
        await page.goto(link2);
        ok('expired reset link refused', /invalid, already used, or expired/.test(await errorText(page)));

        // restore the original password for the other tests
        execFileSync(T.PHP, ['-r', `$p=new PDO('mysql:host=127.0.0.1;port=${T.DB_PORT};dbname=${T.DB_NAME}','root','rootpw');$p->prepare("UPDATE adminpanel SET password=? WHERE email='admin@test.local'")->execute([password_hash('adminpass123', PASSWORD_DEFAULT)]);`]);
        await other.browserContext().close();
        await page.browserContext().close();
    }

    // ---------------------------------------------------------- profile: current password required
    {
        const page = await fresh(browser);
        await login(page, 'admin@test.local', 'adminpass123');
        await page.goto(BASE + 'profile.php');
        const r = await page.evaluate(() => fetch('profile.php', { method: 'POST', body: new URLSearchParams({ update_profile: '1', username: document.querySelector('input[name=username]').value, email: document.querySelector('input[name=email]').value, password: 'another-pass-9', confirm_password: 'another-pass-9', current_password: 'wrong', csrf_token: document.querySelector('meta[name="csrf-token"]').content }) }).then(r => r.text()));
        ok('profile password change needs current password', r.includes('Enter your current password'));
        const r2 = await page.evaluate(() => fetch('profile.php', { method: 'POST', body: new URLSearchParams({ update_profile: '1', username: document.querySelector('input[name=username]').value, email: document.querySelector('input[name=email]').value, password: 'another-pass-9', confirm_password: 'another-pass-9', current_password: 'adminpass123', csrf_token: document.querySelector('meta[name="csrf-token"]').content }) }).then(r => r.text()));
        ok('profile password change works with current password', r2.includes('Profile updated successfully'));
        await page.goto(BASE + 'pending_applications.php');
        ok('own session stays valid after own password change', page.url().endsWith('pending_applications.php'), page.url());
        execFileSync(T.PHP, ['-r', `$p=new PDO('mysql:host=127.0.0.1;port=${T.DB_PORT};dbname=${T.DB_NAME}','root','rootpw');$p->prepare("UPDATE adminpanel SET password=? WHERE email='admin@test.local'")->execute([password_hash('adminpass123', PASSWORD_DEFAULT)]);`]);
        await page.browserContext().close();
    }

    // ---------------------------------------------------------- idle timeout
    {
        const page = await fresh(browser);
        await login(page, 'admin@test.local', 'adminpass123');
        const sid = (await page.cookies()).find(c => c.name === 'PHPSESSID').value;
        const file = path.join(T.SESSIONS, 'sess_' + sid);
        const data = fs.readFileSync(file, 'utf8');
        fs.writeFileSync(file, data.replace(/last_activity\|i:\d+;/, 'last_activity|i:' + (Math.floor(Date.now() / 1000) - 31 * 60) + ';'));
        await page.goto(BASE + 'pending_applications.php');
        ok('idle 31 min → signed out', page.url().endsWith('admin-login.php'), page.url());
        ok('…with "session expired" notice', /expired after 30 minutes/.test(await noticeText(page)));
        await page.browserContext().close();
    }

    // ---------------------------------------------------------- activity log page
    {
        const page = await fresh(browser);
        await login(page, 'admin@test.local', 'adminpass123');
        await page.goto(BASE + 'activity_log.php');
        ok('regular admin can read the activity log (without Super Admin rows)', page.url().endsWith('activity_log.php') && !(await page.content()).includes('Super Tester'), page.url());
        await page.goto(BASE + 'logout.php'); if (await page.$('form[action="logout.php"] button')) await Promise.all([page.waitForNavigation(), page.click('form[action="logout.php"] button')]);
        await login(page, 'super@test.local', 'superpass123');
        await page.goto(BASE + 'activity_log.php');
        const rows = await page.$$eval('.log-table tbody tr', r => r.length);
        const actions = await page.$$eval('.log-table .tag', t => [...new Set(t.map(x => x.textContent))]);
        ok('activity log lists entries', rows > 5, rows + ' rows: ' + actions.join(', '));
        for (const need of ['Logged in', 'Failed login', 'Admin deactivated', 'Admin activated', 'Password reset', 'Reset link requested', 'Logged out', 'Profile updated']) {
            ok(`log contains "${need}"`, actions.includes(need));
        }
        await page.select('#f-action', 'login_failed');
        await Promise.all([page.waitForNavigation(), page.click('.filters button[type=submit]')]);
        const filtered = await page.$$eval('.log-table .tag', t => [...new Set(t.map(x => x.textContent))]);
        ok('filter by action works', filtered.length === 1 && filtered[0] === 'Failed login', filtered.join(','));
        ok('no JS errors on activity log', page.errors.length === 0, page.errors.join(' | '));
        await page.setViewport({ width: 390, height: 844 });
        await page.goto(BASE + 'activity_log.php');
        await page.screenshot({ path: path.join(T.RUNTIME, 'shot_activity_mobile.png'), fullPage: false });
        await page.setViewport({ width: 1366, height: 900 });
        await page.goto(BASE + 'activity_log.php');
        await page.screenshot({ path: path.join(T.RUNTIME, 'shot_activity.png'), fullPage: false });
        await page.goto(BASE + 'admin_management.php');
        await page.screenshot({ path: path.join(T.RUNTIME, 'shot_mgmt.png'), fullPage: true });
        await page.goto(BASE + 'logout.php'); if (await page.$('form[action="logout.php"] button')) await Promise.all([page.waitForNavigation(), page.click('form[action="logout.php"] button')]);
        await page.goto(BASE + 'forgot-password.php');
        await page.screenshot({ path: path.join(T.RUNTIME, 'shot_forgot.png') });
        await page.goto(BASE + 'admin-login.php');
        await page.screenshot({ path: path.join(T.RUNTIME, 'shot_login.png') });
        await page.browserContext().close();
    }

    await browser.close();
    sql("DELETE FROM admin_login_attempts");
    console.log(results.join('\n'));
    const fails = results.filter(r => r.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})().catch(e => { console.error(e); console.log(results.join('\n')); process.exit(1); });
