<?php
/**
 * Attendance of one member: overall and per-semester percentages and every session they were
 * expected at. Excused sessions don't count against them. Permission take_attendance.
 */
require_once '../backend/includes/auth.php';
require_admin(['can' => 'take_attendance']);
require_once '../backend/includes/attendance.php';

$conn = db();
attendance_ensure($conn);
$st = $conn->prepare("SELECT id, full_name, university_id, position FROM members WHERE id = ?");
$st->execute([(int) ($_GET['id'] ?? 0)]);
$m = $st->fetch();
if (!$m) {
    header('Location: attendance.php?tab=report');
    exit();
}
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$mid = (int) $m['id'];

$overall = att_member_stats($conn, null, [$mid])[$mid] ?? null;
$q = $conn->prepare("SELECT s.*, r.status, r.method, r.marked_at FROM attendance_expected e JOIN attendance_sessions s ON s.id = e.session_id
    LEFT JOIN attendance_records r ON r.session_id = e.session_id AND r.member_id = e.member_id
    WHERE e.member_id = ? ORDER BY s.session_date DESC, s.start_time DESC");
$q->execute([$mid]);
$rows = $q->fetchAll();
$bySem = [];
foreach (array_unique(array_map(fn($r) => att_semester($r['session_date']), $rows)) as $sem) {
    $bySem[$sem] = att_member_stats($conn, $sem, [$mid])[$mid] ?? null;
}
uksort($bySem, fn($a, $b) => att_semester_key($b) <=> att_semester_key($a));
$cls = fn($p) => $p === null ? '' : ($p < ATT_LOW_DEFAULT ? 'low' : 'ok');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($m['full_name']) ?> · Attendance | BUCuC Admin</title>
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
            <div>
                <h2 class="m-0"><?= $h($m['full_name']) ?></h2>
                <div class="note mt-1"><?= $h($m['university_id']) ?> · <?= $h(ATT_LEVELS[$m['position']] ?? $m['position']) ?></div>
            </div>
            <div class="links"><a href="attendance.php?tab=report" class="back-btn"><i class="fas fa-arrow-left"></i>Members report</a></div>
        </div>

        <div class="panel">
            <div class="totals">
                <div><b class="pct <?= $cls($overall['pct'] ?? null) ?>"><?= att_pct_label($overall['pct'] ?? null) ?></b><span>Overall attendance</span></div>
                <div><b><?= $overall['attended'] ?? 0 ?></b><span>Attended (incl. <?= $overall['late'] ?? 0 ?> late)</span></div>
                <div><b><?= $overall['excused'] ?? 0 ?></b><span>Excused (not counted)</span></div>
                <div><b><?= $overall['absent'] ?? 0 ?></b><span>Absent</span></div>
                <div><b><?= $overall['expected'] ?? 0 ?></b><span>Sessions ended</span></div>
            </div>
            <p class="note mt-3 mb-0">Percentage = attended ÷ (sessions − excused). Present and late count as attended. Sessions that haven't ended yet don't count.</p>
        </div>

        <?php if ($bySem): ?>
            <div class="panel">
                <h4 class="mb-2">By semester</h4>
                <div class="scroll-x"><table class="tbl">
                    <thead><tr><th>Semester</th><th class="num">Sessions</th><th class="num">Attended</th><th class="num">Excused</th><th class="num">Absent</th><th class="num">Attendance</th></tr></thead>
                    <tbody><?php foreach ($bySem as $sem => $st): ?>
                        <tr><td><?= $h($sem) ?></td><td class="num"><?= $st['expected'] ?? 0 ?></td><td class="num"><?= $st['attended'] ?? 0 ?></td><td class="num"><?= $st['excused'] ?? 0 ?></td><td class="num"><?= $st['absent'] ?? 0 ?></td><td class="num"><span class="pct <?= $cls($st['pct'] ?? null) ?>"><?= att_pct_label($st['pct'] ?? null) ?></span></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h4 class="mb-2">Sessions (<?= count($rows) ?>)</h4>
            <?php if (!$rows): ?><p class="note m-0">Not expected at any session yet.</p><?php else: ?>
                <div class="scroll-x"><table class="tbl">
                    <thead><tr><th>When</th><th>Session</th><th>Status</th></tr></thead>
                    <tbody><?php foreach ($rows as $r):
                        $ended = att_ended($r);
                        $status = $r['status'] ?? ($ended ? 'absent' : null); ?>
                        <tr><td><?= $h(att_when($r)) ?></td>
                            <td><a href="attendance_session.php?id=<?= (int) $r['id'] ?>"><?= $h($r['title']) ?></a><br><small class="note"><?= ATT_TYPES[$r['type']] ?? '' ?></small></td>
                            <td><?php if ($status): ?><span class="chip <?= $status ?>"><?= ATT_STATUSES[$status] ?><?= $r['status'] === null ? ' (not marked)' : '' ?></span><?php if ($r['method'] === 'qr'): ?> <span class="chip qr">QR</span><?php endif; ?><?php else: ?><span class="chip up">Upcoming</span><?php endif; ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
