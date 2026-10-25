<?php
/**
 * E-mail notices to members (Admin → Email Notices).
 * A notice is saved as a job with one row per e-mail address, then sent in small batches through
 * SMTP (PHPMailer). The sending account depends on the admin's role (backend/config/mail_accounts.php)
 * and each account has its own daily limit. Members get the notice at both their personal e-mail
 * and their G-Suite address when they have both.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/auth_mail.php';
require_once __DIR__ . '/mailer.php';

const NOTICE_BATCH_SIZE = 25;      // e-mails per request
const NOTICE_PLACEHOLDERS = ['{name}' => 'Full name', '{first_name}' => 'First name', '{student_id}' => 'Student ID', '{level}' => 'GB / ASB / Secretary Panel'];
const NOTICE_DEFAULT_SUBJECT = 'Notice from BRAC University Cultural Club';
const NOTICE_DEFAULT_BODY = "Dear {name},\n\nWe hope you are doing well. This is a notice from BRAC University Cultural Club (BUCuC).\n\n[Write your notice here: what is happening, when and where, and what members need to do.]\n\nIf you have any questions, reply to this email or write to club.bucuc@g.bracu.ac.bd.\n\nWork • Bond • Glow\nBRAC University Cultural Club";

function notices_ensure(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS notices (
        id INT AUTO_INCREMENT PRIMARY KEY, subject VARCHAR(200) NOT NULL, body TEXT NOT NULL,
        audience VARCHAR(120) NOT NULL, sender_key VARCHAR(20) NOT NULL DEFAULT 'club', sender_email VARCHAR(255) NULL,
        members INT NOT NULL DEFAULT 0, total INT NOT NULL DEFAULT 0, sent INT NOT NULL DEFAULT 0, failed INT NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'sending', sent_by INT NULL, sent_by_name VARCHAR(100) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, finished_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notice_recipients (
        id INT AUTO_INCREMENT PRIMARY KEY, notice_id INT NOT NULL, member_id INT NULL,
        name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, student_id VARCHAR(50) NULL, level VARCHAR(5) NULL,
        status VARCHAR(8) NOT NULL DEFAULT 'pending', error VARCHAR(255) NULL, sent_at DATETIME NULL,
        batch_token CHAR(32) NULL, claimed_at DATETIME NULL,
        KEY idx_nr_notice (notice_id, status), KEY idx_nr_sent (status, sent_at), KEY idx_nr_batch (batch_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // notices created before 19 Oct 2026 lack the sender and member-count columns
    $cols = $pdo->query("SHOW COLUMNS FROM notices")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['sender_key' => "VARCHAR(20) NOT NULL DEFAULT 'club' AFTER audience", 'sender_email' => 'VARCHAR(255) NULL AFTER sender_key', 'members' => 'INT NOT NULL DEFAULT 0 AFTER sender_email',
              'sent_by_role' => 'VARCHAR(20) NULL AFTER sent_by_name'] as $col => $def) {
        if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE notices ADD COLUMN $col $def");
    }
    // batch claiming, added 2026-10-19
    $rcols = $pdo->query("SHOW COLUMNS FROM notice_recipients")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('batch_token', $rcols, true)) {
        $pdo->exec("ALTER TABLE notice_recipients ADD COLUMN batch_token CHAR(32) NULL AFTER sent_at, ADD COLUMN claimed_at DATETIME NULL AFTER batch_token, ADD KEY idx_nr_batch (batch_token)");
    }
    db_add_fk($pdo, 'notice_recipients', 'fk_nr_notice', 'notice_id', 'notices');   // deleting a notice removes its recipient rows
}

/** Sender account for a role: ['key', 'email', 'name', 'host', 'port', 'security', 'daily_limit', 'password'(internal)]. */
function notice_account_for_role($role)
{
    $key = MAIL_ACCOUNT_FOR_ROLE[role_normalize($role)] ?? 'club';
    return notice_account($key);
}

function notice_account($key)
{
    $a = MAIL_ACCOUNTS[$key] ?? MAIL_ACCOUNTS['club'];
    $a['key'] = isset(MAIL_ACCOUNTS[$key]) ? $key : 'club';
    $a['password'] = (string) constant($a['password_const']);
    return $a;
}

/** True if the account can actually send (has an App Password, or mail is captured in a test setup). */
function notice_account_ready(array $a)
{
    return defined('AUTH_MAIL_CAPTURE') || $a['password'] !== '';
}

/** A usable address (valid, not an import placeholder) or null. */
function notice_clean_email($e)
{
    $e = strtolower(trim((string) $e));
    if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) return null;
    if (substr($e, -strlen('@members.bucuc.local')) === '@members.bucuc.local') return null;
    return $e;
}

/** All addresses a member is e-mailed at: personal e-mail (Gmail) and G-Suite, whichever exist, no duplicates. */
function notice_member_emails(array $m)
{
    $out = [];
    foreach ([$m['email'] ?? '', $m['gsuite_email'] ?? ''] as $e) {
        $e = notice_clean_email($e);
        if ($e !== null && !in_array($e, $out, true)) $out[] = $e;
    }
    return $out;
}

/** Replaces {name}, {first_name}, {student_id}, {level} for one recipient. */
function notice_fill($text, array $r)
{
    $levels = ['GB' => 'GB', 'ASB' => 'ASB', 'SB' => 'Secretary Panel'];
    $first = trim(explode(' ', trim($r['name']))[0] ?? '');
    return strtr($text, ['{name}' => $r['name'], '{first_name}' => $first ?: $r['name'], '{student_id}' => (string) ($r['student_id'] ?? ''), '{level}' => $levels[$r['level'] ?? ''] ?? '']);
}

