<?php
/**
 * Attendance tracking (permission take_attendance; Super, Panel, HR by default).
 *
 * attendance_sessions   one rehearsal / meeting / event / workshop
 * attendance_expected   who is expected at a session (saved when the session is created or edited,
 *                       so later level changes don't rewrite old percentages)
 * attendance_records    one status per expected member: present / late / excused / absent
 *
 * Percentages: present + late count as attended; excused sessions are left out; an expected member
 * with no mark counts as absent once the session has ended. Times are Asia/Dhaka (db.php).
 * QR self check-in: the check-in screen shows a code that changes every 30 seconds (signed with
 * the session's secret), see attendance_checkin.php and checkin.php.
 */

require_once __DIR__ . '/site.php';

const ATT_TYPES = ['rehearsal' => 'Rehearsal', 'meeting' => 'Meeting', 'event' => 'Event', 'workshop' => 'Workshop', 'other' => 'Other'];
const ATT_STATUSES = ['present' => 'Present', 'late' => 'Late', 'excused' => 'Excused', 'absent' => 'Absent'];
const ATT_AUDIENCES = ['all' => 'All members', 'GB' => 'GB members', 'ASB' => 'ASB members', 'SB' => 'Secretary Panel', 'pick' => 'Picked members'];
const ATT_LATE_MINUTES = 15;        // QR check-ins later than this after the start are marked Late
const ATT_QR_SECONDS = 30;          // the QR code changes this often
const ATT_QR_GRACE = 2;             // a scanned code is accepted for this many older windows (~90 s in all)
const ATT_FORM_MINUTES = 5;         // time to type the student ID after scanning
const ATT_FAILS_PER_IP = 30;        // wrong student IDs per IP address …
const ATT_FAILS_MINUTES = 10;       // … within this many minutes
const ATT_LOW_DEFAULT = 50;         // "low attendance" filter, percent
const ATT_LEVELS = ['GB' => 'GB', 'ASB' => 'ASB', 'SB' => 'Secretary Panel'];

