// Phase 4: Notice sending cron, advisory locking, duplicate-send prevention, live status
const T = require('../config');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');
const { execFileSync, spawn } = require('child_process');

const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const PHP = T.PHP;
const PHP_FLAGS = ['-d', 'extension=zip', '-d', 'extension=gd', '-d', `auto_prepend_file=${T.PREPEND}`];
const BASE = 'http://127.0.0.1:8099/frontend/';
const CAPTURE = path.join(T.RUNTIME, 'auth_mail_capture.jsonl');
const SCRIPT = path.join(T.ROOT, 'backend', 'scripts', 'send_notices.php');

const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  - ' + String(extra).slice(0, 200) : ''}`);
const captured = () => fs.existsSync(CAPTURE) ? fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : [];

(async () => {
    ok('send_notices.php script exists', fs.existsSync(SCRIPT));

    if (fs.existsSync(CAPTURE)) try { fs.unlinkSync(CAPTURE); } catch (e) {}

    // -------------------------------------------------------------
    // 1. Create a test notice with recipients in DB
    // -------------------------------------------------------------
    sql("INSERT INTO notices (subject, body, audience, sender_key, sender_email, members, total, status, created_at) VALUES ('Cron Test Notice', 'Hello {name}, student ID {student_id}', 'Test Members', 'club', 'club.bucuc@g.bracu.ac.bd', 3, 3, 'sending', NOW())");
    const noticeId = sql("SELECT id FROM notices WHERE subject = 'Cron Test Notice' ORDER BY id DESC LIMIT 1");

    sql(`INSERT INTO notice_recipients (notice_id, member_id, name, email, student_id, level, status) VALUES
        (${noticeId}, 1, 'Member One', 'm1@example.local', '20101001', 'GB', 'pending'),
        (${noticeId}, 2, 'Member Two', 'm2@example.local', '20101002', 'ASB', 'pending'),
        (${noticeId}, 3, 'Member Three', 'm3@example.local', '20101003', 'SB', 'pending')`);

    ok('test notice and recipients inserted into database', Boolean(noticeId));

    // -------------------------------------------------------------
    // 2. Run send_notices.php cron script
    // -------------------------------------------------------------
    let cronOut = '';
    try {
        cronOut = execFileSync(PHP, [...PHP_FLAGS, SCRIPT, '--test'], { cwd: T.ROOT }).toString();
        ok('send_notices.php runs with exit code 0', true);
    } catch (e) {
        ok('send_notices.php runs with exit code 0', false, e.message);
    }

    // Verify recipients status
    const sentCount = sql(`SELECT COUNT(*) FROM notice_recipients WHERE notice_id = ${noticeId} AND status = 'sent'`);
    const pendingCount = sql(`SELECT COUNT(*) FROM notice_recipients WHERE notice_id = ${noticeId} AND status = 'pending'`);
    const noticeStatus = sql(`SELECT status FROM notices WHERE id = ${noticeId}`);

    ok('all recipients transitioned from pending to sent by cron', sentCount === '3' && pendingCount === '0', `sent: ${sentCount}, pending: ${pendingCount}`);
    ok('notice status transitioned to done when complete', noticeStatus === 'done', `status: ${noticeStatus}`);

    // Verify captured emails
    const mails = captured().filter(m => m.subject === 'Cron Test Notice');
    ok('emails captured via mailer in test mode', mails.length === 3, `captured: ${mails.length}`);
    if (mails.length > 0) {
        ok('recipient placeholder {name} replaced correctly', mails[0].text.includes('Member One'));
        ok('recipient placeholder {student_id} replaced correctly', mails[0].text.includes('20101001'));
    }

    // -------------------------------------------------------------
    // 3. Concurrency Lock: GET_LOCK prevents overlapping runs (Rule 6)
    // -------------------------------------------------------------
    // Acquire lock in a background MySQL process
    const lockProc = spawn(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, T.DB_NAME], {
        stdio: ['pipe', 'pipe', 'pipe']
    });

    lockProc.stdin.write("SELECT GET_LOCK('bucuc_send_notices', 10);\n");
    await new Promise(r => setTimeout(r, 600));

    // Now run send_notices.php --test while lock is held
    let lockRes = '';
    try {
        lockRes = execFileSync(PHP, [...PHP_FLAGS, SCRIPT, '--test'], { cwd: T.ROOT }).toString();
    } catch (e) {
        lockRes = e.stdout ? e.stdout.toString() : '';
    }

    ok('cron script detects active lock and exits cleanly without overlapping', lockRes.includes('locked'), lockRes);

    // Release lock
    lockProc.stdin.write("SELECT RELEASE_LOCK('bucuc_send_notices');\nEXIT;\n");
    lockProc.stdin.end();
    await new Promise(r => setTimeout(r, 400));

    // -------------------------------------------------------------
    // 4. Atomic batch claim (H4 fix verification)
    // -------------------------------------------------------------
    sql("INSERT INTO notices (subject, body, audience, sender_key, sender_email, members, total, status, created_at) VALUES ('Atomic Claim Notice', 'Body', 'Test', 'club', 'club.bucuc@g.bracu.ac.bd', 2, 2, 'sending', NOW())");
    const atomicNid = sql("SELECT id FROM notices WHERE subject = 'Atomic Claim Notice' ORDER BY id DESC LIMIT 1");
    sql(`INSERT INTO notice_recipients (notice_id, member_id, name, email, student_id, level, status) VALUES
        (${atomicNid}, 4, 'Recip A', 'ra@example.local', '20101004', 'GB', 'pending'),
        (${atomicNid}, 5, 'Recip B', 'rb@example.local', '20101005', 'GB', 'pending')`);

    // Verify batch_token column exists in schema
    const tokenCol = sql("SHOW COLUMNS FROM notice_recipients LIKE 'batch_token'");
    ok('batch_token column exists in notice_recipients table', Boolean(tokenCol));

    // Run cron on this notice
    execFileSync(PHP, [...PHP_FLAGS, SCRIPT, '--test'], { cwd: T.ROOT });
    const atomicSent = sql(`SELECT COUNT(*) FROM notice_recipients WHERE notice_id = ${atomicNid} AND status = 'sent'`);
    ok('recipients sent without duplication', atomicSent === '2', `sent: ${atomicSent}`);

    // -------------------------------------------------------------
    // 5. Daily Limit Accounting (Counts cron + alerts towards account cap)
    // -------------------------------------------------------------
    const runnerFile = path.join(T.RUNTIME, 'test_mail_count_runner.php');
    const countCheckScript = `<?php
require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/auth.php';
require_once '${T.ROOT_FWD}/backend/includes/mailer.php';
echo mail_sent_today('club');
@unlink(__FILE__);
`;
    fs.writeFileSync(runnerFile, countCheckScript);
    const todayTotal = parseInt(execFileSync(PHP, ['-d', 'extension=zip', '-d', 'extension=gd', runnerFile]).toString().trim(), 10);
    ok('mail_sent_today counts cron notice emails towards daily limit', todayTotal >= 5, `total count today: ${todayTotal}`);

    // -------------------------------------------------------------
    // 6. Live status on progress page (Rule 6)
    // -------------------------------------------------------------
    const browser = await puppeteer.launch({
        executablePath: T.CHROME,
        headless: 'new',
        args: ['--no-sandbox', '--disable-gpu']
    });

    try {
        const page = await browser.newPage();
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', 'super@test.local');
        await page.type('#adminPassword', 'superpass123');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

        await page.goto(BASE + `notices.php?run=${noticeId}`);
        const panelExists = await page.$('#runPanel');
        ok('progress panel exists on notices.php?run=ID', Boolean(panelExists));

        const nSentText = await page.$eval('#nSent', el => el.textContent.trim());
        const nTotalText = await page.$eval('#nTotal', el => el.textContent.trim());
        ok('progress panel displays live sent count matching database', nSentText === '3', `nSent: ${nSentText}`);
        ok('progress panel displays live total count matching database', nTotalText === '3', `nTotal: ${nTotalText}`);
    } finally {
        await browser.close();
    }

    for (const r of results) console.log(r);
    const passed = results.filter(r => r.startsWith('PASS')).length;
    console.log(`${passed}/${results.length} passed`);
    process.exit(passed === results.length ? 0 : 1);
})().catch(e => {
    console.error(e);
    for (const r of results) console.log(r);
    const passed = results.filter(r => r.startsWith('PASS')).length;
    console.log(`${passed}/${results.length + 1} passed`);
    process.exit(1);
});
