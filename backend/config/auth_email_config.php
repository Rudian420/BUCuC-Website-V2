<?php
// ─────────────────────────────────────────────────────────────────────────────
// Admin account e-mails (forgot-password links, password-changed notices)
// are sent from the club's BRACU Google Workspace account.
//
// SMTP_PASSWORD must be a 16-character Google App Password for
// club.bucuc@g.bracu.ac.bd (not the normal login password):
//   1. Sign in as club.bucuc@g.bracu.ac.bd → https://myaccount.google.com/security
//   2. Turn on 2-Step Verification
//   3. Search "App passwords" → create one named "BUCuC website" → copy the code
// If "App passwords" is missing, the BRACU IT admin has disabled it for the
// domain; ask them to allow it for this account.
//
// Keep the real password out of git: put it in auth_email_config.local.php
// (same folder, git-ignored), e.g.
//   <?php define('AUTH_SMTP_PASSWORD', 'abcdabcdabcdabcd');
// ─────────────────────────────────────────────────────────────────────────────

if (is_file(__DIR__ . '/auth_email_config.local.php')) {
    require_once __DIR__ . '/auth_email_config.local.php';
}

defined('AUTH_SMTP_HOST')     || define('AUTH_SMTP_HOST', 'smtp.gmail.com');
defined('AUTH_SMTP_PORT')     || define('AUTH_SMTP_PORT', 587);
defined('AUTH_SMTP_SECURITY') || define('AUTH_SMTP_SECURITY', 'tls');
defined('AUTH_SMTP_USERNAME') || define('AUTH_SMTP_USERNAME', 'club.bucuc@g.bracu.ac.bd');
defined('AUTH_SMTP_PASSWORD') || define('AUTH_SMTP_PASSWORD', '');
defined('AUTH_FROM_EMAIL')    || define('AUTH_FROM_EMAIL', 'club.bucuc@g.bracu.ac.bd');
defined('AUTH_FROM_NAME')     || define('AUTH_FROM_NAME', 'BUCuC Admin');

// Public address of the site, used to build reset links (never taken from the request).
defined('APP_URL')            || define('APP_URL', 'https://bucuc.online');

defined('AUTH_EMAIL_LOG_PATH') || define('AUTH_EMAIL_LOG_PATH', __DIR__ . '/../logs/auth_email.log');
