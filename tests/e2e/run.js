// End-to-end check of the BUCuC site against the local scratch server (127.0.0.1:8099).
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const { execFileSync } = require('child_process');
const MARIADB = T.MYSQL;
const sql = q => execFileSync(MARIADB, ['-h127.0.0.1', '-P3307', '-uroot', '-prootpw', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const results = [];
const dec = u => decodeURIComponent((u.split('?')[1] || '').replace(/\+/g, ' '));
const ok = (name, cond, extra = '') => { results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + extra : ''}`); };

async function newPage(browser, label) {
    const page = await browser.newPage();
    page.consoleErrors = [];
    page.on('console', m => { if (m.type() === 'error') page.consoleErrors.push(`[${label}] ${m.text()}`); });
    page.on('pageerror', e => page.consoleErrors.push(`[${label}] PAGEERROR ${e.message}`));
    page.on('requestfailed', r => { if (r.url().startsWith('http://127.0.0.1')) page.consoleErrors.push(`[${label}] FAILED ${r.url()}`); });
    page.on('response', r => { if (r.url().startsWith('http://127.0.0.1') && r.status() >= 400) page.consoleErrors.push(`[${label}] HTTP ${r.status()} ${r.url()}`); });
    page.on('dialog', d => d.accept());
    await page.setViewport({ width: 1366, height: 900 });
    return page;
}

async function login(page, email, password) {
    await page.goto(BASE + 'logout.php'); if (await page.$('form[action="logout.php"] button')) await Promise.all([page.waitForNavigation(), page.click('form[action="logout.php"] button')]);
    await page.goto(BASE + 'admin-login.php');
    await page.type('#adminEmail', email);
    await page.type('#adminPassword', password);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

async function postForm(page, url, fields) {
    return page.evaluate(async (url, fields) => {
        const meta = document.querySelector('meta[name="csrf-token"]') || document.querySelector('input[name="csrf_token"]');
        if (meta && !('csrf_token' in fields)) fields.csrf_token = (meta.content || meta.value);
        const ft = document.querySelector('input[name="form_token"]'); if (ft && !('form_token' in fields)) fields.form_token = ft.value;
        const r = await fetch(url, { method: 'POST', body: new URLSearchParams(fields), redirect: 'follow' });
        return { url: r.url, text: await r.text() };
    }, url, fields);
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: T.CHROME,
        headless: true,
        args: ['--no-first-run', '--disable-extensions'],
    });
    const allErrors = [];

    // ---------------------------------------------------------------- public homepage
    {
        const page = await newPage(browser, 'home');
        await page.goto(BASE + 'index.php', { waitUntil: 'networkidle2', timeout: 60000 });
        ok('home: 5 current panel cards', await page.$$eval('#panelMembersGrid .panel-member-card', e => e.length) === 5);
        ok('home: swiper initialised', await page.evaluate(() => !!(window.sbSwiperInstance && window.sbSwiperInstance.slides && window.sbSwiperInstance.slides.length > 0)));
        // the photos load lazily: bring them in first, then a photo is broken only if it really fails
        const brokenImgs = await page.evaluate(async () => {
            const imgs = [...document.querySelectorAll('#panelMembersGrid img')];
            imgs.forEach(i => { i.loading = 'eager'; });
            document.getElementById('panelMembersGrid').scrollIntoView();
            await Promise.all(imgs.map(i => i.complete ? null : new Promise(r => { i.addEventListener('load', r, { once: true }); i.addEventListener('error', r, { once: true }); setTimeout(r, 8000); })));
            return imgs.filter(i => !i.complete || i.naturalWidth === 0).map(i => i.src);
        });
        ok('home: panel photos load', brokenImgs.length === 0, brokenImgs.join(', '));

        await page.select('#panelYearSelect', '2'); // 2023–2025
        await new Promise(r => setTimeout(r, 800));
        const names = await page.$$eval('#panelMembersGrid .pmc-name', e => e.map(x => x.textContent));
        ok('home: switch to 2023–2025 shows Nashib Siam panel', names.includes('Nashib Siam') && names.length === 4, names.join(', '));
        ok('home: 2023–2025 secretaries = 24', await page.$$eval('#sbSwiperWrapper .swiper-slide:not(.swiper-slide-duplicate)', e => new Set(e.map(x => x.querySelector('.name').textContent)).size) === 24);
        await new Promise(r => setTimeout(r, 1500));
        const broken2 = await page.$$eval('#panelMembersGrid img, #sbSwiperWrapper img', imgs => imgs.filter(i => i.complete && i.naturalWidth === 0).map(i => i.getAttribute('src')));
        ok('home: 2023–2025 photos load', broken2.length === 0, broken2.slice(0, 5).join(', '));
        ok('home: title updated', (await page.$eval('#panelYearTitle', e => e.textContent)) === 'Panel 2023-25');

        for (const idx of ['4', '5', '6']) {
            await page.select('#panelYearSelect', idx);
            await new Promise(r => setTimeout(r, 1500));
            const broken = await page.$$eval('#panelMembersGrid img, #sbSwiperWrapper img', imgs => imgs.filter(i => i.complete && i.naturalWidth === 0).map(i => i.getAttribute('src')));
            ok(`home: year option ${idx} photos load`, broken.length === 0, broken.slice(0, 3).join(', '));
        }

        // department dropdowns exclude each other
        await page.select('#signup-first-dept', 'HR');
        const secondOpts = await page.$$eval('#signup-second-dept option', o => o.map(x => x.value));
        ok('apply: second list hides first choice', !secondOpts.includes('HR') && secondOpts.length === 9);
        allErrors.push(...page.consoleErrors);
        await page.close();
    }

    // ---------------------------------------------------------------- deep link keeps hash (#section_5)
    {
        const page = await newPage(browser, 'hash');
        await page.goto(BASE + 'index.php#section_5', { waitUntil: 'networkidle2', timeout: 60000 });
        await new Promise(r => setTimeout(r, 1500));
        const y = await page.evaluate(() => window.scrollY);
        ok('deep link #section_5 scrolls to Past Events', y > 1000, `scrollY=${y}`);
        await page.close();
    }

    // ---------------------------------------------------------------- apply form submit (success, duplicate, refill)
    const applicant = {
        'signup-name': 'Test Applicant', 'signup-id': '99990001', 'signup-main-email': 'applicant1@example.com',
        'signup-gsuite-email': 'test.applicant1@example.com', 'signup-department': 'CSE', 'signup-phone': '01700000001',
        'signup-semester': '3rd', 'signup-gender': 'Female', 'signup-dob': '2004-05-06',
        'signup-facebook': 'https://facebook.com/test.applicant', 'signup-first-dept': 'Creative', 'signup-second-dept': 'HR',
    };
    {
        const page = await newPage(browser, 'apply');
        await page.goto(BASE + 'index.php', { waitUntil: 'domcontentloaded' });
        for (const [k, v] of Object.entries(applicant)) {
            if (['signup-semester', 'signup-gender', 'signup-first-dept', 'signup-second-dept'].includes(k)) await page.select('#' + k, v);
            else await page.$eval('#' + k, (el, v) => { el.value = v; }, v);
        }
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('#signup-form button[type=submit]')]);
        await new Promise(r => setTimeout(r, 1200));
        const success = await page.$eval('#nav-ContactForm', e => e.innerText).catch(() => '');
        ok('apply: success message shown', success.includes('Registration successful'));
        ok('apply: page scrolled to the form after redirect', (await page.evaluate(() => window.scrollY)) > 1500, `scrollY=${await page.evaluate(() => window.scrollY)}`);

        // duplicate → error + fields refilled
        await page.goto(BASE + 'index.php', { waitUntil: 'domcontentloaded' });
        for (const [k, v] of Object.entries(applicant)) {
            if (['signup-semester', 'signup-gender', 'signup-first-dept', 'signup-second-dept'].includes(k)) await page.select('#' + k, v);
            else await page.$eval('#' + k, (el, v) => { el.value = v; }, v);
        }
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('#signup-form button[type=submit]')]);
        const err = await page.$eval('#nav-ContactForm', e => e.innerText).catch(() => '');
        ok('apply: duplicate rejected', err.includes('already submitted'), err.slice(0, 120));
        ok('apply: name refilled after error', (await page.$eval('#signup-name', e => e.value)) === 'Test Applicant');
        ok('apply: dept choices refilled', (await page.$eval('#signup-first-dept', e => e.value)) === 'Creative' && (await page.$eval('#signup-second-dept', e => e.value)) === 'HR');

        // server-side validation: same priority twice
        const bad = await postForm(page, '../backend/Action/signup_handler.php', { ...applicant, 'signup-id': '99990002', 'signup-gsuite-email': 'x2@example.com', 'signup-second-dept': 'Creative' });
        ok('apply: identical priorities rejected server-side', bad.text.includes('must be different'));
        allErrors.push(...page.consoleErrors);
        await page.close();
    }

    // ---------------------------------------------------------------- regular admin
    {
        const page = await newPage(browser, 'admin');
        await login(page, 'admin@test.local', 'adminpass123');
        ok('admin: lands on admin_dashboard', page.url().endsWith('admin_dashboard.php'), page.url());
        ok('admin: no Dashboard Updates link', !(await page.$('a[href="dashboard_updates.php"]')));
        ok('admin: charts or empty-state rendered', await page.evaluate(() => !!document.querySelector('#memberGrowthChart, #memberGrowthEmpty')));

        await page.goto(BASE + 'pending_applications.php');
        ok('admin: no Clear button for regular admin', !(await page.$('#clearAllBtn')));
        const rowCount = await page.$$eval('#applicationsTableBody tr[data-status]', r => r.length);
        ok('admin: pending list shows 376 rows', rowCount === 376, String(rowCount));

        // shortlist the test applicant through the real button
        await page.goto(BASE + 'pending_applications.php');
        const form = await page.evaluateHandle(() => [...document.querySelectorAll('#applicationsTableBody tr')].find(tr => tr.innerText.includes('99990001')).querySelector('input[value=shortlist]').form);
        await Promise.all([page.waitForNavigation(), form.evaluate(f => f.querySelector('button').click())]);
        const msg = await page.$eval('.alert-success', e => e.innerText).catch(() => 'none');
        ok('admin: shortlist via button works', msg.includes('was shortlisted'), msg);

        // Old bug: rejecting pending #N also deleted shortlisted #N. The test applicant is now
        // shortlisted with some id N that also exists in pending_applications.
        const sid = sql("SELECT id FROM shortlisted_members WHERE university_id='99990001'");
        const pendingHasSameId = await page.evaluate(id => fetch('pending_applications.php').then(r => r.text()).then(t => t.includes(`name="member_id" value="${id}"`)), sid);
        const rej = await postForm(page, 'handle_application.php', { action: 'reject', source: 'pending', member_id: sid });
        ok(`reject pending #${sid} succeeds (id also exists in shortlist: ${pendingHasSameId})`, rej.url.includes('success='), dec(rej.url));
        const shortHtml = await page.evaluate(() => fetch('shortlisted_members.php').then(r => r.text()));
        ok(`reject pending #${sid} leaves shortlisted #${sid} intact`, shortHtml.includes('99990001'));

        // regular admin cannot approve or clear
        // (an id nobody has: this only checks HR isn't stopped by permissions, without approving anyone)
        const acc = await postForm(page, 'handle_application.php', { action: 'accept', member_id: '999999', redirect_to: 'shortlisted_members.php' });
        // HR (admin@test.local) has final approval by default now; the request reaches the handler
        ok('admin (HR): accept is allowed (reaches the handler)', !dec(acc.url).includes('permission'), dec(acc.url));
        const clr = await page.evaluate(() => fetch('../backend/Action/clear_all_applications.php', { method: 'POST', headers: { 'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content }, body: JSON.stringify({ action: 'clear_all_applications', confirmation: 'CLEAR ALL APPLICATIONS' }) }).then(r => r.status));
        ok('admin: clear-all endpoint returns 403', clr === 403, String(clr));
        await page.goto(BASE + 'dashboard_updates.php');
        ok('admin: dashboard_updates redirects regular admin', page.url().includes('admin_dashboard.php?error='));

        await page.goto(BASE + 'sb_members.php', { waitUntil: 'networkidle2', timeout: 60000 });
        await new Promise(r => setTimeout(r, 3000));
        const sbState = await page.evaluate(() => document.getElementById('membersTableContainer').style.display === 'block' ? 'table:' + document.querySelectorAll('#membersTable tbody tr').length : document.getElementById('emptyTitle').textContent);
        ok('admin: SB board loads (sheet read)', !!sbState, sbState);
        allErrors.push(...page.consoleErrors);
        await page.close();
    }

    // ---------------------------------------------------------------- super admin
    {
        const page = await newPage(browser, 'super');
        await login(page, 'super@test.local', 'superpass123');
        ok('super: lands on super_admin_dashboard', page.url().endsWith('super_admin_dashboard.php'), page.url());
        const stats = await page.$$eval('.stat-number', e => e.map(x => x.textContent.trim()));
        ok('super: stat cards show live counts', stats.length === 4, stats.join(' | '));

        // set venue
        await page.goto(BASE + 'set_venue.php');
        await page.$eval('#venue_name', e => { e.value = 'UB Auditorium'; });
        await page.$eval('#venue_location', e => { e.value = 'BRACU, Merul Badda'; });
        await page.$eval('#venue_date', e => { e.value = '2026-11-20'; });
        await page.$eval('#venue_time', e => { e.value = '03:30'; });
        await page.$eval('#venue_starting_time', e => { e.value = '9:00'; });
        await page.$eval('#venue_ending_time', e => { e.value = '11:30'; });
        await page.select('#venue_ampm', 'AM');
        await Promise.all([page.waitForNavigation(), page.click('.btn-save-venue')]);
        ok('venue: saved', (await page.$eval('.alert-success', e => e.innerText).catch(() => '')).includes('successfully'));
        await page.goto(BASE + 'set_venue.php');
        ok('venue: prefill keeps date (no UTC shift)', (await page.$eval('#venue_date', e => e.value)) === '2026-11-20' && (await page.$eval('#venue_time', e => e.value)) === '03:30');

        // approve the test applicant
        await page.goto(BASE + 'shortlisted_members.php');
        const approve = await page.evaluateHandle(() => [...document.querySelectorAll('#applicationsTableBody tr')].find(tr => tr.innerText.includes('99990001')).querySelector('input[value=accept]').form);
        await Promise.all([page.waitForNavigation({ timeout: 60000 }), approve.evaluate(f => f.querySelector('button').click())]);
        const warn = await page.$eval('.alert-warning, .alert-success', e => e.innerText).catch(() => 'none');
        ok('super: approve saves member (email/Sheets intentionally unreachable in test)', warn.includes('Member accepted'), warn.slice(0, 160));
        await page.goto(BASE + 'approved_members.php');
        ok('super: member appears in Approved list', (await page.content()).includes('99990001'));

        // approve a restored applicant with NO date of birth (failed before upgrade.sql)
        await page.goto(BASE + 'pending_applications.php');
        const firstId = await page.$eval('#applicationsTableBody tr[data-status] input[name=member_id]', e => e.value);
        await postForm(page, 'handle_application.php', { action: 'shortlist', member_id: firstId });
        const sid = await page.evaluate(() => fetch('shortlisted_members.php').then(r => r.text()).then(t => [...t.matchAll(/name="member_id" value="(\d+)"/g)].map(m => +m[1]).sort((a, b) => b - a)[0]));
        const acc2 = await postForm(page, 'handle_application.php', { action: 'accept', member_id: String(sid), redirect_to: 'shortlisted_members.php' });
        ok('super: approving applicant without DOB works', dec(acc2.url).includes('Member accepted'), dec(acc2.url).slice(0, 120));

        // exports
        for (const st of ['Pending', 'Shortlisted', 'Accepted']) {
            const info = await page.evaluate(st => fetch('../backend/Action/export_members_excel.php?status=' + st).then(r => ({ type: r.headers.get('content-type'), disp: r.headers.get('content-disposition'), url: r.url })), st);
            ok(`export ${st}`, (info.type || '').includes('spreadsheetml') && (info.disp || '').startsWith(`attachment; filename="${st}_Members_`), JSON.stringify(info));
        }

        // dashboard updates → growth chart
        await page.goto(BASE + 'dashboard_updates.php');
        await page.$eval('#totalMembers', e => { e.value = '500'; });
        await page.$eval('#completedevents', e => { e.value = '42'; });
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        await page.goto(BASE + 'super_admin_dashboard.php', { waitUntil: 'networkidle2' });
        const stats2 = await page.$$eval('.stat-number', e => e.map(x => x.textContent.trim()));
        ok('super: dashboard reflects Dashboard Updates', stats2[2] === '42' && +stats2[0] >= 502, stats2.join(' | '));
        ok('super: growth chart drawn', await page.evaluate(() => getComputedStyle(document.getElementById('memberGrowthChart')).display !== 'none'));
        ok('super: gender chart drawn', await page.evaluate(() => getComputedStyle(document.getElementById('genderDistributionChart')).display !== 'none'));

        // signup control off → form closed + handler refuses
        await page.goto(BASE + 'admin-signup-control.php');
        await page.select('#signup_status', 'disabled');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        const home = await page.evaluate(() => fetch('index.php').then(r => r.text()));
        ok('signup off: form hidden on homepage', !home.includes('id="signup-form"') && home.includes('Application'));
        const closed = await postForm(page, '../backend/Action/signup_handler.php', { ...applicant, 'signup-id': '99990003', 'signup-gsuite-email': 'x3@example.com' });
        ok('signup off: handler refuses submissions', closed.text.includes('currently closed'));
        await page.goto(BASE + 'admin-signup-control.php');
        await page.select('#signup_status', 'enabled');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

        // admin management: password policy + cannot demote self
        await page.goto(BASE + 'admin_management.php');
        // every role is filled in the test DB, so check the length rule on an edit
        const miapId = sql("SELECT id FROM adminpanel WHERE email='miap@test.local'");
        const short = await postForm(page, 'admin_management.php', { action: 'update_admin', admin_id: miapId, username: 'Miap Tester', email: 'miap@test.local', role: 'miap_admin', status: 'active', password: '123' });
        ok('admin mgmt: short password rejected', short.text.includes('at least 8 characters'));

        // clear shortlisted (archive log written, then deleted by the test harness)
        const clr = await page.evaluate(() => fetch('../backend/Action/clear_shortlisted_applications.php', { method: 'POST', headers: { 'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content }, body: JSON.stringify({ action: 'clear_shortlisted_applications', confirmation: 'CLEAR ALL SHORTLISTED' }) }).then(r => r.json()));
        ok('super: clear shortlisted works', clr.success === true, clr.message);
        const clrAll = await page.evaluate(() => fetch('../backend/Action/clear_all_applications.php', { method: 'POST', headers: { 'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content }, body: JSON.stringify({ action: 'clear_all_applications', confirmation: 'CLEAR ALL APPLICATIONS' }) }).then(r => r.json()));
        ok('super: clear all works (no "no active transaction" error)', clrAll.success === true, clrAll.message);
        allErrors.push(...page.consoleErrors);
        await page.close();
    }

    await browser.close();
    console.log(results.join('\n'));
    // (expected here: the 403 from the regular admin's clear-all attempt, and the browser asking for /favicon.ico)
    console.log('\nBrowser errors (' + allErrors.length + '):\n' + [...new Set(allErrors)].join('\n'));
    console.log(`\n${results.filter(r => r.startsWith('PASS')).length}/${results.length} passed`);
})().catch(e => {
    console.error('HARNESS ERROR', e);
    console.log(results.join('\n'));
    console.log('FAIL  HARNESS  — ' + e.message);
    console.log(`\n${results.filter(r => r.startsWith('PASS')).length}/${results.length + 1} passed`);
    process.exit(1);
});
