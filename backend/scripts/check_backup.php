<?php
/**
 * Daily backup health check script.
 * Verifies that the latest backup exists, is <= 26 hours old, and >= 1 KB.
 * Alerts the Super Admin via email if missing or too small.
 *
 * Runs via CLI or Hostinger Cron:
 *   0 6 * * * php /home/<user>/public_html/backend/scripts/check_backup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../config/backup.php';

$isTest = in_array('--test', $argv ?? [], true);
$dir = $isTest ? (dirname(__DIR__, 2) . '/tests/runtime/backup_test') : BACKUP_DIR;

$res = backup_health_check($dir, $isTest);
echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
exit($res['healthy'] ? 0 : 1);
