<?php
/**
 * Hourly error log monitor script.
 * Scans php_errors.log for repeated errors (>= 10 times in 1 hour).
 * Sends an alert email to the Super Admin (max 1 per hour, no personal data).
 *
 * Runs via CLI or Hostinger Cron (e.g. hourly):
 *   0 * * * * php /home/<user>/public_html/backend/scripts/check_errors.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

require_once __DIR__ . '/../includes/auth.php';

$repeated = site_check_php_error_log(10, 3600);
$count = count($repeated);

if ($count > 0) {
    echo "Found $count repeated error signature(s). Alert email sent to Super Admin.\n";
} else {
    echo "No repeating errors found in the last hour.\n";
}
