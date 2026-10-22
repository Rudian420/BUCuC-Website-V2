<?php
/**
 * QR check-in screen for one session (put it on a projector or a laptop facing the room).
 * The QR code changes every ATT_QR_SECONDS; members scan it, then type their student ID on
 * checkin.php. Names appear here live; a wrong check-in can be removed. Permission take_attendance.
 */
define('NO_ADMIN_MENU', true);   // projector screen: only the QR code
require_once '../backend/includes/auth.php';
$isJson = isset($_GET['action']) || in_array($_POST['action'] ?? '', ['remove'], true);
require_admin(['can' => 'take_attendance', 'json' => $isJson, 'csrf_redirect' => 'attendance.php']);
require_once '../backend/includes/attendance.php';

$conn = db();
attendance_ensure($conn);
$s = att_session($conn, $_GET['id'] ?? ($_POST['id'] ?? 0));
if (!$s || !$s['qr_enabled']) {
    if ($isJson) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'QR check-in is not turned on for this session.']); exit(); }
    header('Location: attendance.php?success=' . urlencode($s ? 'QR check-in is not turned on for this session. Edit the session to allow it.' : 'That session no longer exists.'));
    exit();
}
$id = (int) $s['id'];
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
const CHECKIN_MINUTES = [10, 15, 20, 30, 45, 60, 90, 120];

function checkins(PDO $pdo, $sessionId)
{
    $st = $pdo->prepare("SELECT m.id, m.full_name, m.university_id, r.status, r.marked_at FROM attendance_records r JOIN members m ON m.id = r.member_id
                         WHERE r.session_id = ? AND r.method = 'qr' ORDER BY r.marked_at DESC");
    $st->execute([(int) $sessionId]);
    return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['full_name'], 'studentId' => $r['university_id'], 'status' => ATT_STATUSES[$r['status']] ?? $r['status'], 'time' => date('g:i:s a', strtotime($r['marked_at']))], $st->fetchAll());
}

