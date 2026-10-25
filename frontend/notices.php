<?php
require_once '../backend/includes/notices.php';

// Email notices to members. Needs the send_notices permission (Panel Admin and HR by default;
// it can be ticked for MIAP / Secretary Admin). Batches are sent by the page itself (?action=batch).
require_admin(['can' => 'send_notices', 'json' => isset($_GET['action']), 'csrf_redirect' => 'notices.php']);

$conn = db();
site_ensure_schema($conn);
notices_ensure($conn);
const LEVEL_NAMES = ['GB' => 'GB', 'ASB' => 'ASB', 'SB' => 'Secretary Panel'];

// ---------------------------------------------------------------- JSON: send the next batch
if (($_GET['action'] ?? '') === 'batch') {
    header('Content-Type: application/json');
    $noticeId = (int) ($_POST['id'] ?? 0);
    try {
        // Use the same advisory lock as the cron job so web & cron never conflict
        $gotLock = (bool) $conn->query("SELECT GET_LOCK('bucuc_send_notices', 1)")->fetchColumn();
        if ($gotLock) {
            try {
                $batchRes = notice_send_batch($conn, $noticeId);
                echo json_encode(['success' => true] + $batchRes);
            } finally {
                $conn->query("SELECT RELEASE_LOCK('bucuc_send_notices')");
            }
        } else {
            // Cron job is actively running in background; return live progress without double-sending
            $c = $conn->prepare("SELECT SUM(status = 'sent'), SUM(status = 'failed'), SUM(status IN ('pending', 'sending')), COUNT(*) FROM notice_recipients WHERE notice_id = ?");
            $c->execute([$noticeId]);
            [$sent, $failed, $pending, $total] = array_map('intval', $c->fetch(PDO::FETCH_NUM));
            echo json_encode([
                'success' => true,
                'sent' => $sent,
                'failed' => $failed,
                'pending' => $pending,
                'total' => $total,
                'done' => ($pending === 0),
                'limitReached' => false,
                'cronActive' => true,
            ]);
        }
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => friendly_error($e, 'Sending this batch')]);
    }
    exit();
}

