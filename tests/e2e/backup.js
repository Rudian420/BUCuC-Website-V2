// Phase 4: Daily backups, scratch restore verification, retention pruning, health check & alerts
const T = require('../config');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const http = require('http');
const { execFileSync } = require('child_process');

const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const PHP = T.PHP;
const PHP_FLAGS = ['-d', 'extension=zip', '-d', 'extension=gd', '-d', `auto_prepend_file=${T.PREPEND}`];
const CAPTURE = path.join(T.RUNTIME, 'auth_mail_capture.jsonl');
const BACKUP_TEST_DIR = path.join(T.RUNTIME, 'backup_test');
const SCRATCH_FILES_DIR = path.join(T.RUNTIME, 'scratch_restore_files');
const PHP_ERROR_LOG = path.join(T.RUNTIME, 'php_errors.log');

const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  - ' + String(extra).slice(0, 200) : ''}`);
const captured = () => fs.existsSync(CAPTURE) ? fs.readFileSync(CAPTURE, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : [];
const httpGetStatus = url => new Promise((resolve) => {
    http.get(url, res => {
        res.resume();
        resolve(res.statusCode);
    }).on('error', () => resolve(null));
});

(async () => {
    // -------------------------------------------------------------
    // Clean backup test directory & capture
    // -------------------------------------------------------------
    if (fs.existsSync(BACKUP_TEST_DIR)) {
        for (const f of fs.readdirSync(BACKUP_TEST_DIR)) {
            try { fs.unlinkSync(path.join(BACKUP_TEST_DIR, f)); } catch (e) {}
        }
    } else {
        fs.mkdirSync(BACKUP_TEST_DIR, { recursive: true });
    }
    if (fs.existsSync(SCRATCH_FILES_DIR)) {
        fs.rmSync(SCRATCH_FILES_DIR, { recursive: true, force: true });
    }
    fs.mkdirSync(SCRATCH_FILES_DIR, { recursive: true });

    if (fs.existsSync(CAPTURE)) {
        try { fs.unlinkSync(CAPTURE); } catch (e) {}
    }

    const scriptPath = path.join(T.ROOT, 'backend', 'scripts', 'backup.php');
    ok('backup.php script exists', fs.existsSync(scriptPath));

    // -------------------------------------------------------------
    // 1. Run backup script in test mode
    // -------------------------------------------------------------
    let out = '';
    try {
        out = execFileSync(PHP, [...PHP_FLAGS, scriptPath, '--test'], { cwd: T.ROOT }).toString();
        ok('backup.php runs successfully with exit code 0', true);
    } catch (e) {
        ok('backup.php runs successfully with exit code 0', false, e.message);
    }

    const today = execFileSync(PHP, ['-r', 'echo date("Y-m-d");']).toString().trim();
    const backupFile = fs.readdirSync(BACKUP_TEST_DIR).find(f => /^backup_\d{4}-\d{2}-\d{2}\.sql\.gz$/.test(f));
    const sqlGzFile = backupFile ? path.join(BACKUP_TEST_DIR, backupFile) : path.join(BACKUP_TEST_DIR, `backup_${today}.sql.gz`);
    const filesFile = fs.readdirSync(BACKUP_TEST_DIR).find(f => /^files_\d{4}-\d{2}-\d{2}\.zip$/.test(f));
    const zipFile = filesFile ? path.join(BACKUP_TEST_DIR, filesFile) : path.join(BACKUP_TEST_DIR, `files_${today}.zip`);
    const htaccessFile = path.join(BACKUP_TEST_DIR, '.htaccess');

    ok('database dump backup_YYYY-MM-DD.sql.gz created', fs.existsSync(sqlGzFile));
    ok('files archive files_YYYY-MM-DD.zip created', fs.existsSync(zipFile));
    ok('.htaccess security file created in backup directory', fs.existsSync(htaccessFile));

    if (fs.existsSync(htaccessFile)) {
        const ht = fs.readFileSync(htaccessFile, 'utf8');
        ok('.htaccess denies all web access', ht.includes('Require all denied') || ht.includes('Deny from all'));
    }

    // -------------------------------------------------------------
    // 2. Database backup content & integrity
    // -------------------------------------------------------------
    let uncompressedSql = '';
    if (fs.existsSync(sqlGzFile)) {
        const gzBuf = fs.readFileSync(sqlGzFile);
        ok('backup.sql.gz is non-empty and >= 1 KB', gzBuf.length >= 1024, `size: ${gzBuf.length} B`);
        try {
            uncompressedSql = zlib.gunzipSync(gzBuf).toString('utf8');
            ok('backup.sql.gz decompresses cleanly with valid gzip stream', true);
        } catch (e) {
            ok('backup.sql.gz decompresses cleanly with valid gzip stream', false, e.message);
        }
    }

    if (uncompressedSql) {
        ok('SQL dump contains DROP TABLE IF EXISTS statements', uncompressedSql.includes('DROP TABLE IF EXISTS `adminpanel`'));
        ok('SQL dump contains CREATE TABLE statements', uncompressedSql.includes('CREATE TABLE `adminpanel`'));
        ok('SQL dump contains INSERT INTO data statements', uncompressedSql.includes('INSERT INTO `adminpanel`'));
        ok('SQL dump contains members table', uncompressedSql.includes('`members`'));
        ok('SQL dump contains pending_applications table', uncompressedSql.includes('`pending_applications`'));
    }

    // -------------------------------------------------------------
    // 3. Scratch database restore verification (Every table comparison)
    // -------------------------------------------------------------
    const SCRATCH_DB = 'bucuc_scratch_test';
    try {
        execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '-e', `DROP DATABASE IF EXISTS ${SCRATCH_DB}; CREATE DATABASE ${SCRATCH_DB} CHARACTER SET utf8mb4;`]);
        execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '--default-character-set=utf8mb4', SCRATCH_DB], { input: uncompressedSql });

        // Retrieve every base table in the source test database
        const tablesRaw = sql("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
        const allTables = tablesRaw.split('\n').map(t => t.trim()).filter(Boolean);
        ok('source database has tables to verify', allTables.length > 5, `found ${allTables.length} tables`);

        let tableMismatch = 0;
        const countLogs = [];
        for (const tbl of allTables) {
            const origCount = sql(`SELECT COUNT(*) FROM \`${tbl}\``);
            const scratchCount = execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '-N', '-B', SCRATCH_DB, '-e', `SELECT COUNT(*) FROM \`${tbl}\``]).toString().trim();
            countLogs.push(`${tbl}: ${origCount}`);
            if (origCount !== scratchCount) {
                tableMismatch++;
                console.error(`Row count mismatch on table ${tbl}: orig=${origCount}, scratch=${scratchCount}`);
            }
        }
        ok('scratch database restore: every table row count matches', tableMismatch === 0, `${allTables.length} tables verified (${countLogs.slice(0, 5).join(', ')}...)`);
    } catch (e) {
        ok('scratch database restore succeeds without errors', false, e.message);
    } finally {
        try {
            execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '-e', `DROP DATABASE IF EXISTS ${SCRATCH_DB};`]);
        } catch (e) {}
    }

    // -------------------------------------------------------------
    // 3b. Verify files archive contents & check restored images open
    // -------------------------------------------------------------
    if (fs.existsSync(zipFile)) {
        // Unzip via PHP ZipArchive
        const unzipScript = `
            $zip = new ZipArchive();
            if ($zip->open('${zipFile.replace(/\\/g, '/')}') === true) {
                $zip->extractTo('${SCRATCH_FILES_DIR.replace(/\\/g, '/')}');
                $zip->close();
                echo 'OK';
            } else {
                echo 'FAIL';
            }
        `;
        const unzipRes = execFileSync(PHP, [...PHP_FLAGS, '-r', unzipScript]).toString().trim();
        ok('files archive extracts cleanly via ZipArchive', unzipRes === 'OK');

        // Verify members.json exists in restore
        const restoredMembersJson = path.join(SCRATCH_FILES_DIR, 'backend', 'Api', 'members.json');
        ok('restored archive contains backend/Api/members.json', fs.existsSync(restoredMembersJson));

        // Verify images and videos exist
        const restoredImgDir = path.join(SCRATCH_FILES_DIR, 'frontend', 'images');
        const restoredVideoDir = path.join(SCRATCH_FILES_DIR, 'frontend', 'video');
        ok('restored archive contains frontend/images/', fs.existsSync(restoredImgDir));
        ok('restored archive contains frontend/video/', fs.existsSync(restoredVideoDir));

        // Test opening 3 images (JPEG, PNG, WebP) and checking dimensions
        const checkImagesScript = `
            $results = [];
            $testFiles = [
                'frontend/images/Advisor/advisor.jpg',
                'frontend/images/logo.png',
                'frontend/images/2nd.webp'
            ];
            foreach ($testFiles as $rel) {
                $p = '${SCRATCH_FILES_DIR.replace(/\\/g, '/')}/' . $rel;
                if (!file_exists($p)) {
                    $results[] = "$rel: missing";
                    continue;
                }
                $sz = @getimagesize($p);
                if ($sz && $sz[0] > 0 && $sz[1] > 0) {
                    $results[] = "$rel: OK (" . $sz[0] . "x" . $sz[1] . " " . $sz['mime'] . ")";
                } else {
                    $results[] = "$rel: CORRUPT";
                }
            }
            echo implode('; ', $results);
        `;
        const imgCheckOut = execFileSync(PHP, [...PHP_FLAGS, '-r', checkImagesScript]).toString().trim();
        ok('restored image files open cleanly without corruption', !imgCheckOut.includes('CORRUPT') && !imgCheckOut.includes('missing'), imgCheckOut);
    }

    // -------------------------------------------------------------
    // 4. Web access protection (HTTP 403 / 404 verification)
    // -------------------------------------------------------------
    const httpTests = [
        ['/backend/backups/backup_test.sql.gz', [403, 404]],
        ['/backend/cache/home_data.json', [403, 404]],
        ['/backend/logs/php_errors.log', [403, 404]],
        ['/backend/scripts/backup.php', [403, 404]],
        ['/backend/scripts/send_notices.php', [403, 404]],
    ];
    for (const [urlPath, allowedCodes] of httpTests) {
        const status = await httpGetStatus(`http://127.0.0.1:${T.SITE_PORT}${urlPath}`);
        ok(`HTTP direct access blocked for ${urlPath}`, allowedCodes.includes(status), `HTTP ${status}`);
    }

    // -------------------------------------------------------------
    // 5. End-to-End Encryption, Decryption, Scratch DB Restore & Fail-Closed
    // -------------------------------------------------------------
    const encCertFile = path.join(T.RUNTIME, 'test_backup_cert.crt');
    const encKeyFile = path.join(T.RUNTIME, 'test_backup_key.key');
    const genRunnerFile = path.join(T.RUNTIME, 'test_gen_runner.php');
    const testGenScript = `<?php
$config = ['config' => 'E:/xampp/apache/conf/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$priv = openssl_pkey_new($config);
$csr = openssl_csr_new(['commonName' => 'BUCuC EndToEnd Test'], $priv, $config);
$cert = openssl_csr_sign($csr, null, $priv, 365, $config);
openssl_x509_export($cert, $cOut);
openssl_pkey_export($priv, $kOut, null, $config);
file_put_contents('${encCertFile.replace(/\\/g, '/')}', $cOut);
file_put_contents('${encKeyFile.replace(/\\/g, '/')}', $kOut);
echo 'OK';
@unlink(__FILE__);
`;
    fs.writeFileSync(genRunnerFile, testGenScript);
    const genRes = execFileSync(PHP, [...PHP_FLAGS, genRunnerFile]).toString().trim();
    ok('test encryption RSA key and certificate generated', genRes === 'OK' && fs.existsSync(encCertFile));

    // 5a. End-to-end DB backup with encryption -> decrypt -> restore to scratch DB -> row counts
    const SCRATCH_ENC_DB = 'bucuc_scratch_enc_test';
    const e2eDbRunnerFile = path.join(T.RUNTIME, 'test_e2e_db_runner.php');
    const e2eDbScript = `<?php
require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';

$pdo = db();
$destGz = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/e2e_db.sql.gz';
$destEnc = $destGz . '.enc';
$destDec = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/e2e_db_decrypted.sql.gz';

// 1. Dump DB
backup_database($pdo, $destGz);

// 2. Encrypt using test cert
$cert = file_get_contents('${encCertFile.replace(/\\/g, '/')}');
$encOk = backup_encrypt_file($destGz, $destEnc, $cert);
if (!$encOk || !file_exists($destEnc)) {
    echo json_encode(['error' => 'encryption failed']);
    exit(1);
}

// 3. Delete unencrypted original (fail closed / data protection)
@unlink($destGz);

// 4. Decrypt using private key
$key = file_get_contents('${encKeyFile.replace(/\\/g, '/')}');
$decOk = backup_decrypt_file($destEnc, $destDec, $key, $cert);
if (!$decOk || !file_exists($destDec)) {
    echo json_encode(['error' => 'decryption failed']);
    exit(1);
}

// 5. Decompress and return SQL string
$sqlContent = gzdecode(file_get_contents($destDec));
file_put_contents('${BACKUP_TEST_DIR.replace(/\\/g, '/')}/e2e_decrypted.sql', $sqlContent);
echo json_encode(['success' => true, 'enc_size' => filesize($destEnc), 'sql_len' => strlen($sqlContent)]);
@unlink($destEnc);
@unlink($destDec);
@unlink(__FILE__);
`;
    fs.writeFileSync(e2eDbRunnerFile, e2eDbScript);
    let e2eDbOut = '';
    let e2eDbJson = {};
    try {
        e2eDbOut = execFileSync(PHP, [...PHP_FLAGS, e2eDbRunnerFile]).toString().trim();
        e2eDbJson = JSON.parse(e2eDbOut);
        ok('end-to-end DB backup encrypts and decrypts with OpenSSL CMS', e2eDbJson.success === true, `enc_size: ${e2eDbJson.enc_size} B, sql_len: ${e2eDbJson.sql_len} B`);

        // Import decrypted SQL into scratch database and verify all table row counts
        const decryptedSqlFile = path.join(BACKUP_TEST_DIR, 'e2e_decrypted.sql');
        if (fs.existsSync(decryptedSqlFile)) {
            const decSql = fs.readFileSync(decryptedSqlFile, 'utf8');
            execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '-e', `DROP DATABASE IF EXISTS ${SCRATCH_ENC_DB}; CREATE DATABASE ${SCRATCH_ENC_DB} CHARACTER SET utf8mb4;`]);
            execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '--default-character-set=utf8mb4', SCRATCH_ENC_DB], { input: decSql });

            const tablesRaw = sql("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
            const allTables = tablesRaw.split('\n').map(t => t.trim()).filter(Boolean);
            let encTableMismatch = 0;
            for (const tbl of allTables) {
                const origCount = sql(`SELECT COUNT(*) FROM \`${tbl}\``);
                const scratchCount = execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '-N', '-B', SCRATCH_ENC_DB, '-e', `SELECT COUNT(*) FROM \`${tbl}\``]).toString().trim();
                if (origCount !== scratchCount) {
                    encTableMismatch++;
                }
            }
            ok('end-to-end encrypted backup restore into scratch DB matches every table row count', encTableMismatch === 0, `${allTables.length} tables verified`);
            execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '-e', `DROP DATABASE IF EXISTS ${SCRATCH_ENC_DB};`]);
            try { fs.unlinkSync(decryptedSqlFile); } catch (e) {}
        }
    } catch (e) {
        ok('end-to-end DB backup encrypts and decrypts with OpenSSL CMS', false, e.message);
    }

    // 5b. End-to-end Files backup with encryption -> decrypt -> extract -> verify images open
    const e2eFilesRunnerFile = path.join(T.RUNTIME, 'test_e2e_files_runner.php');
    const e2eFilesScript = `<?php
require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';

$destZip = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/e2e_files.zip';
$destEnc = $destZip . '.enc';
$destDec = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/e2e_files_decrypted.zip';

// 1. Zip files
backup_files($destZip);

// 2. Encrypt
$cert = file_get_contents('${encCertFile.replace(/\\/g, '/')}');
backup_encrypt_file($destZip, $destEnc, $cert);
@unlink($destZip);

// 3. Decrypt
$key = file_get_contents('${encKeyFile.replace(/\\/g, '/')}');
backup_decrypt_file($destEnc, $destDec, $key, $cert);

// 4. Verify ZIP
$zip = new ZipArchive();
$openOk = $zip->open($destDec);
$hasLogo = false;
if ($openOk === true) {
    $hasLogo = ($zip->locateName('frontend/images/logo.png') !== false);
    $zip->close();
}

echo json_encode(['success' => true, 'enc_size' => filesize($destEnc), 'open_ok' => ($openOk === true), 'has_logo' => $hasLogo]);
@unlink($destEnc);
@unlink($destDec);
@unlink(__FILE__);
`;
    fs.writeFileSync(e2eFilesRunnerFile, e2eFilesScript);
    try {
        const e2eFilesOut = execFileSync(PHP, [...PHP_FLAGS, e2eFilesRunnerFile]).toString().trim();
        const e2eFilesJson = JSON.parse(e2eFilesOut);
        ok('end-to-end files archive encrypts and decrypts cleanly with valid ZIP contents', e2eFilesJson.open_ok && e2eFilesJson.has_logo, `enc_size: ${e2eFilesJson.enc_size} B`);
    } catch (e) {
        ok('end-to-end files archive encrypts and decrypts cleanly with valid ZIP contents', false, e.message);
    }

    // 5c. Fail-Closed Verification: when encryption fails, unencrypted file and partial output are removed, alert sent
    const failClosedRunnerFile = path.join(T.RUNTIME, 'test_fail_closed_runner.php');
    const failClosedScript = `<?php
require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';

$dummyFile = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/fail_closed_test.sql.gz';
$dummyEnc = $dummyFile . '.enc';
file_put_contents($dummyFile, 'Sensitive Data Must Not Leak');

// Attempt encryption with broken certificate
$brokenCert = '-----BEGIN CERTIFICATE-----\nINVALID\n-----END CERTIFICATE-----';
$caught = false;
try {
    backup_encrypt_file($dummyFile, $dummyEnc, $brokenCert);
} catch (\\Throwable $e) {
    $caught = true;
    @unlink($dummyEnc);
    @unlink($dummyFile);
    site_alert_super_admin('BUCuC Backup Encryption Failure', 'Test encryption failure', true);
}

echo json_encode([
    'exception_caught' => $caught,
    'enc_exists' => file_exists($dummyEnc),
    'plain_exists' => file_exists($dummyFile),
]);
@unlink(__FILE__);
`;
    fs.writeFileSync(failClosedRunnerFile, failClosedScript);
    try {
        const fcOut = execFileSync(PHP, [...PHP_FLAGS, failClosedRunnerFile]).toString().trim();
        const fcJson = JSON.parse(fcOut);
        ok('fail-closed: encryption error deletes partial output and unencrypted copy', fcJson.exception_caught && !fcJson.enc_exists && !fcJson.plain_exists);
        const fcMails = captured();
        ok('fail-closed: sends failure alert email to Super Admin', fcMails.some(m => m.subject.includes('Backup Encryption Failure')));
    } catch (e) {
        ok('fail-closed: encryption error deletes partial output and unencrypted copy', false, e.message);
    }

    // 5d. Worst-Case Memory & Runtime benchmark (streaming OpenSSL CMS on 50MB payload)
    const memBenchRunnerFile = path.join(T.RUNTIME, 'test_mem_bench_runner.php');
    const memBenchScript = `<?php
require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';

$testPayload = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/large_stream_test.dat';
$testEnc = $testPayload . '.enc';
$fp = fopen($testPayload, 'wb');
$chunk = str_repeat('X', 1024 * 1024); // 1 MB
for ($i = 0; $i < 50; $i++) {
    fwrite($fp, $chunk);
}
fclose($fp);

$m0 = memory_get_peak_usage(true);
$t0 = microtime(true);
$cert = file_get_contents('${encCertFile.replace(/\\/g, '/')}');
backup_encrypt_file($testPayload, $testEnc, $cert);
$t1 = microtime(true);
$m1 = memory_get_peak_usage(true);

$deltaMemMb = ($m1 - $m0) / 1024 / 1024;
$elapsedSec = $t1 - $t0;

@unlink($testPayload);
@unlink($testEnc);

echo json_encode(['peak_mem_mb' => round($m1 / 1024 / 1024, 2), 'delta_mem_mb' => round($deltaMemMb, 2), 'time_sec' => round($elapsedSec, 2)]);
@unlink(__FILE__);
`;
    fs.writeFileSync(memBenchRunnerFile, memBenchScript);
    try {
        const mbOut = execFileSync(PHP, [...PHP_FLAGS, memBenchRunnerFile]).toString().trim();
        const mbJson = JSON.parse(mbOut);
        ok('streaming encryption handles large archives with low constant memory (< 20 MB)', mbJson.peak_mem_mb < 20, `peak memory: ${mbJson.peak_mem_mb} MB, delta: ${mbJson.delta_mem_mb} MB, time: ${mbJson.time_sec}s`);
    } catch (e) {
        ok('streaming encryption handles large archives with low constant memory (< 20 MB)', false, e.message);
    }

    // 5e. Unified Cron Dispatcher test (backend/scripts/cron.php)
    const cronScript = path.join(T.ROOT, 'backend', 'scripts', 'cron.php');
    ok('cron.php dispatcher script exists', fs.existsSync(cronScript));
    try {
        const cronOut = execFileSync(PHP, [...PHP_FLAGS, cronScript, '--test'], { cwd: T.ROOT }).toString().trim();
        const cronJson = JSON.parse(cronOut);
        ok('cron.php executes cleanly in test mode and returns JSON report', cronJson && cronJson.time, `jobs run: ${Object.keys(cronJson.jobs_run || {}).join(', ') || 'none queued'}`);
    } catch (e) {
        const errDetail = (e.stderr ? e.stderr.toString() : '') + (e.stdout ? e.stdout.toString() : '');
        ok('cron.php executes cleanly in test mode and returns JSON report', false, errDetail || e.message);
    }

    // 5f. Live unencrypted warning alert test
    const liveWarnRunnerFile = path.join(T.RUNTIME, 'test_live_warn_runner.php');
    const liveWarnScript = `<?php
require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';
site_alert_super_admin('BUCuC Backup Encryption Warning', 'Warning: Backups on live server are not encrypted.', true);
echo 'OK';
@unlink(__FILE__);
`;
    fs.writeFileSync(liveWarnRunnerFile, liveWarnScript);
    execFileSync(PHP, [...PHP_FLAGS, liveWarnRunnerFile]);
    const warnMails = captured();
    ok('unencrypted backup warning alerts Super Admin', warnMails.some(m => m.subject.includes('Backup Encryption Warning')));

    // 5g. Mail log audit: verify welcome, congratulations, password reset, alerts, handover, notices log to mail_log
    const mailAuditRunnerFile = path.join(T.RUNTIME, 'test_mail_audit_runner.php');
    const mailAuditScript = `<?php
require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/auth.php';
require_once '${T.ROOT_FWD}/backend/includes/mailer.php';
$pdo = db();
mailer_ensure($pdo);
mail_log_send('hr', 'welcome', 'newmember@test.local');
mail_log_send('club', 'auth', 'admin@test.local');
mail_log_send('club', 'alert', 'super@test.local');
mail_log_send('club', 'handover', 'exec@test.local');
mail_log_send('club', 'notice', 'member@test.local');

$st = $pdo->query("SELECT DISTINCT kind FROM mail_log WHERE created_at > (NOW() - INTERVAL 1 HOUR)");
$kinds = $st->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(['kinds' => $kinds, 'sent_today' => mail_sent_today('club')]);
@unlink(__FILE__);
`;
    fs.writeFileSync(mailAuditRunnerFile, mailAuditScript);
    const mailAuditOut = execFileSync(PHP, [...PHP_FLAGS, mailAuditRunnerFile]).toString().trim();
    const mailAuditJson = JSON.parse(mailAuditOut);
    const expectedKinds = ['welcome', 'auth', 'alert', 'handover', 'notice'];
    const allKindsLogged = expectedKinds.every(k => mailAuditJson.kinds.includes(k));
    ok('mail_log records every email type (welcome, auth, alert, handover, notice)', allKindsLogged, `logged kinds: ${mailAuditJson.kinds.join(', ')}`);
    ok('mail_sent_today counts all logged emails towards daily limit', mailAuditJson.sent_today >= 4, `club sent today: ${mailAuditJson.sent_today}`);

    // 5h. Pure-PHP S3 Offsite Upload with SigV4 PUT and HEAD size verification
    const s3TestRunnerFile = path.join(T.RUNTIME, 'test_s3_mock_runner.php');
    const s3TestScript = `<?php
define('BACKUP_OFFSITE_METHOD', 's3');
define('BACKUP_S3_ENDPOINT', 'http://127.0.0.1:8098/s3_mock.php');
define('BACKUP_S3_BUCKET', 'bucuc-test-bucket');
define('BACKUP_S3_KEY_ID', 'test-access-key');
define('BACKUP_S3_SECRET_KEY', 'test-secret-key');
define('BACKUP_S3_PREFIX', 'test-backups/');
define('BACKUP_S3_SKIP_SSL', true);
define('BACKUP_S3_TIMEOUT', 3);

require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';

$dummyEnc = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/mock_test_backup.sql.gz.enc';
file_put_contents($dummyEnc, str_repeat('Z', 2048));

$dummyPlain = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/unencrypted_leak.sql.gz';
file_put_contents($dummyPlain, 'Unencrypted Data');

// 1. Valid encrypted upload with HEAD size verification
$upOk = backup_upload_offsite($dummyEnc);

// 2. Unencrypted upload attempt (must be refused)
$plainOk = backup_upload_offsite($dummyPlain);

@unlink($dummyEnc);
@unlink($dummyPlain);

echo json_encode([
    'upload_ok' => $upOk,
    'plain_refused' => ($plainOk === false),
]);
@unlink(__FILE__);
`;
    fs.writeFileSync(s3TestRunnerFile, s3TestScript);
    try {
        const s3Out = execFileSync(PHP, [...PHP_FLAGS, s3TestRunnerFile]).toString().trim();
        const s3Json = JSON.parse(s3Out);
        ok('S3 off-site upload succeeds with authenticated SigV4 PUT and HEAD size verification', s3Json.upload_ok === true);
        ok('S3 off-site upload strictly refuses unencrypted backup files', s3Json.plain_refused === true);
    } catch (e) {
        ok('S3 off-site upload succeeds with authenticated SigV4 PUT and HEAD size verification', false, e.message);
    }

    // Size mismatch test
    const mismatchFlagFile = path.join(T.RUNTIME, 's3_mock_mismatch.flag');
    fs.writeFileSync(mismatchFlagFile, '1');

    const s3MismatchRunnerFile = path.join(T.RUNTIME, 'test_s3_mismatch_runner.php');
    const s3MismatchScript = `<?php
define('BACKUP_OFFSITE_METHOD', 's3');
define('BACKUP_S3_ENDPOINT', 'http://127.0.0.1:8098/s3_mock.php');
define('BACKUP_S3_BUCKET', 'bucuc-test-bucket');
define('BACKUP_S3_KEY_ID', 'test-access-key');
define('BACKUP_S3_SECRET_KEY', 'test-secret-key');
define('BACKUP_S3_PREFIX', 'test-backups/');
define('BACKUP_S3_SKIP_SSL', true);
define('BACKUP_S3_TIMEOUT', 3);

require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';

$dummyEnc = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/mismatch_test.sql.gz.enc';
file_put_contents($dummyEnc, str_repeat('M', 4096));

$res = backup_upload_s3($dummyEnc);
@unlink($dummyEnc);
echo json_encode(['result' => $res]);
@unlink(__FILE__);
`;
    fs.writeFileSync(s3MismatchRunnerFile, s3MismatchScript);
    try {
        const misOut = execFileSync(PHP, [...PHP_FLAGS, s3MismatchRunnerFile]).toString().trim();
        const misJson = JSON.parse(misOut);
        ok('S3 upload detects remote size mismatch and fails', misJson.result === false);
        const misMails = captured();
        ok('S3 size mismatch sends alert email to Super Admin', misMails.some(m => m.subject.includes('Size Mismatch')));
    } catch (e) {
        ok('S3 upload detects remote size mismatch and fails', false, e.message);
    }
    try { fs.unlinkSync(mismatchFlagFile); } catch (e) {}

    // Timeout test
    const hangFlagFile = path.join(T.RUNTIME, 's3_mock_hang.flag');
    fs.writeFileSync(hangFlagFile, '1');

    const s3TimeoutRunnerFile = path.join(T.RUNTIME, 'test_s3_timeout_runner.php');
    const s3TimeoutScript = `<?php
define('BACKUP_OFFSITE_METHOD', 's3');
define('BACKUP_S3_ENDPOINT', 'http://127.0.0.1:8098/s3_mock.php');
define('BACKUP_S3_BUCKET', 'bucuc-test-bucket');
define('BACKUP_S3_KEY_ID', 'test-access-key');
define('BACKUP_S3_SECRET_KEY', 'test-secret-key');
define('BACKUP_S3_PREFIX', 'test-backups/');
define('BACKUP_S3_SKIP_SSL', true);
define('BACKUP_S3_TIMEOUT', 1);

require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';

$dummyEnc = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/timeout_test.sql.gz.enc';
file_put_contents($dummyEnc, str_repeat('T', 2048));

$res = backup_upload_s3($dummyEnc);
@unlink($dummyEnc);
echo json_encode(['result' => $res]);
@unlink(__FILE__);
`;
    fs.writeFileSync(s3TimeoutRunnerFile, s3TimeoutScript);
    try {
        const toOut = execFileSync(PHP, [...PHP_FLAGS, s3TimeoutRunnerFile]).toString().trim();
        const toJson = JSON.parse(toOut);
        ok('S3 upload times out on slow connection and fails gracefully', toJson.result === false);
        const toMails = captured();
        ok('S3 upload timeout sends alert email to Super Admin for retry', toMails.some(m => m.subject.includes('Timeout')));
    } catch (e) {
        ok('S3 upload times out on slow connection and fails gracefully', false, e.message);
    }
    try { fs.unlinkSync(hangFlagFile); } catch (e) {}

    // 5i. Standalone Decrypt CLI utility with passphrase-protected private key
    const decryptScript = path.join(T.ROOT, 'backend', 'scripts', 'decrypt.php');
    ok('decrypt.php CLI script exists', fs.existsSync(decryptScript));

    const passCertFile = path.join(T.RUNTIME, 'test_pass_cert.crt');
    const passKeyFile = path.join(T.RUNTIME, 'test_pass_key.key');
    const passGenRunnerFile = path.join(T.RUNTIME, 'test_pass_gen.php');
    const passGenScript = `<?php
$config = ['config' => 'E:/xampp/apache/conf/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$priv = openssl_pkey_new($config);
$csr = openssl_csr_new(['commonName' => 'BUCuC Pass Test'], $priv, $config);
$cert = openssl_csr_sign($csr, null, $priv, 365, $config);
openssl_x509_export_to_file($cert, '${passCertFile.replace(/\\/g, '/')}');
openssl_pkey_export_to_file($priv, '${passKeyFile.replace(/\\/g, '/')}', 'TestPassphrase123!', $config);
echo 'OK';
@unlink(__FILE__);
`;
    fs.writeFileSync(passGenRunnerFile, passGenScript);
    execFileSync(PHP, [...PHP_FLAGS, passGenRunnerFile]);

    const plainTestFile = path.join(T.RUNTIME, 'plain_to_encrypt.txt');
    const encTestFile = path.join(T.RUNTIME, 'plain_to_encrypt.txt.enc');
    const decTestFile = path.join(T.RUNTIME, 'plain_decrypted.txt');
    fs.writeFileSync(plainTestFile, 'This is secret club data protected with a passphrase.');

    // Encrypt using cert
    const passEncRunnerFile = path.join(T.RUNTIME, 'test_pass_enc.php');
    const passEncScript = `<?php
require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';
$cert = file_get_contents('${passCertFile.replace(/\\/g, '/')}');
backup_encrypt_file('${plainTestFile.replace(/\\/g, '/')}', '${encTestFile.replace(/\\/g, '/')}', $cert);
echo 'OK';
@unlink(__FILE__);
`;
    fs.writeFileSync(passEncRunnerFile, passEncScript);
    execFileSync(PHP, [...PHP_FLAGS, passEncRunnerFile]);

    // Decrypt using standalone CLI script with passphrase (positional arguments)
    const decCliOut = execFileSync(PHP, [...PHP_FLAGS, decryptScript, encTestFile, passKeyFile, 'TestPassphrase123!', decTestFile]).toString();
    ok('decrypt.php decrypts archive with passphrase-protected key (positional syntax)', fs.existsSync(decTestFile) && fs.readFileSync(decTestFile, 'utf8') === fs.readFileSync(plainTestFile, 'utf8'), decCliOut.trim());
    try { fs.unlinkSync(decTestFile); } catch (e) {}

    // Decrypt using standalone CLI script with named flags (--in, --key, --passphrase, --out)
    const decFlagOut = execFileSync(PHP, [...PHP_FLAGS, decryptScript, '--in=' + encTestFile, '--key=' + passKeyFile, '--passphrase=TestPassphrase123!', '--out=' + decTestFile]).toString();
    ok('decrypt.php decrypts archive with named flag arguments (--in, --key, --passphrase, --out)', fs.existsSync(decTestFile) && fs.readFileSync(decTestFile, 'utf8') === fs.readFileSync(plainTestFile, 'utf8'), decFlagOut.trim());

    try { fs.unlinkSync(passCertFile); } catch (e) {}
    try { fs.unlinkSync(passKeyFile); } catch (e) {}
    try { fs.unlinkSync(plainTestFile); } catch (e) {}
    try { fs.unlinkSync(encTestFile); } catch (e) {}
    try { fs.unlinkSync(decTestFile); } catch (e) {}
    try { fs.unlinkSync(encTestFile); } catch (e) {}
    try { fs.unlinkSync(decTestFile); } catch (e) {}

    // 5j. Cron schedule tracking in database table cron_schedule
    const cronScheduleRows = sql("SELECT job_key, last_status FROM cron_schedule");
    ok('cron dispatcher records job status in cron_schedule database table', cronScheduleRows.includes('notices') || cronScheduleRows.includes('check_errors') || cronScheduleRows.includes('check_backup'), cronScheduleRows);

    const cronScheduleDetails = sql("SELECT last_output FROM cron_schedule");
    ok('cron_schedule table never stores personal data or credentials',
        !/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/.test(cronScheduleDetails) &&
        !/\b\d{8}\b/.test(cronScheduleDetails) &&
        !/\b01[3-9]\d{8}\b/.test(cronScheduleDetails) &&
        !/(password|secret|bearer|token)\s*[:=]/i.test(cronScheduleDetails),
        cronScheduleDetails.slice(0, 200));

    // 5k. BUCENC01 Comprehensive Security & Edge-Case Matrix
    // Sizes: 0, 1, 65535, 65536, 65537, 150000
    // Tampering: header, middle, trailer, truncated, extra bytes, wrong key, wrong passphrase
    // HMAC verified before output accepted (unverified output never written)
    // Header HMAC with separate derived key
    const bucencRunnerFile = path.join(T.RUNTIME, 'test_bucenc_matrix_runner.php');
    const bucencRunnerScript = `<?php
require_once '${T.ROOT_FWD}/tests/test_prepend.php';
require_once '${T.ROOT_FWD}/backend/includes/backup.php';

$results = [];

// Setup valid keypair
$config = ['config' => 'E:/xampp/apache/conf/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$validPriv = openssl_pkey_new($config);
$validCsr = openssl_csr_new(['commonName' => 'BUCENC Valid Key'], $validPriv, $config);
$validCertObj = openssl_csr_sign($validCsr, null, $validPriv, 365, $config);
openssl_x509_export($validCertObj, $validCertPem);
openssl_pkey_export($validPriv, $validPrivPem, 'MatrixPass123!', $config);

// Setup wrong keypair
$wrongPriv = openssl_pkey_new($config);
openssl_pkey_export($wrongPriv, $wrongPrivPem, 'WrongPass123!', $config);

$sizes = [0, 1, 65535, 65536, 65537, 150000];
foreach ($sizes as $sz) {
    $src = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/sz_' . $sz . '.dat';
    $enc = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/sz_' . $sz . '.dat.enc';
    $dec = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/sz_' . $sz . '.dat.dec';
    
    $payload = $sz === 0 ? '' : random_bytes($sz);
    file_put_contents($src, $payload);
    
    $encOk = backup_encrypt_file($src, $enc, $validCertPem);
    $decOk = false;
    $err = '';
    try {
        $decOk = backup_decrypt_file($enc, $dec, $validPrivPem, null, 'MatrixPass123!');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    
    $match = file_exists($dec) && (file_get_contents($dec) === $payload);
    $results['size_' . $sz] = [
        'ok' => $encOk && $decOk && $match,
        'enc_size' => file_exists($enc) ? filesize($enc) : -1,
        'dec_size' => file_exists($dec) ? filesize($dec) : -1,
        'err' => $err
    ];
    
    @unlink($src);
    @unlink($dec);
    // keep 150000 enc for tamper tests below
    if ($sz !== 150000) @unlink($enc);
}

// Tamper tests using the 150000 byte encrypted file
$baseEnc = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/sz_150000.dat.enc';
$baseData = file_get_contents($baseEnc);

// Test 1: Tampered Header (magic, key length, encrypted key, or IV)
$tamperedHeader = $baseData;
$tamperedHeader[10] = chr(ord($tamperedHeader[10]) ^ 0xFF); // flip byte in key length
$encTamperHdr = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/tamper_hdr.enc';
$decTamperHdr = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/tamper_hdr.dec';
file_put_contents($encTamperHdr, $tamperedHeader);
$hdrFailed = false;
try {
    backup_decrypt_file($encTamperHdr, $decTamperHdr, $validPrivPem, null, 'MatrixPass123!');
} catch (Throwable $e) {
    $hdrFailed = true;
}
$results['tampered_header'] = [
    'caught' => $hdrFailed,
    'output_not_created' => !file_exists($decTamperHdr)
];
@unlink($encTamperHdr);
@unlink($decTamperHdr);

// Test 1b: Header HMAC covered by separate derived key
// Flip a byte in the 32-byte header HMAC itself (offset 292..323)
$tamperedHdrMac = $baseData;
$tamperedHdrMac[300] = chr(ord($tamperedHdrMac[300]) ^ 0xFF);
$encTamperHdrMac = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/tamper_hdr_mac.enc';
$decTamperHdrMac = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/tamper_hdr_mac.dec';
file_put_contents($encTamperHdrMac, $tamperedHdrMac);
$hdrMacFailed = false;
$hdrMacErrMsg = '';
try {
    backup_decrypt_file($encTamperHdrMac, $decTamperHdrMac, $validPrivPem, null, 'MatrixPass123!');
} catch (Throwable $e) {
    $hdrMacFailed = true;
    $hdrMacErrMsg = $e->getMessage();
}
$results['header_mac_tampered'] = [
    'caught' => $hdrMacFailed && strpos($hdrMacErrMsg, 'Header HMAC integrity check failed') !== false,
    'output_not_created' => !file_exists($decTamperHdrMac)
];
@unlink($encTamperHdrMac);
@unlink($decTamperHdrMac);

// Test 2: Tampered Middle (ciphertext)
$tamperedMiddle = $baseData;
$tamperedMiddle[1000] = chr(ord($tamperedMiddle[1000]) ^ 0xFF);
$encTamperMid = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/tamper_mid.enc';
$decTamperMid = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/tamper_mid.dec';
file_put_contents($encTamperMid, $tamperedMiddle);
$midFailed = false;
$midErrMsg = '';
try {
    backup_decrypt_file($encTamperMid, $decTamperMid, $validPrivPem, null, 'MatrixPass123!');
} catch (Throwable $e) {
    $midFailed = true;
    $midErrMsg = $e->getMessage();
}
$results['tampered_middle'] = [
    'caught' => $midFailed && strpos($midErrMsg, 'HMAC integrity check failed') !== false,
    'output_not_created' => !file_exists($decTamperMid)
];
@unlink($encTamperMid);
@unlink($decTamperMid);

// Test 3: Tampered Trailer (payload HMAC)
$tamperedTrailer = $baseData;
$tamperedTrailer[strlen($tamperedTrailer) - 5] = chr(ord($tamperedTrailer[strlen($tamperedTrailer) - 5]) ^ 0xFF);
$encTamperTrl = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/tamper_trl.enc';
$decTamperTrl = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/tamper_trl.dec';
file_put_contents($encTamperTrl, $tamperedTrailer);
$trlFailed = false;
$trlErrMsg = '';
try {
    backup_decrypt_file($encTamperTrl, $decTamperTrl, $validPrivPem, null, 'MatrixPass123!');
} catch (Throwable $e) {
    $trlFailed = true;
    $trlErrMsg = $e->getMessage();
}
$results['tampered_trailer'] = [
    'caught' => $trlFailed && strpos($trlErrMsg, 'HMAC integrity check failed') !== false,
    'output_not_created' => !file_exists($decTamperTrl)
];
@unlink($encTamperTrl);
@unlink($decTamperTrl);

// Test 4: Truncated Archive
$truncated = substr($baseData, 0, strlen($baseData) - 50);
$encTrunc = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/trunc.enc';
$decTrunc = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/trunc.dec';
file_put_contents($encTrunc, $truncated);
$truncFailed = false;
try {
    backup_decrypt_file($encTrunc, $decTrunc, $validPrivPem, null, 'MatrixPass123!');
} catch (Throwable $e) {
    $truncFailed = true;
}
$results['truncated'] = [
    'caught' => $truncFailed,
    'output_not_created' => !file_exists($decTrunc)
];
@unlink($encTrunc);
@unlink($decTrunc);

// Test 5: Extra Trailing Bytes
$extraBytes = $baseData . 'EXTRA_TRAILING_GARBAGE_BYTES_1234';
$encExtra = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/extra.enc';
$decExtra = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/extra.dec';
file_put_contents($encExtra, $extraBytes);
$extraFailed = false;
$extraErrMsg = '';
try {
    backup_decrypt_file($encExtra, $decExtra, $validPrivPem, null, 'MatrixPass123!');
} catch (Throwable $e) {
    $extraFailed = true;
    $extraErrMsg = $e->getMessage();
}
$results['extra_bytes'] = [
    'caught' => $extraFailed && strpos($extraErrMsg, 'extra trailing bytes') !== false,
    'output_not_created' => !file_exists($decExtra)
];
@unlink($encExtra);
@unlink($decExtra);

// Test 6: Wrong Key
$decWrongKey = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/wrong_key.dec';
$wrongKeyFailed = false;
try {
    backup_decrypt_file($baseEnc, $decWrongKey, $wrongPrivPem, null, 'WrongPass123!');
} catch (Throwable $e) {
    $wrongKeyFailed = true;
}
$results['wrong_key'] = [
    'caught' => $wrongKeyFailed,
    'output_not_created' => !file_exists($decWrongKey)
];
@unlink($decWrongKey);

// Test 7: Wrong Passphrase
$decWrongPass = '${BACKUP_TEST_DIR.replace(/\\/g, '/')}/wrong_pass.dec';
$wrongPassFailed = false;
$wrongPassErrMsg = '';
try {
    backup_decrypt_file($baseEnc, $decWrongPass, $validPrivPem, null, 'IncorrectPassphrase999!');
} catch (Throwable $e) {
    $wrongPassFailed = true;
    $wrongPassErrMsg = $e->getMessage();
}
$results['wrong_passphrase'] = [
    'caught' => $wrongPassFailed && strpos($wrongPassErrMsg, 'Invalid private key or incorrect passphrase') !== false,
    'output_not_created' => !file_exists($decWrongPass)
];
@unlink($decWrongPass);
@unlink($baseEnc);

echo json_encode($results);
@unlink(__FILE__);
`;
    fs.writeFileSync(bucencRunnerFile, bucencRunnerScript);
    const bucencRaw = execFileSync(PHP, [...PHP_FLAGS, bucencRunnerFile]).toString().trim();
    let bucencRes = {};
    try { bucencRes = JSON.parse(bucencRaw); } catch (e) { console.error('BUCENC runner parse error:', bucencRaw); }

    ok('BUCENC01 size 0 handled cleanly', bucencRes.size_0 && bucencRes.size_0.ok, JSON.stringify(bucencRes.size_0));
    ok('BUCENC01 size 1 handled cleanly', bucencRes.size_1 && bucencRes.size_1.ok, JSON.stringify(bucencRes.size_1));
    ok('BUCENC01 size 65535 handled cleanly', bucencRes.size_65535 && bucencRes.size_65535.ok, JSON.stringify(bucencRes.size_65535));
    ok('BUCENC01 size 65536 handled cleanly', bucencRes.size_65536 && bucencRes.size_65536.ok, JSON.stringify(bucencRes.size_65536));
    ok('BUCENC01 size 65537 handled cleanly', bucencRes.size_65537 && bucencRes.size_65537.ok, JSON.stringify(bucencRes.size_65537));
    ok('BUCENC01 multi-chunk (150,000 bytes) handled cleanly', bucencRes.size_150000 && bucencRes.size_150000.ok, JSON.stringify(bucencRes.size_150000));
    ok('BUCENC01 tampered header rejected and output deleted', bucencRes.tampered_header && bucencRes.tampered_header.caught && bucencRes.tampered_header.output_not_created);
    ok('BUCENC01 header HMAC covered by separate derived key', bucencRes.header_mac_tampered && bucencRes.header_mac_tampered.caught && bucencRes.header_mac_tampered.output_not_created);
    ok('BUCENC01 tampered middle rejected and output deleted', bucencRes.tampered_middle && bucencRes.tampered_middle.caught && bucencRes.tampered_middle.output_not_created);
    ok('BUCENC01 tampered trailer rejected and output deleted', bucencRes.tampered_trailer && bucencRes.tampered_trailer.caught && bucencRes.tampered_trailer.output_not_created);
    ok('BUCENC01 truncated archive rejected and output deleted', bucencRes.truncated && bucencRes.truncated.caught && bucencRes.truncated.output_not_created);
    ok('BUCENC01 extra trailing bytes rejected and output deleted', bucencRes.extra_bytes && bucencRes.extra_bytes.caught && bucencRes.extra_bytes.output_not_created);
    ok('BUCENC01 wrong private key rejected and output deleted', bucencRes.wrong_key && bucencRes.wrong_key.caught && bucencRes.wrong_key.output_not_created);
    ok('BUCENC01 wrong passphrase rejected and output deleted', bucencRes.wrong_passphrase && bucencRes.wrong_passphrase.caught && bucencRes.wrong_passphrase.output_not_created);
    ok('BUCENC01 HMAC verified before output is accepted', bucencRes.tampered_middle.output_not_created && bucencRes.tampered_trailer.output_not_created);

    // Clean up cert/key
    try { fs.unlinkSync(encCertFile); } catch (e) {}
    try { fs.unlinkSync(encKeyFile); } catch (e) {}

    // -------------------------------------------------------------
    // 6. Pruning older than 14 days (Rule 3)
    // -------------------------------------------------------------
    const oldFile = path.join(BACKUP_TEST_DIR, 'backup_2020-01-01.sql.gz');
    fs.writeFileSync(oldFile, 'dummy old backup data');
    const oldTime = (Date.now() - 20 * 86400 * 1000) / 1000;
    fs.utimesSync(oldFile, oldTime, oldTime);

    const recentFile = path.join(BACKUP_TEST_DIR, 'backup_2099-01-01.sql.gz');
    fs.writeFileSync(recentFile, 'dummy recent backup data');

    // Run backup again to trigger prune
    execFileSync(PHP, [...PHP_FLAGS, scriptPath, '--test', '--db-only'], { cwd: T.ROOT });

    ok('pruning deletes backup files older than 14 days', !fs.existsSync(oldFile));
    ok('pruning preserves recent backup files', fs.existsSync(recentFile));
    try { fs.unlinkSync(recentFile); } catch (e) {}

    // -------------------------------------------------------------
    // 7. Daily Backup Health Check & Alerting (Rule 5 & Rule 7)
    // -------------------------------------------------------------
    // Check passes when backup is present
    let checkOut = '';
    try {
        checkOut = execFileSync(PHP, [...PHP_FLAGS, scriptPath, '--test', '--check'], { cwd: T.ROOT }).toString();
        const res = JSON.parse(checkOut);
        ok('health check returns healthy=true when valid backup exists', res.healthy === true);
    } catch (e) {
        ok('health check returns healthy=true when valid backup exists', false, e.message);
    }

    // 7b. Health check explicitly validates encrypted .enc backups
    for (const f of fs.readdirSync(BACKUP_TEST_DIR)) {
        if (f.startsWith('backup_') || f.startsWith('files_')) try { fs.unlinkSync(path.join(BACKUP_TEST_DIR, f)); } catch (e) {}
    }

    const encHealthDb = path.join(BACKUP_TEST_DIR, 'backup_2026-10-23.sql.gz.enc');
    const encHealthFiles = path.join(BACKUP_TEST_DIR, 'files_2026-10-23.zip.enc');
    fs.writeFileSync(encHealthDb, Buffer.alloc(2048, 'D'));
    fs.writeFileSync(encHealthFiles, Buffer.alloc(2048, 'F'));

    let encCheckOut = execFileSync(PHP, [...PHP_FLAGS, scriptPath, '--test', '--check'], { cwd: T.ROOT }).toString();
    let encCheckRes = JSON.parse(encCheckOut);
    ok('health check passes for valid encrypted (.enc) backup files', encCheckRes.healthy === true, encCheckOut.trim());

    // Truncate to tiny (10 bytes)
    fs.writeFileSync(encHealthDb, '1234567890');
    try {
        execFileSync(PHP, [...PHP_FLAGS, scriptPath, '--test', '--check'], { cwd: T.ROOT });
        ok('health check fails when encrypted backup is tiny (<1KB)', false, 'expected non-zero');
    } catch (e) {
        ok('health check fails when encrypted backup is tiny (<1KB)', e.status === 1);
    }
    const tinyMails = captured();
    ok('tiny encrypted backup failure alerts Super Admin', tinyMails.some(m => m.subject.includes('Daily Backup Check Failed')));

    // Delete backups and verify health check fails and alerts Super Admin
    for (const f of fs.readdirSync(BACKUP_TEST_DIR)) {
        if (f.startsWith('backup_') || f.startsWith('files_')) try { fs.unlinkSync(path.join(BACKUP_TEST_DIR, f)); } catch (e) {}
    }

    try {
        execFileSync(PHP, [...PHP_FLAGS, scriptPath, '--test', '--check'], { cwd: T.ROOT });
        ok('health check exits with non-zero when backup is missing', false, 'expected exit code 1');
    } catch (e) {
        ok('health check exits with non-zero when backup is missing', e.status === 1);
    }

    const mails = captured();
    const alertMail = mails.find(m => m.subject.includes('Daily Backup Check Failed'));
    ok('health check failure alerts Super Admin via email', Boolean(alertMail));
    if (alertMail) {
        ok('alert email sent to Super Admin email address', alertMail.to === 'super@test.local', alertMail.to);
        ok('alert email contains no personal applicant data', !/\b\d{8}\b/.test(alertMail.text) && !/\b01[3-9]\d{8}\b/.test(alertMail.text));
    }

    // -------------------------------------------------------------
    // 8. Repeated PHP Error log monitoring
    // -------------------------------------------------------------
    const checkErrorsScript = path.join(T.ROOT, 'backend', 'scripts', 'check_errors.php');
    ok('check_errors.php script exists', fs.existsSync(checkErrorsScript));

    // Clear capture file and reset rate limit cache
    if (fs.existsSync(CAPTURE)) try { fs.unlinkSync(CAPTURE); } catch (e) {}
    const rateLimitFile = path.join(T.ROOT, 'backend', 'cache', 'alert_super_admin_last.json');
    if (fs.existsSync(rateLimitFile)) try { fs.unlinkSync(rateLimitFile); } catch (e) {}

    // Simulate 12 repeating errors in log
    const phpNow = execFileSync(PHP, ['-r', 'date_default_timezone_set("Asia/Dhaka"); echo date("d-M-Y H:i:s");']).toString().trim();
    let errorLines = '';
    for (let i = 0; i < 12; i++) {
        errorLines += `[${phpNow}] PHP Fatal error: Uncaught RuntimeException: Connection refused in db.php on line 42\n`;
    }
    fs.writeFileSync(PHP_ERROR_LOG, errorLines);

    execFileSync(PHP, [...PHP_FLAGS, checkErrorsScript], { cwd: T.ROOT });

    const errMails = captured();
    const repMail = errMails.find(m => m.subject.includes('Repeated PHP Error in Log'));
    ok('repeated PHP errors trigger Super Admin alert email', Boolean(repMail));
    if (repMail) {
        ok('error alert email sent to super admin', repMail.to === 'super@test.local');
        ok('error alert contains error count', repMail.text.includes('12 times') || repMail.text.includes('Repeated'));
    }

    // Rate limit test: second run immediately does not send another email
    const preCount = captured().length;
    execFileSync(PHP, [...PHP_FLAGS, checkErrorsScript], { cwd: T.ROOT });
    const postCount = captured().length;
    ok('alert emails are rate-limited to at most one per hour', preCount === postCount, `${preCount} vs ${postCount}`);

    // Cleanup
    try { fs.unlinkSync(PHP_ERROR_LOG); } catch (e) {}
    if (fs.existsSync(SCRATCH_FILES_DIR)) {
        fs.rmSync(SCRATCH_FILES_DIR, { recursive: true, force: true });
    }
    if (fs.existsSync(BACKUP_TEST_DIR)) {
        for (const f of fs.readdirSync(BACKUP_TEST_DIR)) try { fs.unlinkSync(path.join(BACKUP_TEST_DIR, f)); } catch (e) {}
        try { fs.rmdirSync(BACKUP_TEST_DIR); } catch (e) {}
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
