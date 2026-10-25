// Phase 5: Applicant Data Deletion on Rejection, Tracking Link, Confirmation, Rollback & Anonymous Stats
const T = require('../config');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');
const { execFileSync } = require('child_process');

const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '-N', '-B', T.DB_NAME, '-e', q])
    .toString().replace(/\r/g, '').trim();

const runPhp = code => execFileSync(T.PHP, ['-r', `require_once 'tests/test_prepend.php'; ${code}`], { cwd: T.ROOT }).toString().trim();

const BASE = 'http://127.0.0.1:8099/frontend/';
const out = [];

function check(name, ok, detail = '') {
    out.push([name, ok, detail]);
    console.log((ok ? 'PASS  ' : 'FAIL  ') + name + (detail ? ' — ' + detail : ''));
}

async function loginAdmin(page, email, password) {
    await page.goto(BASE + 'admin-login.php', { waitUntil: 'domcontentloaded' });
    await page.type('#adminEmail', email);
    await page.type('#adminPassword', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click('button[type=submit]'),
    ]);
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: T.CHROME,
        headless: true,
        protocolTimeout: 45000,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    try {
        const testApplicant = {
            'signup-name': 'Test Rejectee Applicant',
            'signup-id': '29999888',
            'signup-main-email': 'rejectee.student@example.com',
            'signup-gsuite-email': 'rejectee.student@g.example.edu',
            'signup-department': 'CSE',
            'signup-phone': '01799887766',
            'signup-semester': '3rd',
            'signup-gender': 'Other',
            'signup-dob': '2004-09-15',
            'signup-facebook': 'https://facebook.com/rejectee.student',
            'signup-first-dept': 'Creative',
            'signup-second-dept': 'HR',
        };

        // Clean previous test data
        sql(`DELETE FROM pending_applications WHERE university_id IN ('29999888', '29999889', '29999890');
             DELETE FROM shortlisted_members WHERE university_id IN ('29999888', '29999889', '29999890');
             DELETE FROM mail_log WHERE recipient IN ('rejectee.student@example.com', 'rejectee.student@g.example.edu');
             DELETE FROM notice_recipients WHERE student_id IN ('29999888', '29999889');
             DELETE FROM admin_activity_log WHERE target LIKE '%2999988%' OR details LIKE '%2999988%';`);

        const page = await browser.newPage();

        // -------------------------------------------------------------
        // Step 1: Submit Application
        // -------------------------------------------------------------
        await page.goto(BASE + 'index.php', { waitUntil: 'domcontentloaded' });
        for (const [k, v] of Object.entries(testApplicant)) {
            if (['signup-semester', 'signup-gender', 'signup-first-dept', 'signup-second-dept'].includes(k)) {
                await page.select('#' + k, v);
            } else {
                await page.$eval('#' + k, (el, val) => { el.value = val; }, v);
            }
        }
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle2' }),
            page.click('#signup-form button[type=submit]'),
        ]);

        const savedPending = sql("SELECT COUNT(*) FROM pending_applications WHERE university_id='29999888'") === '1';
        check('application submitted and saved to pending_applications', savedPending);

        // Add dummy auxiliary records across other tables to test comprehensive anonymization/deletion
        sql(`INSERT INTO mail_log (account_key, kind, recipient) VALUES ('club', 'notice', 'rejectee.student@example.com');
             INSERT INTO notice_recipients (notice_id, member_id, name, email, student_id, status) VALUES (1, NULL, 'Test Rejectee Applicant', 'rejectee.student@example.com', '29999888', 'pending');
             INSERT INTO admin_activity_log (action, target, details) VALUES ('test_action', 'Test Rejectee Applicant (29999888)', 'Processed applicant 29999888');`);

        // Populate log files with applicant data to verify scrubbing
        const logDir = path.join(T.ROOT, 'backend', 'logs');
        if (!fs.existsSync(logDir)) fs.mkdirSync(logDir, { recursive: true });
        fs.appendFileSync(path.join(logDir, 'email.log'), `[2026-10-23 10:00:00] Sent notice to rejectee.student@example.com (29999888)\n`);
        fs.appendFileSync(path.join(logDir, 'cleared_applications.log'), `[2026-10-23 10:00:00] Candidate Test Rejectee Applicant 29999888 01799887766\n`);

        // -------------------------------------------------------------
        // Step 3: Check Rejection Confirmation Dialog in Admin UI
        // -------------------------------------------------------------
        const adminPage = await browser.newPage();
        await loginAdmin(adminPage, 'super@test.local', 'superpass123');

        await adminPage.goto(BASE + 'pending_applications.php', { waitUntil: 'networkidle2' });

        // Locate reject form for applicant 29999888
        const applicantRowId = sql("SELECT id FROM pending_applications WHERE university_id='29999888'");
        check('applicant row id located in pending_applications', Boolean(applicantRowId), `id=${applicantRowId}`);

        // Read the onsubmit attribute of the reject form
        const onsubmitText = await adminPage.evaluate((id) => {
            const form = [...document.querySelectorAll('form[action="handle_application.php"]')].find(f => {
                const idInput = f.querySelector('input[name="member_id"]');
                const actionInput = f.querySelector('input[name="action"]');
                return idInput && idInput.value === String(id) && actionInput && actionInput.value === 'reject';
            });
            return form ? form.getAttribute('onsubmit') || '' : '';
        }, applicantRowId);

        check('rejection confirmation text contains required warning',
            onsubmitText.includes("This permanently deletes this applicant's data and cannot be undone."),
            onsubmitText.slice(0, 120));

        // Test Dialog Cancel: trigger dialog, dismiss it, assert nothing deleted
        let dialogDismissed = false;
        let dialogMessage = '';
        adminPage.once('dialog', async dialog => {
            dialogMessage = dialog.message();
            dialogDismissed = true;
            await dialog.dismiss();
        });

        const rejectBtn = await adminPage.evaluateHandle((id) => {
            const form = [...document.querySelectorAll('form[action="handle_application.php"]')].find(f => {
                const idInput = f.querySelector('input[name="member_id"]');
                const actionInput = f.querySelector('input[name="action"]');
                return idInput && idInput.value === String(id) && actionInput && actionInput.value === 'reject';
            });
            return form ? form.querySelector('button') : null;
        }, applicantRowId);

        if (rejectBtn) {
            await rejectBtn.click();
            await new Promise(r => setTimeout(r, 600));
        }

        check('confirmation dialog appears on reject click',
            dialogDismissed && dialogMessage.includes("This permanently deletes this applicant's data and cannot be undone."));

        const stillExistsAfterCancel = sql(`SELECT COUNT(*) FROM pending_applications WHERE id='${applicantRowId}'`) === '1';
        check('cancelling confirmation dialog deletes nothing', stillExistsAfterCancel);

        // -------------------------------------------------------------
        // Step 4: Check Atomic Rollback Safety (Failed Deletion Rolls Back Completely)
        // -------------------------------------------------------------
        // Execute simulated failure via PHP directly
        const rollbackCheck = runPhp(`
            require_once 'backend/Database/db.php';
            require_once 'backend/includes/site.php';
            require_once 'backend/includes/applicant_deletion.php';
            $pdo = db();
            $res = applicant_reject_and_delete($pdo, (int)'${applicantRowId}', 'pending', 'rejected', true);
            echo json_encode($res);
        `);

        const rollbackRes = JSON.parse(rollbackCheck);
        const existsAfterRollback = sql(`SELECT COUNT(*) FROM pending_applications WHERE id='${applicantRowId}'`) === '1';
        check('simulated failure triggers rollback and leaves application intact',
            rollbackRes.success === false && existsAfterRollback,
            rollbackRes.error || '');

        const applicantDataIntact = sql(`SELECT full_name FROM pending_applications WHERE id='${applicantRowId}'`) === 'Test Rejectee Applicant';
        check('applicant personal data completely preserved after rollback', applicantDataIntact);

        // -------------------------------------------------------------
        // Step 5: Anonymous Counts Verification (Before vs After)
        // -------------------------------------------------------------
        const initialStatsCount = parseInt(sql("SELECT COALESCE(SUM(rejected_count), 0) FROM applicant_rejection_stats") || '0', 10);

        // Perform the real rejection
        const rejectOutput = runPhp(`
            require_once 'backend/Database/db.php';
            require_once 'backend/includes/site.php';
            require_once 'backend/includes/applicant_deletion.php';
            $pdo = db();
            $res = applicant_reject_and_delete($pdo, (int)'${applicantRowId}', 'pending', 'rejected', false);
            echo json_encode($res);
        `);

        const realRejectRes = JSON.parse(rejectOutput);
        check('rejection succeeds and commits transaction', realRejectRes.success === true);

        const afterStatsCount = parseInt(sql("SELECT COALESCE(SUM(rejected_count), 0) FROM applicant_rejection_stats") || '0', 10);

        check('anonymous stats count increased by one per rejection',
            afterStatsCount === initialStatsCount + 1,
            `before=${initialStatsCount}, after=${afterStatsCount}`);

        // -------------------------------------------------------------
        // Step 6: Comprehensive Database-Wide Zero Personal Data Scan
        // -------------------------------------------------------------

        // Verify queued unsent notices removed
        const pendingNotices = sql(`SELECT COUNT(*) FROM notice_recipients WHERE student_id='29999888' AND status='pending'`);
        check('queued unsent notice for rejected applicant removed', pendingNotices === '0', `pendingNotices=${pendingNotices}`);

        // Verify log files scrubbed of applicant details
        const emailLogContent = fs.existsSync(path.join(logDir, 'email.log')) ? fs.readFileSync(path.join(logDir, 'email.log'), 'utf8') : '';
        const clearedLogContent = fs.existsSync(path.join(logDir, 'cleared_applications.log')) ? fs.readFileSync(path.join(logDir, 'cleared_applications.log'), 'utf8') : '';
        check('logs scrubbed: no email or student ID remaining in email.log', !emailLogContent.includes('rejectee.student@example.com') && !emailLogContent.includes('29999888'));
        check('logs scrubbed: no name, phone or ID remaining in cleared_applications.log', !clearedLogContent.includes('Test Rejectee Applicant') && !clearedLogContent.includes('01799887766') && !clearedLogContent.includes('29999888'));

        // Verify members.json is pristine and contains zero applicant data
        const membersJson = fs.readFileSync(path.join(T.ROOT, 'backend', 'Api', 'members.json'), 'utf8');
        check('members.json contains zero applicant data', !membersJson.includes('29999888') && !membersJson.includes('rejectee.student@example.com'));

        // Verify that no row anywhere in any table contains name, student ID, phone, or email across ALL text and JSON columns
        const colsRaw = sql("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND data_type IN ('varchar', 'text', 'char', 'tinytext', 'mediumtext', 'longtext', 'json')");
        const tableCols = {};
        for (const line of colsRaw.split('\n')) {
            const parts = line.trim().split(/\s+/);
            if (parts.length >= 2) {
                const [t, c] = parts;
                if (!tableCols[t]) tableCols[t] = [];
                tableCols[t].push(c);
            }
        }

        let totalFound = 0;
        const searchTerms = ['Test Rejectee Applicant', '29999888', 'rejectee.student@example.com', 'rejectee.student@g.example.edu', '01799887766'];

        for (const [tbl, cols] of Object.entries(tableCols)) {
            if (!cols.length) continue;
            for (const term of searchTerms) {
                const whereClause = cols.map(c => `\`${c}\` LIKE '%${term}%'`).join(' OR ');
                const matchCount = parseInt(sql(`SELECT COUNT(*) FROM \`${tbl}\` WHERE ${whereClause}`) || '0', 10);
                if (matchCount > 0) {
                    totalFound += matchCount;
                    console.error(`Found ${matchCount} occurrence(s) of "${term}" in table ${tbl}`);
                }
            }
        }

        check('database-wide scan: zero rows anywhere in text/JSON columns contain name, student ID, phone or email',
            totalFound === 0,
            `totalOccurrences=${totalFound}`);

        // Scan files in backend/cache and backend/logs
        let fileOccurrences = 0;
        const dirsToScan = [path.join(T.ROOT, 'backend', 'cache'), path.join(T.ROOT, 'backend', 'logs')];
        for (const d of dirsToScan) {
            if (fs.existsSync(d)) {
                for (const f of fs.readdirSync(d)) {
                    const fp = path.join(d, f);
                    if (fs.statSync(fp).isFile()) {
                        const txt = fs.readFileSync(fp, 'utf8');
                        for (const term of searchTerms) {
                            if (txt.includes(term)) {
                                fileOccurrences++;
                                console.error(`Found "${term}" in file ${fp}`);
                            }
                        }
                    }
                }
            }
        }
        check('file-wide scan: zero occurrences in backend/cache or backend/logs', fileOccurrences === 0, `fileOccurrences=${fileOccurrences}`);

        // Specific table assertions
        check('deleted from pending_applications', sql("SELECT COUNT(*) FROM pending_applications WHERE university_id='29999888'") === '0');
        check('deleted from shortlisted_members', sql("SELECT COUNT(*) FROM shortlisted_members WHERE university_id='29999888'") === '0');
        check('mail_log recipient anonymized to NULL', sql("SELECT COUNT(*) FROM mail_log WHERE recipient LIKE '%rejectee.student%'") === '0');
        check('notice_recipients record removed', sql("SELECT COUNT(*) FROM notice_recipients WHERE student_id='29999888'") === '0');
        check('admin_activity_log sanitized without name or student ID', sql("SELECT COUNT(*) FROM admin_activity_log WHERE target LIKE '%29999888%' OR details LIKE '%29999888%'") === '0');

        // Confirm mail_log keeps row count intact so daily send caps remain accurate
        const mailLogClubTotal = parseInt(sql("SELECT COUNT(*) FROM mail_log WHERE account_key='club'") || '0', 10);
        const mailLogAnonymized = parseInt(sql("SELECT COUNT(*) FROM mail_log WHERE account_key='club' AND recipient IS NULL") || '0', 10);
        check('mail_log keeps row count intact so daily send caps remain accurate', mailLogClubTotal >= 1);
        check('mail_log recipient address is cleared to NULL while row is preserved', mailLogAnonymized >= 1);

        // Check Google Sheet fake endpoint received delete request with shared secret
        const gasCallsPath = path.join(T.ROOT, 'tests', 'runtime', 'gas_calls.jsonl');
        const gasCallsContent = fs.existsSync(gasCallsPath) ? fs.readFileSync(gasCallsPath, 'utf8') : '';
        const hasGasDeleteCall = gasCallsContent.split('\n').some(line => {
            try {
                const j = JSON.parse(line.trim());
                return j.action === 'delete' && j.studentId === '29999888' && j.secret === 'test-shared-webhook-secret-xyz';
            } catch { return false; }
        });
        check('Google Sheet received action=delete request with shared secret for rejected applicant', hasGasDeleteCall);

        // Part B: Verify unauthorized request to Google Sheet webhook is rejected
        const gasUnauthorizedCheck = runPhp(`
            $ch = curl_init('http://127.0.0.1:8098/gas.php');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['action' => 'delete', 'studentId' => '29999888', 'secret' => 'wrong-secret']),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            echo json_encode(['code' => $code, 'res' => json_decode($res, true)]);
        `);
        const unauthRes = JSON.parse(gasUnauthorizedCheck);
        check('unauthorized Google Sheet webhook request (wrong secret) returns HTTP 200 with success=false', unauthRes.code === 200 && unauthRes.res.success === false);

        const gasNoSecretCheck = runPhp(`
            $ch = curl_init('http://127.0.0.1:8098/gas.php');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['action' => 'delete', 'studentId' => '29999888']),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            echo json_encode(['code' => $code, 'res' => json_decode($res, true)]);
        `);
        const noSecretRes = JSON.parse(gasNoSecretCheck);
        check('unauthorized Google Sheet webhook request (missing secret) returns HTTP 200 with success=false', noSecretRes.code === 200 && noSecretRes.res.success === false);

        // Test PHP Google Sheet deletion failure handling: records retry entry in failed_sheet_deletions table
        const testRetryResult = runPhp(`
            require_once 'backend/includes/site.php';
            require_once 'backend/includes/applicant_deletion.php';
            $pdo = db();
            // Point temporarily to a failing endpoint or invalid secret
            $res = applicant_delete_from_google_sheet('99991111', $pdo);
            $count = (int) $pdo->query("SELECT COUNT(*) FROM failed_sheet_deletions WHERE student_id = '99991111'")->fetchColumn();
            // Now test successful deletion removes it
            $resOk = applicant_delete_from_google_sheet('29999888', $pdo);
            $countAfterOk = (int) $pdo->query("SELECT COUNT(*) FROM failed_sheet_deletions WHERE student_id = '29999888'")->fetchColumn();
            echo json_encode(['hasRetry' => ($count > 0), 'cleanAfterOk' => ($countAfterOk === 0)]);
        `);
        const retryCheck = JSON.parse(testRetryResult);
        check('Google Sheet deletion failure records row in failed_sheet_deletions for retry', retryCheck.hasRetry);
        check('Google Sheet deletion success removes row from failed_sheet_deletions', retryCheck.cleanAfterOk);

        // Test atomic log scrubbing: failure midway leaves original log intact
        const gsLogPath = path.join(T.ROOT, 'backend', 'logs', 'google_sheets.log');
        const originalGsContent = "[2026-10-23 12:00:00] Syncing applicant 88887777 phone 01788887777\n";
        fs.writeFileSync(gsLogPath, originalGsContent, 'utf8');

        const scrubMidwayRes = runPhp(`
            require_once 'backend/Database/db.php';
            require_once 'backend/includes/applicant_deletion.php';
            try {
                applicant_scrub_logs('88887777', '', '', '01788887777', '', true);
                echo json_encode(['error' => null]);
            } catch (\\Throwable $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
        `);
        const scrubErr = JSON.parse(scrubMidwayRes);
        const gsContentAfterFail = fs.readFileSync(gsLogPath, 'utf8');
        const tmpFilesLeft = fs.readdirSync(path.join(T.ROOT, 'backend', 'logs')).filter(f => f.startsWith('.scrub_tmp_'));

        check('atomic log scrub: simulated mid-way failure throws error', scrubErr.error && scrubErr.error.includes('mid-way failure'));
        check('atomic log scrub: failure leaves original log completely intact and untouched', gsContentAfterFail === originalGsContent);
        check('atomic log scrub: temporary files cleaned up on failure', tmpFilesLeft.length === 0);

        // Now perform normal scrub and confirm file is scrubbed atomically
        runPhp(`
            require_once 'backend/Database/db.php';
            require_once 'backend/includes/applicant_deletion.php';
            applicant_scrub_logs('88887777', '', '', '01788887777', '', false);
        `);
        const gsContentScrubbed = fs.readFileSync(gsLogPath, 'utf8');
        check('atomic log scrub: normal run scrubs personal details with [REDACTED_ON_REJECTION]', gsContentScrubbed.includes('[REDACTED_ON_REJECTION]') && !gsContentScrubbed.includes('88887777'));

        // -------------------------------------------------------------
        // Step 8: Shortlist Rejection Verification
        // -------------------------------------------------------------
        sql(`INSERT INTO shortlisted_members (full_name, university_id, email, gsuite_email, department, phone, semester, gender, date_of_birth, facebook_url, firstPriority, secondPriority)
             VALUES ('Shortlist Rejectee', '29999889', 'short.reject@example.com', 'short.reject@g.example.edu', 'CSE', '01799887755', '4th', 'Male', '2004-03-01', 'https://facebook.com/short.reject', 'HR', 'PR');`);

        await adminPage.goto(BASE + 'shortlisted_members.php', { waitUntil: 'networkidle2' });
        const shortRowId = sql("SELECT id FROM shortlisted_members WHERE university_id='29999889'");

        const shortOnsubmitText = await adminPage.evaluate((id) => {
            const form = [...document.querySelectorAll('form[action="handle_application.php"]')].find(f => {
                const idInput = f.querySelector('input[name="member_id"]');
                const actionInput = f.querySelector('input[name="action"]');
                return idInput && idInput.value === String(id) && actionInput && actionInput.value === 'reject';
            });
            return form ? form.getAttribute('onsubmit') || '' : '';
        }, shortRowId);

        check('shortlisted rejection confirmation contains required warning',
            shortOnsubmitText.includes("This permanently deletes this applicant's data and cannot be undone."),
            shortOnsubmitText.slice(0, 120));

        // Reject from shortlisted
        const shortRejectOutput = runPhp(`
            require_once 'backend/Database/db.php';
            require_once 'backend/includes/site.php';
            require_once 'backend/includes/applicant_deletion.php';
            $pdo = db();
            $res = applicant_reject_and_delete($pdo, (int)'${shortRowId}', 'shortlisted', 'rejected', false);
            echo json_encode($res);
        `);

        const shortRejectRes = JSON.parse(shortRejectOutput);
        check('shortlist rejection succeeds and deletes record',
            shortRejectRes.success === true && sql("SELECT COUNT(*) FROM shortlisted_members WHERE university_id='29999889'") === '0');

        // -------------------------------------------------------------
        // Step 9: Unreviewed Retention Purge
        // -------------------------------------------------------------
        // Insert dummy application older than 6 months (7 months old)
        sql(`INSERT INTO pending_applications (full_name, university_id, email, gsuite_email, department, phone, semester, gender, date_of_birth, facebook_url, firstPriority, secondPriority, created_at)
             VALUES ('Old Unreviewed', '29999890', 'old.unreviewed@example.com', 'old.unreviewed@g.example.edu', 'CSE', '01799887744', '1st', 'Female', '2004-01-01', 'https://facebook.com/old', 'PR', 'HR', DATE_SUB(NOW(), INTERVAL 7 MONTH));`);

        // Test retention purge advisory locking: acquire lock in background connection, assert concurrent purge skips
        const lockCheckRes = runPhp(`
            require_once 'backend/Database/db.php';
            require_once 'backend/includes/site.php';
            require_once 'backend/includes/applicant_deletion.php';
            $pdo = db();
            $pdo2 = (new Database())->createConnection();
            // Lock manually on separate connection
            $pdo2->query("SELECT GET_LOCK('bucuc_retention_purge', 0)");
            // Calling purge now on primary connection should fail lock acquisition and return 0
            $secondRun = applicant_purge_unreviewed($pdo, 6);
            $pdo2->query("SELECT RELEASE_LOCK('bucuc_retention_purge')");
            echo $secondRun;
        `);
        check('retention purge advisory lock prevents concurrent executions', lockCheckRes === '0', `lockResult=${lockCheckRes}`);

        const purgedOldCount = parseInt(runPhp(`
            require_once 'backend/Database/db.php';
            require_once 'backend/includes/site.php';
            require_once 'backend/includes/applicant_deletion.php';
            $pdo = db();
            echo applicant_purge_unreviewed($pdo, 6);
        `), 10);

        check('applicant_purge_unreviewed purges applications older than retention months',
            purgedOldCount >= 1,
            `purged=${purgedOldCount}`);

        check('old unreviewed applicant personal data deleted from database',
            sql("SELECT COUNT(*) FROM pending_applications WHERE university_id='29999890'") === '0');

        // Check retention purge log: contains counts only, zero personal data
        const purgeLogFile = path.join(logDir, 'retention_purge.log');
        const purgeLogContent = fs.existsSync(purgeLogFile) ? fs.readFileSync(purgeLogFile, 'utf8') : '';
        check('retention purge log records execution counts only with zero personal data',
            purgeLogContent.includes('retention_purge') &&
            !purgeLogContent.includes('Old Unreviewed') &&
            !purgeLogContent.includes('29999890') &&
            !purgeLogContent.includes('old.unreviewed@example.com'));

        // Test safe schema migration on a fresh scratch database that never had dropped tables or columns
        const scratchMigrationResult = runPhp(`
            require_once 'backend/includes/site.php';
            $scratchPdo = new PDO('mysql:host=127.0.0.1;port=${T.DB_PORT};charset=utf8mb4', 'root', 'rootpw', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $scratchPdo->exec('DROP DATABASE IF EXISTS bucuc_scratch_mig_test');
            $scratchPdo->exec('CREATE DATABASE bucuc_scratch_mig_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $scratchPdo->exec('USE bucuc_scratch_mig_test');

            @unlink('backend/logs/.schema_v13');
            site_ensure_schema($scratchPdo);

            $hasFailedSheet = db_table_exists($scratchPdo, 'failed_sheet_deletions');
            $hasAdminAttempts = db_table_exists($scratchPdo, 'admin_login_attempts');
            $scratchPdo->exec('DROP DATABASE IF EXISTS bucuc_scratch_mig_test');
            echo json_encode(['success' => true, 'hasFailedSheet' => $hasFailedSheet, 'hasAdminAttempts' => $hasAdminAttempts]);
        `);
        const migCheck = JSON.parse(scratchMigrationResult);
        check('safe schema migration runs on fresh scratch database without errors',
            migCheck.success && migCheck.hasFailedSheet && migCheck.hasAdminAttempts);

        await adminPage.close();
        await page.close();

    } finally {
        await browser.close();
    }

    const passed = out.filter(x => x[1]).length;
    console.log(`\n${passed}/${out.length} passed`);
    process.exit(passed === out.length ? 0 : 1);
})().catch(err => {
    console.error('Fatal test error:', err);
    process.exit(1);
});
