<?php
require_once '../backend/includes/auth.php';

// Every admin can read the log. Everything done by or to the Super Admin is visible to the
// Super Admin only: rows whose actor or target is (or was) the Super Admin are filtered out.
require_admin();

$conn = db();
auth_ensure_tables($conn);
$iAmSuper = auth_is_super();

const LOG_PAGE_SIZE = 50;

$actionLabels = [
    'login'                     => 'Logged in',
    'login_failed'              => 'Failed login',
    'logout'                    => 'Logged out',
    'password_reset_requested'  => 'Reset link requested',
    'password_reset'            => 'Password reset',
    'profile_updated'           => 'Profile updated',
    'admin_added'               => 'Admin added',
    'admin_updated'             => 'Admin edited',
    'admin_deleted'             => 'Admin deleted',
    'admin_activated'           => 'Admin activated',
    'admin_deactivated'         => 'Admin deactivated',
    'application_shortlisted'   => 'Shortlisted',
    'application_rejected'      => 'Rejected',
    'member_accepted'           => 'Accepted',
    'member_deleted'            => 'Member deleted',
    'cleared_all_applications'  => 'Cleared all lists',
    'cleared_shortlist'         => 'Cleared shortlist',
    'venue_created'             => 'Venue set',
    'venue_updated'             => 'Venue updated',
    'venue_deleted'             => 'Venue deleted',
    'dashboard_updated'         => 'Dashboard edited',
    'signup_toggled'            => 'Signup on/off',
    'exported_excel'            => 'Excel export',
    'board_member_updated'      => 'Board member moved',
    'board_member_deleted'      => 'Board member removed',
    'login_blocked'             => 'Login blocked',
    'permissions_changed'       => 'Permissions changed',
    'member_promoted'           => 'Promoted',
    'member_demoted'            => 'Demoted',
    'carousel_photo_added'      => 'Carousel photo added',
    'carousel_photo_removed'    => 'Carousel photo removed',
    'panel_member_added'        => 'Panel member added',
    'panel_member_updated'      => 'Panel member edited',
    'panel_member_removed'      => 'Panel member removed',
    'event_added'               => 'Event added',
    'event_updated'             => 'Event edited',
    'event_removed'             => 'Event removed',
    'hero_video_changed'        => 'Hero video changed',
    'hero_video_reset'          => 'Hero video reset',
    'tenure_created'            => 'Tenure created',
    'tenure_deleted'            => 'Tenure deleted',
    'tenure_set_current'        => 'Current tenure set',
    'secretary_added'           => 'Secretary added',
    'secretary_updated'         => 'Secretary edited',
    'secretary_removed'         => 'Secretary removed',
    'members_imported'          => 'Members imported',
    'home_stats_changed'        => 'Homepage stats changed',
    'palette_saved'             => 'Site colours saved',
    'palette_reset'             => 'Site colours reset',
    'palette_restored'          => 'Site colours restored',
    'super_recovered'           => 'Super Admin recovered',
    'attendance_session_created' => 'Attendance session created',
    'attendance_session_updated' => 'Attendance session edited',
    'attendance_session_deleted' => 'Attendance session deleted',
    'attendance_changed'        => 'Attendance changed after the session',
    'attendance_checkin_opened' => 'QR check-in opened',
    'attendance_checkin_closed' => 'QR check-in ended',
    'attendance_checkin_removed' => 'QR check-in removed',
    'handover_edited'           => 'Handover notes edited',
    'handover_restored'         => 'Handover version restored',
    'handover_task_done'        => 'Handover task ticked',
    'handover_unlocked'         => 'Handover notes unlocked',
    'handover_locked'           => 'Handover notes locked',
    'handover_exported'         => 'Handover notes exported',
    'handover_end_date_set'     => 'Tenure end date set',
    'handover_reminder_sent'    => 'Handover reminder sent',
    'gallery_video_added'       => 'Gallery video added',
    'gallery_video_updated'     => 'Gallery video edited',
    'gallery_video_hidden'      => 'Gallery video hidden',
    'gallery_video_shown'       => 'Gallery video shown',
    'gallery_video_deleted'     => 'Gallery video deleted',
    'notice_sent'               => 'Email notice sent',
    'notice_finished'           => 'Email notice finished',
    'lockdown_scheduled'        => 'Lockdown scheduled',
    'lockdown_cancelled'        => 'Lockdown cancelled',
    'lockdown_lifted'           => 'Lockdown lifted',
];
if ($iAmSuper) {
    // Sponsor banners exist only for the Super Admin: their labels are added only here
    $actionLabels += ['ad_created' => 'Sponsor ad added', 'ad_updated' => 'Sponsor ad edited', 'ad_paused' => 'Sponsor ad paused', 'ad_resumed' => 'Sponsor ad resumed',
        'ad_deleted' => 'Sponsor ad deleted', 'ad_spot_on' => 'Sponsor spot switched on', 'ad_spot_off' => 'Sponsor spot switched off', 'ad_report_exported' => 'Sponsor report exported'];
}
if (!$iAmSuper) {
    // Lockdown is the Super Admin's tool; others never see those entries, so don't offer them as filters
    $actionLabels = array_filter($actionLabels, fn($k) => strpos($k, 'lockdown_') !== 0, ARRAY_FILTER_USE_KEY);
}

