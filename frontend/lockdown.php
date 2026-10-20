<?php
require_once '../backend/includes/auth.php';

// Super Admin only: schedule, cancel or lift a lockdown.
// While locked, the PUBLIC site shows a maintenance page with the notice; the admin panel keeps working for everyone.
require_admin(['can' => 'lockdown', 'csrf_redirect' => 'lockdown.php']);

$conn = db();
site_ensure_schema($conn);
$myId = (int) $_SESSION['admin_id'];
$message = $_GET['success'] ?? '';
$error = '';
$current = lockdown_current();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'schedule') {
        $notice = trim($_POST['notice'] ?? '');
        $public = trim($_POST['public_message'] ?? '');
        $now = !empty($_POST['start_now']);
        $ts = $now ? time() : strtotime(str_replace('T', ' ', $_POST['starts_at'] ?? ''));
        if ($notice === '') {
            $error = 'Write the notice the admins will see.';
        } elseif (!$ts) {
            $error = 'Pick the date and time when the lockdown starts.';
        } elseif (!$now && $ts < time() - 60) {
            $error = 'That time is in the past. Pick a future time or tick "Lock right now".';
        } else {
            $conn->beginTransaction();
            // Only one lockdown at a time: a new one replaces any scheduled one
            $conn->prepare("UPDATE site_lockdown SET status = 'cancelled', ended_at = NOW(), ended_by = ? WHERE status = 'scheduled'")->execute([$myId]);
            $conn->prepare("INSERT INTO site_lockdown (starts_at, notice, public_message, created_by) VALUES (?, ?, ?, ?)")
                ->execute([date('Y-m-d H:i:s', $ts), $notice, $public !== '' ? $public : null, $myId]);
            $conn->commit();
            log_activity('lockdown_scheduled', ($now ? 'Locked immediately' : 'Starts ' . date('j M Y, g:i a', $ts)) . ' — ' . $notice, null, null, 'Whole website');
            header('Location: lockdown.php?success=' . urlencode($now ? 'The public website is now offline. The admin panel keeps working.' : 'Lockdown scheduled for ' . date('D j M Y, g:i a', $ts) . '. Every admin will see the notice.'));
            exit();
        }
    } elseif ($action === 'end' && $current) {
        $wasActive = $current['active'];
        $conn->prepare("UPDATE site_lockdown SET status = ?, ended_at = NOW(), ended_by = ? WHERE id = ?")
            ->execute([$wasActive ? 'lifted' : 'cancelled', $myId, $current['id']]);
        log_activity($wasActive ? 'lockdown_lifted' : 'lockdown_cancelled', $wasActive ? 'Website reopened' : 'Scheduled lockdown called off', null, null, 'Whole website');
        header('Location: lockdown.php?success=' . urlencode($wasActive ? 'Lockdown lifted. The public website is online again.' : 'The scheduled lockdown was cancelled.'));
        exit();
    }
}

