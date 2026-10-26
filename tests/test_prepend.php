<?php
/**
 * TEST SETTINGS ONLY (loaded before every page by tests/run.js via auto_prepend_file).
 * Points the site at the throwaway test database on port 3307 and keeps e-mail, Google Sheets
 * and YouTube traffic off the real services. The config files use defined() || define(), so
 * these values win. No real password belongs in this file.
 */
$runtime = __DIR__ . '/runtime';
$dbPort = getenv('TEST_DB_PORT') ?: '3607';
define('DB_HOST', '127.0.0.1;port=' . $dbPort);
define('DB_NAME', 'bucuc_test');
define('DB_USER', 'root');
define('DB_PASS', 'rootpw');                       // the throwaway test server's password
define('PHP_ERROR_LOG', $runtime . '/php_errors.log');
define('SMTP_HOST', '127.0.0.1');
define('SMTP_PORT', 2525);                         // nothing listens there: mail can't leave
define('SMTP_SECURITY', 'tls');
define('SMTP_USERNAME', 'test');
define('SMTP_PASSWORD', 'test');
define('FROM_EMAIL', 'test@localhost');
define('FROM_NAME', 'Local Test');
define('EMAIL_LOG_PATH', $runtime . '/email_test.log');
define('GOOGLE_SHEETS_WEBAPP_URL', 'http://127.0.0.1:9/');   // closed port: accepted-member rows go nowhere
define('GOOGLE_SHEETS_TIMEOUT', 3);
define('GOOGLE_SHEETS_LOGGING', false);
define('APP_URL', 'http://127.0.0.1:8099');
define('AUTH_SMTP_PASSWORD', 'not-used-in-tests');
define('AUTH_MAIL_CAPTURE', $runtime . '/auth_mail_capture.jsonl');
define('AUTH_EMAIL_LOG_PATH', $runtime . '/auth_email_test.log');
define('MEMBER_SHEET_CSV_URL', 'http://127.0.0.1:8098/csv.php?output=csv');
define('MEMBER_SHEET_GAS_URL', 'http://127.0.0.1:8098/gas.php');
define('MEMBER_SHEET_SECRET', 'test-shared-webhook-secret-xyz');
define('GALLERY_OEMBED_URL', 'http://127.0.0.1:8098/oembed.php');
define('GOATCOUNTER_CODE', 'bucuc');
