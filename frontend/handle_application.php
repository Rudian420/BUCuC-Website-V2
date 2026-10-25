<?php
/**
 * Application workflow handler (form POST target for the admin list pages).
 *
 *   pending_applications --shortlist--> shortlisted_members --accept--> members
 *            |                                  |                         |
 *          reject                             reject               delete_approved
 *
 * POST fields: action, member_id, source (pending|shortlisted), redirect_to
 */

require_once '../backend/includes/auth.php';
require_admin(['can' => 'shortlist']);

// Only redirect back to our own list pages
$allowedRedirects = ['pending_applications.php', 'shortlisted_members.php', 'approved_members.php'];
$redirect_to = $_POST['redirect_to'] ?? 'pending_applications.php';
if (!in_array($redirect_to, $allowedRedirects, true)) {
    $redirect_to = 'pending_applications.php';
}

function redirectWith($page, $type, $message)
{
    header("Location: " . $page . "?" . $type . "=" . urlencode($message));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWith($redirect_to, 'error', 'Invalid request method');
}

require_once '../backend/Database/db.php';
require_once '../backend/config/email_config.php';
require_once '../backend/includes/mailer.php';
require_once '../backend/includes/email_template.php';
require_once '../backend/Action/google_sheets_integration.php';
require_once '../backend/includes/applicant_deletion.php';


$action = $_POST['action'] ?? '';
$memberId = filter_var($_POST['member_id'] ?? '', FILTER_VALIDATE_INT);
$canApprove = can('final_approve');   // final approval + rejecting shortlisted members
$canManage = can('manage_site');      // deleting approved members

if ($action === '' || $memberId === false) {
    redirectWith($redirect_to, 'error', 'Missing required parameters');
}

$memberColumns = ['full_name', 'university_id', 'email', 'gsuite_email', 'department', 'phone',
    'semester', 'gender', 'date_of_birth', 'facebook_url', 'firstPriority', 'secondPriority', 'created_at'];

/**
 * Copy one applicant row into $table, keeping the original application date.
 */
function insertApplicant(PDO $pdo, $table, array $member, array $columns)
{
    $sql = "INSERT INTO $table (" . implode(', ', $columns) . ", updated_at)
            VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ", CURRENT_TIMESTAMP)";
    $values = [];
    foreach ($columns as $col) {
        $values[] = $member[$col] ?? null;
    }
    $pdo->prepare($sql)->execute($values);
}

