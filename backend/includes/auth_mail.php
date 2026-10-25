<?php
/**
 * Sends admin-account e-mails (password reset links, password-changed notices) from the club account,
 * club.bucuc@g.bracu.ac.bd, through the shared mailer (backend/includes/mailer.php).
 * Returns true on success. Failures are written to backend/logs/auth_email.log, never shown to the visitor.
 */

require_once __DIR__ . '/../config/auth_email_config.php';
require_once __DIR__ . '/mailer.php';

function auth_mail_log($line)
{
    @file_put_contents(AUTH_EMAIL_LOG_PATH, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function send_auth_email($to, $toName, $subject, $html, $text)
{
    if (mailer_capturing()) {   // local testing: written to a file, never sent
        mailer_capture('club', $to, $subject, $text, 'auth');
        mail_log_send('club', 'auth', $to);
        return true;
    }
    $mail = mailer_make('club', AUTH_FROM_NAME);
    if (!$mail) {
        auth_mail_log("NOT SENT to $to ($subject): AUTH_SMTP_PASSWORD is empty - set it in backend/config/auth_email_config.local.php");
        return false;
    }
    try {
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        mail_log_send('club', 'auth', $to);
        auth_mail_log("Sent to $to ($subject)");
        return true;
    } catch (\Throwable $e) {
        auth_mail_log("FAILED to $to ($subject): " . ($mail->ErrorInfo ?: $e->getMessage()));
        return false;
    }
}

/** Simple branded HTML wrapper for admin e-mails. */
function auth_mail_layout($title, $bodyHtml)
{
    $logo = rtrim(APP_URL, '/') . '/frontend/images/logopng.png';
    return '<!DOCTYPE html><html><body style="margin:0;background:#f4f2fb;font-family:Arial,Helvetica,sans-serif;color:#15102c">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">'
        . '<table role="presentation" width="100%" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden" cellpadding="0" cellspacing="0">'
        . '<tr><td style="height:4px;background:linear-gradient(90deg,#5cffb0,#2de2e6,#8b5cff,#ff4fd8)"></td></tr>'
        . '<tr><td style="padding:28px 32px 8px"><img src="' . $logo . '" alt="BUCuC" width="56" height="56" style="display:block"></td></tr>'
        . '<tr><td style="padding:8px 32px 28px"><h1 style="font-size:20px;margin:0 0 14px">' . htmlspecialchars($title) . '</h1>' . $bodyHtml . '</td></tr>'
        . '<tr><td style="padding:16px 32px;background:#fbfaff;color:#6b6588;font-size:12px">BRAC University Cultural Club · admin panel</td></tr>'
        . '</table></td></tr></table></body></html>';
}
