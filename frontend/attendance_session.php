<?php
/**
 * One attendance session: totals and the checklist (Present / Late / Excused / Absent).
 * Permission take_attendance. After the session has ended, every change needs a short reason and
 * is written to the activity log with who changed what.
 */
require_once '../backend/includes/auth.php';
require_admin(['can' => 'take_attendance', 'csrf_redirect' => 'attendance.php']);
require_once '../backend/includes/attendance.php';

$conn = db();
attendance_ensure($conn);
$s = att_session($conn, $_GET['id'] ?? ($_POST['id'] ?? 0));
if (!$s) {
    header('Location: attendance.php?success=' . urlencode('That session no longer exists.'));
    exit();
}
$id = (int) $s['id'];
$message = $_GET['success'] ?? '';
$error = '';
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_marks') {
    // Only rows the admin actually changed are sent as changed (mark != orig), so check-ins that
    // arrived while the page was open are never overwritten.
    $marks = (array) ($_POST['mark'] ?? []);
    $orig = (array) ($_POST['orig'] ?? []);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $roster = [];
    foreach (att_roster($conn, $id) as $r) $roster[(int) $r['id']] = $r;
    $changes = [];
    foreach ($marks as $mid => $status) {
        $mid = (int) $mid;
        $status = (string) $status;
        if (!isset($roster[$mid]) || ($status !== '' && !isset(ATT_STATUSES[$status]))) continue;
        if ($status === (string) ($orig[$mid] ?? '')) continue;              // not touched on the page
        if ($status === (string) $roster[$mid]['status']) continue;          // already so
        $changes[$mid] = $status;
    }
    $ended = att_ended($s);
    if (!$changes) {
        $message = 'Nothing to save: no marks were changed.';
    } elseif ($ended && mb_strlen($reason) < 5) {
        $error = 'This session has ended. Write a short reason for the change (at least 5 characters); it goes in the activity log.';
    } else {
        try {
            $conn->beginTransaction();
            $set = $conn->prepare("INSERT INTO attendance_records (session_id, member_id, status, method, marked_by, marked_at) VALUES (?, ?, ?, 'manual', ?, NOW())
                                   ON DUPLICATE KEY UPDATE status = VALUES(status), method = 'manual', marked_by = VALUES(marked_by), marked_at = NOW()");
            $del = $conn->prepare("DELETE FROM attendance_records WHERE session_id = ? AND member_id = ?");
            $lines = [];
            foreach ($changes as $mid => $status) {
                if ($status === '') $del->execute([$id, $mid]);
                else $set->execute([$id, $mid, $status, $_SESSION['admin_id']]);
                $from = $roster[$mid]['status'] ? ATT_STATUSES[$roster[$mid]['status']] : 'not marked';
                $lines[] = $roster[$mid]['full_name'] . ' (' . $roster[$mid]['university_id'] . '): ' . $from . ' → ' . ($status === '' ? 'not marked' : ATT_STATUSES[$status]);
            }
            $conn->commit();
            if ($ended) {
                $shown = array_slice($lines, 0, 25);
                log_activity('attendance_changed', 'Reason: ' . mb_substr($reason, 0, 200) . '. ' . implode('; ', $shown) . (count($lines) > 25 ? '; and ' . (count($lines) - 25) . ' more' : ''), null, null, $s['title'] . ' (' . date('j M Y', att_start_ts($s)) . ')');
            }
            header('Location: attendance_session.php?id=' . $id . '&success=' . urlencode(count($changes) . ' mark' . (count($changes) === 1 ? '' : 's') . ' saved' . ($ended ? ' and logged.' : '.')));
            exit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('attendance_session.php: ' . $e->getMessage());
            $error = 'Saving failed because of a database problem. Nothing was changed; please try again.';
        }
    }
}