try {
    $database = new Database();
    $pdo = $database->createConnection();

    if ($action === 'reject') {
        // IDs are independent per table, so only delete from the list the admin is looking at
        $source = $_POST['source'] ?? '';
        $tables = ['pending' => 'pending_applications', 'shortlisted' => 'shortlisted_members'];
        if (!isset($tables[$source])) {
            redirectWith($redirect_to, 'error', 'Unknown application list');
        }
        if ($source === 'shortlisted' && !$canApprove) {
            redirectWith($redirect_to, 'error', 'You need the final approval permission to reject shortlisted members.');
        }

        $simulateFailure = !empty($_POST['simulate_failure']);
        $res = applicant_reject_and_delete($pdo, $memberId, $source, 'rejected', $simulateFailure);

        if ($res['success']) {
            redirectWith($redirect_to, 'success', 'Application rejected and all applicant personal data permanently deleted.');
        }
        redirectWith($redirect_to, 'error', $res['error'] ?? 'Application not found');

    } elseif ($action === 'shortlist') {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM pending_applications WHERE id = ? FOR UPDATE");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();

        if (!$member) {
            $pdo->rollBack();
            redirectWith($redirect_to, 'error', 'Application not found');
        }

        $colsToInsert = $memberColumns;
        if (!empty($member['tracking_token'])) {
            $colsToInsert[] = 'tracking_token';
            $pdo->prepare("UPDATE `application_tracking` SET `status` = 'shortlisted', `updated_at` = NOW() WHERE `tracking_token` = ?")
                ->execute([$member['tracking_token']]);
        }

        insertApplicant($pdo, 'shortlisted_members', $member, $colsToInsert);
        $pdo->prepare("DELETE FROM pending_applications WHERE id = ?")->execute([$memberId]);
        $pdo->commit();
        log_activity('application_shortlisted', 'Moved from pending to shortlisted', null, null, "{$member['full_name']} ({$member['university_id']})");

        redirectWith($redirect_to, 'success', $member['full_name'] . ' was shortlisted. An admin with final-approval permission can now approve them.');

    } elseif ($action === 'accept') {
        if (!$canApprove) {
            redirectWith($redirect_to, 'error', 'You need the final approval permission to do this.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM shortlisted_members WHERE id = ? FOR UPDATE");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();

        if (!$member) {
            $pdo->rollBack();
            redirectWith($redirect_to, 'error', 'Shortlisted member not found');
        }

        $checkStmt = $pdo->prepare("SELECT id FROM members WHERE university_id = ? OR email = ? OR gsuite_email = ?");
        $checkStmt->execute([$member['university_id'], $member['email'], $member['gsuite_email']]);
        if ($checkStmt->fetch()) {
            $pdo->rollBack();
            redirectWith($redirect_to, 'error', 'Member already exists in the approved list.');
        }

        // Save to DB first: acceptance must never depend on e-mail or Google Sheets
        insertApplicant($pdo, 'members', $member, $memberColumns);
        $pdo->prepare("DELETE FROM shortlisted_members WHERE id = ?")->execute([$memberId]);

        // Update tracking status to approved
        if (!empty($member['tracking_token'])) {
            $pdo->prepare("UPDATE `application_tracking` SET `status` = 'approved', `updated_at` = NOW() WHERE `tracking_token` = ?")
                ->execute([$member['tracking_token']]);
        }

        $pdo->commit();

        $emailResult = sendCongratulationsEmail($pdo, $member);
        logEmailResult($member['full_name'], $emailResult);

        $sheetsResult = sendToGoogleSheets($member);
        logGoogleSheetsOperation('accept_member', $member, $sheetsResult);

        log_activity('member_accepted', 'Final approval; email ' . ($emailResult['success'] ? 'sent' : 'FAILED') . ', sheet ' . ($sheetsResult['success'] ? 'updated' : 'FAILED'), null, null, "{$member['full_name']} ({$member['university_id']})");

        $warnings = [];
        if (!$emailResult['success']) {
            $warnings[] = 'email failed (' . ($emailResult['error'] ?? 'unknown') . ') — check the SMTP App Password';
        }
        if (!$sheetsResult['success']) {
            $warnings[] = 'Google Sheets: ' . ($sheetsResult['message'] ?? 'not available');
        }

        if (empty($warnings)) {
            redirectWith($redirect_to, 'success', 'Member accepted successfully. Email sent to: ' . implode(', ', $emailResult['recipients']));
        }
        redirectWith($redirect_to, 'warning', 'Member accepted and saved to database. Warnings: ' . implode('; ', $warnings));

    } elseif ($action === 'delete_approved') {
        if (!$canManage) {
            redirectWith($redirect_to, 'error', 'Only the Panel Admin can delete approved members.');
        }

        $who = $pdo->prepare("SELECT full_name, university_id FROM members WHERE id = ?");
        $who->execute([$memberId]);
        $deleted = $who->fetch();

        $stmt = $pdo->prepare("DELETE FROM members WHERE id = ?");
        $stmt->execute([$memberId]);

        if ($stmt->rowCount() > 0) {
            log_activity('member_deleted', 'Removed from approved members', null, null, $deleted ? "{$deleted['full_name']} ({$deleted['university_id']})" : "#$memberId");
            redirectWith($redirect_to, 'success', 'Member record deleted successfully');
        }
        redirectWith($redirect_to, 'error', 'Member not found');

    } else {
        redirectWith($redirect_to, 'error', 'Invalid action specified');
    }
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirectWith($redirect_to, 'error', friendly_error($e, 'Updating the application'));
}

/**
 * Deduplicated, validated recipients: G-Suite address first, then the personal
 * address if it is different. Returns a list of e-mail strings.
 */
function resolveRecipients(array $member)
{
    $recipients = [];
    foreach ([$member['gsuite_email'] ?? '', $member['email'] ?? ''] as $address) {
        $address = trim($address);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) && !in_array(strtolower($address), array_map('strtolower', $recipients), true)) {
            $recipients[] = $address;
        }
    }
    return $recipients;
}

function logEmailResult($name, array $result)
{
    $entry = json_encode([
        'timestamp'  => date('Y-m-d H:i:s'),
        'name'       => $name,
        'recipients' => $result['recipients'] ?? [],
        'success'    => $result['success'],
        'detail'     => $result['error'] ?? 'sent',
    ]) . PHP_EOL;

    @file_put_contents(EMAIL_LOG_PATH, $entry, FILE_APPEND | LOCK_EX);
}

function sendCongratulationsEmail(PDO $pdo, array $member)
{
    $recipients = resolveRecipients($member);

    if (empty($recipients)) {
        return ['success' => false, 'error' => 'No valid email address found for ' . $member['full_name'], 'recipients' => []];
    }

    $venueInfo = getLatestVenueInfo($pdo);
    $subject = 'Congratulations! Welcome to BRAC University Cultural Club';
    if (mailer_capturing()) {   // local testing: written to a file, never sent
        foreach ($recipients as $address) {
            mailer_capture('hr', $address, $subject, generatePlainTextEmail($member, $venueInfo), 'welcome');
            mail_log_send('hr', 'welcome', $address);   // counts toward the account's daily limit
        }
        return ['success' => true, 'recipients' => $recipients];
    }
    // Sent from hr.bucuc@gmail.com (App Password in backend/config/mail_accounts.local.php)
    $mail = mailer_make('hr', FROM_NAME);
    if (!$mail) {
        return ['success' => false, 'error' => 'no App Password set for hr.bucuc@gmail.com', 'recipients' => $recipients];
    }
    try {
        foreach ($recipients as $address) {
            $mail->addAddress($address, $member['full_name']);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = generateEmailTemplate($member, $venueInfo);
        $mail->AltBody = generatePlainTextEmail($member, $venueInfo);
        $mail->send();
        foreach ($recipients as $address) {
            mail_log_send('hr', 'welcome', $address);   // counts toward the account's daily limit
        }
        return ['success' => true, 'recipients' => $recipients];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage(), 'recipients' => $recipients];
    }
}
