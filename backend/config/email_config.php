<?php

// ─────────────────────────────────────────────────────────────────────────────
// Gmail SMTP Configuration
//
// IMPORTANT: Gmail requires a 16-character App Password (not your normal login).
// To regenerate:
//   1. Go to https://myaccount.google.com/security
//   2. Enable 2-Step Verification (must be ON)
//   3. Search "App passwords" → create one for "Mail"
//   4. Put the 16-char code (no spaces) in backend/config/mail_accounts.local.php:
//      <?php define('HR_SMTP_PASSWORD', 'abcdabcdabcdabcd');
// ─────────────────────────────────────────────────────────────────────────────

defined('SMTP_HOST') || define('SMTP_HOST',     'smtp.gmail.com');
defined('SMTP_PORT') || define('SMTP_PORT',     587);
defined('SMTP_SECURITY') || define('SMTP_SECURITY', 'tls');
defined('SMTP_USERNAME') || define('SMTP_USERNAME', 'hr.bucuc@gmail.com');
// The App Password lives in the git-ignored mail_accounts.local.php (HR_SMTP_PASSWORD), shared with
// Email Notices sent by HR. It is never written in this file.
if (is_file(__DIR__ . '/mail_accounts.local.php')) {
    require_once __DIR__ . '/mail_accounts.local.php';
}
defined('SMTP_PASSWORD') || define('SMTP_PASSWORD', defined('HR_SMTP_PASSWORD') ? HR_SMTP_PASSWORD : '');
defined('FROM_EMAIL') || define('FROM_EMAIL',    'hr.bucuc@gmail.com');
defined('FROM_NAME') || define('FROM_NAME',     'BRAC University Cultural Club');

// Path to the dedicated email error log (relative to this file)
defined('EMAIL_LOG_PATH') || define('EMAIL_LOG_PATH', __DIR__ . '/../logs/email.log');