$error = '';
$acct = notice_account_for_role($_SESSION['admin_role']);   // who this admin sends from
$members = $conn->query("SELECT id, full_name, university_id, email, gsuite_email, position FROM members ORDER BY full_name")->fetchAll();
foreach ($members as &$m) $m['to'] = notice_member_emails($m);   // personal Gmail + G-Suite
unset($m);
$reachable = array_values(array_filter($members, fn($m) => count($m['to']) > 0));
$emailsOf = fn(array $list) => count(array_unique(array_merge([], ...array_map(fn($m) => $m['to'], $list))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $type = $_POST['audience'] ?? 'all';
        $picked = [];
        if ($type === 'all') {
            $picked = $reachable;
            $audience = 'All members';
        } elseif ($type === 'group' && isset(LEVEL_NAMES[$_POST['group'] ?? ''])) {
            $g = $_POST['group'];
            $picked = array_values(array_filter($reachable, fn($m) => $m['position'] === $g));
            $audience = LEVEL_NAMES[$g] . ' members';
        } elseif ($type === 'pick') {
            $ids = array_map('intval', (array) ($_POST['member_ids'] ?? []));
            $picked = array_values(array_filter($reachable, fn($m) => in_array((int) $m['id'], $ids, true)));
            $audience = count($picked) . ' selected member' . (count($picked) === 1 ? '' : 's');
        } else {
            $audience = '';
        }
        // One row per e-mail address (a member with Gmail + G-Suite gets two); never the same address twice
        $rows = [];
        foreach ($picked as $m) {
            foreach ($m['to'] as $addr) {
                if (!isset($rows[$addr])) $rows[$addr] = [$m, $addr];
            }
        }
        if (!notice_account_ready($acct)) {
            $error = "The sending account {$acct['email']} has no App Password set up yet, so nothing can be sent. Ask whoever looks after the website to set it up.";
        } elseif ($subject === '' || $body === '') {
            $error = 'Write a subject and a message.';
        } elseif (!$picked) {
            $error = 'No members with an e-mail address match those recipients.';
        } else {
            $conn->beginTransaction();
            $conn->prepare("INSERT INTO notices (subject, body, audience, sender_key, sender_email, members, total, sent_by, sent_by_name, sent_by_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([mb_substr($subject, 0, 200), $body, $audience, $acct['key'], $acct['email'], count($picked), count($rows), $_SESSION['admin_id'], $_SESSION['username'], $_SESSION['admin_role']]);
            $nid = (int) $conn->lastInsertId();
            $ins = $conn->prepare("INSERT INTO notice_recipients (notice_id, member_id, name, email, student_id, level) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($rows as [$m, $addr]) $ins->execute([$nid, $m['id'], $m['full_name'], $addr, $m['university_id'], $m['position']]);
            $conn->commit();
            log_activity('notice_sent', count($rows) . ' e-mail(s) to ' . count($picked) . ' member(s) from ' . $acct['email'] . '; subject "' . $subject . '"', null, null, $audience);
            header("Location: notices.php?run=$nid");
            exit();
        }
    } elseif ($action === 'retry') {
        $nid = (int) ($_POST['id'] ?? 0);
        $conn->prepare("UPDATE notice_recipients SET status = 'pending', error = NULL WHERE notice_id = ? AND status = 'failed'")->execute([$nid]);
        $conn->prepare("UPDATE notices SET status = 'sending' WHERE id = ?")->execute([$nid]);
        header("Location: notices.php?run=$nid");
        exit();
    }
}

$run = null;
if (isset($_GET['run'])) {
    $st = $conn->prepare("SELECT * FROM notices WHERE id = ?");
    $st->execute([(int) $_GET['run']]);
    $run = $st->fetch() ?: null;
}
$history = $conn->query("SELECT * FROM notices ORDER BY id DESC LIMIT 15")->fetchAll();
$failedRows = [];
if ($run) {
    $f = $conn->prepare("SELECT name, email, error FROM notice_recipients WHERE notice_id = ? AND status = 'failed' ORDER BY id LIMIT 50");
    $f->execute([$run['id']]);
    $failedRows = $f->fetchAll();
}
$counts = ['all' => [count($reachable), $emailsOf($reachable)]];
foreach (LEVEL_NAMES as $k => $v) {
    $inGroup = array_filter($reachable, fn($m) => $m['position'] === $k);
    $counts[$k] = [count($inGroup), $emailsOf($inGroup)];
}
$sentToday = notices_sent_today($conn, $acct['key']);
$fmtCount = fn($c) => $c[0] . ' member' . ($c[0] === 1 ? '' : 's') . ' · ' . $c[1] . ' e-mail' . ($c[1] === 1 ? '' : 's');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Notices | BUCuC Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/all.min.css" />
    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <style>
        .wrapx { padding: 2rem 1rem; color: var(--ink, rgb(var(--c-fixed-ffffff, 255 255 255))); max-width: 1000px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .back-btn { text-decoration: none; color: inherit; padding: .5rem 1rem; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3); border-radius: 999px; }
        .panel { background: rgb(var(--c-surface, 14 9 36) / 0.72); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); border-radius: 18px; padding: 1.5rem; margin-bottom: 1.25rem; }
        .form-control, .form-select { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.25); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-control:focus, .form-select:focus { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.35); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-select option { color: rgb(var(--c-fixed-000000, 0 0 0)); }
        .form-label, .note { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .85rem; }
        .aud { display: flex; gap: .5rem; flex-wrap: wrap; }
        .aud label { padding: .5rem 1rem; border-radius: 999px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); cursor: pointer; font-weight: 600; font-size: .9rem; }
        .aud input { display: none; }
        .aud input:checked + span { color: rgb(var(--c-accent2, 92 255 176)); }
        .aud label:has(input:checked) { border-color: rgb(var(--c-accent2, 92 255 176)); background: rgb(var(--c-accent2, 92 255 176) / 0.1); }
        .picklist { max-height: 280px; overflow: auto; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.12); border-radius: 12px; padding: .5rem; }
        .picklist label { display: flex; gap: .6rem; align-items: center; padding: .35rem .5rem; border-radius: 8px; font-size: .9rem; }
        .picklist label:hover { background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05); }
        .picklist small { color: var(--mute, rgb(var(--c-muted, 170 163 210))); margin-left: auto; }
        .sender { padding: .8rem 1rem; border-radius: 12px; background: rgb(var(--c-accent, 45 226 230) / 0.1); border: 1px solid rgb(var(--c-accent, 45 226 230) / 0.35); }
        .sender.bad { background: rgb(var(--c-danger-ff4f7b, 255 79 123) / 0.12); border-color: rgb(var(--c-danger-ff4f7b, 255 79 123) / 0.5); color: rgb(var(--c-danger, 255 147 180)); }
        .ph code { background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); color: rgb(var(--c-highlight, 255 207 125)); padding: .1rem .4rem; border-radius: 6px; cursor: pointer; }
        .phbtn, .linkbtn { background: none; border: 0; padding: .35rem .1rem; min-height: 32px; color: inherit; font: inherit; cursor: pointer; }
        .linkbtn { text-decoration: underline; }
        .progress { height: 14px; background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); border-radius: 999px; }
        .progress-bar { background: linear-gradient(90deg, rgb(var(--c-accent2, 92 255 176)), rgb(var(--c-accent, 45 226 230)), rgb(var(--c-primary, 139 92 255))); }
        .nums { display: grid; grid-template-columns: repeat(4, 1fr); gap: .75rem; margin: 1rem 0; text-align: center; }
        .nums b { display: block; font: 800 1.6rem/1 'Bricolage Grotesque', sans-serif; }
        .nums .bad b { color: rgb(var(--c-danger, 255 147 180)); }
        .hist { width: 100%; font-size: .88rem; border-collapse: collapse; }
        .hist td, .hist th { padding: .55rem .4rem; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); vertical-align: top; }
        .hist th { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .75rem; text-transform: uppercase; letter-spacing: .08em; }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table-scroll .hist { min-width: 640px; }
        @media (max-width: 600px) { .nums { grid-template-columns: repeat(2, 1fr); } }
    </style>
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="container wrapx">
        <div class="page-header">
            <h2 class="m-0"><i class="fas fa-envelope-open-text me-2"></i>Email Notices</h2>
            <a href="<?= auth_dashboard_page() ?>" class="back-btn"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if ($run): ?>
            <!-- Sending progress -->
            <div class="panel" id="runPanel" data-id="<?= (int) $run['id'] ?>" data-status="<?= htmlspecialchars($run['status']) ?>">
                <h4 class="mb-1">"<?= htmlspecialchars($run['subject']) ?>"</h4>
                <p class="note mb-2">To <?= htmlspecialchars($run['audience']) ?> (<?= (int) $run['members'] ?> members) · from <b><?= htmlspecialchars($run['sender_email'] ?: MAIL_ACCOUNTS['club']['email']) ?></b> · started by <?= htmlspecialchars(admin_shown_name($run['sent_by_name'], $run['sent_by_role'] ?? null, $run['sent_by'])) ?> · <?= date('j M Y, g:i a', strtotime($run['created_at'])) ?></p>
                <div class="progress"><div class="progress-bar" id="bar" style="width:0%"></div></div>
                <div class="nums">
                    <div><b id="nTotal"><?= (int) $run['total'] ?></b><span class="note">E-mails</span></div>
                    <div><b id="nSent"><?= (int) $run['sent'] ?></b><span class="note">Sent</span></div>
                    <div class="bad"><b id="nFailed"><?= (int) $run['failed'] ?></b><span class="note">Failed</span></div>
                    <div><b id="nPending"><?= (int) $run['total'] - (int) $run['sent'] - (int) $run['failed'] ?></b><span class="note">Waiting</span></div>
                </div>
                <p id="runMsg" class="mb-2"></p>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-success btn-sm d-none" id="resumeBtn"><i class="fas fa-play me-1"></i>Resume sending</button>
                    <?php if ($failedRows): ?>
                        <form method="POST" action="notices.php"><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int) $run['id'] ?>">
                            <button type="submit" class="btn btn-outline-light btn-sm"><i class="fas fa-redo me-1"></i>Retry the failed ones</button></form>
                    <?php endif; ?>
                    <a href="notices.php" class="btn btn-secondary btn-sm">New notice</a>
                </div>
                <?php if ($failedRows): ?>
                    <details class="mt-3"><summary class="note">Failed addresses (<?= count($failedRows) ?>)</summary>
                        <table class="hist mt-2"><?php foreach ($failedRows as $fr): ?><tr><td><?= htmlspecialchars($fr['name']) ?></td><td><?= htmlspecialchars($fr['email']) ?></td><td class="note"><?= htmlspecialchars($fr['error']) ?></td></tr><?php endforeach; ?></table>
                    </details>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Compose -->
            <form method="POST" action="notices.php" class="panel" id="composeForm">
                <input type="hidden" name="action" value="create">
                <h4 class="mb-3">New notice</h4>
                <div class="sender mb-3<?= notice_account_ready($acct) ? '' : ' bad' ?>">
                    <i class="fas fa-paper-plane me-2"></i>Will be sent from <b><?= htmlspecialchars($acct['email']) ?></b>
                    <span class="note">· limit <?= number_format($acct['daily_limit']) ?> e-mails a day · <?= number_format($sentToday) ?> sent from this address in the last 24 h</span>
                    <?php if (!notice_account_ready($acct)): ?><div class="mt-1">This address has no App Password set up yet, so sending is blocked.</div><?php endif; ?>
                </div>
                <label class="form-label">Send to</label>
                <div class="aud mb-2">
                    <label><input type="radio" name="audience" value="all" checked><span>All members (<?= $fmtCount($counts['all']) ?>)</span></label>
                    <label><input type="radio" name="audience" value="group"><span>A group</span></label>
                    <label><input type="radio" name="audience" value="pick"><span>Pick members</span></label>
                </div>
                <div id="groupBox" class="mb-3 d-none" style="max-width:320px">
                    <select name="group" class="form-select">
                        <?php foreach (LEVEL_NAMES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?> (<?= $fmtCount($counts[$k]) ?>)</option><?php endforeach; ?>
                    </select>
                </div>
                <div id="pickBox" class="mb-3 d-none">
                    <input type="search" id="pickFilter" class="form-control mb-2" placeholder="Search name or student ID">
                    <div class="picklist">
                        <?php foreach ($reachable as $m): ?>
                            <label data-q="<?= htmlspecialchars(strtolower($m['full_name'] . ' ' . $m['university_id'])) ?>">
                                <input type="checkbox" name="member_ids[]" value="<?= (int) $m['id'] ?>">
                                <?= htmlspecialchars($m['full_name']) ?> <small><?= htmlspecialchars($m['university_id']) ?> · <?= LEVEL_NAMES[$m['position']] ?? '' ?> · <?= count($m['to']) ?> e-mail<?= count($m['to']) === 1 ? '' : 's' ?></small>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$reachable): ?><p class="note m-2">No members with an e-mail address yet. Members are added in Admin → Members.</p><?php endif; ?>
                    </div>
                    <span class="note" id="pickCount">0 selected</span>
                </div>
                <p class="note">Each member gets the notice at their personal e-mail and their G-Suite address (or the one they have).<?php if (count($members) > count($reachable)): ?> <?= count($members) - count($reachable) ?> member(s) have neither and are skipped.<?php endif; ?></p>

                <div class="mb-3"><label class="form-label" for="subject">Subject</label>
                    <input type="text" id="subject" name="subject" class="form-control" required maxlength="200" value="<?= htmlspecialchars($_POST['subject'] ?? NOTICE_DEFAULT_SUBJECT) ?>"></div>
                <div class="mb-2"><label class="form-label" for="body">Message</label>
                    <textarea id="body" name="body" class="form-control" rows="11" required><?= htmlspecialchars($_POST['body'] ?? NOTICE_DEFAULT_BODY) ?></textarea></div>
                <p class="note ph">Placeholders (click to insert): <?php foreach (NOTICE_PLACEHOLDERS as $ph => $desc): ?><button type="button" class="phbtn" title="<?= $desc ?>"><code><?= $ph ?></code></button> <?php endforeach; ?>
                    · <button type="button" id="resetTpl" class="linkbtn note">Restore the default template</button></p>
                <p class="note">Sent in batches of <?= NOTICE_BATCH_SIZE ?>; the daily limit counts e-mails, not members.</p>
                <button type="submit" class="btn btn-success" <?= notice_account_ready($acct) ? '' : 'disabled' ?>><i class="fas fa-paper-plane me-2"></i>Send notice</button>
            </form>
        <?php endif; ?>

        <?php if ($history): ?>
            <div class="panel">
                <h4 class="mb-3">Sent notices</h4>
                <div class="table-scroll">
                <table class="hist">
                    <tr><th>When</th><th>Subject</th><th>To</th><th>E-mails sent / failed</th><th>From</th><th>By</th></tr>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td class="note"><?= date('j M Y, g:i a', strtotime($h['created_at'])) ?></td>
                            <td><a href="notices.php?run=<?= (int) $h['id'] ?>" style="color:inherit"><?= htmlspecialchars($h['subject']) ?></a></td>
                            <td><?= htmlspecialchars($h['audience']) ?></td>
                            <td><?= (int) $h['sent'] ?> / <span style="color:<?= $h['failed'] ? 'rgb(var(--c-danger, 255 147 180))' : 'inherit' ?>"><?= (int) $h['failed'] ?></span><?= $h['status'] !== 'done' ? ' <span class="note">(not finished)</span>' : '' ?></td>
                            <td class="note"><?= htmlspecialchars($h['sender_email'] ?? '') ?></td>
                            <td class="note"><?= htmlspecialchars(admin_shown_name($h['sent_by_name'], $h['sent_by_role'] ?? null, $h['sent_by'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                </div>
            </div>
        <?php elseif (!$run): ?>
            <div class="panel"><h4 class="mb-1">Sent notices</h4><p class="note mb-0">No notices sent yet. Notices you send appear here with how many e-mails went out.</p></div>
        <?php endif; ?>
    </div>

    <script src="js/admin-toast.js"></script>
    <script type="application/json" id="notices-config-data"><?= json_encode([
        'defaultSubject' => NOTICE_DEFAULT_SUBJECT,
        'defaultBody' => NOTICE_DEFAULT_BODY,
        'confirmMsg' => 'Send this notice now from ' . ($acct['email'] ?? '') . '?',
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <script src="js/notices-admin.js?v=<?= filemtime(__DIR__ . '/js/notices-admin.js') ?>"></script>
</body>

</html>