$history = $conn->query("SELECT * FROM site_lockdown ORDER BY id DESC LIMIT 10")->fetchAll();
$defaultStart = date('Y-m-d\TH:i', time() + 3600);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Lockdown - BUCuC</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <style>
        .wrapx { padding: 2rem 1rem; color: var(--ink, rgb(var(--c-fixed-ffffff, 255 255 255))); max-width: 900px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .back-btn { text-decoration: none; color: inherit; padding: .5rem 1rem; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3); border-radius: 999px; }
        .panel { background: rgb(var(--c-surface, 14 9 36) / 0.72); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); border-radius: 18px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .form-control { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.25); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-control:focus { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.35); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-label { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .85rem; }
        .state { display: flex; gap: 1rem; align-items: center; }
        .state .dot { width: 14px; height: 14px; border-radius: 50%; flex: none; }
        .note { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .85rem; }
        .hist { width: 100%; font-size: .88rem; border-collapse: collapse; }
        .hist td, .hist th { padding: .5rem; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); vertical-align: top; }
        .hist th { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .75rem; text-transform: uppercase; letter-spacing: .08em; }
    </style>
    <link rel="icon" type="image/png" href="images/logopng.png">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="container wrapx">
        <div class="page-header">
            <h2 class="m-0"><i class="fas fa-lock me-2"></i>Site Lockdown</h2>
            <a href="<?= auth_dashboard_page() ?>" class="back-btn"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>

        <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="panel">
            <?php if (!$current): ?>
                <div class="state"><span class="dot" style="background:rgb(var(--c-accent2, 92 255 176))"></span>
                    <div><b>The public website is online.</b><div class="note">No lockdown is scheduled.</div></div>
                </div>
            <?php else: ?>
                <div class="state"><span class="dot" style="background:<?= $current['active'] ? 'rgb(var(--c-danger-ff4f7b, 255 79 123))' : 'rgb(var(--c-highlight, 255 207 125))' ?>"></span>
                    <div>
                        <b><?= $current['active'] ? 'The public website is OFFLINE' : 'Lockdown scheduled' ?></b>
                        <div class="note"><?= $current['active'] ? 'Since' : 'Starts' ?> <?= date('D j M Y, g:i a', $current['starts_ts']) ?></div>
                        <div class="mt-2"><?= nl2br(htmlspecialchars($current['notice'])) ?></div>
                    </div>
                </div>
                <form method="POST" action="lockdown.php" class="mt-3" onsubmit="return confirm(<?= htmlspecialchars(json_encode($current['active'] ? 'Lift the lockdown and reopen the website?' : 'Cancel the scheduled lockdown?'), ENT_QUOTES) ?>)">
                    <input type="hidden" name="action" value="end">
                    <button type="submit" class="btn <?= $current['active'] ? 'btn-success' : 'btn-warning' ?>">
                        <i class="fas <?= $current['active'] ? 'fa-lock-open' : 'fa-ban' ?> me-2"></i><?= $current['active'] ? 'Lift lockdown now' : 'Cancel scheduled lockdown' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h4 class="mb-3"><i class="fas fa-calendar-plus me-2"></i><?= $current ? 'Replace with a new lockdown' : 'Schedule a lockdown' ?></h4>
            <p class="note">From the start time the public website shows a maintenance page with your notice. The admin panel keeps working for every admin. Until then every admin sees the notice with a countdown on each page.</p>
            <form method="POST" action="lockdown.php">
                <input type="hidden" name="action" value="schedule">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="starts_at">Starts at (Dhaka time)</label>
                        <input type="datetime-local" id="starts_at" name="starts_at" class="form-control" value="<?= $defaultStart ?>">
                    </div>
                    <div class="col-md-6 mb-3 d-flex align-items-end">
                        <label class="form-check-label"><input type="checkbox" name="start_now" value="1" class="form-check-input me-2">Lock right now</label>
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="notice">Notice (shown on every admin page and on the maintenance page)</label>
                        <textarea id="notice" name="notice" class="form-control" rows="3" required maxlength="1000" placeholder="e.g. The website is down for maintenance until Friday evening."></textarea>
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="public_message">Different message for visitors (optional; blank = show the notice)</label>
                        <input type="text" id="public_message" name="public_message" class="form-control" maxlength="500">
                    </div>
                </div>
                <button type="submit" class="btn btn-danger"><i class="fas fa-lock me-2"></i>Schedule lockdown</button>
            </form>
        </div>

        <?php if ($history): ?>
            <div class="panel">
                <h4 class="mb-3"><i class="fas fa-history me-2"></i>Recent lockdowns</h4>
                <table class="hist">
                    <tr><th>Start</th><th>Status</th><th>Notice</th><th>Ended</th></tr>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td><?= date('j M Y, g:i a', strtotime($h['starts_at'])) ?></td>
                            <td><?= htmlspecialchars($h['status'] === 'scheduled' ? (strtotime($h['starts_at']) <= time() ? 'locked' : 'scheduled') : $h['status']) ?></td>
                            <td><?= htmlspecialchars(mb_strimwidth($h['notice'], 0, 120, '…')) ?></td>
                            <td><?= $h['ended_at'] ? date('j M Y, g:i a', strtotime($h['ended_at'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>