$roster = att_roster($conn, $id);
$ended = att_ended($s);
$t = att_totals($roster, $ended);
$posted = $error ? (array) ($_POST['mark'] ?? []) : [];   // keep the admin's choices after an error
$open = att_checkin_open($s);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($s['title']) ?> · Attendance | BUCuC Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/all.min.css">
    <link rel="stylesheet" href="css/attendance.css?v=<?= filemtime(__DIR__ . '/css/attendance.css') ?>">
    <style>
        .toolbar { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; margin-bottom: .75rem; }
        .toolbar .form-control { flex: 1 1 220px; }
        .fchips { display: flex; gap: .35rem; flex-wrap: wrap; }
        .fchips button { border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); background: transparent; color: inherit; border-radius: 999px; padding: .3rem .75rem; font-size: .82rem; }
        .fchips button[aria-pressed="true"] { background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); border-color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.4); }
        .mrow { display: flex; gap: .75rem; align-items: center; padding: .6rem .25rem; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.07); }
        .mrow.changed { background: rgb(var(--c-highlight, 255 207 125) / 0.07); border-radius: 10px; }
        .mrow .who { flex: 1; min-width: 0; }
        .mrow .who b { display: block; overflow-wrap: anywhere; }
        .mrow .who small { color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .seg { display: inline-flex; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.22); border-radius: 12px; overflow: hidden; flex: none; }
        .seg input { position: absolute; opacity: 0; pointer-events: none; }
        .wrapx .seg label { color: var(--ink, rgb(var(--c-text, 243 240 255))) !important; min-width: 44px; min-height: 40px; padding: .45rem .7rem; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: .85rem; font-weight: 600; border-left: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.12); user-select: none; }
        .seg label:first-of-type { border-left: 0; }
        .seg input:focus-visible + label { outline: 2px solid rgb(var(--c-fixed-ffffff, 255 255 255)); outline-offset: -2px; }
        .seg input[value=present]:checked + label { background: rgb(var(--c-accent2, 92 255 176)); color: rgb(var(--c-bg, 6 4 15)) !important; }
        .seg input[value=late]:checked + label { background: rgb(var(--c-highlight, 255 207 125)); color: rgb(var(--c-bg, 6 4 15)) !important; }
        .seg input[value=excused]:checked + label { background: rgb(var(--c-accent, 45 226 230)); color: rgb(var(--c-bg, 6 4 15)) !important; }
        .seg input[value=absent]:checked + label { background: rgb(var(--c-danger, 255 147 180)); color: rgb(var(--c-bg, 6 4 15)) !important; }
        .seg .short { display: none; }
        .savebar { position: sticky; bottom: 0; z-index: 20; margin-top: 1rem; padding: .8rem 1rem; border-radius: 16px; background: rgb(var(--c-surface-0c0822, 12 8 34) / 0.96); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.18); display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
        .savebar .grow { flex: 1 1 220px; }
        @media (max-width: 700px) {
            .mrow { flex-wrap: wrap; }
            .seg { width: 100%; }
            .seg label { flex: 1; }
            .seg .long { display: none; }
            .seg .short { display: inline; }
        }
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
                <h2 class="m-0"><?= $h($s['title']) ?></h2>
                <div class="note mt-1"><?= ATT_TYPES[$s['type']] ?? '' ?> · <?= $h(att_when($s)) ?> · <?= ATT_AUDIENCES[$s['audience']] ?? '' ?> · <?= att_semester($s['session_date']) ?>
                    <?php if ($ended): ?><span class="chip done">Ended</span><?php elseif (time() >= att_start_ts($s)): ?><span class="chip now">In progress</span><?php else: ?><span class="chip up">Upcoming</span><?php endif; ?>
                    <?php if ($open): ?><span class="chip now">Check-in open until <?= date('g:i a', strtotime($s['checkin_closes_at'])) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="links"><a href="attendance.php" class="back-btn"><i class="fas fa-arrow-left"></i>All sessions</a></div>
        </div>
        <?php if ($message): ?><div class="alert alert-success"><?= $h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>

        <div class="panel">
            <div class="totals mb-3">
                <div><b><?= $t['present'] ?></b><span>Present</span></div>
                <div><b><?= $t['late'] ?></b><span>Late</span></div>
                <div><b><?= $t['excused'] ?></b><span>Excused</span></div>
                <div><b><?= $t['absent'] ?></b><span>Absent<?= $ended && $t['unmarked'] ? ' (incl. ' . $t['unmarked'] . ' not marked)' : '' ?></span></div>
                <?php if (!$ended): ?><div><b><?= $t['unmarked'] ?></b><span>Not marked yet</span></div><?php endif; ?>
                <div><b><?= $t['expected'] ?></b><span>Expected</span></div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($s['qr_enabled']): ?><a href="attendance_checkin.php?id=<?= $id ?>" class="btn btn-success"><i class="fas fa-qrcode me-1"></i><?= $open ? 'Check-in screen (open)' : 'Open QR check-in' ?></a><?php endif; ?>
                <a href="attendance_export.php?session=<?= $id ?>" class="btn btn-outline-light"><i class="fas fa-file-excel me-1"></i>Excel</a>
                <a href="attendance.php?edit=<?= $id ?>#sessionForm" class="btn btn-outline-light"><i class="fas fa-pen me-1"></i>Edit session</a>
                <form method="POST" action="attendance.php" class="m-0" onsubmit="return confirm(<?= $h(json_encode('Delete "' . $s['title'] . '"? Its ' . ($t['expected'] - $t['unmarked'] + ($ended ? 0 : 0)) . ' attendance mark(s) will be deleted too. This cannot be undone.')) ?>)">
                    <input type="hidden" name="action" value="delete_session"><input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-outline-danger"><i class="fas fa-trash me-1"></i>Delete</button>
                </form>
            </div>
        </div>

        <form method="POST" action="attendance_session.php?id=<?= $id ?>" class="panel" id="marksForm">
            <input type="hidden" name="action" value="save_marks">
            <input type="hidden" name="id" value="<?= $id ?>">
            <h4 class="mb-3">Attendance</h4>
            <?php if (!$roster): ?>
                <p class="note m-0">Nobody is expected at this session. Edit the session to choose who is expected.</p>
            <?php else: ?>
                <div class="toolbar">
                    <input type="search" class="form-control" id="q" placeholder="Search name or student ID" aria-label="Search members">
                    <button type="button" class="btn btn-success" id="allPresent" title="Members not marked, or marked absent, become present. Late and excused stay."><i class="fas fa-check-double me-1"></i>Mark all present</button>
                </div>
                <div class="fchips mb-2" role="group" aria-label="Show">
                    <?php foreach (['' => 'All', 'none' => 'Not marked', 'present' => 'Present', 'late' => 'Late', 'excused' => 'Excused', 'absent' => 'Absent'] as $k => $l): ?><button type="button" data-f="<?= $k ?>" aria-pressed="<?= $k === '' ? 'true' : 'false' ?>"><?= $l ?></button><?php endforeach; ?>
                </div>
                <div id="rows">
                    <?php foreach ($roster as $r): $cur = array_key_exists($r['id'], $posted) ? (string) $posted[$r['id']] : (string) $r['status']; ?>
                        <div class="mrow" data-q="<?= $h(mb_strtolower($r['full_name'] . ' ' . $r['university_id'])) ?>">
                            <input type="hidden" name="orig[<?= (int) $r['id'] ?>]" value="<?= $h($r['status']) ?>">
                            <input type="hidden" name="mark[<?= (int) $r['id'] ?>]" value="<?= $h($cur) ?>" class="mark">
                            <div class="who"><b><?= $h($r['full_name']) ?></b><small><?= $h($r['university_id']) ?> · <?= $h(ATT_LEVELS[$r['position']] ?? $r['position']) ?><?php if ($r['method'] === 'qr'): ?> · <span class="chip qr">QR <?= date('g:i a', strtotime($r['marked_at'])) ?></span><?php endif; ?></small></div>
                            <div class="seg" role="radiogroup" aria-label="Attendance of <?= $h($r['full_name']) ?>">
                                <?php foreach (ATT_STATUSES as $k => $l): $rid = 'm' . $r['id'] . $k; ?>
                                    <input type="radio" id="<?= $rid ?>" name="ui<?= (int) $r['id'] ?>" value="<?= $k ?>" <?= $cur === $k ? 'checked' : '' ?>><label for="<?= $rid ?>"><span class="long"><?= $l ?></span><span class="short"><?= $l[0] ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="savebar">
                    <span class="note" id="changed">No changes</span>
                    <?php if ($ended): ?>
                        <input type="text" name="reason" class="form-control grow" maxlength="200" placeholder="Reason for changing an ended session (required)" aria-label="Reason" value="<?= $h($_POST['reason'] ?? '') ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success ms-auto" id="saveBtn"><i class="fas fa-save me-1"></i>Save attendance</button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <script src="js/attendance-session.js?v=<?= filemtime(__DIR__ . '/js/attendance-session.js') ?>"></script>
</body>

</html>