// ---------------------------------------------------------------- live state (polled by the screen)
if (($_GET['action'] ?? '') === 'state') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $open = att_checkin_open($s);
    $window = intdiv(time(), ATT_QR_SECONDS);
    $exp = $conn->prepare("SELECT COUNT(*) FROM attendance_expected WHERE session_id = ?");
    $exp->execute([$id]);
    echo json_encode(['success' => true, 'open' => $open, 'closesAt' => $open ? strtotime($s['checkin_closes_at']) * 1000 : null,
        'token' => $open ? att_qr_token($s, $window) : null, 'nextChange' => (($window + 1) * ATT_QR_SECONDS) * 1000, 'serverNow' => (int) round(microtime(true) * 1000),
        'expected' => (int) $exp->fetchColumn(), 'checkins' => checkins($conn, $id), 'ended' => att_ended($s)]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'start') {
        $min = (int) ($_POST['minutes'] ?? 30);
        if (!in_array($min, CHECKIN_MINUTES, true)) $min = 30;
        // The secret stays the same for the session, so a phone that already checked someone in stays blocked after a reopen
        $secret = $s['checkin_secret'] ?: bin2hex(random_bytes(32));
        $conn->prepare("UPDATE attendance_sessions SET checkin_secret = ?, checkin_opened_at = NOW(), checkin_closes_at = NOW() + INTERVAL ? MINUTE WHERE id = ?")->execute([$secret, $min, $id]);
        log_activity('attendance_checkin_opened', "QR check-in open for $min minutes", null, null, $s['title'] . ' (' . date('j M Y', att_start_ts($s)) . ')');
        header("Location: attendance_checkin.php?id=$id");
        exit();
    }
    if ($action === 'end') {
        $conn->prepare("UPDATE attendance_sessions SET checkin_closes_at = NOW() WHERE id = ? AND checkin_closes_at > NOW()")->execute([$id]);
        $n = count(checkins($conn, $id));
        log_activity('attendance_checkin_closed', "QR check-in ended by hand; $n check-in(s)", null, null, $s['title'] . ' (' . date('j M Y', att_start_ts($s)) . ')');
        header("Location: attendance_session.php?id=$id&success=" . urlencode("Check-in ended. $n member(s) checked in."));
        exit();
    }
    if ($action === 'remove') {
        header('Content-Type: application/json');
        $mid = (int) ($_POST['member_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (att_ended($s) && mb_strlen($reason) < 5) { echo json_encode(['success' => false, 'error' => 'This session has ended: a short reason (at least 5 characters) is needed.']); exit(); }
        $st = $conn->prepare("SELECT m.full_name, m.university_id, r.status FROM attendance_records r JOIN members m ON m.id = r.member_id WHERE r.session_id = ? AND r.member_id = ? AND r.method = 'qr'");
        $st->execute([$id, $mid]);
        $who = $st->fetch();
        if (!$who) { echo json_encode(['success' => false, 'error' => 'That check-in is already gone.']); exit(); }
        $conn->prepare("DELETE FROM attendance_records WHERE session_id = ? AND member_id = ? AND method = 'qr'")->execute([$id, $mid]);
        log_activity('attendance_checkin_removed', "Removed {$who['full_name']} ({$who['university_id']}), was " . (ATT_STATUSES[$who['status']] ?? $who['status']) . ($reason !== '' ? ". Reason: $reason" : ''), null, null, $s['title'] . ' (' . date('j M Y', att_start_ts($s)) . ')');
        echo json_encode(['success' => true]);
        exit();
    }
}
$open = att_checkin_open($s);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-in: <?= $h($s['title']) ?> | BUCuC Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/all.min.css">
    <link rel="stylesheet" href="css/attendance.css?v=<?= filemtime(__DIR__ . '/css/attendance.css') ?>">
    <style>
        .ck-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
        @media (max-width: 900px) { .ck-grid { grid-template-columns: 1fr; } }
        .qr-box { background: rgb(var(--c-fixed-ffffff, 255 255 255)); border-radius: 22px; padding: 18px; width: min(100%, 460px); aspect-ratio: 1; margin: 0 auto; display: grid; place-items: center; position: relative; }
        .qr-box svg { width: 100%; height: 100%; display: block; }
        .qr-box.closed { background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); color: var(--mute, rgb(var(--c-muted, 170 163 210))); text-align: center; padding: 2rem; }
        .ticker { display: flex; justify-content: center; align-items: center; gap: .6rem; margin-top: 1rem; font-size: .95rem; }
        .bar { height: 6px; border-radius: 999px; background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.12); overflow: hidden; width: min(100%, 460px); margin: .6rem auto 0; }
        .bar i { display: block; height: 100%; width: 100%; background: rgb(var(--c-accent2, 92 255 176)); transform-origin: left; }
        .big-count { font-size: 2.6rem; font-weight: 800; line-height: 1; }
        .live { list-style: none; padding: 0; margin: 0; max-height: 60vh; overflow: auto; }
        .live li { display: flex; gap: .7rem; align-items: center; padding: .55rem .25rem; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.07); animation: pop .4s ease; }
        .live li .who { flex: 1; min-width: 0; }
        .live li small { color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        @keyframes pop { from { background: rgb(var(--c-accent2, 92 255 176) / 0.25); } to { background: transparent; } }
        .howto { text-align: center; font-size: 1.05rem; margin-top: .8rem; }
    </style>
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="wrapx">
        <div class="page-header">
            <div>
                <h2 class="m-0"><i class="fas fa-qrcode me-2"></i>Check-in: <?= $h($s['title']) ?></h2>
                <div class="note mt-1"><?= $h(att_when($s)) ?> · <?= ATT_AUDIENCES[$s['audience']] ?? '' ?> · check-ins after <?= ATT_LATE_MINUTES ?> minutes past the start are marked Late</div>
            </div>
            <div class="links"><a href="attendance_session.php?id=<?= $id ?>" class="back-btn"><i class="fas fa-arrow-left"></i>Session</a></div>
        </div>

        <div class="ck-grid">
            <div class="panel">
                <div class="qr-box<?= $open ? '' : ' closed' ?>" id="qr" aria-live="polite">
                    <?php if (!$open): ?><div><i class="fas fa-lock fa-2x mb-2"></i><br>Check-in is closed.</div><?php endif; ?>
                </div>
                <?php if ($open): ?>
                    <div class="bar" aria-hidden="true"><i id="bar"></i></div>
                    <div class="ticker"><span class="note" id="tick">New code in 30 s</span></div>
                    <p class="howto">Scan with your phone camera, then type your student ID.</p>
                    <div class="d-flex justify-content-center gap-2 flex-wrap mt-2">
                        <span class="chip now" id="closesChip">Open until <?= date('g:i a', strtotime($s['checkin_closes_at'])) ?></span>
                        <form method="POST" action="attendance_checkin.php?id=<?= $id ?>" class="m-0" onsubmit="return confirm('End check-in now? Members can no longer check in.')">
                            <input type="hidden" name="action" value="end"><input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-stop me-1"></i>End check-in</button>
                        </form>
                    </div>
                <?php else: ?>
                    <form method="POST" action="attendance_checkin.php?id=<?= $id ?>" class="d-flex justify-content-center gap-2 flex-wrap align-items-center mt-3">
                        <input type="hidden" name="action" value="start"><input type="hidden" name="id" value="<?= $id ?>">
                        <label class="note" for="minutes">Keep open for</label>
                        <select class="form-select w-auto" id="minutes" name="minutes"><?php foreach (CHECKIN_MINUTES as $m): ?><option value="<?= $m ?>" <?= $m === 30 ? 'selected' : '' ?>><?= $m ?> minutes</option><?php endforeach; ?></select>
                        <button type="submit" class="btn btn-success"><i class="fas fa-play me-1"></i>Start check-in</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="panel">
                <div class="d-flex align-items-end gap-2 mb-2"><span class="big-count" id="count">0</span><span class="note mb-1">of <span id="expected">…</span> expected checked in</span></div>
                <ul class="live" id="live"><li class="note">Nobody has checked in yet.</li></ul>
            </div>
        </div>
    </div>

    <script src="vendor/qrcode-generator/qrcode.js"></script>
    <script src="js/admin-toast.js"></script>
    <script>
        (function () {
            const ID = <?= $id ?>;
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            let token = null, nextChange = 0, skew = 0, open = <?= $open ? 'true' : 'false' ?>, ended = false, shown = '';
            function drawQr(t) {
                const url = new URL(`checkin.php?s=${ID}&t=${encodeURIComponent(t)}`, location.href).href;
                const qr = qrcode(0, 'M');
                qr.addData(url);
                qr.make();
                const box = document.getElementById('qr');
                box.innerHTML = qr.createSvgTag({ cellSize: 8, margin: 2, scalable: true });
                box.dataset.url = url;
            }
            function renderList(list, expected) {
                document.getElementById('count').textContent = list.length;
                document.getElementById('expected').textContent = expected;
                const key = list.map(c => c.id).join(',');
                if (key === shown) return;
                shown = key;
                document.getElementById('live').innerHTML = list.length ? list.map(c => `<li data-id="${c.id}"><div class="who"><b>${esc(c.name)}</b><br><small>${esc(c.studentId)} · ${esc(c.time)}</small></div>
                    <span class="chip ${c.status === 'Late' ? 'late' : 'present'}">${esc(c.status)}</span>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-remove="${c.id}" aria-label="Remove ${esc(c.name)}"><i class="fas fa-times"></i></button></li>`).join('')
                    : '<li class="note">Nobody has checked in yet.</li>';
            }
            async function poll() {
                try {
                    const d = await (await fetch(`attendance_checkin.php?id=${ID}&action=state`, { cache: 'no-store' })).json();
                    if (!d.success) return;
                    skew = d.serverNow - Date.now();
                    ended = d.ended;
                    if (open && !d.open) { location.reload(); return; }   // closed (time up or ended elsewhere)
                    if (d.open && d.token !== token) { token = d.token; drawQr(token); }
                    nextChange = d.nextChange;
                    renderList(d.checkins, d.expected);
                } catch (e) { /* try again on the next tick */ }
            }
            // progress bar and countdown to the next code; fetch the new code right when it changes
            function tick() {
                if (open && nextChange) {
                    const left = Math.max(0, nextChange - (Date.now() + skew));
                    document.getElementById('tick').textContent = `New code in ${Math.ceil(left / 1000)} s`;
                    document.getElementById('bar').style.transform = `scaleX(${left / <?= ATT_QR_SECONDS * 1000 ?>})`;
                    if (left <= 0) { nextChange = 0; poll(); }
                }
                requestAnimationFrame(tick);
            }
            document.getElementById('live').addEventListener('click', async e => {
                const b = e.target.closest('[data-remove]');
                if (!b) return;
                const name = b.closest('li').querySelector('b').textContent;
                let reason = '';
                if (ended) {
                    reason = prompt(`Remove ${name}'s check-in? The session has ended, so write a short reason:`) || '';
                    if (reason.trim().length < 5) return;
                } else if (!confirm(`Remove ${name}'s check-in?`)) return;
                const fd = new FormData();
                fd.append('action', 'remove'); fd.append('id', ID); fd.append('member_id', b.dataset.remove); fd.append('reason', reason); fd.append('csrf_token', csrf);
                let d;
                try { d = await (await fetch('attendance_checkin.php', { method: 'POST', body: fd })).json(); }
                catch (err) { showNotification('Could not reach the server, so the check-in was not removed. Check your connection and try again.', 'error'); return; }
                if (!d.success) showNotification(d.error || 'Could not remove the check-in. Reload the page and try again.', 'error');
                else showNotification(`${name}'s check-in was removed.`, 'success');
                shown = ''; poll();
            });
            poll();
            setInterval(poll, 2500);
            requestAnimationFrame(tick);
        })();
    </script>
</body>

</html>
