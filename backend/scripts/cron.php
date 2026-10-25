<?php
/**
 * Master Cron Dispatcher for BUCuC website.
 *
 * Runs via CLI or Hostinger Cron (every 1 to 5 minutes):
 *   * * * * * php /home/<user>/public_html/backend/scripts/cron.php
 *
 * Dispatches due background tasks:
 *   1. Notices: runs every minute to process active notice batches.
 *   2. Backup: runs daily (default 03:00) with encryption and off-site transfer.
 *   3. Backup Health Check: runs daily (default 06:00) to verify backup freshness and size.
 *   4. Error Scan: runs hourly to scan php_errors.log for repeated failures.
 *   5. Handover Reminders: runs daily (default 04:00) to notify upcoming/outgoing executives.
 *
 * Advisory locks prevent overlapping runs; per-job heartbeats report status to uptime monitors.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/notices.php';
require_once __DIR__ . '/../includes/handover.php';
require_once __DIR__ . '/../config/backup.php';

$isTest = in_array('--test', $argv ?? [], true);
$forceJob = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--force=') === 0) {
        $forceJob = substr($arg, 8);
    }
}

$pdo = db();
site_ensure_schema($pdo);

// Acquire central dispatcher advisory lock (non-blocking)
$st = $pdo->prepare("SELECT GET_LOCK('bucuc_cron_dispatcher', 0)");
$st->execute();
if ((int) $st->fetchColumn() !== 1) {
    // Another dispatcher process is currently active; exit cleanly
    if ($isTest) {
        echo json_encode(['status' => 'locked', 'message' => 'Another cron dispatcher is running']) . "\n";
    }
    exit(0);
}

// Helper function to record job status in database table cron_schedule
// Strictly redacts any personal data, email addresses, student IDs, or credentials before writing
function cron_record_job(PDO $pdo, string $jobKey, string $status, ?string $output = null): void
{
    if ($output !== null) {
        // Redact potential emails
        $output = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $output);
        // Redact potential 8-digit student IDs
        $output = preg_replace('/\b\d{8}\b/', '[REDACTED_ID]', $output);
        // Redact potential phone numbers
        $output = preg_replace('/(\+?88)?01[3-9]\d{8}/', '[REDACTED_PHONE]', $output);
        // Redact potential passwords, tokens, or credentials
        $output = preg_replace('/(pass(word|phrase)?|secret|token|key|cred(ential)?)=([^\s&]+)/i', '$1=[REDACTED]', $output);
    }
    try {
        $st = $pdo->prepare("INSERT INTO cron_schedule (job_key, last_run_at, last_status, last_output)
            VALUES (:k, NOW(), :s, :o)
            ON DUPLICATE KEY UPDATE last_run_at = NOW(), last_status = :s2, last_output = :o2");
        $st->execute([
            ':k' => $jobKey,
            ':s' => $status,
            ':o' => $output,
            ':s2' => $status,
            ':o2' => $output,
        ]);
    } catch (\Throwable $e) {
        error_log("cron_record_job failure for $jobKey: " . $e->getMessage());
    }
}

/**
 * Spawns a background CLI process using proc_open with popen and exec fallbacks.
 * Returns true if the background process was launched, or false if process execution is disabled.
 */
function cron_spawn_background_process(string $cmd): bool
{
    $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

    // 1. Try proc_open
    if (function_exists('proc_open')) {
        $nullDevice = $isWin ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $nullDevice, 'w'],
            2 => ['file', $nullDevice, 'w'],
        ];
        $formattedCmd = $isWin ? "cmd /c start /B " . $cmd : $cmd . " > /dev/null 2>&1 &";
        $proc = @proc_open($formattedCmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            if (isset($pipes[0])) fclose($pipes[0]);
            @proc_close($proc);
            return true;
        }
    }

    // 2. Try popen fallback
    if (function_exists('popen')) {
        $pCmd = $isWin ? "start /B " . $cmd : $cmd . " > /dev/null 2>&1 &";
        $handle = @popen($pCmd, "r");
        if ($handle) {
            @pclose($handle);
            return true;
        }
    }

    // 3. Try exec fallback
    if (function_exists('exec')) {
        if ($isWin) {
            @exec("start /B " . $cmd);
        } else {
            @exec($cmd . " > /dev/null 2>&1 &");
        }
        return true;
    }

    return false;
}

// State tracking from database table cron_schedule
$state = [];
try {
    $st = $pdo->query("SELECT job_key, UNIX_TIMESTAMP(last_run_at) AS last_ts, last_status, last_output FROM cron_schedule");
    if ($st) {
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state[$row['job_key'] . '_last_run'] = (int) ($row['last_ts'] ?? 0);
            $state[$row['job_key'] . '_status'] = $row['last_status'];
            $state[$row['job_key'] . '_output'] = $row['last_output'];
        }
    }
} catch (\Throwable $e) {
    error_log("Failed to load cron_schedule table: " . $e->getMessage());
}

$now = time();
$currentHour = (int) date('G', $now);
$report = [
    'time' => date('Y-m-d H:i:s', $now),
    'jobs_run' => [],
];

