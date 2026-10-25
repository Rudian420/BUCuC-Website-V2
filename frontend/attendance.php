<?php
/**
 * Attendance: sessions (create / edit / delete) and the members report.
 * Permission take_attendance (Super, Panel, HR by default; grantable). Taking attendance for one
 * session: attendance_session.php. QR check-in screen: attendance_checkin.php.
 */
require_once '../backend/includes/auth.php';
require_admin(['can' => 'take_attendance', 'csrf_redirect' => 'attendance.php']);
require_once '../backend/includes/attendance.php';

$conn = db();
attendance_ensure($conn);
$message = $_GET['success'] ?? '';
$error = '';
$tab = ($_GET['tab'] ?? '') === 'report' ? 'report' : 'sessions';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_session') {
            $id = (int) ($_POST['id'] ?? 0);
            $old = $id ? att_session($conn, $id) : null;
            $title = trim((string) ($_POST['title'] ?? ''));
            $type = (string) ($_POST['type'] ?? '');
            $date = (string) ($_POST['session_date'] ?? '');
            $start = (string) ($_POST['start_time'] ?? '');
            $end = trim((string) ($_POST['end_time'] ?? ''));
            $audience = (string) ($_POST['audience'] ?? 'all');
            $picked = array_map('intval', (array) ($_POST['members'] ?? []));
            $qr = !empty($_POST['qr_enabled']) ? 1 : 0;
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if ($id && !$old) $error = 'That session no longer exists.';
            elseif ($title === '') $error = 'Enter a title.';
            elseif (!isset(ATT_TYPES[$type])) $error = 'Choose a type.';
            elseif (!$d || $d->format('Y-m-d') !== $date) $error = 'Enter a valid date.';
            elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start)) $error = 'Enter the start time.';
            elseif ($end !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) $error = 'Enter a valid end time, or leave it empty.';
            elseif (!isset(ATT_AUDIENCES[$audience])) $error = 'Choose who is expected.';
            elseif ($old && att_ended($old) && mb_strlen($reason) < 5) $error = 'This session has ended. Write a short reason for the change (at least 5 characters); it goes in the activity log.';
            if (!$error) {
                $memberIds = att_audience_members($conn, $audience, $picked);
                if (!$memberIds) $error = $audience === 'pick' ? 'Pick at least one member.' : 'No members match this group yet.';
            }
            if (!$error) {
                $conn->beginTransaction();
                $vals = [mb_substr($title, 0, 150), $type, $date, $start . ':00', $end !== '' ? $end . ':00' : null, $audience, $qr];
                if ($old) {
                    $conn->prepare("UPDATE attendance_sessions SET title = ?, type = ?, session_date = ?, start_time = ?, end_time = ?, audience = ?, qr_enabled = ? WHERE id = ?")
                        ->execute(array_merge($vals, [$id]));
                    if (!$qr) $conn->prepare("UPDATE attendance_sessions SET checkin_closes_at = NULL WHERE id = ?")->execute([$id]);
                } else {
                    $conn->prepare("INSERT INTO attendance_sessions (title, type, session_date, start_time, end_time, audience, qr_enabled, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute(array_merge($vals, [$_SESSION['admin_id']]));
                    $id = (int) $conn->lastInsertId();
                }
                [$added, $removed] = att_set_expected($conn, $id, $memberIds);
                $conn->commit();
                $s = att_session($conn, $id);
                $what = ATT_TYPES[$type] . ', ' . att_when($s) . ', ' . ATT_AUDIENCES[$audience] . ' (' . count($memberIds) . ' expected)' . ($qr ? ', QR check-in' : '');
                if ($old) {
                    log_activity('attendance_session_updated', $what . ($added || $removed ? "; expected list: +$added / −$removed" . ($removed ? ' (their marks removed)' : '') : '') . ($reason !== '' ? '. Reason: ' . $reason : ''), null, null, $title);
                    header('Location: attendance_session.php?id=' . $id . '&success=' . urlencode('Session saved.'));
                } else {
                    log_activity('attendance_session_created', $what, null, null, $title);
                    header('Location: attendance_session.php?id=' . $id . '&success=' . urlencode('Session created. Mark attendance below' . ($qr ? ' or open the QR check-in screen.' : '.')));
                }
                exit();
            }
        } elseif ($action === 'delete_session') {
            $s = att_session($conn, $_POST['id'] ?? 0);
            if (!$s) { $error = 'That session no longer exists.'; }
            else {
                $n = $conn->prepare("SELECT COUNT(*) FROM attendance_records WHERE session_id = ?");
                $n->execute([$s['id']]);
                $records = (int) $n->fetchColumn();
                $conn->prepare("DELETE FROM attendance_sessions WHERE id = ?")->execute([$s['id']]);   // expected + records go with it
                log_activity('attendance_session_deleted', ATT_TYPES[$s['type']] . ', ' . att_when($s) . "; $records attendance mark(s) deleted", null, null, $s['title']);
                header('Location: attendance.php?success=' . urlencode("\"{$s['title']}\" and its $records attendance mark(s) were deleted."));
                exit();
            }
        }
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('attendance.php: ' . $e->getMessage());
        $error = 'Saving failed because of a database problem. Nothing was changed; please try again.';
    }
}

