<?php
/**
 * Sends the handover reminder e-mail when it is due (about one month before the current tenure's
 * end date, once). The admin dashboard also does this when it is opened; a cron job makes it
 * independent of anyone logging in. Hostinger: hPanel → Advanced → Cron Jobs, once a day:
 *   php /home/<user>/public_html/backend/scripts/handover_reminders.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/handover.php';

$sent = handover_send_due_reminders(db());
echo $sent ? "Handover reminder sent to $sent admin(s).\n" : "No handover reminder due.\n";
