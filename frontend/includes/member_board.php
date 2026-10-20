<?php
/**
 * Shared page for SB / ASB / GB member directories (sb_members.php, asb_panel.php, gb_members.php).
 * Member levels live in the database (members.position: GB / ASB / SB = Secretary Panel).
 * Promote / demote / remove go through this same file (?action=gas_proxy, JSON) and change the
 * database; the club's Google member sheet is then updated too (best effort, via the Apps Script).
 * "Import from Google Sheet" (Panel Admin / Super Admin) copies the sheet's members into the database.
 *
 * Expects $board = 'SB' | 'ASB' | 'GB' and $selfPage (the including file name).
 */
require_once __DIR__ . '/../../backend/includes/auth.php';

// Published CSV of the member sheet (read) and Apps Script web app (update/delete).
// defined() lets a local test setup point these at fake services.
defined('MEMBER_SHEET_CSV_URL') || define('MEMBER_SHEET_CSV_URL', 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTeWd8iZFzbbD7S9VJR6mrPCmQar0guSJ2QMMS9HnSq8pzZeN609XDf9Y1LEPGJCnbAAYNtrAPmM9iL/pub?output=csv');
defined('MEMBER_SHEET_GAS_URL') || define('MEMBER_SHEET_GAS_URL', 'https://script.google.com/macros/s/AKfycbyiUnMIc9CVukYUmHRfxmFQFXCQhtBbgskIYl8zjrSEVUGK9uWJ5a9QRQJ0FgZ-M0ClfA/exec');

$boards = [
    'SB'  => 'Student Board',
    'ASB' => 'Active Student Body',
    'GB'  => 'General Board',
];
$boardName = $boards[$board];

// Admins who can see the member lists; the proxy answers in JSON
require_admin(['can' => 'view_members', 'json' => isset($_GET['action'])]);

// Member flow: GB -> ASB -> SB (Secretary Panel). One step at a time.
const BOARD_ORDER = ['GB' => 0, 'ASB' => 1, 'SB' => 2];
const BOARD_NAMES = ['GB' => 'GB', 'ASB' => 'ASB', 'SB' => 'Secretary Panel'];
$canGbAsb = can('promote_gb_asb');
$canSp = can('promote_sp');
$canDeleteMember = can('manage_site');
$conn = db();
site_ensure_schema($conn);   // adds members.position on first use

/** Permission needed to move between two adjacent levels, or null if the move isn't one step. */
function board_move_permission($from, $to)
{
    if (!isset(BOARD_ORDER[$from], BOARD_ORDER[$to]) || abs(BOARD_ORDER[$from] - BOARD_ORDER[$to]) !== 1) return null;
    return (in_array('SB', [$from, $to], true)) ? 'promote_sp' : 'promote_gb_asb';
}

/** Mirrors a change to the club's Google member sheet. Returns null on success or an error text. */
function board_sheet_sync(array $payload)
{
    if (!MEMBER_SHEET_GAS_URL) return null;
    foreach ($payload as $k => $v) {
        if ($k !== 'action' && $k !== 'studentId') $payload[$k] = sheet_safe($v);
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => MEMBER_SHEET_GAS_URL, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return 'could not reach the Google member sheet (' . $err . ')';
    $json = json_decode($resp, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || (isset($json['success']) && !$json['success'])) {
        return 'the Google member sheet answered HTTP ' . $status;
    }
    return null;
}

/** Copies the published Google member sheet into the members table. Returns [added, updated, skipped]. */
function board_import_sheet(PDO $conn)
{
    $ch = curl_init(MEMBER_SHEET_CSV_URL . (strpos(MEMBER_SHEET_CSV_URL, '?') === false ? '?' : '&') . 'cachebust=' . time());
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
    $csv = curl_exec($ch);
    curl_close($ch);
    if ($csv === false || $csv === '') throw new RuntimeException('Could not download the Google member sheet.');
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    // Columns are found by their header; defaults are the sheet's usual order: name, student ID, G-Suite, position.
    // A Gmail / personal e-mail column is used when the sheet has one.
    $head = array_map(fn($h) => strtolower(trim((string) $h)), fgetcsv($fh) ?: []);
    $col = ['name' => 0, 'id' => 1, 'gsuite' => 2, 'position' => 3, 'gmail' => null];
    foreach ($head as $i => $h) {
        if (strpos($h, 'suite') !== false || strpos($h, 'bracu') !== false) $col['gsuite'] = $i;
        elseif (strpos($h, 'gmail') !== false || strpos($h, 'personal') !== false || $h === 'email' || $h === 'e-mail') $col['gmail'] = $i;
        elseif (strpos($h, 'student') !== false || $h === 'id') $col['id'] = $i;
        elseif (strpos($h, 'position') !== false || strpos($h, 'level') !== false) $col['position'] = $i;
        elseif (strpos($h, 'name') !== false) $col['name'] = $i;
    }
    $find = $conn->prepare("SELECT id, email, gsuite_email FROM members WHERE university_id = ?");
    $upd = $conn->prepare("UPDATE members SET position = ?, email = ?, gsuite_email = ? WHERE id = ?");
    $ins = $conn->prepare("INSERT INTO members (full_name, university_id, email, gsuite_email, department, phone, semester, gender, position, application_status, status)
                           VALUES (?, ?, ?, ?, '', '', '', '', ?, 'accepted', 'active')");
    $added = $updated = $skipped = 0;
    $valid = fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    while (($r = fgetcsv($fh)) !== false) {
        $name = trim($r[$col['name']] ?? '');
        $sid = trim($r[$col['id']] ?? '');
        $gsuite = $valid(strtolower(trim($r[$col['gsuite']] ?? '')));
        $gmail = $col['gmail'] === null ? null : $valid(strtolower(trim($r[$col['gmail']] ?? '')));
        $raw = $r[$col['position']] ?? '';
        $pos = strtoupper(trim(strpos($raw, '~') !== false ? explode('~', $raw)[1] : $raw));
        if ($name === '' || $sid === '' || !isset(BOARD_ORDER[$pos])) { $skipped++; continue; }
        $find->execute([$sid]);
        $existing = $find->fetch();
        try {
            if ($existing) {
                // Fill in addresses the website doesn't have yet; never overwrite a real one
                $email = $existing['email'];
                if ($gmail && (str_ends_with((string) $email, '@members.bucuc.local') || $email === $existing['gsuite_email'])) $email = $gmail;
                $upd->execute([$pos, $email, $existing['gsuite_email'] ?: $gsuite, $existing['id']]);
                $updated++;
            } else {
                // members.email is the personal e-mail (Gmail); a placeholder when the sheet has none
                $ins->execute([$name, $sid, $gmail ?? ($sid . '@members.bucuc.local'), $gsuite, $pos]);
                $added++;
            }
        } catch (\PDOException $e) {
            $skipped++;   // e.g. the e-mail already belongs to another member
        }
    }
    fclose($fh);
    return [$added, $updated, $skipped];
}

// Import from the Google sheet (form POST)
$importMsg = '';
if (($_POST['action'] ?? '') === 'import_sheet') {
    require_can('manage_site');
    try {
        [$added, $updated, $skipped] = board_import_sheet($conn);
        log_activity('members_imported', "From the Google member sheet: $added added, $updated levels updated, $skipped skipped", null, null, 'Member list');
        header('Location: ' . $selfPage . '?success=' . urlencode("Imported from the Google sheet: $added new members, $updated levels updated" . ($skipped ? ", $skipped rows skipped" : '') . '.'));
    } catch (\Throwable $e) {
        header('Location: ' . $selfPage . '?error=' . urlencode($e->getMessage()));
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'gas_proxy') {
    header('Content-Type: application/json; charset=utf-8');
    $fail = function ($code, $msg) {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'Method not allowed');

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || !in_array($payload['action'] ?? '', ['update', 'delete'], true) || empty($payload['id'])) $fail(400, 'Invalid request');
    $stmt = $conn->prepare("SELECT id, full_name, university_id, position FROM members WHERE id = ?");
    $stmt->execute([(int) $payload['id']]);
    $member = $stmt->fetch();
    if (!$member) $fail(404, 'That member no longer exists. Reload the page.');
    $who = "{$member['full_name']} ({$member['university_id']})";

    if ($payload['action'] === 'delete') {
        if (!$canDeleteMember) $fail(403, 'Only the Panel Admin can remove members.');
        $conn->prepare("DELETE FROM members WHERE id = ?")->execute([$member['id']]);
        $warn = board_sheet_sync(['action' => 'delete', 'studentId' => $member['university_id']]);
        log_activity('board_member_deleted', "Removed from the member list (was " . BOARD_NAMES[$member['position']] . ')' . ($warn ? "; $warn" : ''), null, null, $who);
        echo json_encode(['success' => true, 'warning' => $warn]);
        exit;
    }

    $from = $member['position'];
    $to = (string) ($payload['position'] ?? '');
    if (!isset(BOARD_ORDER[$to])) $fail(400, 'Invalid level');
    $need = board_move_permission($from, $to);
    if ($need === null) $fail(400, "Members move one step at a time (GB ↔ ASB ↔ Secretary Panel). {$member['full_name']} is " . BOARD_NAMES[$from] . '.');
    if (!can($need)) {
        $fail(403, $need === 'promote_sp' ? 'You need permission to promote into or demote from the Secretary Panel.' : 'You need permission to promote / demote between GB and ASB.');
    }
    // Only move if nobody changed it meanwhile
    $upd = $conn->prepare("UPDATE members SET position = ? WHERE id = ? AND position = ?");
    $upd->execute([$to, $member['id'], $from]);
    if ($upd->rowCount() !== 1) $fail(409, 'Someone else just changed this member. Reload the page.');
    $warn = board_sheet_sync(['action' => 'update', 'studentId' => $member['university_id'], 'position' => $to]);
    log_activity(BOARD_ORDER[$to] > BOARD_ORDER[$from] ? 'member_promoted' : 'member_demoted',
        BOARD_NAMES[$from] . ' → ' . BOARD_NAMES[$to] . ($warn ? "; not copied to the Google sheet: $warn" : ''), null, null, $who);
    echo json_encode(['success' => true, 'position' => $to, 'warning' => $warn]);
    exit;
}

// Members of this level, for the page
$list = $conn->prepare("SELECT id, full_name, university_id, COALESCE(NULLIF(gsuite_email, ''), email) AS gsuite FROM members WHERE position = ? ORDER BY full_name");
$list->execute([$board]);
$boardMembers = array_map(fn($m) => ['id' => (int) $m['id'], 'name' => $m['full_name'], 'studentId' => $m['university_id'], 'gsuite' => $m['gsuite'], 'currentPosition' => $board], $list->fetchAll());
// Attendance next to each name, only for admins who may see attendance (take_attendance)
$attSemester = null;
if (can('take_attendance')) {
    require_once __DIR__ . '/../../backend/includes/attendance.php';
    $attSemester = att_semester(date('Y-m-d'));
    $ids = array_column($boardMembers, 'id');
    $attAll = att_member_stats($conn, null, $ids);
    $attSem = att_member_stats($conn, $attSemester, $ids);
    foreach ($boardMembers as &$bm) {
        $bm['att'] = $attAll[$bm['id']]['pct'] ?? null;
        $bm['attSem'] = $attSem[$bm['id']]['pct'] ?? null;
    }
    unset($bm);
}
$levelCounts = array_fill_keys(array_keys(BOARD_ORDER), 0);
foreach ($conn->query("SELECT position, COUNT(*) n FROM members GROUP BY position")->fetchAll() as $c) $levelCounts[$c['position']] = (int) $c['n'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $board ?> Members - BUCuC</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link rel="icon" type="image/png" href="images/logopng.png">
    <style>
        /* Main Container Styling */
        .applications-container {
            min-height: 100vh;
            background: linear-gradient(135deg, rgb(var(--c-paperText-1a1a2e, 26 26 46)) 0%, rgb(var(--c-paperText-16213e, 22 33 62)) 50%, rgb(var(--c-surface-0f3460, 15 52 96)) 100%);
            padding: 2rem 0;
        }

        .applications-card {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgb(var(--c-fixed-000000, 0 0 0) / 0.3);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            margin: 0 1rem;
        }

        .applications-title {
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgb(var(--c-fixed-000000, 0 0 0) / 0.5);
            text-align: center;
        }

        .applications-subtitle {
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
            font-size: 1.1rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            text-align: center;
        }

        /* Table Styling */
        .applications-table {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05);
            border-radius: 15px;
            overflow: hidden;
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
        }

        .table-dark {
            --bs-table-bg: transparent;
        }

        .table-dark thead th {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border-color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 600;
            padding: 1rem;
        }

        .table-dark td {
            border-color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
            vertical-align: middle;
            padding: 1rem;
        }

        .table-dark tbody tr:hover {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            transition: all 0.3s ease;
        }

        /* Avatar Styling */
        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
        }

        /* Status Badge Styling */
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-pending {
            background: linear-gradient(45deg, rgb(var(--c-highlight-ffc107, 255 193 7)), rgb(var(--c-highlight-ffeb3b, 255 235 59)));
            color: rgb(var(--c-fixed-333333, 51 51 51));
        }

        .status-accepted {
            background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151)));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        .status-asb {
            background: linear-gradient(45deg, rgb(var(--c-accent-007bff, 0 123 255)), rgb(var(--c-accent-0056b3, 0 86 179)));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        .status-gb {
            background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151)));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        .status-sb {
            background: linear-gradient(45deg, rgb(var(--c-paperAccent-6f42c1, 111 66 193)), rgb(var(--c-paperAccent-5a32a3, 90 50 163)));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        /* Statistics Row */
        .stats-row {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: rgb(var(--c-accent2-51cf66, 81 207 102));
        }

        .stat-label {
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        /* Loading Animation */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3);
            border-radius: 50%;
            border-top-color: rgb(var(--c-fixed-ffffff, 255 255 255));
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Delete Button Styling */
        .btn-delete {
            background: linear-gradient(45deg, rgb(var(--c-danger-dc3545, 220 53 69)), rgb(var(--c-danger-c82333, 200 35 51)));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgb(var(--c-danger-dc3545, 220 53 69) / 0.3);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-delete:hover {
            background: linear-gradient(45deg, rgb(var(--c-danger-c82333, 200 35 51)), rgb(var(--c-danger-a71e2a, 167 30 42)));
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgb(var(--c-danger-dc3545, 220 53 69) / 0.4);
        }

        .btn-delete:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgb(var(--c-danger-dc3545, 220 53 69) / 0.3);
        }

        .btn-delete:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-delete i {
            font-size: 0.875rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .applications-card {
                margin: 0 0.5rem;
                padding: 1.5rem;
            }

            .applications-title {
                font-size: 2rem;
            }

            .table-responsive {
                font-size: 0.85rem;
            }

            .stat-number {
                font-size: 1.5rem;
            }

            .btn-delete {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
        }

        /* Custom Select Styling */
        .custom-select {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .custom-select:hover {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.15);
            border-color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3);
        }

        .custom-select:focus {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2);
            border-color: rgb(var(--c-accent-007bff, 0 123 255));
            box-shadow: 0 0 0 0.2rem rgb(var(--c-accent-007bff, 0 123 255) / 0.25);
            outline: none;
        }

        .custom-select option {
            background: rgb(var(--c-paperText-1a1a2e, 26 26 46));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            padding: 0.5rem;
        }

        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3);
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.5);
        }

        .back-btn-delicate {
            display: inline-flex;
            align-items: center;
            color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.8);
            text-decoration: none;
            padding: 0.6rem 1.2rem;
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
        }

        .back-btn-delicate:hover {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            border-color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3);
            transform: translateX(-5px);
        }
        /* Attendance line under each name (admins with take_attendance) */
        .att-line { display: inline-block; margin-top: 2px; font-size: .78rem; text-decoration: none; color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .att-line:hover { text-decoration: underline; }
        .att-ok { color: rgb(var(--c-accent2, 92 255 176)); font-weight: 600; }
        .att-low { color: rgb(var(--c-danger, 255 147 180)); font-weight: 700; }
        .att-none { color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
    </style>
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="applications-container">
        <div class="applications-card">
            <a href="member_types.php" class="back-btn-delicate">
                <i class="fas fa-arrow-left me-2"></i>Back to Member Types
            </a>
            <h1 class="applications-title">
                <i class="fas fa-users mb-3"></i><br>
                <?= $board ?> Members Only
            </h1>
            <p class="applications-subtitle">
                <?= $boardName ?> (<?= $board ?>) Member Directory
            </p>

            <!-- Statistics Row -->
            <div class="stats-row">
                <div class="row">
                    <div class="col-md-4 col-12">
                        <div class="stat-item">
                            <div class="stat-number" id="totalMembers">0</div>
                            <div class="stat-label">Total <?= $board ?> Members</div>
                        </div>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="stat-item">
                            <div class="stat-number"><?= $levelCounts['GB'] ?> · <?= $levelCounts['ASB'] ?> · <?= $levelCounts['SB'] ?></div>
                            <div class="stat-label">All members: GB · ASB · Secretary Panel</div>
                        </div>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="stat-item">
                            <div class="stat-number" id="lastUpdated">Loading...</div>
                            <div class="stat-label">Last Updated</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div><?php endif; ?>
            <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>
            <?php if ($canDeleteMember): ?>
                <form method="POST" action="<?= htmlspecialchars($selfPage) ?>" class="text-center mb-4" onsubmit="return confirm('Copy the members from the Google member sheet into the website? New students are added; existing ones get the level shown in the sheet.')">
                    <input type="hidden" name="action" value="import_sheet">
                    <button type="submit" class="btn btn-outline-light btn-sm"><i class="fas fa-file-import me-2"></i>Import from Google Sheet</button>
                </form>
            <?php endif; ?>

            <!-- Loading State -->
            <div class="text-center mb-4" id="loadingState">
                <div class="loading-spinner me-2"></div>
                <span class="text-light">Loading <?= $board ?> member data...</span>
            </div>

            <!-- Members Table -->
            <div class="applications-table" id="membersTableContainer" style="display: none;">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0" id="membersTable">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Student ID</th>
                                <th>G-Suite Email</th>
                                <th>Panel</th>
                                <th>Update Position</th>
                                <th>Delete Member</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <!-- Empty State -->
            <div class="empty-state d-none" id="emptyState">
                <i class="fas fa-users-slash"></i>
                <h4 id="emptyTitle">No <?= $board ?> Members Found</h4>
                <p id="emptyText">No members are at the <?= $board ?> level yet.</p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="js/admin-toast.js"></script>

    <script>
        const BOARD = <?= json_encode($board) ?>;
        const PROXY_URL = <?= json_encode($selfPage . '?action=gas_proxy') ?>;
        const MEMBERS = <?= json_encode($boardMembers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        // Attendance (only sent to admins with take_attendance): overall and this semester, low under <?= $attSemester ? ATT_LOW_DEFAULT : 50 ?>%
        const ATT = <?= json_encode($attSemester ? ['semester' => $attSemester, 'low' => ATT_LOW_DEFAULT] : null) ?>;
        function attLine(m) {
            const fmt = p => p === null ? '—' : p + '%';
            const cls = p => p === null ? 'att-none' : (p < ATT.low ? 'att-low' : 'att-ok');
            return `<a class="att-line" href="attendance_member.php?id=${m.id}" title="Attendance: overall, and ${escapeHtml(ATT.semester)}">
                <span class="${cls(m.att)}">Attendance ${fmt(m.att)}</span> · <span class="${cls(m.attSem)}">${escapeHtml(ATT.semester)}: ${fmt(m.attSem)}</span></a>`;
        }
        const POSITIONS = ['GB', 'ASB', 'SB'];
        const POSITION_NAMES = { GB: 'GB', ASB: 'ASB', SB: 'Secretary Panel (SB)' };
        // What this admin may do (the server checks again on every request)
        const CAN = <?= json_encode(['gbAsb' => $canGbAsb, 'sp' => $canSp, 'delete' => $canDeleteMember]) ?>;
        function allowedMoves(from) {
            const i = POSITIONS.indexOf(from);
            return POSITIONS.filter((p, j) => Math.abs(i - j) === 1 && ((p === 'SB' || from === 'SB') ? CAN.sp : CAN.gbAsb));
        }
        const BADGE_CLASS = { GB: 'status-gb', ASB: 'status-asb', SB: 'status-sb' };

        function escapeHtml(unsafe) {
            if (unsafe === null || unsafe === undefined) return '';
            return unsafe.toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function getInitials(name) {
            return name.split(' ').filter(w => w.length > 0).map(w => w.charAt(0).toUpperCase()).join('').substring(0, 2);
        }

        function getAvatarColor(index) {
            const colors = [
                'linear-gradient(45deg, #007bff, #0056b3)',
                'linear-gradient(45deg, #28a745, #20c997)',
                'linear-gradient(45deg, #ffc107, #ffeb3b)',
                'linear-gradient(45deg, #17a2b8, #138496)',
                'linear-gradient(45deg, #dc3545, #c82333)',
                'linear-gradient(45deg, #6f42c1, #5a32a3)'
            ];
            return colors[index % colors.length];
        }

        function setBadge(row, position) {
            const badge = row.querySelector('.status-badge');
            badge.className = `status-badge ${BADGE_CLASS[position] || 'status-sb'}`;
            badge.innerHTML = `<i class="fas fa-star me-1"></i>${escapeHtml(position)}`;
        }

        function refreshCount() {
            const total = document.querySelectorAll('#membersTable tbody tr').length;
            document.getElementById('totalMembers').textContent = total;
            if (total === 0) {
                document.getElementById('membersTableContainer').style.display = 'none';
                document.getElementById('emptyState').classList.remove('d-none');
            }
        }

        // POST {action, studentId, position?} to the Apps Script proxy; resolves to the JSON reply
        async function callSheet(payload) {
            const response = await fetch(PROXY_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(payload)
            });
            let data = null;
            try { data = await response.json(); } catch (e) { /* not JSON */ }
            if (!response.ok || !data || data.success === false) {
                throw new Error((data && (data.error || data.message)) || `HTTP ${response.status}`);
            }
            return data;
        }

        async function deleteMember(row, button) {
            const { studentId, name } = row.dataset;
            if (!confirm(`Remove "${name}" (Student ID: ${studentId}) from the member list?\n\nThis cannot be undone.`)) return;

            const originalContent = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';
            button.disabled = true;

            try {
                const res = await callSheet({ action: 'delete', id: row.dataset.id });
                if (res.warning) showNotification(`Removed. Note: ${res.warning}`, 'warning');
                showNotification(`${name} has been deleted`, 'success');
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px)';
                setTimeout(() => { row.remove(); refreshCount(); }, 300);
            } catch (error) {
                button.innerHTML = originalContent;
                button.disabled = false;
                showNotification(`Could not delete ${name}: ${error.message}`, 'error');
            }
        }

        // Promote / demote select: current position plus the one-step moves this admin may make
        function renderMoveSelect(row, current) {
            const cell = row.querySelector('.move-cell');
            const moves = allowedMoves(current);
            if (!moves.length) {
                cell.innerHTML = '<span class="text-muted small">No permission to move</span>';
                return;
            }
            cell.innerHTML = `<select class="custom-select" data-original-value="${escapeHtml(current)}">
                <option value="${escapeHtml(current)}" selected>${escapeHtml(POSITION_NAMES[current] || current)} (now)</option>
                ${moves.map(p => `<option value="${p}">${POSITIONS.indexOf(p) > POSITIONS.indexOf(current) ? 'Promote to' : 'Demote to'} ${POSITION_NAMES[p]}</option>`).join('')}
            </select>`;
            cell.querySelector('select').addEventListener('change', e => updatePosition(row, e.target));
        }

        async function updatePosition(row, select) {
            const position = select.value;
            const previous = select.dataset.originalValue;
            if (!position || position === previous) return;

            select.disabled = true;
            try {
                const res = await callSheet({ action: 'update', id: row.dataset.id, position });
                if (res.warning) setTimeout(() => showNotification(`Saved, but ${res.warning}`, 'warning'), 400);
                setBadge(row, position);
                renderMoveSelect(row, position);
                showNotification(`Moved to ${POSITION_NAMES[position]}`, 'success');
            } catch (error) {
                select.value = previous;
                showNotification(`Could not update position: ${error.message}`, 'error');
            } finally {
                select.disabled = false;
            }
        }


        // Members of this level come from the database (rendered into the page)
        Promise.resolve(MEMBERS)
            .then(members => {
                document.getElementById('loadingState').style.display = 'none';
                document.getElementById('lastUpdated').textContent = new Date().toLocaleTimeString();

                if (members.length === 0) {
                    document.getElementById('emptyState').classList.remove('d-none');
                    return;
                }

                const tbody = document.querySelector('#membersTable tbody');
                tbody.innerHTML = '';

                members.forEach((member, index) => {
                    const row = document.createElement('tr');
                    row.dataset.studentId = member.studentId;
                    row.dataset.id = member.id;
                    row.dataset.name = member.name;
                    row.innerHTML = `
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="member-avatar me-3" style="background: ${getAvatarColor(index)};">${escapeHtml(getInitials(member.name))}</div>
                                <div><div class="fw-bold">${escapeHtml(member.name)}</div>${ATT ? attLine(member) : ''}</div>
                            </div>
                        </td>
                        <td>${escapeHtml(member.studentId)}</td>
                        <td>${escapeHtml(member.gsuite)}</td>
                        <td><span class="status-badge"></span></td>
                        <td class="move-cell"></td>
                        <td>
                            ${CAN.delete ? '<button class="btn-delete"><i class="fas fa-trash"></i>Delete</button>' : '<span class="text-muted small">—</span>'}
                        </td>`;
                    setBadge(row, member.currentPosition);
                    renderMoveSelect(row, member.currentPosition);
                    if (CAN.delete) row.querySelector('.btn-delete').addEventListener('click', e => deleteMember(row, e.currentTarget));
                    tbody.appendChild(row);
                });

                refreshCount();
                document.getElementById('membersTableContainer').style.display = 'block';
            })
            .catch(err => {
                console.error('Error loading data:', err);
                document.getElementById('loadingState').style.display = 'none';
                document.getElementById('emptyTitle').textContent = 'Could not load the member sheet';
                document.getElementById('emptyText').textContent = 'Check your connection and that the Google Sheet is still published as CSV.';
                document.getElementById('emptyState').classList.remove('d-none');
            });
    </script>
</body>

</html>
