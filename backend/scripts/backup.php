<?php
/**
 * Daily backup script for BUCuC website (database + uploads).
 *
 * Runs via CLI or Hostinger Cron:
 *   php /home/<user>/public_html/backend/scripts/backup.php
 *
 * Options:
 *   --test          Run in test mode (writes to tests/runtime/backup_test, skips offsite upload)
 *   --check         Run daily health check only (checks latest backup exists, not empty, <=26h old)
 *   --db-only       Dump database only
 *   --files-only    Archive uploads/media only
 *
 * Hostinger Shared Limits:
 *   - Memory limit set to 512M
 *   - Execution time set to 300s
 *   - Gzipped streaming export keeps memory < 15MB and runtime < 20s
 *   - Keeps 14 days of backups; older archives are pruned automatically
 *   - Access blocked by .htaccess if stored inside web root; recommended outside public_html
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../config/backup.php';

$isTest = in_array('--test', $argv ?? [], true);
$checkOnly = in_array('--check', $argv ?? [], true);
$dbOnly = in_array('--db-only', $argv ?? [], true);
$filesOnly = in_array('--files-only', $argv ?? [], true);

// Set backup directory
if ($isTest) {
    $backupDir = dirname(__DIR__, 2) . '/tests/runtime/backup_test';
} else {
    $backupDir = BACKUP_DIR;
}

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0750, true);
}

// Ensure .htaccess blocks web access if stored inside web root
$htaccess = $backupDir . '/.htaccess';
if (!file_exists($htaccess)) {
    @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
}

// -------------------------------------------------------------
// Execution flow
// -------------------------------------------------------------

$date = date('Y-m-d');
$dbDest = $backupDir . "/backup_{$date}.sql.gz";
$filesDest = $backupDir . "/files_{$date}.zip";

if ($checkOnly) {
    $res = backup_health_check($backupDir, $isTest);
    echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
    exit($res['healthy'] ? 0 : 1);
}

$report = ['date' => $date, 'backupDir' => $backupDir];

$pdo = db();
site_ensure_schema($pdo);

// Acquire independent backup advisory lock (non-blocking)
$lockSt = $pdo->prepare("SELECT GET_LOCK('bucuc_backup_lock', 0)");
$lockSt->execute();
if ((int) $lockSt->fetchColumn() !== 1) {
    if ($isTest) {
        echo json_encode(['status' => 'locked', 'message' => 'Another backup process is currently running']) . "\n";
    } else {
        fwrite(STDERR, "Another backup process is currently running; exiting.\n");
    }
    exit(0);
}

try {
    $isEncrypted = (defined('BACKUP_ENCRYPTION_METHOD') && BACKUP_ENCRYPTION_METHOD !== 'none') ||
                   (defined('BACKUP_ENCRYPTION_CERT') && BACKUP_ENCRYPTION_CERT !== '') ||
                   (defined('BACKUP_AGE_RECIPIENT') && BACKUP_AGE_RECIPIENT !== '') ||
                   (defined('BACKUP_GPG_RECIPIENT') && BACKUP_GPG_RECIPIENT !== '');

    // Security check: if running on live server and unencrypted, warn Super Admin
    if (!$isTest && !$isEncrypted) {
        site_alert_super_admin('BUCuC Backup Encryption Warning', "No encryption certificate or public key is configured on this server. Backups are currently unencrypted. Please configure BACKUP_ENCRYPTION_CERT in backend/config/backup.local.php.", false);
    }

    // Security check: if backup directory is inside web root on live server, alert Super Admin
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : null;
    $realBackup = realpath($backupDir) ?: $backupDir;
    if ($docRoot && strpos($realBackup, $docRoot) === 0 && !$isTest) {
        $secMsg = "BACKUP SECURITY WARNING: Backup directory ($realBackup) is located inside the public web root ($docRoot).\n\n"
            . "While protected by .htaccess, please move backups outside public_html on Hostinger (e.g. /home/<user>/backups) "
            . "by setting BACKUP_DIR in backend/config/backup.local.php.";
        site_alert_super_admin('Backup Security Alert', $secMsg, false);
    }

    $uploadDb = $dbDest;
    $uploadFiles = $filesDest;

    if (!$filesOnly) {
        echo "Dumping database to $dbDest...\n";
        $dbSize = backup_database($pdo, $dbDest);
        $report['db_backup'] = ['file' => basename($dbDest), 'size' => $dbSize];
        echo "Database dump complete (" . round($dbSize / 1024, 1) . " KB).\n";

        if ($isEncrypted) {
            $dbEnc = $dbDest . '.enc';
            echo "Encrypting database dump to $dbEnc...\n";
            try {
                $encOk = backup_encrypt_file($dbDest, $dbEnc);
                if (!$encOk || !file_exists($dbEnc) || filesize($dbEnc) === 0) {
                    throw new RuntimeException("Encryption returned false or produced an empty file");
                }
            } catch (\Throwable $e) {
                // Fail closed: delete partial encrypted file and unencrypted copy, send alert, never keep/upload
                @unlink($dbEnc);
                @unlink($dbDest);
                site_alert_super_admin('BUCuC Backup Encryption Failure', "Database encryption failed: " . $e->getMessage() . ". Unencrypted dump and partial files were removed to protect data.", true);
                throw $e;
            }

            // Successfully encrypted: remove unencrypted copy so plain data is not left on server
            if (!defined('BACKUP_KEEP_UNENCRYPTED_LOCAL') || !BACKUP_KEEP_UNENCRYPTED_LOCAL) {
                @unlink($dbDest);
            }

            $uploadDb = $dbEnc;
            $report['db_backup']['encrypted_file'] = basename($dbEnc);
            $report['db_backup']['encrypted_size'] = filesize($dbEnc);
        }

        if (!$isTest && BACKUP_OFFSITE_METHOD !== 'none') {
            echo "Uploading DB backup offsite...\n";
            $ok = backup_upload_offsite($uploadDb);
            $report['db_offsite'] = $ok;
        }
    }

    if (!$dbOnly) {
        echo "Archiving uploads to $filesDest...\n";
        $filesSize = backup_files($filesDest);
        $report['files_backup'] = ['file' => basename($filesDest), 'size' => $filesSize];
        echo "Files archive complete (" . round($filesSize / (1024 * 1024), 2) . " MB).\n";

        if ($isEncrypted) {
            $filesEnc = $filesDest . '.enc';
            echo "Encrypting files archive to $filesEnc...\n";
            try {
                $encOk = backup_encrypt_file($filesDest, $filesEnc);
                if (!$encOk || !file_exists($filesEnc) || filesize($filesEnc) === 0) {
                    throw new RuntimeException("Encryption returned false or produced an empty file");
                }
            } catch (\Throwable $e) {
                // Fail closed: delete partial encrypted file and unencrypted copy, send alert, never keep/upload
                @unlink($filesEnc);
                @unlink($filesDest);
                site_alert_super_admin('BUCuC Backup Encryption Failure', "Files archive encryption failed: " . $e->getMessage() . ". Unencrypted archive and partial files were removed to protect data.", true);
                throw $e;
            }

            // Successfully encrypted: remove unencrypted copy so plain data is not left on server
            if (!defined('BACKUP_KEEP_UNENCRYPTED_LOCAL') || !BACKUP_KEEP_UNENCRYPTED_LOCAL) {
                @unlink($filesDest);
            }

            $uploadFiles = $filesEnc;
            $report['files_backup']['encrypted_file'] = basename($filesEnc);
            $report['files_backup']['encrypted_size'] = filesize($filesEnc);
        }

        if (!$isTest && BACKUP_OFFSITE_METHOD !== 'none') {
            echo "Uploading files backup offsite...\n";
            $ok = backup_upload_offsite($uploadFiles);
            $report['files_offsite'] = $ok;
        }
    }

    // Prune backups older than retention period
    $pruned = backup_prune($backupDir, BACKUP_RETENTION_DAYS);
    $report['pruned'] = $pruned;
    if (!empty($pruned)) {
        echo "Pruned " . count($pruned) . " old backup file(s): " . implode(', ', $pruned) . "\n";
    }

    // Run health check
    $health = backup_health_check($backupDir, $isTest);
    $report['health_check'] = $health;

    // Scan for repeating PHP errors (e.g. 10x per hr)
    $repeatedErrors = site_check_php_error_log(10, 3600);
    $report['repeated_errors_detected'] = count($repeatedErrors);

    // Optional heartbeat ping on success (healthchecks.io style)
    if (defined('BACKUP_HEARTBEAT_URL') && BACKUP_HEARTBEAT_URL) {
        backup_ping_heartbeat(BACKUP_HEARTBEAT_URL, true);
    }

    echo "Backup procedure completed successfully.\n";
    if ($isTest) {
        echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
    }
    $exitCode = 0;
} catch (\Throwable $e) {
    $err = "Backup failed with exception: " . $e->getMessage();
    error_log($err);
    fwrite(STDERR, "$err\n");
    if (defined('BACKUP_HEARTBEAT_URL') && BACKUP_HEARTBEAT_URL) {
        backup_ping_heartbeat(BACKUP_HEARTBEAT_URL, false);
    }
    site_alert_super_admin('BUCuC Backup Failure', $err, true);
    $exitCode = 1;
} finally {
    try {
        $pdo->prepare("SELECT RELEASE_LOCK('bucuc_backup_lock')")->execute();
    } catch (\Throwable $e) {}
}
exit($exitCode);
