<?php
/**
 * QR self check-in (members, no login). Opened by scanning the code on the admin's check-in screen
 * (attendance_checkin.php). The code in the link is valid for about 90 seconds; after that the
 * member has ATT_FORM_MINUTES to type their student ID. Each student ID checks in once per
 * session, each phone checks in one person per session, and wrong IDs are limited per network.
 * Not closed by a site lockdown (it is an attendance tool, not part of the public website).
 */
require_once __DIR__ . '/../backend/includes/auth.php';
require_once __DIR__ . '/../backend/includes/attendance.php';

$conn = db();
attendance_ensure($conn);
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$s = att_session($conn, $_GET['s'] ?? ($_POST['s'] ?? 0));
$state = 'form';     // form | done | error
$msg = '';
$ticket = '';
$studentId = '';

if (!$s || !$s['qr_enabled']) {
    $state = 'error';
    $msg = 'This check-in link is not valid.';
} elseif (!att_checkin_open($s)) {
    $state = 'error';
    $msg = 'Check-in for this session is closed.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket = (string) ($_POST['ticket'] ?? '');
    $studentId = preg_replace('/\s+/', '', (string) ($_POST['student_id'] ?? ''));
    $cookie = att_device_cookie($s);
    if (!att_ticket_valid($s, $ticket)) {
        $state = 'error';
        $msg = 'This page was open too long. Scan the code on the screen again.';
    } elseif (hash_equals($cookie['value'], (string) ($_COOKIE[$cookie['name']] ?? ''))) {
        $state = 'error';
        $msg = 'This phone has already been used to check in for this session. Each member checks in with their own phone.';
    } elseif (att_fail_count($conn) >= ATT_FAILS_PER_IP) {
        $msg = 'Too many wrong student IDs from this network. Wait a few minutes, or ask the admin to mark you.';
    } elseif (!preg_match('/^[A-Za-z0-9-]{4,20}$/', $studentId)) {
        $msg = 'Enter your student ID (numbers only, as on your ID card).';
    } else {
        $st = $conn->prepare("SELECT m.id, m.full_name, r.status FROM members m
            JOIN attendance_expected e ON e.member_id = m.id AND e.session_id = ?
            LEFT JOIN attendance_records r ON r.session_id = e.session_id AND r.member_id = m.id
            WHERE m.university_id = ?");
        $st->execute([$s['id'], $studentId]);
        $m = $st->fetch();
        if (!$m) {
            att_fail_record($conn);
            $msg = "That student ID isn't on the list for this session. Check it and try again, or ask the admin.";
        } elseif ($m['status'] !== null) {
            $state = 'done';
            $msg = 'You are already marked for this session (' . (ATT_STATUSES[$m['status']] ?? $m['status']) . ').';
        } else {
            $status = time() > att_start_ts($s) + ATT_LATE_MINUTES * 60 ? 'late' : 'present';
            // INSERT IGNORE: if two phones try the same ID at the same moment, only the first counts
            $ins = $conn->prepare("INSERT IGNORE INTO attendance_records (session_id, member_id, status, method, marked_at) VALUES (?, ?, ?, 'qr', NOW())");
            $ins->execute([$s['id'], $m['id'], $status]);
            if ($ins->rowCount() === 0) {
                $state = 'done';
                $msg = 'You are already marked for this session.';
            } else {
                setcookie($cookie['name'], $cookie['value'], ['expires' => strtotime($s['checkin_closes_at']) + 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
                $first = trim(explode(' ', trim($m['full_name']))[0] ?? '');
                $state = 'done';
                $msg = "Checked in, $first! You're marked " . ($status === 'late' ? 'Late' : 'Present') . '.';
            }
        }
    }
} else {
    if (!att_qr_valid($s, (string) ($_GET['t'] ?? ''))) {
        $state = 'error';
        $msg = 'This code has expired. Scan the code on the screen again (it changes every ' . ATT_QR_SECONDS . ' seconds).';
    } else {
        $ticket = att_ticket($s);
    }
}
if ($state === 'form' && $ticket === '' && $s) $ticket = (string) ($_POST['ticket'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>Check In | BUCuC</title>
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px 16px; background: rgb(var(--c-bg, 6 4 15)); color: rgb(var(--c-text, 243 240 255)); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        .card { width: 100%; max-width: 420px; background: rgb(var(--c-surface, 14 9 36)); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / .14); border-radius: 22px; padding: 26px 22px; text-align: center; }
        .logo { width: 64px; height: 64px; object-fit: contain; background: rgb(var(--c-fixed-ffffff, 255 255 255)); border-radius: 16px; padding: 6px; }
        h1 { font-size: 1.35rem; margin: 14px 0 4px; }
        .sub { color: rgb(var(--c-muted, 170 163 210)); font-size: .92rem; margin: 0 0 18px; }
        label { display: block; text-align: left; font-size: .9rem; color: rgb(var(--c-muted, 170 163 210)); margin-bottom: 6px; }
        input[type=text] { width: 100%; padding: 14px 16px; border-radius: 14px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / .3); background: rgb(var(--c-surface-09061c, 9 6 28)); color: rgb(var(--c-text, 243 240 255)); font-size: 1.35rem; letter-spacing: .06em; text-align: center; }
        input[type=text]:focus { outline: 2px solid rgb(var(--c-accent, 45 226 230)); outline-offset: 2px; }
        button { width: 100%; margin-top: 14px; padding: 14px; border: 0; border-radius: 999px; background: rgb(var(--c-accent2, 92 255 176)); color: rgb(var(--c-bg, 6 4 15)); font-size: 1.05rem; font-weight: 700; cursor: pointer; }
        .msg { border-radius: 14px; padding: 12px 14px; margin-bottom: 14px; font-size: .95rem; }
        .msg.bad { background: rgb(var(--c-danger, 255 147 180) / .14); border: 1px solid rgb(var(--c-danger, 255 147 180) / .5); color: rgb(var(--c-danger, 255 147 180)); }
        .done-icon { font-size: 54px; line-height: 1; color: rgb(var(--c-accent2, 92 255 176)); }
        .big { font-size: 1.2rem; font-weight: 700; margin: 10px 0 4px; }
    </style>
</head>

<body>
    <main class="card">
        <picture><source srcset="images/logopng.webp" type="image/webp"><img src="images/logopng.png" alt="BUCuC logo" class="logo"></picture>
        <?php if ($s && $s['qr_enabled']): ?>
            <h1><?= $h($s['title']) ?></h1>
            <p class="sub"><?= $h(att_when($s)) ?></p>
        <?php else: ?>
            <h1>BUCuC check-in</h1>
        <?php endif; ?>

        <?php if ($state === 'done'): ?>
            <div class="done-icon" aria-hidden="true">✓</div>
            <p class="big" role="status"><?= $h($msg) ?></p>
            <p class="sub">You can close this page.</p>
        <?php elseif ($state === 'error'): ?>
            <div class="msg bad" role="alert"><?= $h($msg) ?></div>
        <?php else: ?>
            <?php if ($msg): ?><div class="msg bad" role="alert"><?= $h($msg) ?></div><?php endif; ?>
            <form method="POST" action="checkin.php" autocomplete="off">
                <input type="hidden" name="s" value="<?= (int) $s['id'] ?>">
                <input type="hidden" name="ticket" value="<?= $h($ticket) ?>">
                <label for="student_id">Your student ID</label>
                <input type="text" id="student_id" name="student_id" inputmode="numeric" autocomplete="off" required maxlength="20" placeholder="e.g. 22101234" value="<?= $h($studentId) ?>" autofocus>
                <button type="submit">Check in</button>
            </form>
            <p class="sub" style="margin:14px 0 0">Within <?= ATT_FORM_MINUTES ?> minutes of scanning. Check in only yourself.</p>
        <?php endif; ?>
    </main>
</body>

</html>