function attendance_ensure(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    site_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, type VARCHAR(20) NOT NULL DEFAULT 'rehearsal',
        session_date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME NULL, audience VARCHAR(10) NOT NULL DEFAULT 'all',
        event_id INT NULL, qr_enabled TINYINT(1) NOT NULL DEFAULT 0, checkin_secret CHAR(64) NULL,
        checkin_opened_at DATETIME NULL, checkin_closes_at DATETIME NULL,
        created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_att_date (session_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_expected (
        session_id INT NOT NULL, member_id INT NOT NULL, PRIMARY KEY (session_id, member_id), KEY idx_att_exp_member (member_id),
        CONSTRAINT fk_att_exp_session FOREIGN KEY (session_id) REFERENCES attendance_sessions (id) ON DELETE CASCADE,
        CONSTRAINT fk_att_exp_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_records (
        session_id INT NOT NULL, member_id INT NOT NULL, status VARCHAR(8) NOT NULL, method VARCHAR(6) NOT NULL DEFAULT 'manual',
        marked_by INT NULL, marked_at DATETIME NOT NULL, PRIMARY KEY (session_id, member_id), KEY idx_att_rec_member (member_id),
        CONSTRAINT fk_att_rec_session FOREIGN KEY (session_id) REFERENCES attendance_sessions (id) ON DELETE CASCADE,
        CONSTRAINT fk_att_rec_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_checkin_fails (
        id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_att_fail (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ------------------------------------------------------------------ sessions */

function att_session(PDO $pdo, $id)
{
    attendance_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM attendance_sessions WHERE id = ?");
    $st->execute([(int) $id]);
    return $st->fetch() ?: null;
}

/** When a session starts / ends (Unix time). Without an end time it ends at midnight of its date. */
function att_start_ts(array $s)
{
    return strtotime($s['session_date'] . ' ' . $s['start_time']);
}
function att_end_ts(array $s)
{
    if (empty($s['end_time'])) return strtotime($s['session_date'] . ' 23:59:59');
    $end = strtotime($s['session_date'] . ' ' . $s['end_time']);
    return $end <= att_start_ts($s) ? $end + 86400 : $end;   // ends after midnight
}
function att_ended(array $s)
{
    return time() > att_end_ts($s);
}

/** SQL for a session's end, matching att_end_ts() (for reports). */
const ATT_END_SQL = "IF(s.end_time IS NULL, TIMESTAMP(s.session_date, '23:59:59'),
    IF(s.end_time <= s.start_time, TIMESTAMP(s.session_date + INTERVAL 1 DAY, s.end_time), TIMESTAMP(s.session_date, s.end_time)))";

/** "Fall 2026" (BRACU: Spring Jan–Apr, Summer May–Aug, Fall Sep–Dec). */
function att_semester($date)
{
    $t = strtotime($date);
    $m = (int) date('n', $t);
    return ($m <= 4 ? 'Spring' : ($m <= 8 ? 'Summer' : 'Fall')) . ' ' . date('Y', $t);
}
/** Sort key for a semester label, newest first when sorted descending. */
function att_semester_key($label)
{
    [$name, $year] = explode(' ', $label) + [1 => '0'];
    return (int) $year * 10 + (['Spring' => 1, 'Summer' => 2, 'Fall' => 3][$name] ?? 0);
}

/** Members matching an audience: all / GB / ASB / SB / pick (with ids). */
function att_audience_members(PDO $pdo, $audience, array $pickedIds = [])
{
    if ($audience === 'pick') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pickedIds))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id FROM members WHERE id IN ($in)");
        $st->execute($ids);
    } elseif (in_array($audience, ['GB', 'ASB', 'SB'], true)) {
        $st = $pdo->prepare("SELECT id FROM members WHERE position = ?");
        $st->execute([$audience]);
    } else {
        $st = $pdo->query("SELECT id FROM members");
    }
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Saves who is expected. Members no longer expected lose their marks for this session. Returns [added, removed]. */
function att_set_expected(PDO $pdo, $sessionId, array $memberIds)
{
    $sessionId = (int) $sessionId;
    $st = $pdo->prepare("SELECT member_id FROM attendance_expected WHERE session_id = ?");
    $st->execute([$sessionId]);
    $old = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $add = array_diff($memberIds, $old);
    $drop = array_diff($old, $memberIds);
    $ins = $pdo->prepare("INSERT IGNORE INTO attendance_expected (session_id, member_id) VALUES (?, ?)");
    foreach ($add as $m) $ins->execute([$sessionId, $m]);
    if ($drop) {
        $in = implode(',', array_map('intval', $drop));
        $pdo->exec("DELETE FROM attendance_expected WHERE session_id = $sessionId AND member_id IN ($in)");
        $pdo->exec("DELETE FROM attendance_records WHERE session_id = $sessionId AND member_id IN ($in)");
    }
    return [count($add), count($drop)];
}

/** Expected members of a session with their mark (status null = not marked yet). */
function att_roster(PDO $pdo, $sessionId)
{
    $st = $pdo->prepare("SELECT m.id, m.full_name, m.university_id, m.position, r.status, r.method, r.marked_at
        FROM attendance_expected e JOIN members m ON m.id = e.member_id
        LEFT JOIN attendance_records r ON r.session_id = e.session_id AND r.member_id = e.member_id
        WHERE e.session_id = ? ORDER BY m.full_name");
    $st->execute([(int) $sessionId]);
    return $st->fetchAll();
}

/** Totals for a session: present, late, excused, absent (incl. unmarked once ended), unmarked, expected. */
function att_totals(array $roster, $ended)
{
    $t = ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'unmarked' => 0, 'expected' => count($roster)];
    foreach ($roster as $r) {
        if ($r['status'] === null) { $t['unmarked']++; if ($ended) $t['absent']++; }
        else $t[$r['status']]++;
    }
    return $t;
}

/* ------------------------------------------------------------------ reports */

/**
 * Per-member attendance over ended sessions: [member_id => [expected, attended, late, excused,
 * absent, pct (null if nothing counts yet)]]. $semester = "Fall 2026" limits it to that semester.
 */
function att_member_stats(PDO $pdo, $semester = null, ?array $memberIds = null)
{
    attendance_ensure($pdo);
    $rows = $pdo->query("SELECT e.member_id, s.session_date, r.status FROM attendance_expected e
        JOIN attendance_sessions s ON s.id = e.session_id
        LEFT JOIN attendance_records r ON r.session_id = e.session_id AND r.member_id = e.member_id
        WHERE " . ATT_END_SQL . " < NOW()")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $mid = (int) $r['member_id'];
        if ($memberIds !== null && !in_array($mid, $memberIds, true)) continue;
        if ($semester !== null && att_semester($r['session_date']) !== $semester) continue;
        $o = &$out[$mid];
        $o = ($o ?? ['expected' => 0, 'attended' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0]);
        $o['expected']++;
        if ($r['status'] === 'present' || $r['status'] === 'late') $o['attended']++;
        if ($r['status'] === 'late') $o['late']++;
        if ($r['status'] === 'excused') $o['excused']++;
        if ($r['status'] === null || $r['status'] === 'absent') $o['absent']++;
        unset($o);
    }
    foreach ($out as &$o) {
        $counted = $o['expected'] - $o['excused'];   // excused sessions don't count against anyone
        $o['pct'] = $counted > 0 ? (int) round($o['attended'] * 100 / $counted) : null;
    }
    return $out;
}

/** Semesters that have sessions, newest first. */
function att_semesters(PDO $pdo)
{
    attendance_ensure($pdo);
    $s = array_unique(array_map('att_semester', $pdo->query("SELECT DISTINCT session_date FROM attendance_sessions")->fetchAll(PDO::FETCH_COLUMN)));
    usort($s, fn($a, $b) => att_semester_key($b) <=> att_semester_key($a));
    return $s;
}

/* ------------------------------------------------------------------ QR self check-in */

function att_checkin_open(array $s)
{
    return $s['qr_enabled'] && $s['checkin_secret'] && $s['checkin_closes_at'] && strtotime($s['checkin_closes_at']) > time();
}

/** The code shown in the QR for one 30-second window. */
function att_qr_token(array $s, $window = null)
{
    $window = $window ?? intdiv(time(), ATT_QR_SECONDS);
    return substr(hash_hmac('sha256', 'qr|' . $s['id'] . '|' . $window, $s['checkin_secret']), 0, 20);
}

/** A scanned code is valid in its own window and the ATT_QR_GRACE windows after it (~90 s). */
function att_qr_valid(array $s, $token)
{
    if (!is_string($token) || !att_checkin_open($s)) return false;
    $now = intdiv(time(), ATT_QR_SECONDS);
    for ($w = $now; $w >= $now - ATT_QR_GRACE; $w--) {
        if (hash_equals(att_qr_token($s, $w), $token)) return true;
    }
    return false;
}

/** After a valid scan, the student gets ATT_FORM_MINUTES to type their ID: a signed ticket. */
function att_ticket(array $s)
{
    $t = time();
    return $t . '.' . substr(hash_hmac('sha256', 'ticket|' . $s['id'] . '|' . $t, $s['checkin_secret']), 0, 32);
}
function att_ticket_valid(array $s, $ticket)
{
    if (!is_string($ticket) || !preg_match('/^(\d+)\.([0-9a-f]{32})$/', $ticket, $m)) return false;
    if (time() - (int) $m[1] > ATT_FORM_MINUTES * 60 || (int) $m[1] > time() + 60) return false;
    return hash_equals(substr(hash_hmac('sha256', 'ticket|' . $s['id'] . '|' . $m[1], $s['checkin_secret']), 0, 32), $m[2]);
}

/** Cookie that marks "this phone already checked someone in for this session". */
function att_device_cookie(array $s)
{
    return ['name' => 'att_dev_' . (int) $s['id'], 'value' => substr(hash_hmac('sha256', 'device|' . $s['id'], $s['checkin_secret']), 0, 24)];
}

function att_fail_count(PDO $pdo)
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM attendance_checkin_fails WHERE ip = ? AND created_at > (NOW() - INTERVAL " . ATT_FAILS_MINUTES . " MINUTE)");
    $st->execute([auth_ip()]);
    return (int) $st->fetchColumn();
}
function att_fail_record(PDO $pdo)
{
    $pdo->prepare("INSERT INTO attendance_checkin_fails (ip) VALUES (?)")->execute([auth_ip()]);
    if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM attendance_checkin_fails WHERE created_at < (NOW() - INTERVAL 1 DAY)");
}

/* ------------------------------------------------------------------ display helpers */

function att_when(array $s)
{
    $txt = date('D j M Y, g:i a', att_start_ts($s));
    if (!empty($s['end_time'])) $txt .= ' – ' . date('g:i a', att_end_ts($s));
    return $txt;
}
function att_pct_label($pct)
{
    return $pct === null ? '—' : $pct . '%';
}