$semesters = att_semesters($conn);
$members = $conn->query("SELECT id, full_name, university_id, position FROM members ORDER BY full_name")->fetchAll();
$levelCounts = ['all' => count($members), 'GB' => 0, 'ASB' => 0, 'SB' => 0];
foreach ($members as $m) if (isset($levelCounts[$m['position']])) $levelCounts[$m['position']]++;

// ---------------------------------------------------------------- sessions tab
$editing = isset($_GET['edit']) ? att_session($conn, $_GET['edit']) : null;
$posted = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_session') ? $_POST : null;
if ($posted && (int) ($posted['id'] ?? 0)) $editing = att_session($conn, $posted['id']);
$pickedNow = [];
if ($posted) $pickedNow = array_map('intval', (array) ($posted['members'] ?? []));
elseif ($editing) {
    $st = $conn->prepare("SELECT member_id FROM attendance_expected WHERE session_id = ?");
    $st->execute([$editing['id']]);
    $pickedNow = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
$f = function ($k, $def = '') use ($posted, $editing) {
    if ($posted) return (string) ($posted[$k] ?? '');
    if ($editing) {
        $v = (string) ($editing[$k] ?? '');
        return in_array($k, ['start_time', 'end_time'], true) ? substr($v, 0, 5) : $v;
    }
    return $def;
};
$showForm = $editing || $posted || isset($_GET['new']);

$semFilter = $_GET['semester'] ?? '';
$sessions = [];
if ($tab === 'sessions') {
    $rows = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM attendance_expected e WHERE e.session_id = s.id) AS expected FROM attendance_sessions s ORDER BY s.session_date DESC, s.start_time DESC")->fetchAll();
    $counts = [];
    foreach ($conn->query("SELECT session_id, status, COUNT(*) n FROM attendance_records GROUP BY session_id, status")->fetchAll() as $c) $counts[$c['session_id']][$c['status']] = (int) $c['n'];
    foreach ($rows as $s) {
        if ($semFilter !== '' && att_semester($s['session_date']) !== $semFilter) continue;
        $c = $counts[$s['id']] ?? [];
        $marked = array_sum($c);
        $s['c'] = ['present' => $c['present'] ?? 0, 'late' => $c['late'] ?? 0, 'excused' => $c['excused'] ?? 0, 'absent' => ($c['absent'] ?? 0) + (att_ended($s) ? (int) $s['expected'] - $marked : 0)];
        $sessions[] = $s;
    }
}

