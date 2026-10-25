<?php
/**
 * One place for sending e-mail (password resets, welcome e-mails, notices).
 *   mailer_make('club'|'hr', $fromName)  configured PHPMailer, or null when that account has no App Password
 *   mailer_capture(...)                  local testing: AUTH_MAIL_CAPTURE set → write to that file, never send
 *   mail_log_send($account, $kind, $to)  counts every e-mail an account sends (daily limits)
 *   mail_sent_today($account)            e-mails an account sent in the last 24 hours (all kinds)
 * Accounts and their App Passwords: backend/config/mail_accounts.php.
 */

require_once __DIR__ . '/../config/mail_accounts.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

/** True when e-mails are written to a local capture file instead of being sent (test setup). */
function mailer_capturing()
{
    return defined('AUTH_MAIL_CAPTURE');
}

function mailer_capture($account, $to, $subject, $text, $kind)
{
    @file_put_contents(AUTH_MAIL_CAPTURE, json_encode(['to' => $to, 'from' => MAIL_ACCOUNTS[$account]['email'] ?? '', 'subject' => $subject, 'text' => $text, 'kind' => $kind]) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** A PHPMailer ready to send from one account, or null if that account has no App Password. */
function mailer_make($account, $fromName = null, $keepAlive = false)
{
    $a = MAIL_ACCOUNTS[$account] ?? MAIL_ACCOUNTS['club'];
    $password = (string) constant($a['password_const']);
    if ($password === '') return null;
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $a['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $a['email'];
    $mail->Password = $password;
    $mail->SMTPSecure = ($a['security'] === 'tls') ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $a['port'];
    $mail->Timeout = 15;
    $mail->CharSet = 'UTF-8';
    $mail->SMTPKeepAlive = $keepAlive;
    $mail->setFrom($a['email'], $fromName ?? $a['name']);
    $mail->addReplyTo($a['email'], $fromName ?? $a['name']);
    return $mail;
}

function mailer_ensure(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_log (
        id INT AUTO_INCREMENT PRIMARY KEY, account_key VARCHAR(20) NOT NULL, kind VARCHAR(20) NOT NULL,
        recipient VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mail_account (account_key, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Records one sent e-mail (welcome, congratulations, password reset, alerts, handover, notices). Never throws. */
function mail_log_send($account, $kind, $to)
{
    try {
        $pdo = db();
        mailer_ensure($pdo);
        $pdo->prepare("INSERT INTO mail_log (account_key, kind, recipient) VALUES (?, ?, ?)")->execute([$account, $kind, mb_substr((string) $to, 0, 255)]);
        if (random_int(1, 100) === 1) $pdo->exec("DELETE FROM mail_log WHERE created_at < (NOW() - INTERVAL 30 DAY)");
    } catch (\Throwable $e) {
        error_log('mail_log_send: ' . $e->getMessage());
    }
}

/** Every e-mail one account sent in the last 24 hours across all categories (notices, password resets, welcome e-mails, alerts, handover). */
function mail_sent_today($account)
{
    $pdo = db();
    mailer_ensure($pdo);
    // Non-notice emails recorded in mail_log (welcome, auth, alert, handover)
    $st = $pdo->prepare("SELECT COUNT(*) FROM mail_log WHERE account_key = ? AND kind != 'notice' AND created_at > (NOW() - INTERVAL 1 DAY)");
    $st->execute([$account]);
    $n = (int) $st->fetchColumn();

    // Notices: counted from notice_recipients (handles both live delivery and test batch simulations)
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM notice_recipients r JOIN notices n ON n.id = r.notice_id
                             WHERE n.sender_key = ? AND r.status = 'sent' AND r.sent_at > (NOW() - INTERVAL 1 DAY)");
        $st->execute([$account]);
        $noticeCount = (int) $st->fetchColumn();
        if ($noticeCount > 0) {
            $n += $noticeCount;
        } else {
            // If notice_recipients not used or empty, check mail_log notice entries
            $st2 = $pdo->prepare("SELECT COUNT(*) FROM mail_log WHERE account_key = ? AND kind = 'notice' AND created_at > (NOW() - INTERVAL 1 DAY)");
            $st2->execute([$account]);
            $n += (int) $st2->fetchColumn();
        }
    } catch (\Throwable $e) {
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM mail_log WHERE account_key = ? AND kind = 'notice' AND created_at > (NOW() - INTERVAL 1 DAY)");
        $st2->execute([$account]);
        $n += (int) $st2->fetchColumn();
    }
    return $n;
}