try {
    // -------------------------------------------------------------
    // Job 1: Notices (runs whenever active notices are queued)
    // -------------------------------------------------------------
    $dueNotices = ($forceJob === 'notices') || true;
    if ($dueNotices) {
        $st = $pdo->query("SELECT id FROM notices WHERE status = 'sending' ORDER BY id ASC");
        $sending = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];

        if (!empty($sending)) {
            $lockSt = $pdo->prepare("SELECT GET_LOCK('bucuc_send_notices', 0)");
            $lockSt->execute();
            if ((int) $lockSt->fetchColumn() === 1) {
                try {
                    $noticeResults = [];
                    foreach ($sending as $nid) {
                        $noticeResults[$nid] = notice_send_batch($pdo, (int) $nid);
                    }
                    $report['jobs_run']['notices'] = [
                        'status' => 'success',
                        'processed' => count($sending),
                        'results' => $noticeResults,
                    ];
                    $state['notices_last_run'] = $now;
                    cron_record_job($pdo, 'notices', 'success', json_encode($noticeResults));

                    if (defined('NOTICE_CRON_HEARTBEAT_URL') && NOTICE_CRON_HEARTBEAT_URL) {
                        backup_ping_heartbeat(NOTICE_CRON_HEARTBEAT_URL, true);
                    }
                } finally {
                    $pdo->prepare("SELECT RELEASE_LOCK('bucuc_send_notices')")->execute();
                }
            }
        }
    }

    // -------------------------------------------------------------
    // Job 2: Backup (daily at 03:00 or >24h since last run)
    // Runs as its own process with its own lock so notices cron is never blocked.
    // -------------------------------------------------------------
    $lastBackup = $state['backup_last_run'] ?? 0;
    $dueBackup = ($forceJob === 'backup') ||
                 ($currentHour === 3 && ($now - $lastBackup) > 7200) ||
                 (($now - $lastBackup) > 86400 && $lastBackup > 0);

    if ($dueBackup) {
        $phpBin = PHP_BINARY ?: 'php';
        $scriptPath = __DIR__ . '/backup.php';
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ($isTest ? ' --test' : '');
        $syncMode = in_array('--sync', $argv ?? [], true);

        if (!$syncMode && !$isTest) {
            // Asynchronous spawn via proc_open / popen / exec: lets dispatcher return immediately so notices keep sending
            $spawned = cron_spawn_background_process($cmd);
            if ($spawned) {
                cron_record_job($pdo, 'backup', 'spawned', 'Spawned background backup process');
                $report['jobs_run']['backup'] = [
                    'status' => 'spawned',
                    'mode' => 'async_process',
                ];
                $state['backup_last_run'] = $now;
            } else {
                // If proc_open/popen/exec are all disabled on host, fall back to synchronous execution
                $bOut = [];
                $bRet = 0;
                if (function_exists('exec')) {
                    exec($cmd, $bOut, $bRet);
                } else {
                    $bRet = backup_run($pdo) ? 0 : 1;
                }
                $report['jobs_run']['backup'] = [
                    'status' => $bRet === 0 ? 'success' : 'failed',
                    'code' => $bRet,
                    'mode' => 'synchronous_fallback',
                ];
                cron_record_job($pdo, 'backup', $bRet === 0 ? 'success' : 'failed', 'Synchronous fallback: ' . ($bRet === 0 ? 'success' : 'failed'));
                if ($bRet === 0) {
                    $state['backup_last_run'] = $now;
                }
            }
        } else {
            // Synchronous execution for test mode or explicit --sync
            exec($cmd, $bOut, $bRet);

            $report['jobs_run']['backup'] = [
                'status' => $bRet === 0 ? 'success' : 'failed',
                'code' => $bRet,
                'output' => array_slice($bOut, -5),
            ];

            cron_record_job($pdo, 'backup', $bRet === 0 ? 'success' : 'failed', implode("\n", array_slice($bOut, -5)));
            if ($bRet === 0) {
                $state['backup_last_run'] = $now;
            }
        }
    }

    // -------------------------------------------------------------
    // Job 3: Backup Health Check (daily at 06:00 or >24h since last check)
    // -------------------------------------------------------------
    $lastCheck = $state['check_backup_last_run'] ?? 0;
    $dueCheck = ($forceJob === 'check_backup') ||
                ($currentHour === 6 && ($now - $lastCheck) > 7200) ||
                (($now - $lastCheck) > 86400 && $lastCheck > 0);

    if ($dueCheck) {
        $backupDir = $isTest ? (dirname(__DIR__, 2) . '/tests/runtime/backup_test') : BACKUP_DIR;
        $health = backup_health_check($backupDir, $isTest);
        $report['jobs_run']['check_backup'] = [
            'status' => $health['healthy'] ? 'success' : 'alert',
            'health' => $health,
        ];
        $state['check_backup_last_run'] = $now;
        cron_record_job($pdo, 'check_backup', $health['healthy'] ? 'success' : 'alert', json_encode($health));

        if (defined('CHECK_BACKUP_HEARTBEAT_URL') && CHECK_BACKUP_HEARTBEAT_URL) {
            backup_ping_heartbeat(CHECK_BACKUP_HEARTBEAT_URL, $health['healthy']);
        }
    }

    // -------------------------------------------------------------
    // Job 4: Error Scan (hourly or >3600s since last run)
    // -------------------------------------------------------------
    $lastErr = $state['check_errors_last_run'] ?? 0;
    $dueErr = ($forceJob === 'check_errors') || (($now - $lastErr) >= 3600);

    if ($dueErr) {
        $phpErrors = site_check_php_error_log(10, 3600);
        $report['jobs_run']['check_errors'] = [
            'status' => 'success',
            'repeated_errors' => count($phpErrors),
        ];
        $state['check_errors_last_run'] = $now;
        cron_record_job($pdo, 'check_errors', 'success', count($phpErrors) . ' repeated errors');

        if (defined('CHECK_ERRORS_HEARTBEAT_URL') && CHECK_ERRORS_HEARTBEAT_URL) {
            backup_ping_heartbeat(CHECK_ERRORS_HEARTBEAT_URL, true);
        }
    }

    // -------------------------------------------------------------
    // Job 5: Handover Reminders (daily at 04:00 or >24h since last run)
    // -------------------------------------------------------------
    $lastHandover = $state['handover_last_run'] ?? 0;
    $dueHandover = ($forceJob === 'handover_reminders') ||
                   ($currentHour === 4 && ($now - $lastHandover) > 7200) ||
                   (($now - $lastHandover) > 86400 && $lastHandover > 0);

    if ($dueHandover) {
        $sentReminders = handover_send_due_reminders($pdo);
        $report['jobs_run']['handover_reminders'] = [
            'status' => 'success',
            'reminders_sent' => $sentReminders,
        ];
        $state['handover_last_run'] = $now;
        cron_record_job($pdo, 'handover_reminders', 'success', $sentReminders . ' reminders sent');

        if (defined('HANDOVER_HEARTBEAT_URL') && HANDOVER_HEARTBEAT_URL) {
            backup_ping_heartbeat(HANDOVER_HEARTBEAT_URL, true);
        }
    }

    // -------------------------------------------------------------
    // Job 6: Retention Purge (daily at 05:00 or >24h since last run)
    // Deletes unreviewed applications older than UNREVIEWED_APPLICANT_RETENTION_MONTHS
    // -------------------------------------------------------------
    require_once __DIR__ . '/../includes/applicant_deletion.php';
    $lastPurge = $state['retention_purge_last_run'] ?? 0;
    $duePurge = ($forceJob === 'retention_purge') ||
                ($currentHour === 5 && ($now - $lastPurge) > 7200) ||
                (($now - $lastPurge) > 86400 && $lastPurge > 0);

    if ($duePurge) {
        $purgedCount = applicant_purge_unreviewed($pdo);
        $report['jobs_run']['retention_purge'] = [
            'status' => 'success',
            'purged' => $purgedCount,
            'retention_months' => UNREVIEWED_APPLICANT_RETENTION_MONTHS,
        ];
        $state['retention_purge_last_run'] = $now;
        cron_record_job($pdo, 'retention_purge', 'success', "Purged $purgedCount unreviewed applicant(s) older than " . UNREVIEWED_APPLICANT_RETENTION_MONTHS . " months");
    }

    // -------------------------------------------------------------
    // Job 7: Retry Failed Google Sheet Deletions (processes retry queue)
    // -------------------------------------------------------------
    if (function_exists('db_table_exists') && db_table_exists($pdo, 'failed_sheet_deletions')) {
        try {
            $failedRows = $pdo->query("SELECT student_id FROM failed_sheet_deletions WHERE attempts < 10 ORDER BY failed_at ASC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($failedRows)) {
                $retriedCount = 0;
                foreach ($failedRows as $sid) {
                    $res = applicant_delete_from_google_sheet($sid, $pdo);
                    if (!empty($res['success'])) {
                        $retriedCount++;
                    }
                }
                if ($retriedCount > 0) {
                    $report['jobs_run']['sheet_deletions_retry'] = [
                        'status' => 'success',
                        'retried_and_resolved' => $retriedCount,
                    ];
                    cron_record_job($pdo, 'sheet_deletions_retry', 'success', "Retried and resolved $retriedCount sheet deletion(s)");
                }
            }
        } catch (\Throwable $e) {
            error_log("Failed sheet deletions retry error: " . $e->getMessage());
        }
    }

    // Dispatcher master heartbeat
    if (defined('CRON_DISPATCHER_HEARTBEAT_URL') && CRON_DISPATCHER_HEARTBEAT_URL) {
        backup_ping_heartbeat(CRON_DISPATCHER_HEARTBEAT_URL, true);
    }

    if ($isTest) {
        echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
    }
} finally {
    // Release dispatcher advisory lock
    $pdo->prepare("SELECT RELEASE_LOCK('bucuc_cron_dispatcher')")->execute();
}

exit(0);