// Colour group per action, for the badge
function actionTone($action)
{
    if (in_array($action, ['login_failed', 'admin_deleted', 'admin_deactivated', 'member_deleted', 'cleared_all_applications', 'cleared_shortlist', 'application_rejected', 'venue_deleted', 'board_member_deleted', 'member_demoted', 'carousel_photo_removed', 'panel_member_removed', 'event_removed', 'lockdown_scheduled', 'login_blocked', 'tenure_deleted', 'secretary_removed', 'super_recovered', 'gallery_video_deleted', 'gallery_video_hidden', 'attendance_session_deleted', 'attendance_changed', 'attendance_checkin_removed', 'handover_unlocked'], true)) return 'bad';
    if (in_array($action, ['login', 'logout', 'password_reset_requested', 'password_reset', 'profile_updated'], true)) return 'auth';
    if (in_array($action, ['member_accepted', 'application_shortlisted', 'admin_added', 'admin_activated', 'member_promoted', 'carousel_photo_added', 'panel_member_added', 'event_added', 'hero_video_changed', 'lockdown_cancelled', 'lockdown_lifted', 'tenure_created', 'secretary_added', 'members_imported', 'gallery_video_added', 'gallery_video_shown', 'attendance_session_created'], true)) return 'good';
    return 'info';
}

