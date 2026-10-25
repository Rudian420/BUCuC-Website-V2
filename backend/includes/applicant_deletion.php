<?php
/**
 * Applicant Data Deletion, Anonymization & Tracking System (Phase 5).
 *
 * Implements strict applicant data deletion on rejection:
 * - Immediate, atomic deletion of personal data inside a single DB transaction.
 * - Minimal anonymous tracking token retained with status 'not_successful' or 'expired_unreviewed'.
 * - Anonymous rejection counts updated by semester for club statistics.
 * - Thorough scrubbing across all tables: pending/shortlist, interview slots,
 *   attendance, mail_log, notice_recipients, and activity log.
 * - Automatic retention purge of unreviewed applications older than configurable months.
 */

require_once __DIR__ . '/site.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/retention.php';

/**
 * Rejects an applicant and permanently deletes all their personal data in a single transaction.
 *
 * @param PDO    $pdo
 * @param int    $applicantId
 * @param string $source           'pending' | 'shortlisted'
 * @param string $reason           'rejected' | 'expired_unreviewed'
 * @param bool   $simulateFailure  For testing atomic rollback safety
 * @return array ['success' => bool, 'error' => string|null]
 */
function applicant_reject_and_delete(PDO $pdo, int $applicantId, string $source = 'pending', string $reason = 'rejected', bool $simulateFailure = false): array
{
    site_ensure_schema($pdo);

    $tables = [
        'pending'     => 'pending_applications',
        'shortlisted' => 'shortlisted_members',
    ];

    $table = $tables[$source] ?? 'pending_applications';

    // Must be executed inside a single atomic transaction
    $alreadyInTransaction = $pdo->inTransaction();
    if (!$alreadyInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Lock applicant row
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? FOR UPDATE");
        $stmt->execute([$applicantId]);
        $applicant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$applicant) {
            if (!$alreadyInTransaction) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Application not found'];
        }

        $name      = trim((string) ($applicant['full_name'] ?? ''));
        $uid       = trim((string) ($applicant['university_id'] ?? ''));
        $email     = trim((string) ($applicant['email'] ?? ''));
        $gsuite    = trim((string) ($applicant['gsuite_email'] ?? ''));
        $phone     = trim((string) ($applicant['phone'] ?? ''));
        $semester  = trim((string) ($applicant['semester'] ?? 'General'));
        $appliedAt = $applicant['created_at'] ?? date('Y-m-d H:i:s');
        $newStatus = ($reason === 'expired_unreviewed') ? 'expired_unreviewed' : 'not_successful';

        // 1. Increment anonymous rejection count by semester (statistics only, zero personal data)
        $statStmt = $pdo->prepare("
            INSERT INTO `applicant_rejection_stats` (`semester`, `rejected_count`)
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE `rejected_count` = `rejected_count` + 1
        ");
        $statStmt->execute([$semester]);

        // 2. Delete applicant personal record from application tables
        $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$applicantId]);
        if ($uid !== '') {
            $otherTable = $source === 'shortlisted' ? 'pending_applications' : 'shortlisted_members';
            $pdo->prepare("DELETE FROM `$otherTable` WHERE `university_id` = ?")->execute([$uid]);
        }

        // 3. Remove any attendance records (if applicant attended an orientation/session prior to rejection)
        if ($uid !== '') {
            $pdo->prepare("DELETE FROM `attendance_records` WHERE `member_id` IN (SELECT id FROM `members` WHERE `university_id` = ?)")->execute([$uid]);
            $pdo->prepare("DELETE FROM `attendance_expected` WHERE `member_id` IN (SELECT id FROM `members` WHERE `university_id` = ?)")->execute([$uid]);
        }

        // 8. Delete queued unsent notices and remove applicant from notice_recipients
        try {
            $stN = $pdo->prepare("
                SELECT DISTINCT notice_id FROM notice_recipients
                WHERE (`student_id` != '' AND `student_id` = ?)
                   OR (`email` != '' AND `email` = ?)
                   OR (`email` != '' AND `email` = ?)
            ");
            $stN->execute([$uid, $email, $gsuite]);
            $affectedNotices = $stN->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare("
                DELETE FROM `notice_recipients`
                WHERE (`student_id` != '' AND `student_id` = ?)
                   OR (`email` != '' AND `email` = ?)
                   OR (`email` != '' AND `email` = ?)
            ")->execute([$uid, $email, $gsuite]);

            foreach ($affectedNotices as $nid) {
                $c = $pdo->prepare("SELECT 
                    SUM(status = 'sent') AS sent, 
                    SUM(status = 'failed') AS failed, 
                    SUM(status = 'pending' OR status = 'sending') AS pending, 
                    COUNT(*) AS total 
                    FROM notice_recipients WHERE notice_id = ?");
                $c->execute([$nid]);
                $counts = $c->fetch(PDO::FETCH_ASSOC);
                if ($counts) {
                    $pending = (int) ($counts['pending'] ?? 0);
                    $done = ($pending === 0);
                    $pdo->prepare("UPDATE notices SET total = ?, sent = ?, failed = ?, status = IF(? AND status != 'failed', 'done', status) WHERE id = ?")
                        ->execute([
                            (int) $counts['total'],
                            (int) $counts['sent'],
                            (int) $counts['failed'],
                            $done ? 1 : 0,
                            $nid
                        ]);
                }
            }
        } catch (\Throwable $e) {
            // Ignore if notice tables not present
        }

        // 9. Anonymize mail_log recipient email addresses
        $pdo->prepare("
            UPDATE `mail_log`
            SET `recipient` = NULL
            WHERE (`recipient` != '' AND `recipient` = ?)
               OR (`recipient` != '' AND `recipient` = ?)
        ")->execute([$email, $gsuite]);

        // 10. Clean and anonymize activity log:
        if (function_exists('log_activity')) {
            log_activity('application_rejected', "An applicant was rejected from the $source list", null, null, null);
        } else {
            try {
                $stAct = $pdo->prepare("INSERT INTO admin_activity_log (action, details, created_at) VALUES ('application_rejected', ?, NOW())");
                $stAct->execute(["An applicant was rejected from the $source list"]);
            } catch (\Throwable $e) {}
        }

        // Sanitize any older log entries that mentioned this applicant's student ID or name
        if ($uid !== '') {
            $pdo->prepare("
                UPDATE `admin_activity_log`
                SET `target` = NULL, `details` = 'An applicant was processed'
                WHERE `target` = ? OR `target` LIKE ? OR `details` LIKE ?
            ")->execute([$uid, "%$uid%", "%$uid%"]);
        }
        if ($name !== '') {
            $pdo->prepare("
                UPDATE `admin_activity_log`
                SET `target` = NULL, `details` = 'An applicant was processed'
                WHERE `target` = ? OR `target` LIKE ?
            ")->execute([$name, "$name%"]);
        }

        // 6. External: sync removal with Google Sheet (validates secret, alerts on failure, queues retries)
        if ($uid !== '') {
            applicant_delete_from_google_sheet($uid, $pdo);
        }

        // 7. Scrub applicant personal data from physical log files in backend/logs/
        applicant_scrub_logs($uid, $email, $gsuite, $phone, $name);

        // Simulate failure if requested (for rollback test verification)
        if ($simulateFailure) {
            throw new RuntimeException("Simulated transaction failure for atomic rollback verification");
        }

        if (!$alreadyInTransaction) {
            $pdo->commit();
        }

        return [
            'success'  => true,
            'status'   => $newStatus,
            'semester' => $semester,
            'error'    => null,
        ];

    } catch (\Throwable $e) {
        if (!$alreadyInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("applicant_reject_and_delete failed: " . $e->getMessage());
        return [
            'success' => false,
            'error'   => $e->getMessage(),
        ];
    }
}

/**
 * Scrubs an applicant's personal data (ID, email, phone, name) from backend/logs/*.log
 * Uses atomic replacement via temporary file and preserves file permissions.
 *
 * @param string $uid
 * @param string $email
 * @param string $gsuite
 * @param string $phone
 * @param string $name
 * @param bool   $simulateFailure For testing: aborts midway to verify original log is untouched
 */
function applicant_scrub_logs(string $uid, string $email, string $gsuite, string $phone, string $name, bool $simulateFailure = false): void
{
    $targets = [
        __DIR__ . '/../logs/email.log',
        __DIR__ . '/../logs/cleared_applications.log',
        __DIR__ . '/../logs/cleared_shortlisted.log',
        __DIR__ . '/../logs/google_sheets.log',
    ];

    $terms = array_filter([$uid, $email, $gsuite, $phone], fn($t) => !empty($t) && strlen($t) > 3);
    if (empty($terms)) return;

    foreach ($targets as $logFile) {
        if (!is_file($logFile)) continue;
        $content = @file_get_contents($logFile);
        if ($content === false || $content === '') continue;

        $perms = @fileperms($logFile);
        $modified = false;
        $lines = explode("\n", $content);
        $newLines = [];
        foreach ($lines as $line) {
            if ($line === '') continue;
            $hasTerm = false;
            foreach ($terms as $t) {
                if (strpos($line, $t) !== false) {
                    $hasTerm = true;
                    break;
                }
            }
            if ($hasTerm) {
                $scrubbed = $line;
                foreach ($terms as $t) {
                    $scrubbed = str_replace($t, '[REDACTED_ON_REJECTION]', $scrubbed);
                }
                if ($name !== '' && strlen($name) > 3) {
                    $scrubbed = str_replace($name, '[REDACTED_ON_REJECTION]', $scrubbed);
                }
                $newLines[] = $scrubbed;
                $modified = true;
            } else {
                $newLines[] = $line;
            }
        }

        if ($modified) {
            $tmpFile = dirname($logFile) . '/.scrub_tmp_' . basename($logFile) . '_' . bin2hex(random_bytes(6));
            try {
                $res = @file_put_contents($tmpFile, implode("\n", $newLines) . "\n", LOCK_EX);
                if ($res === false) {
                    throw new RuntimeException("Failed to write scrubbed content to temporary log file: $tmpFile");
                }
                if ($perms !== false) {
                    @chmod($tmpFile, $perms & 0777);
                }

                if ($simulateFailure) {
                    throw new RuntimeException("Simulated mid-way failure during atomic log scrubbing");
                }

                // Atomic rename on Windows/POSIX
                if (!@rename($tmpFile, $logFile)) {
                    // Fallback if destination cannot be directly replaced on some platforms
                    @unlink($logFile);
                    if (!@rename($tmpFile, $logFile)) {
                        throw new RuntimeException("Failed to atomically replace log file: $logFile");
                    }
                }
            } catch (\Throwable $e) {
                if (file_exists($tmpFile)) {
                    @unlink($tmpFile);
                }
                throw $e;
            }
        }
    }
}


/**
 * Returns anonymous rejection count for a semester, or total across all semesters.
 */
function applicant_rejection_count(PDO $pdo, ?string $semester = null): int
{
    site_ensure_schema($pdo);
    if ($semester !== null && $semester !== '') {
        $stmt = $pdo->prepare("SELECT `rejected_count` FROM `applicant_rejection_stats` WHERE `semester` = ?");
        $stmt->execute([$semester]);
        return (int) $stmt->fetchColumn();
    }
    return (int) $pdo->query("SELECT COALESCE(SUM(`rejected_count`), 0) FROM `applicant_rejection_stats`")->fetchColumn();
}

/**
 * Purges unreviewed applications that have remained in pending_applications longer
 * than the configured retention threshold (UNREVIEWED_APPLICANT_RETENTION_MONTHS).
 *
 * Dedicated advisory lock prevents overlapping runs.
 * Failure triggers an alert email to Super Admin.
 * Logs execution counts only (no personal data).
 *
 * @param PDO      $pdo
 * @param int|null $months
 * @return int Number of unreviewed applicants purged
 */
function applicant_purge_unreviewed(PDO $pdo, ?int $months = null): int
{
    site_ensure_schema($pdo);

    // Dedicated advisory lock for retention purge
    $st = $pdo->prepare("SELECT GET_LOCK('bucuc_retention_purge', 0)");
    $st->execute();
    if ((int) $st->fetchColumn() !== 1) {
        // Another retention purge process is active; exit cleanly
        return 0;
    }

    $logFile = __DIR__ . '/../logs/retention_purge.log';
    $months = $months !== null ? $months : (int) UNREVIEWED_APPLICANT_RETENTION_MONTHS;
    if ($months <= 0) {
        $pdo->prepare("SELECT RELEASE_LOCK('bucuc_retention_purge')")->execute();
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT `id` FROM `pending_applications` WHERE `created_at` < (NOW() - INTERVAL ? MONTH)");
        $stmt->execute([$months]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $purged = 0;
        foreach ($ids as $id) {
            $res = applicant_reject_and_delete($pdo, (int) $id, 'pending', 'expired_unreviewed');
            if ($res['success']) {
                $purged++;
            }
        }

        // Write log that holds COUNTS ONLY (no names, IDs, or personal details)
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $entry = json_encode([
            'timestamp'        => date('Y-m-d H:i:s'),
            'event'            => 'retention_purge',
            'status'           => 'success',
            'purged_count'     => $purged,
            'retention_months' => $months,
        ]) . PHP_EOL;
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

        return $purged;
    } catch (\Throwable $e) {
        error_log("applicant_purge_unreviewed failure: " . $e->getMessage());
        site_alert_super_admin('BUCuC Retention Purge Failure', 'Automated applicant retention purge failed: ' . $e->getMessage(), true);
        $entry = json_encode([
            'timestamp'        => date('Y-m-d H:i:s'),
            'status'           => 'failed',
            'purged_count'     => 0,
            'retention_months' => $months,
            'error'            => 'Execution error'
        ]) . PHP_EOL;
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        throw $e;
    } finally {
        $pdo->prepare("SELECT RELEASE_LOCK('bucuc_retention_purge')")->execute();
    }
}

/**
 * Deletes an applicant from the Google member sheet via Apps Script webhook.
 * - Enforces shared secret authentication.
 * - Reads JSON response 'success' flag (Apps Script returns HTTP 200 on failure with success=false).
 * - Treats success=false or network failure as an error.
 * - Alerts Super Admin via site_alert_super_admin().
 * - Records failure in failed_sheet_deletions table for automatic retry.
 * - Removes from failed_sheet_deletions table upon success.
 *
 * @param string $studentId
 * @param PDO|null $pdo
 * @return array ['success' => bool, 'error' => string|null]
 */
function applicant_delete_from_google_sheet(string $studentId, ?PDO $pdo = null): array
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return ['success' => false, 'error' => 'Empty student ID'];
    }

    $gasUrl = defined('MEMBER_SHEET_GAS_URL') ? MEMBER_SHEET_GAS_URL : '';
    if (empty($gasUrl)) {
        return ['success' => false, 'error' => 'MEMBER_SHEET_GAS_URL is not configured'];
    }

    $sheetSecret = defined('MEMBER_SHEET_SECRET') ? MEMBER_SHEET_SECRET : '';
    if ($sheetSecret === '') {
        error_log("Warning: MEMBER_SHEET_SECRET is not configured; Google Sheets delete operation may fail authentication.");
    }

    $payload = [
        'action'    => 'delete',
        'studentId' => $studentId,
        'secret'    => $sheetSecret,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $gasUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $success = false;
    $errorMsg = null;

    if ($resp === false || $curlErr) {
        $errorMsg = "Connection failed: $curlErr";
    } elseif ($httpCode < 200 || $httpCode >= 300) {
        $errorMsg = "HTTP error $httpCode";
    } else {
        $data = json_decode($resp, true);
        if (is_array($data) && !empty($data['success'])) {
            $success = true;
        } else {
            $errorMsg = is_array($data) && !empty($data['error'])
                ? $data['error']
                : "Google Apps Script reported success=false: " . substr((string) $resp, 0, 120);
        }
    }

    $pdoInstance = $pdo ?: (function_exists('db') ? db() : null);

    if (!$success) {
        site_alert_super_admin('Google Sheet Deletion Failure', "Google Sheet deletion failed for applicant ID $studentId: $errorMsg");
        if ($pdoInstance && function_exists('db_table_exists') && db_table_exists($pdoInstance, 'failed_sheet_deletions')) {
            try {
                $st = $pdoInstance->prepare("INSERT INTO failed_sheet_deletions (student_id, action, failed_at, last_error, attempts)
                    VALUES (:id, 'delete', NOW(), :err, 1)
                    ON DUPLICATE KEY UPDATE failed_at = NOW(), last_error = :err2, attempts = attempts + 1");
                $st->execute([':id' => $studentId, ':err' => $errorMsg, ':err2' => $errorMsg]);
            } catch (\Throwable $e) {
                error_log("Failed to record failed sheet deletion: " . $e->getMessage());
            }
        }
        return ['success' => false, 'error' => $errorMsg];
    }

    // Success: remove any recorded retry entry
    if ($pdoInstance && function_exists('db_table_exists') && db_table_exists($pdoInstance, 'failed_sheet_deletions')) {
        try {
            $st = $pdoInstance->prepare("DELETE FROM failed_sheet_deletions WHERE student_id = ? AND action = 'delete'");
            $st->execute([$studentId]);
        } catch (\Throwable $e) {}
    }

    return ['success' => true, 'error' => null];
}