/** E-mails one account sent in the last 24 hours (notices + password resets + welcome e-mails). */
function notices_sent_today(PDO $pdo, $senderKey)
{
    notices_ensure($pdo);
    return mail_sent_today($senderKey);
}

/**
 * Sends the next batch of one notice from the notice's own sender account. Returns counts and
 * whether it is finished or paused by that account's daily limit.
 */
function notice_send_batch(PDO $pdo, $noticeId)
{
    notices_ensure($pdo);
    $n = $pdo->prepare("SELECT * FROM notices WHERE id = ?");
    $n->execute([$noticeId]);
    $notice = $n->fetch();
    if (!$notice) throw new RuntimeException('That notice no longer exists.');
    $acct = notice_account($notice['sender_key']);

    // Rows another batch claimed but never finished (closed tab, crash) go back to the queue after 10 minutes
    $pdo->prepare("UPDATE notice_recipients SET status = 'pending', batch_token = NULL, claimed_at = NULL
                   WHERE notice_id = ? AND status = 'sending' AND claimed_at < (NOW() - INTERVAL 10 MINUTE)")->execute([$noticeId]);

    // Room left today for this account; e-mails being sent right now by other batches count too
    $busy = $pdo->prepare("SELECT COUNT(*) FROM notice_recipients r JOIN notices n ON n.id = r.notice_id WHERE n.sender_key = ? AND r.status = 'sending'");
    $busy->execute([$acct['key']]);
    $room = $acct['daily_limit'] - notices_sent_today($pdo, $acct['key']) - (int) $busy->fetchColumn();
    $batch = [];
    if ($room > 0) {
        // Claim the rows in ONE query, so two batches running at once never get the same e-mail
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE notice_recipients SET status = 'sending', batch_token = ?, claimed_at = NOW()
                       WHERE notice_id = ? AND status = 'pending' ORDER BY id LIMIT " . (int) min(NOTICE_BATCH_SIZE, $room))
            ->execute([$token, $noticeId]);
        $q = $pdo->prepare("SELECT * FROM notice_recipients WHERE batch_token = ? AND status = 'sending' ORDER BY id");
        $q->execute([$token]);
        $batch = $q->fetchAll();
    }

    if ($batch) {
        $mark = $pdo->prepare("UPDATE notice_recipients SET status = ?, error = ?, sent_at = ?, batch_token = NULL WHERE id = ?");
        $capture = mailer_capturing();
        $mail = $capture ? null : mailer_make($acct['key'], null, true);   // one SMTP connection for the whole batch
        foreach ($batch as $r) {
            $subject = notice_fill($notice['subject'], $r);
            $text = notice_fill($notice['body'], $r);
            $html = auth_mail_layout($subject, '<div style="font-size:15px;line-height:1.7">' . nl2br(htmlspecialchars($text)) . '</div>');
            $error = null;
            if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid e-mail address';
            } elseif ($capture) {
                mailer_capture($acct['key'], $r['email'], $subject, $text, 'notice');
            } elseif (!$mail) {
                $error = 'No App Password set for ' . $acct['email'];
            } else {
                try {
                    $mail->clearAddresses();
                    $mail->addAddress($r['email'], $r['name']);
                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $html;
                    $mail->AltBody = $text;
                    $mail->send();
                } catch (\Throwable $e) {
                    $error = mb_substr($mail->ErrorInfo ?: $e->getMessage(), 0, 250);
                    try { $mail->getSMTPInstance()->reset(); } catch (\Throwable $e2) { }
                }
            }
            $mark->execute([$error ? 'failed' : 'sent', $error, $error ? null : date('Y-m-d H:i:s'), $r['id']]);
            if (!$error) {
                mail_log_send($acct['key'], 'notice', $r['email']);
            }
            if ($error) auth_mail_log("NOTICE #$noticeId from {$acct['email']} FAILED to {$r['email']}: $error");
        }
        if ($mail) $mail->smtpClose();
    }

    // Totals and status (counted in e-mails, not members)
    $c = $pdo->prepare("SELECT SUM(status = 'sent'), SUM(status = 'failed'), SUM(status IN ('pending', 'sending')), COUNT(*) FROM notice_recipients WHERE notice_id = ?");
    $c->execute([$noticeId]);
    [$sent, $failed, $pending, $total] = array_map('intval', $c->fetch(PDO::FETCH_NUM));
    $done = $pending === 0;
    $pdo->prepare("UPDATE notices SET sent = ?, failed = ?, status = ?, finished_at = IF(? AND finished_at IS NULL, NOW(), finished_at) WHERE id = ?")
        ->execute([$sent, $failed, $done ? 'done' : 'sending', $done ? 1 : 0, $noticeId]);
    if ($done && $notice['status'] !== 'done') {
        log_activity('notice_finished', "$sent e-mails sent, $failed failed, from {$acct['email']}", null, null, "\"{$notice['subject']}\" to {$notice['audience']}");
    }
    return ['sent' => $sent, 'failed' => $failed, 'pending' => $pending, 'total' => $total, 'done' => $done, 'from' => $acct['email'],
        'limitReached' => !$done && ($acct['daily_limit'] - notices_sent_today($pdo, $acct['key'])) <= 0];
}