// Filters
$filterAdmin = $_GET['admin'] ?? '';
$filterAction = $_GET['action'] ?? '';
$filterQ = trim($_GET['q'] ?? '');
$filterFrom = $_GET['from'] ?? '';
$filterTo = $_GET['to'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = [];
$params = [];
if (!$iAmSuper) {
    // Hide the Super Admin: by role recorded at the time, and by the current Super Admin account
    // (sponsor ad entries are excluded by name too: that feature doesn't exist for other admins)
    $where[] = "COALESCE(actor_role, '') <> 'super_admin' AND COALESCE(target_role, '') <> 'super_admin'
        AND COALESCE(admin_id, 0) NOT IN (SELECT id FROM adminpanel WHERE role IN ('super_admin', 'main_admin'))
        AND action NOT LIKE 'ad\\_%'";
}
if ($filterAdmin !== '' && ctype_digit($filterAdmin)) {
    $where[] = 'admin_id = ?';
    $params[] = (int) $filterAdmin;
}
if ($filterAction !== '' && isset($actionLabels[$filterAction])) {
    $where[] = 'action = ?';
    $params[] = $filterAction;
}
if ($filterQ !== '') {
    $where[] = '(details LIKE ? OR target LIKE ? OR admin_name LIKE ? OR ip LIKE ?)';
    $like = '%' . $filterQ . '%';
    array_push($params, $like, $like, $like, $like);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $where[] = 'created_at >= ?';
    $params[] = $filterFrom . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $where[] = 'created_at <= ?';
    $params[] = $filterTo . ' 23:59:59';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $conn->prepare("SELECT COUNT(*) FROM admin_activity_log $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / LOG_PAGE_SIZE));
$page = min($page, $pages);
$offset = ($page - 1) * LOG_PAGE_SIZE;

$stmt = $conn->prepare("SELECT * FROM admin_activity_log $whereSql ORDER BY id DESC LIMIT " . LOG_PAGE_SIZE . " OFFSET $offset");
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$admins = $conn->query("SELECT id, username FROM adminpanel" . ($iAmSuper ? '' : " WHERE COALESCE(role, '') NOT IN ('super_admin', 'main_admin')") . " ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Small summary for the last 24 hours (same visibility rules)
$summaryWhere = $iAmSuper ? '' : " AND COALESCE(actor_role, '') <> 'super_admin' AND COALESCE(target_role, '') <> 'super_admin'
    AND COALESCE(admin_id, 0) NOT IN (SELECT id FROM adminpanel WHERE role IN ('super_admin', 'main_admin'))
    AND action NOT LIKE 'ad\\_%'";
$summary = $conn->query("SELECT
    SUM(action = 'login') AS logins,
    SUM(action = 'login_failed') AS failed,
    COUNT(*) AS total
    FROM admin_activity_log WHERE created_at > (NOW() - INTERVAL 1 DAY)$summaryWhere")->fetch(PDO::FETCH_ASSOC);

function pageUrl($p)
{
    $q = $_GET;
    $q['page'] = $p;
    return 'activity_log.php?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - BUCuC</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <style>
        .log-container { padding: 2rem 1rem; color: var(--ink, rgb(var(--c-fixed-ffffff, 255 255 255))); }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .back-btn { text-decoration: none; color: inherit; padding: .5rem 1rem; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3); border-radius: 999px; }
        .back-btn:hover { background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1); color: inherit; }
        .panel { background: rgb(var(--c-surface, 14 9 36) / 0.72); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); border-radius: 18px; padding: 1.25rem 1.5rem; margin-bottom: 1.25rem; }
        .stat-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; margin-bottom: 1.25rem; }
        .stat-row .panel { margin: 0; text-align: center; padding: 1rem; }
        .stat-row b { display: block; font-size: 1.6rem; line-height: 1.1; }
        .stat-row span { font-size: .8rem; letter-spacing: .08em; text-transform: uppercase; color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .filters .form-control, .filters .form-select { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.25); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .filters .form-select option { color: rgb(var(--c-fixed-000000, 0 0 0)); }
        .filters label { font-size: .8rem; color: var(--mute, rgb(var(--c-muted, 170 163 210))); margin-bottom: .25rem; }
        .log-table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        .log-table th { text-align: left; font-weight: 600; color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; padding: .6rem .5rem; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.18); }
        .log-table td { padding: .6rem .5rem; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.07); vertical-align: top; }
        .log-table td.when { white-space: nowrap; color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .log-table td.details { word-break: break-word; }
        .tag { display: inline-block; padding: .2rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 700; white-space: nowrap; }
        .tag.good { background: rgb(var(--c-accent2, 92 255 176) / 0.16); color: rgb(var(--c-accent2, 92 255 176)); }
        .tag.bad { background: rgb(var(--c-danger, 255 147 180) / 0.16); color: rgb(var(--c-danger, 255 147 180)); }
        .tag.auth { background: rgb(var(--c-primary, 139 92 255) / 0.2); color: rgb(var(--c-primary-c8b6ff, 200 182 255)); }
        .tag.info { background: rgb(var(--c-accent, 45 226 230) / 0.14); color: rgb(var(--c-accent, 45 226 230)); }
        .ip { font-family: ui-monospace, Consolas, monospace; font-size: .8rem; color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .pager { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-top: 1rem; }
        .pager a, .pager span.cur { padding: .35rem .8rem; border-radius: 999px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: inherit; text-decoration: none; }
        .pager a:hover { background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1); }
        .empty { text-align: center; padding: 2rem 0; color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        @media (max-width: 720px) {
            .log-table thead { display: none; }
            .log-table tr { display: block; padding: .6rem 0; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1); }
            .log-table td { display: block; border: 0; padding: .15rem 0; }
            .stat-row b { font-size: 1.25rem; }
            .stat-row span { font-size: .66rem; letter-spacing: .03em; }
            .stat-row .panel { padding: .75rem .4rem; }
        }
    </style>
    <link rel="icon" type="image/png" href="images/logopng.png">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>

    <div class="container log-container">
        <div class="page-header">
            <h2 class="m-0"><i class="fas fa-clipboard-list me-2"></i>Activity Log</h2>
            <a href="<?= auth_dashboard_page() ?>" class="back-btn"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>

        <div class="stat-row">
            <div class="panel"><b><?= (int) $summary['total'] ?></b><span>Actions · 24h</span></div>
            <div class="panel"><b><?= (int) $summary['logins'] ?></b><span>Logins · 24h</span></div>
            <div class="panel"><b style="color:<?= $summary['failed'] > 0 ? 'rgb(var(--c-danger, 255 147 180))' : 'inherit' ?>"><?= (int) $summary['failed'] ?></b><span>Failed logins · 24h</span></div>
        </div>

        <form class="panel filters" method="GET" action="activity_log.php">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label for="f-admin">Admin</label>
                    <select id="f-admin" name="admin" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($admins as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= $filterAdmin === (string) $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-action">Action</label>
                    <select id="f-action" name="action" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($actionLabels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $filterAction === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label for="f-q">Search details / IP</label>
                    <input id="f-q" type="search" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($filterQ) ?>" placeholder="e.g. a name or student ID">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-from">From</label>
                    <input id="f-from" type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($filterFrom) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-to">To</label>
                    <input id="f-to" type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($filterTo) ?>">
                </div>
                <div class="col-12 col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill" title="Filter"><i class="fas fa-filter"></i></button>
                    <a href="activity_log.php" class="btn btn-sm btn-secondary flex-fill" title="Clear filters"><i class="fas fa-times"></i></a>
                </div>
            </div>
        </form>

        <div class="panel">
            <?php if (!$entries): ?>
                <div class="empty"><i class="fas fa-inbox me-2"></i>No activity matches these filters.</div>
            <?php else: ?>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Admin</th>
                            <th>Action</th>
                            <th>To whom / what</th>
                            <th>Details</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $e): ?>
                            <tr>
                                <td class="when" title="<?= htmlspecialchars($e['created_at']) ?>"><?= date('j M Y, g:i a', strtotime($e['created_at'])) ?></td>
                                <td><?= htmlspecialchars($e['admin_id'] || $e['admin_name'] ? admin_shown_name($e['admin_name'], $e['actor_role'], $e['admin_id']) : '—') ?></td>
                                <td><span class="tag <?= actionTone($e['action']) ?>"><?= htmlspecialchars($actionLabels[$e['action']] ?? $e['action']) ?></span></td>
                                <td class="details"><?= htmlspecialchars($e['target'] ?? '') ?></td>
                                <td class="details"><?= htmlspecialchars($e['details'] ?? '') ?></td>
                                <td class="ip"><?= htmlspecialchars($e['ip'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="pager">
                <small><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?><?= $total ? ' · page ' . $page . ' of ' . $pages : '' ?></small>
                <?php if ($pages > 1): ?>
                    <div class="d-flex gap-1">
                        <?php if ($page > 1): ?><a href="<?= htmlspecialchars(pageUrl($page - 1)) ?>">&larr; Newer</a><?php endif; ?>
                        <span class="cur"><?= $page ?></span>
                        <?php if ($page < $pages): ?><a href="<?= htmlspecialchars(pageUrl($page + 1)) ?>">Older &rarr;</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>