// ---------------------------------------------------------------- report tab
$report = [];
$repSem = $_GET['semester'] ?? ($semesters[0] ?? '');
$repLevel = $_GET['level'] ?? '';
$below = isset($_GET['below']) && $_GET['below'] !== '' ? max(0, min(100, (int) $_GET['below'])) : null;
if ($tab === 'report') {
    $semStats = att_member_stats($conn, $repSem !== '' ? $repSem : null);
    $allStats = att_member_stats($conn);
    foreach ($members as $m) {
        if ($repLevel !== '' && $m['position'] !== $repLevel) continue;
        $st = $semStats[(int) $m['id']] ?? null;
        if (!$st && !isset($allStats[(int) $m['id']])) continue;   // never expected at any session
        $pct = $st['pct'] ?? null;
        if ($below !== null && ($pct === null || $pct >= $below)) continue;
        $report[] = ['m' => $m, 's' => $st, 'all' => $allStats[(int) $m['id']]['pct'] ?? null];
    }
    usort($report, fn($a, $b) => [($a['s']['pct'] ?? 101), $a['m']['full_name']] <=> [($b['s']['pct'] ?? 101), $b['m']['full_name']]);
}
$status = function (array $s) {
    if (att_checkin_open($s)) return ['now', 'Check-in open'];
    if (att_ended($s)) return ['done', 'Ended'];
    if (time() >= att_start_ts($s)) return ['now', 'In progress'];
    return ['up', date('Y-m-d') === $s['session_date'] ? 'Today' : 'Upcoming'];
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance | BUCuC Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/all.min.css">
    <link rel="stylesheet" href="css/attendance.css?v=<?= filemtime(__DIR__ . '/css/attendance.css') ?>">
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
            <h2 class="m-0"><i class="fas fa-clipboard-check me-2"></i>Attendance</h2>
            <div class="links"><a href="<?= $h(auth_dashboard_page()) ?>" class="back-btn"><i class="fas fa-arrow-left"></i>Back to Dashboard</a></div>
        </div>
        <?php if ($message): ?><div class="alert alert-success"><?= $h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>

        <div class="tabs">
            <a href="attendance.php" class="<?= $tab === 'sessions' ? 'on' : '' ?>"><i class="fas fa-calendar-check me-1"></i>Sessions</a>
            <a href="attendance.php?tab=report" class="<?= $tab === 'report' ? 'on' : '' ?>"><i class="fas fa-chart-bar me-1"></i>Members report</a>
        </div>

        <?php if ($tab === 'sessions'): ?>
            <?php if ($showForm): ?>
                <div class="panel" id="sessionForm">
                    <h4 class="mb-3"><?= $editing ? 'Edit session' : 'New session' ?></h4>
                    <form method="POST" action="attendance.php">
                        <input type="hidden" name="action" value="save_session">
                        <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label" for="title">Title</label><input type="text" class="form-control" id="title" name="title" maxlength="150" required placeholder="Dance rehearsal, Rhythm Revive" value="<?= $h($f('title')) ?>"></div>
                            <div class="col-md-6 mb-3"><label class="form-label" for="type">Type</label>
                                <select class="form-select" id="type" name="type"><?php foreach (ATT_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $f('type', 'rehearsal') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
                            </div>
                            <div class="col-sm-4 mb-3"><label class="form-label" for="session_date">Date</label><input type="date" class="form-control" id="session_date" name="session_date" required value="<?= $h($f('session_date', date('Y-m-d'))) ?>"></div>
                            <div class="col-sm-4 mb-3"><label class="form-label" for="start_time">Starts</label><input type="time" class="form-control" id="start_time" name="start_time" required value="<?= $h($f('start_time', '16:00')) ?>"></div>
                            <div class="col-sm-4 mb-3"><label class="form-label" for="end_time">Ends (optional)</label><input type="time" class="form-control" id="end_time" name="end_time" value="<?= $h($f('end_time')) ?>"></div>
                        </div>
                        <p class="note mt-n2">Times are Dhaka time. Without an end time the session counts as ended at midnight; after it ends, every change to its attendance needs a short reason.</p>
                        <div class="mb-2 form-label">Who is expected</div>
                        <div class="pills mb-2" role="radiogroup">
                            <?php foreach (ATT_AUDIENCES as $k => $l): ?>
                                <label><input type="radio" name="audience" value="<?= $k ?>" <?= $f('audience', 'all') === $k ? 'checked' : '' ?>><?= $l ?><?= $k !== 'pick' ? ' (' . $levelCounts[$k] . ')' : '' ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div id="pickBox" class="mb-3 <?= $f('audience', 'all') === 'pick' ? '' : 'd-none' ?>">
                            <input type="search" id="pickFilter" class="form-control mb-2" placeholder="Search name or student ID">
                            <div class="picklist">
                                <?php foreach ($members as $m): ?>
                                    <label data-q="<?= $h(mb_strtolower($m['full_name'] . ' ' . $m['university_id'])) ?>"><input type="checkbox" name="members[]" value="<?= (int) $m['id'] ?>" <?= in_array((int) $m['id'], $pickedNow, true) ? 'checked' : '' ?>><?= $h($m['full_name']) ?><small><?= $h($m['university_id']) ?> · <?= $h(ATT_LEVELS[$m['position']] ?? $m['position']) ?></small></label>
                                <?php endforeach; ?>
                            </div>
                            <span class="note" id="pickCount"></span>
                        </div>
                        <label class="form-check-label mb-3 d-block"><input type="checkbox" class="form-check-input me-2" name="qr_enabled" value="1" <?= $f('qr_enabled') ? 'checked' : '' ?>>Allow QR self check-in (members scan a code on your screen and enter their student ID)</label>
                        <?php if ($editing && att_ended($editing)): ?>
                            <div class="mb-3"><label class="form-label" for="reason">Reason for changing an ended session (goes in the activity log)</label><input type="text" class="form-control" id="reason" name="reason" maxlength="200" required minlength="5" value="<?= $h($posted['reason'] ?? '') ?>"></div>
                        <?php endif; ?>
                        <?php if ($editing): ?><p class="note">Changing who is expected removes the marks of members who are no longer expected.</p><?php endif; ?>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i><?= $editing ? 'Save session' : 'Create session' ?></button>
                        <a href="<?= $editing ? 'attendance_session.php?id=' . (int) $editing['id'] : 'attendance.php' ?>" class="btn btn-secondary ms-2">Cancel</a>
                    </form>
                </div>
            <?php endif; ?>

            <div class="panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h4 class="m-0">Sessions</h4>
                    <div class="d-flex gap-2 flex-wrap">
                        <form method="GET" action="attendance.php" class="m-0">
                            <select name="semester" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Semester">
                                <option value="">All semesters</option>
                                <?php foreach ($semesters as $sem): ?><option <?= $semFilter === $sem ? 'selected' : '' ?>><?= $h($sem) ?></option><?php endforeach; ?>
                            </select>
                        </form>
                        <?php if (!$showForm): ?><a href="attendance.php?new=1#sessionForm" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i>New session</a><?php endif; ?>
                    </div>
                </div>
                <?php if (!$sessions): ?>
                    <p class="note m-0">No sessions yet. Create one to start taking attendance.</p>
                <?php else: ?>
                    <div class="scroll-x">
                        <table class="tbl">
                            <thead><tr><th>When</th><th>Session</th><th class="num">Expected</th><th class="num">Present</th><th class="num">Late</th><th class="num hide-sm">Excused</th><th class="num">Absent</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($sessions as $s): [$cls, $label] = $status($s); ?>
                                    <tr>
                                        <td><?= $h(att_when($s)) ?><br><span class="chip <?= $cls ?>"><?= $label ?></span></td>
                                        <td><a href="attendance_session.php?id=<?= (int) $s['id'] ?>"><b><?= $h($s['title']) ?></b></a><br><small class="note"><?= ATT_TYPES[$s['type']] ?? '' ?> · <?= ATT_AUDIENCES[$s['audience']] ?? '' ?></small><?php if ($s['qr_enabled']): ?> <span class="chip qr">QR</span><?php endif; ?></td>
                                        <td class="num"><?= (int) $s['expected'] ?></td>
                                        <td class="num"><?= $s['c']['present'] ?></td>
                                        <td class="num"><?= $s['c']['late'] ?></td>
                                        <td class="num hide-sm"><?= $s['c']['excused'] ?></td>
                                        <td class="num"><?= $s['c']['absent'] ?></td>
                                        <td class="text-end"><a class="btn btn-sm btn-outline-light" href="attendance_session.php?id=<?= (int) $s['id'] ?>">Open</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="panel">
                <form method="GET" action="attendance.php" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="tab" value="report">
                    <div class="col-sm-3"><label class="form-label" for="semester">Semester</label>
                        <select class="form-select" id="semester" name="semester"><option value="">All time</option><?php foreach ($semesters as $sem): ?><option <?= $repSem === $sem ? 'selected' : '' ?>><?= $h($sem) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-3"><label class="form-label" for="level">Level</label>
                        <select class="form-select" id="level" name="level"><option value="">All levels</option><?php foreach (ATT_LEVELS as $k => $l): ?><option value="<?= $k ?>" <?= $repLevel === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-3"><label class="form-label" for="below">Only below (%)</label><input type="number" class="form-control" id="below" name="below" min="0" max="100" placeholder="e.g. <?= ATT_LOW_DEFAULT ?>" value="<?= $below !== null ? $below : '' ?>"></div>
                    <div class="col-sm-3 d-flex gap-2"><button class="btn btn-outline-light flex-fill" type="submit">Show</button>
                        <a class="btn btn-success flex-fill" href="attendance_export.php?<?= $h(http_build_query(['report' => 'members', 'semester' => $repSem, 'level' => $repLevel, 'below' => $below])) ?>"><i class="fas fa-file-excel me-1"></i>Excel</a></div>
                </form>
                <p class="note">Present and late count as attended; excused sessions are left out; a member not marked at an ended session counts as absent. <a href="attendance.php?tab=report&semester=<?= $h(urlencode($repSem)) ?>&below=<?= ATT_LOW_DEFAULT ?>" class="text-reset">Show members under <?= ATT_LOW_DEFAULT ?>%</a></p>
                <input type="search" id="repFilter" class="form-control mb-2" placeholder="Search name or student ID">
                <?php if (!$report): ?>
                    <p class="note m-0"><?= $below !== null ? 'Nobody is below ' . $below . '%.' : 'No attendance yet for this selection.' ?></p>
                <?php else: ?>
                    <div class="scroll-x">
                        <table class="tbl" id="repTable">
                            <thead><tr><th>Member</th><th class="hide-sm">Level</th><th class="num">Sessions</th><th class="num">Attended</th><th class="num hide-sm">Late</th><th class="num hide-sm">Excused</th><th class="num">Absent</th><th class="num"><?= $repSem !== '' ? $h($repSem) : 'All time' ?></th><th class="num hide-sm">Overall</th></tr></thead>
                            <tbody>
                                <?php foreach ($report as $r): $s = $r['s'] ?? ['expected' => 0, 'attended' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'pct' => null]; ?>
                                    <tr data-q="<?= $h(mb_strtolower($r['m']['full_name'] . ' ' . $r['m']['university_id'])) ?>">
                                        <td><a href="attendance_member.php?id=<?= (int) $r['m']['id'] ?>"><b><?= $h($r['m']['full_name']) ?></b></a><br><small class="note"><?= $h($r['m']['university_id']) ?></small></td>
                                        <td class="hide-sm"><?= $h(ATT_LEVELS[$r['m']['position']] ?? $r['m']['position']) ?></td>
                                        <td class="num"><?= $s['expected'] ?></td>
                                        <td class="num"><?= $s['attended'] ?></td>
                                        <td class="num hide-sm"><?= $s['late'] ?></td>
                                        <td class="num hide-sm"><?= $s['excused'] ?></td>
                                        <td class="num"><?= $s['absent'] ?></td>
                                        <td class="num"><span class="pct <?= $s['pct'] !== null && $s['pct'] < ATT_LOW_DEFAULT ? 'low' : 'ok' ?>"><?= att_pct_label($s['pct']) ?></span></td>
                                        <td class="num hide-sm"><?= att_pct_label($r['all']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="js/attendance-admin.js?v=<?= filemtime(__DIR__ . '/js/attendance-admin.js') ?>"></script>
</body>

</html>
