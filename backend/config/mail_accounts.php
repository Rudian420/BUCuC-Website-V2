<?php
// ─────────────────────────────────────────────────────────────────────────────
// Sender accounts for Email Notices, and which admin role sends from which.
// Passwords are NOT stored here: each account reads its App Password from a
// git-ignored file, and the admin panel never shows them.
//
//   club  club.bucuc@g.bracu.ac.bd  password: AUTH_SMTP_PASSWORD in auth_email_config.local.php
//                                   (the same one used for password-reset e-mails)
//   hr    hr.bucuc@gmail.com        password: HR_SMTP_PASSWORD in mail_accounts.local.php
//
// hr.bucuc@gmail.com is a normal Gmail account: turn on 2-Step Verification, create an
// App Password, then upload backend/config/mail_accounts.local.php containing
//   <?php define('HR_SMTP_PASSWORD', 'abcdabcdabcdabcd');
//
// Daily limits are counted per account (e-mails sent in the last 24 hours): Google Workspace
// allows about 2,000 a day, a normal Gmail account about 500, so stay below both.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/auth_email_config.php';
if (is_file(__DIR__ . '/mail_accounts.local.php')) {
    require_once __DIR__ . '/mail_accounts.local.php';
}
defined('HR_SMTP_PASSWORD') || define('HR_SMTP_PASSWORD', '');

const MAIL_ACCOUNTS = [
    'club' => [
        'email' => AUTH_FROM_EMAIL, 'name' => 'BRAC University Cultural Club', 'password_const' => 'AUTH_SMTP_PASSWORD',
        'host' => AUTH_SMTP_HOST, 'port' => AUTH_SMTP_PORT, 'security' => AUTH_SMTP_SECURITY, 'daily_limit' => 1500,
    ],
    'hr' => [
        'email' => 'hr.bucuc@gmail.com', 'name' => 'BUCuC Human Resources', 'password_const' => 'HR_SMTP_PASSWORD',
        'host' => 'smtp.gmail.com', 'port' => 587, 'security' => 'tls', 'daily_limit' => 400,
    ],
];

// Which account each role sends notices from.
// MIAP and Secretary Admin (only if given the send permission): club account until decided.
const MAIL_ACCOUNT_FOR_ROLE = [
    'super_admin'     => 'club',
    'panel_admin'     => 'club',
    'hr_admin'        => 'hr',
    'miap_admin'      => 'club',
    'secretary_admin' => 'club',
];
