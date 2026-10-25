<?php
/**
 * Cron job for sending email notices in the background.
 *
 * Runs via CLI or Hostinger Cron every 1 or 5 minutes:
 *   * * * * * php /home/<user>/public_html/backend/scripts/send_notices.php
 *
 * Safety & Architecture:
 *   - MySQL GET_LOCK('bucuc_send_notices', 0) ensures two cron runs NEVER overlap.
 *   - Atomic batch claiming with random tokens (H4 fix) in notice_send_batch() ensures
 *     nobody gets duplicate emails even if web UI and cron run concurrently.
 *   - Daily sending limits per role account are strictly enforced.
 *   - The web progress page (frontend/notices.php) still polls and displays live status.
 *
 * Options:
 *   --test          Run in test mode (prints JSON, uses test database)
 *   --force         Skip lock (only for testing)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

ini_set('memory_limit', '256M');
ini_set('max_execution_time', '180');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notices.php';

$isTest = in_array('--test', $argv ?? [], true);
$skipLock = in_array('--force', $argv ?? [], true);

try {
    $pdo = db();
    site_ensure_schema($pdo);
    notices_ensure($pdo);

    // Advisory lock so two cron runs never overlap
    $lockAcquired = false;
    if (!$skipLock) {
        $st = $pdo->query("SELECT GET_LOCK('bucuc_send_notices', 0)");
        $locked = (int) $st->fetchColumn();
        if ($locked !== 1) {
            if ($isTest) {
                echo json_encode(['status' => 'locked', 'message' => 'Another send_notices job is already running']) . "\n";
            }
            exit(0);
        }
        $lockAcquired = true;
    }

    // Find all notices currently in 'sending' state
    $notices = $pdo->query("SELECT id, subject, sender_key, audience, total, sent, failed FROM notices WHERE status = 'sending' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($notices as $n) {
        $nid = (int) $n['id'];
        try {
            $batchResult = notice_send_batch($pdo, $nid);
            $results[] = [
                'notice_id' => $nid,
                'subject'   => $n['subject'],
                'result'    => $batchResult,
            ];
            if (!$isTest) {
                echo "Notice #$nid ('{$n['subject']}'): sent={$batchResult['sent']}, failed={$batchResult['failed']}, pending={$batchResult['pending']}, done=" . ($batchResult['done'] ? 'yes' : 'no') . "\n";
            }
        } catch (\Throwable $e) {
            error_log("send_notices error on notice #$nid: " . $e->getMessage());
            $results[] = [
                'notice_id' => $nid,
                'error'     => $e->getMessage(),
            ];
        }
    }

    if ($lockAcquired) {
        $pdo->query("SELECT RELEASE_LOCK('bucuc_send_notices')");
    }

    // Optional heartbeat ping on success (e.g. healthchecks.io)
    if (defined('NOTICE_CRON_HEARTBEAT_URL') && NOTICE_CRON_HEARTBEAT_URL) {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'user_agent' => 'BUCuC-Notice-Heartbeat/1.0']]);
        @file_get_contents(NOTICE_CRON_HEARTBEAT_URL, false, $ctx);
    }

    if ($isTest) {
        echo json_encode([
            'status'       => 'ok',
            'processed'    => count($results),
            'notices'      => $results,
        ], JSON_PRETTY_PRINT) . "\n";
    }

    exit(0);
} catch (\Throwable $e) {
    if (isset($pdo) && isset($lockAcquired) && $lockAcquired) {
        try { $pdo->query("SELECT RELEASE_LOCK('bucuc_send_notices')"); } catch (\Throwable $e2) {}
    }
    $err = "send_notices failed: " . $e->getMessage();
    error_log($err);
    fwrite(STDERR, "$err\n");
    if (defined('NOTICE_CRON_HEARTBEAT_URL') && NOTICE_CRON_HEARTBEAT_URL) {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'user_agent' => 'BUCuC-Notice-Heartbeat/1.0']]);
        @file_get_contents(rtrim(NOTICE_CRON_HEARTBEAT_URL, '/') . '/fail', false, $ctx);
    }
    site_alert_super_admin('BUCuC Notice Cron Failure', $err, $isTest);
    exit(1);
}
